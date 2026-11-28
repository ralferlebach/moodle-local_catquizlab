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
 * Progress in questions, not only in sittings (#141, #142).
 *
 * A CLASSIC sitting of 625 questions moved the bar by one sitting after an hour and not at all before; an adaptive
 * sitting of 20 counted the same. What is measured here is the questions the engine has stored as answered —
 * confirmed, not presented — each engine attempt once, by the sitting it is bound to (#173, #180): never the newest
 * attempt of a person taken as its owner. A retry of the same engine attempt keeps its answers, also while it waits
 * in the queue; an attempt no sitting owns is said apart, discarded only where a decision retired it.
 *
 * How many questions there will be is known exactly only for a fixed form (the whole pool). For an adaptive
 * strategy it is estimated from the sittings collected so far, bounded by the global maximum before there are any,
 * and unknown where there is neither: said as such, never presented as a count.
 *
 * A fixed number of queries per call, whatever the number of runs, sittings or questions: the sums are made by
 * the database.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_progress {
    /** @var string The number of questions is known: a fixed form, every sitting the whole pool. */
    public const EXACT = 'exact';

    /** @var string Estimated from the sittings collected so far. */
    public const ESTIMATE = 'estimate';

    /** @var string No sitting collected yet: the global maximum, an upper bound. */
    public const BOUND = 'bound';

    /** @var string Neither a sample nor a maximum: not determinable. */
    public const UNKNOWN = 'unknown';

    /** @var int Collected sittings needed before their mean is the estimate. */
    public const MIN_SAMPLE = 3;

    /** @var string[] The buckets an answered question falls in, each exactly once (#180). */
    public const BUCKETS = ['collected', 'technical', 'running', 'queued', 'held', 'terminal', 'discarded', 'unknown',
        'foreign', 'missing'];

    /**
     * Questions answered and expected, per run: one projection by identity (#141, #142, #180).
     *
     * Every answer is the engine's count of one engine attempt, and every engine attempt of the run's persons in the
     * run's test is counted once, by what it is for the lab — never by its id or its age:
     * - bound to a sitting (engineattemptid, #173): by the sitting's state — collected (a trace stored or on its way),
     *   technical (collected, but ended by the host or before the design, completion contract #179), running, queued
     *   (requeued for a retry: the same engine attempt goes on, its answers are kept), held (failed or cancelled with
     *   its engine attempt still open: resumable on a decision), terminal (failed or cancelled, the attempt closed);
     * - not bound to any sitting of the run: discarded where an explicit decision retired it (local_catquizlab_retired),
     *   unknown otherwise — an older try of a release without binding, a start not yet bound, a second attempt nobody
     *   decided on. Not counted, said apart, never guessed (no newest id as the owner);
     * - bound, but not an attempt of the sitting's person in the run's test (foreign), or gone from the host (missing):
     *   a binding that is wrong, said as such.
     * Done is what the run's sittings have answered through their binding (collected, technical, running, queued,
     * held, terminal). The planned questions of a fixed form are its pool times the sittings, whatever failed; what
     * held, terminal and technical sittings did not answer is not ahead (no worker will play it without a decision):
     * notahead, said apart. Remaining is the work ahead of the sittings still to be played (running, queued, and
     * those not bound yet), net of the answers they keep.
     *
     * One statement for all runs, so that all buckets are of the same moment (one snapshot of the database), plus the
     * runs' limits (preload): a fixed number of queries whatever the number of runs, sittings or questions.
     *
     * @param array $runs Run id => anything (the runs to read; their sittings are read here, in the same snapshot).
     * @return array<int, array> Run id => answered (collected), inflight (running), done, persitting, kind (EXACT,
     *      ESTIMATE, BOUND, UNKNOWN), expected (null where UNKNOWN), remaining (null where UNKNOWN), notahead (null
     *      where not a fixed form), secondsperquestion, percent, discarded (retired by a decision), buckets (bucket =>
     *      [sittings or attempts, answers]), sittings (state => count), unbound (open sittings without a binding),
     *      ambiguous (persons with two or more engine attempts nobody owns), diagnostics (codes of what does not add
     *      up; none where everything does).
     */
    public static function for_runs(array $runs): array {
        global $DB;

        $out = [];
        if ($runs === []) {
            return $out;
        }
        $runids = array_map('intval', array_keys($runs));
        $rows = [];
        if ($DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $rows = self::buckets($runids);
        }

        self::preload($runids);
        foreach ($runids as $runid) {
            $b = [];
            foreach (array_merge(self::BUCKETS, ['unboundopen', 'collectednohost', 'endedunbound', 'hostall']) as $name) {
                $b[$name] = $rows[$runid][$name] ?? ['n' => 0, 'answers' => 0, 'runtimems' => 0, 'top' => 0, 'extra' => 0];
            }
            $out[$runid] = self::project($runid, $b);
        }

        return $out;
    }

    /**
     * The buckets of the runs, in one statement.
     *
     * @param int[] $runids The runs.
     * @return array<int, array<string, array{n: int, answers: int, runtimems: int, top: int, extra: int}>>
     */
    protected static function buckets(array $runids): array {
        global $DB;

        $q = attempt_scheduler::STATUS_QUEUED;
        $r = attempt_scheduler::STATUS_RUNNING;
        $finished = implode(', ', [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_VALIDATED,
            attempt_scheduler::STATUS_TRACEPENDING]);
        // The completion contract's verdict as stored (attempt_collector: json_encode, the class a plain word).
        $technical = "(a.completionjson LIKE '%\"class\":\"" . completion_contract::TECHNICAL . "\"%'"
            . " OR a.completionjson LIKE '%\"class\":\"" . completion_contract::INCOMPLETE . "\"%')";
        [$in1, $p1] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'qa');
        [$in2, $p2] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'qb');
        [$in3, $p3] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'qc');

        $sql = "SELECT s.runid, s.bucket, COUNT(1) AS n, SUM(s.answers) AS answers, SUM(s.runtimems) AS runtimems,
                       MAX(s.answers) AS top, 0 AS extra
                  FROM (SELECT a.runid,
                               CASE
                                   WHEN a.engineattemptid > 0 AND aa.id IS NULL THEN 'missing'
                                   WHEN aa.id IS NOT NULL AND (p.id IS NULL OR cm.id IS NULL OR aa.userid <> p.moodleuserid
                                        OR aa.instance <> cm.instance) THEN 'foreign'
                                   WHEN a.status IN ({$finished}) THEN
                                       CASE WHEN aa.id IS NULL THEN 'collectednohost'
                                            WHEN {$technical} THEN 'technical' ELSE 'collected' END
                                   WHEN a.status = {$r} THEN CASE WHEN aa.id IS NULL THEN 'unboundopen' ELSE 'running' END
                                   WHEN a.status = {$q} THEN CASE WHEN aa.id IS NULL THEN 'unboundopen' ELSE 'queued' END
                                   WHEN aa.id IS NULL THEN 'endedunbound'
                                   WHEN aa.attemptstate = 'inprogress' THEN 'held'
                                   ELSE 'terminal'
                               END AS bucket,
                               COALESCE(aa.questionsattempted, 0) AS answers,
                               CASE WHEN a.status IN ({$finished}) THEN COALESCE(a.runtimems, 0) ELSE 0 END AS runtimems
                          FROM {local_catquizlab_attempt} a
                          JOIN {local_catquizlab_run} r ON r.id = a.runid
                     LEFT JOIN {course_modules} cm ON cm.id = r.testcmid
                     LEFT JOIN {local_catquizlab_person} p ON p.id = a.personid
                     LEFT JOIN {adaptivequiz_attempt} aa ON aa.id = a.engineattemptid
                         WHERE a.runid {$in1}) s
              GROUP BY s.runid, s.bucket
             UNION ALL
                SELECT u.runid, u.bucket, SUM(u.n) AS n, SUM(u.answers) AS answers, 0 AS runtimems, MAX(u.top) AS top,
                       SUM(CASE WHEN u.n > 1 THEN 1 ELSE 0 END) AS extra
                  FROM (SELECT r.id AS runid, aa.userid,
                               CASE WHEN EXISTS (SELECT 1 FROM {local_catquizlab_retired} x WHERE x.engineattemptid = aa.id)
                                    THEN 'discarded' ELSE 'unknown' END AS bucket,
                               COUNT(1) AS n, SUM(aa.questionsattempted) AS answers, MAX(aa.questionsattempted) AS top
                          FROM {local_catquizlab_run} r
                          JOIN {course_modules} cm ON cm.id = r.testcmid
                          JOIN {local_catquizlab_person} p ON p.runid = r.id
                          JOIN {adaptivequiz_attempt} aa ON aa.instance = cm.instance AND aa.userid = p.moodleuserid
                         WHERE r.id {$in2}
                               AND NOT EXISTS (SELECT 1 FROM {local_catquizlab_attempt} b
                                                WHERE b.runid = r.id AND b.engineattemptid = aa.id)
                      GROUP BY r.id, aa.userid,
                               CASE WHEN EXISTS (SELECT 1 FROM {local_catquizlab_retired} x WHERE x.engineattemptid = aa.id)
                                    THEN 'discarded' ELSE 'unknown' END) u
              GROUP BY u.runid, u.bucket
             UNION ALL
                SELECT r.id AS runid, 'hostall' AS bucket, COUNT(aa.id) AS n, SUM(COALESCE(aa.questionsattempted, 0)) AS answers,
                       0 AS runtimems, 0 AS top, 0 AS extra
                  FROM {local_catquizlab_run} r
                  JOIN {course_modules} cm ON cm.id = r.testcmid
                  JOIN {local_catquizlab_person} p ON p.runid = r.id
                  JOIN {adaptivequiz_attempt} aa ON aa.instance = cm.instance AND aa.userid = p.moodleuserid
                 WHERE r.id {$in3}
              GROUP BY r.id";
        $out = [];
        $set = $DB->get_recordset_sql($sql, $p1 + $p2 + $p3);
        foreach ($set as $row) {
            $out[(int) $row->runid][(string) $row->bucket] = ['n' => (int) $row->n, 'answers' => (int) $row->answers,
                'runtimems' => (int) $row->runtimems, 'top' => (int) $row->top, 'extra' => (int) $row->extra];
        }
        $set->close();

        return $out;
    }

    /**
     * One run's projection from its buckets.
     *
     * @param int $runid The run.
     * @param array $b Its buckets.
     * @return array As for_runs().
     */
    protected static function project(int $runid, array $b): array {
        $limits = self::limits($runid);
        $fixed = $limits['fixedform'] && $limits['poolsize'] > 0;
        $collected = $b['collected'];
        $sittings = [
            'collected' => $collected['n'] + $b['collectednohost']['n'],
            'technical' => $b['technical']['n'],
            'running' => $b['running']['n'],
            'queued' => $b['queued']['n'],
            'unbound' => $b['unboundopen']['n'],
            'held' => $b['held']['n'],
            'terminal' => $b['terminal']['n'] + $b['endedunbound']['n'],
            'foreign' => $b['foreign']['n'],
            'missing' => $b['missing']['n'],
        ];
        $total = array_sum($sittings);

        if ($fixed) {
            [$kind, $persitting] = [self::EXACT, (float) $limits['poolsize']];
        } else if ($collected['n'] >= self::MIN_SAMPLE) {
            // From the sittings that ended as designed: a timeout's or a broken form's length is not a sitting's.
            [$kind, $persitting] = [self::ESTIMATE, $collected['answers'] / $collected['n']];
        } else if (($bound = self::bound($limits)) > 0) {
            [$kind, $persitting] = [self::BOUND, (float) $bound];
        } else {
            [$kind, $persitting] = [self::UNKNOWN, null];
        }

        $done = 0;
        foreach (['collected', 'technical', 'running', 'queued', 'held', 'terminal'] as $name) {
            $done += $b[$name]['answers'];
        }
        $diagnostics = [];
        foreach (['foreign', 'missing', 'collectednohost'] as $name) {
            if ($b[$name]['n'] > 0) {
                $diagnostics[] = $name;
            }
        }
        // Every engine attempt of the run's persons in its test once: bound and found, or not bound at all.
        $accounted = $done + $b['discarded']['answers'] + $b['unknown']['answers'];
        if ($b['hostall']['answers'] !== $accounted) {
            $diagnostics[] = $b['hostall']['answers'] < $accounted ? 'doublebound' : 'unaccounted';
        }

        $remaining = null;
        $expected = null;
        $notahead = null;
        if ($persitting !== null) {
            $open = [['running', $b['running']], ['queued', $b['queued']]];
            $remaining = 0.0;
            foreach ($open as [$name, $bucket]) {
                $ahead = $bucket['n'] * $persitting - $bucket['answers'];
                if ($ahead < 0 && $kind === self::EXACT) {
                    $diagnostics[] = 'overcount';
                }
                // Past its estimate a sitting still to be played has at least one question ahead: it is not done
                // until it is collected, and 100 % before that would be a completion that has not happened (#141).
                $remaining += $kind === self::EXACT ? max(0.0, $ahead) : max($ahead, (float) $bucket['n']);
            }
            $remaining += $b['unboundopen']['n'] * $persitting;
            $remaining = (int) round($remaining);
            if ($kind === self::EXACT) {
                $expected = (int) round($total * $persitting);
                // What ended without its whole form and will not be played without a decision.
                $notahead = $expected - $done - $remaining;
                if ($notahead < 0 || $b['collected']['top'] > $persitting || $b['technical']['top'] > $persitting) {
                    $diagnostics[] = 'overcount';
                }
            } else {
                $expected = $done + $remaining;
            }
        }
        $buckets = [];
        foreach (self::BUCKETS as $name) {
            $buckets[$name] = [$b[$name]['n'], $b[$name]['answers']];
        }

        return [
            'answered' => $collected['answers'],
            'inflight' => $b['running']['answers'],
            'queuedanswered' => $b['queued']['answers'],
            'done' => $done,
            'persitting' => $persitting === null ? null : round($persitting, 2),
            'kind' => $kind,
            'expected' => $expected,
            'remaining' => $remaining,
            'notahead' => $notahead,
            'secondsperquestion' => ($collected['answers'] > 0 && $collected['runtimems'] > 0)
                ? round(($collected['runtimems'] / 1000) / $collected['answers'], 3) : null,
            'percent' => $expected ? self::percent($done, $expected) : null,
            'discarded' => $b['discarded']['answers'],
            'held' => $b['held']['answers'],
            'unknown' => $b['unknown']['answers'],
            'buckets' => $buckets,
            'sittings' => $sittings + ['total' => $total],
            'unbound' => $b['unboundopen']['n'],
            'ambiguous' => $b['unknown']['extra'],
            'diagnostics' => array_values(array_unique($diagnostics)),
        ];
    }

    /**
     * Sum per-run figures to an experiment's: the kind is the least certain of its runs.
     *
     * @param array $perrun From for_runs().
     * @return array done, expected (null where any run is UNKNOWN), remaining, notahead (null unless every run is a
     *      fixed form), kind, percent, discarded, held, unknown, queuedanswered, unbound, ambiguous, diagnostics.
     */
    public static function total(array $perrun): array {
        $rank = [self::EXACT => 0, self::ESTIMATE => 1, self::BOUND => 2, self::UNKNOWN => 3];
        $kind = self::EXACT;
        $done = 0;
        $expected = 0;
        $remaining = 0;
        $discarded = 0;
        $unknown = false;
        $sums = ['held' => 0, 'unknown' => 0, 'queuedanswered' => 0, 'unbound' => 0, 'ambiguous' => 0];
        $notahead = 0;
        $exact = $perrun !== [];
        $diagnostics = [];
        foreach ($perrun as $run) {
            $done += (int) $run['done'];
            $discarded += (int) ($run['discarded'] ?? 0);
            foreach (array_keys($sums) as $key) {
                $sums[$key] += (int) ($run[$key] ?? 0);
            }
            if (($run['notahead'] ?? null) === null) {
                $exact = false;
            } else {
                $notahead += (int) $run['notahead'];
            }
            $diagnostics = array_merge($diagnostics, $run['diagnostics'] ?? []);
            if ($run['expected'] === null) {
                $unknown = true;
            } else {
                $expected += (int) $run['expected'];
                $remaining += (int) $run['remaining'];
            }
            if ($rank[$run['kind']] > $rank[$kind]) {
                $kind = $run['kind'];
            }
        }

        return [
            'done' => $done,
            'expected' => $unknown || $perrun === [] ? null : $expected,
            'remaining' => $unknown || $perrun === [] ? null : $remaining,
            'kind' => $perrun === [] ? self::UNKNOWN : $kind,
            'percent' => !$unknown && $expected > 0 ? self::percent($done, $expected) : null,
            'discarded' => $discarded,
            'notahead' => $exact ? $notahead : null,
            'diagnostics' => array_values(array_unique($diagnostics)),
        ] + $sums;
    }

    /**
     * Read the limits of several runs at once: two queries, not two per run.
     *
     * @param int[] $runids The runs.
     * @return void
     */
    protected static function preload(array $runids): void {
        global $DB;

        $missing = array_values(array_diff($runids, array_keys(self::$limits)));
        if ($missing === []) {
            return;
        }
        $records = $DB->get_records_list('local_catquizlab_run', 'id', $missing);
        [$insql, $params] = $DB->get_in_or_equal($missing, SQL_PARAMS_NAMED, 'p');
        $pools = $DB->get_records_sql_menu(
            "SELECT runid, COUNT(1) FROM {local_catquizlab_item} WHERE runid {$insql} GROUP BY runid",
            $params
        );
        // Runs from before manifests carried their definition fall back to their experiment's: read once for all
        // of them, not once per run (#158 AK6).
        $fallback = [];
        foreach ($records as $record) {
            $manifest = json_decode((string) $record->manifestjson, true) ?: [];
            if (!isset($manifest['config']['definition'])) {
                $fallback[(int) $record->experimentid] = true;
            }
        }
        $configs = $fallback === [] ? [] : $DB->get_records_list(
            'local_catquizlab_experiment',
            'id',
            array_keys($fallback),
            '',
            'id, configjson'
        );
        foreach ($missing as $runid) {
            $out = ['fixedform' => false, 'poolsize' => (int) ($pools[$runid] ?? 0), 'maxquestions' => 0];
            try {
                $definition = [];
                if (isset($records[$runid])) {
                    $manifest = json_decode((string) $records[$runid]->manifestjson, true) ?: [];
                    $config = $configs[(int) $records[$runid]->experimentid]->configjson ?? '';
                    $definition = $manifest['config']['definition']
                        ?? ((string) $config !== '' ? experiment_definition::from_json((string) $config)->get_normalised() : []);
                }
            } catch (\Throwable $e) {
                $definition = [];
            }
            if ($definition !== [] && isset($definition['strategy'])) {
                $strategy = (string) $definition['strategy'];
                $out['fixedform'] = strategy_catalog::has($strategy) && strategy_catalog::fixed_form($strategy);
                $options = test_provisioner::options_from_definition($definition);
                $out['maxquestions'] = (int) ($options['maxquestions'] ?? 0);
            }
            self::$limits[$runid] = $out;
        }
    }

    /**
     * A run's limits: fixed form or not, its pool, its global maximum.
     *
     * @param int $runid The run.
     * @return array{fixedform: bool, poolsize: int, maxquestions: int}
     */
    public static function limits(int $runid): array {
        self::preload([$runid]);

        return self::$limits[$runid];
    }

    /**
     * A whole-number percentage that reads 100 only when it is.
     *
     * @param int $done Questions answered.
     * @param int $expected Questions expected.
     * @return int
     */
    protected static function percent(int $done, int $expected): int {
        if ($expected <= 0) {
            return 0;
        }

        return $done < $expected ? min(99, (int) floor(100 * $done / $expected)) : 100;
    }

    /**
     * The most questions a sitting can have: the global maximum, the pool where that is smaller or unlimited.
     *
     * @param array $limits From limits().
     * @return int 0 where neither is known.
     */
    public static function bound(array $limits): int {
        $max = (int) $limits['maxquestions'];
        $pool = (int) $limits['poolsize'];
        if ($max === experiment_definition::ENGINE_UNLIMITED || $max <= 0) {
            return max(0, $pool);
        }

        return $pool > 0 ? min($max, $pool) : $max;
    }

    /** @var array<int, array> Per run, its limits, for this request. */
    protected static array $limits = [];

    /**
     * Forget what was read: for tests that change a run between two reads.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$limits = [];
    }
}
