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
 * Ad-hoc task: one pipeline tick now, not at the next five-minute mark (#151).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

/**
 * The scheduled tick's work, once, as soon as cron runs ad-hoc tasks.
 *
 * Asked for when something the tick acts on has just happened: a start was accepted (the line can move), a run became
 * ready (workers can start). Without it, both waited for the next scheduled tick, up to five minutes each — ten
 * minutes between "Start experiment" and the first sitting, for nothing. The tick's own work is safe to run beside
 * the scheduled one: the line advances under a lock, workers start only into free slots.
 *
 * Queued once: a kick already waiting is not doubled.
 */
class kick_pipeline extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:kickpipeline', 'local_catquizlab');
    }

    /**
     * Run the tick's work once.
     *
     * @return void
     */
    public function execute(): void {
        (new pipeline_tick())->execute();
    }

    /**
     * Ask for a tick now, once.
     *
     * @param string $why What happened, for the log.
     * @return void
     */
    public static function queue(string $why): void {
        $task = new self();
        $task->set_custom_data(['why' => $why]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
