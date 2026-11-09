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
 * confirmed, not presented — in the sittings collected and in those being played, each sitting once (the newest
 * engine attempt of its person, so that a retry is not counted twice).
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

    /**
     * Questions answered and expected, per run.
     *
     * @param array $runs Run id => sittings: collected, inflight, queued (open but not in flight).
     * @return array<int, array> Run id => answered (in collected sittings), inflight (answered so far in the sittings
     *      being played), done (both), persitting (questions a sitting has, or is expected to have), kind (EXACT,
     *      ESTIMATE, BOUND, UNKNOWN), expected (total questions of the run, null where UNKNOWN), remaining (null
     *      where UNKNOWN), secondsperquestion (from the collected sittings, null without them), percent.
     */
    public static function for_runs(array $runs): array {
        global $DB;

        $out = [];
        if ($runs === []) {
            return $out;
        }
        $runids = array_map('intval', array_keys($runs));
        $available = $DB->get_manager()->table_exists('adaptivequiz_attempt');

        // Collected sittings: the engine's answered count of their own engine attempt, and their running time.
        $collected = [];
        $inflight = [];
        if ($available) {
            [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'r');
            [$stsql, $stparams] = $DB->get_in_or_equal(
                [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_VALIDATED],
                SQL_PARAMS_NAMED,
                'st'
            );
            $collected = $DB->get_records_sql(
                "SELECT a.runid, COUNT(1) AS sittings, SUM(aa.questionsattempted) AS answered,
                        SUM(COALESCE(a.runtimems, 0)) AS runtimems
                   FROM {local_catquizlab_attempt} a
                   JOIN {adaptivequiz_attempt} aa ON aa.id = a.engineattemptid
                  WHERE a.runid {$insql} AND a.status {$stsql}
               GROUP BY a.runid",
                $params + $stparams
            );
            // Sittings being played: the newest engine attempt of the person on the run's test, in progress.
            [$insql2, $params2] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'q');
            $inflight = $DB->get_records_sql_menu(
                "SELECT a.runid, SUM(aa.questionsattempted)
                   FROM {local_catquizlab_attempt} a
                   JOIN {local_catquizlab_person} p ON p.id = a.personid
                   JOIN {local_catquizlab_run} r ON r.id = a.runid
                   JOIN {course_modules} cm ON cm.id = r.testcmid
                   JOIN {adaptivequiz_attempt} aa ON aa.userid = p.moodleuserid AND aa.instance = cm.instance
                  WHERE a.runid {$insql2} AND a.status = :running AND aa.attemptstate = :inprogress
                        AND aa.id = (SELECT MAX(n.id) FROM {adaptivequiz_attempt} n
                                      WHERE n.userid = aa.userid AND n.instance = aa.instance)
               GROUP BY a.runid",
                $params2 + ['running' => attempt_scheduler::STATUS_RUNNING, 'inprogress' => 'inprogress']
            );
        }

        self::preload($runids);
        foreach ($runs as $runid => $sittings) {
            $runid = (int) $runid;
            $limits = self::limits($runid);
            $c = $collected[$runid] ?? null;
            $answered = $c ? (int) $c->answered : 0;
            $csittings = $c ? (int) $c->sittings : 0;
            $now = (int) ($inflight[$runid] ?? 0);
            $nin = (int) ($sittings['inflight'] ?? 0);
            $nqueued = (int) ($sittings['queued'] ?? 0);

            if ($limits['fixedform'] && $limits['poolsize'] > 0) {
                [$kind, $persitting] = [self::EXACT, (float) $limits['poolsize']];
            } else if ($csittings >= self::MIN_SAMPLE) {
                [$kind, $persitting] = [self::ESTIMATE, $answered / $csittings];
            } else if (($bound = self::bound($limits)) > 0) {
                [$kind, $persitting] = [self::BOUND, (float) $bound];
            } else {
                [$kind, $persitting] = [self::UNKNOWN, null];
            }

            $remaining = null;
            $expected = null;
            if ($persitting !== null) {
                // A sitting in flight still has its expected share minus what it has already answered, never less.
                $remaining = (int) round(max(0.0, $nin * $persitting - $now) + $nqueued * $persitting);
                $expected = $answered + $now + $remaining;
            }
            $out[$runid] = [
                'answered' => $answered,
                'inflight' => $now,
                'done' => $answered + $now,
                'persitting' => $persitting === null ? null : round($persitting, 2),
                'kind' => $kind,
                'expected' => $expected,
                'remaining' => $remaining,
                'secondsperquestion' => ($c && $answered > 0 && (int) $c->runtimems > 0)
                    ? round(((int) $c->runtimems / 1000) / $answered, 3) : null,
                'percent' => $expected ? self::percent($answered + $now, $expected) : null,
            ];
        }

        return $out;
    }

    /**
     * Sum per-run figures to an experiment's: the kind is the least certain of its runs.
     *
     * @param array $perrun From for_runs().
     * @return array done, expected (null where any run is UNKNOWN), remaining, kind, percent.
     */
    public static function total(array $perrun): array {
        $rank = [self::EXACT => 0, self::ESTIMATE => 1, self::BOUND => 2, self::UNKNOWN => 3];
        $kind = self::EXACT;
        $done = 0;
        $expected = 0;
        $remaining = 0;
        $unknown = false;
        foreach ($perrun as $run) {
            $done += (int) $run['done'];
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
        ];
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
        foreach ($missing as $runid) {
            $out = ['fixedform' => false, 'poolsize' => (int) ($pools[$runid] ?? 0), 'maxquestions' => 0];
            try {
                $definition = isset($records[$runid]) ? run_registry::definition_for($records[$runid]) : [];
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
