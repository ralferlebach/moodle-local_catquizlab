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
 * Tests for the item repository.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\item_repository;
use local_catquizlab\local\environment;

/**
 * Item repository tests.
 *
 * @covers \local_catquizlab\local\item_repository
 */
final class item_repository_test extends \advanced_testcase {
    /**
     * Row shaping casts fields and applies Rasch defaults for missing columns.
     *
     * @return void
     */
    public function test_shape_params(): void {
        $full = (object) [
            'questionid'     => '42',
            'catscaleid'     => '7',
            'model'          => 'raschbirnbaum',
            'difficulty'     => '0.5',
            'discrimination' => '1.3',
            'guessing'       => '0.2',
        ];
        $shaped = item_repository::shape_params($full);
        $this->assertSame(42, $shaped['questionid']);
        $this->assertSame(7, $shaped['catscaleid']);
        $this->assertSame('raschbirnbaum', $shaped['model']);
        $this->assertEqualsWithDelta(0.5, $shaped['difficulty'], 1e-9);
        $this->assertEqualsWithDelta(1.3, $shaped['discrimination'], 1e-9);
        $this->assertEqualsWithDelta(0.2, $shaped['guessing'], 1e-9);

        // Missing discrimination/guessing fall back to the 1PL defaults.
        $rasch = (object) ['questionid' => '9', 'catscaleid' => '1', 'model' => 'rasch',
            'difficulty' => '-1.0', 'discrimination' => null, 'guessing' => null];
        $shapedrasch = item_repository::shape_params($rasch);
        $this->assertEqualsWithDelta(1.0, $shapedrasch['discrimination'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $shapedrasch['guessing'], 1e-9);
    }

    /**
     * Without the engine the reads return null / an empty list.
     *
     * @return void
     */
    /**
     * A params json with steps marks the item polytomous and exposes the steps.
     *
     * @return void
     */
    public function test_shape_params_polytomous(): void {
        $row = (object) [
            'questionid' => 5, 'catscaleid' => 3, 'model' => 'grmgeneralized',
            'difficulty' => 0.5, 'discrimination' => 1.2, 'guessing' => 0.0,
            'json' => json_encode(['steps' => [-0.5, 0.5, 1.5]]),
        ];
        $shaped = item_repository::shape_params($row);
        $this->assertTrue($shaped['polytomous']);
        $this->assertSame([-0.5, 0.5, 1.5], $shaped['steps']);

        // No json means dichotomous with no steps.
        $plain = (object) ['questionid' => 5, 'catscaleid' => 3, 'model' => 'raschbirnbaum',
            'difficulty' => 0.5, 'discrimination' => 1.0, 'guessing' => 0.0, 'json' => ''];
        $this->assertFalse(item_repository::shape_params($plain)['polytomous']);
    }

    public function test_reads_require_engine(): void {
        $this->resetAfterTest();

        if (environment::engine_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        $this->assertNull(item_repository::for_question(1, 100));
        $this->assertSame([], item_repository::for_scale(1, 5));
    }
}
