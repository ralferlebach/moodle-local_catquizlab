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
 * The engine's request traces of a time window, counted whole, and each linked to its lab sitting (#164).
 *
 *     php cli/trace_report.php --from=UNIXTIME [--until=UNIXTIME] [--json] [--rows=20]
 *
 * Per kind of request (first_item, next_item, reload, …), from every trace of the window — not a top-100 of the
 * slowest: n, mean, standard deviation, a 95 % interval of the mean, p50/p95/p99 and the maximum of the request time;
 * the same for the span strategy:select_question and the pool lookup, where the engine wrote spans; the bytes of the
 * spans stored, per kind and in all. Then the link of every trace to its lab sitting, run, experiment and worker
 * (live_correlation: stored, in play, person, none) — also for sittings being played, whose engine attempt the lab
 * stores only at collection. And the lab's own debug log in the window: rows and bytes by channel.
 *
 * With the engine's tracing off (local_catquiz/attempttrace = 0) there are no traces, and the report says so instead of
 * printing zeros as if they were measured (#164 AK7).
 *
 * Read only. The trace table is the engine's (local_catquiz_trace); nothing is written to it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\live_correlation;

[$options] = cli_get_params(['from' => 0, 'until' => 0, 'json' => false, 'rows' => 20, 'help' => false]);
if (!empty($options['help']) || (int) $options['from'] <= 0) {
    cli_writeln('php cli/trace_report.php --from=UNIXTIME [--until=UNIXTIME] [--json] [--rows=20]');
    exit((int) $options['from'] > 0 ? 0 : 1);
}
$from = (int) $options['from'];
$until = (int) $options['until'] > 0 ? (int) $options['until'] : time();

$report = [
    'from' => $from, 'until' => $until,
    'tracing' => (bool) get_config('local_catquiz', 'attempttrace'),
    'debuglevel' => \local_catquizlab\local\debug_trace::level(),
    'traces' => 0, 'kinds' => [], 'spans' => [], 'bytes' => 0, 'links' => [], 'debug' => [], 'bysitting' => [],
    'selections' => 0, 'unlinked' => [],
];
$manager = $DB->get_manager();

if ($manager->table_exists('local_catquiz_trace')) {
    $requestms = [];
    $spanms = [];
    $bytes = [];
    $traces = [];
    $rs = $DB->get_recordset_select(
        'local_catquiz_trace',
        'timecreated >= ? AND timecreated <= ?',
        [$from, $until],
        'id ASC',
        'id, adaptiveattemptid, userid, kind, requestms, spans'
    );
    foreach ($rs as $trace) {
        $kind = (string) $trace->kind;
        $report['traces']++;
        if ($trace->requestms !== null) {
            $requestms[$kind][] = (float) $trace->requestms;
        }
        $size = strlen((string) $trace->spans);
        $bytes[$kind] = ($bytes[$kind] ?? 0) + $size;
        $report['bytes'] += $size;
        foreach ((array) (json_decode((string) $trace->spans, true) ?: []) as $span) {
            if (isset($span['name'], $span['ms'])) {
                $spanms[(string) $span['name']][] = (float) $span['ms'];
            }
        }
        $selections = 0;
        foreach ((array) (json_decode((string) $trace->spans, true) ?: []) as $span) {
            if (($span['name'] ?? '') === 'strategy:select_question') {
                $selections++;
            }
        }
        $traces[(int) $trace->id] = ['userid' => (int) $trace->userid, 'adaptiveattemptid' => (int) $trace->adaptiveattemptid,
            'kind' => $kind, 'requestms' => $trace->requestms, 'selections' => $selections];
    }
    $rs->close();
    foreach ($requestms as $kind => $values) {
        $report['kinds'][$kind] = trace_report_stats($values) + ['bytes' => $bytes[$kind] ?? 0];
    }
    foreach (['strategy:select_question', 'pool:cache_lookup'] as $name) {
        if (isset($spanms[$name])) {
            $report['spans'][$name] = trace_report_stats($spanms[$name]);
        }
    }
    $links = live_correlation::link($traces);
    foreach ($links as $id => $link) {
        $report['links'][$link['how']] = ($report['links'][$link['how']] ?? 0) + 1;
        // Per lab sitting: its traces by kind and the selections they hold — what a check maps onto the sitting's
        // answer requests (#164 re-audit 19.11.2026: request and selector spans mapped completely, not sampled).
        $sitting = (string) (int) $link['labattemptid'];
        $report['bysitting'][$sitting] = $report['bysitting'][$sitting] ?? ['engineattempt' => 0, 'kinds' => [],
            'selections' => 0];
        $report['bysitting'][$sitting]['engineattempt'] = (int) $traces[$id]['adaptiveattemptid'];
        $kind = $traces[$id]['kind'];
        $report['bysitting'][$sitting]['kinds'][$kind] = ($report['bysitting'][$sitting]['kinds'][$kind] ?? 0) + 1;
        $report['bysitting'][$sitting]['selections'] += $traces[$id]['selections'];
        if ($link['how'] === live_correlation::NONE) {
            // A trace of no lab sitting: which kind, and whether its engine attempt still exists — the preparation's
            // dry run (engine_dryrun) asks for one first item on an attempt it deletes again.
            $report['unlinked'][] = ['trace' => $id, 'kind' => $traces[$id]['kind'],
                'engineattempt' => (int) $traces[$id]['adaptiveattemptid'],
                'exists' => $DB->record_exists('adaptivequiz_attempt', ['id' => (int) $traces[$id]['adaptiveattemptid']])];
        }
    }
    $report['latest'] = [];
    foreach (array_slice(array_reverse($links, true), 0, max(0, (int) $options['rows']), true) as $id => $link) {
        $report['latest'][] = ['trace' => $id, 'kind' => $traces[$id]['kind'], 'requestms' => $traces[$id]['requestms'],
            'engineattempt' => $traces[$id]['adaptiveattemptid']] + $link;
    }
}
// What the sittings of the window came to: executions by outcome, and the capacity causes the load guard counts.
$report['executions'] = $DB->get_records_sql_menu(
    'SELECT outcome, COUNT(1) FROM {local_catquizlab_attemptlog} WHERE timecreated >= ? AND timecreated <= ? GROUP BY outcome',
    [$from, $until]
);
[$csql, $cparams] = $DB->get_in_or_equal(\local_catquizlab\local\circuit_breaker::CAPACITY_CAUSES);
$report['capacitycauses'] = $DB->get_records_sql_menu(
    "SELECT reasoncode, COUNT(1) FROM {local_catquizlab_attemptlog}
      WHERE timecreated >= ? AND timecreated <= ? AND reasoncode {$csql} GROUP BY reasoncode",
    array_merge([$from, $until], $cparams)
);
// The lab's own debug log in the same window: rows and bytes by channel.
foreach (
    $DB->get_recordset_select(
        'local_catquizlab_debug',
        'timecreated >= ? AND timecreated <= ?',
        [$from, $until],
        '',
        'id, channel, params, detail'
    ) as $row
) {
    $c = (string) $row->channel;
    $report['debug'][$c] = $report['debug'][$c] ?? ['rows' => 0, 'bytes' => 0];
    $report['debug'][$c]['rows']++;
    $report['debug'][$c]['bytes'] += strlen((string) $row->params) + strlen((string) $row->detail);
}

$report['selections'] = $report['spans']['strategy:select_question']['n'] ?? 0;
if (!empty($options['json'])) {
    // Every map an object, also when empty: an empty PHP array is a JSON list, and a reader that expects a map broke on
    // it (#164, re-audit 19.11.2026: "'list' object has no attribute 'get'").
    $json = $report;
    foreach (['kinds', 'spans', 'links', 'debug', 'executions', 'capacitycauses', 'bysitting'] as $key) {
        $json[$key] = (object) ($json[$key] ?? []);
    }
    $json['bysitting'] = (object) array_map(
        static fn(array $row): array => ['kinds' => (object) $row['kinds']] + $row,
        $report['bysitting']
    );
    echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

cli_writeln(sprintf(
    'Window %s – %s UTC; engine tracing %s, lab debug level %s.',
    gmdate('Y-m-d H:i:s', $from),
    gmdate('H:i:s', $until),
    $report['tracing'] ? 'on' : 'off',
    $report['debuglevel']
));
if ($report['traces'] === 0) {
    cli_writeln($report['tracing'] ? 'No engine trace in the window.'
        : 'Engine tracing is off: no request or span times were measured by the engine in this window (none are shown).');
} else {
    cli_writeln(sprintf('%d engine traces, %.2f MiB of spans.', $report['traces'], $report['bytes'] / 1048576));
    cli_writeln(sprintf(
        '  %-26s %6s %9s %9s %19s %9s %9s %9s %9s %9s',
        'request time (ms)',
        'n',
        'mean',
        'sd',
        '95 % CI of mean',
        'p50',
        'p95',
        'p99',
        'max',
        'KiB'
    ));
    foreach ($report['kinds'] + $report['spans'] as $name => $s) {
        cli_writeln(sprintf(
            '  %-26s %6d %9.0f %9.0f %9.0f–%-9.0f %9.0f %9.0f %9.0f %9.0f %9s',
            $name,
            $s['n'],
            $s['mean'],
            $s['sd'],
            $s['cilow'],
            $s['cihigh'],
            $s['p50'],
            $s['p95'],
            $s['p99'],
            $s['max'],
            isset($s['bytes']) ? (string) round($s['bytes'] / 1024) : '–'
        ));
    }
    cli_writeln('Linked to their lab sitting: ' . json_encode($report['links']) . '.');
    foreach ($report['latest'] as $l) {
        cli_writeln(sprintf(
            '  trace %d %-10s %8s ms  engine attempt %d → lab sitting %d (%s), run %d, experiment %d, worker %s',
            $l['trace'],
            $l['kind'],
            $l['requestms'] === null ? '–' : (string) round((float) $l['requestms']),
            $l['engineattempt'],
            $l['labattemptid'],
            $l['how'],
            $l['runid'],
            $l['experimentid'],
            $l['workerid'] ?: '–'
        ));
    }
}
cli_writeln('Executions in the window: ' . json_encode($report['executions']) . '; capacity causes: '
    . json_encode($report['capacitycauses']) . '.');
cli_writeln('Lab debug log in the window: ' . ($report['debug'] === [] ? 'nothing' : json_encode($report['debug'])) . '.');

/**
 * Count, mean, standard deviation, a 95 % interval of the mean, quantiles and maximum of all values.
 *
 * @param float[] $values The values.
 * @return array{n: int, mean: float, sd: float, cilow: float, cihigh: float, p50: float, p95: float, p99: float, max: float}
 */
function trace_report_stats(array $values): array {
    sort($values);
    $n = count($values);
    $mean = array_sum($values) / max(1, $n);
    $var = $n > 1 ? array_sum(array_map(static fn(float $v): float => ($v - $mean) ** 2, $values)) / ($n - 1) : 0.0;
    $sd = sqrt($var);
    // 1.96 for large samples; for small ones Student's t at 97.5 %, read from a short table.
    $t = $n >= 120 ? 1.96 : ([2 => 12.71, 3 => 4.30, 4 => 3.18, 5 => 2.78, 6 => 2.57, 8 => 2.36, 10 => 2.26, 15 => 2.14,
        20 => 2.09, 30 => 2.05, 60 => 2.00][array_reduce(
            [2, 3, 4, 5, 6, 8, 10, 15, 20, 30, 60],
            static fn(int $c, int $k): int => $n >= $k ? $k : $c,
            2
        )]);
    $half = $n > 1 ? $t * $sd / sqrt($n) : 0.0;
    $q = static fn(float $p): float => $n === 0 ? 0.0 : $values[(int) min($n - 1, max(0, ceil($p * $n) - 1))];

    return ['n' => $n, 'mean' => $mean, 'sd' => $sd, 'cilow' => $mean - $half, 'cihigh' => $mean + $half,
        'p50' => $q(0.5), 'p95' => $q(0.95), 'p99' => $q(0.99), 'max' => $n === 0 ? 0.0 : $values[$n - 1]];
}
