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
 * One writer at a time for the end of a sitting (#138, re-audit 2026-11-09).
 *
 * Four paths can end a sitting: the worker's completion report (job_complete), the same report sent again after a
 * timeout (#134), the lease recovery (reclaim_stale) and the look for a missing trace (retry_pending_traces); the
 * re-collection of a run (collect_run) reads it again. Each read the sitting, decided, and wrote — so two of them at
 * the same moment could both pass their check and both collect, record and count the sitting. Each now takes this
 * lock for the sitting, reads the sitting again under it, and decides on what it reads then. The collector itself
 * takes no lock; it is only called from inside one.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sitting_lock {
    /** @var string The lock factory's type. */
    public const FACTORY = 'local_catquizlab_sitting';

    /** @var int Seconds a worker's report waits for the sitting: longer than one collection takes. */
    public const REPORT_WAIT = 15;

    /** @var int Seconds the re-collection of a run waits for one sitting. */
    public const COLLECT_WAIT = 5;

    /** @var int|null An upper bound on every wait, for tests that hold a sitting on purpose; null in production. */
    public static ?int $maxwait = null;

    /**
     * The lock of one sitting, or null when another process holds it for longer than the wait.
     *
     * @param int $attemptid The sitting (local_catquizlab_attempt.id).
     * @param int $wait Seconds to wait; 0 to try once.
     * @return \core\lock\lock|null
     */
    public static function acquire(int $attemptid, int $wait) {
        if (self::$maxwait !== null) {
            $wait = min($wait, self::$maxwait);
        }
        $lock = \core\lock\lock_config::get_lock_factory(self::FACTORY)->get_lock('attempt' . $attemptid, $wait);

        return $lock ?: null;
    }

    /**
     * Run a step with the sitting's lock held, released afterwards whatever happens.
     *
     * @param int $attemptid The sitting.
     * @param int $wait Seconds to wait for it.
     * @param callable $step What to do; it reads the sitting again itself.
     * @return array{0: bool, 1: mixed} Whether the lock was had, and the step's result (null without the lock).
     */
    public static function run(int $attemptid, int $wait, callable $step): array {
        $lock = self::acquire($attemptid, $wait);
        if ($lock === null) {
            return [false, null];
        }
        try {
            return [true, $step()];
        } finally {
            $lock->release();
        }
    }
}
