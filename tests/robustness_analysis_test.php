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
 * Tests for the robustness analysis.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\robustness_analysis;
use local_catquizlab\local\run_registry;

/**
 * Robustness-analysis tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\robustness_analysis
 */
final class robustness_analysis_test extends \advanced_testcase {
    /**
     * Observations of one cell with a chosen error size.
     *
     * @param string $variant The pool variant.
     * @param float|null $strength The disturbance strength.
     * @param float $error The signed error every attempt in the cell shows.
     * @param int $count How many attempts.
     * @param array $overrides Coordinate overrides, e.g. a different strategy.
     * @return array[]
     */
    protected function cell(
        string $variant,
        ?float $strength,
        float $error,
        int $count = 4,
        array $overrides = []
    ): array {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $overrides + [
                'attemptid'   => count($rows) + 1,
                'runid'       => 1,
                'personid'    => $i + 1,
                'tier'        => 'main',
                'strategy'    => 'lowestsub',
                'model'       => '2pl',
                'stratum'     => 'conforming',
                'severity'    => 'none',
                'variant'     => $variant,
                'strength'    => $strength,
                'truetheta'   => 0.0,
                'esttheta'    => $error,
                'error'       => $error,
                'se'          => 0.4,
                'nitems'      => 20,
                'stopreached' => true,
                'runtimems'   => 1000,
                'items'       => [1, 2, 3],
                'profile'     => [],
                'trace'       => [],
            ];
        }

        return $rows;
    }

    /**
     * A disturbed cell is measured against the ideal cell.
     *
     * @return void
     */
    public function test_deltas_against_the_ideal_pool(): void {
        $this->resetAfterTest();

        $observations = array_merge(
            $this->cell('ideal', null, 0.20),
            $this->cell('shifted', 1.0, 0.50)
        );

        $cells = robustness_analysis::cells($observations);
        $shifted = $this->find($cells, 'shifted');

        $this->assertNotNull($shifted['reference']);
        // RMSE rises from 0.20 to 0.50, so the delta is +0.30.
        $this->assertEqualsWithDelta(0.30, $shifted['deltas']['rmse'], 0.000001);
        $this->assertEqualsWithDelta(0.30, $shifted['deltas']['bias'], 0.000001);
    }

    /**
     * The ideal cell itself carries no delta.
     *
     * @return void
     */
    public function test_reference_cell_is_marked(): void {
        $this->resetAfterTest();

        $cells = robustness_analysis::cells(array_merge(
            $this->cell('ideal', null, 0.2),
            $this->cell('gappy', 1.0, 0.3)
        ));

        $ideal = $this->find($cells, 'ideal');
        $this->assertTrue($ideal['isreference']);
        $this->assertSame([], $ideal['deltas']);
    }

    /**
     * A cell is not compared against an ideal cell from another condition.
     *
     * @return void
     */
    public function test_reference_must_match_the_other_factors(): void {
        $this->resetAfterTest();

        // The only ideal cell ran a different strategy. Subtracting it would
        // report the strategy difference as a robustness effect.
        $observations = array_merge(
            $this->cell('ideal', null, 0.2, 4, ['strategy' => 'fastest']),
            $this->cell('shifted', 1.0, 0.5)
        );

        $cells = robustness_analysis::cells($observations);
        $shifted = $this->find($cells, 'shifted');

        $this->assertNull($shifted['reference']);
        $this->assertSame([], $shifted['deltas']);
    }

    /**
     * Cells of the same variant at different strengths stay apart.
     *
     * @return void
     */
    public function test_strengths_form_separate_cells(): void {
        $this->resetAfterTest();

        $observations = array_merge(
            $this->cell('ideal', null, 0.20),
            $this->cell('calibrationerror', 0.05, 0.25),
            $this->cell('calibrationerror', 0.10, 0.30),
            $this->cell('calibrationerror', 0.20, 0.45)
        );

        $cells = robustness_analysis::cells($observations);
        $series = robustness_analysis::by_strength($cells, 'calibrationerror');

        $this->assertCount(3, $series);
        $this->assertSame([0.05, 0.10, 0.20], array_column($series, 'strength'));
        // The damage grows with the disturbance, which is the shape the design
        // is looking for.
        $deltas = array_map(static fn(array $c): float => $c['deltas']['rmse'], $series);
        $this->assertEqualsWithDelta(0.05, $deltas[0], 0.000001);
        $this->assertEqualsWithDelta(0.25, $deltas[2], 0.000001);
    }

    /**
     * The direction of a metric decides whether a delta is an improvement.
     *
     * @return void
     */
    public function test_metric_direction(): void {
        $this->resetAfterTest();

        $this->assertSame(-1, robustness_analysis::direction('rmse'));
        $this->assertSame(-1, robustness_analysis::direction('se'));
        $this->assertSame(1, robustness_analysis::direction('correlation'));
        $this->assertSame(1, robustness_analysis::direction('within1se'));
        // Bias is signed and test length is a cost: a verdict would mislead.
        $this->assertSame(0, robustness_analysis::direction('bias'));
        $this->assertSame(0, robustness_analysis::direction('nitems'));
    }

    /**
     * The strength of each variant is read off its own recipe key.
     *
     * @return void
     */
    public function test_disturbance_strength_per_variant(): void {
        $this->resetAfterTest();

        $this->assertSame(0.15, run_registry::disturbance_strength('calibrationerror', ['fraction' => 0.15]));
        $this->assertSame(1.0, run_registry::disturbance_strength('shifted', ['shift' => -1.0]));
        $this->assertSame(1.25, run_registry::disturbance_strength('stretched', ['factor' => 1.25]));
        $this->assertSame(1.0, run_registry::disturbance_strength('gappy', ['gapmin' => -0.5, 'gapmax' => 0.5]));
        // The ideal pool has no disturbance, and a combined one does not
        // reduce to a single number.
        $this->assertNull(run_registry::disturbance_strength('ideal', []));
        $this->assertNull(run_registry::disturbance_strength('combined', ['steps' => []]));
    }

    /**
     * The strength unit distinguishes shares from logits.
     *
     * @return void
     */
    public function test_strength_units(): void {
        $this->resetAfterTest();

        $this->assertSame('share', run_registry::strength_unit('depleted'));
        $this->assertSame('logits', run_registry::strength_unit('shifted'));
        $this->assertSame('factor', run_registry::strength_unit('stretched'));
        $this->assertSame('', run_registry::strength_unit('ideal'));
    }

    /**
     * The variant list leaves out the reference.
     *
     * @return void
     */
    public function test_variants_exclude_the_reference(): void {
        $this->resetAfterTest();

        $cells = robustness_analysis::cells(array_merge(
            $this->cell('ideal', null, 0.2),
            $this->cell('depleted', 0.5, 0.4),
            $this->cell('taggingerror', 0.1, 0.3)
        ));

        $this->assertSame(['depleted', 'taggingerror'], robustness_analysis::variants($cells));
    }

    /**
     * A missing outcome yields a missing delta, not a zero.
     *
     * @return void
     */
    public function test_missing_outcome_gives_no_delta(): void {
        $this->resetAfterTest();

        $deltas = robustness_analysis::deltas(
            ['rmse' => 0.5, 'correlation' => null],
            ['rmse' => 0.3, 'correlation' => 0.9]
        );

        $this->assertEqualsWithDelta(0.2, $deltas['rmse'], 0.000001);
        $this->assertNull($deltas['correlation']);
    }

    /**
     * Outcomes of a cell include the primary global figures.
     *
     * @return void
     */
    public function test_outcomes_cover_the_global_metrics(): void {
        $this->resetAfterTest();

        $outcomes = robustness_analysis::outcomes($this->cell('ideal', null, 0.25));

        foreach (array_keys(robustness_analysis::global_metrics()) as $metric) {
            $this->assertArrayHasKey($metric, $outcomes);
        }
        $this->assertEqualsWithDelta(0.25, $outcomes['rmse'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $outcomes['stopsuccess'], 0.000001);
    }

    /**
     * Find a cell by its variant.
     *
     * @param array $cells The cells.
     * @param string $variant The variant.
     * @return array
     */
    protected function find(array $cells, string $variant): array {
        foreach ($cells as $cell) {
            if ($cell['variant'] === $variant) {
                return $cell;
            }
        }
        $this->fail('No cell for variant ' . $variant);
    }
}
