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
 * Tests for the SE-aware diagnostics.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\se_diagnostics;

/**
 * SE-aware diagnostics tests.
 *
 * @covers \local_catquizlab\local\se_diagnostics
 */
final class se_diagnostics_test extends \advanced_testcase {
    /**
     * SE-aware deficit labels only flag values beyond the standard-error band.
     *
     * @return void
     */
    public function test_deficit_labels_se(): void {
        $values = [-1.0, -0.2, 0.5];
        $ses = [0.3, 0.3, 0.3];

        // At 1 SE only the clear deficit (-1.0 < -0.3) is flagged.
        $this->assertSame([true, false, false], se_diagnostics::deficit_labels_se($values, 0.0, $ses, 1.0));
        // At 0.5 SE the borderline case (-0.2 < -0.15) is flagged too.
        $this->assertSame([true, true, false], se_diagnostics::deficit_labels_se($values, 0.0, $ses, 0.5));
    }

    /**
     * Agreement counts subscales recovered within the SE tolerance.
     *
     * @return void
     */
    public function test_agreement_within_se(): void {
        $true = [-1.0, 0.0, 1.0];
        $est = [-1.2, 0.1, 1.5];
        $ses = [0.3, 0.3, 0.3];

        $one = se_diagnostics::agreement_within_se($true, $est, $ses, 1.0);
        $this->assertSame(2, $one['within']);
        $this->assertEqualsWithDelta(2 / 3, $one['fraction'], 1e-6);

        $two = se_diagnostics::agreement_within_se($true, $est, $ses, 2.0);
        $this->assertSame(3, $two['within']);
    }
}
