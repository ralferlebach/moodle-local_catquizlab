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
use local_catquizlab\external\sitting_bind;
use local_catquizlab\external\sitting_state;
use local_catquizlab\external\worker_heartbeat;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\engine_identity;
use local_catquizlab\local\lease_fence;

/**
 * A sitting's engine attempt and question usage, bound and checked (#173).
 *
 * Until 0.7.83 every reader took the newest activity attempt of the person's account on the run's activity: the
 * worker's starting count, the reconciliation, the engine attempt of a finished report. These tests hold the
 * readers to the sitting's own attempt and refuse what is not it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\engine_identity
 * @covers     \local_catquizlab\external\sitting_bind
 * @covers     \local_catquizlab\external\sitting_state
 * @covers     \local_catquizlab\external\job_complete
 * @covers     \local_catquizlab\external\worker_heartbeat
 * @covers     \local_catquizlab\local\lease_fence
 */
final class engine_identity_test extends \advanced_testcase {
    /** @var int The activity instance of the fixture. */
    protected const INSTANCE = 4713;

    /**
     * A running sitting held by worker "w1", with an activity and an account; no engine attempt yet.
     *
     * @return array{0: int, 1: int, 2: int} The sitting, its account, its run.
     */
    protected function sitting(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('Needs mod_adaptivequiz.');
        }
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
            'instance' => self::INSTANCE, 'section' => 0, 'added' => time()]);
        $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $run->id]);
        $person = $generator->create_person(['runid' => $run->id, 'moodleuserid' => $user->id]);
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id,
            'personid' => $person->id, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1, 'executionid' => 1,
            'leaseowner' => 'w1', 'leaseexpires' => time() + 600, 'timecreated' => time(), 'timemodified' => time()]);

        return [$attemptid, (int) $user->id, (int) $run->id];
    }

    /**
     * An activity attempt with its usage and presented slots.
     *
     * @param int $userid Its account.
     * @param int $answered questionsattempted.
     * @param int $presented Slots in its usage.
     * @param string $state attemptstate.
     * @param int $instance The activity instance.
     * @return array{0: int, 1: int} The attempt and its usage.
     */
    protected function engine(
        int $userid,
        int $answered,
        int $presented,
        string $state = 'inprogress',
        int $instance = self::INSTANCE
    ): array {
        global $DB;

        $usage = (int) $DB->insert_record('question_usages', (object) ['contextid' => 1, 'component' => 'mod_adaptivequiz',
            'preferredbehaviour' => 'deferredfeedback']);
        $engine = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => $instance, 'userid' => $userid,
            'uniqueid' => $usage, 'attemptstate' => $state, 'attemptstopcriteria' => '', 'questionsattempted' => $answered,
            'timecreated' => time(), 'timemodified' => time()]);
        for ($slot = 1; $slot <= $presented; $slot++) {
            $DB->insert_record('question_attempts', (object) ['questionusageid' => $usage, 'slot' => $slot,
                'behaviour' => 'deferredfeedback', 'questionid' => $slot, 'variant' => 1, 'maxmark' => 1, 'minfraction' => 0,
                'maxfraction' => 1, 'flagged' => 0, 'timemodified' => time()]);
        }

        return [$engine, $usage];
    }

    /**
     * Not bound: the account's only attempt is the sitting's; a second makes it ambiguous, never "the newest".
     *
     * @return void
     */
    public function test_unbound_is_resolved_only_when_unambiguous(): void {
        $this->resetAfterTest();
        [$attemptid, $userid] = $this->sitting();

        $this->assertSame(engine_identity::NO_ENGINE_ATTEMPT, sitting_state::state($attemptid)['reason']);
        [$first, $usage] = $this->engine($userid, 264, 265);
        $state = sitting_state::state($attemptid);
        $this->assertTrue($state['found']);
        $this->assertFalse($state['bound']);
        $this->assertSame([$first, $usage, 264, 265], [$state['engineattemptid'], $state['qubaid'],
            $state['questionsattempted'], $state['slots']]);

        // A second attempt of the same account: 0.7.83 took it as the sitting's because it was newer.
        $this->engine($userid, 0, 1);
        $state = sitting_state::state($attemptid);
        $this->assertFalse($state['found']);
        $this->assertSame(engine_identity::AMBIGUOUS, $state['reason']);
        $this->assertSame(0, $state['questionsattempted'], 'no count stands in for an answer');
    }

    /**
     * Bound: the bound attempt is read whatever else exists; another usage is refused.
     *
     * @return void
     */
    public function test_bound_is_read_and_another_usage_refused(): void {
        global $DB;
        $this->resetAfterTest();
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 3, 4);

        $bound = engine_identity::bind($attemptid, $usage, 'w1', 1);
        $this->assertTrue($bound['found']);
        $this->assertTrue($bound['newly']);
        $this->assertSame($engine, (int) $DB->get_field('local_catquizlab_attempt', 'engineattemptid', ['id' => $attemptid]));

        // A newer attempt appears (a restart by somebody, a second browser): the binding holds.
        [, $otherusage] = $this->engine($userid, 0, 1);
        $state = sitting_state::state($attemptid);
        $this->assertTrue($state['bound']);
        $this->assertSame($engine, $state['engineattemptid']);
        $this->assertSame(engine_identity::QUBA_MISMATCH, sitting_state::state($attemptid, $otherusage)['reason']);
        $this->assertTrue(sitting_state::state($attemptid, $usage)['found']);

        // Binding the other usage is refused and changes nothing.
        $refused = engine_identity::bind($attemptid, $otherusage, 'w1', 1);
        $this->assertFalse($refused['found']);
        $this->assertSame(engine_identity::IDENTITY_MISMATCH, $refused['reason']);
        $this->assertSame($engine, (int) $DB->get_field('local_catquizlab_attempt', 'engineattemptid', ['id' => $attemptid]));

        // Confirming the same usage again is not a new binding.
        $again = engine_identity::bind($attemptid, $usage, 'w1', 1);
        $this->assertTrue($again['found']);
        $this->assertFalse($again['newly']);
    }

    /**
     * Only the lease holder binds; a usage of another account or activity is foreign; two attempts are ambiguous.
     *
     * @return void
     */
    public function test_binding_is_refused_without_the_lease_or_for_a_foreign_usage(): void {
        global $DB;
        $this->resetAfterTest();
        [$attemptid, $userid] = $this->sitting();
        [, $usage] = $this->engine($userid, 0, 1);
        $stranger = (int) $this->getDataGenerator()->create_user()->id;
        [, $strangerusage] = $this->engine($stranger, 0, 1);
        [, $otheractivity] = $this->engine($userid, 0, 1, 'inprogress', self::INSTANCE + 1);

        $this->assertSame(engine_identity::NOT_LEASE_HOLDER, engine_identity::bind($attemptid, $usage, 'w2', 1)['reason']);
        $this->assertSame(engine_identity::NOT_LEASE_HOLDER, engine_identity::bind($attemptid, $usage, '', 1)['reason']);
        $this->assertSame(engine_identity::FOREIGN_USAGE, engine_identity::bind($attemptid, $strangerusage, 'w1', 1)['reason']);
        $this->assertSame(engine_identity::FOREIGN_USAGE, engine_identity::bind($attemptid, $otheractivity, 'w1', 1)['reason']);
        $this->assertSame(engine_identity::FOREIGN_USAGE, engine_identity::bind($attemptid, 999999, 'w1', 1)['reason']);

        // A second attempt of the account on the activity beside the one shown: not bound.
        $this->engine($userid, 2, 3);
        $this->assertSame(engine_identity::AMBIGUOUS, engine_identity::bind($attemptid, $usage, 'w1', 1)['reason']);
        $this->assertEmpty($DB->get_field('local_catquizlab_attempt', 'engineattemptid', ['id' => $attemptid]));

        // A stale execution (the sitting went back to the queue) may not bind either.
        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_QUEUED, ['id' => $attemptid]);
        $this->assertSame(engine_identity::NOT_LEASE_HOLDER, engine_identity::bind($attemptid, $usage, 'w1', 1)['reason']);
    }

    /**
     * A bound attempt that is gone or of another account is an identity mismatch, not a fresh start.
     *
     * @return void
     */
    public function test_a_broken_binding_is_said(): void {
        global $DB;
        $this->resetAfterTest();
        [$attemptid, $userid] = $this->sitting();
        $stranger = (int) $this->getDataGenerator()->create_user()->id;
        [$foreign] = $this->engine($stranger, 5, 6);
        $DB->set_field('local_catquizlab_attempt', 'engineattemptid', $foreign, ['id' => $attemptid]);
        $this->assertSame(engine_identity::IDENTITY_MISMATCH, sitting_state::state($attemptid)['reason']);
        $DB->set_field('local_catquizlab_attempt', 'engineattemptid', 99999, ['id' => $attemptid]);
        $this->assertSame(engine_identity::IDENTITY_MISMATCH, sitting_state::state($attemptid)['reason']);
        // A count that is not a non-negative whole number is not one.
        [$engine] = $this->engine($userid, 0, 1);
        $DB->set_field('local_catquizlab_attempt', 'engineattemptid', $engine, ['id' => $attemptid]);
        $DB->set_field('adaptivequiz_attempt', 'questionsattempted', -1, ['id' => $engine]);
        $this->assertSame(engine_identity::INVALID_COUNT, sitting_state::state($attemptid)['reason']);
    }

    /**
     * The web service: binds once, records it once, and says busy rather than guess when the sitting is locked.
     *
     * @return void
     */
    public function test_the_web_service_binds_once_and_records_it(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 0, 1);

        $first = sitting_bind::execute($attemptid, $usage, 'w1', 1);
        $second = sitting_bind::execute($attemptid, $usage, 'w1', 1);
        $this->assertTrue($first['newly']);
        $this->assertFalse($second['newly']);
        $this->assertSame($engine, $second['engineattemptid']);
        $bound = array_filter(attempt_history::of_attempt($attemptid), static fn($r) => $r['outcome'] === attempt_history::BOUND);
        $this->assertCount(1, $bound);

        // Held by another process: busy, not an answer about the identity. A lock factory that keeps two holders
        // apart within one process (database advisory locks are re-entrant per connection, and a test has only one).
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        \local_catquizlab\local\sitting_lock::$maxwait = 0;
        $lock = \local_catquizlab\local\sitting_lock::acquire($attemptid, 0);
        try {
            $this->assertSame('busy', sitting_bind::execute($attemptid, $usage, 'w1', 1)['reason']);
        } finally {
            $lock->release();
            \local_catquizlab\local\sitting_lock::$maxwait = null;
        }
    }

    /**
     * A finished report naming another attempt than the bound one is not collected; it is held with the reason.
     *
     * @return void
     */
    public function test_a_finished_report_of_another_attempt_is_not_collected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 12, 12, 'complete');
        engine_identity::bind($attemptid, $usage, 'w1', 1);
        $stranger = (int) $this->getDataGenerator()->create_user()->id;
        [$foreign] = $this->engine($stranger, 12, 12, 'complete');

        $answer = job_complete::execute($attemptid, 'finished', 1000, $foreign, '', '', 'w1', '', 1);
        $this->assertDebuggingCalled();
        $this->assertTrue($answer['acknowledged']);
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $row->status, 'held, not collected');
        $this->assertSame($engine, (int) $row->engineattemptid, 'the binding is kept');
        $this->assertStringContainsString('[identity:identity-mismatch]', (string) $row->lasterror);
        $outcomes = array_column(attempt_history::of_attempt($attemptid), 'reasoncode', 'outcome');
        $this->assertSame('identity_mismatch', $outcomes[attempt_history::FAILED]);
        $this->assertSame('identity_mismatch', $outcomes[attempt_history::HELD]);
        $this->assertArrayNotHasKey(attempt_history::COLLECTED, $outcomes);
    }

    /**
     * Two unbound attempts and a finished report without an id: not the newest, but held as ambiguous.
     *
     * @return void
     */
    public function test_a_finished_report_with_two_unbound_attempts_is_held(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid] = $this->sitting();
        $this->engine($userid, 12, 12, 'complete');
        $this->engine($userid, 3, 4);

        job_complete::execute($attemptid, 'finished', 1000, 0, '', '', 'w1', '', 1);
        $this->assertDebuggingCalled();
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $row->status);
        $this->assertEmpty($row->engineattemptid);
        $this->assertStringContainsString('[identity:ambiguous-engine-attempt]', (string) $row->lasterror);
    }

    /**
     * The sitting's row as the fence reads it.
     *
     * @param int $attemptid The sitting.
     * @return \stdClass
     */
    protected function row(int $attemptid): \stdClass {
        global $DB;

        return $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
    }

    /**
     * The history's outcomes of a sitting, oldest first.
     *
     * @param int $attemptid The sitting.
     * @return string[]
     */
    protected function outcomes(int $attemptid): array {
        return array_column(attempt_history::of_attempt($attemptid), 'outcome');
    }

    /**
     * The fence itself: worker, execution, status and lease, each refused on its own (#173, re-audit 27.11.2026).
     *
     * @return void
     */
    public function test_the_fence_names_each_reason(): void {
        $now = 1000000;
        $holder = (object) ['status' => attempt_scheduler::STATUS_RUNNING, 'leaseowner' => 'w1', 'executionid' => 2,
            'tries' => 1, 'leaseexpires' => $now + 10];
        $this->assertSame(lease_fence::HOLDS, lease_fence::check($holder, 'w1', 2, $now));
        $this->assertSame(lease_fence::HOLDS, lease_fence::check(
            (object) (['leaseexpires' => $now] + (array) $holder),
            'w1',
            2,
            $now
        ), 'the last second of a lease is still the lease');
        $this->assertSame(lease_fence::NO_IDENTITY, lease_fence::check($holder, '', 2, $now));
        $this->assertSame(lease_fence::NO_IDENTITY, lease_fence::check($holder, 'w1', 0, $now));
        $this->assertSame(lease_fence::OTHER_OWNER, lease_fence::check($holder, 'w2', 2, $now));
        $this->assertSame(lease_fence::OTHER_EXECUTION, lease_fence::check($holder, 'w1', 1, $now));
        $this->assertSame(lease_fence::OTHER_EXECUTION, lease_fence::check($holder, 'w1', 3, $now));
        $this->assertSame(lease_fence::EXPIRED, lease_fence::check($holder, 'w1', 2, $now + 11));
        $this->assertSame(lease_fence::EXPIRED, lease_fence::check(
            (object) (['leaseexpires' => 0] + (array) $holder),
            'w1',
            2,
            $now
        ), 'a running sitting without a lease time holds nothing');
        foreach (
            [attempt_scheduler::STATUS_QUEUED, attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_FAILED,
                attempt_scheduler::STATUS_TRACEPENDING] as $status
        ) {
            $this->assertSame(lease_fence::NOT_RUNNING, lease_fence::check(
                (object) (['status' => $status] + (array) $holder),
                'w1',
                2,
                $now
            ));
        }
        $this->assertTrue(lease_fence::lapsed($holder, $now + 11));
        $this->assertFalse(lease_fence::lapsed($holder, $now));
    }

    /**
     * Same worker name, claim 1 run out, claim 2 holding: execution 1's bind, heartbeat and report change nothing.
     *
     * @return void
     */
    public function test_an_old_execution_of_the_same_worker_is_fenced(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 40, 41);
        $this->assertTrue(engine_identity::bind($attemptid, $usage, 'w1', 1)['found']);
        // Claim 1's lease ran out, the recovery handed the sitting back, and worker "w1" — started again under the
        // same name — claimed it as execution 2.
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $attemptid, 'tries' => 2, 'executionid' => 2,
            'status' => attempt_scheduler::STATUS_RUNNING, 'leaseowner' => 'w1', 'leaseexpires' => time() + 300]);
        $before = $this->row($attemptid);
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $engine]);

        // Execution 1, late: its bind is fenced and binds nothing.
        [, $other] = $this->engine($userid, 0, 1);
        $bind = sitting_bind::execute($attemptid, $other, 'w1', 1);
        $this->assertSame(engine_identity::NOT_LEASE_HOLDER, $bind['reason']);
        $this->assertSame(lease_fence::OTHER_EXECUTION, $bind['fence']);
        // Its heartbeat renews nothing and says the lease is lost.
        $leased = time() + 30;
        $DB->set_field('local_catquizlab_attempt', 'leaseexpires', $leased, ['id' => $attemptid]);
        $beat = worker_heartbeat::execute('w1', $attemptid, 'working', '', 1);
        $this->assertTrue($beat['leaselost']);
        $this->assertSame('taken', $beat['leasefate']);
        $this->assertSame($leased, (int) $this->row($attemptid)->leaseexpires, 'execution 2\'s lease is untouched');
        $DB->set_field('local_catquizlab_attempt', 'leaseexpires', $before->leaseexpires, ['id' => $attemptid]);
        // Its finished report: kept, not applied — no status, no trace, no binding changed.
        $report = job_complete::execute($attemptid, 'finished', 9000, $engine, '', '', 'w1', '', 1);
        $this->assertFalse($report['acknowledged']);
        $this->assertSame(lease_fence::OTHER_EXECUTION, $report['fence']);
        $this->assertSame('', $report['recovered'], 'execution 2 holds a live lease: nothing for the server to do');
        $after = $this->row($attemptid);
        foreach (
            ['status', 'leaseowner', 'leaseexpires', 'tries', 'executionid', 'engineattemptid', 'tracejson',
                'runtimems'] as $field
        ) {
            $this->assertEquals($before->$field, $after->$field, $field);
        }
        // And its failure report the same.
        $this->assertFalse(job_complete::execute($attemptid, 'failed', 9000, 0, 'boom', '', 'w1', '', 1)['acknowledged']);
        $this->assertSame(attempt_scheduler::STATUS_RUNNING, (int) $this->row($attemptid)->status);
        $stale = array_values(array_filter(
            attempt_history::of_attempt($attemptid),
            static fn($r) => $r['outcome'] === attempt_history::STALE
        ));
        $this->assertCount(3, $stale, 'bind, finished and failed report kept as stale');
        $this->assertNotContains(attempt_history::COLLECTED, $this->outcomes($attemptid));
        $this->assertNotContains(attempt_history::FAILED, $this->outcomes($attemptid));

        // Execution 2, the holder, renews and reports.
        $this->assertFalse(worker_heartbeat::execute('w1', $attemptid, 'working', '', 2)['leaselost']);
        $this->assertTrue(job_complete::execute($attemptid, 'finished', 9000, $engine, '', '', 'w1', '', 2)['acknowledged']);
        $this->assertContains(
            (int) $this->row($attemptid)->status,
            [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_TRACEPENDING]
        );
    }

    /**
     * A lease run out and not yet handed back: the holder's own calls are fenced, and its report has the server do what
     * the recovery would — collect a complete attempt, hand an unfinished one back.
     *
     * @return void
     */
    public function test_an_expired_lease_without_the_recovery_is_fenced(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 12, 12);
        engine_identity::bind($attemptid, $usage, 'w1', 1);
        // One instant, held: a second time() may fall into the next second (gate 503/mariadb, 0.7.85).
        $expired = time() - 5;
        $DB->set_field('local_catquizlab_attempt', 'leaseexpires', $expired, ['id' => $attemptid]);

        // No renewal of a lease that ran out, though nobody has handed the sitting back yet.
        $beat = worker_heartbeat::execute('w1', $attemptid, 'working', '', 1);
        $this->assertTrue($beat['leaselost']);
        $this->assertSame('expired', $beat['leasefate']);
        $this->assertSame($expired, (int) $this->row($attemptid)->leaseexpires);
        $this->assertSame(lease_fence::EXPIRED, sitting_bind::execute($attemptid, $usage, 'w1', 1)['fence']);

        // Unfinished: the report's "finished" is not taken; the server hands it back for a retry, as the recovery would.
        $report = job_complete::execute($attemptid, 'finished', 9000, $engine, '', '', 'w1', '', 1);
        $this->assertFalse($report['acknowledged']);
        $this->assertSame(lease_fence::EXPIRED, $report['fence']);
        $this->assertSame('reclaimed', $report['recovered']);
        $row = $this->row($attemptid);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, (int) $row->status);
        $this->assertEmpty($row->leaseowner);
        $this->assertNull($row->tracejson);

        // Claimed again, the lease runs out again — and this time the engine attempt is complete: collected from the
        // server, not played again.
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $attemptid, 'tries' => 2, 'executionid' => 2,
            'status' => attempt_scheduler::STATUS_RUNNING, 'leaseowner' => 'w1', 'leaseexpires' => time() - 1]);
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $engine]);
        $report = job_complete::execute($attemptid, 'failed', 9000, 0, 'lost', '', 'w1', '', 2);
        $this->assertSame('reclaimed', $report['recovered']);
        $this->assertContains(
            (int) $this->row($attemptid)->status,
            [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_TRACEPENDING]
        );
        $this->assertSame($engine, (int) $this->row($attemptid)->engineattemptid);
    }

    /**
     * A call without the identity (no worker, no execution) is fenced, also when the sitting is otherwise in order.
     *
     * @return void
     */
    public function test_a_call_without_the_identity_is_fenced(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 12, 12, 'complete');
        $this->assertSame(lease_fence::NO_IDENTITY, sitting_bind::execute($attemptid, $usage, 'w1')['fence']);
        $this->assertSame(lease_fence::NO_IDENTITY, sitting_bind::execute($attemptid, $usage, '', 1)['fence']);
        $this->assertTrue(worker_heartbeat::execute('w1', $attemptid, 'working')['leaselost'], 'no execution, no renewal');

        foreach ([['', 1], ['w1', 0], ['', 0]] as [$worker, $execution]) {
            $report = job_complete::execute($attemptid, 'finished', 1000, $engine, '', '', $worker, '', $execution);
            $this->assertFalse($report['acknowledged']);
            $this->assertSame(lease_fence::NO_IDENTITY, $report['fence']);
            $this->assertSame('', $report['recovered']);
            $this->assertSame(attempt_scheduler::STATUS_RUNNING, (int) $this->row($attemptid)->status);
        }
        $this->assertEmpty($this->row($attemptid)->engineattemptid, 'nothing bound');
    }

    /**
     * The same complete report twice: collected once; the second is fenced (the sitting is no longer running) and the
     * server finds nothing to do.
     *
     * @return void
     */
    public function test_a_repeated_complete_report_collects_once(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 12, 12, 'complete');
        engine_identity::bind($attemptid, $usage, 'w1', 1);

        $first = job_complete::execute($attemptid, 'finished', 1000, $engine, '', '', 'w1', '', 1);
        $this->assertTrue($first['acknowledged']);
        $status = (int) $this->row($attemptid)->status;
        $second = job_complete::execute($attemptid, 'finished', 1000, $engine, '', '', 'w1', '', 1);
        $this->assertFalse($second['acknowledged']);
        $this->assertSame(lease_fence::NOT_RUNNING, $second['fence']);
        $this->assertSame('', $second['recovered']);
        $this->assertSame($status, (int) $this->row($attemptid)->status);
        $ends = array_filter($this->outcomes($attemptid), static fn($o) => in_array($o, [attempt_history::COLLECTED,
            attempt_history::TRACE_PENDING], true));
        $this->assertCount(1, $ends, 'one end, not two');
    }

    /**
     * Back in the queue, its own attempt complete (the old execution's last answer was stored after the recovery had
     * looked): a late report — whatever it names — has the server collect from its own state, once.
     *
     * @return void
     */
    public function test_a_late_report_after_the_requeue_is_recovered_from_the_server_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 12, 12);
        engine_identity::bind($attemptid, $usage, 'w1', 1);
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $attemptid,
            'status' => attempt_scheduler::STATUS_QUEUED, 'leaseowner' => null, 'leaseexpires' => 0]);

        // Still in progress on the server: the late report changes nothing, the sitting waits for its next claim.
        $stale = job_complete::execute($attemptid, 'finished', 1000, $engine, '', '', 'w1', '', 1);
        $this->assertSame(lease_fence::NOT_RUNNING, $stale['fence']);
        $this->assertSame('', $stale['recovered']);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, (int) $this->row($attemptid)->status);

        // Complete on the server now. A report naming another attempt does not choose it: the server collects the
        // sitting's own.
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $engine]);
        $late = job_complete::execute($attemptid, 'finished', 1000, $engine + 1000, '', '', 'w1', '', 1);
        $this->assertFalse($late['acknowledged']);
        $this->assertContains($late['recovered'], ['collected', 'tracepending']);
        $row = $this->row($attemptid);
        $this->assertSame($engine, (int) $row->engineattemptid);
        $this->assertNull($row->runtimems, 'nothing of the fenced report is taken over');
        $again = job_complete::execute($attemptid, 'finished', 1000, $engine, '', '', 'w1', '', 1);
        $this->assertSame('', $again['recovered'], 'idempotent');
        $ends = array_filter($this->outcomes($attemptid), static fn($o) => in_array($o, [attempt_history::COLLECTED,
            attempt_history::TRACE_PENDING], true));
        $this->assertCount(1, $ends);
        // The server's own reading is kept with the end (#176 AK02).
        $end = array_values(array_filter(
            attempt_history::of_attempt($attemptid),
            static fn($r) => in_array($r['outcome'], [attempt_history::COLLECTED, attempt_history::TRACE_PENDING], true)
        ))[0];
        $server = $end['diagnosis']['server'];
        $this->assertSame(['fenced-report-recovery', $engine, $usage, 'complete', 12, 12], [$server['source'],
            $server['engineattemptid'], $server['qubaid'], $server['state'], $server['questionsattempted'],
            $server['slots']]);
    }

    /**
     * Fail-closed collection: a sitting whose owner or activity cannot be established, or whose attempt is not complete
     * on the server, is not collected — it is held with the reason.
     *
     * @return void
     */
    public function test_collection_is_fail_closed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // The finished report of an attempt the engine has not completed.
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 12, 12);
        engine_identity::bind($attemptid, $usage, 'w1', 1);
        job_complete::execute($attemptid, 'finished', 1000, $engine, '', '', 'w1', '', 1);
        $this->assertDebuggingCalled();
        $row = $this->row($attemptid);
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $row->status);
        $this->assertStringContainsString('[identity:' . engine_identity::NOT_COMPLETE . ']', (string) $row->lasterror);
        $this->assertNull($row->tracejson);

        // A sitting without an account: 0.7.84 collected the reported attempt unchecked.
        [$orphan, $orphanuser] = $this->sitting();
        [$orphanengine] = $this->engine($orphanuser, 12, 12, 'complete');
        $DB->set_field(
            'local_catquizlab_person',
            'moodleuserid',
            0,
            ['id' => (int) $DB->get_field('local_catquizlab_attempt', 'personid', ['id' => $orphan])]
        );
        job_complete::execute($orphan, 'finished', 1000, $orphanengine, '', '', 'w1', '', 1);
        $this->assertDebuggingCalled();
        $row = $this->row($orphan);
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $row->status);
        $this->assertStringContainsString('[identity:' . engine_identity::SITTING_INCOMPLETE . ']', (string) $row->lasterror);
        $this->assertEmpty($row->engineattemptid);

        // A run without its activity.
        [$noactivity, $user3, $run3] = $this->sitting();
        [$engine3] = $this->engine($user3, 12, 12, 'complete');
        $DB->set_field('local_catquizlab_run', 'testcmid', 0, ['id' => $run3]);
        job_complete::execute($noactivity, 'finished', 1000, $engine3, '', '', 'w1', '', 1);
        $this->assertDebuggingCalled();
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $this->row($noactivity)->status);
        $this->assertEmpty($this->row($noactivity)->engineattemptid);
    }

    /**
     * The worker's calls need the worker capability; without it nothing is bound, renewed or reported.
     *
     * @return void
     */
    public function test_the_calls_need_the_worker_capability(): void {
        $this->resetAfterTest();
        [$attemptid, $userid] = $this->sitting();
        [, $usage] = $this->engine($userid, 0, 1);
        $this->setUser($this->getDataGenerator()->create_user());
        $calls = [
            static fn() => sitting_bind::execute($attemptid, $usage, 'w1', 1),
            static fn() => job_complete::execute($attemptid, 'failed', 0, 0, 'x', '', 'w1', '', 1),
            static fn() => worker_heartbeat::execute('w1', $attemptid, 'working', '', 1),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('called without the capability');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
        $this->assertEmpty($this->row($attemptid)->engineattemptid);
        $this->assertSame(attempt_scheduler::STATUS_RUNNING, (int) $this->row($attemptid)->status);
    }

    /**
     * A report while another process holds the sitting's lock: busy, nothing applied.
     *
     * @return void
     */
    public function test_a_report_waits_for_the_sittings_lock(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 12, 12, 'complete');
        engine_identity::bind($attemptid, $usage, 'w1', 1);
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        \local_catquizlab\local\sitting_lock::$maxwait = 0;
        $lock = \local_catquizlab\local\sitting_lock::acquire($attemptid, 0);
        try {
            $busy = job_complete::execute($attemptid, 'finished', 1000, $engine, '', '', 'w1', '', 1);
            $this->assertFalse($busy['acknowledged']);
            $this->assertSame(get_string('job:busy', 'local_catquizlab'), $busy['message']);
            $this->assertSame(attempt_scheduler::STATUS_RUNNING, (int) $this->row($attemptid)->status);
        } finally {
            $lock->release();
            \local_catquizlab\local\sitting_lock::$maxwait = null;
        }
        $this->assertTrue(job_complete::execute($attemptid, 'finished', 1000, $engine, '', '', 'w1', '', 1)['acknowledged']);
    }

    /**
     * A worker of contract 1 — no execution on its calls — is handed no sitting.
     *
     * @return void
     */
    public function test_a_worker_of_the_old_contract_gets_no_sitting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $answer = \local_catquizlab\external\job_claim::execute('w-old', 1);
        $this->assertFalse($answer['hasjob']);
        $this->assertSame(get_string('job:outdatedworker', 'local_catquizlab', 2), $answer['message']);
        $this->assertSame(2, \local_catquizlab\external\job_claim::WORKER_CONTRACT);
    }

    /**
     * A reset retry counter repeats try numbers, never the execution's identity (#173 AK07): the old execution's
     * calls stay fenced although its try number is the new one's.
     *
     * @return void
     */
    public function test_a_reset_retry_counter_does_not_reuse_the_execution_identity(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid, $runid] = $this->sitting();
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $attemptid, 'status' => attempt_scheduler::STATUS_QUEUED,
            'tries' => 0, 'executionid' => 0, 'leaseowner' => null, 'leaseexpires' => 0, 'nextruntime' => 0]);
        $DB->set_field('local_catquizlab_run', 'status', \local_catquizlab\local\registry::STATUS_READY, ['id' => $runid]);
        [, $usage] = $this->engine($userid, 0, 1);

        $first = \local_catquizlab\external\job_claim::execute('w1');
        $this->assertSame($attemptid, (int) $first['attemptid']);
        $this->assertSame(1, (int) $first['execution']);
        $this->assertTrue(sitting_bind::execute($attemptid, $usage, 'w1', (int) $first['executionid'])['found']);
        // A broken invariant: held; a person puts it back, which clears the try counter.
        job_complete::execute(
            $attemptid,
            'failed',
            10,
            0,
            '[guard:budget] x',
            '',
            'w1',
            'budget_violation',
            (int) $first['executionid']
        );
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $this->row($attemptid)->status);
        $this->assertSame(1, \local_catquizlab\local\run_lifecycle::requeue_failed($runid));
        $this->assertSame(0, (int) $this->row($attemptid)->tries, 'the counter was reset');

        $second = \local_catquizlab\external\job_claim::execute('w1');
        $this->assertSame($attemptid, (int) $second['attemptid']);
        $this->assertSame(1, (int) $second['execution'], 'the same try number again');
        $this->assertNotSame((int) $first['executionid'], (int) $second['executionid'], 'never the same identity');
        $this->assertGreaterThan((int) $first['executionid'], (int) $second['executionid']);

        // Execution one, late, with the same worker name and the same try number: fenced.
        $this->assertSame(lease_fence::OTHER_EXECUTION, sitting_bind::execute(
            $attemptid,
            $usage,
            'w1',
            (int) $first['executionid']
        )['fence']);
        $late = job_complete::execute($attemptid, 'failed', 10, 0, 'late', '', 'w1', '', (int) $first['executionid']);
        $this->assertSame(lease_fence::OTHER_EXECUTION, $late['fence']);
        $this->assertSame(attempt_scheduler::STATUS_RUNNING, (int) $this->row($attemptid)->status);
        $this->assertSame((int) $second['executionid'], (int) $this->row($attemptid)->executionid);

        // The history tells the two executions apart by their identity, though their try numbers are the same.
        $started = array_values($DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $attemptid,
            'outcome' => attempt_history::STARTED], 'id ASC', 'id, tryno, executionid'));
        $this->assertSame([1, 1], array_map(static fn($r) => (int) $r->tryno, $started));
        $this->assertSame(
            [(int) $first['executionid'], (int) $second['executionid']],
            array_map(static fn($r) => (int) $r->executionid, $started)
        );
        $this->assertSame(
            [(int) $first['executionid'], (int) $second['executionid']],
            array_map(static fn($r) => (int) $r->id, $started),
            'the identity is the STARTED row'
        );
    }
}
