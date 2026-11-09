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
 * Stop an experiment, completely and now.
 *
 * Before this there was no such thing. Deleting asked the workers playing the experiment to finish their sitting
 * and refused until they had — while every tick started new workers for the sittings still waiting, so the next
 * attempt to delete found the same situation again. Cancelling was per run, and left the run's sittings queued.
 *
 * A stop takes the work away rather than asking for it back:
 *
 * - every run that can still build or hand out work (created, scheduled, ready, running) is cancelled, so no claim
 *   finds it and no tick starts a worker for it;
 * - its waiting and running sittings are closed as failed with the reason. A worker in the middle of one learns at
 *   its next heartbeat (every 20 s) that the sitting is no longer its own and stops before its next answer; what it
 *   reports afterwards is recorded as late and not applied;
 * - the workers that held them are asked to exit;
 * - setup and planning tasks of those runs are removed, the experiment leaves the execution queue.
 *
 * Kept: everything already collected, the traces still being fetched for sittings that finished, and runs that are
 * aggregating — their sittings are all done, and stopping them would only throw a finished measurement away.
 * A stopped run can be reset and run again from the runs page.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class experiment_stop {
    /** @var string The reason, in runs' status log, the execution queue and the sittings' history. */
    public const REASON = 'stopped_by_operator';

    /** @var int[] Run statuses a stop cancels: every one that can still build or hand out work. */
    public const STOPPABLE = [
        registry::STATUS_DRAFT,
        registry::STATUS_SCHEDULED,
        registry::STATUS_READY,
        registry::STATUS_RUNNING,
    ];

    /** @var string[] Ad-hoc tasks that build or hand out work. Collection, aggregation and export stay. */
    public const TASKS = [
        '\local_catquizlab\task\orchestrate_run',
        '\local_catquizlab\task\schedule_attempts',
        '\local_catquizlab\task\dispatch_worker',
    ];

    /**
     * What a stop would do, before it is done.
     *
     * @param int $experimentid The experiment.
     * @return array{runs: int, queued: int, running: int, workers: int, tasks: int, queue: int, aggregating: int,
     *               any: bool}
     */
    public static function preview(int $experimentid): array {
        global $DB;

        $runs = $DB->get_records_menu('local_catquizlab_run', ['experimentid' => $experimentid], '', 'id, status');
        $stoppable = array_keys(array_filter($runs, static fn($status): bool => in_array((int) $status, self::STOPPABLE, true)));
        $aggregating = count(array_filter($runs, static fn($status): bool => (int) $status === registry::STATUS_AGGREGATING));

        $queued = 0;
        $running = 0;
        $workers = 0;
        if ($runs !== []) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'r');
            $queued = $DB->count_records_select(
                'local_catquizlab_attempt',
                'runid ' . $insql . ' AND status = :queued',
                $params + ['queued' => attempt_scheduler::STATUS_QUEUED]
            );
            $running = $DB->count_records_select(
                'local_catquizlab_attempt',
                'runid ' . $insql . ' AND status = :running',
                $params + ['running' => attempt_scheduler::STATUS_RUNNING]
            );
            $workers = count(self::lease_owners(array_keys($runs)));
        }
        $tasks = count(self::task_ids(array_keys($runs)));
        $queue = $DB->get_manager()->table_exists('local_catquizlab_execqueue') ? $DB->count_records_select(
            'local_catquizlab_execqueue',
            'experimentid = :experimentid AND state IN (:waiting, :running)',
            ['experimentid' => $experimentid, 'waiting' => execution_queue::WAITING, 'running' => execution_queue::RUNNING]
        ) : 0;

        return [
            'runs'        => count($stoppable),
            'queued'      => $queued,
            'running'     => $running,
            'workers'     => $workers,
            'tasks'       => $tasks,
            'queue'       => $queue,
            'aggregating' => $aggregating,
            'any'         => count($stoppable) + $queued + $running + $tasks + $queue > 0,
        ];
    }

    /**
     * Stop the experiment.
     *
     * Idempotent: a second stop finds nothing left to stop and changes nothing.
     *
     * @param int $experimentid The experiment.
     * @return array{runs: int, queued: int, running: int, workers: int, tasks: int, queue: int, aggregating: int}
     */
    public static function stop(int $experimentid): array {
        global $DB;

        $runs = $DB->get_records_menu('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC', 'id, status');
        $stoppable = array_keys(array_filter($runs, static fn($status): bool => in_array((int) $status, self::STOPPABLE, true)));
        $aggregating = count(array_filter($runs, static fn($status): bool => (int) $status === registry::STATUS_AGGREGATING));

        // Under the claim lock where it can be had: a claim that reads the run as ready while the stop is half done
        // would hand out a sitting of a cancelled run. Not having it is not a reason not to stop — a claim that slips
        // through holds a sitting this closes anyway, and its worker hears so at its next heartbeat.
        $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_claim')->get_lock('claim', 10);
        try {
            $transaction = $DB->start_delegated_transaction();

            // Runs first, so nothing claims from them while their sittings are closed.
            foreach ($stoppable as $runid) {
                run_lifecycle::set_status((int) $runid, registry::STATUS_CANCELLED, self::REASON, ['timemodified' => time()]);
            }

            $owners = $runs === [] ? [] : self::lease_owners(array_keys($runs));
            $workers = 0;
            foreach ($owners as $workerid) {
                if (worker_registry::request_stop($workerid)) {
                    $workers++;
                }
            }

            $closed = $runs === [] ? ['queued' => 0, 'running' => 0] : self::close_sittings(array_keys($runs));

            $tasks = self::task_ids(array_keys($runs));
            if ($tasks !== []) {
                $DB->delete_records_list('task_adhoc', 'id', $tasks);
            }

            $queue = execution_queue::forget($experimentid, self::REASON);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Rolled back here, not left open for whoever catches it: a caller that records the failure would
            // otherwise write into the transaction that is about to be thrown away.
            if (isset($transaction)) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }

        run_lifecycle::refresh_experiment_by_id($experimentid);
        foreach ($stoppable as $runid) {
            \local_catquizlab\event\run_aborted::create([
                'objectid' => (int) $runid,
                'context'  => \context_system::instance(),
            ])->trigger();
        }

        return [
            'runs'        => count($stoppable),
            'queued'      => $closed['queued'],
            'running'     => $closed['running'],
            'workers'     => $workers,
            'tasks'       => count($tasks),
            'queue'       => $queue,
            'aggregating' => $aggregating,
        ];
    }

    /**
     * Close the open sittings of runs, with the stop as their reason.
     *
     * Also for a run its setup was still building when it was stopped: the last setup stage plans sittings, and
     * those must not outlive the stop either.
     *
     * @param int[] $runids The runs.
     * @return array{queued: int, running: int} How many of each were closed.
     */
    public static function close_sittings(array $runids): array {
        global $DB;

        if ($runids === []) {
            return ['queued' => 0, 'running' => 0];
        }
        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'r');
        $reason = get_string('history:experimentstopped', 'local_catquizlab');

        // The sittings being played get a line in their history: which worker held them, and that it was a stop,
        // not a failure of theirs.
        $running = $DB->get_records_select(
            'local_catquizlab_attempt',
            'runid ' . $insql . ' AND status = :running',
            $params + ['running' => attempt_scheduler::STATUS_RUNNING],
            '',
            'id, leaseowner'
        );
        foreach ($running as $attempt) {
            attempt_history::record((int) $attempt->id, attempt_history::ABANDONED, [
                'workerid' => $attempt->leaseowner,
                'detail'   => $reason,
            ]);
        }

        $queued = $DB->count_records_select(
            'local_catquizlab_attempt',
            'runid ' . $insql . ' AND status = :queued',
            $params + ['queued' => attempt_scheduler::STATUS_QUEUED]
        );

        $DB->execute(
            'UPDATE {local_catquizlab_attempt}
                SET status = :failed, lasterror = :reason, leaseowner = NULL, leaseexpires = 0, timemodified = :now
              WHERE runid ' . $insql . ' AND status IN (:queued, :running)',
            $params + [
                'failed'  => attempt_scheduler::STATUS_FAILED,
                'reason'  => $reason,
                'now'     => time(),
                'queued'  => attempt_scheduler::STATUS_QUEUED,
                'running' => attempt_scheduler::STATUS_RUNNING,
            ]
        );

        return ['queued' => $queued, 'running' => count($running)];
    }

    /**
     * Whether a run has been stopped (cancelled) since somebody last looked.
     *
     * @param int $runid The run.
     * @return bool
     */
    public static function is_stopped(int $runid): bool {
        global $DB;

        return (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]) === registry::STATUS_CANCELLED;
    }

    /**
     * The workers holding a sitting of these runs.
     *
     * Whatever the lease's expiry: an expired lease of a worker that is still alive is still a browser in the
     * experiment.
     *
     * @param int[] $runids The runs.
     * @return string[]
     */
    protected static function lease_owners(array $runids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'r');

        return array_values(array_filter(array_map('strval', $DB->get_fieldset_sql(
            'SELECT DISTINCT leaseowner FROM {local_catquizlab_attempt}
              WHERE runid ' . $insql . ' AND status = :running AND leaseowner IS NOT NULL',
            $params + ['running' => attempt_scheduler::STATUS_RUNNING]
        ))));
    }

    /**
     * The ids of the work-building tasks queued for these runs.
     *
     * @param int[] $runids The runs.
     * @return int[]
     */
    protected static function task_ids(array $runids): array {
        global $DB;

        if ($runids === []) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(self::TASKS, SQL_PARAMS_NAMED, 'cls');
        $ids = [];
        foreach ($DB->get_records_select('task_adhoc', 'classname ' . $insql, $params, '', 'id, customdata') as $row) {
            $data = json_decode((string) $row->customdata, true) ?: [];
            if (in_array((int) ($data['runid'] ?? 0), array_map('intval', $runids), true)) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }
}
