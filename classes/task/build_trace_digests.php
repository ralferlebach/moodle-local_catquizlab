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

use local_catquizlab\local\trace_digest;

/**
 * Write the digest of the sittings collected before 0.7.57, a few thousand at a time (#147).
 *
 * Queued by the upgrade. Requeues itself while sittings without a digest are left, so that no single run of cron
 * reads gigabytes of traces. Until a sitting has its digest the results take it from the trace as they read it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_trace_digests extends \core\task\adhoc_task {
    /**
     * Fill in a share, and queue the rest.
     *
     * @return void
     */
    public function execute(): void {
        $done = trace_digest::fill(5000, 120);
        $left = trace_digest::missing();
        mtrace('local_catquizlab: wrote ' . $done . ' trace digests, ' . $left . ' left.');
        if ($left > 0 && $done > 0) {
            // Not checked for an existing one: this task is still queued while it runs, and would find itself.
            \core\task\manager::queue_adhoc_task(new self());
        }
    }
}
