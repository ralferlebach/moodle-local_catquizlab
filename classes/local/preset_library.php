<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Reusable building blocks of an experiment definition.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * A library of item-pool structures and person models an experiment can cite.
 *
 * Retyping a scale structure or a person distribution for every new experiment
 * invites drift: two studies meant to share a pool end up with slightly
 * different numbers, and nothing in the data says so. A preset makes the
 * sharing explicit. The experiment stores the preset id and its fingerprint, so
 * a later reader can tell from the manifest alone whether two experiments ran
 * on the same blueprint.
 *
 * A preset that a published experiment already used is locked. Editing it would
 * retrospectively change what those runs did, which is the same rule the
 * experiment itself follows once it has runs; the way forward is a new version.
 */
class preset_library {
    /** @var string An item-pool structure with its item-parameter distributions. */
    public const KIND_POOL = 'pool';

    /** @var string A person model: strata, severity and ability distribution. */
    public const KIND_PERSONS = 'persons';

    /**
     * The kinds a preset can have.
     *
     * @return string[]
     */
    public static function kinds(): array {
        return [self::KIND_POOL, self::KIND_PERSONS];
    }

    /**
     * The definition fragment a preset of this kind contributes.
     *
     * @param string $kind One of the KIND_* constants.
     * @return string The top-level definition key the payload is merged into.
     */
    public static function target_key(string $kind): string {
        return $kind === self::KIND_PERSONS ? 'persons' : 'pool';
    }

    /**
     * Extract a preset payload from a definition.
     *
     * Only the reusable part is taken. The pool variant and its recipe stay
     * with the experiment, because a robustness condition is a property of the
     * study rather than of the pool it disturbs; and the person count stays
     * with the experiment because the sample size is a design decision, not
     * part of the person model.
     *
     * @param array $definition A normalised experiment definition.
     * @param string $kind One of the KIND_* constants.
     * @return array The payload.
     */
    public static function extract(array $definition, string $kind): array {
        if ($kind === self::KIND_PERSONS) {
            $persons = (array) ($definition['persons'] ?? []);
            unset($persons['count'], $persons['naming']);
            return $persons;
        }

        $pool = (array) ($definition['pool'] ?? []);
        unset($pool['variant'], $pool['recipe']);

        return [
            'scales'           => (array) ($pool['scales'] ?? []),
            'questiontemplate' => (array) ($pool['questiontemplate'] ?? []),
            'itemnaming'       => (array) ($pool['itemnaming'] ?? []),
            'model'            => (string) ($definition['model'] ?? '2pl'),
            'modelparams'      => (array) ($definition['modelparams'] ?? []),
        ];
    }

    /**
     * Merge a preset into a definition.
     *
     * The experiment's own values win: a preset supplies a starting point, it
     * does not overrule a choice the author made afterwards.
     *
     * @param array $definition The definition being built.
     * @param int $presetid The preset to apply.
     * @return array The definition with the preset merged in.
     */
    public static function apply(array $definition, int $presetid): array {
        $preset = self::get($presetid);
        if ($preset === null) {
            return $definition;
        }
        $payload = $preset['payload'];

        if ($preset['kind'] === self::KIND_PERSONS) {
            $definition['persons'] = ((array) ($definition['persons'] ?? [])) + $payload;
            $definition['personspreset'] = $presetid;
            $definition['personspresetfingerprint'] = $preset['fingerprint'];
            return $definition;
        }

        if (isset($payload['model']) && !isset($definition['model'])) {
            $definition['model'] = $payload['model'];
        }
        if (isset($payload['modelparams']) && !isset($definition['modelparams'])) {
            $definition['modelparams'] = $payload['modelparams'];
        }
        $pool = (array) ($definition['pool'] ?? []);
        foreach (['scales', 'questiontemplate', 'itemnaming'] as $key) {
            if (!isset($pool[$key]) && isset($payload[$key])) {
                $pool[$key] = $payload[$key];
            }
        }
        $definition['pool'] = $pool;
        $definition['poolpreset'] = $presetid;
        $definition['poolpresetfingerprint'] = $preset['fingerprint'];

        return $definition;
    }

    /**
     * Store a preset.
     *
     * @param string $kind One of the KIND_* constants.
     * @param string $name The display name.
     * @param array $payload The definition fragment.
     * @param string $description What it is for.
     * @param int|null $id An existing preset to update, or null to create.
     * @return int The preset id.
     * @throws \moodle_exception If the kind is unknown, or the preset is locked.
     */
    public static function save(
        string $kind,
        string $name,
        array $payload,
        string $description = '',
        ?int $id = null
    ): int {
        global $DB, $USER;

        if (!in_array($kind, self::kinds(), true)) {
            throw new \moodle_exception('preset:unknownkind', 'local_catquizlab', '', $kind);
        }

        $now = time();
        $record = (object) [
            'kind'         => $kind,
            'name'         => $name,
            'description'  => $description,
            // Without PRESERVE_ZERO_FRACTION a discrimination of 1.0 comes
            // back as int 1, so a block silently changes type between saving
            // and reuse.
            'payloadjson'  => json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            ),
            'fingerprint'  => self::fingerprint($payload),
            'usermodified' => (int) ($USER->id ?? 0),
            'timemodified' => $now,
        ];

        if ($id === null) {
            $record->timecreated = $now;
            $record->usecount = 0;
            $record->locked = 0;
            return (int) $DB->insert_record('local_catquizlab_preset', $record);
        }

        $existing = $DB->get_record('local_catquizlab_preset', ['id' => $id], '*', MUST_EXIST);
        if ((int) $existing->locked === 1) {
            throw new \moodle_exception('preset:locked', 'local_catquizlab', '', $existing->name);
        }
        $record->id = $id;
        $DB->update_record('local_catquizlab_preset', $record);

        return (int) $id;
    }

    /**
     * Read a preset.
     *
     * @param int $id The preset.
     * @return array|null The preset with its decoded payload, or null when absent.
     */
    public static function get(int $id): ?array {
        global $DB;

        $record = $DB->get_record('local_catquizlab_preset', ['id' => $id]);
        if (!$record) {
            return null;
        }

        return [
            'id'          => (int) $record->id,
            'kind'        => (string) $record->kind,
            'name'        => (string) $record->name,
            'description' => (string) $record->description,
            'payload'     => json_decode((string) $record->payloadjson, true) ?: [],
            'fingerprint' => (string) $record->fingerprint,
            'usecount'    => (int) $record->usecount,
            'locked'      => (int) $record->locked === 1,
        ];
    }

    /**
     * All presets of a kind, for a picker.
     *
     * @param string $kind One of the KIND_* constants.
     * @return array<int, string> Preset id => label with a short summary.
     */
    public static function menu(string $kind): array {
        global $DB;

        $menu = [];
        foreach ($DB->get_records('local_catquizlab_preset', ['kind' => $kind], 'name ASC') as $record) {
            $payload = json_decode((string) $record->payloadjson, true) ?: [];
            $menu[(int) $record->id] = $record->name . ' — ' . self::summarise($record->kind, $payload);
        }

        return $menu;
    }

    /**
     * All presets of a kind with their details, for a listing.
     *
     * @param string|null $kind Restrict to one kind, or null for all.
     * @return array[] One row per preset.
     */
    public static function listing(?string $kind = null): array {
        global $DB;

        $conditions = $kind !== null ? ['kind' => $kind] : [];
        $rows = [];
        foreach ($DB->get_records('local_catquizlab_preset', $conditions, 'kind ASC, name ASC') as $record) {
            $payload = json_decode((string) $record->payloadjson, true) ?: [];
            $rows[] = [
                'id'          => (int) $record->id,
                'kind'        => (string) $record->kind,
                'kindlabel'   => get_string('preset:kind' . $record->kind, 'local_catquizlab'),
                'name'        => (string) $record->name,
                'description' => (string) $record->description,
                'summary'     => self::summarise((string) $record->kind, $payload),
                'fingerprint' => substr((string) $record->fingerprint, 0, 12),
                'usecount'    => (int) $record->usecount,
                'locked'      => (int) $record->locked === 1,
                'timemodified' => (int) $record->timemodified,
            ];
        }

        return $rows;
    }

    /**
     * Note that an experiment now uses a preset, locking it once it has runs.
     *
     * @param int $presetid The preset.
     * @param bool $published Whether the citing experiment has runs.
     * @return void
     */
    public static function record_use(int $presetid, bool $published = false): void {
        global $DB;

        $record = $DB->get_record('local_catquizlab_preset', ['id' => $presetid]);
        if (!$record) {
            return;
        }
        $DB->set_field('local_catquizlab_preset', 'usecount', (int) $record->usecount + 1, ['id' => $presetid]);
        if ($published) {
            $DB->set_field('local_catquizlab_preset', 'locked', 1, ['id' => $presetid]);
        }
    }

    /**
     * Delete a preset that no experiment has used.
     *
     * @param int $id The preset.
     * @return void
     * @throws \moodle_exception If the preset is locked.
     */
    public static function delete(int $id): void {
        global $DB;

        $record = $DB->get_record('local_catquizlab_preset', ['id' => $id], '*', MUST_EXIST);
        if ((int) $record->locked === 1) {
            throw new \moodle_exception('preset:locked', 'local_catquizlab', '', $record->name);
        }
        $DB->delete_records('local_catquizlab_preset', ['id' => $id]);
    }

    /**
     * The fingerprint of a payload.
     *
     * Keys are sorted before hashing, so two payloads that differ only in the
     * order they were written produce the same fingerprint — they are the same
     * blueprint, and the data should say so.
     *
     * @param array $payload The payload.
     * @return string A hex digest.
     */
    public static function fingerprint(array $payload): string {
        $normalise = static function ($value) use (&$normalise) {
            if (!is_array($value)) {
                return $value;
            }
            ksort($value);
            return array_map($normalise, $value);
        };

        return hash('sha256', json_encode(
            $normalise($payload),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /**
     * A one-line summary of a payload, for pickers and listings.
     *
     * @param string $kind One of the KIND_* constants.
     * @param array $payload The payload.
     * @return string
     */
    public static function summarise(string $kind, array $payload): string {
        if ($kind === self::KIND_PERSONS) {
            $stratum = (string) ($payload['stratum'] ?? '');
            $severity = (string) ($payload['severity'] ?? 'none');
            return get_string('preset:personsummary', 'local_catquizlab', (object) [
                'stratum'  => run_registry::group_label('stratum', $stratum),
                'severity' => run_registry::group_label('severity', $severity),
            ]);
        }

        $scales = (array) ($payload['scales'] ?? []);
        $categories = (int) ($scales['categories'] ?? 0);
        $subscales = (int) ($scales['subcategories'] ?? 0);
        $peritem = (int) ($scales['itemspersubscale'] ?? 0);
        $model = (string) ($payload['model'] ?? '');

        return get_string('preset:poolsummary', 'local_catquizlab', (object) [
            'model' => model_catalog::has($model) ? model_catalog::label($model) : $model,
            'items' => $categories * $subscales * $peritem,
            'scales' => $categories . '×' . $subscales,
        ]);
    }
}
