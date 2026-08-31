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
 * The experiment service: the one entry point CLI, web UI and API share.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Saving, validating, previewing and expanding an experiment.
 *
 * The web UI must not become a second implementation of the experiment logic.
 * If it were, a sweep started from a form and the same sweep started from
 * cli/sweep.php could produce different cells, and the results would not be
 * comparable — which defeats the purpose of the suite.
 *
 * So the entry points stay thin: they resolve parameters, check the capability
 * for the action they perform, call one method here and render what comes back.
 * Everything factual — validation, normalisation, cell expansion, seeds — lives
 * behind this class and is reached the same way from every direction.
 */
class experiment_service {
    /** @var int Draft: editable, never executed. */
    public const STATUS_DRAFT = 0;

    /** @var int Validated: passed validation, no runs yet. */
    public const STATUS_VALIDATED = 10;

    /** @var int Executed: runs exist, so the definition is history and immutable. */
    public const STATUS_EXECUTED = 20;

    /** @var int Archived: kept for the record, not offered for new sweeps. */
    public const STATUS_ARCHIVED = 30;

    /** @var int Above this many runs a sweep is flagged as large. */
    public const LARGE_SWEEP_RUNS = 10000;

    /**
     * Validate a definition without storing anything.
     *
     * @param array $definition The definition as written.
     * @return array{valid: bool, errors: string[], warnings: string[], normalised: array}
     */
    public static function validate(array $definition): array {
        $object = new experiment_definition($definition);
        $result = $object->validate();
        $result['normalised'] = $object->get_normalised();

        return $result;
    }

    /**
     * Save an experiment, creating or updating it.
     *
     * An experiment with runs is not updated in place: its definition is the
     * record of what those runs actually did, and rewriting it would make the
     * stored results describe a configuration that never ran.
     *
     * @param array $definition The definition as written.
     * @param int|null $id The experiment to update, or null to create.
     * @param bool $force Allow updating an experiment that already has runs.
     * @return array{id: int, created: bool, valid: bool, errors: string[], warnings: string[]}
     * @throws \moodle_exception If the experiment has runs and $force is not set.
     */
    public static function save(array $definition, ?int $id = null, bool $force = false): array {
        global $DB, $USER;

        $result = self::validate($definition);
        $now = time();
        $normalised = $result['normalised'];

        $record = (object) [
            'name'         => (string) ($normalised['name'] ?? get_string('experiment:untitled', 'local_catquizlab')),
            'tier'         => (string) ($normalised['tier'] ?? 'baseline'),
            // PRESERVE_ZERO_FRACTION keeps a discrimination of 1.0 a float;
            // without it the stored definition differs in type from the one
            // that was validated.
            'configjson'   => json_encode(
                $definition,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            ),
            'status'       => $result['valid'] ? self::STATUS_VALIDATED : self::STATUS_DRAFT,
            'usermodified' => (int) ($USER->id ?? 0),
            'timemodified' => $now,
        ];

        if ($id === null) {
            $record->timecreated = $now;
            $newid = (int) $DB->insert_record('local_catquizlab_experiment', $record);

            return ['id' => $newid, 'created' => true] + $result;
        }

        $existing = $DB->get_record('local_catquizlab_experiment', ['id' => $id], '*', MUST_EXIST);
        if (!$force && self::run_count($id) > 0) {
            throw new \moodle_exception('experiment:hasruns', 'local_catquizlab', '', $existing->name);
        }

        $record->id = $id;
        if (self::run_count($id) > 0) {
            $record->status = self::STATUS_EXECUTED;
        }
        $DB->update_record('local_catquizlab_experiment', $record);

        return ['id' => (int) $id, 'created' => false] + $result;
    }

    /**
     * Duplicate an experiment as a fresh draft.
     *
     * @param int $id The experiment to copy.
     * @param string|null $name Name of the copy; defaults to the original plus a suffix.
     * @return int The new experiment id.
     */
    public static function duplicate(int $id, ?string $name = null): int {
        global $DB;

        $source = $DB->get_record('local_catquizlab_experiment', ['id' => $id], '*', MUST_EXIST);
        $definition = json_decode((string) $source->configjson, true) ?: [];
        $definition['name'] = $name ?? ($source->name . ' ' . get_string('experiment:copysuffix', 'local_catquizlab'));

        return (int) self::save($definition)['id'];
    }

    /**
     * Archive an experiment.
     *
     * @param int $id The experiment.
     * @return void
     */
    public static function archive(int $id): void {
        global $DB;

        $DB->set_field('local_catquizlab_experiment', 'status', self::STATUS_ARCHIVED, ['id' => $id]);
        $DB->set_field('local_catquizlab_experiment', 'timemodified', time(), ['id' => $id]);
    }

    /**
     * Delete an experiment that has never been executed.
     *
     * @param int $id The experiment.
     * @return void
     * @throws \moodle_exception If runs exist for the experiment.
     */
    public static function delete(int $id): void {
        global $DB;

        if (self::run_count($id) > 0) {
            throw new \moodle_exception('experiment:hasruns', 'local_catquizlab', '', (string) $id);
        }
        $DB->delete_records('local_catquizlab_experiment', ['id' => $id]);
    }

    /**
     * Preview the sweep a definition expands to, without creating anything.
     *
     * The counts come from the same expansion the sweep itself uses, so what
     * the preview promises is what gets created.
     *
     * @param array $definition The definition as written.
     * @return array{cells: int, replications: int, runs: int, attempts: int, large: bool,
     *               excluded: int, errors: string[], warnings: string[], factors: array}
     */
    public static function preview(array $definition): array {
        $validation = self::validate($definition);
        if (!$validation['valid']) {
            return [
                'cells'        => 0,
                'replications' => 0,
                'runs'         => 0,
                'attempts'     => 0,
                'large'        => false,
                'excluded'     => 0,
                'errors'       => $validation['errors'],
                'warnings'     => $validation['warnings'],
                'factors'      => [],
            ];
        }

        $normalised = $validation['normalised'];
        $expansion = sweep::expand(self::sweep_spec($normalised));
        $runs = count($expansion['runs'] ?? []);
        $capacity = (array) ($expansion['capacity'] ?? []);

        $warnings = $validation['warnings'];
        if ($runs > self::LARGE_SWEEP_RUNS) {
            $warnings[] = get_string('sweep:large', 'local_catquizlab', number_format($runs));
        }

        return [
            'cells'        => count($expansion['cells'] ?? []),
            'replications' => (int) ($normalised['replications'] ?? 1),
            'runs'         => $runs,
            'attempts'     => (int) ($capacity['attempts'] ?? 0),
            'large'        => $runs > self::LARGE_SWEEP_RUNS,
            'excluded'     => (int) ($expansion['excluded'] ?? 0),
            'errors'       => [],
            'warnings'     => $warnings,
            'factors'      => (array) ($normalised['sweep']['factors'] ?? []),
        ];
    }

    /**
     * Expand an experiment into runs and persist them.
     *
     * @param int $experimentid The experiment.
     * @return array{created: int, cells: int, runs: int[]}
     * @throws \moodle_exception If the stored definition does not validate.
     */
    public static function create_sweep(int $experimentid): array {
        global $DB;

        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid], '*', MUST_EXIST);
        $definition = json_decode((string) $experiment->configjson, true) ?: [];
        $validation = self::validate($definition);
        if (!$validation['valid']) {
            throw new \moodle_exception(
                'experiment:invalid',
                'local_catquizlab',
                '',
                implode('; ', $validation['errors'])
            );
        }

        $normalised = $validation['normalised'];
        $expansion = sweep::expand(self::sweep_spec($normalised));
        $master = (int) ($normalised['seed'] ?? 42);
        $now = time();

        $runids = [];
        $transaction = $DB->start_delegated_transaction();
        foreach ($expansion['runs'] ?? [] as $run) {
            $runids[] = (int) $DB->insert_record('local_catquizlab_run', (object) [
                'experimentid' => $experimentid,
                'cellkey'      => (string) ($run['cellkey'] ?? ''),
                'masterseed'   => $master,
                'seed'         => (int) ($run['seed'] ?? $master),
                'replication'  => (int) ($run['replication'] ?? 1),
                'status'       => registry::STATUS_DRAFT,
                'manifestjson' => json_encode(
                    manifest::build_for_run((array) ($run['definition'] ?? $normalised), [
                        'cellkey'     => (string) ($run['cellkey'] ?? ''),
                        'replication' => (int) ($run['replication'] ?? 1),
                        'seeds'       => seed_domains::manifest_block(
                            $master,
                            (int) ($run['replication'] ?? 1),
                            (string) ($run['definition']['persons']['stratum'] ?? 'conforming'),
                            (string) ($run['definition']['persons']['severity'] ?? 'none'),
                            (string) ($run['definition']['pool']['variant'] ?? 'ideal'),
                            (string) ($run['definition']['model'] ?? '2pl')
                        ),
                    ]),
                    JSON_UNESCAPED_SLASHES
                ),
                'courseid'     => null,
                'testcmid'     => null,
                'usermodified' => 0,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }
        $DB->set_field('local_catquizlab_experiment', 'status', self::STATUS_EXECUTED, ['id' => $experimentid]);
        $transaction->allow_commit();

        // Runs now depend on whatever building blocks the definition cited, so
        // those blocks may no longer be edited in place.
        foreach (['poolpreset', 'personspreset'] as $field) {
            $presetid = (int) ($normalised[$field] ?? 0);
            if ($presetid > 0) {
                preset_library::record_use($presetid, true);
            }
        }

        return [
            'created' => count($runids),
            'cells'   => count($expansion['cells'] ?? []),
            'runs'    => $runids,
        ];
    }

    /**
     * Build the sweep specification from a normalised definition.
     *
     * The definition carries its own sweep block; everything else the expander
     * needs is the definition itself as the non-swept base. Keeping this in one
     * place is what guarantees the UI and the CLI expand to the same cells.
     *
     * @param array $normalised The normalised experiment definition.
     * @return array The sweep specification.
     */
    public static function sweep_spec(array $normalised): array {
        $sweep = (array) ($normalised['sweep'] ?? []);

        return [
            'base'                        => $normalised,
            'factors'                     => (array) ($sweep['factors'] ?? []),
            'exclude'                     => (array) ($sweep['exclude'] ?? []),
            'replications'                => (int) ($normalised['replications'] ?? 1),
            'seed'                        => (int) ($normalised['seed'] ?? 42),
            'estimatedsecondsperattempt'  => (int) ($sweep['estimatedsecondsperattempt'] ?? 30),
            'maxcells'                    => $sweep['maxcells'] ?? null,
        ];
    }

    /**
     * The number of runs an experiment has.
     *
     * @param int $experimentid The experiment.
     * @return int
     */
    public static function run_count(int $experimentid): int {
        global $DB;

        return (int) $DB->count_records('local_catquizlab_run', ['experimentid' => $experimentid]);
    }

    /**
     * A summary row per experiment, for the overview table.
     *
     * @param array $conditions Optional field conditions.
     * @return array[] One row per experiment.
     */
    public static function overview(array $conditions = []): array {
        global $DB;

        $rows = [];
        foreach ($DB->get_records('local_catquizlab_experiment', $conditions, 'timemodified DESC') as $record) {
            $definition = json_decode((string) $record->configjson, true) ?: [];
            $normalised = (new experiment_definition($definition))->get_normalised();
            $model = (string) ($normalised['model'] ?? '');
            $strategy = (string) ($normalised['strategy'] ?? '');

            $rows[] = [
                'id'           => (int) $record->id,
                'name'         => (string) $record->name,
                'tier'         => (string) $record->tier,
                'status'       => (int) $record->status,
                'statuslabel'  => self::status_label((int) $record->status),
                'model'        => $model,
                'modellabel'   => model_catalog::has($model) ? model_catalog::label($model) : $model,
                'strategy'     => $strategy,
                'strategylabel' => strategy_catalog::has($strategy)
                    ? strategy_catalog::label($strategy)
                    : $strategy,
                'variant'      => (string) ($normalised['pool']['variant'] ?? 'ideal'),
                'stratum'      => (string) ($normalised['persons']['stratum'] ?? ''),
                'severity'     => (string) ($normalised['persons']['severity'] ?? 'none'),
                'replications' => (int) ($normalised['replications'] ?? 1),
                'cells'        => self::cell_count($normalised),
                'runs'         => self::run_count((int) $record->id),
                'timemodified' => (int) $record->timemodified,
            ];
        }

        return $rows;
    }

    /**
     * How many experimental cells a definition expands to.
     *
     * Expanding can fail on an invalid definition, and a draft is allowed to be
     * invalid, so the overview reports a dash rather than refusing to render.
     *
     * @param array $normalised The normalised definition.
     * @return int|string The cell count, or an em dash when it cannot be determined.
     */
    public static function cell_count(array $normalised) {
        try {
            $expansion = sweep::expand(self::sweep_spec($normalised));
            return count($expansion['cells'] ?? []);
        } catch (\Throwable $e) {
            return '—';
        }
    }

    /**
     * The human-readable name of an experiment status.
     *
     * @param int $status One of the STATUS_* constants.
     * @return string
     */
    public static function status_label(int $status): string {
        $keys = [
            self::STATUS_DRAFT     => 'status:draft',
            self::STATUS_VALIDATED => 'status:validated',
            self::STATUS_EXECUTED  => 'status:executed',
            self::STATUS_ARCHIVED  => 'status:archived',
        ];

        return get_string($keys[$status] ?? 'status:draft', 'local_catquizlab');
    }
}
