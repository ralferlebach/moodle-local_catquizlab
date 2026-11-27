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
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\engine_identity;

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
            'personid' => $person->id, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
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

        $bound = engine_identity::bind($attemptid, $usage, 'w1');
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
        $refused = engine_identity::bind($attemptid, $otherusage, 'w1');
        $this->assertFalse($refused['found']);
        $this->assertSame(engine_identity::IDENTITY_MISMATCH, $refused['reason']);
        $this->assertSame($engine, (int) $DB->get_field('local_catquizlab_attempt', 'engineattemptid', ['id' => $attemptid]));

        // Confirming the same usage again is not a new binding.
        $again = engine_identity::bind($attemptid, $usage, 'w1');
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

        $this->assertSame(engine_identity::NOT_LEASE_HOLDER, engine_identity::bind($attemptid, $usage, 'w2')['reason']);
        $this->assertSame(engine_identity::NOT_LEASE_HOLDER, engine_identity::bind($attemptid, $usage, '')['reason']);
        $this->assertSame(engine_identity::FOREIGN_USAGE, engine_identity::bind($attemptid, $strangerusage, 'w1')['reason']);
        $this->assertSame(engine_identity::FOREIGN_USAGE, engine_identity::bind($attemptid, $otheractivity, 'w1')['reason']);
        $this->assertSame(engine_identity::FOREIGN_USAGE, engine_identity::bind($attemptid, 999999, 'w1')['reason']);

        // A second attempt of the account on the activity beside the one shown: not bound.
        $this->engine($userid, 2, 3);
        $this->assertSame(engine_identity::AMBIGUOUS, engine_identity::bind($attemptid, $usage, 'w1')['reason']);
        $this->assertEmpty($DB->get_field('local_catquizlab_attempt', 'engineattemptid', ['id' => $attemptid]));

        // A stale execution (the sitting went back to the queue) may not bind either.
        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_QUEUED, ['id' => $attemptid]);
        $this->assertSame(engine_identity::NOT_LEASE_HOLDER, engine_identity::bind($attemptid, $usage, 'w1')['reason']);
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

        $first = sitting_bind::execute($attemptid, $usage, 'w1');
        $second = sitting_bind::execute($attemptid, $usage, 'w1');
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
            $this->assertSame('busy', sitting_bind::execute($attemptid, $usage, 'w1')['reason']);
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
        engine_identity::bind($attemptid, $usage, 'w1');
        $stranger = (int) $this->getDataGenerator()->create_user()->id;
        [$foreign] = $this->engine($stranger, 12, 12, 'complete');

        $answer = job_complete::execute($attemptid, 'finished', 1000, $foreign, '', '', 'w1');
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

        job_complete::execute($attemptid, 'finished', 1000, 0, '', '', 'w1');
        $this->assertDebuggingCalled();
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $row->status);
        $this->assertEmpty($row->engineattemptid);
        $this->assertStringContainsString('[identity:ambiguous-engine-attempt]', (string) $row->lasterror);
    }

    /**
     * A late report of a sitting already queued again is accepted only for the sitting's own complete attempt.
     *
     * @return void
     */
    public function test_a_stale_report_is_fenced_by_the_identity(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $userid] = $this->sitting();
        [$engine, $usage] = $this->engine($userid, 12, 12, 'complete');
        engine_identity::bind($attemptid, $usage, 'w1');
        // The lease ran out, the sitting went back to the queue.
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $attemptid,
            'status' => attempt_scheduler::STATUS_QUEUED, 'leaseowner' => null]);

        // Naming another attempt: kept as stale, not applied.
        $stale = job_complete::execute($attemptid, 'finished', 1000, $engine + 1000, '', '', 'w1');
        $this->assertFalse($stale['acknowledged']);
        $this->assertSame(
            attempt_scheduler::STATUS_QUEUED,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $attemptid])
        );

        // A failure report of the old execution: kept as stale too.
        $this->assertFalse(job_complete::execute($attemptid, 'failed', 1000, 0, 'x', '', 'w1')['acknowledged']);

        // Its own complete attempt: collected (here: waiting for the trace the stand-in lacks), once.
        $accepted = job_complete::execute($attemptid, 'finished', 1000, $engine, '', '', 'w1');
        $this->assertTrue($accepted['acknowledged']);
        $this->assertContains(
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $attemptid]),
            [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_TRACEPENDING]
        );
    }
}
