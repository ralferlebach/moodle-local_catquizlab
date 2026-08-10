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

/**
 * Attempt collector tests.
 *
 * @covers \local_catquizlab\local\attempt_collector
 */
final class attempt_collector_test extends \advanced_testcase {
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
