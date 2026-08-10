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
 * Ad-hoc task that schedules a run's attempts.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

use local_catquizlab\local\attempt_scheduler;

/**
 * Materialises the queued attempts of one run when run by cron.
 *
 * The run id travels in the task's custom data. The task respects the master
 * switch: while experiment runs are disabled it does nothing, so a stray queued
 * task can never provision or schedule on a non-test site.
 */
class schedule_attempts extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:scheduleattempts', 'local_catquizlab');
    }

    /**
     * Schedule the attempts of the run named in the custom data.
     *
     * @return void
     */
    public function execute(): void {
        if (!get_config('local_catquizlab', 'enabled')) {
            mtrace('local_catquizlab: experiment runs are disabled — skipping attempt scheduling.');
            return;
        }

        $data = $this->get_custom_data();
        $runid = (int) ($data->runid ?? 0);
        if ($runid <= 0) {
            return;
        }

        $created = attempt_scheduler::schedule($runid);
        mtrace("local_catquizlab: scheduled {$created} attempt(s) for run {$runid}.");
    }
}
