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
 * Sittings whose engine trace never came: where to find them, and their lookup opened again (#138 AK4).
 *
 * Lists, per run, every sitting given up for a missing trace with what was recorded when it was given up: the lab
 * sitting, the engine attempt (adaptivequiz_attempt.id) and what the host holds of it, the workers' correlation ids,
 * what the trace lacked and what the engine says about it. With --reopen the lookup of those sittings (or of one,
 * --attemptid) is opened again: they wait for their trace once more, with the full number of looks, and the pipeline
 * collects them when it arrives. Nothing is played again.
 *
 *     php local/catquizlab/cli/recover_traces.php --runid=51 [--json]
 *     php local/catquizlab/cli/recover_traces.php --runid=51 --reopen
 *     php local/catquizlab/cli/recover_traces.php --attemptid=4711 --reopen
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_scheduler;

[$options] = cli_get_params(
    ['runid' => 0, 'attemptid' => 0, 'reopen' => false, 'json' => false, 'help' => false],
    ['h' => 'help']
);
if ($options['help'] || ((int) $options['runid'] <= 0 && (int) $options['attemptid'] <= 0)) {
    echo "Sittings given up for a missing engine trace, with their provenance (#138).\n"
        . "  --runid=N       The run's given-up sittings.\n"
        . "  --attemptid=N   One sitting.\n"
        . "  --reopen        Open their trace lookup again (nothing is played again).\n"
        . "  --json          As JSON.\n";
    exit(0);
}
global $DB;

$attemptid = (int) $options['attemptid'];
$runid = (int) $options['runid'] ?: (int) $DB->get_field('local_catquizlab_attempt', 'runid', ['id' => $attemptid]);
$given = attempt_scheduler::given_up_traces($runid);
if ($attemptid > 0) {
    $given = array_intersect_key($given, [$attemptid => true]);
}

$reopened = [];
if ($options['reopen']) {
    foreach (array_keys($given) as $id) {
        if (attempt_scheduler::reopen_trace_lookup($id, 'cli/recover_traces.php')) {
            $reopened[] = $id;
        }
    }
}

if ($options['json']) {
    echo json_encode(
        ['runid' => $runid, 'givenup' => $given, 'reopened' => $reopened],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
}
cli_writeln('Run ' . $runid . ': ' . count($given) . ' sitting(s) given up for a missing trace.');
foreach ($given as $id => $p) {
    $engine = $p['engine'] ?? [];
    cli_writeln(sprintf(
        '  sitting %d: engine attempt %s (%s), looked %d time(s) from %s to %s; lacked [%s]; engine says: %s;'
            . ' correlation ids: %s',
        $id,
        $p['engineattemptid'] ?: '-',
        !empty($engine['found']) ? 'host: ' . $engine['state'] . ', ' . $engine['questionsattempted'] . ' questions, usage '
            . $engine['questionusage'] : 'not in the host',
        (int) ($p['looks'] ?? 0),
        $p['firstmissing'] ? userdate((int) $p['firstmissing'], '%Y-%m-%d %H:%M:%S') : '-',
        userdate((int) $p['givenup'], '%Y-%m-%d %H:%M:%S'),
        implode(',', (array) ($p['defects'] ?? [])),
        (string) ($p['lookup'] ?? ''),
        implode(' ', (array) ($p['correlationids'] ?? [])) ?: '-'
    ));
}
if ($options['reopen']) {
    cli_writeln('Lookup opened again: ' . count($reopened) . ' sitting(s)' . ($reopened ? ' (' . implode(', ', $reopened) . ')'
        : '') . '. The pipeline looks for their traces from the next tick on.');
}
