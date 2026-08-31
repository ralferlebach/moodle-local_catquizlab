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
 * Tests for the run orchestrator.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\run_orchestrator;
use local_catquizlab\local\environment;

/**
 * Run orchestrator tests.
 *
 * @covers \local_catquizlab\local\run_orchestrator
 */
final class run_orchestrator_test extends \advanced_testcase {
    /**
     * The pipeline lists the five setup stages in order.
     *
     * @return void
     */
    public function test_plan_stages(): void {
        $this->assertSame(
            // The container comes before the test: an adaptivequiz needs a
            // course and a section to be created in, and the old order asked
            // for the test while the run still had neither.
            ['scales', 'materialise', 'container', 'people', 'test', 'attempts'],
            run_orchestrator::plan_stages()
        );
    }

    /**
     * Setup is a guarded no-op without the engine and does not advance the run.
     *
     * @return void
     */
    public function test_setup_requires_engine(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        if (environment::engine_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run(['status' => 0]);

        $result = run_orchestrator::setup($run->id);
        $this->assertFalse($result['ok']);
        $this->assertSame('engine-unavailable', $result['reason']);
        $this->assertSame(0, (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $run->id]));
    }
}
