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
 * Tests for the run lifecycle events.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\event\run_scheduled;
use local_catquizlab\event\run_aggregated;
use local_catquizlab\event\run_aborted;

/**
 * Run lifecycle event tests.
 *
 * @covers \local_catquizlab\event\run_scheduled
 * @covers \local_catquizlab\event\run_aggregated
 * @covers \local_catquizlab\event\run_aborted
 */
final class events_test extends \advanced_testcase {
    /**
     * Each lifecycle event triggers with the run id and a readable description.
     *
     * @return void
     */
    public function test_events_trigger(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();

        foreach ([run_scheduled::class, run_aggregated::class, run_aborted::class] as $class) {
            $sink = $this->redirectEvents();
            $class::create(['objectid' => 7, 'context' => $context])->trigger();
            $events = $sink->get_events();
            $sink->close();

            $this->assertCount(1, $events);
            $this->assertInstanceOf($class, $events[0]);
            $this->assertSame(7, (int) $events[0]->objectid);
            $this->assertStringContainsString('7', $events[0]->get_description());
            $this->assertNotEmpty($class::get_name());
        }
    }
}
