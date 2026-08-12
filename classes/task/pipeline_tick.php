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
 * Scheduled task that keeps the attempt pipeline moving (E3.1/E3.2).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\worker_launcher;

/**
 * Periodic maintenance: reclaim crashed attempts, then dispatch the worker pool.
 *
 * Reclaiming is always safe (lab-store bookkeeping). Dispatching only happens when
 * the exec worker is enabled and configured, so on hubs, worker-less nodes and CI
 * the task simply reclaims and returns.
 */
class pipeline_tick extends \core\task\scheduled_task {
    /** @var int Consider a running attempt crashed after this many seconds. */
    public const STALE_SECONDS = 1800;

    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:pipelinetick', 'local_catquizlab');
    }

    /**
     * Run one maintenance tick.
     *
     * @return void
     */
    public function execute(): void {
        if (!get_config('local_catquizlab', 'enabled')) {
            return;
        }

        $reclaimed = attempt_scheduler::reclaim_stale(null, self::STALE_SECONDS);
        if ($reclaimed > 0) {
            mtrace("local_catquizlab: reclaimed {$reclaimed} stale attempt(s).");
        }

        $result = worker_launcher::launch_pool(worker_launcher::config_from_settings());
        if ($result !== null) {
            mtrace("local_catquizlab: dispatched worker pool ({$result['launched']}).");
        }
    }
}
