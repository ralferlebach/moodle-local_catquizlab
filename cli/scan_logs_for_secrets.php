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
 * Look through existing server logs for secrets that earlier versions may have written (#149).
 *
 * Before 0.7.56 the fatal-error line "local_catquizlab memory: … url=…" carried the request's whole query string.
 * This script reads the given log files (also .gz), counts every such line and every secret parameter it finds in
 * them by name and by category (session, token or key, password, e-mail address), and says on which lines and in
 * which hours — never what the values were. With --redact-to it writes a copy of each file without the values, for
 * whoever then replaces the original under the site's own rules.
 *
 * The operator runs it on the server, over the logs the server still has (--dir reads a whole directory, rotated and
 * gzip files included). The report holds counts, categories, line numbers and hours only, so it can leave the server;
 * the logs never do (docs/dev/log-datenschutz.md, "Bestands-Scan").
 *
 *     php local/catquizlab/cli/scan_logs_for_secrets.php --file=/var/log/apache2/error.log,/var/log/php/error.log
 *     php local/catquizlab/cli/scan_logs_for_secrets.php --dir=/var/log/apache2 --all --json > scan-149.json
 *     php local/catquizlab/cli/scan_logs_for_secrets.php --file=… --redact-to=/root/redacted-logs
 *
 * Exit code 0: nothing found; 3: secrets found; 1: a file could not be read.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Paths are read relative to where the operator started the script, before Moodle's setup moves elsewhere.
define('LOCAL_CATQUIZLAB_SCAN_STARTDIR', (string) getcwd());

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\log_scan;

[$options] = cli_get_params(
    ['file' => '', 'dir' => '', 'json' => false, 'redact-to' => '', 'all' => false, 'help' => false],
    ['h' => 'help']
);
$startdir = LOCAL_CATQUIZLAB_SCAN_STARTDIR;
$absolute = static fn(string $path): string => ($path === '' || $path[0] === '/') ? $path : $startdir . '/' . $path;
$files = array_map($absolute, array_filter(array_map('trim', explode(',', (string) $options['file']))));
foreach (array_filter(array_map('trim', explode(',', (string) $options['dir']))) as $dir) {
    $files = array_merge($files, log_scan::files_in($absolute($dir)));
}
if ($options['help'] || !$files) {
    echo "Count secrets earlier catquizlab versions may have written to server logs (#149).\n"
        . "  --file=a,b       Log files to read (plain or .gz).\n"
        . "  --dir=a,b        Every log file under these directories (*.log, *.log.N, *.gz, *_log).\n"
        . "  --all            Every line with a secret parameter, not only catquizlab's own lines.\n"
        . "  --json           The report as JSON.\n"
        . "  --redact-to=DIR  Also write a copy of each file without the values.\n";
    exit(0);
}

$report = [
    'version' => (string) \core_plugin_manager::instance()->get_plugin_info('local_catquizlab')->release,
    'generated' => date('c'),
    'files' => [],
    'total' => ['files' => 0, 'lines' => 0, 'lineswithsecrets' => 0, 'categories' => [], 'first' => null, 'last' => null],
];
$found = false;
$unreadable = false;
foreach ($files as $file) {
    $entry = log_scan::scan_file($file, (bool) $options['all'], (string) $options['redact-to']);
    $unreadable = $unreadable || isset($entry['error']);
    $found = $found || $entry['lineswithsecrets'] > 0;
    $report['files'][] = $entry;
    $report['total']['files']++;
    $report['total']['lines'] += $entry['lines'];
    $report['total']['lineswithsecrets'] += $entry['lineswithsecrets'];
    foreach ($entry['categories'] as $category => $count) {
        $report['total']['categories'][$category] = ($report['total']['categories'][$category] ?? 0) + $count;
    }
    foreach ($entry['windows'] as $window) {
        $report['total']['first'] = min($report['total']['first'] ?? $window['first'], $window['first']);
        $report['total']['last'] = max($report['total']['last'] ?? $window['last'], $window['last']);
    }
}
ksort($report['total']['categories']);

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    foreach ($report['files'] as $entry) {
        echo $entry['file'] . ': ' . ($entry['error'] ?? ($entry['lines'] . ' lines, ' . $entry['fatallines']
            . ' catquizlab memory lines (' . $entry['fatallineswithquery'] . ' with a query string), '
            . $entry['lineswithsecrets'] . ' lines with secret values')) . PHP_EOL;
        foreach ($entry['names'] as $name => $count) {
            echo '    ' . $name . ': ' . $count . PHP_EOL;
        }
        foreach ($entry['windows'] as $category => $window) {
            echo '    ' . $category . ': ' . $entry['categories'][$category] . ' lines, ' . $window['first'] . ' to '
                . $window['last'] . ', in ' . count($window['hours']) . ' hours' . PHP_EOL;
        }
        if (!empty($entry['untimed'])) {
            echo '    without a readable time: ' . $entry['untimed'] . ' lines' . PHP_EOL;
        }
        if ($entry['linenumbers']) {
            echo '    lines: ' . implode(',', $entry['linenumbers']) . (count($entry['linenumbers']) >= 200 ? ',…' : '')
                . PHP_EOL;
        }
        if ($entry['redactedcopy']) {
            echo '    redacted copy: ' . $entry['redactedcopy'] . PHP_EOL;
        }
    }
    echo 'Total: ' . $report['total']['files'] . ' files, ' . $report['total']['lines'] . ' lines, '
        . $report['total']['lineswithsecrets'] . ' lines with secret values'
        . ($report['total']['first'] !== null ? ', ' . $report['total']['first'] . ' to ' . $report['total']['last'] : '')
        . PHP_EOL;
    foreach ($report['total']['categories'] as $category => $count) {
        echo '    ' . $category . ': ' . $count . PHP_EOL;
    }
}

exit($unreadable ? 1 : ($found ? 3 : 0));
