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
        // One run, and which sittings by validity (#118). Both were dropped here
        // without a word: a query for one run read every run, and "all" read
        // only the valid ones.
        'runid', 'validity',
    ];

    /** @var string Dispersion reported as the standard deviation over replications. */
    public const DISPERSION_SD = 'sd';

    /** @var string Dispersion reported as a 95% confidence interval on the mean. */
    public const DISPERSION_CI95 = 'ci95';

    /** @var string Dispersion reported as median and interquartile range. */
    public const DISPERSION_IQR = 'iqr';

    /** @var array The active filter. */
    protected array $filter;

    /** @var string The sitting and person whose detail is cached. */
    protected static string $detailfor = '';

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
        // One run: asked for by the aggregator and by the export's context, and
        // ignored until 0.7.28 — a query for one run read every run (#118).
        if (!empty($this->filter['runid'])) {
            $conditions['id'] = (int) $this->filter['runid'];
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

    /**
     * The stop rules in force, per strategy, for the runs of the selection.
     *
     * Read from each run's own definition through the same function that
     * provisioned it, so the results report what the engine was given — and
     * only what applies: a classical test reports no precision target, a
     * strategy without subscales no limit per subscale (#101).
     *
     * @return array<string, array{label: string, rules: string[], runs: int}> Keyed by strategy and rule set.
     */
    public function stop_rules(): array {
        global $DB;

        $groups = [];
        $ids = array_keys($this->runs());
        if ($ids === []) {
            return $groups;
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'run');
        $records = $DB->get_records_select('local_catquizlab_run', 'id ' . $insql, $params, 'id ASC');
        foreach ($records as $record) {
            $definition = run_registry::definition_for($record);
            if (!isset($definition['strategy'])) {
                continue;
            }
            $effective = test_provisioner::effective_parameters($definition);
            $rules = strategy_parameters::stop_rules($effective);
            $key = $definition['strategy'] . '|' . implode('|', $rules);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'   => (string) $definition['strategy'],
                    'label' => strategy_catalog::display_label((string) $definition['strategy']),
                    'rules' => $rules,
                    'runs'  => 0,
                ];
            }
            $groups[$key]['runs']++;
        }

        return $groups;
    }

    /**
     * The ability distributions of the selection's runs, in words (#105).
     *
     * @return array<string, int> Description => number of runs.
     */
    public function ability_distributions(): array {
        global $DB;

        $out = [];
        $ids = array_keys($this->runs());
        if ($ids === []) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'run');
        foreach ($DB->get_records_select('local_catquizlab_run', 'id ' . $insql, $params) as $record) {
            $text = ability_distribution::describe(ability_distribution::of(run_registry::definition_for($record)));
            $out[$text] = ($out[$text] ?? 0) + 1;
        }

        return $out;
    }

    /** @var array<int, int> Effective maximum number of questions by run. */
    protected static array $maxitems = [];

    /**
     * Forget the per-run lookups of this request — for tests, which reuse run ids.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$maxitems = [];
        self::$detail = [];
    }

    /**
     * A run's effective questions per subscale, as provisioned; -1 for none (#109).
     *
     * @param int $runid The run.
     * @return int
     */
    public static function run_subscale_maxitems(int $runid): int {
        global $DB;

        $record = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        $definition = $record ? run_registry::definition_for($record) : [];

        return $definition === [] ? -1
            : (int) test_provisioner::options_from_definition($definition)['maxquestionspersubscale'];
    }

    /**
     * A run's effective maximum number of questions, as provisioned; -1 for none.
     *
     * @param int $runid The run.
     * @return int
     */
    public static function run_maxitems(int $runid): int {
        global $DB;

        if (!isset(self::$maxitems[$runid])) {
            $record = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
            $definition = $record ? run_registry::definition_for($record) : [];
            self::$maxitems[$runid] = $definition === []
                ? -1
                : (int) test_provisioner::options_from_definition($definition)['maxquestions'];
        }

        return self::$maxitems[$runid];
    }

    /**
     * A scale and every scale below it, in a run's provisioned tree (#109).
     *
     * @param int $runid The run.
     * @param int $scaleid The catscale.
     * @return int[]
     */
    public static function scale_subtree(int $runid, int $scaleid): array {
        global $DB;

        $children = [];
        $rows = $DB->get_records('local_catquizlab_scalemap', ['runid' => $runid], 'id ASC', 'id, catscaleid, parentcatscaleid');
        foreach ($rows as $row) {
            $children[(int) $row->parentcatscaleid][] = (int) $row->catscaleid;
        }
        $subtree = [$scaleid];
        for ($i = 0; $i < count($subtree); $i++) {
            foreach ($children[$subtree[$i]] ?? [] as $child) {
                if (!in_array($child, $subtree, true)) {
                    $subtree[] = $child;
                }
            }
        }

        return $subtree;
    }

    /** @var array The counts of a pass before it has read anything. */
    protected const EMPTY_COUNTS = [
        'total' => 0, 'valid' => 0, 'invalid' => 0, 'designstops' => 0, 'reasons' => [], 'bystrategy' => [], 'bycell' => [],
    ];

    /** @var array<int, ?int> The engine's status number by engine attempt, for the batch being read. */
    protected array $enginestatus = [];

    /** @var array<int, array> What each run's design says about its end: min, max, SE target, strategy, pool. */
    protected array $runfacts = [];

    /** @var array Every collected sitting the last whole pass read, by validity and end — before any filter. */
    protected array $validitycounts = self::EMPTY_COUNTS;

    /**
     * The facts of a run's design that decide whether an end is a regular one (#118).
     *
     * A run without a definition — from before definitions were kept — has no
     * minimum to hold it to: nothing is assumed for it.
     *
     * @param int $runid The run.
     * @return array
     */
    protected function run_facts(int $runid): array {
        global $DB;

        if (!isset($this->runfacts[$runid])) {
            $record = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
            $definition = $record ? run_registry::definition_for($record) : [];
            if ($definition === []) {
                $this->runfacts[$runid] = [];
            } else {
                $options = test_provisioner::options_from_definition($definition);
                $strategy = (string) ($definition['strategy'] ?? '');
                $this->runfacts[$runid] = [
                    'strategy' => $strategy,
                    'minitems' => strategy_catalog::uses($strategy, 'globalmin') ? (int) $options['minquestions'] : 0,
                    'maxitems' => (int) $options['maxquestions'],
                    'semin' => strategy_catalog::uses_standard_error($strategy) ? (float) $options['se_min'] : null,
                    'poolsize' => $DB->count_records('local_catquizlab_item', ['runid' => $runid]),
                ];
            }
        }

        return $this->runfacts[$runid];
    }

    /**
     * The tier of a run row as the observation carries it.
     *
     * @param array $run A run row from runs().
     * @return string
     */
    protected function tier_of_run(array $run): string {
        return (string) ($run['tier'] ?? '');
    }

    /**
     * The success of the stop rules: sittings that ended on a criterion, of all collected (#118).
     *
     * Of all, not of the valid ones: those are the ones that may enter the
     * figures, and an end before the minimum is exactly a stop rule not met.
     *
     * @param string $level '' for the whole selection, 'bystrategy' or 'bycell'.
     * @param string $key The strategy or the cell key.
     * @return float|null Percent, null where nothing was collected.
     */
    public function stop_success(string $level = '', string $key = ''): ?float {
        $counts = $this->validity_counts();
        $node = $level === '' ? $counts : ($counts[$level][$key] ?? ['total' => 0, 'designstops' => 0]);

        return (int) $node['total'] === 0 ? null : 100.0 * (int) $node['designstops'] / (int) $node['total'];
    }

    /**
     * How many sittings the last whole pass read: valid, invalid, by end — before any filter (#118).
     *
     * @return array{total: int, valid: int, invalid: int, designstops: int, reasons: array<string, int>}
     */
    public function validity_counts(): array {
        if ($this->validitycounts['total'] === 0) {
            foreach ($this->each_observation() as $unused) {
                // A pass for its counts.
                continue;
            }
        }

        return $this->validitycounts;
    }

    /** @var int[]|null Restrict the observations to these sittings, or null for all. */
    protected ?array $onlyattempts = null;

    /**
     * The observations of some sittings only, fetched as themselves.
     *
     * @param int[] $attemptids The sittings.
     * @return array[]
     */
    public function observations_of(array $attemptids): array {
        $this->onlyattempts = array_values(array_unique(array_map('intval', $attemptids)));
        try {
            return iterator_to_array($this->each_observation(), false);
        } finally {
            $this->onlyattempts = null;
        }
    }

    /** @var array<int, string> End reason codes of the batch being read, by sitting. */
    protected array $endreasons = [];

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
        // A second reservoir for the ability plots of the global tab (#99):
        // true and estimated ability, error and run, never more than
        // $maxpoints kept — and, in the same pass, how many true abilities lie
        // outside the scale range of their run (#105).
        $sample = [];
        $sampled = 0;
        $outside = 0;
        $ranges = [];

        foreach ($this->each_observation() as $observation) {
            if ($observation['esttheta'] !== null && $observation['truetheta'] !== null) {
                $sampled++;
                $row = [
                    'truetheta' => (float) $observation['truetheta'],
                    'esttheta'  => (float) $observation['esttheta'],
                    'error'     => (float) $observation['error'],
                    'runid'     => (int) $observation['runid'],
                ];
                if (count($sample) < $maxpoints) {
                    $sample[] = $row;
                } else {
                    $slot = random_int(0, $sampled - 1);
                    if ($slot < $maxpoints) {
                        $sample[$slot] = $row;
                    }
                }
                $runid = (int) $observation['runid'];
                if (!isset($ranges[$runid])) {
                    $record = $GLOBALS['DB']->get_record('local_catquizlab_run', ['id' => $runid]);
                    $ranges[$runid] = ability_distribution::of($record ? run_registry::definition_for($record) : []);
                }
                if (!ability_distribution::inside($ranges[$runid], (float) $observation['truetheta'])) {
                    $outside++;
                }
            }

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
            'sample'     => $sample,
            'sampled'    => $sampled,
            'outside'    => $outside,
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

        self::$detailfor = '';
        self::$detail = [];
        // The counts are those of this pass — of a whole one, not of the few
        // sittings a comparison fetches as themselves.
        if ($this->onlyattempts === null) {
            $this->validitycounts = self::EMPTY_COUNTS;
        }

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
        $personfields = 'id, twinid, abilityglobal, stratum';

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
                // Restricted to given sittings where asked (#99): a twin family
                // of a few tests is fetched as itself, not found by reading all.
                $where = 'runid = :runid AND id > :lastid AND status IN (:collected, :validated) AND tracejson IS NOT NULL';
                $batchparams = [
                    'runid'     => $runid,
                    'lastid'    => $lastid,
                    'collected' => attempt_scheduler::STATUS_COLLECTED,
                    'validated' => attempt_scheduler::STATUS_VALIDATED,
                ];
                if ($this->onlyattempts !== null) {
                    [$onlyin, $onlyparams] = $DB->get_in_or_equal($this->onlyattempts ?: [0], SQL_PARAMS_NAMED, 'only');
                    $where .= ' AND id ' . $onlyin;
                    $batchparams += $onlyparams;
                }
                $batch = $DB->get_records_select(
                    'local_catquizlab_attempt',
                    $where,
                    $batchparams,
                    'id ASC',
                    'id, runid, personid, runtimems, tracejson, engineattemptid',
                    0,
                    self::BATCH
                );

                // The end reason of every sitting in the batch, in one query
                // (#106): the latest code each one's history carries.
                $this->endreasons = [];
                // The engine's own end code of every sitting in the batch (#118):
                // a number, whatever language the stop text was written in.
                $this->enginestatus = [];
                $engineids = array_filter(array_map(static fn($a): int => (int) ($a->engineattemptid ?? 0), $batch));
                if ($engineids !== [] && $DB->get_manager()->table_exists('local_catquiz_attempts')) {
                    [$inengine, $engineparams] = $DB->get_in_or_equal(array_values($engineids), SQL_PARAMS_NAMED, 'eng');
                    $statuses = $DB->get_records_select_menu(
                        'local_catquiz_attempts',
                        "component = 'adaptivequiz' AND attemptid " . $inengine,
                        $engineparams,
                        '',
                        'attemptid, status'
                    );
                    foreach ($statuses as $engineattemptid => $status) {
                        $this->enginestatus[(int) $engineattemptid] = $status === null ? null : (int) $status;
                    }
                }
                if ($batch !== [] && $DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
                    [$inreason, $reasonparams] = $DB->get_in_or_equal(array_keys($batch), SQL_PARAMS_NAMED, 'att');
                    $codes = $DB->get_records_select(
                        'local_catquizlab_attemptlog',
                        'attemptid ' . $inreason . ' AND reasoncode IS NOT NULL',
                        $reasonparams,
                        'id ASC',
                        'id, attemptid, reasoncode'
                    );
                    foreach ($codes as $code) {
                        $this->endreasons[(int) $code->attemptid] = (string) $code->reasoncode;
                    }
                }
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

        // How it ended and whether its result counts, decided here for every
        // view alike (#118) — from the engine's code and the run's design, not
        // from the code stored at collection, which knew neither the minimum
        // number of questions nor the engine's code.
        $run = $runs[(int) $attempt->runid];
        $facts = $this->run_facts((int) $attempt->runid) + [
            'played' => (int) ($trace['nitems'] ?? count((array) ($trace['items'] ?? []))),
            'finalse' => $se,
            'dropped' => count((array) ($trace['progress']['droppedscales'] ?? [])),
            'enginestatus' => $trace['enginestatus']
                ?? ($this->enginestatus[(int) ($attempt->engineattemptid ?? 0)] ?? null),
        ];
        $endcode = reason_catalog::outcome((string) ($trace['stopreason'] ?? ''), $facts);
        $validity = result_validity::evaluate($endcode);
        if ($this->onlyattempts === null) {
            $this->validitycounts['total']++;
            $this->validitycounts[$validity['valid'] ? 'valid' : 'invalid']++;
            $this->validitycounts['designstops'] += $validity['criterionstop'] ? 1 : 0;
            $this->validitycounts['reasons'][$endcode] = ($this->validitycounts['reasons'][$endcode] ?? 0) + 1;
            // Per strategy and per cell as well: the success of the stop rules is
            // a share of all sittings, invalid ones included, at every level.
            // The cell as the overview keys it: tier, strategy, model, variant, stratum, severity.
            $cell = implode('|', [$this->tier_of_run($run), $run['strategy'], $run['model'], $run['variant'],
                $run['stratum'], $run['severity']]);
            foreach (['bystrategy' => (string) $run['strategy'], 'bycell' => $cell] as $level => $key) {
                $this->validitycounts[$level][$key]['total'] = ($this->validitycounts[$level][$key]['total'] ?? 0) + 1;
                $this->validitycounts[$level][$key]['designstops'] = ($this->validitycounts[$level][$key]['designstops'] ?? 0)
                    + ($validity['criterionstop'] ? 1 : 0);
            }
        }
        $mode = (string) ($this->filter['validity'] ?? result_validity::VALID);
        if (
            ($mode === result_validity::VALID && !$validity['valid'])
            || ($mode === result_validity::INVALID && $validity['valid'])
        ) {
            return;
        }

        yield [
            'nscales'     => count((array) ($trace['scaleabilities'] ?? [])),
            'attemptid'   => (int) $attempt->id,
            'runid'       => (int) $attempt->runid,
            'personid'    => (int) $attempt->personid,
            'twinid'      => (string) ($person->twinid ?? ''),
            // The person's own stratum: a run's people need not all share the run's.
            'personstratum' => (string) ($person->stratum ?? ''),
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
            'nitems'      => (int) ($trace['nitems'] ?? count((array) ($trace['items'] ?? []))),
            'se'          => $se,
            'stopreason'  => (string) ($trace['stopreason'] ?? ''),
            // The stop rule succeeded when the engine stopped on a
            // criterion of its own rather than running out of items.
            // A criterion met — what the stop rules' success counts — and,
            // apart from it, any planned end, the maximum included (#118).
            'stopreached' => $validity['criterionstop'],
            'designstopreached' => $validity['designstop'],
            'enginefinished' => $validity['enginefinished'],
            'valid' => $validity['valid'],
            'validityreason' => $validity['reason'],
            'runtimems'   => (int) ($attempt->runtimems ?? 0),
            // How it ended, as a code and in words, and where it ended (#106).
            'endreasoncode'  => $endcode,
            'storedreasoncode' => $this->endreasons[(int) $attempt->id] ?? '',
            'endreasonlabel' => $endcode !== ''
                ? reason_catalog::label($endcode)
                : '',
            'finalti'        => isset($trace['information']) && is_numeric($trace['information'])
                ? (float) $trace['information']
                : null,
            'activescalesatend' => isset($trace['progress']['activescales'])
                ? count((array) $trace['progress']['activescales'])
                : null,
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
        // Counted as the sittings pass (#99): what metrics::exposure() does,
        // without holding every sitting and a copy of every item list — that
        // was most of the global tab's 230 MB for 50,000 sittings.
        $counts = [];
        $n = 0;
        foreach ($this->each_observation() as $row) {
            $n++;
            foreach ((array) ($row['items'] ?? []) as $item) {
                $key = (string) $item;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return metrics::exposure_from_counts($counts, $n, $this->pool_size());
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

        // Keyed by sitting and person together, and forgotten whenever a new
        // stream begins. Keyed by the sitting alone, a cache from an earlier
        // selection answered for a later sitting that happened to reuse the
        // id — twelve subscale rows went missing in a test run that way.
        $attemptid = (int) ($observation['attemptid'] ?? 0);
        $key = $attemptid . ':' . (int) ($observation['personid'] ?? 0);
        if ($attemptid > 0 && $key === self::$detailfor) {
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

        self::$detailfor = $key;

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
