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

namespace local_catquizlab\task;

use local_catquizlab\local\research_package;

/**
 * Builds a research data package off the web request and keeps it for download (#156 AK6).
 *
 * For packages too large to stream within a request: the ZIP goes into the plugin's export file area, where the
 * export tab lists it and the pluginfile handler serves it to whoever may export.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_research_package extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:researchpackage', 'local_catquizlab');
    }

    /**
     * Build and store the package.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $filter = (array) json_decode((string) ($data->filter ?? '[]'), true);
        $experimentid = (int) ($filter['experimentid'] ?? 0);
        if ($experimentid <= 0) {
            return;
        }
        $time = time();
        $path = make_request_directory() . '/' . research_package::filename($experimentid, $time);
        $manifest = research_package::write_file($filter, $path);
        $context = \context_system::instance();
        get_file_storage()->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_catquizlab',
            'filearea' => 'export',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => basename($path),
            'userid' => (int) ($data->userid ?? 0) ?: null,
            'timecreated' => $time,
            'timemodified' => $time,
        ], $path);
        @unlink($path);
        mtrace('local_catquizlab: research package of experiment ' . $experimentid . ' stored, '
            . array_sum(array_column($manifest['tables'], 'rows')) . ' rows.');
    }

    /**
     * Queue a package.
     *
     * @param array $filter The results filter; names the experiment.
     * @param int $userid Who asked.
     * @return void
     */
    public static function queue(array $filter, int $userid): void {
        $task = new self();
        // The filter as JSON: custom data turns arrays into objects, and the filter's keys must come back as they were.
        $task->set_custom_data(['filter' => json_encode($filter), 'experimentid' => (int) ($filter['experimentid'] ?? 0),
            'userid' => $userid]);
        $task->set_userid($userid);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * The packages of an experiment that are queued or being built.
     *
     * @param int $experimentid The experiment.
     * @return array[] running (bool) and since (Unix time: started, or due).
     */
    public static function pending(int $experimentid): array {
        $out = [];
        foreach (\core\task\manager::get_adhoc_tasks(self::class) as $task) {
            if ((int) ($task->get_custom_data()->experimentid ?? 0) === $experimentid) {
                $started = (int) $task->get_timestarted();
                $out[] = ['running' => $started > 0, 'since' => $started > 0 ? $started : (int) $task->get_next_run_time()];
            }
        }

        return $out;
    }

    /**
     * The stored packages of an experiment, newest first.
     *
     * @param int $experimentid The experiment.
     * @return \stored_file[]
     */
    public static function stored(int $experimentid): array {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_catquizlab',
            'export',
            0,
            'timemodified DESC',
            false
        );

        return array_values(array_filter($files, static fn(\stored_file $file): bool =>
            str_starts_with($file->get_filename(), research_package::FILEPREFIX . $experimentid . '-')));
    }
}
