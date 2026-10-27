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
 * Does this PHP run shutdown functions after running out of memory at all?
 *
 * The control for resource_guard_probe.php: the same exhaustion without the
 * guard, and a bare shutdown function that only says it ran. Where it does not
 * run, no guard can say anything, and the guard's own test has nothing to test.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// phpcs:disable moodle.Files.RequireLogin.Missing

register_shutdown_function(static function (): void {
    echo "RUNTIME-SHUTDOWN-RAN\n";
});

echo "RUNTIME-START\n";

$hold = [];
while (true) {
    $hold[] = str_repeat('x', 1024 * 1024);
}
