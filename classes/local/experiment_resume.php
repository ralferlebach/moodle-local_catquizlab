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

namespace local_catquizlab\local;

/**
 * Resume a stopped experiment, keeping everything it has collected (#165).
 *
 * Until 0.7.65 a stopped experiment had no way on but a reset of each run on its own page, and a reset deletes the
 * run's sittings, people, items and results: on the live installation 1018 collected sittings of six runs, to be
 * played again. Resuming is the other way:
 *
 * - every run the stop cancelled goes on: a run that was ready or playing is ready again, one nobody had started is a
 *   draft again and is prepared when its turn comes; a run that was being prepared when the stop came is named, not
 *   guessed at (its setup may be half done): reset it, or start a new replicate;
 * - every sitting the stop closed is queued again (#166: cancelled, never failed). One whose engine attempt was
 *   finished after the stop, by a worker that had not heard of it yet, is not played again: it waits for its trace and
 *   is collected (AK4). One whose engine attempt is in progress is continued by the worker that takes it;
 * - collected, validated and failed sittings, results, items, fingerprints and seeds are not touched;
 * - the experiment takes a place in the execution line like any started one (#163), and the pipeline is kicked.
 *
 * Under a lock, and idempotent: a second resume finds nothing cancelled and says so.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class experiment_resume {
    /** @var string A run that goes on as it was: ready, its sittings played where they stopped. */
    public const RESUME = 'resume';

    /** @var string A run nobody had started: a draft again, prepared at the experiment's turn. */
    public const PREPARE = 'prepare';

    /** @var string A run that cannot go on as it is; the reason says why and what to do instead. */
    public const BLOCKED = 'blocked';

    /** @var string Why in the run's log and the sittings' history. */
    public const REASON = 'resumed_by_operator';

    /**
     * What a resume would do, run by run, before it is done (AK2).
     *
     * @param int $experimentid The experiment.
     * @param int $onlyrunid One cancelled run of it, or 0 for every run the stop cancelled (AK1: a run cancelled on its
     *      own goes on on its own).
     * @return array{resumable: bool, reason: string, runs: array[], totals: array}
     *      runs: runid, cellkey, status, action (resume, prepare, blocked), blocker (string key or ''), planned,
     *      collected, validated, failed (technical, kept), cancelled (closed by an operator), requeue (to be played),
     *      finishedafter (finished in the engine after the stop: collected, not played again), inprogress (an engine
     *      attempt to be continued), leases (still held: none after a stop), held (why a run that goes on stays paused:
     *      the circuit breaker's cause or "by a person"; '' if it is not paused).
     */
    public static function preview(int $experimentid, int $onlyrunid = 0): array {
        global $DB;

        $out = ['resumable' => false, 'reason' => '', 'runs' => [], 'totals' => ['runs' => 0, 'planned' => 0,
            'collected' => 0, 'validated' => 0, 'failed' => 0, 'cancelled' => 0, 'requeue' => 0, 'finishedafter' => 0,
            'inprogress' => 0, 'leases' => 0, 'blocked' => 0, 'held' => 0]];
        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid], 'id, timedeleting');
        if (!$experiment) {
            $out['reason'] = 'noexperiment';

            return $out;
        }
        if ((int) $experiment->timedeleting > 0) {
            $out['reason'] = 'being-deleted';

            return $out;
        }
        $runs = $DB->get_records(
            'local_catquizlab_run',
            ['experimentid' => $experimentid, 'status' => registry::STATUS_CANCELLED]
                + ($onlyrunid > 0 ? ['id' => $onlyrunid] : []),
            'id ASC',
            'id, cellkey, status, testcmid'
        );
        if ($runs === []) {
            $out['reason'] = 'nothing';

            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'run');
        $bystatus = [];
        $rows = $DB->get_recordset_sql(
            "SELECT runid, status, COUNT(1) AS n, SUM(CASE WHEN leaseexpires > :now THEN 1 ELSE 0 END) AS leased
               FROM {local_catquizlab_attempt}
              WHERE runid {$insql}
           GROUP BY runid, status",
            $params + ['now' => time()]
        );
        foreach ($rows as $row) {
            $bystatus[(int) $row->runid][(int) $row->status] = [(int) $row->n, (int) $row->leased];
        }
        $rows->close();
        $persons = $DB->get_records_sql_menu(
            "SELECT runid, COUNT(1) FROM {local_catquizlab_person} WHERE runid {$insql} GROUP BY runid",
            $params
        );
        // Ready to be played once: its setup ran to the end. The provisioning task says so with "provisioning_ready";
        // a run prepared in one go (e2e_prepare, the setup assistant) only with its status changing to ready; either
        // way it was ready or running before something cancelled it.
        $provisioned = $DB->get_records_sql_menu(
            "SELECT runid, COUNT(1)
               FROM {local_catquizlab_runlog}
              WHERE runid {$insql}
                    AND (event = :ready OR (event = :changed AND (" . $DB->sql_like('detail', ':toready') . '
                         OR ' . $DB->sql_like('detail', ':torunning') . ')))
           GROUP BY runid',
            $params + ['ready' => run_log::PROVISIONING_READY, 'changed' => run_log::STATUS_CHANGED,
                'toready' => '%"to":"ready"%', 'torunning' => '%"to":"running"%']
        );
        $engine = self::engine_attempts(array_keys($runs));

        foreach ($runs as $runid => $run) {
            $runid = (int) $runid;
            $s = $bystatus[$runid] ?? [];
            $n = static fn(int $status): int => (int) ($s[$status][0] ?? 0);
            $cancelled = $n(attempt_scheduler::STATUS_CANCELLED);
            $finishedafter = count(array_filter($engine[$runid] ?? [], static fn(array $e): bool => $e['complete']));
            $inprogress = count(array_filter($engine[$runid] ?? [], static fn(array $e): bool => !$e['complete']));
            $planned = array_sum(array_map(static fn(array $c): int => $c[0], $s));
            $leases = array_sum(array_map(static fn(array $c): int => $c[1], $s));
            $cm = (int) $run->testcmid > 0 && $DB->record_exists('course_modules', ['id' => (int) $run->testcmid]);
            $built = $planned > 0 || (int) ($persons[$runid] ?? 0) > 0 || (int) $run->testcmid > 0;
            $blocker = '';
            if (!$built) {
                // Nothing of it was built: a draft again, prepared at the experiment's turn.
                $action = self::PREPARE;
            } else if (empty($provisioned[$runid]) || $planned === 0) {
                // Stopped while being prepared, never ready: what of it exists is unknown to the run itself.
                [$action, $blocker] = [self::BLOCKED, 'partial'];
            } else if (!$cm) {
                // Its activity is gone (deleted in the course, or by a cleanup): there is nothing to play in.
                [$action, $blocker] = [self::BLOCKED, 'noactivity'];
            } else if ($leases > 0) {
                // A sitting still held by a worker: after a stop there is none; wait for its lease to run out.
                [$action, $blocker] = [self::BLOCKED, 'leased'];
            } else {
                $action = self::RESUME;
            }
            // Paused before the stop — by the circuit breaker or by a person — it stays paused (#133 AK-CLARA-12):
            // resuming the experiment is not releasing a pause, and the sittings a breaker held wait for a person.
            $held = '';
            if ($action === self::RESUME && run_lifecycle::is_paused($runid)) {
                $reason = run_lifecycle::pause_reason($runid);
                $held = strpos($reason, circuit_breaker::PAUSE_REASON) === 0
                    ? reason_catalog::label(substr($reason, strlen(circuit_breaker::PAUSE_REASON) + 1))
                    : get_string('resume:heldbyperson', 'local_catquizlab');
            }
            $entry = [
                'runid' => $runid, 'cellkey' => (string) $run->cellkey, 'status' => (int) $run->status,
                'action' => $action, 'blocker' => $blocker, 'held' => $held,
                'planned' => $planned,
                'collected' => $n(attempt_scheduler::STATUS_COLLECTED) + $n(attempt_scheduler::STATUS_VALIDATED),
                'validated' => $n(attempt_scheduler::STATUS_VALIDATED),
                'failed' => $n(attempt_scheduler::STATUS_FAILED),
                'cancelled' => $cancelled,
                'requeue' => $action === self::RESUME ? max(0, $cancelled - $finishedafter) : 0,
                'finishedafter' => $action === self::RESUME ? $finishedafter : 0,
                'inprogress' => $action === self::RESUME ? $inprogress : 0,
                'leases' => $leases,
            ];
            $out['runs'][] = $entry;
            $out['totals']['runs']++;
            $summed = ['planned', 'collected', 'validated', 'failed', 'cancelled', 'requeue', 'finishedafter',
                'inprogress', 'leases'];
            foreach ($summed as $key) {
                $out['totals'][$key] += $entry[$key];
            }
            $out['totals']['blocked'] += $action === self::BLOCKED ? 1 : 0;
            $out['totals']['held'] += $held !== '' ? 1 : 0;
        }
        $out['resumable'] = $out['totals']['runs'] > $out['totals']['blocked'];
        $out['reason'] = $out['resumable'] ? '' : 'allblocked';

        return $out;
    }

    /**
     * Resume: the runs the stop cancelled go on, the sittings it closed are played (or collected), nothing else changes.
     *
     * @param int $experimentid The experiment.
     * @param int $onlyrunid One cancelled run of it, or 0 for every run the stop cancelled.
     * @return array{ok: bool, reason: string, runs: int, prepared: int, requeued: int, collecting: int, blocked: int,
     *      held: int, queue: array} held: runs that went on but stay paused, as before the stop.
     */
    public static function resume(int $experimentid, int $onlyrunid = 0): array {
        global $DB;

        $none = ['runs' => 0, 'prepared' => 0, 'requeued' => 0, 'collecting' => 0, 'blocked' => 0, 'held' => 0,
            'queue' => []];
        $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_resume')->get_lock('e' . $experimentid, 10);
        if (!$lock) {
            return ['ok' => false, 'reason' => 'busy'] + $none;
        }
        try {
            $preview = self::preview($experimentid, $onlyrunid);
            if (!$preview['resumable']) {
                return ['ok' => false, 'reason' => $preview['reason']] + $none;
            }
            $engine = self::engine_attempts(array_column($preview['runs'], 'runid'));
            $done = $none;
            $transaction = $DB->start_delegated_transaction();
            foreach ($preview['runs'] as $run) {
                $runid = (int) $run['runid'];
                if ($run['action'] === self::BLOCKED) {
                    $done['blocked']++;
                    continue;
                }
                if ($run['action'] === self::PREPARE) {
                    run_lifecycle::set_status($runid, registry::STATUS_DRAFT, self::REASON, ['timemodified' => time()]);
                    $done['prepared']++;
                    $done['runs']++;
                    continue;
                }
                // Finished in the engine after the stop: collected from its engine attempt, not played again (AK4).
                foreach ($engine[$runid] ?? [] as $sittingid => $attempt) {
                    if (!$attempt['complete']) {
                        continue;
                    }
                    $DB->update_record('local_catquizlab_attempt', (object) ['id' => $sittingid,
                        'status' => attempt_scheduler::STATUS_TRACEPENDING, 'engineattemptid' => $attempt['id'],
                        'nextruntime' => time(), 'lasterror' => null, 'leaseowner' => null, 'leaseexpires' => 0,
                        'timemodified' => time()]);
                    attempt_history::record($sittingid, attempt_history::RESUMED, [
                        'detail' => get_string('history:resumedcollect', 'local_catquizlab'),
                        'engineattemptid' => $attempt['id'], 'reasoncode' => self::REASON]);
                    $done['collecting']++;
                }
                // The rest of what the stop closed: queued again, at its place (#163: queued first, first played).
                $ids = $DB->get_fieldset_select(
                    'local_catquizlab_attempt',
                    'id',
                    'runid = :runid AND status = :cancelled',
                    ['runid' => $runid, 'cancelled' => attempt_scheduler::STATUS_CANCELLED]
                );
                foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
                    [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'sitting');
                    $DB->execute(
                        "UPDATE {local_catquizlab_attempt}
                            SET status = :queued, nextruntime = 0, lasterror = NULL, leaseowner = NULL,
                                leaseexpires = 0, timemodified = :now
                          WHERE id {$insql} AND status = :cancelled",
                        $params + ['queued' => attempt_scheduler::STATUS_QUEUED, 'now' => time(),
                            'cancelled' => attempt_scheduler::STATUS_CANCELLED]
                    );
                    attempt_history::record_many(
                        $chunk,
                        attempt_history::RESUMED,
                        self::REASON,
                        get_string('history:experimentresumed', 'local_catquizlab')
                    );
                }
                $done['requeued'] += count($ids);
                $done['held'] += $run['held'] !== '' ? 1 : 0;
                run_lifecycle::set_status($runid, registry::STATUS_READY, self::REASON, ['timemodified' => time()]);
                run_log::record($runid, run_log::RUN_RESUMED, ['requeued' => count($ids), 'why' => self::REASON]);
                $done['runs']++;
            }
            $transaction->allow_commit();
            run_lifecycle::refresh_experiment_by_id($experimentid);

            // In the line like any started experiment (#163), at its end; the pipeline goes on at once. One still in
            // the line (a run of it cancelled on its own) keeps its place: the run is played at the experiment's turn.
            $done['queue'] = execution_queue::state_of($experimentid) !== null
                ? ['ok' => true, 'position' => 0, 'reason' => 'already-queued']
                : execution_queue::enqueue($experimentid);
            \local_catquizlab\task\kick_pipeline::queue('experiment-resumed');
            debug_trace::record(debug_trace::LIFECYCLE, 'experiment_resumed', ['experimentid' => $experimentid] + $done);

            return ['ok' => true, 'reason' => ''] + $done;
        } finally {
            $lock->release();
        }
    }

    /**
     * What resetting the stopped runs and running them again would delete, run by run (#165 AK6).
     *
     * @param int $experimentid The experiment.
     * @return array{runs: array[], totals: array} runs: runid, cellkey, sittings, collected, persons, items, results.
     */
    public static function restart_inventory(int $experimentid): array {
        global $DB;

        $runs = $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid,
            'status' => registry::STATUS_CANCELLED], 'id ASC', 'id, cellkey');
        $out = ['runs' => [], 'totals' => ['runs' => 0, 'sittings' => 0, 'collected' => 0, 'persons' => 0, 'items' => 0,
            'results' => 0]];
        if ($runs === []) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'run');
        $count = static fn(string $table, string $extra = '', array $more = []): array => $DB->get_records_sql_menu(
            "SELECT runid, COUNT(1) FROM {{$table}} WHERE runid {$insql}{$extra} GROUP BY runid",
            $params + $more
        );
        $sittings = $count('local_catquizlab_attempt');
        $collected = $count('local_catquizlab_attempt', ' AND status IN (:collected, :validated)', [
            'collected' => attempt_scheduler::STATUS_COLLECTED, 'validated' => attempt_scheduler::STATUS_VALIDATED]);
        $persons = $count('local_catquizlab_person');
        $items = $count('local_catquizlab_item');
        $results = $count('local_catquizlab_result');
        foreach ($runs as $runid => $run) {
            $entry = ['runid' => (int) $runid, 'cellkey' => (string) $run->cellkey,
                'sittings' => (int) ($sittings[$runid] ?? 0), 'collected' => (int) ($collected[$runid] ?? 0),
                'persons' => (int) ($persons[$runid] ?? 0), 'items' => (int) ($items[$runid] ?? 0),
                'results' => (int) ($results[$runid] ?? 0)];
            $out['runs'][] = $entry;
            $out['totals']['runs']++;
            foreach (['sittings', 'collected', 'persons', 'items', 'results'] as $key) {
                $out['totals'][$key] += $entry[$key];
            }
        }

        return $out;
    }

    /**
     * The engine attempts of the sittings a stop closed, where there are: finished or in progress (AK4).
     *
     * The sitting's own attempt in the run's activity (#173): the one an execution bound to it, or the account's only
     * one there. The newest of several was taken until 0.7.83.
     *
     * @param int[] $runids The runs.
     * @return array<int, array<int, array{id: int, complete: bool}>> Run id => sitting id => its engine attempt.
     */
    protected static function engine_attempts(array $runids): array {
        global $DB;

        $out = [];
        if ($runids === [] || !$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'run');
        // The sitting's own attempt (#173): the one bound to it, or — when none was bound — the account's only one on
        // the activity. A bound attempt of another account or activity, or two unbound ones, is not taken: the
        // sitting is queued again like the others and its next execution says what it found.
        $rows = $DB->get_recordset_sql(
            "SELECT a.id AS sittingid, a.runid, aa.id AS engineid, aa.attemptstate
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_person} p ON p.id = a.personid
               JOIN {local_catquizlab_run} r ON r.id = a.runid
               JOIN {course_modules} cm ON cm.id = r.testcmid
               JOIN {adaptivequiz_attempt} aa ON aa.userid = p.moodleuserid AND aa.instance = cm.instance
              WHERE a.runid {$insql} AND a.status = :cancelled AND a.tries > 0
                    AND ((a.engineattemptid > 0 AND aa.id = a.engineattemptid)
                         OR ((a.engineattemptid IS NULL OR a.engineattemptid = 0)
                             AND (SELECT COUNT(1) FROM {adaptivequiz_attempt} n
                                   WHERE n.userid = aa.userid AND n.instance = aa.instance) = 1))",
            $params + ['cancelled' => attempt_scheduler::STATUS_CANCELLED]
        );
        foreach ($rows as $row) {
            $out[(int) $row->runid][(int) $row->sittingid] = ['id' => (int) $row->engineid,
                'complete' => (string) $row->attemptstate === 'complete'];
        }
        $rows->close();

        return $out;
    }
}
