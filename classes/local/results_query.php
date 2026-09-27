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
 * The single data source behind every results view.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Resolves the results filter into observations, and aggregates them.
 *
 * Every tab, table and chart reads from here. That is a requirement rather than
 * tidiness: a figure in a chart and the same figure in the table below it must
 * come from one computation, or a reader has no way to tell which of two
 * disagreeing numbers to believe.
 *
 * The unit of observation is one attempt — one simulated person taking one
 * test. Aggregation happens afterwards and always says which level it is on,
 * because an aggregated point must not look like a single observation.
 */
class results_query {
    /** @var string[] The filters a results view understands. */
    public const FILTERS = [
        'experimentid', 'tier', 'model', 'strategy', 'variant', 'stratum', 'severity',
        'replication', 'cellkey', 'budget',
    ];

    /** @var string Dispersion reported as the standard deviation over replications. */
    public const DISPERSION_SD = 'sd';

    /** @var string Dispersion reported as a 95% confidence interval on the mean. */
    public const DISPERSION_CI95 = 'ci95';

    /** @var string Dispersion reported as median and interquartile range. */
    public const DISPERSION_IQR = 'iqr';

    /** @var array The active filter. */
    protected array $filter;

    /** @var int The attempt whose detail is cached. */
    protected static int $detailfor = 0;

    /** @var array The cached detail of that attempt. */
    protected static array $detail = [];

    /** @var array|null Cached observations. */
    protected ?array $observations = null;

    /**
     * Construct for a filter.
     *
     * @param array $filter Field => value, restricted to {@see self::FILTERS}.
     */
    public function __construct(array $filter = []) {
        $this->filter = array_intersect_key($filter, array_flip(self::FILTERS));
    }

    /**
     * The active filter, as applied.
     *
     * @return array
     */
    public function get_filter(): array {
        return $this->filter;
    }

    /**
     * The runs the filter selects, keyed by run id.
     *
     * @return array[] Run coordinate rows from {@see run_registry::describe()}.
     */
    public function runs(): array {
        global $DB;

        $conditions = [];
        if (!empty($this->filter['experimentid'])) {
            $conditions['experimentid'] = (int) $this->filter['experimentid'];
        }
        if (!empty($this->filter['replication'])) {
            $conditions['replication'] = (int) $this->filter['replication'];
        }

        $runs = [];
        foreach ($DB->get_records('local_catquizlab_run', $conditions, 'id ASC') as $record) {
            $row = run_registry::describe($record);
            $row['tier'] = $this->tier_of($record);
            if ($this->matches($row)) {
                $runs[$row['id']] = $row;
            }
        }

        return $runs;
    }

    /** @var int Observations beyond which an unfiltered selection is refused. */
    public const TOO_MANY = 50000;

    /**
     * Whether this selection is larger than a page can honestly analyse.
     *
     * "All experiments" on an installation with a year of work behind it is
     * not a question anybody means to ask, and answering it badly — a blank
     * page after the tabs have already rendered, which is what a memory
     * failure looks like mid-output — is worse than declining.
     *
     * @return array{toolarge: bool, count: int, limit: int}
     */
    public function size_check(): array {
        global $DB;

        $runs = $this->runs();
        if ($runs === []) {
            return ['toolarge' => false, 'count' => 0, 'limit' => self::TOO_MANY];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'run');
        $params['collected'] = attempt_scheduler::STATUS_COLLECTED;
        $params['validated'] = attempt_scheduler::STATUS_VALIDATED;

        $count = (int) $DB->count_records_select(
            'local_catquizlab_attempt',
            'runid ' . $insql . ' AND status IN (:collected, :validated) AND tracejson IS NOT NULL',
            $params
        );

        return [
            'toolarge' => $count > self::TOO_MANY,
            'count'    => $count,
            'limit'    => self::TOO_MANY,
        ];
    }

    /**
     * The observations of the selection, as one array.
     *
     * For the analyses that need every observation at once. A page that only
     * needs sums, means or quantiles walks {@see each_observation()} instead
     * and never holds the rows.
     *
     * @return array[]
     */
    public function observations(): array {
        if ($this->observations !== null) {
            return $this->observations;
        }

        return $this->observations = iterator_to_array($this->each_observation(), false);
    }

    /**
     * What the overview needs, gathered in one pass without holding sittings.
     *
     * Each sitting contributes a handful of numbers and its group labels; its
     * item list is counted into the exposure tally and then dropped. The
     * medians and quartiles the overview reports need the values, so the
     * values are kept — as numbers, not as observations.
     *
     * @param int $maxpoints The most points the scatter sample keeps.
     * @return array Summaries (all, strategy, cell), itemcounts, points and n.
     */
    public function overview(int $maxpoints = 2000): array {
        $all = new stream_summary();
        $bystrategy = [];
        $bycell = [];
        $itemcounts = [];
        $points = [];
        $seen = 0;

        foreach ($this->each_observation() as $observation) {
            foreach ((array) ($observation['items'] ?? []) as $item) {
                $key = (string) $item;
                $itemcounts[$key] = ($itemcounts[$key] ?? 0) + 1;
            }

            $all->add($observation);

            $strategy = (string) $observation['strategy'];
            $bystrategy[$strategy] = $bystrategy[$strategy] ?? new stream_summary();
            $bystrategy[$strategy]->add($observation);

            $cell = implode('|', [
                $observation['tier'], $observation['strategy'], $observation['model'],
                $observation['variant'], $observation['stratum'], $observation['severity'],
            ]);
            $bycell[$cell] = $bycell[$cell] ?? new stream_summary();
            $bycell[$cell]->add($observation);

            // A reservoir sample for the scatter chart: an even chance for
            // every sitting, never more than $maxpoints kept.
            if ($observation['se'] !== null) {
                $seen++;
                $point = ['x' => $observation['nitems'], 'y' => $observation['se']];
                if (count($points) < $maxpoints) {
                    $points[] = $point;
                } else {
                    $slot = random_int(0, $seen - 1);
                    if ($slot < $maxpoints) {
                        $points[$slot] = $point;
                    }
                }
            }
        }

        return [
            'all'        => $all,
            'strategy'   => $bystrategy,
            'cell'       => $bycell,
            'itemcounts' => $itemcounts,
            'points'     => $points,
            'n'          => $all->count(),
        ];
    }

    /**
     * The observations of the selection, one at a time.
     *
     * Read from a recordset and yielded as they are built, so that what is in
     * memory is one sitting rather than all of them. Fifty thousand sittings
     * of a large experiment are an analysis, not an array.
     *
     * @return \Generator<array>
     */
    public function each_observation(): \Generator {
        global $DB;

        $runs = $this->runs();
        if ($runs === []) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'run');

        // People are fetched as they are needed and kept in a small cache.
        // Every person of every run used to be held at once, profile JSON
        // included: nine thousand people at roughly three kilobytes each is
        // twenty-five megabytes before a single number is computed, and with
        // the sittings beside them the page ran out of memory. Only people who
        // actually sat the test are read now.
        $persons = [];
        // Two fields, not the profile. The profile is three kilobytes of JSON
        // per person and the row never reads it — detail() fetches it for the
        // one observation that needs it. Caching it for every person of a run
        // is what made the stream grow to 48 MB while keeping no rows.
        $personfields = 'id, twinid, abilityglobal';

        // A recordset, and only sittings that have something to report. Every
        // sitting of every run used to be loaded at once — nine thousand rows
        // carrying a full trace each — and the page died of exhausted memory
        // before it computed anything. A recordset holds one row at a time;
        // what stays is one small array per observation.
        // Run by run, and within a run in batches of a few hundred by id. A
        // single recordset over everything is not a stream: the database
        // drivers buffer it — Moodle's PostgreSQL driver fetches up to a
        // hundred thousand rows at a time by default — so walking nine
        // thousand sittings peaked at 48 MB while keeping none of them. The
        // person cache is per run for the same reason: across nine runs of a
        // thousand people it was nine thousand profiles.
        foreach (array_keys($runs) as $runid) {
            $persons = [];
            $lastid = 0;

            do {
                $batch = $DB->get_records_select(
                    'local_catquizlab_attempt',
                    'runid = :runid AND id > :lastid AND status IN (:collected, :validated) AND tracejson IS NOT NULL',
                    [
                        'runid'     => $runid,
                        'lastid'    => $lastid,
                        'collected' => attempt_scheduler::STATUS_COLLECTED,
                        'validated' => attempt_scheduler::STATUS_VALIDATED,
                    ],
                    'id ASC',
                    'id, runid, personid, runtimems, tracejson',
                    0,
                    self::BATCH
                );

                foreach ($batch as $attempt) {
                    $lastid = (int) $attempt->id;
                    yield from $this->observation_row($attempt, $runs, $persons, $personfields);
                }
            } while (count($batch) === self::BATCH);
        }
    }

    /** @var int Sittings read per query while streaming. */
    public const BATCH = 500;

    /**
     * One observation from one sitting.
     *
     * @param \stdClass $attempt The sitting row.
     * @param array $runs The runs of the selection.
     * @param array $persons The person cache of the current run.
     * @param string $personfields The person fields to read.
     * @return \Generator<array>
     */
    protected function observation_row(\stdClass $attempt, array $runs, array &$persons, string $personfields): \Generator {
        global $DB;

        $trace = json_decode((string) $attempt->tracejson, true) ?: [];

        $personid = (int) $attempt->personid;
        if (!array_key_exists($personid, $persons)) {
            $persons[$personid] = $DB->get_record(
                'local_catquizlab_person',
                ['id' => $personid],
                $personfields
            ) ?: null;
        }
        $person = $persons[$personid];
        if ($person === null || $trace === []) {
            // An attempt without a trace has no outcome to report. Counting
            // it as a zero would quietly bias every mean it entered.
            return;
        }
        $run = $runs[(int) $attempt->runid];

        $truetheta = (float) $person->abilityglobal;
        $esttheta = (float) ($trace['finaltheta'] ?? 0.0);
        $se = isset($trace['finalse']) ? (float) $trace['finalse'] : null;

        yield [
            'nscales'     => count((array) ($trace['scaleabilities'] ?? [])),
            'attemptid'   => (int) $attempt->id,
            'runid'       => (int) $attempt->runid,
            'personid'    => (int) $attempt->personid,
            'twinid'      => (string) ($person->twinid ?? ''),
            'experimentid' => $run['experimentid'],
            'experiment'  => $run['experiment'],
            'cellkey'     => $run['cellkey'],
            'replication' => $run['replication'],
            'tier'        => $run['tier'],
            'strategy'    => $run['strategy'],
            'model'       => $run['model'],
            'variant'     => $run['variant'],
            'strength'    => $run['strength'] ?? null,
            // The budget as one comparable token. A study that varies the
            // budget needs to filter on the condition, not on two numbers
            // that only mean something together.
            'budget'      => $run['budget'] ?? '',
            'stratum'     => $run['stratum'],
            'severity'    => $run['severity'],
            'truetheta'   => $truetheta,
            'esttheta'    => $esttheta,
            'error'       => $esttheta - $truetheta,
            'nitems'      => (int) ($trace['nitems'] ?? 0),
            'se'          => $se,
            'stopreason'  => (string) ($trace['stopreason'] ?? ''),
            // The stop rule succeeded when the engine stopped on a
            // criterion of its own rather than running out of items.
            'stopreached' => self::stop_reached((string) ($trace['stopreason'] ?? '')),
            'runtimems'   => (int) ($attempt->runtimems ?? 0),
            'items'       => (array) ($trace['items'] ?? []),
        ];
    }

    /**
     * Whether a stop reason counts as the stop rule having succeeded.
     *
     * @param string $reason The engine's stop criterion.
     * @return bool
     */
    public static function stop_reached(string $reason): bool {
        if ($reason === '') {
            return false;
        }
        // Running out of items or of the budget is the rule failing to bite,
        // not succeeding.
        // Matching on substrings has to be careful: "standarderror" is the
        // precision criterion doing its job and contains the word "error".
        $exhausted = [
            'maxquestions', 'maxitems', 'nomoreitems', 'noremainingquestions',
            'nomorequestions', 'abort', 'cancelled', 'timeout',
            // The host activity reports "An error occured" whenever the engine
            // returns no question — including when every subscale has simply
            // reached its own maximum. That is not the stop rule succeeding,
            // and counting it as such would inflate the success rate with runs
            // that ran out of room.
            'an error occured', 'error occurred',
        ];
        foreach ($exhausted as $needle) {
            if (stripos($reason, $needle) !== false) {
                return false;
            }
        }
        if (strcasecmp($reason, 'error') === 0) {
            return false;
        }

        return true;
    }

    /**
     * Summarise one numeric field over a set of observations.
     *
     * @param array $rows Observations.
     * @param string $field The numeric field.
     * @return array{n: int, mean: float|null, sd: float|null, se: float|null,
     *               ci95lo: float|null, ci95hi: float|null, median: float|null,
     *               q1: float|null, q3: float|null, min: float|null, max: float|null}
     */
    public static function summarise(array $rows, string $field): array {
        $values = [];
        foreach ($rows as $row) {
            if (isset($row[$field]) && is_numeric($row[$field])) {
                $values[] = (float) $row[$field];
            }
        }

        return self::describe_values($values);
    }

    /**
     * Descriptive statistics of a list of values.
     *
     * @param array $values Numeric values.
     * @return array The statistics; every field is null when there is nothing to describe.
     */
    public static function describe_values(array $values): array {
        $n = count($values);
        $empty = [
            'n' => 0, 'mean' => null, 'sd' => null, 'se' => null,
            'ci95lo' => null, 'ci95hi' => null, 'median' => null,
            'q1' => null, 'q3' => null, 'min' => null, 'max' => null,
        ];
        if ($n === 0) {
            return $empty;
        }

        sort($values);
        $mean = array_sum($values) / $n;

        $sd = null;
        $stderr = null;
        $ci95lo = null;
        $ci95hi = null;
        if ($n > 1) {
            $variance = 0.0;
            foreach ($values as $value) {
                $variance += ($value - $mean) ** 2;
            }
            $sd = sqrt($variance / ($n - 1));
            $stderr = $sd / sqrt($n);
            // A normal approximation. With one observation there is no
            // dispersion to report, and inventing one would suggest a precision
            // the data does not have.
            $ci95lo = $mean - 1.96 * $stderr;
            $ci95hi = $mean + 1.96 * $stderr;
        }

        return [
            'n'      => $n,
            'mean'   => round($mean, 6),
            'sd'     => $sd === null ? null : round($sd, 6),
            'se'     => $stderr === null ? null : round($stderr, 6),
            'ci95lo' => $ci95lo === null ? null : round($ci95lo, 6),
            'ci95hi' => $ci95hi === null ? null : round($ci95hi, 6),
            'median' => round(self::quantile($values, 0.5), 6),
            'q1'     => round(self::quantile($values, 0.25), 6),
            'q3'     => round(self::quantile($values, 0.75), 6),
            'min'    => round($values[0], 6),
            'max'    => round($values[$n - 1], 6),
        ];
    }

    /**
     * Group observations by a coordinate and summarise a field in each group.
     *
     * @param array $rows Observations.
     * @param string $groupby A coordinate field, e.g. strategy.
     * @param string $field The numeric field to summarise.
     * @return array[] One row per group: group, label, plus the statistics.
     */
    public static function group(array $rows, string $groupby, string $field): array {
        $groups = [];
        foreach ($rows as $row) {
            $key = (string) ($row[$groupby] ?? '');
            $groups[$key][] = $row;
        }

        $out = [];
        foreach ($groups as $key => $members) {
            $out[] = array_merge(
                [
                    'group' => $key,
                    'label' => run_registry::group_label($groupby, $key),
                ],
                self::summarise($members, $field)
            );
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * Exposure and its concentration across the filtered attempts.
     *
     * @return array The exposure statistics, including the concentration block.
     */
    public function exposure(): array {
        $rows = $this->observations();
        $attempts = [];
        foreach ($rows as $row) {
            $attempts[] = ['items' => $row['items']];
        }

        return metrics::exposure($attempts, $this->pool_size());
    }

    /**
     * The decoded trace and person profile of one observation.
     *
     * Kept out of the observation itself: both are large once PHP has them as
     * arrays — about 54 kB a row together for a hundred-subscale experiment —
     * and most of the page computes means that need neither. Read here for the
     * row being looked at, and cached for that row only, so the tabs that walk
     * every observation hold one decoded pair at a time instead of all of them.
     *
     * @param array $observation One row of {@see observations()}.
     * @return array{profile: array, trace: array}
     */
    public static function detail(array $observation): array {
        global $DB;

        $attemptid = (int) ($observation['attemptid'] ?? 0);
        if ($attemptid > 0 && $attemptid === self::$detailfor) {
            return self::$detail;
        }

        $trace = [];
        $profile = [];

        if ($attemptid > 0) {
            $json = $DB->get_field('local_catquizlab_attempt', 'tracejson', ['id' => $attemptid]);
            $trace = json_decode((string) $json, true) ?: [];
        }
        $personid = (int) ($observation['personid'] ?? 0);
        if ($personid > 0) {
            $json = $DB->get_field('local_catquizlab_person', 'profilejson', ['id' => $personid]);
            $profile = json_decode((string) $json, true) ?: [];
        }

        self::$detailfor = $attemptid;

        return self::$detail = ['profile' => $profile, 'trace' => $trace];
    }

    /**
     * The engine-scale to subscale-key map of every selected run.
     *
     * @return array<int, array<int, string>> Run id => engine scale id => "category:subscale".
     */
    public function scale_maps(): array {
        global $DB;

        $maps = [];
        foreach (array_keys($this->runs()) as $runid) {
            $rows = $DB->get_records(
                'local_catquizlab_scalemap',
                ['runid' => $runid, 'level' => scale_provisioner::LEVEL_SUBSCALE],
                '',
                'id, catscaleid, categoryindex, subscaleindex'
            );
            $index = [];
            foreach ($rows as $row) {
                if ($row->categoryindex !== null && $row->subscaleindex !== null) {
                    $index[(int) $row->catscaleid] = (int) $row->categoryindex . ':' . (int) $row->subscaleindex;
                }
            }
            $maps[$runid] = $index;
        }

        return $maps;
    }

    /**
     * The per-subscale observations behind the local diagnostics.
     *
     * @return array[] Rows from {@see local_analysis::rows()}.
     */
    public function subscale_observations(): array {
        return local_analysis::rows($this->each_observation(), $this->scale_maps());
    }

    /**
     * The number of items materialised for the filtered runs.
     *
     * @return int|null The pool size, or null when it cannot be determined.
     */
    public function pool_size(): ?int {
        global $DB;

        $runs = $this->runs();
        if ($runs === []) {
            return null;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'run');
        $count = $DB->count_records_select('local_catquizlab_item', 'runid ' . $insql, $params);

        return $count > 0 ? $count : null;
    }

    /**
     * The distinct values a coordinate takes among the selected runs.
     *
     * The menus offer only what the data actually contains, so a filter cannot
     * be set to a combination that yields nothing.
     *
     * @param string $field A coordinate field.
     * @return array<string, string> Value => label.
     */
    public function available(string $field): array {
        $values = [];
        foreach ($this->runs() as $run) {
            $value = (string) ($run[$field] ?? '');
            if ($value !== '') {
                $values[$value] = run_registry::group_label($field, $value);
            }
        }
        ksort($values);

        return $values;
    }

    /**
     * A description of what the current view rests on.
     *
     * Shown with every table and chart: without the aggregation level and the
     * observation count, a number on screen cannot be interpreted.
     *
     * @return array{runs: int, attempts: int, replications: int, dispersion: string, computed: int}
     */
    public function provenance(): array {
        // Streamed: provenance is two counts and a set of replications, and
        // it used to materialise every observation to get them — the reason
        // the export tab and the JSON download still peaked above 150 MB on
        // fifty thousand sittings after everything else streamed.
        $rows = $this->each_observation();
        $attempts = 0;
        $replications = [];
        foreach ($rows as $row) {
            $attempts++;
            $replications[$row['replication']] = true;
        }

        return [
            'runs'         => count($this->runs()),
            'attempts'     => $attempts,
            'replications' => count($replications),
            'dispersion'   => self::DISPERSION_CI95,
            'computed'     => time(),
        ];
    }

    /**
     * The tier of a run, taken from the definition it was expanded from.
     *
     * @param \stdClass $record The run record.
     * @return string
     */
    protected function tier_of(\stdClass $record): string {
        global $DB;

        $manifest = json_decode((string) $record->manifestjson, true) ?: [];
        $tier = $manifest['config']['experiment']['tier'] ?? null;
        if (is_string($tier) && $tier !== '') {
            return $tier;
        }

        return (string) $DB->get_field(
            'local_catquizlab_experiment',
            'tier',
            ['id' => $record->experimentid]
        );
    }

    /**
     * Whether a run row satisfies the coordinate filters.
     *
     * @param array $row A described run.
     * @return bool
     */
    protected function matches(array $row): bool {
        foreach (['tier', 'model', 'strategy', 'variant', 'stratum', 'severity', 'cellkey', 'budget'] as $key) {
            if (!empty($this->filter[$key]) && (string) ($row[$key] ?? '') !== (string) $this->filter[$key]) {
                return false;
            }
        }

        return true;
    }

    /**
     * A quantile of a sorted list.
     *
     * @param array $sorted Ascending values.
     * @param float $q The quantile in [0, 1].
     * @return float
     */
    protected static function quantile(array $sorted, float $q): float {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $position = $q * ($n - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return (float) $sorted[$lower];
        }

        return $sorted[$lower] + ($position - $lower) * ($sorted[$upper] - $sorted[$lower]);
    }
}
