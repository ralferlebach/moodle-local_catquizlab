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
 * Read-only inventory of the CAT engine's rows: what belongs to which lab run, and what belongs to nobody (#161 AK6).
 *
 * Changes nothing. For the operator who has to decide about engine rows left behind:
 *
 * - **Per run** (--runid, or every run with --runs): the run's footprint (engine_footprint): what of the engine, the
 *   activity and Moodle belongs to it alone, and the rows of others that share an attempt number with it.
 * - **Without an owner** (--orphans): CAT progress whose CAT attempt is gone, CAT attempts whose activity attempt is
 *   gone, abilities of deleted or missing accounts, item parameters without their item — each with how many, the
 *   oldest and newest time and the components. Rows are assigned to a lab run only where the lab's own records still
 *   prove it (a run's activity or accounts); nothing is assigned to a run that is no longer there.
 *
 *     php local/catquizlab/cli/engine_inventory.php --orphans [--json]
 *     php local/catquizlab/cli/engine_inventory.php --runid=51 [--json]
 *     php local/catquizlab/cli/engine_inventory.php --runs [--json]
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\engine_footprint;
use local_catquizlab\local\engine_inventory;

[$options] = cli_get_params(
    ['runid' => 0, 'runs' => false, 'orphans' => false, 'json' => false, 'help' => false],
    ['h' => 'help']
);
if ($options['help'] || (!$options['orphans'] && !$options['runs'] && (int) $options['runid'] <= 0)) {
    echo "Read-only inventory of the CAT engine's rows (#161). Changes nothing.\n"
        . "  --runid=N   One lab run's footprint.\n"
        . "  --runs      Every lab run's footprint, in counts.\n"
        . "  --orphans   Engine rows without an owner, by kind, with times and components.\n"
        . "  --json      As JSON.\n";
    exit(0);
}
global $DB;

$report = [];
if ((int) $options['runid'] > 0) {
    $footprint = engine_footprint::of_run((int) $options['runid']);
    $report['run'] = $footprint;
}
if ($options['runs']) {
    $report['runs'] = [];
    foreach ($DB->get_fieldset_select('local_catquizlab_run', 'id', '1 = 1', null) as $runid) {
        $footprint = engine_footprint::of_run((int) $runid);
        $report['runs'][(int) $runid] = ['owned' => engine_footprint::counts($footprint['owned']),
            'atrisk' => engine_footprint::counts($footprint['atrisk'])];
    }
}
if ($options['orphans']) {
    $report['orphans'] = engine_inventory::orphans();
}

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
if (isset($report['run'])) {
    $f = $report['run'];
    cli_writeln('Run ' . $f['runid'] . ': activity ' . ($f['cmid'] ?: '-') . ' (instance ' . ($f['instance'] ?: '-') . '), '
        . count($f['users']) . ' simulated account(s), ' . count($f['contexts']) . ' engine context(s).');
    cli_writeln('  owned:   ' . (engine_footprint::line($f['owned']) ?: '-'));
    cli_writeln('  at risk: ' . (engine_footprint::line($f['atrisk']) ?: '-') . '  (others\' rows sharing an attempt number)');
}
foreach ($report['runs'] ?? [] as $runid => $c) {
    $line = [];
    foreach ($c['owned'] as $table => $n) {
        $line[] = $table . ' ' . $n;
    }
    cli_writeln('Run ' . $runid . ': ' . ($line ? implode(', ', $line) : 'nothing in the engine')
        . ($c['atrisk'] ? '; at risk: ' . array_sum($c['atrisk']) : ''));
}
foreach ($report['orphans'] ?? [] as $kind => $o) {
    cli_writeln(sprintf(
        '%-40s %7d  %s – %s  %s',
        $kind,
        $o['count'],
        $o['oldest'] ? date('Y-m-d', $o['oldest']) : '-',
        $o['newest'] ? date('Y-m-d', $o['newest']) : '-',
        implode(', ', array_map(static fn($k, $v) => $k . ' ' . $v, array_keys($o['components']), $o['components']))
    ));
}
