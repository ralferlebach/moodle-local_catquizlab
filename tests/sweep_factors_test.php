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
 * Tests for composite sweep factors and the run lifecycle.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\form\experiment_form;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\registry;
use local_catquizlab\local\sweep;

/**
 * Sweep-factor and lifecycle tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\sweep
 * @covers     \local_catquizlab\local\registry
 * @covers     \local_catquizlab\form\experiment_form
 */
final class sweep_factors_test extends \advanced_testcase {
    /**
     * A sweep specification over the given factors.
     *
     * @param array $factors The factors.
     * @return array The spec.
     */
    protected function spec(array $factors): array {
        return [
            'base'         => (new experiment_definition(experiment_definition::example_baseline()))
                ->get_normalised(),
            'factors'      => $factors,
            'replications' => 1,
            'seed'         => 42,
        ];
    }

    /**
     * A budget is one condition, not two independent ends.
     *
     * @return void
     */
    public function test_global_budget_is_swept_as_a_pair(): void {
        $this->resetAfterTest();

        $expansion = sweep::expand($this->spec([
            'globalbudget' => [
                ['minitems' => 10, 'maxitems' => 15],
                ['minitems' => 40, 'maxitems' => 45],
            ],
        ]));

        $this->assertCount(2, $expansion['cells']);

        $budgets = [];
        foreach ($expansion['runs'] as $run) {
            $budgets[] = $run['definition']['budgets']['global'];
        }

        // Varying the ends independently would also produce 10/45 and 40/15,
        // and the second describes nothing.
        $this->assertSame([10, 15], [$budgets[0]['minitems'], $budgets[0]['maxitems']]);
        $this->assertSame([40, 45], [$budgets[1]['minitems'], $budgets[1]['maxitems']]);
    }

    /**
     * The SE window is swept as a pair too.
     *
     * @return void
     */
    public function test_se_window_is_swept_as_a_pair(): void {
        $this->resetAfterTest();

        $expansion = sweep::expand($this->spec([
            'se' => [['min' => 0.30, 'max' => 0.75], ['min' => 0.45, 'max' => 1.0]],
        ]));

        $this->assertCount(2, $expansion['cells']);
        $this->assertEqualsWithDelta(
            0.30,
            $expansion['runs'][0]['definition']['budgets']['se']['min'],
            1e-9
        );
    }

    /**
     * A composite level must be a block, not a single value.
     *
     * @return void
     */
    public function test_composite_factor_rejects_a_scalar_level(): void {
        $this->resetAfterTest();

        $this->expectException(\invalid_parameter_exception::class);
        sweep::expand($this->spec(['globalbudget' => [10, 20]]));
    }

    /**
     * A budget level replaces the base budget rather than merging into it.
     *
     * @return void
     */
    public function test_budget_level_replaces_the_base(): void {
        $this->resetAfterTest();

        $spec = $this->spec(['globalbudget' => [['minitems' => 5, 'maxitems' => 7]]]);
        $expansion = sweep::expand($spec);
        $budget = $expansion['runs'][0]['definition']['budgets']['global'];

        // Merging would leave the base maximum of 250 in place beside the new
        // minimum, quietly producing a condition nobody asked for.
        $this->assertSame(['minitems' => 5, 'maxitems' => 7], $budget);
    }

    /**
     * Disturbance strength and variant are separate factors.
     *
     * @return void
     */
    public function test_strength_is_filtered_against_the_variant(): void {
        $this->resetAfterTest();

        $expansion = sweep::expand($this->spec([
            'variant' => ['ideal', 'calibrationerror'],
            'recipe'  => [['fraction' => 0.05], ['fraction' => 0.20]],
        ]));

        $this->assertCount(4, $expansion['cells']);

        foreach ($expansion['runs'] as $run) {
            $pool = $run['definition']['pool'];
            if ($pool['variant'] === 'ideal') {
                // The ideal pool accepts no recipe keys, so pairing it with a
                // strength drops the strength rather than invalidating a cell
                // the author plainly meant to include.
                $this->assertSame([], $pool['recipe']);
            } else {
                $this->assertArrayHasKey('fraction', $pool['recipe']);
            }
        }
    }

    /**
     * Every swept cell validates.
     *
     * @return void
     */
    public function test_swept_cells_are_valid(): void {
        $this->resetAfterTest();

        $expansion = sweep::expand($this->spec([
            'variant'      => ['ideal', 'depleted'],
            'recipe'       => [['fraction' => 0.5]],
            'globalbudget' => [['minitems' => 10, 'maxitems' => 15]],
        ]));

        foreach ($expansion['cells'] as $cell) {
            $this->assertTrue($cell['valid'], implode('; ', $cell['errors']));
        }
    }

    /**
     * Cells differing only in a budget still have distinguishable keys.
     *
     * @return void
     */
    public function test_cell_keys_distinguish_composite_levels(): void {
        $this->resetAfterTest();

        $expansion = sweep::expand($this->spec([
            'globalbudget' => [
                ['minitems' => 10, 'maxitems' => 15],
                ['minitems' => 20, 'maxitems' => 25],
            ],
        ]));

        $keys = array_column($expansion['cells'], 'cellkey');

        $this->assertCount(2, array_unique($keys));
        $this->assertStringContainsString('10', $keys[0]);
    }

    /**
     * The editor parses budget lines into levels.
     *
     * @return void
     */
    public function test_form_parses_budget_lines(): void {
        $this->resetAfterTest();

        $levels = experiment_form::parse_pairs("10/15\n20/25\n\nrubbish\n30/35", 'items');

        $this->assertCount(3, $levels);
        $this->assertSame(['minitems' => 10, 'maxitems' => 15], $levels[0]);
        $this->assertSame(['minitems' => 30, 'maxitems' => 35], $levels[2]);
    }

    /**
     * The editor parses SE lines as floats.
     *
     * @return void
     */
    public function test_form_parses_se_lines(): void {
        $this->resetAfterTest();

        $levels = experiment_form::parse_pairs("0.30/0.75", 'se');

        $this->assertSame(['min' => 0.30, 'max' => 0.75], $levels[0]);
    }

    /**
     * The editor reads strengths as percentages or as named keys.
     *
     * @return void
     */
    public function test_form_parses_strengths(): void {
        $this->resetAfterTest();

        $levels = experiment_form::parse_strengths("5\n10\nshift=1.0\nfactor=1.25\n0.2");

        // A percentage is written as one and stored as a share, because the
        // design speaks in percentages and the recipe in fractions.
        $this->assertSame(['fraction' => 0.05], $levels[0]);
        $this->assertSame(['fraction' => 0.10], $levels[1]);
        $this->assertSame(['shift' => 1.0], $levels[2]);
        $this->assertSame(['factor' => 1.25], $levels[3]);
        $this->assertSame(['fraction' => 0.2], $levels[4]);
    }

    /**
     * The form sends the composite factors into the definition.
     *
     * @return void
     */
    public function test_form_builds_composite_factors(): void {
        $this->resetAfterTest();

        $definition = experiment_form::to_definition([
            'name'                 => 'Full sweep',
            'model'                => '2pl',
            'sweepglobalbudgets'   => "10/15\n20/25",
            'sweepsubscalebudgets' => "3/5",
            'sweepse'              => "0.30/0.75",
            'sweepstrengths'       => "5\n10",
            'sweepmodels'          => ['2pl', '3pl'],
        ]);
        $factors = $definition['sweep']['factors'];

        foreach (['globalbudget', 'subscalebudget', 'se', 'recipe', 'model'] as $factor) {
            $this->assertArrayHasKey($factor, $factors, 'Missing sweep factor: ' . $factor);
        }
        $this->assertCount(2, $factors['globalbudget']);
    }

    /**
     * Cancelled is a status of its own, not a kind of failure.
     *
     * @return void
     */
    public function test_cancelled_is_distinct_from_failed(): void {
        $this->resetAfterTest();

        $this->assertNotSame(registry::STATUS_CANCELLED, registry::STATUS_FAILED);
        $this->assertTrue(registry::is_terminal(registry::STATUS_CANCELLED));
        $this->assertTrue(registry::is_terminal(registry::STATUS_FAILED));
        $this->assertFalse(registry::is_terminal(registry::STATUS_RUNNING));
    }

    /**
     * Actions follow the status.
     *
     * @return void
     */
    public function test_actions_depend_on_the_status(): void {
        $this->resetAfterTest();

        $running = registry::allowed_actions(registry::STATUS_RUNNING);
        $finished = registry::allowed_actions(registry::STATUS_FINISHED);
        $draft = registry::allowed_actions(registry::STATUS_DRAFT);

        // Offering an action that cannot work makes the suite look broken
        // rather than the run look unfinished.
        $this->assertTrue($running['cancel']);
        $this->assertFalse($running['reproduce']);
        $this->assertFalse($finished['cancel']);
        $this->assertTrue($finished['reproduce']);
        $this->assertTrue($finished['results']);
        $this->assertFalse($draft['cancel']);
        $this->assertFalse($draft['results']);
    }

    /**
     * The lifecycle covers every state a run can be seen in.
     *
     * @return void
     */
    public function test_lifecycle_states(): void {
        $this->resetAfterTest();

        $statuses = registry::run_statuses();

        foreach (
            [
            registry::STATUS_DRAFT,
            registry::STATUS_SCHEDULED,
            registry::STATUS_READY,
            registry::STATUS_RUNNING,
            registry::STATUS_AGGREGATING,
            registry::STATUS_FINISHED,
            registry::STATUS_FAILED,
            registry::STATUS_CANCELLED,
            ] as $status
        ) {
            $this->assertContains($status, $statuses);
            $this->assertNotEmpty(local\run_registry::status_label($status));
        }
    }
}
