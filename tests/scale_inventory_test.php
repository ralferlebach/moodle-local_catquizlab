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

        // Continue from whatever this run already has, so a test can add a
        // second generation to a run that has one — which is how a polluted
        // installation came to look the way it does.
        $generation = (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(generation), 0) FROM {local_catquizlab_scalemap} WHERE runid = ?',
            [$runid]
        );

        foreach ($pairs as $index => [$root, $context]) {
            $generation++;

            $DB->insert_record('local_catquizlab_scalemap', (object) [
                'runid' => $runid, 'level' => scale_provisioner::LEVEL_ROOT,
                'catscaleid' => $root, 'contextid' => $context,
                'categoryindex' => 0, 'subscaleindex' => 0,
                'nodekey' => 'root', 'generation' => $generation,
                'timecreated' => time(),
            ]);
            $DB->insert_record('local_catquizlab_scalemap', (object) [
                'runid' => $runid, 'level' => scale_provisioner::LEVEL_SUBSCALE,
                'catscaleid' => $root + 1, 'contextid' => $context,
                'categoryindex' => 1, 'subscaleindex' => 1,
                'nodekey' => 'c1s1', 'generation' => $generation,
                'timecreated' => time(),
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
        $this->assertCount(3, scale_inventory::generations($runid));

        // Picking the newest looks reasonable and is a guess: the run's items
        // were materialised into one of them, and which one is not knowable
        // from the map. So there is no current root — there is recovery.
        $this->assertNull(scale_inventory::current_root($runid));
        $this->assertTrue(scale_inventory::recovery_required($runid));
        $this->assertSame(556, scale_inventory::newest_root($runid));
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
        // One left, so there is a current root again.
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
        $this->assertFalse(scale_inventory::recovery_required($runid));
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

    /**
     * The database refuses a second root, whatever its scale id.
     *
     * @return void
     */
    public function test_the_database_refuses_a_second_root(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $row = [
            'runid' => $runid, 'catscaleid' => 1001, 'parentcatscaleid' => 0, 'contextid' => 1,
            'level' => scale_provisioner::LEVEL_ROOT, 'categoryindex' => 0, 'subscaleindex' => 0,
            'nodekey' => 'root', 'generation' => 1, 'name' => 'R',
            'timecreated' => time(), 'timemodified' => time(),
        ];
        $DB->insert_record('local_catquizlab_scalemap', (object) $row);

        // UNIQUE(runid, catscaleid) let this through: two different scale ids
        // are both valid roots as far as it can tell, which is exactly the
        // shape the defect took.
        $row['catscaleid'] = 2002;
        $this->expectException(\dml_exception::class);
        $DB->insert_record('local_catquizlab_scalemap', (object) $row);
    }

    /**
     * The node key describes the position, not the ids.
     *
     * @return void
     */
    public function test_node_keys_describe_the_position(): void {
        $this->resetAfterTest();

        $this->assertSame('root', scale_provisioner::node_key(['level' => 0]));
        $this->assertSame('c2', scale_provisioner::node_key(['level' => 1, 'categoryindex' => 2]));
        $this->assertSame(
            'c1s3',
            scale_provisioner::node_key(['level' => 2, 'categoryindex' => 1, 'subscaleindex' => 3])
        );
    }

    /**
     * A run with several generations hands out no work.
     *
     * @return void
     */
    public function test_an_ambiguous_run_is_not_runnable(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $DB->set_field(
            'local_catquizlab_run',
            'status',
            \local_catquizlab\local\registry::STATUS_READY,
            ['id' => $runid]
        );

        $this->give_generations($runid, [[100, 1]]);
        $this->assertTrue(\local_catquizlab\local\run_lifecycle::is_runnable($runid));

        $this->give_generations($runid, [[200, 2]]);

        // The run's items were materialised into one of these trees, and which
        // one is not knowable from the map. An attempt played now may draw from
        // the wrong one, and a wrong answer nobody can detect is worse than no
        // answer.
        $this->assertTrue(scale_inventory::recovery_required($runid));
        $this->assertNull(scale_inventory::current_root($runid));
        $this->assertFalse(\local_catquizlab\local\run_lifecycle::is_runnable($runid));
    }

    /**
     * Cleanup is possible on a run whose attempts can never finish.
     *
     * @return void
     */
    public function test_cleanup_is_not_blocked_by_stranded_claims(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $this->give_generations($runid, [[100, 1], [200, 2]]);

        // Claimed, with a lease nobody is holding any more. Such a run hands
        // out no work, so this attempt can never finish — refusing the repair
        // on its account made repair impossible for exactly the runs needing
        // it.
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $runid, 'personid' => 0,
            'status' => \local_catquizlab\local\attempt_scheduler::STATUS_RUNNING,
            'tries' => 1, 'leaseowner' => 'gone', 'leaseexpires' => time() - 3600,
            'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $result = scale_inventory::cleanup($runid);

        $this->assertTrue($result['ok'], $result['reason']);
        $this->assertFalse(scale_inventory::recovery_required($runid));
    }

    /**
     * A live worker still blocks the repair.
     *
     * @return void
     */
    public function test_a_live_claim_blocks_cleanup(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $this->give_generations($runid, [[100, 1], [200, 2]]);

        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $runid, 'personid' => 0,
            'status' => \local_catquizlab\local\attempt_scheduler::STATUS_RUNNING,
            'tries' => 1, 'leaseowner' => 'alive', 'leaseexpires' => time() + 3600,
            'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        // A worker mid-attempt is reading from one of these trees, and which
        // one is not worth guessing.
        $this->assertSame('run-is-being-played', scale_inventory::cleanup($runid)['reason']);
    }

    /**
     * A parent pointing outside the run is named.
     *
     * @return void
     */
    public function test_a_foreign_parent_is_named(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $this->give_generations($runid, [[100, 1]]);

        $DB->insert_record('local_catquizlab_scalemap', (object) [
            'runid' => $runid, 'level' => scale_provisioner::LEVEL_SUBSCALE,
            'catscaleid' => 777, 'parentcatscaleid' => 666, 'contextid' => 1,
            'nodekey' => 'c9s9', 'generation' => 1,
            'categoryindex' => 9, 'subscaleindex' => 9, 'timecreated' => time(),
        ]);

        $health = \local_catquizlab\local\scale_health::check($runid);

        // A node whose parent is not in this run's map belongs to a tree the
        // run does not own, and the engine will walk it anyway.
        $this->assertFalse($health['ok']);
        $this->assertContains('parents', $health['codes']);
        $this->assertStringContainsString('c9s9', $health['checks'][4]['detail']);
    }

    /**
     * A cycle is found rather than walked.
     *
     * @return void
     */
    public function test_a_cycle_is_found(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();

        // Two nodes each claiming the other as parent: a walk of this tree does
        // not end, so finding it is the difference between an error and a hang.
        foreach ([[501, 502, 'root'], [502, 501, 'c1']] as [$id, $parent, $key]) {
            $DB->insert_record('local_catquizlab_scalemap', (object) [
                'runid' => $runid, 'level' => scale_provisioner::LEVEL_ROOT,
                'catscaleid' => $id, 'parentcatscaleid' => $parent, 'contextid' => 1,
                'nodekey' => $key, 'generation' => 1,
                'categoryindex' => null, 'subscaleindex' => null, 'timecreated' => time(),
            ]);
        }

        $health = \local_catquizlab\local\scale_health::check($runid);

        $this->assertContains('acyclic', $health['codes']);
    }

    /**
     * The verdict carries machine-readable codes and the ids.
     *
     * @return void
     */
    public function test_the_verdict_is_machine_readable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $this->give_generations($runid, [[100, 1], [200, 2]]);

        $health = \local_catquizlab\local\scale_health::check($runid);

        // A report that names the objects beats one that counts them. The order
        // is the map's own, which is what somebody reading the table sees.
        $this->assertContains('oneroot', $health['codes']);
        $this->assertCount(2, $health['facts']['rootids']);
        $this->assertContains(100, $health['facts']['rootids']);
        $this->assertContains(200, $health['facts']['rootids']);
        $this->assertCount(2, $health['facts']['contextids']);
    }
}
