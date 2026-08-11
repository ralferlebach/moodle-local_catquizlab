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
        foreach ([111, 222] as $engineid) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $person->id,
                'engineattemptid' => $engineid,
                'status' => attempt_scheduler::STATUS_RUNNING,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
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

        // This test only asserts the guard when the engine is absent.
        if (environment::engine_available() && environment::adaptivequiz_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

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
}
