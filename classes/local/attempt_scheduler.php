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
    public static function reclaim_stale(?int $runid, int $timeoutseconds): int {
        global $DB;

        $now = time();
        $params = ['status' => self::STATUS_RUNNING, 'cutoff' => $now - $timeoutseconds];
        $where = 'status = :status AND timemodified < :cutoff';
        if ($runid !== null) {
            $where .= ' AND runid = :runid';
            $params['runid'] = $runid;
        }

        $stale = $DB->get_records_select('local_catquizlab_attempt', $where, $params, '', 'id, tries');
        foreach ($stale as $attempt) {
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
