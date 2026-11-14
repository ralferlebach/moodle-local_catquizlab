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
 * Provenance snapshot of an experiment's results: every count, from every place that shows one, side by side (#115).
 *
 * For one experiment: how many sittings were planned, started, collected, failed; how many have a trace (the size
 * check); and for each validity (valid, invalid, unknown, all) the number of observations, the row count the export
 * tab shows for each level, the data rows of the CSV download and the rows of the JSON download - parsed from the
 * streamed files, not taken from the same counter. It checks that they agree: valid + invalid + unknown = all, the
 * size check = all, and for each level the tab's count = CSV rows = JSON rows.
 *
 * Written for the audit of 11.11.2026 (#112/#115/#147): the export tab of 0.7.56 said 9000 for the attempt level of
 * a valid-only selection whose download had 8706 rows; 0.7.57 says 8706. This shows which number is the download's.
 *
 *     php local/catquizlab/cli/provenance_snapshot.php --experimentid=12
 *     php local/catquizlab/cli/provenance_snapshot.php --experimentid=12 --levels=attempt,run --json
 *
 * Exit status 0 when every count agrees, 1 otherwise.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

[$options] = cli_get_params([
    'experimentid' => 0, 'validityrule' => 'uniform', 'levels' => 'run,attempt,subscale,item', 'json' => false,
    'jsonlimitmb' => 32, 'help' => false,
], ['h' => 'help']);
if ($options['help'] || (int) $options['experimentid'] < 1) {
    echo "Provenance snapshot (#115). Options: --experimentid=N [--validityrule=uniform|engine]\n"
        . "  [--levels=run,attempt,subscale,item(,step)] [--json] [--jsonlimitmb=32]\n";
    exit($options['help'] ? 0 : 2);
}
global $DB;
\core\session\manager::set_user(get_admin());

$experimentid = (int) $options['experimentid'];
$levels = array_values(array_filter(array_map('trim', explode(',', (string) $options['levels']))));
$known = array_keys(results_export::levels());
foreach ($levels as $level) {
    if (!in_array($level, $known, true)) {
        cli_error('Unknown level ' . $level . '; known: ' . implode(', ', $known));
    }
}
$filter = ['experimentid' => $experimentid, 'validityrule' => (string) $options['validityrule']];

// The queue's side: what was planned and what became of it.
[$inruns, $runparams] = $DB->get_in_or_equal(
    array_keys($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], '', 'id')) ?: [0],
    SQL_PARAMS_NAMED
);
$statuses = $DB->get_records_sql_menu(
    'SELECT status, COUNT(1) FROM {local_catquizlab_attempt} WHERE runid ' . $inruns . ' GROUP BY status',
    $runparams
);
$started = (int) $DB->count_records_sql(
    'SELECT COUNT(DISTINCT l.attemptid) FROM {local_catquizlab_attemptlog} l
      WHERE l.runid ' . $inruns . ' AND l.outcome = :started',
    $runparams + ['started' => attempt_history::STARTED]
);
$queue = [
    'planned' => (int) array_sum($statuses),
    'started' => $started,
    'collected' => (int) (($statuses[attempt_scheduler::STATUS_COLLECTED] ?? 0)
        + ($statuses[attempt_scheduler::STATUS_VALIDATED] ?? 0)),
    'tracepending' => (int) ($statuses[attempt_scheduler::STATUS_TRACEPENDING] ?? 0),
    'failed' => (int) ($statuses[attempt_scheduler::STATUS_FAILED] ?? 0),
    'queued' => (int) ($statuses[attempt_scheduler::STATUS_QUEUED] ?? 0),
    'running' => (int) ($statuses[attempt_scheduler::STATUS_RUNNING] ?? 0),
];
$sizecheck = (int) (new results_query($filter + ['validity' => 'all']))->size_check()['count'];

/**
 * Rows of a streamed download, parsed back from what was written.
 *
 * @param results_query $query The selection.
 * @param string $level The level.
 * @param string $format csv or json.
 * @param int $jsonlimit Bytes beyond which a JSON download is not decoded whole.
 * @return array rows (null where not parsed), bytes.
 */
function provenance_download_rows(results_query $query, string $level, string $format, int $jsonlimit): array {
    $file = tmpfile();
    $bytes = 0;
    ob_start(static function (string $chunk) use ($file, &$bytes): string {
        fwrite($file, $chunk);
        $bytes += strlen($chunk);
        return '';
    }, 65536);
    results_export::stream($query, $level, $format);
    ob_end_clean();
    rewind($file);
    $rows = null;
    if ($format === 'csv') {
        $rows = -1;
        while (fgetcsv($file, 0, ',', '"', '') !== false) {
            $rows++;
        }
    } else if ($bytes <= $jsonlimit) {
        $decoded = json_decode(stream_get_contents($file), true);
        $rows = is_array($decoded) && isset($decoded['rows']) && is_array($decoded['rows']) ? count($decoded['rows']) : null;
    } else {
        // Too large to decode within the memory limit: the objects of the "rows" array counted by a scanner that
        // follows strings and nesting, chunk by chunk.
        $rows = provenance_count_json_rows($file);
    }
    fclose($file);

    return ['rows' => $rows, 'bytes' => $bytes];
}

/**
 * The objects of the top-level "rows" array of a JSON download, counted without decoding it.
 *
 * @param resource $file The download, rewound.
 * @return int|null Null where there is no "rows" array.
 */
function provenance_count_json_rows($file): ?int {
    $depth = 0;
    $instring = false;
    $escaped = false;
    $key = '';
    $lastkey = '';
    $inrows = false;
    $rows = 0;
    while (!feof($file)) {
        $chunk = (string) fread($file, 1048576);
        $length = strlen($chunk);
        for ($i = 0; $i < $length; $i++) {
            $c = $chunk[$i];
            if ($instring) {
                if ($escaped) {
                    $escaped = false;
                } else if ($c === '\\') {
                    $escaped = true;
                } else if ($c === '"') {
                    $instring = false;
                    $lastkey = $key;
                } else if ($depth === 1) {
                    $key .= $c;
                }
                continue;
            }
            if ($c === '"') {
                $instring = true;
                $key = '';
            } else if ($c === '{' || $c === '[') {
                $depth++;
                if ($c === '[' && $depth === 2 && $lastkey === 'rows') {
                    $inrows = true;
                } else if ($c === '{' && $inrows && $depth === 3) {
                    $rows++;
                }
            } else if ($c === '}' || $c === ']') {
                if ($c === ']' && $depth === 2) {
                    $inrows = false;
                }
                $depth--;
            }
        }
    }

    return $rows;
}

$snapshot = ['experimentid' => $experimentid, 'validityrule' => $filter['validityrule'], 'queue' => $queue,
    'sizecheck' => $sizecheck, 'validity' => []];
$problems = [];
foreach (['valid', 'invalid', 'unknown', 'all'] as $validity) {
    $query = static fn() => new results_query($filter + ['validity' => $validity]);
    $observations = 0;
    foreach ($query()->each_observation() as $unused) {
        $observations++;
    }
    $table = results_export::all_row_counts($query())['counts'];
    $entry = ['observations' => $observations, 'levels' => []];
    foreach ($levels as $level) {
        $csv = provenance_download_rows($query(), $level, 'csv', 0);
        $json = provenance_download_rows($query(), $level, 'json', (int) $options['jsonlimitmb'] * 1024 * 1024);
        $entry['levels'][$level] = ['table' => (int) $table[$level], 'csv' => $csv['rows'], 'json' => $json['rows'],
            'csvbytes' => $csv['bytes'], 'jsonbytes' => $json['bytes']];
        if ((int) $table[$level] !== $csv['rows']) {
            $problems[] = "$validity/$level: the export tab says {$table[$level]}, the CSV has {$csv['rows']} rows";
        }
        if ($json['rows'] !== null && $json['rows'] !== $csv['rows']) {
            $problems[] = "$validity/$level: the JSON has {$json['rows']} rows, the CSV {$csv['rows']}";
        }
    }
    if (in_array('attempt', $levels, true) && $entry['levels']['attempt']['csv'] !== $observations) {
        $problems[] = "$validity: $observations observations, {$entry['levels']['attempt']['csv']} attempt rows";
    }
    $snapshot['validity'][$validity] = $entry;
}
$parts = array_sum(array_map(static fn($v) => $snapshot['validity'][$v]['observations'], ['valid', 'invalid', 'unknown']));
if ($parts !== $snapshot['validity']['all']['observations']) {
    $problems[] = "valid + invalid + unknown = $parts, all = {$snapshot['validity']['all']['observations']}";
}
if ($sizecheck !== $snapshot['validity']['all']['observations']) {
    $problems[] = "size check $sizecheck, observations of all validities {$snapshot['validity']['all']['observations']}";
}
$snapshot['problems'] = $problems;
$plugininfo = core_plugin_manager::instance()->get_plugin_info('local_catquizlab');
$snapshot['release'] = (string) $plugininfo->release;
$snapshot['version'] = (string) $plugininfo->versiondb;
$snapshot['db'] = $DB->get_dbfamily() . ' ' . ($DB->get_server_info()['version'] ?? '');

if ($options['json']) {
    echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    printf(
        "Experiment %d, local_catquizlab %s (%s), %s, validity rule %s\n",
        $experimentid,
        $snapshot['release'],
        $snapshot['version'],
        $snapshot['db'],
        $filter['validityrule']
    );
    printf("Queue: %s\n", implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($queue), $queue)));
    printf("Size check (sittings with a trace): %d\n", $sizecheck);
    printf("%-8s %-10s %12s %10s %10s %10s\n", 'validity', 'level', 'observations', 'tab', 'CSV rows', 'JSON rows');
    foreach ($snapshot['validity'] as $validity => $entry) {
        foreach ($entry['levels'] as $level => $counts) {
            printf(
                "%-8s %-10s %12d %10d %10d %10s\n",
                $validity,
                $level,
                $entry['observations'],
                $counts['table'],
                $counts['csv'],
                $counts['json'] === null ? 'not parsed' : (string) $counts['json']
            );
        }
    }
    echo $problems === [] ? "All counts agree.\n" : "DISAGREES:\n  " . implode("\n  ", $problems) . "\n";
}
exit($problems === [] ? 0 : 1);
