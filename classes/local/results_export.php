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
 * Export of the results currently on screen.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Turns the filtered results into flat data files.
 *
 * The export takes the same filter the screen is showing. Anything else would
 * be a trap: a reader looks at one selection, exports, and gets another. The
 * filter is therefore written into the export's own metadata, so a file that
 * has been moved or renamed still says what it contains.
 *
 * Four levels are offered because analyses ask different questions of the same
 * run: the run level for cell comparisons, the attempt level for per-person
 * outcomes, the subscale level for the local diagnostics, and the item level
 * for the ground truth against what the engine was told. Each level is a flat
 * rectangle — no nesting, no repeated headers — so it opens in a statistics
 * package without being reshaped first.
 */
class results_export {
    /** @var string One row per run. */
    public const LEVEL_RUN = 'run';

    /** @var string One row per attempt. */
    public const LEVEL_ATTEMPT = 'attempt';

    /** @var string One row per subscale of an attempt. */
    public const LEVEL_SUBSCALE = 'subscale';

    /** @var string One row per materialised item. */
    public const LEVEL_ITEM = 'item';

    /** @var float Seconds per pool item and step for TI@n, measured on the engine (0.6 µs) with headroom. */
    public const SECONDS_PER_ITEM_STEP = 0.8e-6;

    /** @var int Above this estimate, TI@n is left out of a step export unless asked for (#99). */
    public const ENGINE_INFO_SOFT_LIMIT = 60;

    /** @var int Above this estimate, TI@n is not offered at all: the download would outrun its time limit. */
    public const ENGINE_INFO_HARD_LIMIT = 540;

    /** @var bool Whether the step level computes TI@n and the remaining potential. */
    public static bool $engineinfo = true;

    /**
     * What TI@n in a step export would cost for this selection, before it is started (#99).
     *
     * Steps times the size of each run's item pool times the measured cost of
     * one item's information: the engine computes the information of every
     * pool item at every step.
     *
     * @param results_query $query The selection.
     * @return array{steps: int, items: int, seconds: float}
     */
    public static function engine_info_cost(results_query $query): array {
        global $DB;

        $steps = [];
        foreach ($query->each_observation() as $row) {
            $steps[(int) $row['runid']] = ($steps[(int) $row['runid']] ?? 0) + (int) ($row['nitems'] ?? 0);
        }
        $work = 0.0;
        $items = 0;
        foreach ($steps as $runid => $count) {
            $pool = $DB->count_records('local_catquizlab_item', ['runid' => $runid]);
            $items = max($items, $pool);
            $work += $count * $pool;
        }

        return ['steps' => array_sum($steps), 'items' => $items, 'seconds' => $work * self::SECONDS_PER_ITEM_STEP];
    }

    /** @var string One row per step of every sitting (#106). */
    public const LEVEL_STEP = 'step';

    /** @var string[] The columns of the step level. */
    public const STEP_COLUMNS = [
        'attemptid', 'runid', 'strategy', 'step', 'questionid', 'subscaleid', 'score',
        'esttheta', 'se', 'ti_played', 'ti_at_n', 'ti_remaining_min', 'ti_remaining_max', 'scales_estimated',
    ];

    /**
     * The levels an export can be taken at.
     *
     * @return array<string, string> Level => language string key.
     */
    public static function levels(): array {
        return [
            self::LEVEL_RUN      => 'export:levelrun',
            self::LEVEL_ATTEMPT  => 'export:levelattempt',
            self::LEVEL_SUBSCALE => 'export:levelsubscale',
            self::LEVEL_ITEM     => 'export:levelitem',
            self::LEVEL_STEP     => 'export:levelstep',
        ];
    }

    /**
     * How many rows an export would have, without building it.
     *
     * Opening the export tab used to build all four datasets — run, sitting,
     * subscale and item — so that four numbers could be printed beside four
     * download links. On a large experiment that is the whole export, four
     * times over, to answer "how big is it".
     *
     * @param results_query $query The selection.
     * @param string $level One of the LEVEL_ constants.
     * @return int
     */
    public static function row_count(results_query $query, string $level): int {
        // Counted from the stream, never from a materialised selection.
        $observations = $query->each_observation();

        switch ($level) {
            case self::LEVEL_RUN:
                // One row per run in the selection.
                $runs = [];
                foreach ($observations as $row) {
                    $runs[(int) $row['runid']] = true;
                }
                return count($runs);

            case self::LEVEL_ATTEMPT:
                return (int) $query->size_check()['count'];

            case self::LEVEL_STEP:
                // One row per question each sitting was asked.
                $total = 0;
                foreach ($observations as $row) {
                    $total += (int) ($row['nitems'] ?? 0);
                }

                return $total;

            case self::LEVEL_SUBSCALE:
            case self::LEVEL_ITEM:
                // These expand per observation, and the factor is the number
                // of subscales or of items the sitting used. Counted from the
                // observations rather than by building the rows.
                $total = 0;
                foreach ($observations as $row) {
                    $total += $level === self::LEVEL_ITEM
                        ? count((array) ($row['items'] ?? []))
                        : (int) ($row['nscales'] ?? 0);
                }

                // Subscale rows are one per subscale a sitting reports on,
                // which each observation now carries as a count.
                return $total;

            default:
                return 0;
        }
    }

    /**
     * An export's columns and a stream of its rows.
     *
     * The same rows as {@see dataset()}, yielded one at a time for the two
     * levels that grow with the number of sittings. The download and the raw
     * data view read this; nothing then holds the export in memory.
     *
     * @param results_query $query The selection.
     * @param string $level One of the LEVEL_ constants.
     * @return array{columns: string[], rows: iterable}
     */
    public static function iterate(results_query $query, string $level): array {
        switch ($level) {
            case self::LEVEL_STEP:
                return ['columns' => self::STEP_COLUMNS, 'rows' => self::step_rows($query)];
            case self::LEVEL_ATTEMPT:
                return ['columns' => self::ATTEMPT_COLUMNS, 'rows' => self::attempt_rows($query->each_observation())];
            case self::LEVEL_SUBSCALE:
                return self::subscales($query, true);
            default:
                return self::dataset($query, $level);
        }
    }

    /** @var string[] The columns of the sitting-level export. */
    public const ATTEMPT_COLUMNS = [
        'attemptid', 'runid', 'personid', 'twinid', 'replication', 'tier',
        'strategy', 'model', 'variant', 'strength', 'stratum', 'severity',
        'truetheta', 'esttheta', 'error', 'nitems', 'se', 'stopreason',
        'stopreached', 'runtimems',
        // How and where each sitting ended (#106).
        'endreasoncode', 'endreasonlabel', 'finalti', 'activescalesatend',
        // How the true abilities were drawn, and the scale range they and the
        // engine's estimates live in (#102) — on every row, so that a CSV
        // without metadata still says it. Appended: no column moves.
        'abilitydistribution', 'abilitymean', 'abilitysd', 'abilitymin', 'abilitymax',
        // TI@n at the end (#106): the n most informative pool items at the
        // final estimate, n the test length — from the engine. finalti above
        // is the information of the items played.
        'finaltiatn',
        // Three things kept apart (#118): did the engine finish, did it end as
        // the design planned, may it go into the figures — and why not.
        'valid', 'validityreason', 'enginefinished', 'designstopreached',
        // Both verdicts (#112): the engine's definitions for every strategy, and
        // the engine's own verdict word for word (empty where it gave none).
        'uniformvalid', 'enginevalid',
    ];

    /** @var array<int, array> Ability distribution and range by run, for this request. */
    protected static array $abilities = [];

    /**
     * TI@n at the end of a sitting, from the engine; null where its pool is not there.
     *
     * @param array $observation The observation.
     * @return float|null
     */
    protected static function final_ti_at_n(array $observation): ?float {
        global $DB;

        $runid = (int) $observation['runid'];
        $n = (int) ($observation['nitems'] ?? 0);
        if ($observation['esttheta'] === null || $n < 1 || !engine_information::available()) {
            return null;
        }
        if (!array_key_exists($runid, self::$finalpools)) {
            $root = (int) $DB->get_field_sql(
                'SELECT catscaleid FROM {local_catquizlab_scalemap}
                  WHERE runid = ? AND parentcatscaleid = 0 ORDER BY generation DESC',
                [$runid],
                IGNORE_MULTIPLE
            );
            // The engine's own name of the model: the observation carries the plugin's key ('2pl').
            $model = model_catalog::has((string) ($observation['model'] ?? ''))
                ? model_catalog::engine_key((string) $observation['model']) : '';
            self::$finalpools[$runid] = $root > 0 && $model !== ''
                ? engine_information::pool($runid, $root, $model) : null;
        }

        $pool = self::$finalpools[$runid];

        return engine_information::step((float) $observation['esttheta'], $pool, $n, [], 0)['tiatn'];
    }

    /** @var array<int, mixed> Item pools by run, for TI@n at the end. */
    protected static array $finalpools = [];

    /**
     * Forget what this request has looked up — for tests, which reuse run ids.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$abilities = [];
        self::$finalpools = [];
    }

    /**
     * A run's ability distribution and scale range, as its definition sets them (#102).
     *
     * @param int $runid The run.
     * @return array{distribution: string, mean: float, sd: ?float, min: float, max: float}
     */
    public static function run_ability(int $runid): array {
        global $DB;

        if (!isset(self::$abilities[$runid])) {
            $record = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
            $d = ability_distribution::of($record ? run_registry::definition_for($record) : []);
            self::$abilities[$runid] = [
                'distribution' => (string) $d['distribution'],
                'mean' => (float) $d['mean'],
                'sd' => $d['distribution'] === ability_distribution::UNIFORM ? null : (float) $d['sd'],
                'min' => (float) $d['min'],
                'max' => (float) $d['max'],
            ];
        }

        return self::$abilities[$runid];
    }

    /**
     * One row per step, with SE and TI@n where they agree with the engine (#106).
     *
     * Row by row: a step level of fifty thousand sittings is a million rows.
     *
     * @param results_query $query The selection.
     * @return \Generator
     */
    protected static function step_rows(results_query $query): \Generator {
        foreach ($query->each_observation() as $observation) {
            $detail = $observation + results_query::detail($observation);
            $flow = test_flow::steps($detail);
            if (self::$engineinfo) {
                $flow = test_flow::with_engine_information(
                    $flow,
                    (array) ($detail['trace'] ?? []),
                    (int) $observation['runid'],
                    results_query::run_maxitems((int) $observation['runid'])
                );
            }
            foreach ($flow['steps'] as $step) {
                yield [
                    'attemptid'        => (int) $observation['attemptid'],
                    'runid'            => (int) $observation['runid'],
                    'strategy'         => (string) $observation['strategy'],
                    'step'             => (int) $step['step'],
                    'questionid'       => (int) $step['questionid'],
                    'subscaleid'       => (int) $step['scaleid'],
                    'score'            => $step['fraction'],
                    'esttheta'         => $step['ability'],
                    'se'               => $step['se'],
                    // The information of the items played, and TI@n — the n most
                    // informative items of the pool — as the engine computes it.
                    'ti_played'        => $step['ti'],
                    'ti_at_n'          => $step['tiatn'] ?? null,
                    'ti_remaining_min' => $step['tiremainingmin'] ?? null,
                    'ti_remaining_max' => $step['tiremaining'] ?? null,
                    'scales_estimated' => $step['scalesestimated'],
                ];
            }
        }
    }

    /**
     * Sitting-level export rows from observations.
     *
     * @param iterable $observations Rows or a stream of rows.
     * @return \Generator<array>
     */
    protected static function attempt_rows(iterable $observations): \Generator {
        foreach ($observations as $observation) {
            $row = [];
            foreach (self::ATTEMPT_COLUMNS as $column) {
                $value = $observation[$column] ?? null;
                // Booleans are written as 0 and 1: a statistics package reads
                // those, and "true"/"" would silently become a factor level.
                $row[$column] = is_bool($value) ? (int) $value : $value;
            }
            $row['finaltiatn'] = self::final_ti_at_n($observation);
            $row['valid'] = !empty($observation['valid']) ? 1 : 0;
            $row['validityreason'] = (string) ($observation['validityreason'] ?? '');
            $row['enginefinished'] = !empty($observation['enginefinished']) ? 1 : 0;
            $row['designstopreached'] = !empty($observation['designstopreached']) ? 1 : 0;
            $row['uniformvalid'] = !empty($observation['uniformvalid']) ? 1 : 0;
            $row['enginevalid'] = $observation['enginevalid'] === null ? '' : ((int) $observation['enginevalid']);
            $ability = self::run_ability((int) $observation['runid']);
            $row['abilitydistribution'] = $ability['distribution'];
            $row['abilitymean'] = $ability['mean'];
            $row['abilitysd'] = $ability['sd'];
            $row['abilitymin'] = $ability['min'];
            $row['abilitymax'] = $ability['max'];
            yield $row;
        }
    }

    /**
     * Build the dataset of one level under a filter.
     *
     * @param results_query $query The filtered data source.
     * @param string $level One of the LEVEL_* constants.
     * @return array{columns: string[], rows: array[]}
     * @throws \coding_exception If the level is unknown.
     */
    public static function dataset(results_query $query, string $level): array {
        switch ($level) {
            case self::LEVEL_RUN:
                return self::runs($query);
            case self::LEVEL_ATTEMPT:
                return self::attempts($query);
            case self::LEVEL_SUBSCALE:
                return self::subscales($query);
            case self::LEVEL_ITEM:
                return self::items($query);
            case self::LEVEL_STEP:
                return ['columns' => self::STEP_COLUMNS, 'rows' => iterator_to_array(self::step_rows($query), false)];
            default:
                throw new \coding_exception('Unknown export level: ' . $level);
        }
    }

    /**
     * One row per run, with its coordinates and aggregate outcomes.
     *
     * @param results_query $query The data source.
     * @return array{columns: string[], rows: array[]}
     */
    protected static function runs(results_query $query): array {
        $columns = [
            'runid', 'experimentid', 'experiment', 'cellkey', 'replication', 'tier',
            'strategy', 'model', 'variant', 'strength', 'stratum', 'severity',
            'attempts', 'rmse', 'bias', 'correlation', 'meanse', 'meanitems',
            'stopsuccess', 'meanruntimems',
        ];

        $byrun = [];
        foreach ($query->observations() as $observation) {
            $byrun[$observation['runid']][] = $observation;
        }

        $rows = [];
        foreach ($query->runs() as $runid => $run) {
            $members = $byrun[$runid] ?? [];
            $outcomes = $members === [] ? [] : robustness_analysis::outcomes($members);
            $rows[] = [
                'runid'         => $runid,
                'experimentid'  => $run['experimentid'],
                'experiment'    => $run['experiment'],
                'cellkey'       => $run['cellkey'],
                'replication'   => $run['replication'],
                'tier'          => $run['tier'] ?? '',
                'strategy'      => $run['strategy'],
                'model'         => $run['model'],
                'variant'       => $run['variant'],
                'strength'      => $run['strength'],
                'stratum'       => $run['stratum'],
                'severity'      => $run['severity'],
                'attempts'      => count($members),
                'rmse'          => $outcomes['rmse'] ?? null,
                'bias'          => $outcomes['bias'] ?? null,
                'correlation'   => $outcomes['correlation'] ?? null,
                'meanse'        => $outcomes['se'] ?? null,
                'meanitems'     => $outcomes['nitems'] ?? null,
                'stopsuccess'   => $outcomes['stopsuccess'] ?? null,
                'meanruntimems' => $outcomes['runtimems'] ?? null,
            ];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * One row per attempt.
     *
     * @param results_query $query The data source.
     * @return array{columns: string[], rows: array[]}
     */
    protected static function attempts(results_query $query): array {
        // One column list for both paths, streamed and built: a second copy of
        // it drifted the first time a column was added (#106).
        return [
            'columns' => self::ATTEMPT_COLUMNS,
            'rows'    => iterator_to_array(self::attempt_rows($query->each_observation()), false),
        ];
    }

    /**
     * One row per subscale of an attempt.
     *
     * @param results_query $query The data source.
     * @param bool $stream Rows as a stream rather than an array.
     * @return array{columns: string[], rows: array[]}
     */
    protected static function subscales(results_query $query, bool $stream = false): array {
        $columns = [
            'attemptid', 'runid', 'personid', 'twinid', 'strategy', 'variant',
            'stratum', 'severity', 'category', 'subscale',
            'truetheta', 'esttheta', 'truedelta', 'estdelta', 'error',
            'localse', 'items', 'within1se', 'within2se',
            // Validity per scale (#112): by the engine's definitions, why not,
            // and the engine's own verdict.
            'fraction', 'scalevalid', 'scalereasons', 'enginescalevalid',
            // The column 'items' is what was administered on the scale, from the
            // sitting's own steps (#113): empty where that is not known, 0 only where it
            // is certain. Where it is from, why it is not known, its diagnostic
            // class, and the engine's count of answered items beside it.
            'itemssource', 'itemsreason', 'itemclass', 'itemsanswered',
        ];

        $filter = $query->get_filter();
        $mode = (string) ($filter['validity'] ?? result_validity::VALID);
        $engine = ($filter['validityrule'] ?? engine_validity::RULE_UNIFORM) === engine_validity::RULE_ENGINE;
        $rows = (static function () use ($query, $columns, $mode, $engine): \Generator {
            $source = local_analysis::each_row($query->each_observation(), $query->scale_maps());
            foreach ($source as $observation) {
                // The scales of a valid sitting are not all valid: the export of
                // the analysis holds the ones the figures use, unless all are asked for.
                $valid = $engine ? $observation['enginescalevalid'] === true : (bool) $observation['scalevalid'];
                if ($mode !== result_validity::ALL && (($mode === result_validity::VALID) !== $valid)) {
                    continue;
                }
                $observation['scalereasons'] = implode(',', (array) $observation['scalereasons']);
                $row = [];
                foreach ($columns as $column) {
                    $value = $observation[$column] ?? null;
                    $row[$column] = is_bool($value) ? (int) $value : $value;
                }
                yield $row;
            }
        })();

        return ['columns' => $columns, 'rows' => $stream ? $rows : iterator_to_array($rows, false)];
    }

    /**
     * One row per materialised item: the ground truth beside the engine's view.
     *
     * @param results_query $query The data source.
     * @return array{columns: string[], rows: array[]}
     */
    protected static function items(results_query $query): array {
        return export_dataset::items(array_keys($query->runs()));
    }

    /**
     * The metadata that travels with an export.
     *
     * A results file outlives the screen it came from. Without the filter, the
     * aggregation level and the versions it was produced under, a reader cannot
     * tell what the numbers refer to — and a file whose provenance is unclear
     * is not usable as evidence.
     *
     * @param results_query $query The data source.
     * @param string $level The export level.
     * @param bool $afterrows Written after the rows: the provenance is taken from the pass that read them.
     * @param int|null $rows The rows already written and counted; null to count them here.
     * @return array The metadata block.
     */
    public static function metadata(results_query $query, string $level, bool $afterrows = false, ?int $rows = null): array {
        global $CFG, $USER;

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/catquizlab/version.php');

        // After the rows of a level that reads every sitting, from that pass.
        $provenance = $query->provenance($afterrows && in_array($level, [self::LEVEL_ATTEMPT, self::LEVEL_SUBSCALE], true));

        // The columns and the count, not the dataset: metadata used to build
        // the whole export to say how many rows it had, and the JSON download
        // then built it a second time to write them.
        // Counted while they were written, where they were: counting them here
        // read the whole level once more — every sitting and every scale of it.
        $stream = self::iterate($query, $level);
        $count = $rows;
        if ($count === null) {
            $count = 0;
            foreach ($stream['rows'] as $unused) {
                $count++;
            }
        }
        $dataset = ['columns' => $stream['columns']];

        return [
            'schema'        => 'local_catquizlab/results',
            'schemaversion' => 1,
            'level'         => $level,
            'leveldescription' => get_string(self::levels()[$level], 'local_catquizlab'),
            'filter'        => $query->get_filter(),
            'runs'          => $provenance['runs'],
            'attempts'      => $provenance['attempts'],
            'replications'  => $provenance['replications'],
            'rows'          => $count,
            'columns'       => $dataset['columns'],
            'dispersion'    => $provenance['dispersion'],
            // Per run: the distribution of the true abilities, its parameters,
            // the share expected outside the range, and the range written to
            // the engine's root scale (#102) — as the manifest records them.
            'ability'       => self::ability_by_run($query),
            'exported'      => date('c'),
            'exportedby'    => (int) ($USER->id ?? 0),
            'plugin'        => [
                'component' => 'local_catquizlab',
                'version'   => $plugin->version ?? null,
                'release'   => $plugin->release ?? null,
            ],
            'environment'   => [
                'moodlerelease' => $CFG->release ?? null,
                'phpversion'    => PHP_VERSION,
            ],
            // The engine version is part of what a result depends on, and it
            // is recorded per run in the manifest; the export points at that
            // rather than duplicating a lookup.
            'engine'        => ['available' => environment::engine_available()],
        ];
    }

    /**
     * The ability block of every run in the selection, as in its manifest (#102).
     *
     * @param results_query $query The selection.
     * @return array<int, array>
     */
    protected static function ability_by_run(results_query $query): array {
        global $DB;

        $out = [];
        foreach (array_keys($query->runs()) as $runid) {
            $record = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
            if ($record) {
                $out[(int) $runid] = test_provisioner::effective_parameters(run_registry::definition_for($record))['ability'];
            }
        }

        return $out;
    }

    /**
     * Render a dataset as CSV.
     *
     * @param array $dataset A dataset from {@see self::dataset()}.
     * @return string
     */
    public static function to_csv(array $dataset): string {
        $lines = [implode(',', $dataset['columns'])];

        foreach ($dataset['rows'] as $row) {
            $cells = [];
            foreach ($dataset['columns'] as $column) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    // An empty field, not the string "null": a missing value
                    // must not arrive as a category of its own.
                    $cells[] = '';
                    continue;
                }
                if (is_bool($value)) {
                    $cells[] = (int) $value;
                    continue;
                }
                if (is_float($value) || is_int($value)) {
                    $cells[] = (string) $value;
                    continue;
                }
                $cells[] = '"' . str_replace('"', '""', (string) $value) . '"';
            }
            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Render a dataset as JSON, with its metadata attached.
     *
     * @param results_query $query The data source.
     * @param string $level The export level.
     * @return string
     */
    public static function to_json(results_query $query, string $level): string {
        $dataset = self::dataset($query, $level);

        return json_encode(
            [
                'metadata' => self::metadata($query, $level),
                'data'     => $dataset['rows'],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * Write an export straight to the client, a row at a time.
     *
     * A download used to hold the dataset and the finished string at once: for
     * a large experiment that is the export twice over in memory, to send it
     * once. Rows go out as they are formatted and the buffer is flushed, so
     * what is held is one row.
     *
     * The dataset itself is still built in one piece — that is RESULTS-001,
     * and it is bounded for now by the size ceiling on the page.
     *
     * Writing only: the caller sends the headers, which keeps this testable
     * and keeps one job in one place.
     *
     * @param results_query $query The selection.
     * @param string $level One of the LEVEL_ constants.
     * @param string $format csv or json.
     * @return void
     */
    public static function stream(results_query $query, string $level, string $format): void {
        // A stream of rows, not a dataset: nothing here holds the export.
        $dataset = self::iterate($query, $level);

        $out = fopen('php://output', 'w');

        if ($format === 'csv') {
            fputcsv($out, $dataset['columns']);
            foreach ($dataset['rows'] as $row) {
                $line = [];
                foreach ($dataset['columns'] as $column) {
                    $line[] = $row[$column] ?? '';
                }
                fputcsv($out, $line);
            }
        } else {
            // Written by hand rather than json_encode() on the whole thing,
            // which would build the very string this exists to avoid.
            // The metadata last: it counts the sittings, and the rows have just
            // been read — written first, it read every sitting once more
            // beforehand. The order of keys means nothing in JSON.
            fwrite($out, '{"columns":' . json_encode($dataset['columns']));
            fwrite($out, ',"rows":[');
            $first = true;
            $written = 0;
            foreach ($dataset['rows'] as $row) {
                fwrite($out, ($first ? '' : ',') . json_encode($row, JSON_UNESCAPED_SLASHES));
                $first = false;
                $written++;
            }
            fwrite($out, '],"metadata":' . json_encode(self::metadata($query, $level, true, $written), JSON_UNESCAPED_SLASHES)
                . '}');
        }

        fclose($out);
    }

    /**
     * A file name that carries the level and the filter.
     *
     * @param results_query $query The data source.
     * @param string $level The export level.
     * @param string $extension The file extension without a dot.
     * @return string
     */
    public static function filename(results_query $query, string $level, string $extension): string {
        $parts = ['catquizlab', $level];
        foreach ($query->get_filter() as $key => $value) {
            $parts[] = $key . '-' . clean_param((string) $value, PARAM_ALPHANUMEXT);
        }
        $parts[] = date('Ymd-His');

        return implode('_', $parts) . '.' . $extension;
    }
}
