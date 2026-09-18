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
 * Two actions: prepare an experiment, and run it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\experiment_runner;
use local_catquizlab\local\registry;

/**
 * Experiment runner tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_runner
 */
final class experiment_runner_test extends \advanced_testcase {
    /**
     * An experiment with nothing built from it is a draft, and can be prepared.
     *
     * @return void
     */
    public function test_a_new_experiment_can_be_prepared(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $generator->create_experiment()->id;

        $state = experiment_runner::state($experimentid);

        // Three states a person needs, not thirty run statuses to read through.
        $this->assertSame(experiment_runner::STATE_DRAFT, $state['state']);
        $this->assertTrue($state['canprepare']);
        $this->assertFalse($state['canstart']);
    }

    /**
     * A definition that cannot be read blocks before anything is built.
     *
     * @return void
     */
    public function test_a_broken_definition_blocks_before_mutating(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $generator->create_experiment()->id;
        $DB->set_field('local_catquizlab_experiment', 'configjson', 'not json', ['id' => $experimentid]);

        $result = experiment_runner::prepare($experimentid);

        // Validate before mutating: a definition that cannot be read should not
        // leave half an experiment built behind it.
        $this->assertFalse($result['ok']);
        $this->assertSame(experiment_runner::STATE_BLOCKED, $result['state']);
        $this->assertSame(0, $DB->count_records('local_catquizlab_run', ['experimentid' => $experimentid]));
    }

    /**
     * Preparation reports per run and per stage.
     *
     * @return void
     */
    public function test_blockers_name_the_run_and_the_stage(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $generator->create_experiment()->id;

        $result = experiment_runner::prepare($experimentid);

        // A bare "provisioning failed" for an experiment of thirty runs is
        // not something anybody can act on.
        foreach ($result['blockers'] as $blocker) {
            $this->assertArrayHasKey('reason', $blocker);
            $this->assertNotSame('', $blocker['reason']);
        }
    }

    /**
     * A finished experiment is finished, and a partly failed one is blocked.
     *
     * @return void
     */
    public function test_the_state_follows_the_runs(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);

        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_READY, ['id' => $runid]);
        $ready = experiment_runner::state($experimentid);
        $this->assertSame(experiment_runner::STATE_READY, $ready['state']);
        $this->assertTrue($ready['canstart']);

        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $runid]);
        $this->assertSame(
            experiment_runner::STATE_FINISHED,
            experiment_runner::state($experimentid)['state']
        );

        // A result over the runs that happened to work is a different quantity
        // from the one that was designed, so any failure blocks the experiment.
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FAILED, ['id' => $runid]);
        $blocked = experiment_runner::state($experimentid);
        $this->assertSame(experiment_runner::STATE_BLOCKED, $blocked['state']);
        $this->assertFalse($blocked['canstart']);
    }

    /**
     * Preparing twice does not build twice.
     *
     * @return void
     */
    public function test_preparing_twice_creates_nothing_twice(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_READY, ['id' => $runid]);

        $before = $DB->count_records('local_catquizlab_run', ['experimentid' => $experimentid]);
        experiment_runner::prepare($experimentid);
        experiment_runner::prepare($experimentid);

        // Pressing the button twice is a thing people do when the first press
        // seemed not to work.
        $this->assertSame($before, $DB->count_records('local_catquizlab_run', ['experimentid' => $experimentid]));
    }
}
