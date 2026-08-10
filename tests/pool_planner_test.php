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
 * Tests for the pool planner.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\pool_planner;

/**
 * Pool planner tests.
 *
 * @covers \local_catquizlab\local\pool_planner
 */
final class pool_planner_test extends \advanced_testcase {
    /**
     * A small pool definition for reuse.
     *
     * @return array
     */
    protected function definition(): array {
        return [
            'pool' => [
                'scales'     => ['categories' => 2, 'subcategories' => 3, 'itemspersubscale' => 4],
                'itemnaming' => ['pattern' => 'Q-{category}-{subscale}-{index:03d}'],
            ],
        ];
    }

    /**
     * The blueprint has the right tree shape and totals.
     *
     * @return void
     */
    public function test_structure_and_totals(): void {
        $blueprint = pool_planner::plan($this->definition(), 42);

        $this->assertCount(2, $blueprint['categories']);
        $this->assertCount(3, $blueprint['categories'][0]['subscales']);
        $this->assertCount(4, $blueprint['categories'][0]['subscales'][0]['items']);
        $this->assertSame(['categories' => 2, 'subscales' => 6, 'items' => 24], $blueprint['totals']);
    }

    /**
     * The default full ideal pool has 10 x 10 x 25 = 2500 items.
     *
     * @return void
     */
    public function test_ideal_pool_size(): void {
        $blueprint = pool_planner::plan([], 1);
        $this->assertSame(2500, $blueprint['totals']['items']);
    }

    /**
     * The same seed reproduces the blueprint; a different seed changes it.
     *
     * @return void
     */
    public function test_determinism(): void {
        $a = pool_planner::plan($this->definition(), 42);
        $b = pool_planner::plan($this->definition(), 42);
        $c = pool_planner::plan($this->definition(), 99);

        $this->assertSame($a, $b);
        $this->assertNotSame($a['categories'][0]['mean'], $c['categories'][0]['mean']);
    }

    /**
     * Item names follow the pattern and are unique across the pool.
     *
     * @return void
     */
    public function test_item_names_unique(): void {
        $blueprint = pool_planner::plan($this->definition(), 42);

        $names = [];
        foreach ($blueprint['categories'] as $category) {
            foreach ($category['subscales'] as $subscale) {
                foreach ($subscale['items'] as $item) {
                    $names[] = $item['name'];
                }
            }
        }

        $this->assertCount(24, $names);
        $this->assertCount(24, array_unique($names));
        $this->assertSame('Q-1-1-001', $names[0]);
    }
}
