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
 * Query cost, as a gate rather than a reading.
 *
 * Measuring a provisioning told us where 549,727 queries went; it did not stop
 * the next change from making it worse. These budgets fail the build when the
 * rate changes, which is the only part of the number that is actionable: a
 * large pool costing many queries is arithmetic, and a small pool suddenly
 * costing twice as many per item is a defect.
 *
 * The thresholds are generous on purpose. A gate that fires on noise gets
 * raised until it fires on nothing.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\cat_item_provisioner;
use local_catquizlab\local\run_log;

/**
 * Query budget tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class query_budget_test extends \advanced_testcase {
    /**
     * Repeated engine lookups for one scale are free after the first.
     *
     * @return void
     */
    public function test_engine_lookups_do_not_scale_with_items(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        cat_item_provisioner::forget_visible_items();

        // Twenty items on one scale used to mean twenty engine round trips,
        // which is where the thirty-nine queries per item came from.
        $before = $DB->perf_get_queries();
        for ($i = 0; $i < 20; $i++) {
            cat_item_provisioner::visible_items(1, 1);
        }
        $spent = $DB->perf_get_queries() - $before;

        $this->assertLessThanOrEqual(
            5,
            $spent,
            'Twenty lookups of one scale cost ' . $spent . ' queries; the answer is held for the request.'
        );
    }

    /**
     * Counting a queue does not scale with the queue.
     *
     * @return void
     */
    public function test_the_queue_breakdown_does_not_scale_with_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;

        for ($i = 0; $i < 60; $i++) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => 0,
                'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED,
                'tries' => 0, 'nextruntime' => 0,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        $before = $DB->perf_get_queries();
        \local_catquizlab\local\attempt_scheduler::queue_breakdown();
        $spent = $DB->perf_get_queries() - $before;

        // Grouped by run rather than walked per attempt: a queue of 1600 must
        // not be 1600 lookups to draw one line of text.
        $this->assertLessThanOrEqual(
            15,
            $spent,
            'Breaking down 60 attempts cost ' . $spent . ' queries.'
        );
    }

    /**
     * Reading the debug console does not scale with the buffer.
     *
     * @return void
     */
    public function test_reading_the_debug_console_is_bounded(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('debuglevel', 'verbose', 'local_catquizlab');
        for ($i = 0; $i < 50; $i++) {
            \local_catquizlab\local\debug_trace::record(
                \local_catquizlab\local\debug_trace::UI,
                'action' . $i
            );
        }

        $before = $DB->perf_get_queries();
        \local_catquizlab\local\debug_trace::entries([], 50);
        $spent = $DB->perf_get_queries() - $before;

        $this->assertLessThanOrEqual(
            3,
            $spent,
            'Reading 50 entries cost ' . $spent . ' queries.'
        );
    }

    /**
     * The per-stage budgets describe the rates this codebase actually has.
     *
     * @return void
     */
    public function test_the_budgets_match_the_measured_rates(): void {
        $this->resetAfterTest();

        // Measured here: materialising 24 items took 937 queries, about 39
        // each, and the reported 549,727-query provisioning was that same rate
        // against some fourteen thousand. A budget that failed on the reported
        // number would be a budget against arithmetic.
        $this->assertFalse(run_log::over_budget('materialise', 549727, 14000));
        $this->assertFalse(run_log::over_budget('materialise', 937, 24));

        // Double the rate is a defect, and that is what this catches.
        $this->assertTrue(run_log::over_budget('materialise', 1900, 24));

        // The flat stages do not scale with anything, so their budget is a
        // number rather than a rate.
        $this->assertTrue(run_log::over_budget('container', 900));
        $this->assertFalse(run_log::over_budget('container', 20));
    }
}
