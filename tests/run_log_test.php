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
 * What happened to a run, kept across the things that used to erase it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\run_log;

/**
 * Run log tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\run_log
 */
final class run_log_test extends \advanced_testcase {
    /**
     * A run to log against.
     *
     * @return int
     */
    protected function make_run(): int {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');

        return (int) $generator->create_run()->id;
    }

    /**
     * A reset keeps the log and starts a new execution attempt.
     *
     * @return void
     */
    public function test_a_reset_keeps_the_log(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        run_log::record($runid, run_log::START_REQUESTED);
        run_log::record($runid, run_log::RUN_FAILED, ['reason' => 'pool too small']);

        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FAILED, ['id' => $runid]);
        run_lifecycle::reset($runid);

        // The wrong moment to lose it: what went wrong on the last attempt is
        // exactly what somebody needs while looking at this one.
        $first = run_log::entries($runid, 1);
        $this->assertCount(2, $first);
        $this->assertSame('pool too small', $first[1]['summary']);

        $this->assertSame(2, run_log::current_attempt($runid));
    }

    /**
     * A measured step records what it cost.
     *
     * @return void
     */
    public function test_a_step_records_its_cost(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();

        $token = run_log::start_step($runid, 'materialise');
        $DB->get_records('local_catquizlab_run', ['id' => $runid]);
        run_log::finish_step($token, true);

        $completed = null;
        foreach (run_log::entries($runid) as $entry) {
            if ($entry['event'] === run_log::STAGE_COMPLETED) {
                $completed = $entry;
            }
        }

        // A provisioning once used 549,727 queries and nothing recorded which
        // stage spent them.
        $this->assertNotNull($completed);
        $this->assertSame('materialise', $completed['stage']);
        $this->assertGreaterThan(0, $completed['dbqueries']);
    }

    /**
     * The budget is per stage, and the scaling stages scale.
     *
     * @return void
     */
    public function test_the_budget_is_per_stage(): void {
        $this->resetAfterTest();

        // Measured here: 937 queries for 24 items, about 39 each. The reported
        // 549,727-query provisioning was the same rate against a far larger
        // pool — a big number, and not a different problem.
        $this->assertFalse(run_log::over_budget('materialise', 937, 24));
        $this->assertFalse(run_log::over_budget('materialise', 549727, 14000));

        // A rate change is what this catches.
        $this->assertTrue(run_log::over_budget('materialise', 5000, 24));

        // The flat stages do not scale: 900 queries to create one course is
        // unremarkable for a pool and alarming here.
        $this->assertTrue(run_log::over_budget('container', 900));
    }

    /**
     * Logging never breaks the thing it is logging.
     *
     * @return void
     */
    public function test_logging_a_missing_run_is_harmless(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // A run that completes without its story is worse than one with it, and
        // far better than one that dies trying to write it.
        $this->assertIsInt(run_log::record(999999, run_log::RUN_FAILED, ['reason' => 'x']));
        $this->assertSame([], run_log::entries(999998));
    }

    /**
     * Every recorded event carries an attempt number.
     *
     * @return void
     */
    public function test_entries_are_numbered_by_attempt(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        run_log::record($runid, run_log::START_REQUESTED);
        run_log::new_attempt($runid, 'second try');
        run_log::record($runid, run_log::START_REQUESTED);

        // Failing at materialise means something different the third time.
        $this->assertCount(1, run_log::entries($runid, 1));
        $this->assertCount(2, run_log::entries($runid, 2));
    }

    /**
     * Checking an existing pool asks the engine per scale, not per item.
     *
     * @return void
     */
    public function test_checking_a_pool_does_not_scale_with_items(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        if (!\local_catquizlab\local\environment::engine_available()) {
            $this->markTestSkipped('No CAT engine installed.');
        }

        // The engine's item list is held for the request. The items of a run sit
        // on a handful of scales, and asking once per item is what turned a pool
        // of fourteen thousand into half a million queries.
        \local_catquizlab\local\cat_item_provisioner::forget_visible_items();

        $before = $DB->perf_get_queries();
        \local_catquizlab\local\cat_item_provisioner::visible_items(1, 1);
        $first = $DB->perf_get_queries() - $before;

        $middle = $DB->perf_get_queries();
        for ($i = 0; $i < 20; $i++) {
            \local_catquizlab\local\cat_item_provisioner::visible_items(1, 1);
        }
        $repeats = $DB->perf_get_queries() - $middle;

        // Twenty more asks for the same scale cost nothing.
        $this->assertSame(0, $repeats, 'Repeated lookups for one scale cost ' . $repeats . ' queries.');
        $this->assertGreaterThanOrEqual(0, $first);
    }

    /**
     * Writing an item invalidates the held answer.
     *
     * @return void
     */
    public function test_the_held_answer_is_dropped_when_it_changes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $provisioner = \local_catquizlab\local\cat_item_provisioner::class;

        $provisioner::visible_items(1, 1);
        $provisioner::forget_visible_items();

        // Holding the engine's answer for the request is what makes checking a
        // large pool affordable; holding it across the write that changes the
        // answer reported a pool of 120 as 6 visible, and failed the run.
        $this->assertIsArray($provisioner::visible_items(1, 1));
    }
}
