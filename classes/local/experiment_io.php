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
 * JSON exchange of experiment settings.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Exports and imports an experiment definition as JSON.
 *
 * The JSON exchange is part of the reproducibility story rather than a
 * convenience: a definition has to be movable between instances, versionable,
 * archivable and checkable outside the UI, and publishable as a supplement to
 * the article. Two variants exist because they answer different questions.
 * The declarative export is what the author wrote, and is what you edit and
 * re-import. The normalised export is what Catquizlab actually used, defaults
 * resolved and aliases gone, and is what you archive next to the results.
 *
 * Imported JSON is untrusted input throughout. Nothing in it names a class, a
 * callback or a path; it is decoded, its schema is checked, and it then goes
 * through exactly the same validation as a definition typed into the form.
 */
class experiment_io {
    /** @var string Export variant: the definition as the author wrote it. */
    public const VARIANT_DECLARATIVE = 'declarative';

    /** @var string Export variant: defaults resolved and aliases normalised. */
    public const VARIANT_NORMALISED = 'normalised';

    /** @var int Maximum accepted upload size in bytes. */
    public const MAX_BYTES = 1048576;

    /** @var string Import: store as a new experiment. */
    public const CONFLICT_NEW = 'new';

    /** @var string Import: overwrite an existing draft. */
    public const CONFLICT_REPLACE = 'replace';

    /** @var string Import: keep the original and save this as a new version. */
    public const CONFLICT_VERSION = 'version';

    /**
     * Export an experiment as a JSON string.
     *
     * @param int $experimentid The experiment.
     * @param string $variant One of the VARIANT_* constants.
     * @return string Pretty-printed JSON.
     */
    public static function export(int $experimentid, string $variant = self::VARIANT_DECLARATIVE): string {
        global $DB;

        $record = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid], '*', MUST_EXIST);
        $definition = json_decode((string) $record->configjson, true) ?: [];

        if ($variant === self::VARIANT_NORMALISED) {
            $definition = (new experiment_definition($definition))->get_normalised();
        }

        $payload = [
            'schema'        => experiment_definition::SCHEMA,
            'schemaversion' => experiment_definition::SCHEMAVERSION,
            'variant'       => $variant,
            'exported'      => date('c'),
            'experiment'    => [
                'id'      => (int) $record->id,
                'name'    => (string) $record->name,
                'tier'    => (string) $record->tier,
                'runs'    => experiment_service::run_count($experimentid),
            ],
            'definition'    => $definition,
        ];

        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * A suggested file name for an export.
     *
     * @param int $experimentid The experiment.
     * @param string $variant One of the VARIANT_* constants.
     * @return string
     */
    public static function filename(int $experimentid, string $variant = self::VARIANT_DECLARATIVE): string {
        global $DB;

        $name = (string) $DB->get_field('local_catquizlab_experiment', 'name', ['id' => $experimentid]);
        $slug = clean_param(strtolower(str_replace(' ', '-', $name)), PARAM_ALPHANUMEXT);
        $slug = $slug !== '' ? $slug : ('experiment-' . $experimentid);

        return 'catquizlab-' . $slug . '-' . $variant . '.json';
    }

    /**
     * Parse and check an uploaded payload without storing anything.
     *
     * @param string $json The raw uploaded JSON.
     * @return array{ok: bool, errors: string[], warnings: string[], migrations: string[],
     *               definition: array, preview: array, conflict: array|null}
     */
    public static function inspect(string $json): array {
        $empty = [
            'ok'          => false,
            'errors'      => [],
            'warnings'    => [],
            'migrations'  => [],
            'definition'  => [],
            'preview'     => [],
            'conflict'    => null,
        ];

        if (strlen($json) > self::MAX_BYTES) {
            $empty['errors'][] = get_string('import:toolarge', 'local_catquizlab', self::MAX_BYTES);
            return $empty;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $empty['errors'][] = get_string('def:notjson', 'local_catquizlab');
            return $empty;
        }

        $schema = $decoded['schema'] ?? null;
        if ($schema !== null && $schema !== experiment_definition::SCHEMA) {
            $empty['errors'][] = get_string('import:wrongschema', 'local_catquizlab', (string) $schema);
            return $empty;
        }

        $version = $decoded['schemaversion'] ?? 1;
        if (!is_int($version) || $version < 1) {
            $empty['errors'][] = self::msg('schemaversion');
            return $empty;
        }
        if ($version > experiment_definition::SCHEMAVERSION) {
            // A newer schema may attach meaning to fields this code does not
            // know. Guessing would silently reinterpret the author's intent.
            $empty['errors'][] = get_string('def:schematoonew', 'local_catquizlab', (object) [
                'found'    => $version,
                'expected' => experiment_definition::SCHEMAVERSION,
            ]);
            return $empty;
        }

        // A payload may be a wrapped export or a bare definition.
        $definition = isset($decoded['definition']) && is_array($decoded['definition'])
            ? $decoded['definition']
            : $decoded;
        unset($definition['schema'], $definition['variant'], $definition['exported']);

        $migrations = [];
        if ($version < experiment_definition::SCHEMAVERSION) {
            $definition = self::migrate($definition, $version, $migrations);
        }

        $validation = experiment_service::validate($definition);
        $preview = $validation['valid'] ? experiment_service::preview($definition) : [];

        return [
            'ok'         => $validation['valid'],
            'errors'     => $validation['errors'],
            'warnings'   => $validation['warnings'],
            'migrations' => $migrations,
            'definition' => $definition,
            'preview'    => $preview,
            'conflict'   => self::find_conflict($definition),
        ];
    }

    /**
     * Store an inspected definition.
     *
     * Importing never starts a sweep or a run: the author has to look at the
     * preview and decide. An experiment that already has runs is never
     * overwritten, because its definition is the record of what those runs did.
     *
     * @param array $definition The definition, already inspected.
     * @param string $conflict One of the CONFLICT_* constants.
     * @param int|null $targetid The existing experiment when replacing.
     * @return array{id: int, created: bool}
     * @throws \moodle_exception If the target has runs, or the mode is unknown.
     */
    public static function store(array $definition, string $conflict = self::CONFLICT_NEW, ?int $targetid = null): array {
        global $DB;

        if ($conflict === self::CONFLICT_NEW) {
            $result = experiment_service::save($definition);
            return ['id' => (int) $result['id'], 'created' => true];
        }

        if ($conflict === self::CONFLICT_VERSION) {
            $definition['name'] = self::next_version_name($definition);
            $result = experiment_service::save($definition);
            return ['id' => (int) $result['id'], 'created' => true];
        }

        if ($conflict !== self::CONFLICT_REPLACE || $targetid === null) {
            throw new \moodle_exception('import:unknownmode', 'local_catquizlab', '', $conflict);
        }

        $existing = $DB->get_record('local_catquizlab_experiment', ['id' => $targetid], '*', MUST_EXIST);
        if (experiment_service::run_count((int) $targetid) > 0) {
            throw new \moodle_exception('experiment:hasruns', 'local_catquizlab', '', $existing->name);
        }
        $result = experiment_service::save($definition, (int) $targetid);

        return ['id' => (int) $result['id'], 'created' => false];
    }

    /**
     * Migrate a definition from an older schema version.
     *
     * Migrations are deterministic and are reported to the author, so an import
     * never changes meaning behind their back.
     *
     * @param array $definition The definition as written.
     * @param int $from The schema version it was written against.
     * @param string[] $migrations Accumulator for the applied migrations (by reference).
     * @return array The migrated definition.
     */
    protected static function migrate(array $definition, int $from, array &$migrations): array {
        if ($from < 2) {
            if (isset($definition['model']) && is_string($definition['model'])) {
                $normalised = model_catalog::normalise($definition['model']);
                if ($normalised !== null && $normalised !== $definition['model']) {
                    $migrations[] = get_string('import:migratedmodel', 'local_catquizlab', (object) [
                        'from' => $definition['model'],
                        'to'   => $normalised,
                    ]);
                    $definition['model'] = $normalised;
                }
            }
            if (isset($definition['budgets']['setarget'])) {
                $migrations[] = get_string('import:migratedsetarget', 'local_catquizlab');
            }
            if (isset($definition['budgets']['minitems']) && !isset($definition['budgets']['global'])) {
                $migrations[] = get_string('import:migratedbudgets', 'local_catquizlab');
            }
            $definition['schemaversion'] = experiment_definition::SCHEMAVERSION;
        }

        return $definition;
    }

    /**
     * Find an existing experiment with the same name.
     *
     * @param array $definition The definition being imported.
     * @return array|null ['id' => int, 'name' => string, 'runs' => int], or null when free.
     */
    protected static function find_conflict(array $definition): ?array {
        global $DB;

        $name = trim((string) ($definition['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $existing = $DB->get_records('local_catquizlab_experiment', ['name' => $name], 'id', 'id, name', 0, 1);
        if (!$existing) {
            return null;
        }
        $record = reset($existing);
        $runs = experiment_service::run_count((int) $record->id);

        return [
            'id'         => (int) $record->id,
            'name'       => (string) $record->name,
            'runs'       => $runs,
            // With runs present, replacing would rewrite history, so only the
            // non-destructive modes remain open.
            'canreplace' => $runs === 0,
        ];
    }

    /**
     * Derive a name for a new version of an experiment.
     *
     * @param array $definition The definition being imported.
     * @return string
     */
    protected static function next_version_name(array $definition): string {
        global $DB;

        $base = trim((string) ($definition['name'] ?? 'Experiment'));
        $version = 2;
        while ($DB->record_exists('local_catquizlab_experiment', ['name' => $base . ' v' . $version])) {
            $version++;
            if ($version > 999) {
                break;
            }
        }

        return $base . ' v' . $version;
    }

    /**
     * Format a "must be a positive integer" message for a field.
     *
     * @param string $label The field label.
     * @return string
     */
    protected static function msg(string $label): string {
        return get_string('def:positiveint', 'local_catquizlab', $label);
    }
}
