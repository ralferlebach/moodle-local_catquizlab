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
 * Ad-hoc task that sets up a run end to end (E7).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

use local_catquizlab\local\run_orchestrator;

/**
 * Sets up a run's scales, items, test, people and attempts off the web request.
 */
class orchestrate_run extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:orchestraterun', 'local_catquizlab');
    }

    /**
     * Run the setup.
     *
     * @return void
     */
    public function execute(): void {
        // Announce which task this is, and continue the id of the click that
        // queued it: a failure inside a task otherwise reads as a failure from
        // nowhere.
        \local_catquizlab\local\debug_trace::enter_task(
            '\\local_catquizlab\\task\\orchestrate_run',
            (string) ($this->get_custom_data()->correlationid ?? '')
        );

        $data = $this->get_custom_data();
        $runid = (int) ($data->runid ?? 0);
        if ($runid <= 0) {
            return;
        }
        $options = isset($data->options) ? (array) $data->options : [];

        $result = run_orchestrator::setup($runid, $options);

        // One place decides what the run's state is now; the task only reports
        // what happened.
        \local_catquizlab\local\run_lifecycle::provisioned(
            $runid,
            !empty($result['ok']),
            (string) ($result['reason'] ?? '')
        );
        $status = $result['ok'] ? 'ok' : ('skipped: ' . ($result['reason'] ?? 'unknown'));
        mtrace("local_catquizlab: run {$runid} setup {$status}.");
    }

    /**
     * Queue a setup for a run.
     *
     * @param int $runid The run.
     * @param array $options Setup options.
     * @return void
     */
    public static function queue(int $runid, array $options = []): void {
        $task = new self();
        $task->set_custom_data(['runid' => $runid, 'options' => $options]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
