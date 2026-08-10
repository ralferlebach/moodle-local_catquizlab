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
 * Tests for the pool mutator.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\pool_planner;
use local_catquizlab\local\pool_mutator;

/**
 * Pool mutator tests.
 *
 * @covers \local_catquizlab\local\pool_mutator
 */
final class pool_mutator_test extends \advanced_testcase {
    /**
     * The ideal blueprint used as mutation input.
     *
     * @return array
     */
    protected function ideal(): array {
        return pool_planner::plan([
            'pool' => ['scales' => ['categories' => 2, 'subcategories' => 3, 'itemspersubscale' => 10]],
        ], 42);
    }

    /**
     * Flatten a blueprint to its item list.
     *
     * @param array $blueprint The blueprint.
     * @return array
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
     * The ideal variant is an identity on difficulties.
     *
     * @return void
     */
    public function test_ideal_identity(): void {
        $ideal = $this->ideal();
        $out = pool_mutator::mutate($ideal, 'ideal', [], 1);

        $this->assertSame(
            array_column($this->items($ideal), 'difficulty'),
            array_column($this->items($out), 'difficulty')
        );
    }

    /**
     * Shifting moves every difficulty by the constant.
     *
     * @return void
     */
    public function test_shifted(): void {
        $ideal = $this->ideal();
        $out = pool_mutator::mutate($ideal, 'shifted', ['shift' => 1.0], 1);

        $before = array_column($this->items($ideal), 'difficulty');
        $after = array_column($this->items($out), 'difficulty');
        foreach ($before as $i => $b) {
            $this->assertEqualsWithDelta($b + 1.0, $after[$i], 1e-9);
        }
    }

    /**
     * Depletion removes a fraction deterministically.
     *
     * @return void
     */
    public function test_depleted_deterministic(): void {
        $ideal = $this->ideal();
        $a = pool_mutator::mutate($ideal, 'depleted', ['fraction' => 0.5], 7);
        $b = pool_mutator::mutate($ideal, 'depleted', ['fraction' => 0.5], 7);

        $this->assertLessThan($ideal['totals']['items'], $a['totals']['items']);
        $this->assertSame($a['totals']['items'], $b['totals']['items']);
        $this->assertSame(
            array_column($this->items($a), 'name'),
            array_column($this->items($b), 'name')
        );
    }

    /**
     * A gap leaves no items inside the band.
     *
     * @return void
     */
    public function test_gappy(): void {
        $out = pool_mutator::mutate($this->ideal(), 'gappy', ['gapmin' => -0.5, 'gapmax' => 0.5], 1);
        foreach ($this->items($out) as $item) {
            $this->assertTrue($item['difficulty'] < -0.5 || $item['difficulty'] > 0.5);
        }
    }

    /**
     * Calibration error preserves the true difficulty and adds a calibrated value.
     *
     * @return void
     */
    public function test_calibration_error(): void {
        $ideal = $this->ideal();
        $out = pool_mutator::mutate($ideal, 'calibrationerror', ['fraction' => 1.0, 'sd' => 0.8], 3);

        $this->assertSame(
            array_column($this->items($ideal), 'difficulty'),
            array_column($this->items($out), 'difficulty')
        );
        foreach ($this->items($out) as $item) {
            $this->assertArrayHasKey('calibrated', $item);
            $this->assertArrayHasKey('miscalibrated', $item);
        }
    }

    /**
     * Tagging error records true and assigned tags; a full fraction mislabels some.
     *
     * @return void
     */
    public function test_tagging_error(): void {
        $out = pool_mutator::mutate($this->ideal(), 'taggingerror', ['fraction' => 1.0], 5);

        $mistagged = 0;
        foreach ($this->items($out) as $item) {
            $this->assertArrayHasKey('truesubscale', $item);
            $this->assertArrayHasKey('assignedsubscale', $item);
            if ($item['assignedsubscale'] !== $item['truesubscale']) {
                $mistagged++;
            }
        }
        $this->assertGreaterThan(0, $mistagged);
    }

    /**
     * Combined applies steps in order.
     *
     * @return void
     */
    public function test_combined(): void {
        $out = pool_mutator::mutate($this->ideal(), 'combined', [
            'steps' => [
                ['variant' => 'shifted', 'recipe' => ['shift' => 0.5]],
                ['variant' => 'depleted', 'recipe' => ['fraction' => 0.25]],
            ],
        ], 9);

        $this->assertLessThan($this->ideal()['totals']['items'], $out['totals']['items']);
    }

    /**
     * An unknown variant is rejected.
     *
     * @return void
     */
    public function test_unknown_variant_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        pool_mutator::mutate($this->ideal(), 'wobbly', [], 1);
    }
}
