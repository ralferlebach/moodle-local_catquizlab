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
 * The worker instances of this installation, and what they are doing.
 *
 * Without this table a worker exists only as an operating-system process.
 * Nothing can then say how many are running, whether a slot is already taken,
 * or when one last reported in — which is how a site configured for one job at
 * a time ended up with two claimed attempts and no way to see it.
 *
 * Two ideas carry the whole class:
 *
 * A **slot** is a concurrency place. `worker_concurrency = 1` means one live
 * worker installation-wide, not one job per process, and the slot is what makes
 * that checkable. Claiming a slot is an insert against a unique index, so two
 * simultaneous attempts to fill the same slot cannot both win — the database
 * decides, not a read-then-write in PHP that two processes can interleave.
 *
 * A **heartbeat** is the difference between slow and gone. A worker that is
 * merely busy keeps reporting; one whose process died stops. Recovery keys on
 * that rather than on how long an attempt has been running, because a long
 * attempt and a lost one look identical from the outside otherwise.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class worker_registry {
    /** @var int The worker has registered but not yet reported working. */
    public const STATUS_STARTING = 0;

    /** @var int The worker is alive and reporting. */
    public const STATUS_RUNNING = 10;

    /** @var int The worker finished its jobs and released its slot. */
    public const STATUS_STOPPED = 20;

    /** @var int The worker stopped reporting without releasing its slot. */
    public const STATUS_CRASHED = 30;

    /**
     * Whether this worker has reported since it was started.
     *
     * The slot is taken the moment a launch is attempted, so its existence
     * proves nothing. A heartbeat is the worker itself saying it is up, which
     * is the only evidence that distinguishes a running process from a shell
     * command that returned.
     *
     * @param string $workerid The worker.
     * @return bool
     */
    public static function has_reported(string $workerid): bool {
        global $DB;

        $row = $DB->get_record('local_catquizlab_worker', ['workerid' => $workerid], 'id, status');

        // Not the heartbeat: acquiring the slot writes one, so its presence
        // only proves that a launch was attempted. The status leaving STARTING
        // is the worker itself saying it is up, which is the thing a shell
        // command returning cannot fake.
        return $row && (int) $row->status !== self::STATUS_STARTING;
    }

    /**
     * How long a worker may go quiet before it counts as gone.
     *
     * Generous on purpose: a worker playing one long attempt must not be
     * declared dead, and the cost of waiting is a slot that stays busy for a
     * few minutes. Declaring a live worker crashed is the more expensive
     * mistake — its attempt would be handed to a second worker and played
     * twice.
     *
     * @var int
     */
    public const HEARTBEAT_TIMEOUT = 300;

    /**
     * Take a concurrency slot, or come back empty-handed.
     *
     * @param int $slot The slot to occupy.
     * @param string $workerid The instance identity.
     * @param int|null $pid The operating-system process, when known.
     * @return int|null The registry row id, or null when the slot is taken.
     */
    public static function acquire_slot(int $slot, string $workerid, ?int $pid = null): ?int {
        global $DB;

        $now = time();

        // Anything that stopped reporting first: a crashed worker holds its
        // slot for ever otherwise, and the installation quietly loses capacity.
        self::reap();

        // A worker restarting into its own slot is not a collision. Without
        // this exception a restarted worker could not come back until its own
        // heartbeat lapsed, which is the opposite of what a restart is for.
        if (self::slot_is_busy($slot, $workerid)) {
            return null;
        }

        $existing = $DB->get_record('local_catquizlab_worker', ['workerid' => $workerid]);
        if ($existing) {
            // The same identity restarting. Reuse the row rather than failing,
            // so a restarted worker does not need a different name each time.
            $DB->update_record('local_catquizlab_worker', (object) [
                'id'           => $existing->id,
                'slot'         => $slot,
                'status'        => self::STATUS_STARTING,
                'pid'           => $pid,
                'hostname'      => gethostname() ?: null,
                'lasterror'     => null,
                // Everything that belonged to the process that used this
                // identity before. A stop asked of it is not a stop asked of
                // this one, and it was granted at the first heartbeat — which
                // is why a worker with 250 attempts waiting played exactly one
                // and finished.
                'stoprequested' => 0,
                'currentattempt' => 0,
                'workerstate'   => 'starting',
                'heartbeat'     => $now,
                'timemodified'  => $now,
            ]);

            return (int) $existing->id;
        }

        try {
            return (int) $DB->insert_record('local_catquizlab_worker', (object) [
                'workerid'     => $workerid,
                'slot'         => $slot,
                'status'       => self::STATUS_STARTING,
                'pid'          => $pid,
                'hostname'     => gethostname() ?: null,
                'jobsdone'      => 0,
                'stoprequested' => 0,
                'currentattempt' => 0,
                'workerstate'   => 'starting',
                'heartbeat'     => $now,
                'timecreated'   => $now,
                'timemodified'  => $now,
            ]);
        } catch (\dml_exception $e) {
            // The unique index refused it: another process filled this identity
            // between the check and the insert. Losing that race is the correct
            // outcome, not an error.
            return null;
        }
    }

    /**
     * Whether a live worker already occupies a slot.
     *
     * @param int $slot The slot.
     * @param string|null $exceptworkerid An instance that does not count against itself.
     * @return bool
     */
    public static function slot_is_busy(int $slot, ?string $exceptworkerid = null): bool {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(
            [self::STATUS_STARTING, self::STATUS_RUNNING],
            SQL_PARAMS_NAMED,
            'st'
        );
        $params['slot'] = $slot;
        $params['cutoff'] = time() - self::HEARTBEAT_TIMEOUT;

        $where = 'slot = :slot AND status ' . $insql . ' AND heartbeat >= :cutoff';
        if ($exceptworkerid !== null) {
            $where .= ' AND workerid <> :self';
            $params['self'] = $exceptworkerid;
        }

        return $DB->record_exists_select('local_catquizlab_worker', $where, $params);
    }

    /**
     * Report that a worker is alive, and optionally that it finished a job.
     *
     * @param string $workerid The instance.
     * @param int $jobsdone Jobs completed since the last report.
     * @return bool Whether the worker is registered.
     */
    public static function heartbeat(string $workerid, int $jobsdone = 0): bool {
        global $DB;

        $worker = $DB->get_record('local_catquizlab_worker', ['workerid' => $workerid]);
        if (!$worker) {
            return false;
        }

        $now = time();
        $DB->update_record('local_catquizlab_worker', (object) [
            'id'           => $worker->id,
            'status'       => self::STATUS_RUNNING,
            'jobsdone'     => (int) $worker->jobsdone + max(0, $jobsdone),
            'heartbeat'    => $now,
            'timemodified' => $now,
        ]);

        return true;
    }

    /**
     * Whether a worker's departure leaves work with nobody to do it.
     *
     * A rotation limit is a resource setting — how many sittings one browser
     * process plays before it is replaced. It says nothing about the
     * experiment, and letting it stop one is letting an implementation detail
     * decide a scientific run.
     *
     * @return array{needed: bool, claimable: int, live: int}
     */
    public static function replacement_needed(): array {
        $breakdown = attempt_scheduler::queue_breakdown();
        $live = self::summary()['live'];

        return [
            'needed'    => $breakdown['claimable'] > 0 && $live === 0,
            'claimable' => (int) $breakdown['claimable'],
            'live'      => (int) $live,
        ];
    }

    /**
     * Record a worker's own report: alive, and what it is doing.
     *
     * @param string $workerid The instance.
     * @param int $attemptid The attempt being played, or 0.
     * @param string $state working, idle or stopping.
     * @return bool Whether the registry knows this worker.
     */
    public static function report(string $workerid, int $attemptid = 0, string $state = 'working'): bool {
        global $DB;

        $worker = $DB->get_record('local_catquizlab_worker', ['workerid' => $workerid]);
        if (!$worker) {
            return false;
        }

        $now = time();
        $DB->update_record('local_catquizlab_worker', (object) [
            'id'             => $worker->id,
            // A worker reporting that it is stopping is not a worker running.
            // Recording it as RUNNING left a finished process showing as idle
            // and live, its slot apparently taken — so the next start could be
            // refused with all-slots-busy by a process that had already exited.
            'status'         => $state === 'stopping' || $state === 'stopped'
                ? self::STATUS_STOPPED
                : self::STATUS_RUNNING,
            'workerstate'    => $state,
            // Nothing is being played by a worker on its way out.
            'currentattempt' => in_array($state, ['stopping', 'stopped'], true)
                ? 0
                : max(0, $attemptid),
            'heartbeat'      => $now,
            'timemodified'   => $now,
        ]);

        return true;
    }

    /**
     * Whether somebody has asked this worker to stop.
     *
     * @param string $workerid The instance.
     * @return bool
     */
    public static function stop_requested(string $workerid): bool {
        global $DB;

        return (int) $DB->get_field(
            'local_catquizlab_worker',
            'stoprequested',
            ['workerid' => $workerid]
        ) > 0;
    }

    /**
     * Ask a worker to finish its attempt and exit.
     *
     * Not a kill: the worker is in the middle of playing an attempt through a
     * browser, and ending the process there leaves a claim with nobody to
     * finish it — the state the leases exist to prevent. It reads this at its
     * next heartbeat.
     *
     * @param string $workerid The instance.
     * @return bool Whether the request was recorded.
     */
    public static function request_stop(string $workerid): bool {
        global $DB;

        $worker = $DB->get_record('local_catquizlab_worker', ['workerid' => $workerid]);
        if (!$worker) {
            return false;
        }

        $DB->set_field('local_catquizlab_worker', 'stoprequested', time(), ['id' => $worker->id]);

        return true;
    }

    /**
     * Release a slot when a worker finishes on its own terms.
     *
     * @param string $workerid The instance.
     * @param string $error The reason, when it stopped because of one.
     * @return void
     */
    public static function release(string $workerid, string $error = ''): void {
        global $DB;

        $worker = $DB->get_record('local_catquizlab_worker', ['workerid' => $workerid]);
        if (!$worker) {
            return;
        }

        $now = time();
        $DB->update_record('local_catquizlab_worker', (object) [
            'id'           => $worker->id,
            'status'       => $error !== '' ? self::STATUS_CRASHED : self::STATUS_STOPPED,
            'lasterror'    => $error !== '' ? $error : null,
            'heartbeat'    => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Mark workers that stopped reporting, and free what they held.
     *
     * @return array{workers: int, attempts: int} What was recovered.
     */
    public static function reap(): array {
        global $DB;

        $now = time();
        $cutoff = $now - self::HEARTBEAT_TIMEOUT;

        [$insql, $params] = $DB->get_in_or_equal(
            [self::STATUS_STARTING, self::STATUS_RUNNING],
            SQL_PARAMS_NAMED,
            'st'
        );
        $params['cutoff'] = $cutoff;

        $dead = $DB->get_records_select(
            'local_catquizlab_worker',
            'status ' . $insql . ' AND heartbeat < :cutoff',
            $params,
            '',
            'id, workerid'
        );

        $attempts = 0;
        foreach ($dead as $worker) {
            $DB->update_record('local_catquizlab_worker', (object) [
                'id'           => $worker->id,
                'status'       => self::STATUS_CRASHED,
                'lasterror'    => 'worker stopped reporting',
                'timemodified' => $now,
            ]);

            // Whatever it was holding goes back into the queue. A claim whose
            // holder is gone is not in progress, and leaving it as running is
            // how a queue stops draining with nobody able to say why.
            $attempts += attempt_scheduler::release_lease((string) $worker->workerid);
        }

        return ['workers' => count($dead), 'attempts' => $attempts];
    }

    /**
     * The live workers, for the interface and for capacity decisions.
     *
     * @return array[] One entry per worker, most recently active first.
     */
    public static function live(): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(
            [self::STATUS_STARTING, self::STATUS_RUNNING],
            SQL_PARAMS_NAMED,
            'st'
        );
        $params['cutoff'] = time() - self::HEARTBEAT_TIMEOUT;

        return array_values($DB->get_records_select(
            'local_catquizlab_worker',
            'status ' . $insql . ' AND heartbeat >= :cutoff',
            $params,
            'heartbeat DESC'
        ));
    }

    /**
     * How many slots are free for the configured concurrency.
     *
     * @param int $concurrency The configured number of slots.
     * @return int[] The free slot numbers, lowest first.
     */
    public static function free_slots(int $concurrency): array {
        self::reap();

        $free = [];
        for ($slot = 1; $slot <= max(0, $concurrency); $slot++) {
            if (!self::slot_is_busy($slot)) {
                $free[] = $slot;
            }
        }

        return $free;
    }

    /**
     * A summary of the worker fleet, for the operations view.
     *
     * @return array{live: int, crashed: int, stopped: int, jobsdone: int, lasterror: string|null}
     */
    public static function summary(): array {
        global $DB;

        $rows = $DB->get_records('local_catquizlab_worker');
        $cutoff = time() - self::HEARTBEAT_TIMEOUT;

        // Starting is counted separately from live so a caller can tell a
        // worker coming up from one already playing — the difference between a
        // planned rotation and a stall.
        $summary = [
            'live'      => 0,
            'starting'  => 0,
            'crashed'   => 0,
            'stopped'   => 0,
            'jobsdone'  => 0,
            'lasterror' => null,
        ];
        foreach ($rows as $row) {
            $summary['jobsdone'] += (int) $row->jobsdone;
            $alive = in_array((int) $row->status, [self::STATUS_STARTING, self::STATUS_RUNNING], true)
                && (int) $row->heartbeat >= $cutoff;

            if ($alive) {
                $summary['live']++;

                if ((int) $row->status === self::STATUS_STARTING) {
                    $summary['starting']++;
                }
            } else if ((int) $row->status === self::STATUS_STOPPED) {
                $summary['stopped']++;
            } else {
                $summary['crashed']++;
            }

            if (!empty($row->lasterror) && $summary['lasterror'] === null) {
                $summary['lasterror'] = (string) $row->lasterror;
            }
        }

        return $summary;
    }
}
