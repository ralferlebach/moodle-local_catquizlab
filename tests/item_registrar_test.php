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
 * Tests for the item registrar.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\item_registrar;
use local_catquizlab\local\environment;

/**
 * Item registrar tests.
 *
 * @covers \local_catquizlab\local\item_registrar
 */
final class item_registrar_test extends \advanced_testcase {
    /**
     * The item-parameter record carries the model and Rasch defaults.
     *
     * @return void
     */
    public function test_build_itemparam(): void {
        $param = item_registrar::build_itemparam(3397, 10, ['difficulty' => 0.75]);
        $this->assertSame(3397, $param['componentid']);
        $this->assertSame('question', $param['componentname']);
        $this->assertSame(10, $param['contextid']);
        $this->assertSame('raschbirnbaum', $param['model']);
        $this->assertEqualsWithDelta(0.75, $param['difficulty'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $param['discrimination'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $param['guessing'], 1e-9);
        $this->assertSame(item_registrar::STATUS_CALCULATED, $param['status']);
    }

    /**
     * Registration is a no-op without the engine.
     *
     * @return void
     */
    public function test_register_requires_engine(): void {
        $this->resetAfterTest();

        if (environment::engine_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        $this->assertNull(item_registrar::register_item(3397, 101, 10, ['difficulty' => 0.5]));
    }
}
