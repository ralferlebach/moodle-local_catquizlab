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
 *          --max-5xx=N, --max-fatals=N, --max-peak-mb=N,
 *          --raw=FILE (every request of the window as a CSV line: page, status, duration, peak, RSS, queries — for
 *          statistics over the whole sample, #164),
 *          --peak-seconds=N (10: the width of the windows in which slow requests of several sittings are counted
 *          together, #177).
 *
 * Per page the request time as n, p50, p95, p99 and maximum; the requests above their page's p99 (the outliers,
 * with sitting and execution); and the windows in which requests of several sittings were slow at the same time —
 * a synchronous peak, said as such, not explained: what it coincided with is for the samples beside it to say (#177).
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
    'from' => 0, 'until' => 0, 'json' => false, 'raw' => '',
    'max-5xx' => -1, 'max-fatals' => -1, 'max-peak-mb' => -1, 'peak-seconds' => 10,
]);
$from = (int) $options['from'];
$until = (int) $options['until'] > 0 ? (int) $options['until'] : time();
if ($from <= 0) {
    cli_error('--from=UNIXTIME is required');
}

$figures = ['from' => $from, 'until' => $until, 'requests' => 0, 'status' => [], 'http5xx' => 0, 'fatals' => 0,
    'exceptions' => 0, 'aborted' => 0, 'peakmb' => ['max' => null, 'p50' => null, 'p95' => null], 'bypage' => [],
    'problems' => [], 'problemsomitted' => 0, 'rssmb' => ['max' => null, 'p50' => null, 'p95' => null],
    'queries' => ['max' => null, 'p50' => null, 'p95' => null], 'processes' => 0, 'sittings' => 0, 'correlations' => 0];
// Every request that ended in 4xx/5xx, with an exception, a fatal or a broken
// connection is named, not only counted: "exceptions: 4" and "status 404: 4"
// in a green run said that something happened, not what (#135 re-audit).
$maxproblems = 50;
$peaks = [];
$rss = [];
$queries = [];
$pids = [];
$sittings = [];
$correlations = [];
$starts = [];
$durations = [];
// Request time per page (#177).
$pagedurations = [];
// Every request of the window as one CSV line, for statistics over the whole sample (#164 AK-08): no values of
// parameters, only the page, the status and the figures.
$raw = (string) $options['raw'] !== '' ? fopen((string) $options['raw'], 'w') : null;
if ($raw) {
    fputcsv($raw, ['unixms', 'page', 'method', 'status', 'durationms', 'peakmb', 'rssmb', 'queries', 'connection', 'pid',
        'attemptid', 'correlationid']);
}
$weblimits = [];
foreach (request_trace::each($from, $until) as $line) {
    $figures['requests']++;
    // The memory_limit each request ran under, as it reported it (since 0.7.47; the web server's, not this script's).
    if (isset($line['memorylimit']) && $line['memorylimit'] !== '') {
        $weblimits[(string) $line['memorylimit']] = ($weblimits[(string) $line['memorylimit']] ?? 0) + 1;
    }
    if (isset($line['durationms'])) {
        $durations[] = (int) $line['durationms'];
        $pagedurations[(string) strtok((string) ($line['path'] ?? ''), '?')][] = (int) $line['durationms'];
    }
    if ($raw) {
        fputcsv($raw, [(int) ($line['unixms'] ?? 0), (string) strtok((string) ($line['path'] ?? ''), '?'),
            (string) ($line['method'] ?? ''), (int) ($line['status'] ?? 0), (int) ($line['durationms'] ?? -1),
            (float) ($line['peakmb'] ?? 0), $line['rssmb'] ?? '', $line['queries'] ?? '',
            (string) ($line['connection'] ?? 'normal'), (int) ($line['pid'] ?? 0), (int) ($line['attemptid'] ?? 0),
            (string) ($line['correlationid'] ?? '')]);
    }
    $status = (int) ($line['status'] ?? 0);
    $figures['status'][$status] = ($figures['status'][$status] ?? 0) + 1;
    $figures['http5xx'] += $status >= 500 ? 1 : 0;
    $figures['fatals'] += empty($line['fatal']) ? 0 : 1;
    $figures['exceptions'] += empty($line['exception']) ? 0 : 1;
    $figures['aborted'] += ($line['connection'] ?? 'normal') === 'normal' ? 0 : 1;
    $peak = (float) ($line['peakmb'] ?? 0);
    $peaks[] = $peak;
    // The process's resident memory and the request's queries, where the trace has them (0.7.57 on, #110).
    if (isset($line['rssmb'])) {
        $rss[] = (float) $line['rssmb'];
    }
    if (isset($line['queries'])) {
        $queries[] = (int) $line['queries'];
    }
    $pids[(int) ($line['pid'] ?? 0)] = true;
    $sittings[(int) ($line['attemptid'] ?? 0)] = true;
    $correlations[(string) ($line['correlationid'] ?? '')] = true;
    // When each worker's sitting first reached the server (#120): starts staggered, or a wave.
    $cid = (string) ($line['correlationid'] ?? '');
    $ms = (int) ($line['unixms'] ?? 0);
    if ($cid !== '' && (!isset($starts[$cid]) || $ms < $starts[$cid])) {
        $starts[$cid] = $ms;
    }
    // The page without its query: attempt.php, not attempt.php?cmid=….
    $page = (string) strtok((string) ($line['path'] ?? ''), '?');
    $figures['bypage'][$page]['n'] = ($figures['bypage'][$page]['n'] ?? 0) + 1;
    $figures['bypage'][$page]['peakmb'] = max((float) ($figures['bypage'][$page]['peakmb'] ?? 0), $peak);
    $figures['bypage'][$page]['http5xx'] = ($figures['bypage'][$page]['http5xx'] ?? 0) + ($status >= 500 ? 1 : 0);
    if ($status >= 400 || !empty($line['exception']) || !empty($line['fatal']) || ($line['connection'] ?? 'normal') !== 'normal') {
        if (count($figures['problems']) >= $maxproblems) {
            $figures['problemsomitted']++;
            continue;
        }
        $exception = is_array($line['exception'] ?? null) ? $line['exception'] : [];
        $fatal = is_array($line['fatal'] ?? null) ? $line['fatal'] : [];
        $figures['problems'][] = [
            'time' => (string) ($line['time'] ?? ''),
            'status' => $status,
            'method' => (string) ($line['method'] ?? ''),
            'page' => $page,
            'attemptid' => (int) ($line['attemptid'] ?? 0),
            'correlationid' => (string) ($line['correlationid'] ?? ''),
            'connection' => (string) ($line['connection'] ?? 'normal'),
            'exception' => $exception === [] ? '' : trim(($exception['class'] ?? '') . ' '
                . ($exception['errorcode'] ?? '') . ': ' . ($exception['message'] ?? '')),
            'where' => $exception === [] ? '' : ($exception['file'] ?? '') . ':' . ($exception['line'] ?? 0),
            'fatal' => $fatal === [] ? '' : ($fatal['message'] ?? '') . ' @ ' . ($fatal['file'] ?? '') . ':'
                . ($fatal['line'] ?? 0),
        ];
    }
}
ksort($figures['status']);
ksort($figures['bypage']);
if ($peaks !== []) {
    sort($peaks);
    $at = static fn(float $q): float => $peaks[(int) min(count($peaks) - 1, floor($q * (count($peaks) - 1) + 0.5))];
    $figures['peakmb'] = ['max' => end($peaks), 'p50' => $at(0.5), 'p95' => $at(0.95)];
}
$quantiles = static function (array $values): array {
    if ($values === []) {
        return ['max' => null, 'p50' => null, 'p95' => null];
    }
    sort($values);
    $at = static fn(float $q) => $values[(int) min(count($values) - 1, floor($q * (count($values) - 1) + 0.5))];
    return ['max' => end($values), 'p50' => $at(0.5), 'p95' => $at(0.95)];
};
$figures['rssmb'] = $quantiles($rss);
// Request time over every request of the window, not a top-n: n, mean, standard deviation, 95 % interval of the
// mean, p50/p95/p99 and the maximum (#164 AK-08).
$figures['durationms'] = null;
if ($durations !== []) {
    sort($durations);
    $n = count($durations);
    $mean = array_sum($durations) / $n;
    $sd = $n > 1 ? sqrt(array_sum(array_map(static fn($v) => ($v - $mean) ** 2, $durations)) / ($n - 1)) : 0.0;
    $q = static fn(float $p): int => $durations[(int) min($n - 1, floor($p * ($n - 1) + 0.5))];
    $figures['durationms'] = ['n' => $n, 'mean' => round($mean, 1), 'sd' => round($sd, 1),
        'ci95' => [round($mean - 1.96 * $sd / sqrt($n), 1), round($mean + 1.96 * $sd / sqrt($n), 1)],
        'p50' => $q(0.5), 'p95' => $q(0.95), 'p99' => $q(0.99), 'max' => end($durations)];
}
if ($raw) {
    fclose($raw);
}
$figures['queries'] = $quantiles($queries);
// Web server processes that served the window: under prefork or mod_php, the slots that were busy at some point.
$figures['processes'] = count($pids);
$figures['sittings'] = count(array_filter(array_keys($sittings)));
$figures['correlations'] = count($correlations);
// The first request of each correlation, in order, as seconds after the first of all; and the closest two starts.
asort($starts);
$first = $starts === [] ? 0 : reset($starts);
$figures['starts'] = array_map(static fn(int $ms): float => round(($ms - $first) / 1000, 2), array_slice($starts, 0, 200, true));
$gaps = [];
$previous = null;
foreach ($starts as $ms) {
    if ($previous !== null) {
        $gaps[] = ($ms - $previous) / 1000;
    }
    $previous = $ms;
}
$figures['startgap'] = $gaps === [] ? null : ['min' => round(min($gaps), 2), 'max' => round(max($gaps), 2),
    'mean' => round(array_sum($gaps) / count($gaps), 2)];
// Per page: n, p50, p95, p99, max of the request time (#177 AK1). Then a second pass over the same window: the
// requests above their page's p99, and the windows of --peak-seconds in which requests of two or more sittings were
// above their page's p95 together. Two passes, so that no request has to be held: the first pass keeps only figures.
$thresholds = [];
foreach ($pagedurations as $page => $values) {
    sort($values);
    $n = count($values);
    $q = static fn(float $p): int => $values[(int) min($n - 1, floor($p * ($n - 1) + 0.5))];
    $figures['bypage'][$page]['durationms'] = ['n' => $n, 'p50' => $q(0.5), 'p95' => $q(0.95), 'p99' => $q(0.99),
        'max' => end($values)];
    $thresholds[$page] = ['p95' => $q(0.95), 'p99' => $q(0.99)];
}
unset($pagedurations);
$width = max(1, (int) $options['peak-seconds']) * 1000;
$outliers = [];
$buckets = [];
foreach ($thresholds === [] ? [] : request_trace::each($from, $until) as $line) {
    if (!isset($line['durationms'])) {
        continue;
    }
    $page = (string) strtok((string) ($line['path'] ?? ''), '?');
    $ms = (int) $line['durationms'];
    $limits = $thresholds[$page] ?? null;
    if ($limits === null || $ms <= $limits['p95']) {
        continue;
    }
    $at = (int) ($line['unixms'] ?? 0);
    if ($ms > $limits['p99']) {
        $outliers[] = ['time' => (string) ($line['time'] ?? ''), 'page' => $page, 'durationms' => $ms,
            'p99' => $limits['p99'], 'attemptid' => (int) ($line['attemptid'] ?? 0),
            'execution' => (int) ($line['execution'] ?? 0), 'pid' => (int) ($line['pid'] ?? 0)];
    }
    $key = intdiv($at, $width);
    $buckets[$key]['slow'] = ($buckets[$key]['slow'] ?? 0) + 1;
    $buckets[$key]['sittings'][(int) ($line['attemptid'] ?? 0)] = true;
    $buckets[$key]['pages'][$page] = ($buckets[$key]['pages'][$page] ?? 0) + 1;
    $buckets[$key]['maxms'] = max((int) ($buckets[$key]['maxms'] ?? 0), $ms);
}
usort($outliers, static fn(array $a, array $b): int => $b['durationms'] <=> $a['durationms']);
$figures['outliers'] = ['n' => count($outliers), 'top' => array_slice($outliers, 0, 20)];
$peaks2 = [];
foreach ($buckets as $key => $bucket) {
    $sittingsinpeak = count(array_filter(array_keys($bucket['sittings'])));
    if ($sittingsinpeak >= 2 && $bucket['slow'] >= 3) {
        arsort($bucket['pages']);
        $peaks2[] = ['from' => gmdate('Y-m-d\TH:i:s\Z', intdiv($key * $width, 1000)), 'seconds' => $width / 1000,
            'slow' => $bucket['slow'], 'sittings' => $sittingsinpeak, 'maxms' => $bucket['maxms'], 'pages' => $bucket['pages']];
    }
}
usort($peaks2, static fn(array $a, array $b): int => $b['slow'] <=> $a['slow'] ?: strcmp($a['from'], $b['from']));
$figures['synchronouspeaks'] = ['n' => count($peaks2), 'top' => array_slice($peaks2, 0, 10)];

// Until 0.7.77 'memorylimit' was this script's own (the command line's) — read as the web server's, it was wrong
// (local run 22.11.2026: 1024M beside the web server's 512M). Now the web requests' own, the most frequent first,
// and the command line's apart.
arsort($weblimits);
$figures['memorylimit'] = $weblimits === [] ? null : (string) array_key_first($weblimits);
$figures['memorylimits'] = $weblimits;
$figures['clilimit'] = (string) ini_get('memory_limit');

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
// A gate over nothing is no gate: an empty window (a server that never started,
// a wrong --from) fails it instead of passing with "0 HTTP 5xx" (#135 AK 5).
$gated = (int) $options['max-5xx'] >= 0 || (int) $options['max-fatals'] >= 0 || (float) $options['max-peak-mb'] >= 0;
if ($gated && $figures['requests'] === 0) {
    $broken[] = 'no request in the window: nothing was measured';
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
        'PHP peak memory (MB): max %s, p95 %s, p50 %s (the requests\' memory_limit %s; the command line\'s %s)',
        $figures['peakmb']['max'] ?? '-',
        $figures['peakmb']['p95'] ?? '-',
        $figures['peakmb']['p50'] ?? '-',
        $figures['memorylimit'] ?? 'not reported',
        $figures['clilimit']
    ));
    cli_writeln(sprintf(
        'Process RSS (MB): max %s, p95 %s, p50 %s; queries per request: max %s, p95 %s, p50 %s; '
            . '%d web server processes, %d sittings, %d correlation ids',
        $figures['rssmb']['max'] ?? '-',
        $figures['rssmb']['p95'] ?? '-',
        $figures['rssmb']['p50'] ?? '-',
        $figures['queries']['max'] ?? '-',
        $figures['queries']['p95'] ?? '-',
        $figures['queries']['p50'] ?? '-',
        $figures['processes'],
        $figures['sittings'],
        $figures['correlations']
    ));
    if ($figures['durationms'] !== null) {
        $d = $figures['durationms'];
        cli_writeln(sprintf(
            'Request time (ms) over all %d: mean %s (95 %% CI %s–%s, sd %s), p50 %d, p95 %d, p99 %d, max %d',
            $d['n'],
            $d['mean'],
            $d['ci95'][0],
            $d['ci95'][1],
            $d['sd'],
            $d['p50'],
            $d['p95'],
            $d['p99'],
            $d['max']
        ));
    }
    foreach ($figures['status'] as $status => $n) {
        cli_writeln(sprintf('  status %d: %d', $status, $n));
    }
    foreach ($figures['bypage'] as $page => $p) {
        $d = $p['durationms'] ?? null;
        cli_writeln(sprintf(
            '  %-48s n=%-6d peak %6.1f MB  5xx %d%s',
            substr($page, 0, 48),
            $p['n'],
            $p['peakmb'],
            $p['http5xx'],
            $d ? sprintf('  ms p50 %d p95 %d p99 %d max %d', $d['p50'], $d['p95'], $d['p99'], $d['max']) : ''
        ));
    }
    cli_writeln(sprintf('Outliers (above their page\'s p99): %d', $figures['outliers']['n']));
    foreach (array_slice($figures['outliers']['top'], 0, 10) as $o) {
        cli_writeln(sprintf(
            '  %s  %-40s %6d ms (p99 %d)  sitting %d.%d  pid %d',
            $o['time'],
            substr($o['page'], 0, 40),
            $o['durationms'],
            $o['p99'],
            $o['attemptid'],
            $o['execution'],
            $o['pid']
        ));
    }
    cli_writeln(sprintf(
        'Synchronous peaks (%d s windows, >= 3 requests of >= 2 sittings above their page\'s p95): %d',
        (int) $options['peak-seconds'],
        $figures['synchronouspeaks']['n']
    ));
    foreach ($figures['synchronouspeaks']['top'] as $peak) {
        cli_writeln(sprintf(
            '  %s  %d slow, %d sittings, max %d ms  %s',
            $peak['from'],
            $peak['slow'],
            $peak['sittings'],
            $peak['maxms'],
            json_encode($peak['pages'], JSON_UNESCAPED_SLASHES)
        ));
    }
    if ($figures['problems'] !== []) {
        cli_writeln('Requests with 4xx/5xx, an exception, a fatal or a broken connection:');
        foreach ($figures['problems'] as $p) {
            cli_writeln(sprintf(
                '  %s %d %s %s attempt %d cid %s%s%s%s',
                $p['time'],
                $p['status'],
                $p['method'],
                $p['page'],
                $p['attemptid'],
                $p['correlationid'],
                $p['connection'] !== 'normal' ? ' connection ' . $p['connection'] : '',
                $p['exception'] !== '' ? ' — ' . $p['exception'] . ' (' . $p['where'] . ')' : '',
                $p['fatal'] !== '' ? ' — FATAL ' . $p['fatal'] : ''
            ));
        }
        if ($figures['problemsomitted'] > 0) {
            cli_writeln(sprintf('  … and %d more; only the first %d are listed.', $figures['problemsomitted'], $maxproblems));
        }
    }
    foreach ($broken as $line) {
        cli_writeln('LIMIT BROKEN: ' . $line);
    }
}
exit($broken === [] ? 0 : 1);
