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
 * A results request that really dies of memory or of time, with half its analysis written (#147, D2).
 *
 * Run by resource_guard_kinds_test in its own PHP process: "memory" grows until the limit, "time" loops until the
 * execution time limit. Both write a part of a table first, inside the guard's buffer, as a tab does.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// phpcs:disable moodle.Files.RequireLogin.Missing

require(__DIR__ . '/../../classes/local/log_redactor.php');
require(__DIR__ . '/../../classes/local/resource_guard.php');

use local_catquizlab\local\resource_guard;

$mode = $argv[1] ?? 'memory';
$texts = [
    resource_guard::MEMORY => 'PROBE-MEMORY limit={limit} phase={phase}',
    resource_guard::SYSTEM => 'PROBE-SYSTEM limit={limit}',
    resource_guard::TIME => 'PROBE-TIME limit={limit}',
    'requestid' => 'rid {rid}',
];
// What Moodle's own shutdown handler does first: it raises the limit to unlimited so it can finish.
register_shutdown_function(static function (): void {
    ini_set('memory_limit', '-1');
});
register_shutdown_function(static function () use ($texts): void {
    $error = error_get_last();
    resource_guard::at_shutdown($texts);
    $line = resource_guard::log_line($error);
    echo "\nPROBE-LOG " . ($line ?? 'NONE') . "\n";
});

echo "PROBE-HEADER\n";
resource_guard::buffer();
resource_guard::phase('basis');
resource_guard::step('funnel');
echo '<table><tr><td>PROBE-PARTIAL-TABLE';
resource_guard::phase('tab');
resource_guard::step('overviewpass');

if ($mode === 'time') {
    set_time_limit(1);
    $x = 0;
    while (true) {
        $x++;
    }
}
$hold = [];
while (true) {
    $hold[] = str_repeat('x', 1024 * 1024);
}
