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
 * Worker slots, leases and the recovery of orphaned claims.
 *
 * The reported failure these tests exist for: a site configured for one job at
 * a time ended up with two attempts claimed simultaneously, and nothing in the
 * installation could say that a worker had gone. Concurrency that only holds
 * while nobody interrupts anything is not a limit.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\worker_registry;

/**
 * Worker registry tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\worker_registry
 * @covers     \local_catquizlab\local\attempt_scheduler
 */
final class worker_registry_test extends \advanced_testcase {
    /**
     * Add an attempt in a given state.
     *
     * @param int $status One of attempt_scheduler's status constants.
     * @param string|null $owner The lease owner, when it is claimed.
     * @param int $leaseexpires When the lease lapses.
     * @return int The attempt id.
     */
    protected function add_attempt(
        int $status = attempt_scheduler::STATUS_QUEUED,
        ?string $owner = null,
        int $leaseexpires = 0
    ): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        return (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid'        => $run->id,
            'personid'     => 0,
            'status'       => $status,
            'tries'        => 0,
            'leaseowner'   => $owner,
            'leaseexpires' => $leaseexpires,
            'nextruntime'  => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * An attempt's current status.
     *
     * @param int $attemptid The attempt.
     * @return int
     */
    protected function attempt_status(int $attemptid): int {
        global $DB;

        return (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $attemptid]);
    }

    /**
     * A slot holds one worker, and the second is turned away.
     *
     * @return void
     */
    public function test_a_slot_holds_one_worker(): void {
        $this->resetAfterTest();

        $first = worker_registry::acquire_slot(1, 'catquizlab-exec-1');
        $second = worker_registry::acquire_slot(1, 'catquizlab-exec-1-duplicate');

        // This is the whole point: worker_concurrency = 1 has to mean one job
        // at a time installation-wide, not one job per process that happens to
        // be started.
        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertTrue(worker_registry::slot_is_busy(1));
        $this->assertFalse(worker_registry::slot_is_busy(2));
    }

    /**
     * A restarting worker reuses its own slot.
     *
     * @return void
     */
    public function test_the_same_worker_may_restart_into_its_slot(): void {
        $this->resetAfterTest();

        $first = worker_registry::acquire_slot(1, 'catquizlab-exec-1');
        $again = worker_registry::acquire_slot(1, 'catquizlab-exec-1');

        // Otherwise a restarted worker would need a new name every time, and
        // the registry would fill with rows nobody can interpret.
        $this->assertSame($first, $again);
    }

    /**
     * Free slots are counted, not assumed.
     *
     * @return void
     */
    public function test_free_slots_reflect_what_is_held(): void {
        $this->resetAfterTest();

        $this->assertSame([1, 2, 3], worker_registry::free_slots(3));

        worker_registry::acquire_slot(1, 'w1');
        worker_registry::acquire_slot(3, 'w3');

        $this->assertSame([2], worker_registry::free_slots(3));
    }

    /**
     * A worker that stops reporting is treated as gone.
     *
     * @return void
     */
    public function test_a_silent_worker_is_reaped(): void {
        global $DB;
        $this->resetAfterTest();

        $id = worker_registry::acquire_slot(1, 'ghost');
        $this->assertTrue(worker_registry::slot_is_busy(1));

        // Push its heartbeat past the timeout: the process is gone, which from
        // outside is indistinguishable from silence.
        $DB->set_field(
            'local_catquizlab_worker',
            'heartbeat',
            time() - worker_registry::HEARTBEAT_TIMEOUT - 60,
            ['id' => $id]
        );

        $reaped = worker_registry::reap();

        $this->assertSame(1, $reaped['workers']);
        $this->assertFalse(worker_registry::slot_is_busy(1));
        $this->assertSame(
            worker_registry::STATUS_CRASHED,
            (int) $DB->get_field('local_catquizlab_worker', 'status', ['id' => $id])
        );
    }

    /**
     * A busy worker that keeps reporting is left alone.
     *
     * @return void
     */
    public function test_a_reporting_worker_is_not_reaped(): void {
        $this->resetAfterTest();

        worker_registry::acquire_slot(1, 'busy');
        worker_registry::heartbeat('busy', 1);

        $reaped = worker_registry::reap();

        // Declaring a live worker dead is the expensive mistake: its attempt
        // would be handed to a second worker and played twice.
        $this->assertSame(0, $reaped['workers']);
        $this->assertTrue(worker_registry::slot_is_busy(1));
    }

    /**
     * What a dead worker was holding goes back into the queue.
     *
     * @return void
     */
    public function test_reaping_releases_the_attempts_a_worker_held(): void {
        global $DB;
        $this->resetAfterTest();

        $id = worker_registry::acquire_slot(1, 'ghost');
        $attemptid = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'ghost', time() + 300);

        $DB->set_field(
            'local_catquizlab_worker',
            'heartbeat',
            time() - worker_registry::HEARTBEAT_TIMEOUT - 60,
            ['id' => $id]
        );

        $reaped = worker_registry::reap();

        // A claim whose holder is gone is not in progress. Leaving it as
        // running is how a queue stops draining with nobody able to say why.
        $this->assertSame(1, $reaped['attempts']);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, $this->attempt_status($attemptid));
        $this->assertNull($DB->get_field('local_catquizlab_attempt', 'leaseowner', ['id' => $attemptid]));
    }

    /**
     * Releasing by owner touches only that owner's claims.
     *
     * @return void
     */
    public function test_release_lease_is_scoped_to_its_owner(): void {
        $this->resetAfterTest();

        $mine = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'worker-a', time() + 300);
        $theirs = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'worker-b', time() + 300);

        $released = attempt_scheduler::release_lease('worker-a');

        $this->assertSame(1, $released);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, $this->attempt_status($mine));
        $this->assertSame(attempt_scheduler::STATUS_RUNNING, $this->attempt_status($theirs));
    }

    /**
     * An expired lease is recovered; a live one is not.
     *
     * @return void
     */
    public function test_recovery_follows_the_lease_not_the_clock(): void {
        $this->resetAfterTest();

        $expired = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'worker-a', time() - 60);
        $live = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'worker-b', time() + 600);

        // The previous rule keyed on timemodified, which a worker refreshes
        // while it works — so a genuinely stuck attempt and a slow one looked
        // the same. Both of these were modified just now.
        $reclaimed = attempt_scheduler::reclaim_stale(null, 3600);

        $this->assertSame(1, $reclaimed);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, $this->attempt_status($expired));
        $this->assertSame(attempt_scheduler::STATUS_RUNNING, $this->attempt_status($live));
    }

    /**
     * Attempts without a lease still fall back to the timeout.
     *
     * @return void
     */
    public function test_leaseless_attempts_keep_the_timeout_fallback(): void {
        global $DB;
        $this->resetAfterTest();

        // An attempt claimed before leases existed. Dropping the fallback would
        // strand it for good.
        $attemptid = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, null, 0);
        $DB->set_field('local_catquizlab_attempt', 'timemodified', time() - 7200, ['id' => $attemptid]);

        $this->assertSame(1, attempt_scheduler::reclaim_stale(null, 3600));
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, $this->attempt_status($attemptid));
    }

    /**
     * The worker's reason for a failure is kept.
     *
     * @return void
     */
    public function test_the_failure_reason_is_recorded(): void {
        global $DB;
        $this->resetAfterTest();

        $attemptid = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'worker-a', time() + 300);
        attempt_scheduler::record_error($attemptid, "Login as catlab_r1 failed: no username field\nstack…");

        // Without this a retried attempt gives no clue why, and the person
        // looking has only a rising try count.
        $stored = (string) $DB->get_field('local_catquizlab_attempt', 'lasterror', ['id' => $attemptid]);
        $this->assertStringContainsString('Login as catlab_r1 failed', $stored);
        $this->assertLessThanOrEqual(1000, \core_text::strlen($stored));

        // An empty reason is not a reason and must not overwrite a real one.
        attempt_scheduler::record_error($attemptid, '   ');
        $this->assertSame(
            $stored,
            (string) $DB->get_field('local_catquizlab_attempt', 'lasterror', ['id' => $attemptid])
        );
    }

    /**
     * The fleet summary distinguishes live, stopped and crashed.
     *
     * @return void
     */
    public function test_summary_separates_live_from_gone(): void {
        global $DB;
        $this->resetAfterTest();

        worker_registry::acquire_slot(1, 'alive');
        worker_registry::heartbeat('alive', 3);

        worker_registry::acquire_slot(2, 'finished');
        worker_registry::release('finished');

        $id = worker_registry::acquire_slot(3, 'gone');
        $DB->set_field(
            'local_catquizlab_worker',
            'heartbeat',
            time() - worker_registry::HEARTBEAT_TIMEOUT - 60,
            ['id' => $id]
        );
        worker_registry::reap();

        $summary = worker_registry::summary();

        $this->assertSame(1, $summary['live']);
        $this->assertSame(1, $summary['stopped']);
        $this->assertSame(1, $summary['crashed']);
        $this->assertSame(3, $summary['jobsdone']);
    }

    /**
     * Releasing a slot frees it for the next worker.
     *
     * @return void
     */
    public function test_release_frees_the_slot(): void {
        $this->resetAfterTest();

        worker_registry::acquire_slot(1, 'first');
        worker_registry::release('first');

        $this->assertFalse(worker_registry::slot_is_busy(1));
        $this->assertNotNull(worker_registry::acquire_slot(1, 'second'));
    }

    /**
     * A crash is recorded with its reason rather than as a clean stop.
     *
     * @return void
     */
    public function test_a_crash_is_distinguishable_from_a_clean_stop(): void {
        global $DB;
        $this->resetAfterTest();

        worker_registry::acquire_slot(1, 'crashing');
        worker_registry::release('crashing', 'node exited with code 1');

        $row = $DB->get_record('local_catquizlab_worker', ['workerid' => 'crashing']);

        // One records a decision, the other a defect — and a list where both
        // look alike hides the defects among the decisions.
        $this->assertSame(worker_registry::STATUS_CRASHED, (int) $row->status);
        $this->assertStringContainsString('exited with code 1', (string) $row->lasterror);
    }
}
