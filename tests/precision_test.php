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
 * Tests for standard errors computed from the administered items.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\precision;

/**
 * Precision tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\precision
 */
final class precision_test extends \advanced_testcase {
    /**
     * An item is most informative where its difficulty matches the ability.
     *
     * @return void
     */
    public function test_information_peaks_at_the_difficulty(): void {
        $this->resetAfterTest();

        $at = precision::item_information(0.0, 0.0, 1.0, 0.0);
        $off = precision::item_information(0.0, 2.0, 1.0, 0.0);

        // For a 2PL the information is a^2 * P * (1-P), which is largest when
        // P = 0.5 — that is, at the difficulty.
        $this->assertEqualsWithDelta(0.25, $at, 1e-9);
        $this->assertGreaterThan($off, $at);
        $this->assertGreaterThan(0.0, $off);
    }

    /**
     * Discrimination scales the information quadratically.
     *
     * @return void
     */
    public function test_discrimination_scales_the_information(): void {
        $this->resetAfterTest();

        $one = precision::item_information(0.0, 0.0, 1.0);
        $two = precision::item_information(0.0, 0.0, 2.0);

        $this->assertEqualsWithDelta(4.0 * $one, $two, 1e-9);
    }

    /**
     * Guessing reduces the information an item carries.
     *
     * @return void
     */
    public function test_guessing_reduces_the_information(): void {
        $this->resetAfterTest();

        $without = precision::item_information(0.0, 0.0, 1.0, 0.0);
        $with = precision::item_information(0.0, 0.0, 1.0, 0.25);

        // A person who can guess right without knowing tells the test less.
        $this->assertLessThan($without, $with);
        $this->assertGreaterThan(0.0, $with);
    }

    /**
     * Test information adds up and the standard error follows from it.
     *
     * @return void
     */
    public function test_standard_error_follows_the_information(): void {
        $this->resetAfterTest();

        $items = array_fill(0, 4, ['difficulty' => 0.0, 'discrimination' => 1.0, 'guessing' => 0.0]);
        $information = precision::test_information($items, 0.0);

        // Four items at 0.25 each: I = 1, so SE = 1.
        $this->assertEqualsWithDelta(1.0, $information, 1e-9);
        $this->assertEqualsWithDelta(1.0, precision::standard_error($information), 1e-9);

        // Sixteen such items quarter the standard error, not the information —
        // the square root is why a CAT gets expensive near the end.
        $information = precision::test_information(array_fill(0, 16, $items[0]), 0.0);
        $this->assertEqualsWithDelta(0.5, precision::standard_error($information), 1e-9);
    }

    /**
     * No information means no standard error, not a zero one.
     *
     * @return void
     */
    public function test_no_information_yields_no_standard_error(): void {
        $this->resetAfterTest();

        // Reporting 0 or infinity here would both be claims the data does not
        // support; the absence of a figure is the honest answer.
        $this->assertNull(precision::standard_error(0.0));
        $this->assertSame(0.0, precision::test_information([], 0.0));
    }
}
