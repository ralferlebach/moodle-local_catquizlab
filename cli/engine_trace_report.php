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
 * The engine's request timing of a run's sittings, for the server diagnosis (#110).
 *
 * Reads local_catquiz_trace (local_catquiz 2026100907 or later, setting
 * attempttrace on) for the sittings of one run: percentiles of the whole
 * request, of its database queries and of every span, first item and
 * following items apart. Put beside cli/capacity_report.php and the worker's
 * request files for one load step of the measurement matrix.
 *
 * Options: --runid=N (required), --json.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\engine_perf;

[$options] = cli_get_params(['runid' => 0, 'json' => false]);
$runid = (int) $options['runid'];
if ($runid <= 0) {
    cli_error('--runid=N is required');
}
if (!engine_perf::available()) {
    cli_error('The installed local_catquiz does not trace requests (2026100907 or later does).');
}

$figures = engine_perf::for_run($runid);
if (!empty($options['json'])) {
    echo json_encode(
        ['runid' => $runid, 'tracing' => engine_perf::enabled()] + $figures,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . "\n";
    exit(0);
}

cli_writeln(sprintf(
    'Run %d: %d of %d sittings traced; engine tracing is %s.',
    $runid,
    $figures['traced'],
    $figures['sittings'],
    engine_perf::enabled() ? 'on' : 'off'
));
$fmt = static fn(?float $v): string => $v === null ? '-' : sprintf('%.0f', $v);
foreach ($figures['kinds'] as $kind => $k) {
    cli_writeln('');
    cli_writeln(sprintf('%s: %d request(s)', $kind, $k['count']));
    cli_writeln(sprintf('  %-32s %6s %8s %8s %8s %8s', 'part', 'n', 'p50', 'p95', 'p99', 'max'));
    $rows = ['request (ms)' => $k['requestms'], 'db queries (count)' => $k['queries']] + $k['spans'];
    foreach ($rows as $name => $p) {
        cli_writeln(sprintf(
            '  %-32s %6d %8s %8s %8s %8s',
            substr((string) $name, 0, 32),
            $p['n'],
            $fmt($p['p50']),
            $fmt($p['p95']),
            $fmt($p['p99']),
            $fmt($p['max'])
        ));
    }
}
