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
 * The polling endpoint behind the overview's live counters.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\external\live_status;
use local_catquizlab\local\attempt_scheduler;

/**
 * Live status tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\external\live_status
 */
final class live_status_test extends \advanced_testcase {
    /**
     * The endpoint reports the queue as it is.
     *
     * @return void
     */
    public function test_it_reports_the_current_queue(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;

        foreach (
            [attempt_scheduler::STATUS_QUEUED, attempt_scheduler::STATUS_QUEUED,
            attempt_scheduler::STATUS_COLLECTED] as $status
        ) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => 0, 'status' => $status, 'tries' => 0,
                'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        $status = live_status::execute();

        $this->assertSame(2, $status['queued']);
        $this->assertSame(1, $status['collected']);
        $this->assertNotSame('', $status['headline']);
    }

    /**
     * The declared return structure matches what is returned.
     *
     * @return void
     */
    public function test_the_return_structure_holds(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // A mismatch here is invisible in PHP and fatal over AJAX, where Moodle
        // validates the response and the page gets an exception instead of
        // numbers.
        $clean = \core_external\external_api::clean_returnvalue(
            live_status::execute_returns(),
            live_status::execute()
        );

        foreach (
            ['liveworkers', 'crashedworkers', 'queued', 'running', 'collected',
            'failed', 'state', 'headline', 'detail', 'changed'] as $key
        ) {
            $this->assertArrayHasKey($key, $clean);
        }
    }

    /**
     * The endpoint is registered as an AJAX service.
     *
     * @return void
     */
    public function test_it_is_declared_for_ajax(): void {
        global $CFG;
        $this->resetAfterTest();

        $functions = [];
        require($CFG->dirroot . '/local/catquizlab/db/services.php');

        // Declared and not registered is the failure this cost an hour to find:
        // the page polls, Moodle answers "Can't find data record in database
        // table external_functions", and nothing on the page says so.
        $this->assertArrayHasKey('local_catquizlab_live_status', $functions);
        $this->assertTrue($functions['local_catquizlab_live_status']['ajax']);
        $this->assertSame('read', $functions['local_catquizlab_live_status']['type']);
    }

    /**
     * Registering a service needs the plugin version to move.
     *
     * @return void
     */
    public function test_services_are_registered_in_the_database(): void {
        global $DB;
        $this->resetAfterTest();

        // Moodle only re-reads db/services.php when the plugin version changes.
        // A new function added without bumping it is deployed and unusable.
        $this->assertTrue(
            $DB->record_exists('external_functions', ['name' => 'local_catquizlab_live_status']),
            'The service is declared but not in the database: the plugin version did not move.'
        );
    }
}
