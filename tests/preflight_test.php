<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_catquizlab;

use local_catquizlab\local\environment;
use local_catquizlab\local\preflight;

/**
 * Moodle 5: no run starts while the question banks are still being moved (#135).
 *
 * The Moodle 5.2 end-to-end job installed Moodle, prepared a run and sent the
 * worker to an adaptive quiz that said "Item bank is not configured properly":
 * the installer had queued the transfer of question banks into question bank
 * activities, and without cron it never ran. The worker reported only that it
 * found no way to start the attempt.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\environment
 * @covers     \local_catquizlab\local\preflight
 */
final class preflight_test extends \advanced_testcase {
    /**
     * A queued question bank transfer blocks the start, by name, where Moodle has one (5.0 and later); before Moodle 5
     * there is no transfer, nothing is pending and nothing blocks. One test for both, so that neither is a skip (#140).
     *
     * @return void
     */
    public function test_a_queued_question_bank_transfer_blocks_the_start_where_there_is_one(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $blocker = get_string('preflight:qbanktransfer', 'local_catquizlab');

        if (!class_exists('\mod_qbank\task\transfer_question_categories')) {
            // Moodle 4.5: no transfer.
            $this->assertFalse(environment::question_bank_transfer_pending());
            $this->assertNotContains($blocker, preflight::check()['blockers']);
            return;
        }

        // Whatever the test site left queued is gone: nothing pending.
        $DB->delete_records_select('task_adhoc', $DB->sql_like('classname', ':c'), ['c' => '%transfer_question%']);
        $this->assertFalse(environment::question_bank_transfer_pending());
        $this->assertNotContains($blocker, preflight::check()['blockers']);

        // What an installation or an upgrade to Moodle 5 queues.
        \core\task\manager::queue_adhoc_task(new \mod_qbank\task\transfer_question_categories());
        $this->assertTrue(environment::question_bank_transfer_pending());
        $check = preflight::check();
        $this->assertFalse($check['ok']);
        $this->assertContains($blocker, $check['blockers']);
    }
}
