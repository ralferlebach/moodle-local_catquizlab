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
 * Tests that the experiment definition actually drives the run.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\item_registrar;
use local_catquizlab\local\materialiser;
use local_catquizlab\local\pool_mutator;
use local_catquizlab\local\pool_planner;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\test_provisioner;

/**
 * Definition-to-run tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\pool_planner
 * @covers     \local_catquizlab\local\materialiser
 * @covers     \local_catquizlab\local\test_provisioner
 * @covers     \local_catquizlab\local\pool_mutator
 */
final class definition_drives_run_test extends \advanced_testcase {
    /**
     * A small definition for a given model.
     *
     * @param string $model The public model key.
     * @param array $modelparams Model parameters.
     * @return array The normalised definition.
     */
    protected function definition(string $model, array $modelparams = []): array {
        $def = experiment_definition::example_baseline();
        $def['model'] = $model;
        $def['modelparams'] = $modelparams;
        $def['pool']['scales'] = ['categories' => 2, 'subcategories' => 2, 'itemspersubscale' => 5];

        return (new experiment_definition($def))->get_normalised();
    }

    /**
     * A scale map covering the two categories and two subscales.
     *
     * @return array[]
     */
    protected function scalemap(): array {
        $map = [];
        $id = 100;
        for ($c = 1; $c <= 2; $c++) {
            for ($s = 1; $s <= 2; $s++) {
                $map[] = [
                    'level'         => scale_provisioner::LEVEL_SUBSCALE,
                    'categoryindex' => $c,
                    'subscaleindex' => $s,
                    'catscaleid'    => $id++,
                    'contextid'     => 7,
                    'name'          => 'C' . $c . '-S' . $s,
                ];
            }
        }
        return $map;
    }

    /**
     * Flatten a blueprint to its items.
     *
     * @param array $blueprint The blueprint.
     * @return array[]
     */
    protected function items(array $blueprint): array {
        $items = [];
        foreach ($blueprint['categories'] as $category) {
            foreach ($category['subscales'] as $subscale) {
                foreach ($subscale['items'] as $item) {
                    $items[] = $item;
                }
            }
        }
        return $items;
    }

    /**
     * A 1PL pool is controlled: a is one and c is zero throughout.
     *
     * @return void
     */
    public function test_1pl_pool_is_controlled(): void {
        $blueprint = pool_planner::plan($this->definition('1pl'), 42);

        foreach ($this->items($blueprint) as $item) {
            $this->assertSame(1.0, $item['discrimination']);
            $this->assertSame(0.0, $item['guessing']);
        }
    }

    /**
     * A 2PL pool draws item-specific discriminations from the declared distribution.
     *
     * @return void
     */
    public function test_2pl_pool_has_varying_discrimination(): void {
        $definition = $this->definition('2pl', [
            'discrimination' => ['dist' => 'lognormal', 'meanlog' => 0.0, 'sdlog' => 0.3],
        ]);
        $blueprint = pool_planner::plan($definition, 42);

        $values = array_column($this->items($blueprint), 'discrimination');

        // The regression this guards: a 2PL run materialised with a = 1
        // everywhere is a 1PL run wearing a 2PL label.
        $this->assertGreaterThan(1, count(array_unique($values)));
        foreach ($values as $value) {
            $this->assertGreaterThan(0.0, $value);
        }
    }

    /**
     * A 3PL pool additionally draws a guessing parameter.
     *
     * @return void
     */
    public function test_3pl_pool_has_guessing(): void {
        $definition = $this->definition('3pl', [
            'discrimination' => ['dist' => 'lognormal', 'meanlog' => 0.0, 'sdlog' => 0.3],
            'guessing'       => ['dist' => 'uniform', 'min' => 0.1, 'max' => 0.25],
        ]);
        $blueprint = pool_planner::plan($definition, 42);

        foreach ($this->items($blueprint) as $item) {
            $this->assertGreaterThanOrEqual(0.1, $item['guessing']);
            $this->assertLessThanOrEqual(0.25, $item['guessing']);
        }
    }

    /**
     * A polytomous pool carries ordered step thresholds.
     *
     * @return void
     */
    public function test_gpcm_pool_has_ordered_steps(): void {
        $definition = $this->definition('gpcm', [
            'discrimination' => ['dist' => 'constant', 'value' => 1.2],
            'categories'     => 5,
            'stepspacing'    => ['dist' => 'constant', 'value' => 0.8],
        ]);
        $blueprint = pool_planner::plan($definition, 42);

        foreach ($this->items($blueprint) as $item) {
            $this->assertArrayHasKey('steps', $item);
            $this->assertCount(4, $item['steps']);

            $sorted = $item['steps'];
            sort($sorted);
            $this->assertSame($sorted, $item['steps'], 'Thresholds must be ascending.');
        }
    }

    /**
     * Identical seeds reproduce identical pools; different seeds do not.
     *
     * @return void
     */
    public function test_pool_generation_is_seed_deterministic(): void {
        $definition = $this->definition('2pl', [
            'discrimination' => ['dist' => 'lognormal', 'meanlog' => 0.0, 'sdlog' => 0.3],
        ]);

        $a = pool_planner::plan($definition, 4242);
        $b = pool_planner::plan($definition, 4242);
        $c = pool_planner::plan($definition, 9999);

        $this->assertSame($a, $b);
        $this->assertNotSame(
            array_column($this->items($a), 'discrimination'),
            array_column($this->items($c), 'discrimination')
        );
    }

    /**
     * The materialiser hands the engine the model's own catmodel key.
     *
     * @return void
     */
    public function test_materialiser_uses_the_model_engine_key(): void {
        $definition = $this->definition('3pl', [
            'discrimination' => ['dist' => 'constant', 'value' => 1.4],
            'guessing'       => ['dist' => 'constant', 'value' => 0.2],
        ]);
        $blueprint = pool_mutator::mutate(pool_planner::plan($definition, 1), 'ideal', [], 1);

        $specs = materialiser::plan_items($blueprint, $this->scalemap(), ['model' => '3pl']);

        $this->assertNotEmpty($specs);
        foreach ($specs as $spec) {
            $this->assertSame('mixedraschbirnbaum', $spec['model']);
            $this->assertSame(1.4, $spec['discrimination']);
            $this->assertSame(0.2, $spec['guessing']);
        }
    }

    /**
     * A GPCM run is not materialised as a graded-response model.
     *
     * @return void
     */
    public function test_gpcm_is_not_materialised_as_grm(): void {
        $definition = $this->definition('gpcm', [
            'discrimination' => ['dist' => 'constant', 'value' => 1.0],
            'categories'     => 4,
        ]);
        $blueprint = pool_mutator::mutate(pool_planner::plan($definition, 1), 'ideal', [], 1);

        $specs = materialiser::plan_items($blueprint, $this->scalemap(), ['model' => 'gpcm']);

        foreach ($specs as $spec) {
            $this->assertSame('pcmgeneralized', $spec['model']);
            $this->assertNotSame('grmgeneralized', $spec['model']);
            $this->assertNotEmpty($spec['steps']);
        }
    }

    /**
     * The registrar writes the engine key of the model it was given.
     *
     * @return void
     */
    public function test_registrar_respects_the_model(): void {
        $param = item_registrar::build_itemparam(5, 7, ['model' => 'gpcm', 'difficulty' => 0.5]);

        $this->assertSame('pcmgeneralized', $param['model']);
    }

    /**
     * A calibration error separates the truth from what the engine is told.
     *
     * @return void
     */
    public function test_calibration_error_separates_truth_from_engine_view(): void {
        $definition = $this->definition('2pl');
        $blueprint = pool_mutator::mutate(
            pool_planner::plan($definition, 1),
            'calibrationerror',
            ['fraction' => 1.0, 'sd' => 0.8],
            3
        );

        $specs = materialiser::plan_items($blueprint, $this->scalemap(), ['model' => '2pl']);

        $differing = 0;
        foreach ($specs as $spec) {
            $this->assertTrue($spec['miscalibrated']);
            if (abs($spec['difficulty'] - $spec['truedifficulty']) > 0.0001) {
                $differing++;
            }
        }
        $this->assertGreaterThan(0, $differing);
    }

    /**
     * A tagging error binds the item to the wrong engine scale while the true
     * placement survives for the oracle.
     *
     * @return void
     */
    public function test_tagging_error_separates_assigned_from_true_scale(): void {
        $definition = $this->definition('2pl');
        $blueprint = pool_mutator::mutate(
            pool_planner::plan($definition, 1),
            'taggingerror',
            ['fraction' => 1.0],
            3
        );

        $specs = materialiser::plan_items($blueprint, $this->scalemap(), ['model' => '2pl']);

        $mistagged = array_filter($specs, static fn(array $spec): bool => $spec['mistagged']);
        $this->assertNotEmpty($mistagged);
        foreach ($mistagged as $spec) {
            // The engine sees the wrong scale; the true one is still recorded,
            // which is what keeps the condition from cancelling itself out.
            $this->assertNotSame($spec['catscaleid'], $spec['truecatscaleid']);
        }
    }

    /**
     * An ideal pool leaves both views identical.
     *
     * @return void
     */
    public function test_ideal_pool_has_no_divergence(): void {
        $definition = $this->definition('2pl');
        $blueprint = pool_mutator::mutate(pool_planner::plan($definition, 1), 'ideal', [], 1);

        foreach (materialiser::plan_items($blueprint, $this->scalemap(), ['model' => '2pl']) as $spec) {
            $this->assertSame($spec['difficulty'], $spec['truedifficulty']);
            $this->assertSame($spec['catscaleid'], $spec['truecatscaleid']);
            $this->assertFalse($spec['miscalibrated']);
            $this->assertFalse($spec['mistagged']);
        }
    }

    /**
     * Two cells differing only in strategy produce different CAT settings.
     *
     * @return void
     */
    public function test_strategy_reaches_the_cat_configuration(): void {
        $classic = $this->definition('2pl');
        $classic['strategy'] = 'classic';
        $lowest = $classic;
        $lowest['strategy'] = 'lowestsub';

        $a = test_provisioner::build_quizsettings(
            'A',
            1,
            [2],
            test_provisioner::options_from_definition($classic)
        );
        $b = test_provisioner::build_quizsettings(
            'B',
            1,
            [2],
            test_provisioner::options_from_definition($lowest)
        );

        $this->assertNotSame(
            $a['catquiz_selectteststrategy'],
            $b['catquiz_selectteststrategy']
        );
        // The specific regression: classic must not silently become 4.
        $this->assertSame('7', $a['catquiz_selectteststrategy']);
        $this->assertSame('4', $b['catquiz_selectteststrategy']);
    }

    /**
     * Budgets and SE bounds reach the CAT configuration from the definition.
     *
     * @return void
     */
    public function test_budgets_and_se_reach_the_cat_configuration(): void {
        $definition = $this->definition('2pl');
        $definition['budgets'] = [
            'global'   => ['minitems' => 20, 'maxitems' => 25],
            'subscale' => ['minitems' => 3, 'maxitems' => 5],
            'se'       => ['min' => 0.30, 'max' => 0.75],
        ];
        $definition = (new experiment_definition($definition))->get_normalised();

        $settings = test_provisioner::build_quizsettings(
            'Demo',
            1,
            [2],
            test_provisioner::options_from_definition($definition)
        );

        $this->assertSame(20, $settings['maxquestionsgroup']['catquiz_minquestions']);
        $this->assertSame(25, $settings['maxquestionsgroup']['catquiz_maxquestions']);
        $this->assertSame(3, $settings['maxquestionsscalegroup']['catquiz_minquestionspersubscale']);
        $this->assertSame(5, $settings['maxquestionsscalegroup']['catquiz_maxquestionspersubscale']);
        $this->assertSame(0.30, $settings['catquiz_standarderrorgroup']['catquiz_standarderror_min']);
        $this->assertSame(0.75, $settings['catquiz_standarderrorgroup']['catquiz_standarderror_max']);
    }

    /**
     * Two cells differing only in their budgets produce different settings.
     *
     * @return void
     */
    public function test_budget_cells_differ(): void {
        $small = $this->definition('2pl');
        $small['budgets'] = [
            'global'   => ['minitems' => 10, 'maxitems' => 15],
            'subscale' => ['minitems' => 2, 'maxitems' => 3],
            'se'       => ['min' => 0.35, 'max' => 1.0],
        ];
        $large = $small;
        $large['budgets']['global'] = ['minitems' => 40, 'maxitems' => 60];

        $a = test_provisioner::options_from_definition(
            (new experiment_definition($small))->get_normalised()
        );
        $b = test_provisioner::options_from_definition(
            (new experiment_definition($large))->get_normalised()
        );

        $this->assertNotSame($a['maxquestions'], $b['maxquestions']);
    }

    /**
     * The effective parameters record the information target the SE bounds imply.
     *
     * @return void
     */
    public function test_effective_parameters_document_the_information_target(): void {
        $definition = $this->definition('2pl');
        $definition['budgets']['se'] = ['min' => 0.5, 'max' => 1.0];
        $definition = (new experiment_definition($definition))->get_normalised();

        $effective = test_provisioner::effective_parameters($definition);

        // I = 1 / SE^2, so SE 0.5 needs information 4 and SE 1.0 needs 1.
        $this->assertSame(4.0, $effective['targetinformation']['max']);
        $this->assertSame(1.0, $effective['targetinformation']['min']);
        $this->assertSame('Fixed-form baseline', $effective['strategy']['label']);
    }
}
