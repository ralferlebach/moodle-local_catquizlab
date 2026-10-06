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

/**
 * A process that really runs out of memory, with the guard in place.
 *
 * Run by resource_guard_test in its own PHP process with a small memory
 * limit. It loads nothing of Moodle but the guard itself — the point is that
 * the guard works at the moment the process has already died, not that Moodle
 * can be loaded at all.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// phpcs:disable moodle.Files.RequireLogin.Missing

require(__DIR__ . '/../../classes/local/resource_guard.php');

$texts = [
    \local_catquizlab\local\resource_guard::MEMORY => 'PROBE-OUT-OF-MEMORY limit={limit}',
    \local_catquizlab\local\resource_guard::TIME   => 'PROBE-OUT-OF-TIME limit={limit}',
];

register_shutdown_function(static function () use ($texts): void {
    $html = \local_catquizlab\local\resource_guard::message_for(error_get_last(), $texts);
    echo $html ?? 'PROBE-NO-MESSAGE';
});

echo "PROBE-START\n";

// Grow until PHP stops the process, the way the results page did.
$hold = [];
while (true) {
    $hold[] = str_repeat('x', 1024 * 1024);
}
