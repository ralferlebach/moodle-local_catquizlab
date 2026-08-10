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
 * Tests for the metrics.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\metrics;

/**
 * Metrics tests.
 *
 * @covers \local_catquizlab\local\metrics
 */
final class metrics_test extends \advanced_testcase {
    /**
     * A constant estimation offset yields matching bias, RMSE and MAE, correlation 1.
     *
     * @return void
     */
    public function test_ability_recovery_constant_offset(): void {
        $attempts = [
            ['truetheta' => -1.0, 'esttheta' => -0.5],
            ['truetheta' => 0.0, 'esttheta' => 0.5],
            ['truetheta' => 1.0, 'esttheta' => 1.5],
        ];
        $r = metrics::ability_recovery($attempts);

        $this->assertSame(3, $r['n']);
        $this->assertEqualsWithDelta(0.5, $r['bias'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $r['rmse'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $r['mae'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $r['correlation'], 1e-9);
    }

    /**
     * Perfect estimates give zero error; empty input is handled safely.
     *
     * @return void
     */
    public function test_ability_recovery_edge_cases(): void {
        $perfect = metrics::ability_recovery([
            ['truetheta' => -1.0, 'esttheta' => -1.0],
            ['truetheta' => 1.0, 'esttheta' => 1.0],
        ]);
        $this->assertEqualsWithDelta(0.0, $perfect['rmse'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $perfect['correlation'], 1e-9);

        $empty = metrics::ability_recovery([]);
        $this->assertSame(0, $empty['n']);
        $this->assertNull($empty['correlation']);
    }

    /**
     * Efficiency reports test-length range and mean SE.
     *
     * @return void
     */
    public function test_efficiency(): void {
        $e = metrics::efficiency([
            ['nitems' => 10, 'se' => 0.30],
            ['nitems' => 20, 'se' => 0.28],
            ['nitems' => 30, 'se' => 0.26],
        ]);

        $this->assertSame(3, $e['nattempts']);
        $this->assertEqualsWithDelta(20.0, $e['meanlength'], 1e-9);
        $this->assertSame(10, $e['minlength']);
        $this->assertSame(30, $e['maxlength']);
        $this->assertEqualsWithDelta(0.28, $e['meanse'], 1e-9);
    }

    /**
     * Exposure counts items, rates them per attempt and reports unused pool items.
     *
     * @return void
     */
    public function test_exposure(): void {
        $x = metrics::exposure([
            ['items' => ['a', 'b', 'c']],
            ['items' => ['a', 'b', 'd']],
            ['items' => ['a', 'e']],
        ], 8);

        $this->assertSame(3, $x['counts']['a']);
        $this->assertEqualsWithDelta(1.0, $x['rates']['a'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $x['maxrate'], 1e-9);
        $this->assertSame(5, $x['itemsused']);
        $this->assertSame(3, $x['unused']);
    }

    /**
     * The summary composes the three metric groups.
     *
     * @return void
     */
    public function test_summarise(): void {
        $summary = metrics::summarise([
            ['truetheta' => 0.0, 'esttheta' => 0.1, 'nitems' => 12, 'se' => 0.29, 'items' => ['a']],
        ], 4);

        $this->assertArrayHasKey('abilityrecovery', $summary);
        $this->assertArrayHasKey('efficiency', $summary);
        $this->assertArrayHasKey('exposure', $summary);
        $this->assertSame(3, $summary['exposure']['unused']);
    }
}
