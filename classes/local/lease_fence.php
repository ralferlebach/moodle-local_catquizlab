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
 * Whether a worker call comes from the execution that holds a sitting's lease now (#173, re-audit 27.11.2026).
 *
 * A claim hands a sitting to one execution: its worker (leaseowner), its identity (executionid: the id of the claim's
 * STARTED history row, sent to the worker with the claim) and the time its lease runs out (leaseexpires, renewed by its
 * heartbeats). Until 0.7.84 binding, reporting and renewing asked only for the worker's name and the status. A worker
 * started again under the same name — a slot of the launcher is named, not numbered — could not be told from the one
 * before it: the old execution's late report was applied to the new execution's sitting. And a report without a worker
 * name skipped the check. The try number cannot tell executions apart either: a reset clears it (CLARA run 103: 2, 3,
 * 2); the execution's identity is never reused.
 *
 * Every call that changes a sitting on a worker's behalf now carries the execution and is checked here, with the
 * sitting read under its lock: the sitting is running, held by this worker, in this execution, and the lease has not
 * run out. Anything else is fenced: recorded, never applied. A call without the identity is fenced too; there is no
 * path for it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lease_fence {
    /** @var string The caller holds the lease. */
    public const HOLDS = '';

    /** @var string The call names no worker or no execution. */
    public const NO_IDENTITY = 'no-identity';

    /** @var string The sitting is not being played (queued again, collected, failed, cancelled). */
    public const NOT_RUNNING = 'not-running';

    /** @var string Another worker holds the sitting. */
    public const OTHER_OWNER = 'other-owner';

    /** @var string The same worker name, another execution: an earlier or later claim of the sitting. */
    public const OTHER_EXECUTION = 'other-execution';

    /** @var string The lease has run out, whether or not the recovery has handed the sitting back yet. */
    public const EXPIRED = 'lease-expired';

    /** @var string[] Every reason a call can be fenced for. */
    public const REASONS = [self::NO_IDENTITY, self::NOT_RUNNING, self::OTHER_OWNER, self::OTHER_EXECUTION, self::EXPIRED];

    /**
     * Whether the caller holds the sitting's lease.
     *
     * @param \stdClass $sitting The sitting, read under its lock: status, leaseowner, executionid, leaseexpires.
     * @param string $workerid The calling worker.
     * @param int $execution The execution's identity it was handed the sitting with (the claim's "executionid").
     * @param int|null $now The clock.
     * @return string HOLDS, or why not.
     */
    public static function check(\stdClass $sitting, string $workerid, int $execution, ?int $now = null): string {
        $now = $now ?? time();
        if ($workerid === '' || $execution <= 0) {
            return self::NO_IDENTITY;
        }
        if ((int) $sitting->status !== attempt_scheduler::STATUS_RUNNING) {
            return self::NOT_RUNNING;
        }
        if ((string) ($sitting->leaseowner ?? '') !== $workerid) {
            return self::OTHER_OWNER;
        }
        if ((int) ($sitting->executionid ?? 0) !== $execution) {
            return self::OTHER_EXECUTION;
        }
        // A lease that has run out is lost, also before the recovery notices: a running sitting without a time is
        // one of a claim from before leases, and holds nothing either.
        $expires = (int) ($sitting->leaseexpires ?? 0);
        if ($expires <= 0 || $expires < $now) {
            return self::EXPIRED;
        }

        return self::HOLDS;
    }

    /**
     * Whether a sitting's lease has run out, whoever held it.
     *
     * @param \stdClass $sitting The sitting: status, leaseexpires.
     * @param int|null $now The clock.
     * @return bool
     */
    public static function lapsed(\stdClass $sitting, ?int $now = null): bool {
        $expires = (int) ($sitting->leaseexpires ?? 0);

        return (int) $sitting->status === attempt_scheduler::STATUS_RUNNING && $expires > 0 && $expires < ($now ?? time());
    }
}
