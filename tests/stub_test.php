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
 * Installation and stub-scope tests for local_catquizlab.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\environment;

/**
 * Verifies the stub installs correctly: schema present, generator usable,
 * environment detection callable.
 *
 * @covers \local_catquizlab\local\environment
 */
final class stub_test extends \advanced_testcase {
    /**
     * The experiment table from db/install.xml exists after installation.
     *
     * @return void
     */
    public function test_experiment_table_exists(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $this->assertTrue(
            $dbman->table_exists('local_catquizlab_experiment'),
            'Table local_catquizlab_experiment must be created by db/install.xml.'
        );
    }

    /**
     * The plugin generator creates a retrievable experiment record.
     *
     * @return void
     */
    public function test_generator_creates_experiment(): void {
        global $DB;

        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment(['name' => 'Baseline ideal pool', 'tier' => 'baseline']);

        $this->assertGreaterThan(0, $experiment->id);

        $stored = $DB->get_record('local_catquizlab_experiment', ['id' => $experiment->id], '*', MUST_EXIST);
        $this->assertSame('Baseline ideal pool', $stored->name);
        $this->assertSame('baseline', $stored->tier);

        $config = json_decode($stored->configjson, true);
        $this->assertIsArray($config);
        $this->assertArrayHasKey('seed', $config);
    }

    /**
     * Environment detection is callable and consistent.
     *
     * The CAT engine may or may not be installed on the site running this
     * suite (it is absent in CI, present on the project test system), so the
     * individual results are not asserted — only type and the invariant that
     * engine_available() is the conjunction of both checks.
     *
     * @return void
     */
    public function test_environment_detection(): void {
        $catquiz = environment::catquiz_available();
        $adaptivequiz = environment::adaptivequiz_available();

        $this->assertIsBool($catquiz);
        $this->assertIsBool($adaptivequiz);
        $this->assertSame($catquiz && $adaptivequiz, environment::engine_available());
    }
}
