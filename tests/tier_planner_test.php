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
 * Tests for the tier planner.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\tier_planner;

/**
 * Tier planner tests.
 *
 * @covers \local_catquizlab\local\tier_planner
 */
final class tier_planner_test extends \advanced_testcase {
    /**
     * Known tiers rank in order; unknown tiers sort last.
     *
     * @return void
     */
    public function test_tier_rank(): void {
        $this->assertSame(0, tier_planner::tier_rank('baseline'));
        $this->assertSame(1, tier_planner::tier_rank('main'));
        $this->assertSame(2, tier_planner::tier_rank('robustness'));
        $this->assertSame(3, tier_planner::tier_rank('operative'));
        $this->assertGreaterThan(3, tier_planner::tier_rank('something-else'));
    }

    /**
     * Experiments sort by tier, then id.
     *
     * @return void
     */
    public function test_sort_experiments(): void {
        $experiments = [
            (object) ['id' => 5, 'tier' => 'operative'],
            (object) ['id' => 2, 'tier' => 'baseline'],
            (object) ['id' => 9, 'tier' => 'main'],
            (object) ['id' => 1, 'tier' => 'main'],
            (object) ['id' => 7, 'tier' => 'robustness'],
        ];

        $sorted = tier_planner::sort_experiments($experiments);
        $order = array_map(static fn($e) => $e->tier . '#' . $e->id, $sorted);

        $this->assertSame(
            ['baseline#2', 'main#1', 'main#9', 'robustness#7', 'operative#5'],
            $order
        );
    }

    /**
     * The registry reader returns experiments in tier order.
     *
     * @return void
     */
    public function test_experiments_in_order(): void {
        $this->resetAfterTest();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $generator->create_experiment(['name' => 'R', 'tier' => 'robustness']);
        $generator->create_experiment(['name' => 'B', 'tier' => 'baseline']);

        $ordered = tier_planner::experiments_in_order();
        $tiers = array_values(array_map(static fn($e) => $e->tier, $ordered));
        $this->assertSame('baseline', $tiers[0]);
    }
}
