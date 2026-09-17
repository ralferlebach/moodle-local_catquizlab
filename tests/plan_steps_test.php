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
 * Defining an experiment, as the eight decisions it is.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\plan_steps;

/**
 * Plan step tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\plan_steps
 */
final class plan_steps_test extends \advanced_testcase {
    /**
     * A new experiment has made none of the decisions.
     *
     * @return void
     */
    public function test_a_new_experiment_starts_at_the_first_step(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $state = plan_steps::state(0);

        $this->assertSame(0, $state['done']);
        $this->assertSame(8, $state['total']);
        $this->assertFalse($state['ready']);

        // The first thing to do, said rather than left to be inferred from a
        // form that asks for everything at once.
        $this->assertStringContainsString('1.', $state['nextlabel']);
    }

    /**
     * A partly defined experiment says which decision is still open.
     *
     * @return void
     */
    public function test_it_names_the_next_open_decision(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $generator->create_experiment()->id;

        // Named and given a strategy, and nothing else.
        $DB->set_field('local_catquizlab_experiment', 'configjson', json_encode([
            'name'     => 'Half done',
            'strategy' => 'classic',
            'models'   => ['raschbirnbaum'],
        ]), ['id' => $experimentid]);

        $state = plan_steps::state($experimentid);

        $this->assertSame(2, $state['done']);
        $this->assertFalse($state['ready']);
        $this->assertStringContainsString('3.', $state['nextlabel']);
    }

    /**
     * Validation is the decisions, not a separate ceremony.
     *
     * @return void
     */
    public function test_validation_follows_from_the_decisions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $generator->create_experiment()->id;

        $DB->set_field('local_catquizlab_experiment', 'configjson', json_encode([
            'name'         => 'Complete',
            'strategy'     => 'classic',
            'models'       => ['raschbirnbaum'],
            'pool'         => ['scales' => ['categories' => 2, 'subcategories' => 3]],
            'persons'      => ['count' => 10],
            'budgets'      => ['global' => ['minitems' => 4, 'maxitems' => 12]],
            'replications' => 2,
        ]), ['id' => $experimentid]);

        $state = plan_steps::state($experimentid);

        // Six decisions made means it validates: there is nothing separate to
        // check, and a seventh spinner would be theatre.
        $this->assertTrue($state['ready']);
        $this->assertSame(7, $state['done']);

        // The runs are the eighth and are not made by defining anything.
        $this->assertFalse($state['hasruns']);
        $this->assertStringContainsString('8.', $state['nextlabel']);
    }

    /**
     * Once runs exist, the plan points at them.
     *
     * @return void
     */
    public function test_existing_runs_point_at_the_progress_step(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);

        $state = plan_steps::state($experimentid);

        // A plan that ends without saying where the work continues makes the
        // form feel like it ended before the work did.
        $this->assertTrue($state['hasruns']);
        $this->assertStringContainsString('runs.php', $state['progressurl']);
    }
}
