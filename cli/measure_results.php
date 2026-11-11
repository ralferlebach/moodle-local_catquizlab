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
 * Measure the results page step by step, and compare digests with whole traces (#147).
 *
 * Renders, for a selection, what the results page renders — size check, filter bar, analysis basis, every tab —
 * and every export level as CSV and JSON, and reports for each step: walltime, database queries, sittings and bytes
 * read, passes over the selection, sittings read one at a time, PHP peak memory and the process's resident memory.
 * With --compare it does it all twice, once from the stored digests and once from the whole traces as before
 * 0.7.57, and says for every step whether the results are the same.
 *
 *     php local/catquizlab/cli/measure_results.php                       # all experiments
 *     php local/catquizlab/cli/measure_results.php --experimentid=12 --validity=all --compare --json
 *     php -d memory_limit=512M local/catquizlab/cli/measure_results.php --experimentid=12 --tabs=overview,subscales
 *
 * The figures are those of a CLI process: the same PHP, database and code as a web request, without the web
 * server. The page itself reports the same figures to administrators with ?profile=1.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\local\trace_digest;
use local_catquizlab\local\trace_reader;
use local_catquizlab\output\results_page;

[$options] = cli_get_params([
    'experimentid' => 0, 'replication' => 0, 'strategy' => '', 'validity' => '', 'validityrule' => 'uniform',
    'tabs' => 'all', 'exports' => 'all', 'compare' => false, 'json' => false, 'dump' => '', 'step' => '', 'whole' => false,
    'help' => false,
], ['h' => 'help']);
if ($options['help']) {
    echo "Measure the results page step by step (#147). Options: --experimentid --replication --strategy --validity\n"
        . "--validityrule --tabs=all|a,b --exports=all|none|a,b --compare --json --dump=DIR (what each step produced)\n";
    exit(0);
}

global $DB, $PAGE;
\core\session\manager::set_user(get_admin());
$PAGE->set_url('/local/catquizlab/results.php');
$PAGE->set_context(context_system::instance());
raise_memory_limit(MEMORY_EXTRA);

$filter = [];
foreach (['experimentid', 'replication'] as $key) {
    if ((int) $options[$key] > 0) {
        $filter[$key] = (int) $options[$key];
    }
}
if ($options['strategy'] !== '') {
    $filter['strategy'] = clean_param($options['strategy'], PARAM_ALPHANUMEXT);
}
$tabs = $options['tabs'] === 'all' ? array_keys(results_page::tabs())
    : array_values(array_intersect(explode(',', $options['tabs']), array_keys(results_page::tabs())));
$exports = $options['exports'] === 'none' ? [] : ($options['exports'] === 'all' ? array_keys(results_export::levels())
    : array_values(array_intersect(explode(',', $options['exports']), array_keys(results_export::levels()))));

/**
 * A static counter of the plugin, where this version has it: the script also measures versions before 0.7.57, for
 * the comparison before and after (#147, AK9).
 *
 * @param string $class The class.
 * @param string $name The static property.
 * @param int|null $set A value to set, or null to read.
 * @return int
 */
function local_catquizlab_measure_counter(string $class, string $name, ?int $set = null): int {
    if (!property_exists($class, $name)) {
        return -1;
    }
    if ($set !== null) {
        $class::$$name = $set;
    }
    return (int) $class::$$name;
}

/**
 * The process's resident memory now and at its highest, in MB, from the kernel; null where it does not say.
 *
 * @return array{rss: float|null, hwm: float|null}
 */
function local_catquizlab_measure_rss(): array {
    $status = @file_get_contents('/proc/self/status');
    $out = ['rss' => null, 'hwm' => null];
    if ($status && preg_match('/^VmRSS:\s+(\d+) kB/m', $status, $m)) {
        $out['rss'] = round($m[1] / 1024, 1);
    }
    if ($status && preg_match('/^VmHWM:\s+(\d+) kB/m', $status, $m)) {
        $out['hwm'] = round($m[1] / 1024, 1);
    }
    return $out;
}

/**
 * Run every step once and measure it.
 *
 * @param array $filter The filter, without validity.
 * @param array $options The options.
 * @param string[] $tabs The tabs.
 * @param string[] $exports The export levels.
 * @return array[] Step => figures and a fingerprint of what it produced.
 */
function local_catquizlab_measure_all(array $filter, array $options, array $tabs, array $exports): array {
    global $DB;

    $steps = [];
    $validity = $options['validity'] !== '' ? $options['validity'] : null;
    $base = $filter + ['validityrule' => $options['validityrule']];
    $plan = [
        'size check' => fn() => json_encode((new results_query($base + ['validity' => $validity ?? 'valid']))->size_check()),
        'filter bar' => fn() => (new results_page(
            new results_query($base + ['validity' => $validity ?? 'valid']),
            'overview',
            $base
        ))->render_filter_bar(),
        'analysis basis' => fn() => (new results_page(
            new results_query($base + ['validity' => $validity ?? 'valid']),
            'overview',
            $base
        ))->render_provenance((int) ($filter['experimentid'] ?? 0)),
    ];
    foreach ($tabs as $tab) {
        $plan['tab ' . $tab] = function () use ($base, $tab, $validity): string {
            $f = $base + ['validity' => $validity ?? results_page::default_validity($tab)];
            return (new results_page(new results_query($f), $tab, $f))->render_tab();
        };
    }
    foreach ($exports as $level) {
        foreach (['csv', 'json'] as $format) {
            $plan['export ' . $level . ' ' . $format] = function () use ($base, $level, $format, $validity): string {
                $query = new results_query($base + ['validity' => $validity ?? 'valid']);
                // Hashed and counted as it is written, never held: a download is streamed, and holding it here
                // would measure this script, not the export.
                $hash = hash_init('sha1');
                $bytes = 0;
                ob_start(static function (string $chunk) use ($hash, &$bytes): string {
                    hash_update($hash, (string) preg_replace(
                        '/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|[+-]\d\d:?\d\d)?/',
                        'ISO',
                        $chunk
                    ));
                    $bytes += strlen($chunk);
                    return '';
                }, 65536);
                results_export::stream($query, $level, $format);
                ob_end_clean();
                return 'streamed ' . $bytes . ' bytes sha1 ' . hash_final($hash);
            };
        }
    }

    // Each step in a process of its own (#147): a step that dies of memory or time is recorded as that, with the
    // fatal error, and the steps after it are still measured.
    if ($options['step'] === '') {
        foreach (array_keys($plan) as $label) {
            $steps[$label] = local_catquizlab_measure_child($label, $options);
        }
        return $steps;
    }
    $plan = array_intersect_key($plan, [$options['step'] => true]);
    foreach ($plan as $label => $step) {
        results_query::reset_caches();
        results_export::reset_caches();
        trace_reader::reset();
        local_catquizlab_measure_counter(results_query::class, 'passes', 0);
        local_catquizlab_measure_counter(results_query::class, 'detailqueries', 0);
        gc_collect_cycles();
        $before = memory_get_usage();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $queries = $DB->perf_get_queries();
        $start = microtime(true);
        $output = $step();
        $seconds = microtime(true) - $start;
        $read = trace_reader::figures();
        if ($options['dump'] !== '') {
            check_dir_exists($options['dump']);
            $digests = class_exists(trace_digest::class) && !trace_digest::$wholetraces;
            file_put_contents($options['dump'] . '/' . ($digests ? 'digest-' : 'whole-')
                . preg_replace('/\W+/', '-', $label) . '.out', $output);
        }
        $steps[$label] = [
            'seconds' => round($seconds, 2),
            'queries' => $DB->perf_get_queries() - $queries,
            'passes' => local_catquizlab_measure_counter(results_query::class, 'passes'),
            'onebyone' => local_catquizlab_measure_counter(results_query::class, 'detailqueries'),
            'readmb' => round($read['read'] / 1048576, 1),
            'batches' => $read['batches'] ?? -1,
            'peakmb' => round((memory_get_peak_usage() - $before) / 1048576, 1),
            'bytes' => strlen($output),
            // Without what changes between two renderings of the same data: generated element ids and the session key.
            'fingerprint' => sha1((string) preg_replace(
                ['/\b(id|for|aria-labelledby|aria-describedby|aria-controls|data-target|href)="[^"]*"/', '/sesskey=\w+/',
                    '/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|[+-]\d\d:?\d\d)?/', '/yui_[\w-]+/', '/\b\d{10}\b/', '/\b\d{8}-\d{6}\b/'],
                ['$1=""', 'sesskey=', 'ISO', 'yui', 'T', 'STAMP'],
                $output
            )),
        ] + local_catquizlab_measure_rss();
    }

    return $steps;
}

/**
 * One step in a child process with the same PHP settings; its figures, or how it died.
 *
 * @param string $label The step.
 * @param array $options The options.
 * @return array
 */
function local_catquizlab_measure_child(string $label, array $options): array {
    global $argv;
    $args = array_filter(array_slice($argv, 1), static fn(string $a): bool => strpos($a, '--json') !== 0
        && strpos($a, '--compare') !== 0 && strpos($a, '--step=') !== 0);
    $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d memory_limit='
        . escapeshellarg((string) ini_get('memory_limit'))
        . ' -d max_execution_time=' . (int) ini_get('max_execution_time') . ' ' . escapeshellarg(__FILE__) . ' '
        . implode(' ', array_map('escapeshellarg', $args)) . ' --json --step=' . escapeshellarg($label)
        . (class_exists(trace_digest::class) && trace_digest::$wholetraces ? ' --whole' : '');
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $report = json_decode($out, true);
    if (is_array($report) && isset($report['digest'][$label])) {
        return $report['digest'][$label];
    }
    $fatal = preg_match('/(Allowed memory size[^\n]*|Maximum execution time[^\n]*|Out of memory[^\n]*)/', $out . $err, $m)
        ? $m[1] : trim(substr($out . $err, -300));
    return ['seconds' => null, 'queries' => null, 'passes' => null, 'onebyone' => null, 'readmb' => null,
        'batches' => null, 'peakmb' => null, 'bytes' => null, 'fingerprint' => 'died', 'rss' => null, 'hwm' => null,
        'died' => true, 'exit' => $code, 'fatal' => $fatal];
}

$report = [
    'version' => (string) \core_plugin_manager::instance()->get_plugin_info('local_catquizlab')->release,
    'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'db' => $DB->get_dbfamily() . ' ' . ($DB->get_server_info()['version'] ?? ''),
    'memorylimit' => ini_get('memory_limit'), 'filter' => $filter, 'validity' => $options['validity'],
    'sittings' => (new results_query($filter + ['validity' => 'all']))->size_check(),
    'digestsmissing' => class_exists(trace_digest::class) ? trace_digest::missing() : -1,
];
if (class_exists(trace_digest::class)) {
    trace_digest::$wholetraces = (bool) $options['whole'];
}
$report['digest'] = local_catquizlab_measure_all($filter, $options, $tabs, $exports);
if ($options['compare'] && class_exists(trace_digest::class)) {
    trace_digest::$wholetraces = true;
    $report['whole'] = local_catquizlab_measure_all($filter, $options, $tabs, $exports);
    trace_digest::$wholetraces = false;
    $report['same'] = [];
    foreach ($report['digest'] as $label => $figures) {
        $report['same'][$label] = $figures['fingerprint'] === $report['whole'][$label]['fingerprint'];
    }
}

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(isset($report['same']) && in_array(false, $report['same'], true) ? 3 : 0);
}
printf(
    "%s, PHP %s %s, %s, limit %s, %d sittings, %.0f MB read per pass\n",
    $report['version'],
    $report['php'],
    $report['sapi'],
    $report['db'],
    $report['memorylimit'],
    $report['sittings']['count'],
    $report['sittings']['bytes'] / 1048576
);
printf(
    "%-28s %8s %7s %6s %8s %8s %8s %8s %8s%s\n",
    'step',
    'seconds',
    'queries',
    'passes',
    'onebyone',
    'read MB',
    'peak MB',
    'RSS MB',
    'HWM MB',
    $options['compare'] ? '  whole s  whole MB  same' : ''
);
foreach ($report['digest'] as $label => $f) {
    if (!empty($f['died'])) {
        printf("%-28s DIED (exit %d): %s\n", $label, $f['exit'], $f['fatal']);
        continue;
    }
    printf(
        "%-28s %8.2f %7d %6d %8d %8.1f %8.1f %8s %8s",
        $label,
        $f['seconds'],
        $f['queries'],
        $f['passes'],
        $f['onebyone'],
        $f['readmb'],
        $f['peakmb'],
        $f['rss'] ?? '-',
        $f['hwm'] ?? '-'
    );
    if ($options['compare']) {
        $w = $report['whole'][$label];
        printf(" %8.2f %9.1f  %s", $w['seconds'], $w['readmb'] + 0, $report['same'][$label] ? 'yes' : 'NO');
    }
    echo PHP_EOL;
}
exit(isset($report['same']) && in_array(false, $report['same'], true) ? 3 : 0);
