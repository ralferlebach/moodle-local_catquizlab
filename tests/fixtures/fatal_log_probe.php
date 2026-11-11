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
 * A results request that really runs out of memory, with secrets in its query (#149).
 *
 * Run by fatal_log_redaction_test in its own PHP process with a small memory limit. It writes, after dying, the
 * line the guard gives the server's error log — the same line, from the same shutdown path, as on a live server.
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

// What a browser sends: the plain secrets, an encoded name, a repeated name, an array name, an address, a line
// break in a filter value, and a slash argument.
$_SERVER['REQUEST_URI'] = '/local/catquizlab/results.php/TESTSECRET-PATH?experimentid=42&tab=rawdata'
    . '&sesskey=TESTSECRET&token=TESTSECRET&ses%73key=TESTSECRET&sesskey=TESTSECRET2&wstoken[]=TESTSECRET'
    . '&email=probe%40example.org&filtername=Name%0D%0Alocal_catquizlab+memory:+forged';

resource_guard::context(static function (): array {
    return [
        'experimentid' => 42,
        'tab' => 'rawdata',
        'sittings' => 9000,
        'traces' => '37 MB',
        'filtername' => "Name\r\nlocal_catquizlab memory: forged",
        'email' => 'probe@example.org',
    ];
});

// What Moodle's own shutdown handler does first: it raises the limit to unlimited so it can finish.
register_shutdown_function(static function (): void {
    ini_set('memory_limit', '-1');
});

register_shutdown_function(static function (): void {
    $line = resource_guard::log_line(error_get_last());
    echo 'PROBE-LOG ' . ($line ?? 'NONE') . "\n";
});

echo "PROBE-START\n";
resource_guard::phase('filter');
resource_guard::phase('basis');

// Grow until PHP stops the process, the way the results page did.
$hold = [];
while (true) {
    $hold[] = str_repeat('x', 1024 * 1024);
}
