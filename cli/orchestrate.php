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
 * CLI: set up runs end to end, in tier order.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\run_orchestrator;
use local_catquizlab\local\tier_planner;
use local_catquizlab\local\registry;

[$options, $unrecognised] = cli_get_params(
    [
        'help'               => false,
        'runid'              => 0,
        'experimentid'       => 0,
        'questioncategoryid' => 0,
    ],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln("Set up local_catquizlab runs end to end (scales, items, test, people, attempts).\n");
    cli_writeln("Options:");
    cli_writeln("  --runid=N               Set up a single run.");
    cli_writeln("  --experimentid=N        Set up all runs of one experiment.");
    cli_writeln("  (neither)               Set up every run of every experiment, in tier order.");
    cli_writeln("  --questioncategoryid=N  Target question category for materialised items.");
    exit(0);
}

$setupoptions = [
    'questioncategoryid' => (int) $options['questioncategoryid'],
];

$runids = local_catquizlab_orchestrate_target_runs((int) $options['runid'], (int) $options['experimentid']);
if ($runids === []) {
    cli_writeln('No matching runs.');
    exit(0);
}

$failures = 0;
foreach ($runids as $runid) {
    $result = run_orchestrator::setup($runid, $setupoptions);
    $materialise = $result['stages'][run_orchestrator::STAGE_MATERIALISE] ?? null;

    if (!empty($result['ok'])) {
        cli_writeln("Run {$runid}: ok" . local_catquizlab_materialise_summary($materialise));
        continue;
    }

    // The word "skipped" was the only outcome the CLI had, so a run that
    // materialised nothing read exactly like one that ran without the engine.
    $failures++;
    cli_writeln("Run {$runid}: FAILED [" . ($result['reason'] ?? 'unknown') . ']');
    foreach (local_catquizlab_materialise_detail($materialise) as $line) {
        cli_writeln('  ' . $line);
    }
}

cli_writeln('Done.');

if ($failures > 0) {
    // A non-zero exit code, so a scripted run cannot mistake a broken
    // provisioning for a finished one.
    cli_writeln("{$failures} run(s) failed.");
    exit(1);
}

exit(0);

/**
 * A one-line materialisation summary for a successful run.
 *
 * @param mixed $materialise The materialisation stage result.
 * @return string The summary, or an empty string when there is nothing to report.
 */
function local_catquizlab_materialise_summary($materialise): string {
    if (!is_array($materialise) || !isset($materialise['planned'])) {
        return '';
    }

    return sprintf(
        ' [materialise: planned=%d, questions=%d, items=%d, params=%d, visible=%d]',
        (int) $materialise['planned'],
        (int) ($materialise['questionscreated'] ?? 0),
        (int) ($materialise['itemsregistered'] ?? 0),
        (int) ($materialise['parametersregistered'] ?? 0),
        (int) ($materialise['enginevisible'] ?? 0)
    );
}

/**
 * The diagnostic lines for a failed run.
 *
 * @param mixed $materialise The materialisation stage result.
 * @return string[] Lines to print.
 */
function local_catquizlab_materialise_detail($materialise): array {
    if (!is_array($materialise)) {
        return [];
    }

    $lines = [];
    if (isset($materialise['planned'])) {
        $lines[] = sprintf(
            'planned=%d questions=%d items=%d params=%d visible=%d failed=%d',
            (int) $materialise['planned'],
            (int) ($materialise['questionscreated'] ?? 0),
            (int) ($materialise['itemsregistered'] ?? 0),
            (int) ($materialise['parametersregistered'] ?? 0),
            (int) ($materialise['enginevisible'] ?? 0),
            (int) ($materialise['faileditems'] ?? 0)
        );
    }
    if (!empty($materialise['reason'])) {
        $lines[] = 'reason=' . $materialise['reason'];
    }
    foreach (array_slice((array) ($materialise['errors'] ?? []), 0, 5) as $error) {
        $lines[] = sprintf(
            'item %s: %s%s',
            (string) ($error['itemname'] ?? '?'),
            (string) ($error['reason'] ?? '?'),
            !empty($error['engineerror']) ? ' — ' . $error['engineerror'] : ''
        );
    }

    return $lines;
}

/**
 * Resolve the run ids to set up, honouring tier order for the full-suite case.
 *
 * @param int $runid A single run id, or 0.
 * @param int $experimentid A single experiment id, or 0.
 * @return int[]
 */
function local_catquizlab_orchestrate_target_runs(int $runid, int $experimentid): array {
    global $DB;

    if ($runid > 0) {
        return [$runid];
    }
    if ($experimentid > 0) {
        return array_map('intval', array_keys(
            $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id', 'id')
        ));
    }

    $runids = [];
    foreach (tier_planner::experiments_in_order() as $experiment) {
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experiment->id], 'id', 'id') as $run) {
            $runids[] = (int) $run->id;
        }
    }
    return $runids;
}
