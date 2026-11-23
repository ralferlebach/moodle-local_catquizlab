<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_catquizlab\local;

/**
 * An experiment's name and description, changed at any time without touching its design (#152).
 *
 * Name and description are presentation: they carry no computational meaning. Once runs exist the design is locked —
 * changing it means a copy — but there was no way to correct a name either. This changes exactly those two fields:
 * the experiment's name column and the two fields of its stored definition, nothing else. The design hash (the
 * normalised definition without them) is computed before and after and must be the same; runs keep the definition
 * they were created from, name included, so the name a run was made under stays readable beside the current one.
 *
 * Every change is an event (time, user, old and new value), which is its history. Concurrent edits are caught: the
 * change carries the version (timemodified) the editor saw, and a different one is refused, not overwritten.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class experiment_metadata {
    /** @var int Longest name: the column's length. */
    public const NAME_MAX = 255;

    /** @var int Longest description. */
    public const DESCRIPTION_MAX = 4000;

    /** @var string[] The fields this may change; everything else in a definition is design. */
    public const FIELDS = ['name', 'description'];

    /**
     * The hash of an experiment's design: its normalised definition without the presentation fields.
     *
     * @param string $configjson The stored definition.
     * @return string sha256.
     */
    public static function design_hash(string $configjson): string {
        $normalised = experiment_definition::from_json($configjson)->get_normalised();
        foreach (self::FIELDS as $field) {
            unset($normalised[$field]);
        }
        self::ksort_deep($normalised);

        return hash('sha256', json_encode($normalised, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Change an experiment's name and description.
     *
     * @param int $experimentid The experiment.
     * @param string $name The new name.
     * @param string $description The new description.
     * @param int $seen The version (timemodified) the editor showed.
     * @return array{ok: bool, error: string, timemodified: int} error is a string key of local_catquizlab.
     */
    public static function update(int $experimentid, string $name, string $description, int $seen): array {
        global $DB, $USER;

        require_capability('local/catquizlab:edit', \context_system::instance());
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $description = trim(str_replace("\r\n", "\n", $description));
        if ($name === '') {
            return ['ok' => false, 'error' => 'metadata:errorempty', 'timemodified' => $seen];
        }
        if (\core_text::strlen($name) > self::NAME_MAX) {
            return ['ok' => false, 'error' => 'metadata:errorlongname', 'timemodified' => $seen];
        }
        if (\core_text::strlen($description) > self::DESCRIPTION_MAX) {
            return ['ok' => false, 'error' => 'metadata:errorlongdescription', 'timemodified' => $seen];
        }

        // One change at a time per experiment, so two editors cannot interleave.
        $factory = \core\lock\lock_config::get_lock_factory('local_catquizlab_metadata');
        $lock = $factory->get_lock('experiment-' . $experimentid, 10);
        if (!$lock) {
            return ['ok' => false, 'error' => 'metadata:errorbusy', 'timemodified' => $seen];
        }
        try {
            $record = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid], '*', MUST_EXIST);
            if ((int) $record->timedeleting > 0) {
                return ['ok' => false, 'error' => 'metadata:errordeleting', 'timemodified' => (int) $record->timemodified];
            }
            // Somebody else saved since this editor was opened: refuse, never overwrite unseen (#152 AK6).
            if ((int) $record->timemodified !== $seen) {
                return ['ok' => false, 'error' => 'metadata:errorconflict', 'timemodified' => (int) $record->timemodified];
            }
            $definition = json_decode((string) $record->configjson, true);
            if (!is_array($definition)) {
                $definition = [];
            }
            $before = self::design_hash((string) $record->configjson);
            $olddescription = (string) ($definition['description'] ?? '');
            $definition['name'] = $name;
            $definition['description'] = $description;
            $configjson = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION);
            $after = self::design_hash($configjson);
            if ($before !== $after) {
                // Cannot happen with only these two fields; if it does, nothing is written.
                throw new \coding_exception('A metadata change would change the experimental design.');
            }
            // A second edit in the same second must still be a new version.
            $now = max(time(), (int) $record->timemodified + 1);
            $DB->update_record('local_catquizlab_experiment', (object) ['id' => $experimentid, 'name' => $name,
                'configjson' => $configjson, 'usermodified' => (int) ($USER->id ?? 0), 'timemodified' => $now]);

            \local_catquizlab\event\experiment_metadata_updated::create([
                'objectid' => $experimentid,
                'context' => \context_system::instance(),
                'other' => ['oldname' => (string) $record->name, 'newname' => $name,
                    'olddescription' => $olddescription, 'newdescription' => $description, 'designhash' => $after],
            ])->trigger();

            return ['ok' => true, 'error' => '', 'timemodified' => $now];
        } finally {
            $lock->release();
        }
    }

    /**
     * The history of an experiment's name and description, newest first, from the standard log.
     *
     * @param int $experimentid The experiment.
     * @param int $limit How many.
     * @return array[] when, who, oldname, newname. Empty where the standard log is off.
     */
    public static function history(int $experimentid, int $limit = 10): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('logstore_standard_log')) {
            return [];
        }
        $eventname = '\\' . \local_catquizlab\event\experiment_metadata_updated::class;
        $conditions = ['eventname' => $eventname, 'objectid' => $experimentid];
        $fields = 'id, userid, timecreated, other';
        $rows = $DB->get_records('logstore_standard_log', $conditions, 'timecreated DESC, id DESC', $fields, 0, $limit);
        $users = core_compat::users_by_id(array_values(array_unique(array_map(static fn($r) => (int) $r->userid, $rows))));
        $out = [];
        foreach ($rows as $row) {
            $other = self::decode_other($row->other);
            $user = $users[(int) $row->userid] ?? null;
            // An entry whose values cannot be read is listed as such — never as a change from "" to "".
            $unreadable = get_string('metadata:unreadable', 'local_catquizlab');
            $out[] = [
                'when' => userdate((int) $row->timecreated),
                'who' => $user ? fullname($user) : '—',
                'readable' => $other !== null,
                'oldname' => $other !== null ? format_string($other['oldname']) : $unreadable,
                'newname' => $other !== null ? format_string($other['newname']) : $unreadable,
                'description' => $other !== null && $other['olddescription'] !== $other['newdescription'],
            ];
        }

        return $out;
    }

    /**
     * The "other" of a logged metadata change, as the standard log stored it (#171).
     *
     * The standard log writes "other" as JSON, or as a PHP-serialized array where its "jsonformat" setting is off.
     * JSON is read here; a serialized value only through the log store's own decoder
     * (\logstore_standard\log\store::decode_other()), never by this plugin unserializing. That decoder restricts the
     * classes it makes; an object of any class comes back incomplete and is not read. Only the four values the
     * history shows are taken, each a string: names and descriptions, old and new.
     *
     * @param string|null $raw The column as stored.
     * @return array|null oldname, newname, olddescription, newdescription; null when the value is missing, not
     *     readable, or not of this event (no old and new name) — then nothing is read into it.
     */
    public static function decode_other(?string $raw): ?array {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $value = json_decode($raw, true);
        $serialized = $value === null && json_last_error() !== JSON_ERROR_NONE && preg_match('~^(N;|[aO]:\d+:)~', $raw);
        if ($serialized && class_exists(\logstore_standard\log\store::class)) {
            // A malformed serialization warns; it is unreadable, not an error of the page.
            set_error_handler(static fn() => true);
            try {
                $value = \logstore_standard\log\store::decode_other($raw);
            } catch (\Throwable $e) {
                $value = null;
            } finally {
                restore_error_handler();
            }
        }
        if (!is_array($value) || !array_key_exists('oldname', $value) || !array_key_exists('newname', $value)) {
            return null;
        }
        $out = [];
        foreach (['oldname', 'newname', 'olddescription', 'newdescription'] as $key) {
            $item = $value[$key] ?? '';
            if (!is_scalar($item) && $item !== null) {
                return null;
            }
            $out[$key] = (string) $item;
        }

        return $out;
    }

    /**
     * Sort an array's keys at every level, so a hash does not depend on key order.
     *
     * @param array $value The array.
     * @return void
     */
    protected static function ksort_deep(array &$value): void {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::ksort_deep($item);
            }
        }
    }
}
