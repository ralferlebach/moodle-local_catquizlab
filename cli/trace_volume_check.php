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
 * The engine's trace table when it is large: what it costs to read, what retention removes, what it holds (#164 AK-05,
 * DoD-04).
 *
 * 1. The traces already there (from the real sittings of the trace matrix) are read for secrets — names of secret
 *    parameters with a value, e-mail addresses, the worker's token — by log_scan, never printed: their count must be 0.
 *    The largest payload and the bytes per trace are measured.
 * 2. --rows traces are added, spread over --days, each a copy of a real one (or of a fixed shape when there is none)
 *    and marked as seeded (kind "volume_seed"); the table's size before and after.
 * 3. A one-hour window is read as trace_report.php reads it, before and after the table grew: time, PHP's peak memory,
 *    rows — and the query plan of that read (an index on timecreated, no scan of the whole table).
 * 4. Retention: the engine's own task (local_catquiz\task\purge_attempt_traces) with --retention days: time, rows
 *    removed, nothing older left; the setting is put back.
 * 5. Everything seeded that is still there is removed.
 *
 *     php local/catquizlab/cli/trace_volume_check.php --rows=200000 --days=30 --retention=14 --json > volume.json
 *
 * Exit 0 on PASS (no secret, the window read bounded, retention complete), 1 on FAIL.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\log_scan;

[$options] = cli_get_params(['rows' => 200000, 'days' => 30, 'retention' => 14, 'json' => false, 'help' => false]);
if ($options['help']) {
    echo "php cli/trace_volume_check.php [--rows=200000] [--days=30] [--retention=14] [--json]\n";
    exit(0);
}
$table = 'local_catquiz_trace';
if (!$DB->get_manager()->table_exists($table)) {
    cli_error('The engine has no trace table (local_catquiz_trace): an engine without request tracing.');
}
$seedkind = 'volume_seed';
$report = ['rows' => (int) $options['rows'], 'days' => (int) $options['days'], 'retention' => (int) $options['retention'],
    'db' => $DB->get_dbfamily()];

/**
 * The trace table's size on disk, in MB, where the database says.
 *
 * @return float|null
 */
function local_catquizlab_volume_size(): ?float {
    global $DB, $CFG;
    try {
        if ($DB->get_dbfamily() === 'postgres') {
            return round((int) $DB->get_field_sql("SELECT pg_total_relation_size('{$CFG->prefix}local_catquiz_trace')")
                / 1048576, 1);
        }
        if ($DB->get_dbfamily() === 'mysql') {
            return round((int) $DB->get_field_sql('SELECT data_length + index_length FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = ?', [$CFG->prefix . 'local_catquiz_trace']) / 1048576, 1);
        }
    } catch (\Throwable $e) {
        return null;
    }

    return null;
}

/**
 * One hour of traces read as trace_report.php reads it.
 *
 * @param int $from Start.
 * @return array{rows: int, ms: float, peakmb: float}
 */
function local_catquizlab_volume_window(int $from): array {
    global $DB;

    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $start = microtime(true);
    $rows = 0;
    $bytes = 0;
    $rs = $DB->get_recordset_select(
        'local_catquiz_trace',
        'timecreated >= ? AND timecreated <= ?',
        [$from, $from + HOURSECS],
        'id ASC',
        'id, adaptiveattemptid, userid, kind, requestms, spans'
    );
    foreach ($rs as $row) {
        $rows++;
        $bytes += strlen((string) $row->spans);
        json_decode((string) $row->spans, true);
    }
    $rs->close();

    return ['rows' => $rows, 'bytes' => $bytes, 'ms' => round(1000 * (microtime(true) - $start), 1),
        'peakmb' => round(memory_get_peak_usage() / 1048576, 1)];
}

// 1. The real traces: secrets, sizes.
$real = 0;
$secretlines = 0;
$secretnames = [];
$maxbytes = 0;
$sumbytes = 0;
$token = (string) get_config('local_catquizlab', 'worker_token');
$sample = null;
$rs = $DB->get_recordset_select($table, 'kind <> ?', [$seedkind], 'id ASC', 'id, kind, spans');
foreach ($rs as $row) {
    $real++;
    $spans = (string) $row->spans;
    $maxbytes = max($maxbytes, strlen($spans));
    $sumbytes += strlen($spans);
    $names = log_scan::secret_names($spans) + ($token !== '' && strpos($spans, $token) !== false ? ['(worker token)' => 1] : []);
    if ($names) {
        $secretlines++;
        foreach ($names as $name => $count) {
            $secretnames[$name] = ($secretnames[$name] ?? 0) + $count;
        }
    }
    if ($sample === null && $row->kind === 'next_item' && $spans !== '') {
        $sample = $spans;
    }
}
$rs->close();
$report['real'] = ['traces' => $real, 'maxbytes' => $maxbytes, 'meanbytes' => $real ? round($sumbytes / $real) : null,
    'traceswithsecrets' => $secretlines, 'secretnames' => $secretnames];

// 2. Grow the table.
$sample = $sample ?? json_encode([['name' => 'request', 'depth' => 0, 'offsetms' => 0, 'ms' => null],
    ['name' => 'strategy:select_question', 'depth' => 1, 'offsetms' => 12.5, 'ms' => 180.25, 'queries' => 40,
        'meta' => ['pool_items' => 1000]],
    ['name' => 'pool:user_stats', 'depth' => 2, 'offsetms' => 14.0, 'ms' => 21.0, 'queries' => 3]]);
$report['sizemb_before'] = local_catquizlab_volume_size();
$hour = time() - 2 * HOURSECS;
$report['window_before'] = local_catquizlab_volume_window($hour);
$now = time();
$span = max(1, (int) $options['days']) * DAYSECS;
$batch = [];
$start = microtime(true);
for ($i = 0; $i < (int) $options['rows']; $i++) {
    $batch[] = (object) ['adaptiveattemptid' => 1 + ($i % 5000), 'userid' => 0, 'kind' => $seedkind,
        'questionnumber' => 1 + ($i % 150), 'requestms' => 200.0, 'dbqueries' => 50, 'spans' => $sample,
        'timecreated' => $now - (int) floor($span * ($i / max(1, (int) $options['rows'])))];
    if (count($batch) >= 1000) {
        $DB->insert_records($table, $batch);
        $batch = [];
    }
}
if ($batch) {
    $DB->insert_records($table, $batch);
}
$report['seed_seconds'] = round(microtime(true) - $start, 1);
$report['sizemb_after'] = local_catquizlab_volume_size();
$report['rows_total'] = $DB->count_records($table);
$report['window_after'] = local_catquizlab_volume_window($hour);

// 3. The plan of the window read.
try {
    $sql = "SELECT id FROM {local_catquiz_trace} WHERE timecreated >= ? AND timecreated <= ?";
    if ($DB->get_dbfamily() === 'postgres') {
        $plan = $DB->get_fieldset_sql('EXPLAIN ' . $sql, [$hour, $hour + HOURSECS]);
    } else {
        $plan = array_map(static fn($r): string => json_encode($r), array_values($DB->get_records_sql(
            'EXPLAIN ' . $sql,
            [$hour, $hour + HOURSECS]
        )));
    }
} catch (\Throwable $e) {
    $plan = ['EXPLAIN failed: ' . $e->getMessage()];
}
$report['window_plan'] = $plan;
$planned = implode("\n", $plan);
$report['window_uses_index'] = (bool) preg_match('/Index|index|"key":"[^"]*timecreated|range/i', $planned)
    && !preg_match('/Seq Scan on \S*local_catquiz_trace/', $planned);

// 4. Retention through the engine's own task.
$before = get_config('local_catquiz', 'attempttraceretentiondays');
set_config('attempttraceretentiondays', (int) $options['retention'], 'local_catquiz');
$count = $DB->count_records($table);
$start = microtime(true);
try {
    (new \local_catquiz\task\purge_attempt_traces())->execute();
} finally {
    set_config('attempttraceretentiondays', $before === false ? null : $before, 'local_catquiz');
}
$report['retention_seconds'] = round(microtime(true) - $start, 1);
$report['retention_removed'] = $count - $DB->count_records($table);
$report['retention_older_left'] = $DB->count_records_select(
    $table,
    'timecreated < ?',
    [time() - (int) $options['retention'] * DAYSECS - 60]
);

// 5. Clean up.
$DB->delete_records($table, ['kind' => $seedkind]);
$report['seeded_left'] = $DB->count_records($table, ['kind' => $seedkind]);

$checks = [
    'no secret in any real trace (' . $real . ' read)' => $secretlines === 0,
    'reading an hour stays bounded as the table grows to ' . $report['rows_total'] . ' rows (' . $report['window_before']['ms']
        . ' ms → ' . $report['window_after']['ms'] . ' ms, peak ' . $report['window_after']['peakmb'] . ' MB)'
        => $report['window_after']['ms'] <= max(250.0, 5 * $report['window_before']['ms']) && $report['window_uses_index'],
    'retention removed everything older than ' . (int) $options['retention'] . ' days (' . $report['retention_removed']
        . ' rows in ' . $report['retention_seconds'] . ' s)' => $report['retention_older_left'] === 0
        && $report['retention_removed'] > 0,
    'nothing seeded left' => $report['seeded_left'] === 0,
];
$report['checks'] = $checks;
$report['pass'] = !in_array(false, $checks, true);
if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    foreach ($checks as $what => $ok) {
        cli_writeln(($ok ? 'ok    ' : 'FAIL  ') . $what);
    }
    cli_writeln('Table ' . $report['sizemb_before'] . ' MB → ' . $report['sizemb_after'] . ' MB with ' . $report['rows']
        . ' traces; largest real payload ' . $maxbytes . ' bytes, mean ' . $report['real']['meanbytes'] . '.');
    cli_writeln($report['pass'] ? 'PASS' : 'FAIL');
}
exit($report['pass'] ? 0 : 1);
