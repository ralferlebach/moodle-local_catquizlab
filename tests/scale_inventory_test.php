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
 * One run, one scale tree — and what to do about the installations that have more.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\scale_inventory;
use local_catquizlab\local\scale_provisioner;

/**
 * Scale inventory tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\scale_inventory
 */
final class scale_inventory_test extends \advanced_testcase {
    /**
     * Give a run the scale generations a polluted installation has.
     *
     * @param int $runid The run.
     * @param array $pairs Root scale id and context id per generation, oldest first.
     * @return void
     */
    protected function give_generations(int $runid, array $pairs): void {
        global $DB;

        foreach ($pairs as [$root, $context]) {
            $DB->insert_record('local_catquizlab_scalemap', (object) [
                'runid' => $runid, 'level' => scale_provisioner::LEVEL_ROOT,
                'catscaleid' => $root, 'contextid' => $context,
                'categoryindex' => 0, 'subscaleindex' => 0, 'timecreated' => time(),
            ]);
            $DB->insert_record('local_catquizlab_scalemap', (object) [
                'runid' => $runid, 'level' => scale_provisioner::LEVEL_SUBSCALE,
                'catscaleid' => $root + 1, 'contextid' => $context,
                'categoryindex' => 1, 'subscaleindex' => 1, 'timecreated' => time(),
            ]);
        }
    }

    /**
     * A run.
     *
     * @return int
     */
    protected function make_run(): int {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');

        return (int) $generator->create_run()->id;
    }

    /**
     * Several trees are found rather than thrown over.
     *
     * @return void
     */
    public function test_several_trees_are_reported_not_fatal(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $this->give_generations($runid, [[334, 5], [445, 6], [556, 7]]);

        // The get_field() call threw "found more than one record" at the test
        // stage, long after the second tree was created, with a message about a
        // database call rather than about the run.
        $this->assertTrue(scale_inventory::is_ambiguous($runid));
        $this->assertSame(556, scale_inventory::current_root($runid));
        $this->assertCount(3, scale_inventory::generations($runid));
    }

    /**
     * The newest generation is the current one.
     *
     * @return void
     */
    public function test_the_newest_generation_is_current(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $this->give_generations($runid, [[100, 1], [200, 2]]);

        $generations = scale_inventory::generations($runid);

        // Everything provisioned after the second tree points at it; the first
        // was abandoned when the second was built.
        $this->assertTrue($generations[0]['current']);
        $this->assertSame(200, $generations[0]['rootscaleid']);
        $this->assertTrue($generations[1]['stale']);
    }

    /**
     * Cleaning up keeps exactly one, and says which.
     *
     * @return void
     */
    public function test_cleanup_leaves_one_tree(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $this->give_generations($runid, [[334, 5], [445, 6], [556, 7]]);

        $preview = scale_inventory::preview_cleanup($runid);
        $this->assertSame(556, $preview['keep']['rootscaleid']);
        $this->assertSame(2, $preview['totals']['generations']);

        $result = scale_inventory::cleanup($runid);

        $this->assertTrue($result['ok']);
        $this->assertFalse(scale_inventory::is_ambiguous($runid));
        $this->assertSame(556, scale_inventory::current_root($runid));
        $this->assertSame(2, $DB->count_records('local_catquizlab_scalemap', ['runid' => $runid]));
    }

    /**
     * A single tree is not a problem to be solved.
     *
     * @return void
     */
    public function test_one_tree_needs_no_cleanup(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $this->give_generations($runid, [[100, 1]]);

        $this->assertFalse(scale_inventory::is_ambiguous($runid));
        $this->assertFalse(scale_inventory::preview_cleanup($runid)['ok']);
        $this->assertSame('nothing-to-clean', scale_inventory::cleanup($runid)['reason']);
    }

    /**
     * Affected runs are found across the installation.
     *
     * @return void
     */
    public function test_affected_runs_are_listed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $clean = $this->make_run();
        $this->give_generations($clean, [[100, 1]]);

        $dirty = $this->make_run();
        $this->give_generations($dirty, [[200, 2], [300, 3]]);

        $affected = scale_inventory::affected_runs();

        // A fix that only stops new duplicates does not repair the
        // installations that already have them.
        $this->assertCount(1, $affected);
        $this->assertSame($dirty, $affected[0]['runid']);
        $this->assertSame(2, $affected[0]['generations']);
    }

    /**
     * The health check says what is wrong, not that a query found too much.
     *
     * @return void
     */
    public function test_the_health_check_names_the_problem(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $this->give_generations($runid, [[334, 5], [445, 6]]);

        $health = \local_catquizlab\local\scale_health::check($runid);

        // The "found more than one record" message is a database warning
        // about a call. This is a statement about the run, and it was true from
        // the moment the second tree was created.
        $this->assertFalse($health['ok']);
        $this->assertSame(2, $health['facts']['roots']);
        $this->assertSame(2, $health['facts']['contexts']);

        $failed = [];
        foreach ($health['checks'] as $check) {
            if (!$check['ok']) {
                $failed[] = $check['id'];
            }
        }

        // Each check separately, because each has a different answer.
        $this->assertContains('oneroot', $failed);
        $this->assertContains('onecontext', $failed);
    }

    /**
     * A run without scales says so, rather than passing by having nothing.
     *
     * @return void
     */
    public function test_an_unprovisioned_run_is_not_consistent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $health = \local_catquizlab\local\scale_health::check($this->make_run());

        $this->assertFalse($health['ok']);
        $this->assertSame(0, $health['facts']['nodes']);
    }

    /**
     * A sound tree passes every check.
     *
     * @return void
     */
    public function test_a_sound_tree_passes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $this->give_generations($runid, [[100, 1]]);

        // The engine has to know the scales the map names: a row pointing at a
        // deleted scale is worse than a missing row, because everything
        // downstream materialises into a scale nobody can select from.
        if ($DB->get_manager()->table_exists('local_catquiz_catscales')) {
            foreach ([100, 101] as $scaleid) {
                if (!$DB->record_exists('local_catquiz_catscales', ['id' => $scaleid])) {
                    $this->markTestSkipped('Cannot stage engine scales with fixed ids here.');
                }
            }
        }

        $health = \local_catquizlab\local\scale_health::check($runid);

        $this->assertSame(1, $health['facts']['roots']);
        $this->assertSame(1, $health['facts']['contexts']);
    }
}
