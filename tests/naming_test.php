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
 * Tests for the naming engine.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\naming;

/**
 * Naming engine tests.
 *
 * @covers \local_catquizlab\local\naming
 */
final class naming_test extends \advanced_testcase {
    /**
     * Plain placeholders are substituted.
     *
     * @return void
     */
    public function test_plain_substitution(): void {
        $this->assertSame(
            'Q-algebra-linear',
            naming::expand('Q-{category}-{subscale}', ['category' => 'algebra', 'subscale' => 'linear'])
        );
    }

    /**
     * A width spec zero-pads an integer.
     *
     * @return void
     */
    public function test_zero_padding(): void {
        $this->assertSame('P-chaotic-0007', naming::expand('P-{stratum}-{index:04d}', [
            'stratum' => 'chaotic',
            'index'   => 7,
        ]));
        $this->assertSame('item-042', naming::expand('item-{n:03d}', ['n' => 42]));
    }

    /**
     * An unknown placeholder is rejected.
     *
     * @return void
     */
    public function test_unknown_placeholder_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        naming::expand('X-{missing}', ['present' => 1]);
    }

    /**
     * A sequence produces the requested number of ordered, distinct names.
     *
     * @return void
     */
    public function test_sequence(): void {
        $names = naming::sequence('P-{stratum}-{index:03d}', ['stratum' => 'conforming'], 3);

        $this->assertSame(['P-conforming-001', 'P-conforming-002', 'P-conforming-003'], $names);
        $this->assertSame($names, array_values(array_unique($names)));
    }

    /**
     * The sequence start index is honoured.
     *
     * @return void
     */
    public function test_sequence_start(): void {
        $names = naming::sequence('n{index:02d}', [], 2, 'index', 10);
        $this->assertSame(['n10', 'n11'], $names);
    }
}
