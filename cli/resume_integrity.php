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
 * What a past run's sittings went through, from what is on record — fail-closed (#174 AK05, AK07–AK09).
 *
 * Every sitting of the run, every engine attempt of its person on the activity, every resume in its history. Findings
 * per sitting (no-engine-attempt, ambiguous, broken-binding), per engine attempt (timeout, incomplete, regular, unknown)
 * and per resume (compromised-before-fix, after-fix, new-attempt, resume-unknown), counted with their denominators.
 * What cannot be established is said as such — no "none", no all-clear. Reads only, unless --mark is given; then each
 * finding gets one history row per sitting ("audited") — results, traces, statuses and bindings stay as they are.
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
    echo "What a past run's sittings went through, fail-closed (#174). Reads only without --mark.\n"
        . "  --runid=N   The run.\n"
        . "  --json      As JSON.\n"
        . "  --mark      Record each finding in the sitting's history (once; nothing else changes).\n";
    exit(0);
}

$audit = resume_integrity::audit((int) $options['runid']);
$audit['marked'] = $options['mark'] ? resume_integrity::mark($audit) : 0;

if ($options['json']) {
    echo json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

cli_writeln('Run ' . $audit['run'] . ' (' . ($audit['strategy'] ?: 'strategy unknown')
    . ($audit['form'] !== null ? ', form of ' . $audit['form'] : '') . '), audit rules ' . $audit['rules']
    . '; fix installed since: ' . ($audit['fixedsince'] ? gmdate('c', $audit['fixedsince']) : 'not yet')
    . ' (a risk boundary, not proof of what a worker ran)');
$d = $audit['denominators'];
cli_writeln("Sittings {$d['sittings']}, engine attempts {$d['engineattempts']}, resumes {$d['resumes']}.");
$of = [resume_integrity::NO_ENGINE => 'sittings', resume_integrity::AMBIGUOUS => 'sittings',
    resume_integrity::BROKEN_BINDING => 'sittings', resume_integrity::TIMEOUT => 'engine attempts',
    resume_integrity::INCOMPLETE => 'engine attempts', resume_integrity::REGULAR => 'engine attempts',
    resume_integrity::UNKNOWN => 'engine attempts', resume_integrity::AT_RISK => 'resumes',
    resume_integrity::AFTER_FIX => 'resumes', resume_integrity::NEW_ATTEMPT => 'resumes',
    resume_integrity::RESUME_UNKNOWN => 'resumes'];
foreach ($audit['counts'] as $finding => $n) {
    cli_writeln(sprintf('  %-24s %6d  of %s', $finding, $n, $of[$finding] ?? '?'));
}
foreach ($audit['sittings'] as $s) {
    $attempts = array_map(static fn(array $a): string => sprintf(
        '%d/%d %s %d answered%s%s [%s]',
        $a['engineattemptid'],
        $a['qubaid'],
        $a['state'],
        $a['answered'],
        $a['bound'] ? ' bound' : '',
        $a['retired'] ? ' retired' : '',
        $a['finding']
    ), $s['attempts']);
    cli_writeln(sprintf(
        'sitting %d  %-10s tries %d  findings: %s  attempts: %s',
        $s['sitting'],
        $s['status'],
        $s['tries'],
        $s['findings'] === [] ? '-' : implode(', ', $s['findings']),
        $attempts === [] ? '-' : implode('; ', $attempts)
    ));
}
if ($options['mark']) {
    cli_writeln('Marked now: ' . $audit['marked']);
}
