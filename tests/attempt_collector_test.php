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
 * Tests for the attempt collector.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\attempt_collector;
use local_catquizlab\local\environment;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\task\collect_attempts;

/**
 * Attempt collector tests.
 *
 * @covers \local_catquizlab\local\attempt_collector
 */
final class attempt_collector_test extends \advanced_testcase {
    /**
     * The components as installed again after a test that simulated their absence.
     *
     * @return void
     */
    protected function tearDown(): void {
        \local_catquizlab\local\environment::reset();
        parent::tearDown();
    }

    /**
     * collect_run counts candidates with an engine attempt id; without the engine
     * nothing is collected, but it completes cleanly and reports timing.
     *
     * @return void
     */
    public function test_collect_run(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id]);
        $now = time();
        // Finished sittings are candidates: collected ones (read again) and those waiting for their trace (#138).
        foreach ([111 => attempt_scheduler::STATUS_TRACEPENDING, 222 => attempt_scheduler::STATUS_COLLECTED] as $engineid => $st) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $person->id,
                'engineattemptid' => $engineid,
                'status' => $st,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        // One still being played is not: collecting it would turn it into "collected" behind the scheduler's back.
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id, 'engineattemptid' => 333,
            'status' => attempt_scheduler::STATUS_RUNNING, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        // An attempt without an engine id is not a candidate.
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id,
            'status' => attempt_scheduler::STATUS_QUEUED,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $result = attempt_collector::collect_run($run->id);
        $this->assertSame(2, $result['candidates']);
        $this->assertGreaterThanOrEqual(0, $result['runtimems']);
        if (!environment::engine_available() || !environment::adaptivequiz_available()) {
            $this->assertSame(0, $result['collected']);
        }
    }

    /**
     * The collect task runs for a queued run id.
     *
     * @return void
     */
    public function test_collect_task(): void {
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        attempt_collector::queue($run->id);

        $tasks = \core\task\manager::get_adhoc_tasks(collect_attempts::class);
        $this->assertCount(1, $tasks);

        $task = reset($tasks);
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertTrue(true);
    }

    /**
     * The pure trace assembly derives items, count and rounding from the responses.
     *
     * @return void
     */
    public function test_build_trace(): void {
        $trace = attempt_collector::build_trace(0.123456, 0.298765, [
            101 => 1.0,
            102 => 0.0,
            103 => 1.0,
        ], 'maxitems');

        $this->assertEqualsWithDelta(0.12346, $trace['finaltheta'], 1e-9);
        $this->assertEqualsWithDelta(0.29877, $trace['finalse'], 1e-9);
        $this->assertSame([101, 102, 103], $trace['items']);
        $this->assertSame(3, $trace['nitems']);
        $this->assertSame('maxitems', $trace['stopreason']);
        $this->assertSame([101 => 1.0, 102 => 0.0, 103 => 1.0], $trace['responses']);
    }

    /**
     * Debug info yields the final per-scale abilities and exposure.
     *
     * @return void
     */
    public function test_parse_debug_info(): void {
        $json = json_encode([
            ['personabilities' => ['1' => 0.1, '2' => -0.5], 'numquestionsperscale' => ['1' => 3, '2' => 2]],
            ['personabilities' => ['1' => 0.2, '2' => -0.8], 'numquestionsperscale' => ['1' => 4, '2' => 3]],
        ]);
        $parsed = \local_catquizlab\local\attempt_collector::parse_debug_info($json);

        $this->assertSame(2, $parsed['steps']);
        // The last snapshot wins.
        $this->assertEqualsWithDelta(0.2, $parsed['scaleabilities'][1], 1e-9);
        $this->assertEqualsWithDelta(-0.8, $parsed['scaleabilities'][2], 1e-9);
        $this->assertSame(4, $parsed['questionsperscale'][1]);

        // A list-of-objects personabilities form is also accepted.
        $listform = json_encode([['personabilities' => [
            ['catscaleid' => 10, 'ability' => 1.2],
            ['catscaleid' => 11, 'ability' => -0.3],
        ]]]);
        $parsedlist = \local_catquizlab\local\attempt_collector::parse_debug_info($listform);
        $this->assertEqualsWithDelta(1.2, $parsedlist['scaleabilities'][10], 1e-9);

        $this->assertSame(0, \local_catquizlab\local\attempt_collector::parse_debug_info('')['steps']);
    }

    /**
     * A null standard error is preserved.
     *
     * @return void
     */
    public function test_build_trace_null_se(): void {
        $trace = attempt_collector::build_trace(0.5, null, [1 => 1.0]);
        $this->assertNull($trace['finalse']);
    }

    /**
     * Collecting is a no-op without the engine (as in CI / stand-alone installs).
     *
     * @return void
     */
    public function test_collect_requires_engine(): void {
        global $DB;
        $this->resetAfterTest();

        // Where the engine is installed, as in CI, the guard runs against a simulated absence instead of being skipped (#140).
        \local_catquizlab\local\environment::simulate_absent(['local_catquiz', 'mod_adaptivequiz']);

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id]);
        $now = time();
        $attemptid = $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid'           => $run->id,
            'personid'        => $person->id,
            'engineattemptid' => 12345,
            'status'          => attempt_scheduler::STATUS_RUNNING,
            'timecreated'     => $now,
            'timemodified'    => $now,
        ]);

        $this->assertNull(attempt_collector::collect($attemptid));
        // The attempt is left untouched.
        $this->assertSame(
            attempt_scheduler::STATUS_RUNNING,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $attemptid])
        );
    }

    /**
     * The engine's rendered ability line is translated back into scale ids.
     *
     * @return void
     */
    public function test_ability_line_is_translated(): void {
        $this->resetAfterTest();

        // The debug_info rows carry the abilities as a rendered line rather than
        // as a map: "Scale: 0.5, Scale / K1: 0.4". That reads well in a report and
        // says nothing a machine can use, so without translating the names the
        // whole ability path was silently dropped.
        $method = new \ReflectionMethod(attempt_collector::class, 'abilities_from_line');
        $method->setAccessible(true);

        $names = ['CATLab run 1' => 10, 'CATLab run 1 / K1' => 11, 'CATLab run 1 / K1.1' => 12];
        $line = '"CATLab run 1: -0.46, CATLab run 1 / K1: -0.4752, CATLab run 1 / K1.1: -3"';

        $this->assertSame([10 => -0.46, 11 => -0.4752, 12 => -3.0], $method->invoke(null, $line, $names));
    }

    /**
     * A name the run does not know is left out rather than guessed at.
     *
     * @return void
     */
    public function test_unknown_scale_names_are_skipped(): void {
        $this->resetAfterTest();

        $method = new \ReflectionMethod(attempt_collector::class, 'abilities_from_line');
        $method->setAccessible(true);

        $result = $method->invoke(null, '"Known: 1.5, Stranger: 2.5"', ['Known' => 7]);

        $this->assertSame([7 => 1.5], $result);
        $this->assertSame([], $method->invoke(null, '', ['Known' => 7]));
    }

    /**
     * The progress read for a sitting is its own CAT attempt's, never the row that another CAT attempt numbered like
     * the sitting's activity attempt holds (CI cd4a6dfc: another person's, newer, read as this sitting's).
     *
     * @return void
     */
    public function test_read_progress_is_the_sittings_own(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $DB->get_manager();
        if (!$manager->table_exists('local_catquiz_progress') || !$manager->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('The engine is not installed.');
        }
        $me = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        // Only the columns this host version has (4.5 and 5.x differ).
        $columns = $DB->get_columns('adaptivequiz_attempt');
        $attempt = static fn(int $userid): int => (int) $DB->insert_record('adaptivequiz_attempt', (object) array_intersect_key([
            'instance' => 1, 'userid' => $userid, 'uniqueid' => 0, 'attemptstate' => 'inprogress',
            'attemptstopcriteria' => '', 'questionsattempted' => 0, 'difficultysum' => 0, 'standarderror' => 0,
            'measure' => 0, 'timecreated' => time(), 'timemodified' => time()], $columns));
        // Both CAT attempts with ids of their own choosing: mine far off, the other person's with the number of my
        // activity attempt — as two counters that run side by side produce it.
        $cat = static function (int $id, int $attemptid, int $userid) use ($DB): int {
            $DB->insert_record_raw('local_catquiz_attempts', ['id' => $id, 'attemptid' => $attemptid,
                'userid' => $userid, 'component' => 'mod_adaptivequiz', 'timecreated' => time()], false, false, true);
            return $id;
        };
        $progress = static fn(int $catid, int $userid, array $played): int => (int) $DB->insert_record(
            'local_catquiz_progress',
            (object) ['attemptid' => $catid, 'userid' => $userid, 'component' => 'adaptivequiz', 'timemodified' => time(),
                'json' => json_encode(['playedquestions' => $played])]
        );

        $mine = $attempt((int) $me->id);
        $theirs = $attempt((int) $other->id);
        $mycat = $cat($mine + 100000, $mine, (int) $me->id);
        $progress($mycat, (int) $me->id, [['id' => 11], ['id' => 12], ['id' => 13]]);
        // The other person's CAT attempt is numbered like my activity attempt, and its progress is the newer row.
        $cat($mine, $theirs, (int) $other->id);
        $progress($mine, (int) $other->id, [['id' => 91], ['id' => 92]]);

        $method = new \ReflectionMethod(attempt_collector::class, 'read_progress');
        $read = $method->invoke(null, $mine);
        $this->assertSame([11, 12, 13], array_column($read['playedquestions'], 'id'));
        // Without a CAT attempt (an engine before 1.2.1): the row under the activity attempt, if it is the person's.
        $DB->delete_records('local_catquiz_attempts', ['id' => $mycat]);
        $this->assertSame([], $method->invoke(null, $mine));
    }

    /**
     * Without a CAT attempt (an engine before 1.2.1) the progress row under the activity attempt's number is read only
     * if it is the sitting's own person's: another person's row of that number is never taken (#168 DoD1).
     *
     * @return void
     */
    public function test_read_progress_never_falls_back_to_another_person(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $DB->get_manager();
        if (!$manager->table_exists('local_catquiz_progress') || !$manager->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('The engine is not installed.');
        }
        $me = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $columns = $DB->get_columns('adaptivequiz_attempt');
        $mine = (int) $DB->insert_record('adaptivequiz_attempt', (object) array_intersect_key([
            'instance' => 1, 'userid' => $me->id, 'uniqueid' => 0, 'attemptstate' => 'inprogress',
            'attemptstopcriteria' => '', 'questionsattempted' => 0, 'difficultysum' => 0, 'standarderror' => 0,
            'measure' => 0, 'timecreated' => time(), 'timemodified' => time()], $columns));
        // Another person's progress under the number of my activity attempt, and no CAT attempt of mine.
        $DB->insert_record('local_catquiz_progress', (object) ['attemptid' => $mine, 'userid' => (int) $other->id,
            'component' => 'adaptivequiz', 'timemodified' => time(), 'json' => json_encode(['playedquestions' => [
            ['id' => 91], ['id' => 92]]])]);

        $method = new \ReflectionMethod(attempt_collector::class, 'read_progress');
        $this->assertSame([], $method->invoke(null, $mine));
        $this->assertSame([], attempt_collector::progress_of($mine));
    }
}
