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
 * A day of request traces the size a load test leaves, for the logs page (#132).
 *
 * The logs page ran out of PHP memory on a day file of a few hundred thousand
 * lines. This writes such a file — every line as request_trace::record()
 * writes it, one in every 997 a server error — so the interface test opens the
 * page against the size that broke it. Prints LOGS_ERRORS=<n> (the number of
 * server errors written) for a CI job to put into its environment.
 *
 * Options: --lines=250000, --hours=6 (spread over the last hours).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\request_trace;

[$options] = cli_get_params(['lines' => 250000, 'hours' => 6]);
$n = max(1, (int) $options['lines']);
$span = max(1, (int) $options['hours']) * HOURSECS;

$dir = $CFG->dataroot . '/' . request_trace::DIRECTORY;
if (!is_dir($dir) && !mkdir($dir, $CFG->directorypermissions ?? 02777, true) && !is_dir($dir)) {
    cli_error('Cannot create ' . $dir);
}

$start = time() - 60 - $span;
$handles = [];
$errors = 0;
for ($i = 0; $i < $n; $i++) {
    $started = $start + $span * $i / $n;
    $status = $i % 997 === 0 ? 500 : 200;
    $errors += $status === 500 ? 1 : 0;
    $line = request_trace::record(
        ['correlationid' => 'seed' . ($i % 50), 'attemptid' => 0, 'execution' => 1, 'method' => 'POST',
            'path' => '/mod/adaptivequiz/attempt.php?seed=' . $i, 'started' => $started],
        $status,
        $started + 0.2,
        0,
        25 * 1048576,
        null,
        null
    );
    $file = $dir . '/' . substr($line['time'], 0, 10) . '.jsonl';
    $handles[$file] ??= fopen($file, 'ab');
    fwrite($handles[$file], json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
array_map('fclose', $handles);

cli_writeln('LOGS_ERRORS=' . $errors);
