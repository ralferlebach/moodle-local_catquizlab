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
 * Attempt scheduler: materialises a run's attempt queue.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Creates the queued attempt rows a run needs, one per provisioned person (E3.1).
 *
 * Scheduling is pure lab-store bookkeeping: it inserts an attempt row at status
 * "queued" for every person of the run that has a Moodle user and no attempt
 * yet, and marks the run scheduled. It performs no engine work and starts no
 * worker — the timed ad-hoc task {@see \local_catquizlab\task\schedule_attempts}
 * calls it, and the collect/execute steps act on the rows it produces.
 */
class attempt_scheduler {
    /** @var int Attempt status: queued, not yet played. */
    public const STATUS_QUEUED = 0;

    /** @var int Attempt status: being played by a worker. */
    public const STATUS_RUNNING = 10;

    /** @var int Attempt status: trace collected from the engine. */
    public const STATUS_COLLECTED = 20;

    /** @var int Attempt status: collected trace validated. */
    public const STATUS_VALIDATED = 30;

    /** @var int Attempt status: failed. */
    public const STATUS_FAILED = 40;

    /** @var int Maximum number of tries before an attempt is failed for good. */
    public const MAX_TRIES = 3;

    /** @var int Retry backoff base in seconds (multiplied by the try count). */
    public const RETRY_BACKOFF = 60;

    /**
     * The status a stuck/failed attempt should take given how often it was tried.
     *
     * @param int $tries How many times it has been tried.
     * @param int $maxtries The retry ceiling.
     * @return int STATUS_QUEUED to retry, or STATUS_FAILED when exhausted.
     */
    public static function retry_status(int $tries, int $maxtries = self::MAX_TRIES): int {
        return $tries < $maxtries ? self::STATUS_QUEUED : self::STATUS_FAILED;
    }

    /**
     * Reclaim attempts stuck in "running" longer than the timeout.
     *
     * A crashed worker leaves an attempt running forever; this requeues it (with
     * a backoff) when tries remain, or fails it when they are exhausted.
     *
     * @param int|null $runid Limit to one run, or null for all runs.
     * @param int $timeoutseconds The staleness threshold in seconds.
     * @return int The number of attempts reclaimed (requeued or failed).
     */
    /**
     * How the queue breaks down for an operator, not for the database.
     *
     * `QUEUED` is a storage state and was being read as "waiting for a worker",
     * which is only one of the things it can mean. An attempt can be claimable
     * now, not due yet after a failure, held back because its run is not in a
     * state that hands out work, or paused by a decision somebody took. Those
     * four need four different responses, and one number gave them one.
     *
     * @return array{claimable: int, notdue: int, blocked: int, paused: int,
     *               running: int, collected: int, failed: int, queued: int}
     */
    public static function queue_breakdown(): array {
        global $DB;

        $now = time();
        $counts = [
            'claimable' => 0,
            'notdue'    => 0,
            'blocked'   => 0,
            'paused'    => 0,
            'running'   => (int) $DB->count_records(
                'local_catquizlab_attempt',
                ['status' => self::STATUS_RUNNING]
            ),
            'collected' => (int) $DB->count_records(
                'local_catquizlab_attempt',
                ['status' => self::STATUS_COLLECTED]
            ),
            'failed'    => (int) $DB->count_records(
                'local_catquizlab_attempt',
                ['status' => self::STATUS_FAILED]
            ),
        ];

        // Grouped by run so the run's state is read once rather than per
        // attempt: a queue of 1600 would otherwise be 1600 lookups to draw one
        // line of text.
        $rows = $DB->get_records_sql(
            'SELECT a.runid, r.status AS runstatus, r.manifestjson,
                    SUM(CASE WHEN a.nextruntime <= :now THEN 1 ELSE 0 END) AS due,
                    COUNT(1) AS total
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE a.status = :queued
           GROUP BY a.runid, r.status, r.manifestjson',
            ['now' => $now, 'queued' => self::STATUS_QUEUED]
        );

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $due = (int) $row->due;

            $manifest = json_decode((string) $row->manifestjson, true) ?: [];
            if (!empty($manifest['lifecycle']['paused'])) {
                $counts['paused'] += $total;
                continue;
            }

            if (!run_lifecycle::is_runnable((int) $row->runid)) {
                // Queued against a run that cannot hand out work: scheduled,
                // failed, finished. The attempts are real and unreachable, and
                // showing them as waiting invites waiting for them.
                $counts['blocked'] += $total;
                continue;
            }

            $counts['claimable'] += $due;
            $counts['notdue'] += $total - $due;
        }

        $counts['queued'] = $counts['claimable'] + $counts['notdue']
            + $counts['blocked'] + $counts['paused'];

        return $counts;
    }


    /**
     * Whether there is work a worker could actually pick up right now.
     *
     * @return bool
     */
    public static function has_claimable_work(): bool {
        return self::queue_breakdown()['claimable'] > 0;
    }

    /**
     * Hand back everything a named worker was holding.
     *
     * Called when a worker is found to have died. This is the deliberate
     * counterpart to the timeout below: here we know the holder is gone, so
     * there is nothing to guess about.
     *
     * @param string $workerid The worker whose claims lapse.
     * @return int How many attempts went back into the queue.
     */
    public static function release_lease(string $workerid): int {

        global $DB;

        $held = $DB->get_records_select(
            'local_catquizlab_attempt',
            'status = :status AND leaseowner = :owner',
            ['status' => self::STATUS_RUNNING, 'owner' => $workerid],
            '',
            'id, tries'
        );

        $now = time();
        foreach ($held as $attempt) {
            $DB->set_field('local_catquizlab_attempt', 'leaseowner', null, ['id' => $attempt->id]);
            $DB->set_field('local_catquizlab_attempt', 'leaseexpires', 0, ['id' => $attempt->id]);
            self::apply_retry((int) $attempt->id, (int) $attempt->tries, $now);
        }

        return count($held);
    }

    /**
     * Record why an attempt failed, for the interface and for the next reader.
     *
     * @param int $attemptid The attempt.
     * @param string $error The worker's own reason.
     * @return void
     */
    public static function record_error(int $attemptid, string $error): void {

        global $DB;

        if (trim($error) === '') {
            return;
        }

        // Trimmed, because a stack trace in a list column helps nobody, and the
        // first line is what says what happened.
        $DB->set_field(
            'local_catquizlab_attempt',
            'lasterror',
            \core_text::substr(trim($error), 0, 1000),
            ['id' => $attemptid]
        );
    }

    /**
     * Hand back attempts whose claim has lapsed.
     *
     * The lease decides where there is one; the timeout is the fallback for
     * attempts claimed before leases existed.
     *
     * @param int|null $runid Restrict to one run, or null for all of them.
     * @param int $timeoutseconds How long a leaseless claim may sit untouched.
     * @return int How many attempts went back into the queue.
     */
    public static function reclaim_stale(?int $runid, int $timeoutseconds): int {

        global $DB;

        $now = time();

        // The lease decides, and the timeout is the fallback for attempts that
        // predate leases. Keying on timemodified alone was the defect: a worker
        // refreshes it while it works, so a stuck attempt and a slow one looked
        // the same.
        $params = ['status' => self::STATUS_RUNNING, 'cutoff' => $now - $timeoutseconds, 'now' => $now];
        $where = 'status = :status AND ((leaseexpires > 0 AND leaseexpires < :now)'
            . ' OR (leaseexpires = 0 AND timemodified < :cutoff))';
        if ($runid !== null) {
            $where .= ' AND runid = :runid';
            $params['runid'] = $runid;
        }

        $stale = $DB->get_records_select('local_catquizlab_attempt', $where, $params, '', 'id, tries');
        foreach ($stale as $attempt) {
            $DB->set_field('local_catquizlab_attempt', 'leaseowner', null, ['id' => $attempt->id]);
            $DB->set_field('local_catquizlab_attempt', 'leaseexpires', 0, ['id' => $attempt->id]);
            self::apply_retry((int) $attempt->id, (int) $attempt->tries, $now);
        }
        return count($stale);
    }

    /**
     * Record a failed attempt, requeuing it with backoff when tries remain.
     *
     * @param int $attemptid The attempt.
     * @return int The resulting status.
     */
    public static function retry_or_fail(int $attemptid): int {
        global $DB;

        $tries = (int) $DB->get_field('local_catquizlab_attempt', 'tries', ['id' => $attemptid]);
        return self::apply_retry($attemptid, $tries, time());
    }

    /**
     * Abort a run: fail every attempt that has not reached a terminal state.
     *
     * @param int $runid The run.
     * @return int The number of attempts aborted.
     */
    public static function abort(int $runid): int {

        global $DB;

        $active = [self::STATUS_QUEUED, self::STATUS_RUNNING];
        [$insql, $params] = $DB->get_in_or_equal($active, SQL_PARAMS_NAMED);
        $params['runid'] = $runid;
        $count = $DB->count_records_select('local_catquizlab_attempt', "runid = :runid AND status $insql", $params);

        $DB->set_field_select(
            'local_catquizlab_attempt',
            'status',
            self::STATUS_FAILED,
            "runid = :runid AND status $insql",
            $params
        );
        $DB->set_field_select(
            'local_catquizlab_attempt',
            'timemodified',
            time(),
            "runid = :runid AND status = :failed",
            ['runid' => $runid, 'failed' => self::STATUS_FAILED]
        );

        if ($count > 0) {
            \local_catquizlab\event\run_aborted::create([
                'objectid' => $runid,
                'context'  => \context_system::instance(),
            ])->trigger();
        }

        return $count;
    }

    /**
     * Apply the retry decision to one attempt.
     *
     * @param int $attemptid The attempt.
     * @param int $tries The current try count.
     * @param int $now The current time.
     * @return int The resulting status.
     */
    protected static function apply_retry(int $attemptid, int $tries, int $now): int {

        global $DB;

        $status = self::retry_status($tries);
        $update = (object) [
            'id'           => $attemptid,
            'status'       => $status,
            'timemodified' => $now,
        ];
        if ($status === self::STATUS_QUEUED) {
            $update->nextruntime = $now + self::RETRY_BACKOFF * max(1, $tries);
        }
        $DB->update_record('local_catquizlab_attempt', $update);

        // A sitting that has given up is the moment to ask whether this run is
        // failing the same way over and over. Asking later — on a page load, in
        // a scheduled task — means the pool spends minutes more on sittings
        // that will fail identically.
        if ($status === self::STATUS_FAILED) {
            $runid = (int) $DB->get_field('local_catquizlab_attempt', 'runid', ['id' => $attemptid]);
            if ($runid > 0) {
                circuit_breaker::check($runid);
            }
        }

        return $status;
    }

    /**
     * Create the queued attempts for a run.
     *
     * @param int $runid The run to schedule.
     * @return int The number of attempts newly created.
     */
    public static function schedule(int $runid): int {

        global $DB;

        $now = time();
        $persons = $DB->get_records_select(
            'local_catquizlab_person',
            'runid = :runid AND moodleuserid IS NOT NULL',
            ['runid' => $runid],
            'id ASC'
        );

        $created = 0;
        foreach ($persons as $person) {
            if ($DB->record_exists('local_catquizlab_attempt', ['runid' => $runid, 'personid' => $person->id])) {
                continue;
            }
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid'           => $runid,
                'personid'        => $person->id,
                'engineattemptid' => null,
                'status'          => self::STATUS_QUEUED,
                'tracejson'       => null,
                'runtimems'       => null,
                'timecreated'     => $now,
                'timemodified'    => $now,
            ]);
            $created++;
        }

        if ($created > 0) {
            $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_SCHEDULED, ['id' => $runid]);
        }

        return $created;
    }

    /**
     * Queue the ad-hoc task that schedules a run's attempts.
     *
     * @param int $runid The run to schedule.
     * @return void
     */
    public static function queue(int $runid): void {

        $task = new \local_catquizlab\task\schedule_attempts();
        $task->set_custom_data(['runid' => $runid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
