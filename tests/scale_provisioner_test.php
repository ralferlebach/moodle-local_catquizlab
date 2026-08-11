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
 * Tests for the scale provisioner.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\environment;

/**
 * Scale provisioner tests.
 *
 * @covers \local_catquizlab\local\scale_provisioner
 */
final class scale_provisioner_test extends \advanced_testcase {
    /**
     * The plan is a root, its categories and their subscales, with profile indices.
     *
     * @return void
     */
    public function test_plan_scales(): void {
        $plan = scale_provisioner::plan_scales(['categories' => 2, 'subcategories' => 3, 'name' => 'Demo']);

        // 1 root + 2 categories + 2*3 subscales.
        $this->assertCount(9, $plan);
        $this->assertSame(scale_provisioner::LEVEL_ROOT, $plan[0]['level']);
        $this->assertNull($plan[0]['categoryindex']);

        $subscales = array_filter($plan, static fn($n) => $n['level'] === scale_provisioner::LEVEL_SUBSCALE);
        $this->assertCount(6, $subscales);
        // The last subscale is category 2, subscale 3.
        $last = end($plan);
        $this->assertSame(2, $last['categoryindex']);
        $this->assertSame(3, $last['subscaleindex']);
    }

    /**
     * Provisioning is a no-op without the engine.
     *
     * @return void
     */
    public function test_provision_requires_engine(): void {
        $this->resetAfterTest();

        if (environment::engine_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        $this->assertNull(scale_provisioner::provision($run->id, ['categories' => 2, 'subcategories' => 3]));
    }

    /**
     * The mapping reader returns a scale's profile indices.
     *
     * @return void
     */
    public function test_mapping_for(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $now = time();

        $DB->insert_record('local_catquizlab_scalemap', (object) [
            'runid' => $run->id, 'catscaleid' => 555, 'parentcatscaleid' => 500, 'contextid' => 10,
            'level' => scale_provisioner::LEVEL_SUBSCALE, 'categoryindex' => 2, 'subscaleindex' => 3,
            'name' => 'K2.3', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $mapping = scale_provisioner::mapping_for($run->id, 555);
        $this->assertSame(2, $mapping['categoryindex']);
        $this->assertSame(3, $mapping['subscaleindex']);
        $this->assertSame(10, $mapping['contextid']);

        $this->assertNull(scale_provisioner::mapping_for($run->id, 999));
    }
}
