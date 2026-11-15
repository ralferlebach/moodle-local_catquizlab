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
 * The figures of many runs at once, from one reading of the database (#158, #160).
 *
 * The progress bar, the question counter and the forecast of every run on a page are made from these, so that the
 * three cannot disagree, and with a fixed number of queries however many runs there are: every per-run figure is a
 * GROUP BY, none is a query per run (#160 AK12, #158 AK6).
 *
 * The set read is the runs asked for plus every run a worker can claim right now, because a run's completion
 * depends on what is ahead of it in the claim order, whichever experiment that belongs to (#160 AK04).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_figures {
    /** @var int The capacity window, in seconds: at least this, more for long sittings. */
    public const WINDOW = 600;

    /** @var int The latest finished executions whose durations are compared with the whole sample. */
    public const RECENT = 5;

    /**
     * The figures of these runs, and of every run that can hand out work now.
     *
     * @param int[] $runids The runs a page shows.
     * @param int $now The clock.
     * @return array{now: int, runs: array<int, array>, claimable: int[], groups: array[], workers: array}
     *      runs: per run its record fields (experimentid, status, cellkey, strategy, paused, held), sitting counts
     *      (total, collected, failed, queued, inflight, stale, tracepending, open), the duration sample of its
     *      finished executions (n, s, ss in seconds, lastend) and of its last RECENT ones (recent), its first start,
     *      the sittings it has in flight with their summed and latest start times (flying, flyingstarts, flyinglast),
     *      and the busy seconds that fell into the capacity window (busy, from busyfrom,
     *      in a window of window seconds).
     *      groups: the queued sittings of the claimable runs, grouped by run and due time, in claim order.
     */
    public static function read(array $runids, int $now): array {
        global $DB;

        $runids = array_values(array_unique(array_map('intval', $runids)));
        [$csql, $cparams] = $DB->get_in_or_equal([registry::STATUS_READY, registry::STATUS_RUNNING], SQL_PARAMS_NAMED, 'cs');
        $claimablecandidates = array_map('intval', $DB->get_fieldset_select(
            'local_catquizlab_run',
            'id',
            "status {$csql}",
            $cparams
        ));
        // With the other runs of the experiments those belong to: a run not yet observed borrows its duration from
        // them, and it must borrow the same whichever page asks.
        $siblings = [];
        if ($claimablecandidates !== []) {
            [$ssql, $sparams] = $DB->get_in_or_equal($claimablecandidates, SQL_PARAMS_NAMED, 'sb');
            $siblings = array_map('intval', $DB->get_fieldset_sql(
                "SELECT id FROM {local_catquizlab_run}
                  WHERE experimentid IN (SELECT experimentid FROM {local_catquizlab_run} WHERE id {$ssql})",
                $sparams
            ));
        }
        $all = array_values(array_unique(array_merge($runids, $claimablecandidates, $siblings)));
        sort($all);

        $out = ['now' => $now, 'runs' => [], 'claimable' => [], 'groups' => [], 'workers' => worker_registry::summary()];
        if ($all === []) {
            return $out;
        }

        // The runs themselves: status, design cell, strategy, pause and hold, from the records (no query per run).
        $records = $DB->get_records_list('local_catquizlab_run', 'id', $all, '', 'id, experimentid, status, cellkey, manifestjson');
        foreach ($records as $run) {
            $manifest = json_decode((string) $run->manifestjson, true) ?: [];
            $paused = !empty($manifest['lifecycle']['paused']);
            $out['runs'][(int) $run->id] = [
                'runid' => (int) $run->id,
                'experimentid' => (int) $run->experimentid,
                'status' => (int) $run->status,
                'cellkey' => (string) $run->cellkey,
                'strategy' => (string) ($manifest['config']['strategy']['key'] ?? ''),
                'paused' => $paused,
                'held' => circuit_breaker::is_held($run),
                'total' => 0, 'collected' => 0, 'failed' => 0, 'queued' => 0, 'inflight' => 0, 'stale' => 0,
                'tracepending' => 0, 'open' => 0,
                'sample' => ['n' => 0, 's' => 0.0, 'ss' => 0.0], 'lastend' => null, 'firststart' => null,
                'requested' => null,
                'flying' => 0, 'flyingstarts' => 0, 'flyinglast' => null,
                // The durations of its last RECENT finished executions: a duration that drifts (a site under more
                // load than an hour ago) shows as a gap between these and the whole sample.
                'recent' => ['n' => 0, 's' => 0.0, 'ss' => 0.0],
                // Busy seconds inside the window, and since when there was any: a site that started five
                // minutes ago has had five minutes to be busy, not the whole window.
                'busy' => 0.0, 'busyfrom' => null, 'window' => 0,
            ];
        }
        $all = array_keys($out['runs']);
        if ($all === []) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($all, SQL_PARAMS_NAMED, 'fr');

        // Sittings per run and status, in one GROUP BY; in flight is a running sitting whose lease holds.
        $rows = $DB->get_recordset_sql(
            "SELECT x.runid, x.status, x.live, COUNT(1) AS n
               FROM (SELECT runid, status, CASE WHEN status = :running AND leaseexpires > :now THEN 1 ELSE 0 END AS live
                       FROM {local_catquizlab_attempt}
                      WHERE runid {$insql}) x
           GROUP BY x.runid, x.status, x.live",
            $params + ['running' => attempt_scheduler::STATUS_RUNNING, 'now' => $now]
        );
        foreach ($rows as $row) {
            $r = &$out['runs'][(int) $row->runid];
            $n = (int) $row->n;
            $r['total'] += $n;
            switch ((int) $row->status) {
                case attempt_scheduler::STATUS_QUEUED:
                    $r['queued'] += $n;
                    $r['open'] += $n;
                    break;
                case attempt_scheduler::STATUS_RUNNING:
                    // A lease that has run out is a sitting nobody plays: reclaimed and queued again later.
                    $r[(int) $row->live === 1 ? 'inflight' : 'stale'] += $n;
                    $r['open'] += $n;
                    break;
                case attempt_scheduler::STATUS_TRACEPENDING:
                    $r['tracepending'] += $n;
                    $r['open'] += $n;
                    break;
                case attempt_scheduler::STATUS_COLLECTED:
                case attempt_scheduler::STATUS_VALIDATED:
                    $r['collected'] += $n;
                    break;
                default:
                    $r['failed'] += $n;
            }
            unset($r);
        }
        $rows->close();

        // Finished executions: their number and durations, and when the last one ended.
        $finished = $DB->get_recordset_sql(
            "SELECT runid, COUNT(1) AS n, SUM(runtimems) AS s, SUM(runtimems * runtimems) AS ss, MAX(timecreated) AS lastend
               FROM {local_catquizlab_attemptlog}
              WHERE runid {$insql} AND outcome IN (:collected, :failed)
           GROUP BY runid",
            $params + ['collected' => attempt_history::COLLECTED, 'failed' => attempt_history::FAILED]
        );
        foreach ($finished as $row) {
            $out['runs'][(int) $row->runid]['sample'] = ['n' => (int) $row->n, 's' => (float) $row->s / 1000,
                'ss' => (float) $row->ss / 1e6];
            $out['runs'][(int) $row->runid]['lastend'] = (int) $row->lastend;
        }
        $finished->close();
        $recent = $DB->get_recordset_sql(
            "SELECT x.runid, COUNT(1) AS n, SUM(x.runtimems) AS s, SUM(x.runtimems * x.runtimems) AS ss
               FROM (SELECT runid, runtimems,
                            ROW_NUMBER() OVER (PARTITION BY runid ORDER BY timecreated DESC, id DESC) AS position
                       FROM {local_catquizlab_attemptlog}
                      WHERE runid {$insql} AND outcome IN (:collected, :failed)) x
              WHERE x.position <= :recent
           GROUP BY x.runid",
            $params + ['collected' => attempt_history::COLLECTED, 'failed' => attempt_history::FAILED,
                'recent' => self::RECENT]
        );
        foreach ($recent as $row) {
            $out['runs'][(int) $row->runid]['recent'] = ['n' => (int) $row->n, 's' => (float) $row->s / 1000,
                'ss' => (float) $row->ss / 1e6];
        }
        $recent->close();

        // The first start of each run: persisted, so it never moves with a poll.
        $firsts = $DB->get_records_sql_menu(
            "SELECT runid, MIN(timecreated) FROM {local_catquizlab_attemptlog}
              WHERE runid {$insql} AND outcome = :started GROUP BY runid",
            $params + ['started' => attempt_history::STARTED]
        );
        foreach ($firsts as $runid => $first) {
            $out['runs'][(int) $runid]['firststart'] = (int) $first;
        }
        // When a start was first asked for: "not yet — start requested …" before the first sitting begins.
        $requests = $DB->get_records_sql_menu(
            "SELECT runid, MIN(timecreated) FROM {local_catquizlab_runlog}
              WHERE runid {$insql} AND event = :event GROUP BY runid",
            $params + ['event' => run_log::START_REQUESTED]
        );
        foreach ($requests as $runid => $first) {
            $out['runs'][(int) $runid]['requested'] = (int) $first;
        }

        // Claimable now: ready or running, neither paused nor held. A worker claims from these only.
        foreach ($out['runs'] as $runid => $run) {
            $ready = in_array($run['status'], [registry::STATUS_READY, registry::STATUS_RUNNING], true);
            if ($ready && !$run['paused'] && !$run['held']) {
                $out['claimable'][] = (int) $runid;
            }
        }

        // The window: at least WINDOW, three mean durations where sittings are long, so one fits in it. The
        // durations of the runs being played now: an old experiment's hour-long sittings must not stretch the
        // window of a site now playing one-minute ones, or the window is mostly idle time and the capacity reads low.
        $n = 0;
        $s = 0.0;
        $basis = $out['claimable'] !== [] ? array_intersect_key($out['runs'], array_flip($out['claimable'])) : $out['runs'];
        foreach ($basis as $run) {
            $n += $run['sample']['n'];
            $s += $run['sample']['s'];
        }
        $window = (int) max(self::WINDOW, ceil(3 * ($n > 0 ? $s / $n : 0.0)));
        $since = $now - $window;

        // Busy time inside the window (#160 AK07): of an execution that began before the window, only the part
        // inside it. Counting the whole of a long sitting that merely ended in the window made slots no worker had.
        $busy = $DB->get_recordset_sql(
            "SELECT runid, SUM(CASE WHEN timecreated * 1000 - COALESCE(runtimems, 0) < :sincems
                                    THEN timecreated * 1000 - :sincems2 ELSE COALESCE(runtimems, 0) END) AS busyms,
                    MIN(timecreated * 1000 - COALESCE(runtimems, 0)) AS firstms
               FROM {local_catquizlab_attemptlog}
              WHERE runid {$insql} AND outcome IN (:collected, :failed) AND timecreated > :since AND timecreated <= :now
           GROUP BY runid",
            $params + ['collected' => attempt_history::COLLECTED, 'failed' => attempt_history::FAILED,
                'since' => $since, 'now' => $now, 'sincems' => $since * 1000, 'sincems2' => $since * 1000]
        );
        foreach ($busy as $row) {
            $r = &$out['runs'][(int) $row->runid];
            $r['busy'] += max(0.0, (float) $row->busyms / 1000);
            $r['busyfrom'] = min($r['busyfrom'] ?? PHP_INT_MAX, max($since, (int) floor((float) $row->firstms / 1000)));
            unset($r);
        }
        $busy->close();

        // Sittings in flight: how many, since when, and their part of the window.
        $flying = $DB->get_recordset_sql(
            "SELECT a.runid, COUNT(1) AS n, SUM(s.started) AS sumstart, MIN(s.started) AS firststart,
                    MAX(s.started) AS laststart,
                    SUM(CASE WHEN s.started < :since THEN :window ELSE :now - s.started END) AS busy
               FROM {local_catquizlab_attempt} a
               JOIN (SELECT attemptid, MAX(timecreated) AS started
                       FROM {local_catquizlab_attemptlog}
                      WHERE runid {$insql} AND outcome = :started
                   GROUP BY attemptid) s ON s.attemptid = a.id
              WHERE a.status = :running AND a.leaseexpires > :now2
           GROUP BY a.runid",
            $params + ['started' => attempt_history::STARTED, 'running' => attempt_scheduler::STATUS_RUNNING,
                'since' => $since, 'window' => $window, 'now' => $now, 'now2' => $now]
        );
        foreach ($flying as $row) {
            $r = &$out['runs'][(int) $row->runid];
            $r['flying'] = (int) $row->n;
            $r['flyingstarts'] = (int) $row->sumstart;
            $r['flyinglast'] = (int) $row->laststart;
            $r['busy'] += max(0.0, (float) $row->busy);
            $r['busyfrom'] = min($r['busyfrom'] ?? PHP_INT_MAX, max($since, (int) $row->firststart));
            unset($r);
        }
        $flying->close();
        foreach ($out['runs'] as $runid => $run) {
            $out['runs'][$runid]['window'] = $window;
        }

        // The queue as job_claim reads it: nextruntime, then timecreated, then id (#160 AK04). Grouped by run and
        // due time, so a thousand fresh sittings of a run are one group and each retry with its own time another.
        if ($out['claimable'] !== []) {
            [$qsql, $qparams] = $DB->get_in_or_equal($out['claimable'], SQL_PARAMS_NAMED, 'q');
            $groups = $DB->get_recordset_sql(
                "SELECT runid, nextruntime, COUNT(1) AS n, MIN(timecreated) AS firstcreated, MIN(id) AS firstid
                   FROM {local_catquizlab_attempt}
                  WHERE runid {$qsql} AND status = :queued
               GROUP BY runid, nextruntime",
                $qparams + ['queued' => attempt_scheduler::STATUS_QUEUED]
            );
            foreach ($groups as $row) {
                $out['groups'][] = ['runid' => (int) $row->runid, 'due' => (int) $row->nextruntime, 'n' => (int) $row->n,
                    'firstcreated' => (int) $row->firstcreated, 'firstid' => (int) $row->firstid];
            }
            $groups->close();
            // A sitting whose lease ran out is reclaimed and queued again with a backoff: at the end of the line.
            foreach ($out['claimable'] as $runid) {
                if ($out['runs'][$runid]['stale'] > 0) {
                    $out['groups'][] = ['runid' => $runid, 'due' => $now + attempt_scheduler::RETRY_BACKOFF,
                        'n' => $out['runs'][$runid]['stale'], 'firstcreated' => PHP_INT_MAX, 'firstid' => PHP_INT_MAX];
                }
            }
            usort($out['groups'], static fn(array $a, array $b): int =>
                [$a['due'], $a['firstcreated'], $a['firstid']] <=> [$b['due'], $b['firstcreated'], $b['firstid']]);
        }

        return $out;
    }
}
