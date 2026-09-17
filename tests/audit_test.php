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
 * The claims the release gate makes, checked rather than asserted.
 *
 * The audit for #70 set the right standard: "practical functionality and usable
 * operation, not the mere presence of classes or strings or changelog claims".
 * A changelog says what somebody meant to do. This runs the thing.
 *
 * It is deliberately shallow — one check per claim, at the seam where the claim
 * would break — because its job is to notice a regression in a promise, not to
 * re-test what the focused suites already cover.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\debug_trace;
use local_catquizlab\local\scale_health;
use local_catquizlab\local\scale_inventory;
use local_catquizlab\output\shell;

/**
 * Release gate audit.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class audit_test extends \advanced_testcase {
    /**
     * The frame: one per request, selector under the tabs, a way to start one.
     *
     * @return void
     */
    public function test_the_shell_holds_its_promises(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // The selector only renders when there is something to select, which is
        // correct behaviour and means the test has to make one.
        $this->getDataGenerator()->get_plugin_generator('local_catquizlab')->create_experiment();

        shell::reset_for_testing();
        $first = shell::render('plan', 0);

        // RG-001: several pages call this from more than one branch.
        $this->assertSame('', shell::render('plan', 0));

        // Issue 72: above the tabs it read as a filter on the whole plugin.
        $this->assertGreaterThan(
            strpos($first, 'nav-tabs'),
            strpos($first, 'catquizlab-experiment'),
            'The experiment selector is above the tabs.'
        );

        // Issue 53: looking for the new-experiment link on another tab is how
        // somebody makes their second experiment by editing their first.
        $this->assertStringContainsString('experiment.php', $first);
    }

    /**
     * Preparation: six stages, and the PHP path where it belongs.
     *
     * @return void
     */
    public function test_preparation_is_six_stages(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $state = \local_catquizlab\local\setup_wizard::state();
        $this->assertCount(6, $state['stages']);

        $stageof = [];
        foreach ($state['stages'] as $stage) {
            foreach ($stage['steps'] as $step) {
                $stageof[$step['id']] = $stage['id'];
            }
        }

        // RG-004: an installation with working cron runs everything it needs
        // without this path, so it gates the pipeline and not the engine.
        $this->assertSame('pipeline', $stageof['phpcli'] ?? '');
    }

    /**
     * The scale invariant, refused by the database rather than by a check.
     *
     * @return void
     */
    public function test_the_scale_invariant_is_enforced(): void {
        global $DB;
        $this->resetAfterTest();

        $index = $DB->get_manager()->find_index_name(
            new \xmldb_table('local_catquizlab_scalemap'),
            new \xmldb_index('runnodekey', XMLDB_INDEX_UNIQUE, ['runid', 'generation', 'nodekey'])
        );

        // RG-007: UNIQUE(runid, catscaleid) could not express this.
        $this->assertNotFalse($index);

        // A run nobody can resolve gets recovery, not a guess.
        $this->assertNull(scale_inventory::current_root(0));
    }

    /**
     * Nine consistency checks, each with its own answer.
     *
     * @return void
     */
    public function test_scale_health_asks_the_whole_question(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $health = scale_health::check((int) $generator->create_run()->id);

        // RG-008: the node count alone reports a missing subscale and a stray
        // extra one as the same thing.
        $this->assertArrayHasKey('codes', $health);
    }

    /**
     * Observability: a log that survives, costs that are measured, one id.
     *
     * @return void
     */
    public function test_the_observability_promises_hold(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('debuglevel', 'verbose', 'local_catquizlab');
        debug_trace::reset_for_testing();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;

        $correlation = debug_trace::correlation_id();
        \local_catquizlab\local\run_log::record($runid, \local_catquizlab\local\run_log::START_REQUESTED);

        // RG-010/011: one click, one id, across both logs.
        $entries = \local_catquizlab\local\run_log::entries($runid);
        $last = end($entries);
        $this->assertSame($correlation, $last['correlationid']);
        $this->assertNotEmpty(debug_trace::entries(['correlationid' => $correlation]));
    }

    /**
     * Deleting is its own authority, and previews what it takes.
     *
     * @return void
     */
    public function test_deleting_is_guarded(): void {
        $this->resetAfterTest();

        $capability = get_capability_info('local/catquizlab:purge');

        // RG-006: somebody who may operate the lab can do their whole job
        // without ever being able to destroy a measurement.
        $this->assertNotEmpty($capability);
        $this->assertNotSame(0, (int) $capability->riskbitmask & RISK_DATALOSS);
    }
}
