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
use local_catquizlab\local\analysis_population;
use local_catquizlab\local\attempt_collector;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;
use local_catquizlab\local\result_validity;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\sitting_lock;

/**
 * "Collected" only with a trace (#138).
 *
 * A worker reported a sitting finished, the engine attempt existed, and the collector found nothing — and the
 * sitting was stored as collected all the same. Since 0.7.47 it waits for its trace instead: not collected, not
 * valid, not finished; looked for again; collected when the trace arrives; given up as a technical non-result
 * when it does not. Exports and statistics hold it only as that.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\external\job_complete
 * @covers     \local_catquizlab\local\attempt_scheduler
 * @covers     \local_catquizlab\local\attempt_collector
 * @covers     \local_catquizlab\local\analysis_population
 */
final class trace_integrity_test extends \advanced_testcase {
    /**
     * A running sitting of a run with an activity, its engine attempt finished in the engine and with no data of
     * the engine behind it (no CAT attempt, no progress): what the collector cannot read.
     *
     * @return array{0: int, 1: int, 2: int} Sitting, engine attempt, run.
     */
    protected function finished_without_data(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('Needs mod_adaptivequiz.');
        }
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_RUNNING, ['id' => $run->id]);
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
            'instance' => 4713, 'section' => 0, 'added' => time()]);
        $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $run->id]);
        $person = $generator->create_person(['runid' => $run->id, 'moodleuserid' => $user->id]);
        $engine = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => 4713, 'userid' => $user->id,
            'uniqueid' => 0, 'attemptstate' => 'complete', 'attemptstopcriteria' => '', 'questionsattempted' => 7,
            'timecreated' => time(), 'timemodified' => time()]);
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id,
            'personid' => $person->id, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
            'leaseowner' => 'w-1', 'leaseexpires' => time() + 300, 'timecreated' => time(), 'timemodified' => time()]);

        return [$attemptid, $engine, (int) $run->id];
    }

    /**
     * The outcomes of a sitting's history, oldest first.
     *
     * @param int $attemptid The sitting.
     * @return string[]
     */
    protected function outcomes(int $attemptid): array {
        return array_column(attempt_history::of_attempt($attemptid), 'outcome');
    }

    /**
     * Reported finished, no trace: waiting for it — not collected, the run not done, the funnel says so.
     *
     * @return void
     */
    public function test_a_missing_trace_is_not_collected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $engine, $runid] = $this->finished_without_data();

        $answer = job_complete::execute($attemptid, 'finished', 3000, $engine, '', '{}', 'w-1');
        $this->assertTrue($answer['acknowledged']);

        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame(attempt_scheduler::STATUS_TRACEPENDING, (int) $row->status, 'not collected');
        $this->assertNull($row->tracejson);
        $this->assertSame($engine, (int) $row->engineattemptid, 'the engine attempt is kept for the next look');
        $this->assertEmpty($row->leaseowner);
        $this->assertStringStartsWith('trace_missing: ', (string) $row->lasterror);
        $this->assertSame([attempt_history::TRACE_PENDING], $this->outcomes($attemptid));
        $this->assertNotContains(attempt_history::COLLECTED, $this->outcomes($attemptid));

        // The run is not done while the sitting may still be collected.
        $this->assertTrue(run_lifecycle::has_open_attempts($runid));
        $this->assertSame(1, run_lifecycle::attempt_counts($runid)['open']);
        $this->assertSame(0, run_lifecycle::attempt_counts($runid)['collected']);

        // The analysis basis counts it apart: started, not collected, a technical non-result.
        $funnel = analysis_population::funnel(new results_query(['runid' => $runid, 'validity' => result_validity::ALL]));
        $this->assertSame([1, 0, 1, 0], [$funnel['started'], $funnel['collected'], $funnel['tracepending'],
            $funnel['evaluated']]);
        // The exports hold no row for it, and their metadata names it.
        $query = new results_query(['runid' => $runid, 'validity' => result_validity::ALL]);
        $this->assertSame([], results_export::dataset($query, results_export::LEVEL_ATTEMPT)['rows']);
        $meta = results_export::metadata(
            new results_query(['runid' => $runid, 'validity' => result_validity::ALL]),
            results_export::LEVEL_ATTEMPT
        );
        $this->assertSame(1, $meta['population']['attempts']['tracepending']);
        $this->assertSame(0, $meta['population']['attempts']['collected']);
    }

    /**
     * Reported twice: the second report changes nothing and is recorded as late.
     *
     * @return void
     */
    public function test_a_second_completion_changes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $engine] = $this->finished_without_data();

        job_complete::execute($attemptid, 'finished', 3000, $engine, '', '{}', 'w-1');
        $second = job_complete::execute($attemptid, 'finished', 3000, $engine, '', '{}', 'w-1');
        $this->assertFalse($second['acknowledged']);
        $this->assertSame(attempt_scheduler::STATUS_TRACEPENDING, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $attemptid]
        ));
        $this->assertSame([attempt_history::TRACE_PENDING, attempt_history::STALE], $this->outcomes($attemptid));
    }

    /**
     * The trace arrives late: collected at the next look, once — and only then counted as collected.
     *
     * @return void
     */
    public function test_a_late_trace_is_collected_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $engine, $runid] = $this->finished_without_data();
        job_complete::execute($attemptid, 'finished', 3000, $engine, '', '{}', 'w-1');

        // Not yet due: nothing happens.
        $this->assertSame(
            ['collected' => 0, 'waiting' => 0, 'givenup' => 0],
            attempt_scheduler::retry_pending_traces(time())
        );

        // Due, and the engine has it now: what the collector would write.
        $late = static function (int $id) use ($DB): ?array {
            $trace = ['finaltheta' => 0.2, 'finalse' => 0.4, 'items' => [1, 2, 3], 'responses' => [1 => 1.0, 2 => 0.0,
                3 => 1.0], 'nitems' => 3, 'stopreason' => 'standarderror'];
            $DB->update_record('local_catquizlab_attempt', (object) ['id' => $id,
                'status' => attempt_scheduler::STATUS_COLLECTED, 'tracejson' => json_encode($trace)]);
            return $trace;
        };
        $later = time() + attempt_scheduler::TRACE_RETRY_DELAY + 1;
        $this->assertSame(
            ['collected' => 1, 'waiting' => 0, 'givenup' => 0],
            attempt_scheduler::retry_pending_traces($later, $late)
        );
        $this->assertSame(attempt_scheduler::STATUS_COLLECTED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $attemptid]
        ));
        $this->assertSame([attempt_history::TRACE_PENDING, attempt_history::COLLECTED], $this->outcomes($attemptid));
        // Collected: no longer looked at.
        $this->assertSame(
            ['collected' => 0, 'waiting' => 0, 'givenup' => 0],
            attempt_scheduler::retry_pending_traces($later + 3600, $late)
        );
        $this->assertFalse(run_lifecycle::has_open_attempts($runid));
    }

    /**
     * A trace that never comes: looked for TRACE_RETRIES times, then failed — a technical non-result, never
     * collected, never valid, and not played again.
     *
     * @return void
     */
    public function test_a_trace_that_never_comes_is_given_up(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $engine, $runid] = $this->finished_without_data();
        job_complete::execute($attemptid, 'finished', 3000, $engine, '', '{}', 'w-1');

        $none = static fn(int $id): ?array => null;
        $clock = time();
        $waiting = 0;
        for ($look = 1; $look <= attempt_scheduler::TRACE_RETRIES + 1; $look++) {
            $clock += attempt_scheduler::TRACE_RETRY_DELAY + 1;
            $result = attempt_scheduler::retry_pending_traces($clock, $none);
            $waiting += $result['waiting'];
            if ($result['givenup'] === 1) {
                break;
            }
        }
        $this->assertSame(attempt_scheduler::TRACE_RETRIES - 1, $waiting);
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $row->status);
        $this->assertNull($row->tracejson);
        $outcomes = $this->outcomes($attemptid);
        $this->assertSame(attempt_history::ABANDONED, end($outcomes));
        $this->assertNotContains(attempt_history::COLLECTED, $this->outcomes($attemptid));
        // Not played again: nothing queued.
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', ['runid' => $runid,
            'status' => attempt_scheduler::STATUS_QUEUED]));
        $funnel = analysis_population::funnel(new results_query(['runid' => $runid, 'validity' => result_validity::ALL]));
        $this->assertSame([1, 0, 0], [$funnel['failed'], $funnel['collected'], $funnel['valid']]);
    }

    /**
     * Re-collecting a run leaves alone what has not finished; a trace without items is no trace.
     *
     * @return void
     */
    public function test_collecting_a_run_does_not_promote_unfinished_sittings(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$attemptid, $engine, $runid] = $this->finished_without_data();
        // Failed and queued sittings with an engine attempt id: never turned into "collected".
        foreach ([attempt_scheduler::STATUS_FAILED, attempt_scheduler::STATUS_QUEUED] as $status) {
            $DB->set_field('local_catquizlab_attempt', 'status', $status, ['id' => $attemptid]);
            $DB->set_field('local_catquizlab_attempt', 'engineattemptid', $engine, ['id' => $attemptid]);
            $result = attempt_collector::collect_run($runid);
            $this->assertSame(0, $result['candidates']);
            $this->assertSame($status, (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $attemptid]));
        }
        $this->assertFalse(attempt_collector::usable(['items' => [], 'responses' => [], 'finaltheta' => 0.3]));
        // Items without answers are not a record of what was answered (re-audit 2026-11-09).
        $this->assertFalse(attempt_collector::usable(['items' => [5]]));
        $this->assertFalse(attempt_collector::usable(['items' => [5], 'responses' => [5 => null]]));
        $this->assertTrue(attempt_collector::usable(['items' => [5], 'responses' => [5 => 1.0]]));
    }

    /**
     * The trace contract, clause by clause (#138, re-audit 2026-11-09).
     *
     * @return void
     */
    public function test_the_trace_contract(): void {
        $good = ['finaltheta' => 0.4, 'items' => [11, 12, 13], 'responses' => [11 => 1.0, 12 => 0.0, 13 => null],
            'nitems' => 3, 'stopreason' => 'Standard error reached', 'hoststopcode' => null, 'enginestatus' => null];
        $engine = ['found' => true, 'attemptstate' => 'complete', 'catquizrow' => true];
        $this->assertSame([], attempt_collector::defects($good, $engine));
        $this->assertSame([], attempt_collector::defects($good), 'a trace alone: the engine clauses are not asked');

        $cases = [
            'host attempt missing' => [$good, ['found' => false], ['noattempt']],
            'not complete yet' => [$good, ['attemptstate' => 'inprogress'] + $engine, ['notcomplete']],
            'no engine record' => [$good, ['catquizrow' => false] + $engine, ['noengineresult']],
            'only unanswered' => [['responses' => [11 => null, 12 => null, 13 => null]] + $good, $engine, ['noanswers']],
            'nothing at all' => [['items' => [], 'responses' => [], 'nitems' => 0] + $good, $engine, ['noanswers']],
            'items and responses differ' => [['items' => [11, 13, 12]] + $good, $engine, ['itemsmismatch']],
            'nitems wrong' => [['nitems' => 4] + $good, $engine, ['itemsmismatch']],
            'fraction above 1' => [['responses' => [11 => 1.5, 12 => 0.0, 13 => null]] + $good, $engine, ['badresponse']],
            'fraction below 0' => [['responses' => [11 => -0.5, 12 => 0.0, 13 => null]] + $good, $engine, ['badresponse']],
            'fraction NaN' => [['responses' => [11 => NAN, 12 => 0.0, 13 => null]] + $good, $engine, ['badresponse']],
            'theta infinite' => [['finaltheta' => INF] + $good, $engine, ['badtheta']],
            'no reason for the end' => [['stopreason' => ' '] + $good, $engine, ['nostop']],
            'host stop code is a reason' => [['stopreason' => '', 'hoststopcode' => '7'] + $good, $engine, []],
            'engine status is a reason' => [['stopreason' => '', 'enginestatus' => 2] + $good, $engine, []],
            'several at once' => [['responses' => [11 => null, 12 => null, 13 => null], 'stopreason' => ''] + $good,
                ['catquizrow' => false] + $engine, ['noengineresult', 'noanswers', 'nostop']],
        ];
        foreach ($cases as $name => [$trace, $eng, $expected]) {
            $this->assertSame($expected, attempt_collector::defects($trace, $eng), $name);
        }
    }

    /**
     * Give a host attempt answered questions: a question usage, one question attempt per fraction, its steps.
     *
     * @param int $engine The adaptivequiz attempt.
     * @param array $fractions Question id => fraction, null for shown and not answered.
     * @return void
     */
    protected function answered(int $engine, array $fractions): void {
        global $DB;

        $usage = (int) $DB->insert_record('question_usages', (object) ['contextid' => 1,
            'component' => 'mod_adaptivequiz', 'preferredbehaviour' => 'deferredfeedback']);
        $DB->set_field('adaptivequiz_attempt', 'uniqueid', $usage, ['id' => $engine]);
        $slot = 0;
        foreach ($fractions as $questionid => $fraction) {
            $slot++;
            $qa = (int) $DB->insert_record('question_attempts', (object) ['questionusageid' => $usage, 'slot' => $slot,
                'behaviour' => 'deferredfeedback', 'questionid' => $questionid, 'variant' => 1, 'maxmark' => 1,
                'minfraction' => 0, 'maxfraction' => 1, 'flagged' => 0, 'timemodified' => time()]);
            $DB->insert_record('question_attempt_steps', (object) ['questionattemptid' => $qa, 'sequencenumber' => 0,
                'state' => 'todo', 'timecreated' => time(), 'userid' => 2]);
            if ($fraction !== null) {
                $DB->insert_record('question_attempt_steps', (object) ['questionattemptid' => $qa, 'sequencenumber' => 1,
                    'state' => $fraction > 0 ? 'gradedright' : 'gradedwrong', 'fraction' => $fraction,
                    'timecreated' => time(), 'userid' => 2]);
            }
        }
        $DB->set_field('adaptivequiz_attempt', 'questionsattempted', count(array_filter(
            $fractions,
            static fn($f): bool => $f !== null
        )), ['id' => $engine]);
        $DB->set_field('adaptivequiz_attempt', 'attemptstopcriteria', 'Maximum number of questions reached', ['id' => $engine]);
    }

    /**
     * The engine's own record of a host attempt.
     *
     * @param int $engine The adaptivequiz attempt.
     * @return void
     */
    protected function recorded_by_engine(int $engine): void {
        global $DB;

        $userid = (int) $DB->get_field('adaptivequiz_attempt', 'userid', ['id' => $engine]);
        $DB->insert_record('local_catquiz_attempts', (object) ['userid' => $userid, 'attemptid' => $engine,
            'component' => 'mod_adaptivequiz', 'instanceid' => 4713, 'contextid' => 0, 'status' => 1,
            'personability_after_attempt' => 0.75, 'timecreated' => time(), 'timemodified' => time()]);
    }

    /**
     * Answers in the host but no record of the engine: not collected with an invented ability of 0, but held with
     * the reason; collected once the engine's record is there, engine attempt and trace in the same row.
     *
     * @return void
     */
    public function test_a_sitting_the_engine_has_not_recorded_waits_with_the_reason(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!\local_catquizlab\local\environment::engine_available()) {
            $this->markTestSkipped('Needs local_catquiz.');
        }
        [$attemptid, $engine, $runid] = $this->finished_without_data();
        $this->answered($engine, [9101 => 1.0, 9102 => 0.0, 9103 => 1.0]);

        job_complete::execute($attemptid, 'finished', 2500, $engine, '', '{}', 'w-1');
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame(attempt_scheduler::STATUS_TRACEPENDING, (int) $row->status);
        $this->assertNull($row->tracejson);
        $this->assertStringStartsWith('trace_missing: [noengineresult]', (string) $row->lasterror);
        // Written with the status: the engine attempt and the runtime.
        $this->assertSame($engine, (int) $row->engineattemptid);
        $this->assertSame(2500, (int) $row->runtimems);

        $this->recorded_by_engine($engine);
        $later = time() + attempt_scheduler::TRACE_RETRY_DELAY + 1;
        $this->assertSame(['collected' => 1, 'waiting' => 0, 'givenup' => 0], attempt_scheduler::retry_pending_traces($later));
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        $this->assertSame(attempt_scheduler::STATUS_COLLECTED, (int) $row->status);
        $trace = json_decode($row->tracejson, true);
        $this->assertSame([9101, 9102, 9103], $trace['items']);
        $this->assertSame(0.75, (float) $trace['finaltheta']);
        $this->assertSame(['hostattempted' => 3, 'answered' => 3, 'presented' => 3], $trace['consistency']);
        $this->assertSame([], attempt_collector::last_defects());
        $this->assertFalse(run_lifecycle::has_open_attempts($runid));
    }

    /**
     * A host attempt not marked complete yet: its last answers may still be written, so nothing is collected.
     *
     * @return void
     */
    public function test_an_attempt_still_being_written_is_not_collected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!\local_catquizlab\local\environment::engine_available()) {
            $this->markTestSkipped('Needs local_catquiz.');
        }
        [$attemptid, $engine] = $this->finished_without_data();
        $this->answered($engine, [9101 => 1.0, 9102 => null]);
        $this->recorded_by_engine($engine);
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'inprogress', ['id' => $engine]);

        $this->assertNull(attempt_collector::collect($attemptid, $engine));
        $this->assertSame(['notcomplete'], attempt_collector::last_defects());
        $this->assertSame(attempt_scheduler::STATUS_RUNNING, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $attemptid]
        ), 'the collector alone writes nothing when it refuses');

        // Complete now: collected, and the question shown but not answered stays in the trace as such.
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $engine]);
        $trace = attempt_collector::collect($attemptid, $engine);
        $this->assertNotNull($trace);
        $this->assertSame([9101 => 1.0, 9102 => null], $trace['responses']);
        $this->assertSame(['hostattempted' => 1, 'answered' => 1, 'presented' => 2], $trace['consistency']);
    }

    /**
     * While another request holds the sitting, a report, the lease recovery and the trace look change nothing,
     * and nothing is counted (#138: concurrent ends of one sitting).
     *
     * @return void
     */
    public function test_a_sitting_held_by_another_request_is_left_alone(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // A lock factory that keeps two holders apart within one process too (database advisory locks are
        // re-entrant per connection, and a test has only one).
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        sitting_lock::$maxwait = 0;
        try {
            [$attemptid, $engine, $runid] = $this->finished_without_data();
            $held = sitting_lock::acquire($attemptid, 0);
            $this->assertNotNull($held);

            // The report: not applied, said so, nothing written or recorded.
            $answer = job_complete::execute($attemptid, 'finished', 3000, $engine, '', '{}', 'w-1');
            $this->assertFalse($answer['acknowledged']);
            $this->assertSame(get_string('job:busy', 'local_catquizlab'), $answer['message']);
            $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
            $this->assertSame(attempt_scheduler::STATUS_RUNNING, (int) $row->status);
            $this->assertSame('w-1', $row->leaseowner);
            $this->assertSame([], $this->outcomes($attemptid));

            // The lease recovery: the lease has lapsed, but the sitting is held — left for the next tick.
            $DB->set_field('local_catquizlab_attempt', 'leaseexpires', time() - 10, ['id' => $attemptid]);
            $this->assertSame(0, attempt_scheduler::reclaim_stale($runid, 600));
            $this->assertSame('w-1', $DB->get_field('local_catquizlab_attempt', 'leaseowner', ['id' => $attemptid]));

            // The trace look: waiting and held — not a look, not given up.
            $DB->update_record('local_catquizlab_attempt', (object) ['id' => $attemptid,
                'status' => attempt_scheduler::STATUS_TRACEPENDING, 'nextruntime' => 0]);
            $none = static fn(int $id): ?array => null;
            for ($i = 0; $i < attempt_scheduler::TRACE_RETRIES + 2; $i++) {
                $this->assertSame(
                    ['collected' => 0, 'waiting' => 0, 'givenup' => 0],
                    attempt_scheduler::retry_pending_traces(time() + 3600 * ($i + 1), $none)
                );
            }
            $this->assertSame(attempt_scheduler::STATUS_TRACEPENDING, (int) $DB->get_field(
                'local_catquizlab_attempt',
                'status',
                ['id' => $attemptid]
            ));
            $this->assertSame([], $this->outcomes($attemptid));

            // Released: the next look counts.
            $held->release();
            $this->assertSame(
                ['collected' => 0, 'waiting' => 1, 'givenup' => 0],
                attempt_scheduler::retry_pending_traces(time() + 3600, $none)
            );
        } finally {
            sitting_lock::$maxwait = null;
        }
    }

    /**
     * A sitting collected by another process after the list of waiting sittings was read is not looked at again:
     * not counted, not given up, no history (#138: the status is read again under the lock).
     *
     * @return void
     */
    public function test_a_sitting_collected_meanwhile_is_not_looked_at_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$first] = $this->finished_without_data();
        [$second] = $this->finished_without_data();
        foreach ([$first, $second] as $id) {
            $DB->update_record('local_catquizlab_attempt', (object) ['id' => $id,
                'status' => attempt_scheduler::STATUS_TRACEPENDING, 'nextruntime' => 0]);
        }
        // While the first is looked at, "another process" collects the second.
        $collect = static function (int $id) use ($DB, $first, $second): ?array {
            if ($id === $first) {
                $DB->update_record('local_catquizlab_attempt', (object) ['id' => $second,
                    'status' => attempt_scheduler::STATUS_COLLECTED, 'tracejson' => '{"items":[1]}']);
            }
            return null;
        };

        $this->assertSame(
            ['collected' => 0, 'waiting' => 1, 'givenup' => 0],
            attempt_scheduler::retry_pending_traces(time() + 60, $collect)
        );
        $this->assertSame([attempt_history::TRACE_PENDING], $this->outcomes($first));
        $this->assertSame([], $this->outcomes($second), 'the second is left as the other process wrote it');
        $this->assertSame(attempt_scheduler::STATUS_COLLECTED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $second]
        ));
    }
}
