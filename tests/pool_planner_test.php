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

    /**
     * The study's discrimination distribution has its mode where the design says.
     *
     * @return void
     */
    public function test_discrimination_mode_matches_the_design(): void {
        $this->resetAfterTest();

        $spec = \local_catquizlab\local\experiment_definition::study_item_parameters()['discrimination'];

        // The design states 0 < a <= 5 with the most likely value at 2. A beta
        // distribution states both: it cannot leave its range, and its mode is
        // min + (alpha-1)/(alpha+beta-2) * (max-min).
        $this->assertSame('beta', $spec['dist']);
        $mode = $spec['min']
            + (($spec['alpha'] - 1) / ($spec['alpha'] + $spec['beta'] - 2)) * ($spec['max'] - $spec['min']);
        $this->assertEqualsWithDelta(2.0, $mode, 0.0001);

        mt_srand(4711);
        $values = [];
        for ($i = 0; $i < 5000; $i++) {
            $values[] = \local_catquizlab\local\distribution::draw($spec);
        }
        $this->assertGreaterThan(0.0, min($values));
        $this->assertLessThanOrEqual(5.0, max($values));

        // A lognormal with the same mode was measured first and rejected: with
        // the range enforced by a clamp, 9.4% of draws landed on exactly 5.0
        // and the modal bin was the top one. A clamp catching a tenth of the
        // draws is not a guard, it is the shape — and it would have given a
        // tenth of every pool an identical, maximal discrimination.
        $atceiling = count(array_filter($values, static fn(float $v): bool => $v >= 4.999));
        $this->assertLessThan(0.01 * count($values), $atceiling);

        // The empirical mode sits where the design says, not at an edge.
        $histogram = array_fill(0, 50, 0);
        foreach ($values as $value) {
            $histogram[min(49, (int) floor($value / 0.1))]++;
        }
        $peak = (int) array_search(max($histogram), $histogram, true);
        $this->assertGreaterThanOrEqual(1.5, $peak * 0.1);
        $this->assertLessThanOrEqual(2.5, $peak * 0.1);
    }

    /**
     * The study's guessing distribution stays strictly inside its interval.
     *
     * @return void
     */
    public function test_guessing_stays_within_its_bounds(): void {
        $this->resetAfterTest();

        $spec = \local_catquizlab\local\experiment_definition::study_item_parameters()['guessing'];
        $this->assertSame('beta', $spec['dist']);

        mt_srand(4711);
        $values = [];
        for ($i = 0; $i < 5000; $i++) {
            $values[] = \local_catquizlab\local\distribution::draw($spec);
        }

        // The design says 0 < c < 0.5, and a beta reaches neither end. A
        // clamped normal would have piled draws onto both boundaries, which is
        // the opposite of what a guessing parameter should look like.
        $this->assertGreaterThan(0.0, min($values));
        $this->assertLessThan(0.5, max($values));

        // Symmetric shapes put the mode at the midpoint, and with it the mean.
        $mean = array_sum($values) / count($values);
        $this->assertEqualsWithDelta(0.25, $mean, 0.01);
    }

    /**
     * A beta with an invalid interval or shape is refused.
     *
     * @return void
     */
    public function test_beta_validation(): void {
        $this->resetAfterTest();

        $valid = ['dist' => 'beta', 'min' => 0.0, 'max' => 0.5, 'alpha' => 2, 'beta' => 2];
        $this->assertSame([], \local_catquizlab\local\distribution::validate($valid, 'guessing'));

        $this->assertNotEmpty(\local_catquizlab\local\distribution::validate(
            ['dist' => 'beta', 'min' => 0.5, 'max' => 0.5, 'alpha' => 2, 'beta' => 2],
            'guessing'
        ));
        $this->assertNotEmpty(\local_catquizlab\local\distribution::validate(
            ['dist' => 'beta', 'min' => 0.0, 'max' => 0.5, 'alpha' => 0, 'beta' => 2],
            'guessing'
        ));
    }
}
