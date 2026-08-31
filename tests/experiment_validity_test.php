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
 * Tests that a run executes its own cell, and that ground truth stays out of
 * the estimated diagnosis.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\run_orchestrator;
use local_catquizlab\local\subscale_evaluator;
use local_catquizlab\local\test_provisioner;

/**
 * Experiment-validity tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\run_orchestrator
 * @covers     \local_catquizlab\local\subscale_evaluator
 */
final class experiment_validity_test extends \advanced_testcase {
    /**
     * Read the definition a run would be set up with.
     *
     * @param int $runid The run.
     * @return array The normalised definition.
     */
    protected function effective_definition(int $runid): array {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
        $method = new \ReflectionMethod(run_orchestrator::class, 'definition_for');
        $method->setAccessible(true);

        return $method->invoke(null, $run);
    }

    /**
     * Expand a two-strategy sweep and return its run ids.
     *
     * @return int[] The run ids, in order.
     */
    protected function swept_runs(): array {
        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Validity demo';
        $definition['strategy'] = 'fastest';
        $definition['sweep'] = ['factors' => [
            'strategy' => ['fastest', 'lowestsub'],
            'variant'  => ['ideal', 'shifted'],
        ]];
        // No recipe on the base definition: a variant sweep changes the variant
        // but not the recipe, and the ideal pool accepts no recipe keys. The
        // shifted cells therefore use the documented default shift.

        $experimentid = (int) experiment_service::save($definition)['id'];

        return experiment_service::create_sweep($experimentid)['runs'];
    }

    /**
     * Each run is set up with its own cell, not the base definition.
     *
     * @return void
     */
    public function test_each_run_uses_its_own_cell(): void {
        $this->resetAfterTest();

        $runids = $this->swept_runs();
        $strategies = [];
        $variants = [];
        foreach ($runids as $runid) {
            $definition = $this->effective_definition($runid);
            $strategies[] = $definition['strategy'];
            $variants[] = $definition['pool']['variant'];
        }

        // Reading the experiment's base definition back would give every run
        // the same strategy and variant, so the cell key would document an
        // intervention that never happened.
        $this->assertSame(['fastest', 'lowestsub'], array_values(array_unique($strategies)));
        $this->assertContains('shifted', $variants);
        $this->assertContains('ideal', $variants);
    }

    /**
     * Two runs differing only in strategy produce different CAT settings.
     *
     * @return void
     */
    public function test_strategy_from_the_cell_reaches_the_cat_configuration(): void {
        $this->resetAfterTest();

        $settings = [];
        foreach ($this->swept_runs() as $runid) {
            $definition = $this->effective_definition($runid);
            $options = test_provisioner::options_from_definition($definition);
            $settings[$definition['strategy']] = $options['teststrategy'];
        }

        $this->assertCount(2, $settings);
        $this->assertNotSame($settings['fastest'], $settings['lowestsub']);
    }

    /**
     * A run without a manifested cell falls back to the base definition.
     *
     * @return void
     */
    public function test_legacy_run_without_a_cell_definition(): void {
        global $DB;
        $this->resetAfterTest();

        $runid = $this->swept_runs()[0];
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
        $this->assertTrue(run_orchestrator::has_cell_definition($run));

        // A run provisioned before manifests carried a cell definition: the
        // base definition is all there is, and it is used rather than failing.
        $DB->set_field('local_catquizlab_run', 'manifestjson', json_encode(['config' => []]), ['id' => $runid]);
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);

        $this->assertFalse(run_orchestrator::has_cell_definition($run));
        $this->assertNotEmpty($this->effective_definition($runid)['strategy']);
    }

    /**
     * A configuration that contradicts the manifest is a hard failure.
     *
     * @return void
     */
    public function test_manifest_drift_is_detected(): void {
        global $DB;
        $this->resetAfterTest();

        $runid = $this->swept_runs()[0];
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
        $definition = $this->effective_definition($runid);

        $this->assertSame([], run_orchestrator::manifest_drift($run, $definition));

        // The manifest is what a later reader treats as the description of the
        // intervention; results attributed to conditions that did not hold are
        // worse than no results.
        $definition['strategy'] = 'classic';
        $drift = run_orchestrator::manifest_drift($run, $definition);

        $this->assertNotEmpty($drift);
        $this->assertStringContainsString('strategy', $drift[0]);
    }

    /**
     * The estimated diagnosis does not move when only the ground truth moves.
     *
     * @return void
     */
    public function test_ground_truth_does_not_leak_into_the_estimate(): void {
        $this->resetAfterTest();

        $scalemap = [11 => '1:1', 12 => '1:2', 13 => '1:3', 14 => '1:4'];
        $estimates = [11 => -1.0, 12 => -0.2, 13 => 0.3, 14 => 1.1];

        $profile = static function (float $global): array {
            return [
                'global'     => $global,
                'categories' => [[
                    'index'     => 1,
                    'theta'     => $global,
                    'subscales' => [
                        ['index' => 1, 'theta' => $global - 1.0],
                        ['index' => 2, 'theta' => $global - 0.2],
                        ['index' => 3, 'theta' => $global + 0.3],
                        ['index' => 4, 'theta' => $global + 1.1],
                    ],
                ]],
            ];
        };

        // Same estimate, two different true global abilities. The estimated
        // deviations must be identical: they are a property of the estimate.
        $first = subscale_evaluator::evaluate_person($profile(0.0), $estimates, $scalemap, ['estglobal' => 0.05]);
        $second = subscale_evaluator::evaluate_person($profile(2.5), $estimates, $scalemap, ['estglobal' => 0.05]);

        $this->assertSame($first['estdeltas'], $second['estdeltas']);
    }

    /**
     * The estimated deviations follow the estimated global ability.
     *
     * @return void
     */
    public function test_estimated_deltas_follow_the_estimated_global(): void {
        $this->resetAfterTest();

        $scalemap = [11 => '1:1', 12 => '1:2'];
        $estimates = [11 => 0.0, 12 => 1.0];
        $profile = [
            'global'     => 0.0,
            'categories' => [[
                'index'     => 1,
                'theta'     => 0.0,
                'subscales' => [['index' => 1, 'theta' => 0.0], ['index' => 2, 'theta' => 1.0]],
            ]],
        ];

        $low = subscale_evaluator::evaluate_person($profile, $estimates, $scalemap, ['estglobal' => 0.0]);
        $high = subscale_evaluator::evaluate_person($profile, $estimates, $scalemap, ['estglobal' => 1.0]);

        $this->assertSame([0.0, 1.0], $low['estdeltas']);
        $this->assertSame([-1.0, 0.0], $high['estdeltas']);
        // The true deviations are untouched by the estimate.
        $this->assertSame($low['truedeltas'], $high['truedeltas']);
    }

    /**
     * The true deviations come from ground truth alone.
     *
     * @return void
     */
    public function test_true_deltas_come_from_ground_truth(): void {
        $this->resetAfterTest();

        $scalemap = [11 => '1:1', 12 => '1:2'];
        $profile = [
            'global'     => 0.5,
            'categories' => [[
                'index'     => 1,
                'theta'     => 0.5,
                'subscales' => [['index' => 1, 'theta' => -0.5], ['index' => 2, 'theta' => 1.5]],
            ]],
        ];

        $result = subscale_evaluator::evaluate_person(
            $profile,
            [11 => 9.0, 12 => -9.0],
            $scalemap,
            ['estglobal' => 0.0]
        );

        // Wildly wrong estimates must not move the truth by a single logit.
        $this->assertSame([-1.0, 1.0], $result['truedeltas']);
    }
}
