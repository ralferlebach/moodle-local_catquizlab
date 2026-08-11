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
 * Tests for the materialiser.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\materialiser;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\environment;

/**
 * Materialiser tests.
 *
 * @covers \local_catquizlab\local\materialiser
 */
final class materialiser_test extends \advanced_testcase {
    /**
     * plan_items maps each blueprint item to its subscale's engine scale.
     *
     * @return void
     */
    public function test_plan_items(): void {
        $blueprint = ['categories' => [
            ['index' => 1, 'subscales' => [
                ['index' => 1, 'items' => [
                    ['index' => 1, 'name' => 'Q-1-1-001', 'difficulty' => -0.5],
                    ['index' => 2, 'name' => 'Q-1-1-002', 'difficulty' => 0.3],
                ]],
                ['index' => 2, 'items' => [
                    ['index' => 1, 'name' => 'Q-1-2-001', 'difficulty' => 1.1],
                ]],
            ]],
        ]];
        $scalemap = [
            ['level' => scale_provisioner::LEVEL_SUBSCALE, 'categoryindex' => 1, 'subscaleindex' => 1,
                'catscaleid' => 101, 'contextid' => 10, 'name' => 'K1.1'],
            ['level' => scale_provisioner::LEVEL_SUBSCALE, 'categoryindex' => 1, 'subscaleindex' => 2,
                'catscaleid' => 102, 'contextid' => 10, 'name' => 'K1.2'],
        ];

        $specs = materialiser::plan_items($blueprint, $scalemap);
        $this->assertCount(3, $specs);
        $this->assertSame(101, $specs[0]['catscaleid']);
        $this->assertSame(102, $specs[2]['catscaleid']);
        $this->assertEqualsWithDelta(1.1, $specs[2]['difficulty'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $specs[0]['discrimination'], 1e-9);
    }

    /**
     * Subscales without a mapping are skipped.
     *
     * @return void
     */
    public function test_plan_items_skips_unmapped(): void {
        $blueprint = ['categories' => [
            ['index' => 9, 'subscales' => [
                ['index' => 9, 'items' => [['index' => 1, 'name' => 'X', 'difficulty' => 0.0]]],
            ]],
        ]];
        $this->assertSame([], materialiser::plan_items($blueprint, []));
    }

    /**
     * Materialisation is a no-op without the engine.
     *
     * @return void
     */
    public function test_materialise_requires_engine(): void {
        $this->resetAfterTest();

        if (environment::engine_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        $this->assertNull(materialiser::materialise(1, [], ['questioncategoryid' => 5]));
    }

    /**
     * Polytomous steps bracket the difficulty in ascending order.
     *
     * @return void
     */
    public function test_polytomous_steps(): void {
        $steps = materialiser::polytomous_steps(0.5);
        $this->assertSame([-0.5, 0.5, 1.5], $steps);
        $this->assertTrue($steps[0] < $steps[1] && $steps[1] < $steps[2]);
    }
}
