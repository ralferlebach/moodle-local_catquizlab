<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\strategy_catalog;
use local_catquizlab\local\strategy_parameters;
use local_catquizlab\local\sweep;
use local_catquizlab\local\test_provisioner;

/**
 * Parameters apply only where the engine uses them (#101).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\strategy_parameters
 * @covers     \local_catquizlab\local\test_provisioner
 */
final class strategy_parameters_test extends \advanced_testcase {
    /**
     * The runs of a sweep over three strategies of different kinds.
     *
     * @return array<string, array> Strategy => run definition.
     */
    protected function cells(): array {
        $definition = experiment_definition::example_baseline();
        $definition['budgets']['global']['maxitems'] = 35;
        $cells = [];
        $plan = sweep::expand([
            'base'    => $definition,
            'factors' => ['strategy' => ['fastest', 'classic', 'allsubs']],
        ]);
        foreach ($plan['runs'] as $run) {
            $cells[$run['definition']['strategy']] = $run['definition'];
        }

        return $cells;
    }

    /**
     * The matrix answers through the accessors the issue names.
     *
     * @return void
     */
    public function test_the_named_capabilities(): void {
        $this->assertFalse(strategy_catalog::uses_subscale_min('fastest'));
        $this->assertFalse(strategy_catalog::uses_subscale_max('classic'));
        $this->assertFalse(strategy_catalog::uses_standard_error('classic'));
        $this->assertFalse(strategy_catalog::supports_pilot_items('classic'));
        $this->assertFalse(strategy_catalog::uses_first_question_policy('classic'));
        $this->assertTrue(strategy_catalog::fixed_form('classic'));
        // The classical test plays every item of the scale: no question budget.
        $this->assertFalse(strategy_catalog::uses_global_min('classic'));
        $this->assertFalse(strategy_catalog::uses_global_max('classic'));
        $this->assertTrue(strategy_catalog::uses_global_max('fastest'));
        $this->assertTrue(strategy_catalog::uses_standard_error('fastest'));
        $this->assertTrue(strategy_catalog::uses_subscale_max('allsubs'));
    }

    /**
     * A run stores what its strategy uses, and nothing else.
     *
     * @return void
     */
    public function test_a_run_stores_only_what_its_strategy_uses(): void {
        $this->resetAfterTest();
        $cells = $this->cells();

        $this->assertArrayHasKey('subscale', $cells['allsubs']['budgets']);
        $this->assertArrayHasKey('se', $cells['allsubs']['budgets']);

        $this->assertArrayNotHasKey('subscale', $cells['fastest']['budgets']);
        $this->assertArrayHasKey('se', $cells['fastest']['budgets']);

        $this->assertArrayNotHasKey('subscale', $cells['classic']['budgets']);
        $this->assertArrayNotHasKey('se', $cells['classic']['budgets']);

        // Per-strategy budgets are applied and then left out of the run.
        $this->assertArrayNotHasKey('budgetsbystrategy', $cells['allsubs']);
    }

    /**
     * The engine receives neutral stand-ins for what a strategy does not use.
     *
     * @return void
     */
    public function test_the_engine_gets_neutral_values_where_nothing_applies(): void {
        $this->resetAfterTest();
        $cells = $this->cells();

        $options = test_provisioner::options_from_definition($cells['fastest']);
        $fastest = test_provisioner::build_quizsettings('t', 1, [], $options);
        $scale = $fastest['maxquestionsscalegroup'];
        $this->assertSame(strategy_parameters::NEUTRAL_SUBSCALE_MAX, $scale['catquiz_maxquestionspersubscale']);
        $this->assertSame(strategy_parameters::NEUTRAL_SUBSCALE_MIN, $scale['catquiz_minquestionspersubscale']);
        // Its precision target is its own and stays.
        $this->assertSame(0.35, $fastest['catquiz_standarderrorgroup']['catquiz_standarderror_min']);

        $options = test_provisioner::options_from_definition($cells['classic']);
        $classic = test_provisioner::build_quizsettings('t', 1, [], $options);
        $se = $classic['catquiz_standarderrorgroup'];
        $scale = $classic['maxquestionsscalegroup'];
        $this->assertSame(strategy_parameters::NEUTRAL_SE_MIN, $se['catquiz_standarderror_min']);
        $this->assertSame(strategy_parameters::NEUTRAL_SUBSCALE_MAX, $scale['catquiz_maxquestionspersubscale']);

        $options = test_provisioner::options_from_definition($cells['allsubs']);
        $allsubs = test_provisioner::build_quizsettings('t', 1, [], $options);
        $this->assertSame(4, $allsubs['maxquestionsscalegroup']['catquiz_maxquestionspersubscale']);
    }

    /**
     * The run's manifest says what was neutralised.
     *
     * @return void
     */
    public function test_the_manifest_marks_what_was_neutralised(): void {
        $this->resetAfterTest();
        $cells = $this->cells();

        $classic = test_provisioner::effective_parameters($cells['classic']);
        $this->assertSame(strategy_parameters::NEUTRALISED, $classic['budgets']['subscale']);
        $this->assertSame(strategy_parameters::NEUTRALISED, $classic['se']);
        $this->assertSame(strategy_parameters::NEUTRALISED, $classic['firstquestion']);

        $fastest = test_provisioner::effective_parameters($cells['fastest']);
        $this->assertSame(strategy_parameters::NEUTRALISED, $fastest['budgets']['subscale']);
        $this->assertIsArray($fastest['se']);
    }

    /**
     * The stop rules reported are the ones in force.
     *
     * @return void
     */
    public function test_only_the_stop_rules_in_force_are_reported(): void {
        $this->resetAfterTest();
        $cells = $this->cells();
        $precision = get_string('stoprule:precision', 'local_catquizlab', format_float(0.35, 2));
        $persubscale = get_string('stoprule:subscalemaximum', 'local_catquizlab', 4);

        $classic = strategy_parameters::stop_rules(test_provisioner::effective_parameters($cells['classic']));
        $this->assertNotContains($precision, $classic);
        $this->assertNotContains($persubscale, $classic);

        $fastest = strategy_parameters::stop_rules(test_provisioner::effective_parameters($cells['fastest']));
        $this->assertContains($precision, $fastest);
        $this->assertNotContains($persubscale, $fastest);

        $allsubs = strategy_parameters::stop_rules(test_provisioner::effective_parameters($cells['allsubs']));
        $this->assertContains($precision, $allsubs);
        $this->assertContains($persubscale, $allsubs);
    }

    /**
     * A classical test needs no standard-error bounds to be valid.
     *
     * @return void
     */
    public function test_a_classical_test_needs_no_standard_error_bounds(): void {
        $this->resetAfterTest();

        $definition = experiment_definition::example_baseline();
        $definition['strategy'] = 'classic';
        unset($definition['budgets']['se'], $definition['budgets']['subscale'], $definition['sweep']);
        $result = (new experiment_definition($definition))->validate();
        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));

        // And a saved one keeps neither.
        $definition['budgets']['se'] = ['min' => 0.3, 'max' => 0.9];
        $normalised = (new experiment_definition($definition))->get_normalised();
        $this->assertArrayNotHasKey('se', $normalised['budgets']);
    }
}
