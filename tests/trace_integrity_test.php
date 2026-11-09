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
        $this->assertTrue(attempt_collector::usable(['items' => [5]]));
        $this->assertTrue(attempt_collector::usable(['responses' => [5 => 1.0]]));
    }
}
