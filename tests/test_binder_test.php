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
 * Tests for the test binder.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\test_binder;
use local_catquizlab\local\environment;

/**
 * Test binder tests.
 *
 * @covers \local_catquizlab\local\test_binder
 */
final class test_binder_test extends \advanced_testcase {
    /**
     * Without the engine and host activity, binding is a no-op and leaves the run alone.
     *
     * @return void
     */
    public function test_bind_requires_engine(): void {
        global $DB;
        $this->resetAfterTest();

        if (environment::engine_available() && environment::adaptivequiz_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        $this->assertNull(test_binder::bind_existing($run->id, 4242));
        $this->assertNull($DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $run->id]));
    }
}
