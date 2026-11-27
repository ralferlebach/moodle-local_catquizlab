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
 * Which sittings of a run went on after a failure that deleted the engine's intermediate estimates (#174 AK05).
 *
 * Reads only, unless --mark is given; then each sitting found at risk gets one history row ("audited", reason
 * resume_after_param_deletion) — its result, trace and status stay as they are. The figures cannot say whether a
 * result changed: the deleted rows are gone. They say which sittings were exposed, so that a decision about them can
 * be made and documented.
 *
 *     php local/catquizlab/cli/resume_integrity.php --runid=103 [--json] [--mark]
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\resume_integrity;

[$options] = cli_get_params(['runid' => 0, 'json' => false, 'mark' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int) $options['runid'] <= 0) {
    echo "Sittings of a run that went on after a failure that deleted the engine's intermediate estimates (#174).\n"
        . "  --runid=N   The run.\n"
        . "  --json      As JSON.\n"
        . "  --mark      Record the finding in each at-risk sitting's history (once; nothing else changes).\n";
    exit(0);
}

$audit = resume_integrity::audit((int) $options['runid']);
$audit['marked'] = $options['mark'] ? resume_integrity::mark($audit) : 0;

if ($options['json']) {
    echo json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

cli_writeln('Run ' . $audit['run'] . ' (' . ($audit['strategy'] ?: 'strategy unknown') . '); fix in place since: '
    . ($audit['fixedsince'] ? date('c', $audit['fixedsince']) : 'not yet'));
foreach ($audit['counts'] as $risk => $n) {
    cli_writeln(sprintf('  %-12s %d', $risk, $n));
}
foreach ($audit['sittings'] as $s) {
    if ($s['risk'] === resume_integrity::NONE) {
        continue;
    }
    $resumes = array_map(static fn(array $r): string => sprintf(
        'try %d after %s (%s, %s answered)',
        $r['tryno'],
        date('c', $r['failedat']),
        $r['failurecode'] ?: '?',
        $r['answeredbefore'] ?? '?'
    ), $s['resumes']);
    cli_writeln(sprintf(
        'sitting %d  %-10s engine %d usage %d (%d answered, %s)  %s',
        $s['sitting'],
        $s['risk'],
        $s['engineattemptid'],
        $s['qubaid'],
        $s['questionsattempted'],
        $s['status'],
        implode('; ', $resumes)
    ));
}
if ($options['mark']) {
    cli_writeln('Marked now: ' . $audit['marked']);
}
