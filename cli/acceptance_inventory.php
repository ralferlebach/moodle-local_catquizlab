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
 * The one read-only inventory of the lab's data on an installation (re-audit 18.11.2026, section 3).
 *
 * One command instead of a series of queries: versions, schema, status sums, due against claimable, the execution
 * line, the circuit breaker's pauses, workers and leases, orphans and duplicates, collected/cancelled/failed apart and
 * adding up, results and exports, tasks and the last dispatch (classes/local/acceptance_inventory.php). Only SELECTs;
 * the report says how many database writes happened while it ran (none). It starts no cron and no worker.
 *
 *     php local/catquizlab/cli/acceptance_inventory.php                     # text
 *     php local/catquizlab/cli/acceptance_inventory.php --json > inventory.json
 *     php local/catquizlab/cli/acceptance_inventory.php --csv > inventory.csv
 *
 * Exit code 0: no contradiction; 3: at least one; 1: it could not run.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\acceptance_inventory;

[$options] = cli_get_params(['json' => false, 'csv' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "Read-only inventory of the lab's data for an acceptance. --json or --csv for a file; text otherwise.\n";
    exit(0);
}

$report = (new acceptance_inventory())->run();
$report['plugin'] = (string) get_config('local_catquizlab', 'version');
$report['site'] = parse_url($CFG->wwwroot, PHP_URL_HOST);

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else if ($options['csv']) {
    $out = fopen('php://stdout', 'w');
    fputcsv($out, ['section', 'name', 'verdict', 'value']);
    foreach ($report['checks'] as $check) {
        fputcsv($out, [$check['section'], $check['name'], $check['verdict'],
            is_scalar($check['value']) ? (string) $check['value'] : json_encode($check['value'], JSON_UNESCAPED_SLASHES)]);
    }
    fclose($out);
} else {
    echo 'Inventory ' . $report['generated'] . ' (plugin ' . $report['plugin'] . ', ' . $report['site'] . ')' . PHP_EOL;
    $section = '';
    foreach ($report['checks'] as $check) {
        if ($check['section'] !== $section) {
            $section = $check['section'];
            echo PHP_EOL . '[' . $section . ']' . PHP_EOL;
        }
        $mark = ['ok' => 'ok  ', 'contradiction' => 'FAIL', 'info' => '    '][$check['verdict']];
        echo $mark . ' ' . $check['name'] . ': '
            . (is_scalar($check['value']) ? var_export($check['value'], true)
                : json_encode($check['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . PHP_EOL;
    }
    echo PHP_EOL . $report['summary']['ok'] . ' ok, ' . $report['summary']['contradiction'] . ' contradictions, '
        . $report['summary']['info'] . ' counts; ' . $report['writes'] . ' database writes while it ran.' . PHP_EOL;
}

exit($report['summary']['contradiction'] > 0 ? 3 : 0);
