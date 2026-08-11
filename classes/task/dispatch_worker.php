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
 * Ad-hoc task that launches the local Puppeteer worker (E3.2 exec variant).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

use local_catquizlab\local\worker_launcher;

/**
 * Launches the exec-variant worker to drain the attempt queue on this host.
 *
 * Respects the master switch and the exec-worker setting; it is a no-op unless
 * both are on and the worker is configured.
 */
class dispatch_worker extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:dispatchworker', 'local_catquizlab');
    }

    /**
     * Launch the worker.
     *
     * @return void
     */
    public function execute(): void {
        if (!get_config('local_catquizlab', 'enabled')) {
            return;
        }

        $result = worker_launcher::launch(worker_launcher::config_from_settings());
        if ($result === null) {
            mtrace('local_catquizlab: exec worker not launched (disabled or not configured).');
            return;
        }
        mtrace("local_catquizlab: exec worker finished with exit code {$result['exitcode']}.");
    }
}
