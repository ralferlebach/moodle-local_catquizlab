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
 * Ad-hoc task that collects a run's attempt traces from the engine.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

use local_catquizlab\local\attempt_collector;

/**
 * Copies finished engine attempts of a run into lab traces off the web request.
 *
 * The worker already triggers per-attempt collection on completion; this task
 * collects a whole run in the background — useful for re-collection or when a
 * completion did not carry the engine attempt id. The run id travels in the
 * task's custom data. Collection reads engine tables, so it is a no-op (zero
 * collected) when the engine is absent.
 */
class collect_attempts extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:collectattempts', 'local_catquizlab');
    }

    /**
     * Collect the attempts of the run named in the custom data.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $runid = (int) ($data->runid ?? 0);
        if ($runid <= 0) {
            return;
        }

        $result = attempt_collector::collect_run($runid);
        mtrace(
            "local_catquizlab: collected {$result['collected']}/{$result['candidates']} attempt(s) "
            . "for run {$runid} in {$result['runtimems']} ms."
        );
    }
}
