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
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\circuit_breaker;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\sitting_integrity;
use local_catquizlab\external\job_claim;

/**
 * A sitting whose execution vanished while the engine kept its attempt (#133, #134).
 *
 * A worker can die after the engine stored the last answer and before it reported. The lease runs out; the
 * sitting must not be handed blindly to the next execution. The server asks the engine first: a complete engine
 * attempt is collected, not played again; one still in progress goes back to the queue with what the engine
 * knew. A late report of the vanished execution is ignored — unless it brings the finished result of a sitting
 * that is waiting in the queue.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\attempt_scheduler
 * @covers     \local_catquizlab\external\job_complete
 */
final class orphaned_engine_attempt_test extends \advanced_testcase {
    /**
     * A sitting with an activity and a person; its engine attempt in $state, or none.
     *
     * @param string|null $state The engine attempt's attemptstate, or null for none.
     * @param int $leaseexpires When the sitting's lease runs out.
     * @return array{0: int, 1: int} The sitting and its engine attempt (0 for none).
     */
    protected function sitting(?string $state, int $leaseexpires): array {
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
            'instance' => 4712, 'section' => 0, 'added' => time()]);
        $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $run->id]);
        $person = $generator->create_person(['runid' => $run->id, 'moodleuserid' => $user->id]);
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id,
            'personid' => $person->id, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
            'leaseowner' => 'worker-gone', 'leaseexpires' => $leaseexpires,
            'timecreated' => time() - 900, 'timemodified' => time() - 900]);
        $engine = 0;
        if ($state !== null) {
            $engine = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => 4712, 'userid' => $user->id,
                'uniqueid' => 0, 'attemptstate' => $state, 'attemptstopcriteria' => '', 'questionsattempted' => 12,
                'timecreated' => time() - 900, 'timemodified' => time() - 60]);
        }

        return [$attemptid, $engine];
    }

    /**
     * The history events of a sitting, in order.
     *
     * @param int $attemptid The sitting.
     * @return string[]
     */
    protected function events(int $attemptid): array {
        return array_column(attempt_history::of_attempt($attemptid), 'outcome');
    }

    /**
     * Lease expired, the engine attempt complete: collected, not played again.
     *
     * @return void
     */
    public function test_a_complete_engine_attempt_is_collected_not_replayed(): void {
        global $DB;
        $this->resetAfterTest();
        [$attemptid, $engine] = $this->sitting('complete', time() - 30);

        $this->assertSame(1, attempt_scheduler::reclaim_stale(null, 600));

        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertNotEquals(attempt_scheduler::STATUS_QUEUED, (int) $row->status, 'not handed to a new execution');
        $this->assertSame($engine, (int) $row->engineattemptid);
        $this->assertSame(1, (int) $row->tries, 'no further try spent');
        $this->assertSame(0, (int) $row->leaseexpires);
        // The stand-in engine attempt carries no data: not collected without a trace (#138), waiting for it.
        $this->assertSame(attempt_scheduler::STATUS_TRACEPENDING, (int) $row->status);
        $this->assertContains(attempt_history::TRACE_PENDING, $this->events($attemptid));
        $this->assertNotContains(attempt_history::COLLECTED, $this->events($attemptid));
        $detail = implode(' ', array_column(attempt_history::of_attempt($attemptid), 'detail'));
        $this->assertStringContainsString('not collected, not replayed', $detail);
    }

    /**
     * Lease expired, the engine attempt still in progress: back to the queue, saying what the engine knew.
     *
     * @return void
     */
    public function test_an_engine_attempt_in_progress_is_requeued_with_what_was_known(): void {
        global $DB;
        $this->resetAfterTest();
        [$attemptid] = $this->sitting('inprogress', time() - 30);

        $this->assertSame(1, attempt_scheduler::reclaim_stale(null, 600));

        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, (int) $row->status);
        $this->assertEmpty($row->leaseowner);
        $detail = implode(' ', array_column(attempt_history::of_attempt($attemptid), 'detail'));
        $this->assertStringContainsString('lease_expired', $detail);
        $this->assertStringContainsString('worker-gone', $detail);
        $this->assertStringContainsString('inprogress, 12 answered', $detail);
    }

    /**
     * A lease that has not run out is left alone, whatever the engine says.
     *
     * @return void
     */
    public function test_a_live_lease_is_not_touched(): void {
        global $DB;
        $this->resetAfterTest();
        [$attemptid] = $this->sitting('complete', time() + 300);

        $this->assertSame(0, attempt_scheduler::reclaim_stale(null, 600));
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame([attempt_scheduler::STATUS_RUNNING, 'worker-gone'], [(int) $row->status, (string) $row->leaseowner]);
    }

    /**
     * A late "finished" for a sitting back in the queue, with a complete engine attempt, is accepted once.
     * A late report of any other kind is recorded and ignored.
     *
     * @return void
     */
    public function test_a_late_report_is_accepted_only_for_a_finished_result(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Requeued after its lease ran out; the engine attempt was complete when the late report came.
        [$attemptid, $engine] = $this->sitting('inprogress', time() - 30);
        attempt_scheduler::reclaim_stale(null, 600);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $attemptid]
        ));

        // An error report of the vanished execution: not applied.
        $answer = job_complete::execute($attemptid, 'failed', 1000, 0, 'browser crashed', '{}', 'worker-gone');
        $this->assertFalse($answer['acknowledged']);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $attemptid]
        ));
        $this->assertContains(attempt_history::STALE, $this->events($attemptid));

        // The engine finished it after all: the late "finished" is a result, taken instead of a second sitting.
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $engine]);
        $answer = job_complete::execute($attemptid, 'finished', 1000, 0, '', '{}', 'worker-gone');
        $this->assertTrue($answer['acknowledged']);
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertNotEquals(attempt_scheduler::STATUS_QUEUED, (int) $row->status, 'not played a second time');
        $this->assertSame($engine, (int) $row->engineattemptid);
    }

    /**
     * Questions presented in an engine attempt, each answered with one step.
     *
     * @param int $engine The adaptivequiz attempt.
     * @param int[] $questions Question ids, in slot order.
     * @return int[] The question attempts, by slot.
     */
    protected function present(int $engine, array $questions): array {
        global $DB;

        $usage = (int) $DB->get_field('adaptivequiz_attempt', 'uniqueid', ['id' => $engine]);
        if ($usage === 0) {
            $usage = (int) $DB->insert_record('question_usages', (object) ['contextid' => 1,
                'component' => 'mod_adaptivequiz', 'preferredbehaviour' => 'deferredfeedback']);
            $DB->set_field('adaptivequiz_attempt', 'uniqueid', $usage, ['id' => $engine]);
        }
        $slot = (int) $DB->count_records('question_attempts', ['questionusageid' => $usage]);
        $ids = [];
        foreach ($questions as $questionid) {
            $slot++;
            $qa = (int) $DB->insert_record('question_attempts', (object) ['questionusageid' => $usage, 'slot' => $slot,
                'behaviour' => 'deferredfeedback', 'questionid' => $questionid, 'variant' => 1, 'maxmark' => 1,
                'minfraction' => 0, 'maxfraction' => 1, 'flagged' => 0, 'timemodified' => time()]);
            $this->answer($qa);
            $ids[$slot] = $qa;
        }
        $DB->set_field('adaptivequiz_attempt', 'questionsattempted', $slot, ['id' => $engine]);

        return $ids;
    }

    /**
     * One answer step on a question attempt.
     *
     * @param int $qa The question attempt.
     * @return void
     */
    protected function answer(int $qa): void {
        global $DB;
        $n = (int) $DB->count_records('question_attempt_steps', ['questionattemptid' => $qa]);
        if ($n === 0) {
            $DB->insert_record('question_attempt_steps', (object) ['questionattemptid' => $qa, 'sequencenumber' => 0,
                'state' => 'todo', 'timecreated' => time(), 'userid' => 2]);
            $n = 1;
        }
        $DB->insert_record('question_attempt_steps', (object) ['questionattemptid' => $qa, 'sequencenumber' => $n,
            'state' => 'complete', 'timecreated' => time(), 'userid' => 2]);
    }

    /**
     * A run paused by the circuit breaker mid-sitting, resumed: the same engine attempt goes on, every question
     * once, every answer once, the history kept (#133).
     *
     * @return void
     */
    public function test_a_sitting_resumed_after_the_breaker_goes_on_in_its_engine_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$attemptid, $engine] = $this->sitting('inprogress', time() + 300);
        $runid = (int) $DB->get_field('local_catquizlab_attempt', 'runid', ['id' => $attemptid]);
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_RUNNING, ['id' => $runid]);
        // Five questions presented and answered before the server started failing.
        $this->present($engine, [101, 102, 103, 104, 105]);
        $this->assertTrue(sitting_integrity::check($engine)['ok']);

        // The breaker pauses the run; the worker vanishes; its lease runs out.
        run_lifecycle::set_paused($runid, true, circuit_breaker::PAUSE_REASON . ':submit_http_5xx');
        $DB->set_field('local_catquizlab_attempt', 'leaseexpires', time() - 5, ['id' => $attemptid]);
        $this->assertSame(1, attempt_scheduler::reclaim_stale(null, 600));
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $attemptid]
        ));
        // Paused: nobody is handed it.
        $this->assertFalse(job_claim::execute('worker-new')['hasjob']);
        $before = attempt_history::of_attempt($attemptid);

        // Resumed by an operator: the sitting is claimable again, its engine attempt untouched, its history kept.
        // (The readiness check before resuming reads the provisioned CAT test, which this fixture's stand-in activity
        // is not; it is tested in run_recovery_test. Here the activity is set aside for the resume only.)
        $cmid = (int) $DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $runid]);
        $DB->set_field('local_catquizlab_run', 'testcmid', 0, ['id' => $runid]);
        $this->assertTrue(circuit_breaker::reset_and_continue($runid)['ok']);
        $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $runid]);
        $this->assertSame(5, (int) $DB->get_field('adaptivequiz_attempt', 'questionsattempted', ['id' => $engine]));
        $this->assertSame(1, $DB->count_records('adaptivequiz_attempt', ['instance' => 4712]), 'no second engine attempt');
        $after = attempt_history::of_attempt($attemptid);
        $this->assertSame($before, array_slice($after, 0, count($before)), 'appended to, never rewritten');
        // Its retry is due after the backoff the reaper gave it; the clock moves on to it.
        $this->assertGreaterThan(time(), (int) $DB->get_field('local_catquizlab_attempt', 'nextruntime', ['id' => $attemptid]));
        $DB->set_field('local_catquizlab_attempt', 'nextruntime', time() - 1, ['id' => $attemptid]);
        $claim = job_claim::execute('worker-new');
        $this->assertTrue($claim['hasjob'], (string) ($claim['message'] ?? ''));
        $this->assertSame($attemptid, (int) $claim['attemptid']);

        // The worker continues the same engine attempt (worker/test/resume.test.js): three more questions, finished.
        $this->present($engine, [106, 107, 108]);
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $engine]);
        $done = job_complete::execute($attemptid, 'finished', 2000, $engine, '', '{}', 'worker-new');
        $this->assertTrue($done['acknowledged']);
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame($engine, (int) $row->engineattemptid);
        $this->assertNotEquals(attempt_scheduler::STATUS_QUEUED, (int) $row->status);

        // Eight questions, each presented once, each answered once.
        $check = sitting_integrity::check($engine);
        $this->assertSame([true, 8, 8, 8, [1, 1, 1, 1, 1, 1, 1, 1]], [$check['ok'], $check['answered'], $check['presented'],
            $check['distinct'], $check['steps']]);
    }

    /**
     * The check finds what a broken recovery leaves: a question answered twice, a question presented twice, an answer
     * the engine never counted.
     *
     * @return void
     */
    public function test_the_integrity_check_finds_doubles_and_losses(): void {
        global $DB;
        $this->resetAfterTest();

        [, $engine] = $this->sitting('inprogress', time() + 300);
        $qas = $this->present($engine, [201, 202, 203]);
        $this->assertTrue(sitting_integrity::check($engine)['ok']);

        // The second question answered twice: a resubmission that went through.
        $this->answer($qas[2]);
        $this->assertSame(['answer-steps-differ'], sitting_integrity::check($engine)['problems']);

        // The first question presented again in a fourth slot.
        [, $other] = $this->sitting('inprogress', time() + 300);
        $this->present($other, [301, 302, 301]);
        $this->assertContains('question-presented-twice', sitting_integrity::check($other)['problems']);

        // An answer the engine did not count.
        [, $third] = $this->sitting('inprogress', time() + 300);
        $this->present($third, [401, 402]);
        $DB->set_field('adaptivequiz_attempt', 'questionsattempted', 1, ['id' => $third]);
        $this->assertSame(['answered-presented-differ'], sitting_integrity::check($third)['problems']);

        $this->assertSame(['no-engine-attempt'], sitting_integrity::check(0)['problems']);
    }
}
