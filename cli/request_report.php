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
 * The worker's requests of a time window in figures: HTTP status, PHP peak memory, fatals (#110, re-audit AK 6).
 *
 * Every request the worker sends is traced on the server (request_trace): its
 * status, its duration and the peak memory PHP reached for it. This script
 * reads that trace for a window — one load step, one long run — and says how
 * many requests ended in 5xx, how many in a PHP fatal, and how high PHP's peak
 * went, overall and per page. With --max-5xx, --max-fatals and --max-peak-mb it
 * is a gate: the exit code is 1 when a limit is broken.
 *
 * Options: --from=UNIXTIME (required), --until=UNIXTIME (default now), --json,
 *          --max-5xx=N, --max-fatals=N, --max-peak-mb=N.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\request_trace;

[$options] = cli_get_params([
    'from' => 0, 'until' => 0, 'json' => false,
    'max-5xx' => -1, 'max-fatals' => -1, 'max-peak-mb' => -1,
]);
$from = (int) $options['from'];
$until = (int) $options['until'] > 0 ? (int) $options['until'] : time();
if ($from <= 0) {
    cli_error('--from=UNIXTIME is required');
}

$figures = ['from' => $from, 'until' => $until, 'requests' => 0, 'status' => [], 'http5xx' => 0, 'fatals' => 0,
    'exceptions' => 0, 'aborted' => 0, 'peakmb' => ['max' => null, 'p50' => null, 'p95' => null], 'bypage' => []];
$peaks = [];
foreach (request_trace::each($from, $until) as $line) {
    $figures['requests']++;
    $status = (int) ($line['status'] ?? 0);
    $figures['status'][$status] = ($figures['status'][$status] ?? 0) + 1;
    $figures['http5xx'] += $status >= 500 ? 1 : 0;
    $figures['fatals'] += empty($line['fatal']) ? 0 : 1;
    $figures['exceptions'] += empty($line['exception']) ? 0 : 1;
    $figures['aborted'] += ($line['connection'] ?? 'normal') === 'normal' ? 0 : 1;
    $peak = (float) ($line['peakmb'] ?? 0);
    $peaks[] = $peak;
    // The page without its query: attempt.php, not attempt.php?cmid=….
    $page = (string) strtok((string) ($line['path'] ?? ''), '?');
    $figures['bypage'][$page]['n'] = ($figures['bypage'][$page]['n'] ?? 0) + 1;
    $figures['bypage'][$page]['peakmb'] = max((float) ($figures['bypage'][$page]['peakmb'] ?? 0), $peak);
    $figures['bypage'][$page]['http5xx'] = ($figures['bypage'][$page]['http5xx'] ?? 0) + ($status >= 500 ? 1 : 0);
}
ksort($figures['status']);
ksort($figures['bypage']);
if ($peaks !== []) {
    sort($peaks);
    $at = static fn(float $q): float => $peaks[(int) min(count($peaks) - 1, floor($q * (count($peaks) - 1) + 0.5))];
    $figures['peakmb'] = ['max' => end($peaks), 'p50' => $at(0.5), 'p95' => $at(0.95)];
}
$figures['memorylimit'] = (string) ini_get('memory_limit');

$broken = [];
foreach (['max-5xx' => 'http5xx', 'max-fatals' => 'fatals'] as $option => $key) {
    if ((int) $options[$option] >= 0 && $figures[$key] > (int) $options[$option]) {
        $broken[] = "{$key} {$figures[$key]} > {$options[$option]}";
    }
}
if (
    (float) $options['max-peak-mb'] >= 0 && $figures['peakmb']['max'] !== null
        && $figures['peakmb']['max'] > (float) $options['max-peak-mb']
) {
    $broken[] = "peak {$figures['peakmb']['max']} MB > {$options['max-peak-mb']} MB";
}
$figures['broken'] = $broken;

if (!empty($options['json'])) {
    echo json_encode($figures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    cli_writeln(sprintf(
        'Requests %s–%s: %d; HTTP 5xx: %d; PHP fatals: %d; exceptions: %d; aborted: %d',
        gmdate('Y-m-d H:i:s', $from),
        gmdate('Y-m-d H:i:s', $until),
        $figures['requests'],
        $figures['http5xx'],
        $figures['fatals'],
        $figures['exceptions'],
        $figures['aborted']
    ));
    cli_writeln(sprintf(
        'PHP peak memory (MB): max %s, p95 %s, p50 %s (CLI memory_limit %s; the web server\'s is its own)',
        $figures['peakmb']['max'] ?? '-',
        $figures['peakmb']['p95'] ?? '-',
        $figures['peakmb']['p50'] ?? '-',
        $figures['memorylimit']
    ));
    foreach ($figures['status'] as $status => $n) {
        cli_writeln(sprintf('  status %d: %d', $status, $n));
    }
    foreach ($figures['bypage'] as $page => $p) {
        cli_writeln(sprintf('  %-48s n=%-6d peak %6.1f MB  5xx %d', substr($page, 0, 48), $p['n'], $p['peakmb'], $p['http5xx']));
    }
    foreach ($broken as $line) {
        cli_writeln('LIMIT BROKEN: ' . $line);
    }
}
exit($broken === [] ? 0 : 1);
