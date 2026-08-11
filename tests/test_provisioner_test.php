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
 * Tests for the test provisioner.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\test_provisioner;
use local_catquizlab\local\environment;

/**
 * Test provisioner tests.
 *
 * @covers \local_catquizlab\local\test_provisioner
 */
final class test_provisioner_test extends \advanced_testcase {
    /**
     * The built settings carry the catquiz fields the engine needs, in the right shape.
     *
     * @return void
     */
    public function test_build_quizsettings(): void {
        $settings = test_provisioner::build_quizsettings('Demo', 1, [2, 3, 88], [
            'minquestions' => 8,
            'maxquestions' => 20,
            'se_min'       => 0.30,
            'teststrategy' => 4,
        ]);

        $this->assertSame('catquiz', $settings['catmodel']);
        $this->assertSame('1', $settings['catquiz_catscales']);
        $this->assertSame('4', $settings['catquiz_selectteststrategy']);
        $this->assertSame(8, $settings['maxquestionsgroup']['catquiz_minquestions']);
        $this->assertSame(20, $settings['maxquestionsgroup']['catquiz_maxquestions']);
        $this->assertEqualsWithDelta(0.30, $settings['catquiz_standarderrorgroup']['catquiz_standarderror_min'], 1e-9);

        // The root scale and each subscale are activated.
        $this->assertSame('1', $settings['catquiz_subscalecheckbox_1']);
        $this->assertSame('1', $settings['catquiz_subscalecheckbox_2']);
        $this->assertSame('1', $settings['catquiz_subscalecheckbox_88']);
        $this->assertArrayNotHasKey('catquiz_subscalecheckbox_99', $settings);
    }

    /**
     * Defaults fill in when options are omitted.
     *
     * @return void
     */
    public function test_build_quizsettings_defaults(): void {
        $settings = test_provisioner::build_quizsettings('Demo', 5, []);
        $this->assertSame(10, $settings['maxquestionsgroup']['catquiz_minquestions']);
        $this->assertSame(15, $settings['maxquestionsgroup']['catquiz_maxquestions']);
        $this->assertSame('4', $settings['catquiz_selectteststrategy']);
    }

    /**
     * Creation is a no-op without the engine and host activity.
     *
     * @return void
     */
    public function test_create_requires_engine(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        if (environment::engine_available() && environment::adaptivequiz_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        $this->assertNull(test_provisioner::create($run->id, 1, [2, 3]));
        $this->assertNull($DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $run->id]));
    }
}
