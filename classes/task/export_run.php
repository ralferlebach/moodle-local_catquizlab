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
 * Ad-hoc task that exports a run's answer matrix to files (E6.3).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

use local_catquizlab\local\run_exporter;

/**
 * Exports a run's answer matrix off the web request.
 */
class export_run extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:exportrun', 'local_catquizlab');
    }

    /**
     * Run the export.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $runid = (int) ($data->runid ?? 0);
        if ($runid <= 0) {
            return;
        }
        $formats = isset($data->formats) ? (array) $data->formats : ['csv'];

        $files = run_exporter::export_to_files($runid, $formats);
        mtrace('local_catquizlab: exported ' . count($files) . " file(s) for run {$runid}.");
    }

    /**
     * Queue an export for a run.
     *
     * @param int $runid The run.
     * @param string[] $formats The formats to export.
     * @return void
     */
    public static function queue(int $runid, array $formats = ['csv']): void {
        $task = new self();
        $task->set_custom_data(['runid' => $runid, 'formats' => $formats]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
