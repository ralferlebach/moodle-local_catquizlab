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
 * Listing, filtering and comparing runs.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Reads the run registry for the UI: filtered listings, run detail and the
 * cell-level aggregation a comparison needs.
 *
 * A run's experimental coordinates — strategy, model, pool variant, stratum,
 * severity — are not columns on the run row; they live in the definition the
 * cell was expanded from. Rather than denormalise them into the table, this
 * class resolves them per run and offers them as filters. That keeps the stored
 * run as the record of what happened and the definition as the single source
 * for what it means.
 *
 * The aggregation groups by cell rather than by run, because a cell with
 * replications is the unit the design compares: one mean with its spread, not
 * a hundred individual numbers.
 */
class run_registry {
    /** @var string[] The filters a caller may apply. */
    public const FILTERS = [
        'experimentid', 'status', 'strategy', 'model', 'variant', 'stratum', 'severity', 'replication',
    ];

    /** @var int Rows returned per page by default. */
    public const PER_PAGE = 50;

    /**
     * List runs, resolved and filtered.
     *
     * @param array $filters Field => value, restricted to {@see self::FILTERS}.
     * @param int $page Zero-based page number.
     * @param int $perpage Rows per page.
     * @return array{rows: array[], total: int} The page of rows and the filtered total.
     */
    public static function listing(array $filters = [], int $page = 0, int $perpage = self::PER_PAGE): array {
        global $DB;

        // Only the experiment, status and replication are real columns, so only
        // those narrow the query; the rest are resolved from the definition and
        // filtered in PHP.
        $conditions = [];
        if (!empty($filters['experimentid'])) {
            $conditions['experimentid'] = (int) $filters['experimentid'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $conditions['status'] = (int) $filters['status'];
        }
        if (!empty($filters['replication'])) {
            $conditions['replication'] = (int) $filters['replication'];
        }

        $records = $DB->get_records('local_catquizlab_run', $conditions, 'id DESC');

        $rows = [];
        foreach ($records as $record) {
            $row = self::describe($record);
            if (self::matches($row, $filters)) {
                $rows[] = $row;
            }
        }

        $total = count($rows);
        $offset = max(0, $page) * max(1, $perpage);

        return ['rows' => array_slice($rows, $offset, max(1, $perpage)), 'total' => $total];
    }

    /**
     * Describe one run: its coordinates, status and progress.
     *
     * @param \stdClass $record The run record.
     * @return array
     */
    public static function describe(\stdClass $record): array {
        global $DB;

        $definition = self::definition_for($record);
        $model = (string) ($definition['model'] ?? '');
        $strategy = (string) ($definition['strategy'] ?? '');
        $attempts = $DB->count_records('local_catquizlab_attempt', ['runid' => $record->id]);
        $done = $DB->count_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status >= :status',
            ['runid' => $record->id, 'status' => registry::STATUS_FINISHED]
        );

        return [
            'id'            => (int) $record->id,
            'experimentid'  => (int) $record->experimentid,
            'experiment'    => (string) $DB->get_field(
                'local_catquizlab_experiment',
                'name',
                ['id' => $record->experimentid]
            ),
            'cellkey'       => (string) $record->cellkey,
            'replication'   => (int) $record->replication,
            'seed'          => (int) $record->seed,
            'masterseed'    => (int) ($record->masterseed ?? 0),
            'status'        => (int) $record->status,
            'statuslabel'   => self::status_label((int) $record->status),
            'strategy'      => $strategy,
            'strategylabel' => strategy_catalog::has($strategy) ? strategy_catalog::label($strategy) : $strategy,
            'model'         => $model,
            'modellabel'    => model_catalog::has($model) ? model_catalog::label($model) : $model,
            'variant'       => (string) ($definition['pool']['variant'] ?? 'ideal'),
            'recipe'        => (array) ($definition['pool']['recipe'] ?? []),
            'strength'      => self::disturbance_strength(
                (string) ($definition['pool']['variant'] ?? 'ideal'),
                (array) ($definition['pool']['recipe'] ?? [])
            ),
            'stratum'       => (string) ($definition['persons']['stratum'] ?? ''),
            'severity'      => (string) ($definition['persons']['severity'] ?? 'none'),
            'attempts'      => $attempts,
            'attemptsdone'  => $done,
            'progress'      => $attempts > 0 ? (int) round(100 * $done / $attempts) : 0,
            'timecreated'   => (int) $record->timecreated,
            'timemodified'  => (int) $record->timemodified,
            'duration'      => max(0, (int) $record->timemodified - (int) $record->timecreated),
        ];
    }

    /**
     * The strength of a pool disturbance, on the scale that variant uses.
     *
     * Robustness is read against the size of the disturbance, so the design
     * needs one number per run that says how badly the pool was disturbed. Each
     * variant measures that differently — a share of affected items, a shift in
     * logits, a stretch factor — so the figure is only comparable within a
     * variant, which is how the robustness view groups it.
     *
     * @param string $variant The pool variant.
     * @param array $recipe The variant recipe.
     * @return float|null The strength, or null for the ideal pool and unknown variants.
     */
    public static function disturbance_strength(string $variant, array $recipe): ?float {
        $recipe = pool_mutator::apply_recipe_defaults($variant, $recipe);

        switch ($variant) {
            case 'depleted':
            case 'taggingerror':
            case 'calibrationerror':
                return isset($recipe['fraction']) ? round((float) $recipe['fraction'], 6) : null;
            case 'shifted':
                return isset($recipe['shift']) ? round(abs((float) $recipe['shift']), 6) : null;
            case 'stretched':
                return isset($recipe['factor']) ? round((float) $recipe['factor'], 6) : null;
            case 'gappy':
                if (isset($recipe['gapmin'], $recipe['gapmax'])) {
                    return round((float) $recipe['gapmax'] - (float) $recipe['gapmin'], 6);
                }
                return null;
            default:
                // The ideal pool has no disturbance, and 'combined' has several
                // that do not reduce to one number.
                return null;
        }
    }

    /**
     * The unit the strength of a variant is measured in.
     *
     * @param string $variant The pool variant.
     * @return string A language string key suffix, or an empty string when there is no strength.
     */
    public static function strength_unit(string $variant): string {
        $units = [
            'depleted'         => 'share',
            'taggingerror'     => 'share',
            'calibrationerror' => 'share',
            'shifted'          => 'logits',
            'stretched'        => 'factor',
            'gappy'            => 'logits',
        ];

        return $units[$variant] ?? '';
    }

    /**
     * The full detail of one run, for its detail page.
     *
     * @param int $runid The run.
     * @return array{run: array, manifest: array, metrics: array, failure: string|null}
     */
    public static function detail(int $runid): array {
        global $DB;

        $record = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
        $manifest = json_decode((string) $record->manifestjson, true) ?: [];

        return [
            'run'      => self::describe($record),
            'manifest' => $manifest,
            'metrics'  => report_builder::run_report($runid)['scopes'] ?? [],
            'failure'  => self::failure_detail($record),
        ];
    }

    /**
     * A diagnostically useful failure description, or null when the run is fine.
     *
     * "Run failed." is not an answer anybody can act on. When a stage recorded
     * why it stopped, that reason is what belongs on the screen.
     *
     * @param \stdClass $record The run record.
     * @return string|null
     */
    protected static function failure_detail(\stdClass $record): ?string {
        if ((int) $record->status !== registry::STATUS_FAILED) {
            return null;
        }
        $manifest = json_decode((string) $record->manifestjson, true) ?: [];
        $reason = $manifest['config']['failure'] ?? null;

        return is_string($reason) && $reason !== ''
            ? $reason
            : get_string('run:failedunknown', 'local_catquizlab');
    }

    /**
     * Aggregate a metric across the cells of an experiment.
     *
     * Groups the runs by the value of the chosen factor, so two strategies or
     * two pool variants can be put side by side with their mean, spread and a
     * 95% interval over the replications.
     *
     * @param int $experimentid The experiment.
     * @param string $metric The metric name, e.g. rmse.
     * @param string $groupby One of {@see self::FILTERS}, e.g. strategy.
     * @param string $scope The result scope, usually 'run'.
     * @return array[] One row per group, sorted by group label.
     */
    public static function compare(
        int $experimentid,
        string $metric,
        string $groupby = 'strategy',
        string $scope = 'run'
    ): array {
        global $DB;

        if (!in_array($groupby, self::FILTERS, true)) {
            throw new \coding_exception('Unknown grouping factor: ' . $groupby);
        }

        $records = $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC');

        $groups = [];
        foreach ($records as $record) {
            $row = self::describe($record);
            $key = (string) ($row[$groupby] ?? '');
            $value = $DB->get_field('local_catquizlab_result', 'value', [
                'runid'  => $record->id,
                'metric' => $metric,
                'scope'  => $scope,
            ]);
            if ($value === false || $value === null) {
                continue;
            }
            $groups[$key][] = (float) $value;
        }

        $rows = [];
        foreach ($groups as $key => $values) {
            $stability = trend_analysis::stability($values);
            $n = count($values);
            $sd = $stability['sd'];
            // A normal-approximation interval on the mean over replications.
            // With fewer than two replications there is no spread to report,
            // and pretending otherwise would suggest a precision that is not there.
            $halfwidth = ($n > 1 && $sd !== null) ? 1.96 * $sd / sqrt($n) : null;

            $rows[] = [
                'group'  => $key,
                'label'  => self::group_label($groupby, $key),
                'n'      => $n,
                'mean'   => $stability['mean'],
                'sd'     => $sd,
                'ci95lo' => $halfwidth === null ? null : round($stability['mean'] - $halfwidth, 6),
                'ci95hi' => $halfwidth === null ? null : round($stability['mean'] + $halfwidth, 6),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * The distinct values a factor takes among an experiment's runs.
     *
     * @param int|null $experimentid Restrict to one experiment, or null for all.
     * @param string $factor One of {@see self::FILTERS}.
     * @return array<string, string> Value => label, for a filter menu.
     */
    public static function factor_values(?int $experimentid, string $factor): array {
        global $DB;

        $conditions = $experimentid !== null ? ['experimentid' => $experimentid] : [];
        $values = [];
        foreach ($DB->get_records('local_catquizlab_run', $conditions, 'id ASC') as $record) {
            $row = self::describe($record);
            $value = (string) ($row[$factor] ?? '');
            if ($value !== '') {
                $values[$value] = self::group_label($factor, $value);
            }
        }
        ksort($values);

        return $values;
    }

    /**
     * The publication label of a factor value.
     *
     * @param string $factor The factor name.
     * @param string $value The value.
     * @return string
     */
    public static function group_label(string $factor, string $value): string {
        if ($factor === 'strategy' && strategy_catalog::has($value)) {
            return strategy_catalog::label($value);
        }
        if ($factor === 'model' && model_catalog::has($value)) {
            return model_catalog::label($value);
        }
        foreach (['variant' => 'variant:', 'stratum' => 'stratum:', 'severity' => 'severity:'] as $name => $prefix) {
            if ($factor === $name) {
                $key = $prefix . $value;
                $manager = get_string_manager();
                if ($manager->string_exists($key, 'local_catquizlab')) {
                    return get_string($key, 'local_catquizlab');
                }
            }
        }

        return $value;
    }

    /**
     * The human-readable name of a run status.
     *
     * @param int $status One of the registry STATUS_* constants.
     * @return string
     */
    public static function status_label(int $status): string {
        $keys = [
            registry::STATUS_DRAFT     => 'status:draft',
            registry::STATUS_SCHEDULED => 'status:scheduled',
            registry::STATUS_RUNNING   => 'status:running',
            registry::STATUS_FINISHED  => 'status:finished',
            registry::STATUS_FAILED    => 'status:failed',
        ];

        return get_string($keys[$status] ?? 'status:draft', 'local_catquizlab');
    }

    /**
     * All statuses as a filter menu.
     *
     * @return array<int, string>
     */
    public static function status_menu(): array {
        $menu = [];
        foreach (
            [
            registry::STATUS_DRAFT,
            registry::STATUS_SCHEDULED,
            registry::STATUS_RUNNING,
            registry::STATUS_FINISHED,
            registry::STATUS_FAILED,
            ] as $status
        ) {
            $menu[$status] = self::status_label($status);
        }
        return $menu;
    }

    /**
     * The normalised definition a run was expanded from.
     *
     * @param \stdClass $record The run record.
     * @return array
     */
    protected static function definition_for(\stdClass $record): array {
        global $DB;

        // The manifest holds the definition as it was at expansion time, which
        // is what this run actually used. The experiment row is the fallback
        // for runs created before manifests carried it.
        $manifest = json_decode((string) $record->manifestjson, true) ?: [];
        if (isset($manifest['config']['definition']) && is_array($manifest['config']['definition'])) {
            return $manifest['config']['definition'];
        }

        $configjson = (string) $DB->get_field(
            'local_catquizlab_experiment',
            'configjson',
            ['id' => $record->experimentid]
        );
        if ($configjson === '') {
            return [];
        }

        return experiment_definition::from_json($configjson)->get_normalised();
    }

    /**
     * Whether a described run satisfies the PHP-side filters.
     *
     * @param array $row The described run.
     * @param array $filters The requested filters.
     * @return bool
     */
    protected static function matches(array $row, array $filters): bool {
        foreach (['strategy', 'model', 'variant', 'stratum', 'severity'] as $key) {
            if (!empty($filters[$key]) && (string) $row[$key] !== (string) $filters[$key]) {
                return false;
            }
        }
        return true;
    }
}
