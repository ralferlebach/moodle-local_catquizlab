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

namespace local_catquizlab;

use local_catquizlab\external\job_complete;
use local_catquizlab\external\worker_heartbeat;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\circuit_breaker;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\run_log;

/**
 * Lease, retry and circuit breaker kept apart from a run's terminal failure (#133).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\circuit_breaker
 * @covers     \local_catquizlab\local\attempt_scheduler
 * @covers     \local_catquizlab\external\job_complete
 * @covers     \local_catquizlab\external\worker_heartbeat
 */
final class run_recovery_test extends \advanced_testcase {
    /**
     * A run with sittings: so many queued, so many failed for good with a cause.
     *
     * @param int $queued Queued sittings.
     * @param int $failed Failed sittings.
     * @param string $cause The failure message of each failed one.
     * @return int The run.
     */
    protected function run_with(int $queued, int $failed, string $cause = '[submit:http_5xx] submit answer 3'): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $runid = (int) $run->id;
        run_lifecycle::set_status($runid, registry::STATUS_RUNNING, 'test');
        for ($i = 0; $i < $queued + $failed; $i++) {
            $person = $generator->create_person(['runid' => $runid]);
            $isfailed = $i >= $queued;
            $id = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => $person->id,
                'status' => $isfailed ? attempt_scheduler::STATUS_FAILED : attempt_scheduler::STATUS_QUEUED,
                'tries' => $isfailed ? attempt_scheduler::MAX_TRIES : 0, 'lasterror' => $isfailed ? $cause : null,
                'timecreated' => time(), 'timemodified' => time() - ($isfailed ? 0 : 60),
            ]);
            if ($isfailed) {
                attempt_history::record($id, attempt_history::FAILED, [
                    'detail' => $cause, 'tryno' => attempt_scheduler::MAX_TRIES,
                ]);
            }
        }

        return $runid;
    }

    /**
     * Run 51: 250 planned, 210 queued, 40 failed, held as failed by an earlier version — migrated, resumed.
     *
     * @return void
     */
    public function test_run_51_is_paused_not_failed_and_resumes_without_losing_history(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with(210, 40);
        // As 0.7.37 held it: failed, by the breaker.
        run_lifecycle::update_run((object) ['id' => $runid, 'status' => registry::STATUS_FAILED,
            'lasterror' => '[submit:http_5xx] submit answer 3'], 'circuit_breaker_tripped');

        // The upgrade turns it into what it was: running, paused, with its cause.
        $this->assertSame(1, circuit_breaker::migrate_failed_held());
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        $this->assertSame(registry::STATUS_RUNNING, (int) $run->status, 'not terminal');
        $this->assertTrue(circuit_breaker::is_held($run));
        $this->assertSame('circuit_breaker:submit_http_5xx', run_lifecycle::pause_reason($runid));
        $this->assertSame(0, circuit_breaker::migrate_failed_held(), 'once');

        // The queue says what is held and why: 210 held, 40 failed for good, nothing claimable.
        $queue = attempt_scheduler::queue_breakdown((int) $run->experimentid);
        $this->assertSame([210, 40, 0], [$queue['paused'], $queue['failed'], $queue['claimable']]);
        $this->assertStringContainsString('HTTP 5xx', $queue['pausecauses'][0]['line']);
        $this->assertStringContainsString('210', $queue['pausecauses'][0]['line']);
        $this->assertStringContainsString(
            'Paused by the circuit breaker',
            \local_catquizlab\local\run_registry::status_label_of($run)
        );

        // Resumed: the 40 go back with a history row each; their failure history stays.
        $failedbefore = $DB->count_records('local_catquizlab_attemptlog', [
            'runid' => $runid, 'outcome' => attempt_history::FAILED,
        ]);
        $resumed = circuit_breaker::reset_and_continue($runid);
        $this->assertSame(['ok' => true, 'released' => 40], $resumed);
        $this->assertFalse(run_lifecycle::is_paused($runid));
        $this->assertSame(250, $DB->count_records(
            'local_catquizlab_attempt',
            ['runid' => $runid, 'status' => attempt_scheduler::STATUS_QUEUED]
        ));
        $this->assertSame($failedbefore, $DB->count_records(
            'local_catquizlab_attemptlog',
            ['runid' => $runid, 'outcome' => attempt_history::FAILED]
        ), 'failure history kept');
        $this->assertSame(40, $DB->count_records(
            'local_catquizlab_attemptlog',
            ['runid' => $runid, 'outcome' => attempt_history::REQUEUED]
        ));

        // Idempotent: a second press changes nothing and records nothing.
        $logs = $DB->count_records('local_catquizlab_runlog', ['runid' => $runid]);
        $this->assertSame(['ok' => true, 'released' => 0, 'reason' => 'not-held'], circuit_breaker::reset_and_continue($runid));
        $this->assertSame($logs, $DB->count_records('local_catquizlab_runlog', ['runid' => $runid]));

        // Every transition in the run's log, in order.
        $events = $DB->get_fieldset_select('local_catquizlab_runlog', 'event', 'runid = ?', [$runid], 'id ASC');
        $this->assertContains(run_log::RUN_AUTOPAUSED, $events);
        $this->assertContains(run_log::RUN_RESUMED, $events);
    }

    /**
     * The breaker pauses by cause; a run held for a capacity cause says so.
     *
     * @return void
     */
    public function test_the_breaker_pauses_by_cause(): void {
        $this->resetAfterTest();

        $runid = $this->run_with(5, circuit_breaker::THRESHOLD, '[submit:connect_timeout] submit answer 2');
        $verdict = circuit_breaker::check($runid);
        $this->assertTrue($verdict['tripped']);
        $this->assertSame(['submit_connect_timeout', 'capacity'], [$verdict['cause'], $verdict['category']]);
        $this->assertTrue(run_lifecycle::is_paused($runid));
        $this->assertFalse(circuit_breaker::check($runid)['tripped'], 'once');
        $this->assertSame('deterministic', circuit_breaker::category('engine_exception'));
    }

    /**
     * A heartbeat keeps the lease of the sitting its worker holds, and tells a worker that lost it.
     *
     * @return void
     */
    public function test_the_heartbeat_renews_the_lease_and_reports_a_lost_one(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with(1, 0);
        $attemptid = (int) $DB->get_field('local_catquizlab_attempt', 'id', ['runid' => $runid]);
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $attemptid,
            'status' => attempt_scheduler::STATUS_RUNNING, 'leaseowner' => 'w1', 'leaseexpires' => time() + 10]);

        $this->assertTrue(attempt_scheduler::renew_lease('w1', $attemptid));
        $expires = (int) $DB->get_field('local_catquizlab_attempt', 'leaseexpires', ['id' => $attemptid]);
        $this->assertGreaterThan(time() + 200, $expires);
        $this->assertFalse(attempt_scheduler::renew_lease('w2', $attemptid), 'not another worker\'s');

        $reply = worker_heartbeat::execute('w1', $attemptid, 'working');
        $this->assertFalse($reply['leaselost']);
        // The sitting went back to the queue: the worker learns it with its next heartbeat.
        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_QUEUED, ['id' => $attemptid]);
        $this->assertTrue(worker_heartbeat::execute('w1', $attemptid, 'working')['leaselost']);
        $this->assertFalse(worker_heartbeat::execute('w1', 0, 'waiting')['leaselost']);
    }

    /**
     * An expired lease is requeued with what the server knew, not blindly.
     *
     * @return void
     */
    public function test_an_expired_lease_says_what_the_server_knew(): void {
        global $DB;
        $this->resetAfterTest();

        $runid = $this->run_with(1, 0);
        $attemptid = (int) $DB->get_field('local_catquizlab_attempt', 'id', ['runid' => $runid]);
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $attemptid, 'tries' => 1,
            'status' => attempt_scheduler::STATUS_RUNNING, 'leaseowner' => 'w1', 'leaseexpires' => time() - 5]);

        $this->assertSame(1, attempt_scheduler::reclaim_stale($runid, 600));
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $attemptid]
        ));
        $requeued = $DB->get_record('local_catquizlab_attemptlog', ['attemptid' => $attemptid,
            'outcome' => attempt_history::REQUEUED]);
        $this->assertStringContainsString('lease_expired (worker w1; no engine attempt: ', $requeued->detail);
    }

    /**
     * A report from a worker that no longer holds the sitting is kept, not applied.
     *
     * @return void
     */
    public function test_a_late_report_does_not_overwrite_the_next_execution(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with(1, 0);
        $attemptid = (int) $DB->get_field('local_catquizlab_attempt', 'id', ['runid' => $runid]);
        // The second execution holds it now.
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $attemptid, 'tries' => 2,
            'status' => attempt_scheduler::STATUS_RUNNING, 'leaseowner' => 'w2', 'leaseexpires' => time() + 300]);

        $reply = job_complete::execute($attemptid, 'failed', 1000, 0, '[submit:http_5xx] late', '', 'w1');
        $this->assertFalse($reply['acknowledged']);
        $sitting = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame([attempt_scheduler::STATUS_RUNNING, 'w2', 2], [(int) $sitting->status, $sitting->leaseowner,
            (int) $sitting->tries], 'the second execution is untouched');
        $stale = $DB->get_record('local_catquizlab_attemptlog', ['attemptid' => $attemptid, 'outcome' => attempt_history::STALE]);
        $this->assertStringContainsString('late report from w1 ignored (failed)', $stale->detail);

        // The holder's own report is applied as before; a worker before 0.7.38 sends no id and is taken as before.
        $this->assertTrue(job_complete::execute($attemptid, 'failed', 1000, 0, 'real failure', '', 'w2')['acknowledged']);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $attemptid]
        ));
    }
}
