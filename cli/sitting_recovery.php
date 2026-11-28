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
 * An explicit decision on a sitting whose engine attempt is ambiguous or not a result (#173 AK08, re-audit 27.11.2026).
 *
 * Without --execute it reads only: the sitting, its binding, every engine attempt of its person on the activity, the
 * completion verdict — and what the decision would change. With --execute and a note it decides, under the sitting's
 * lock, and the history keeps who, what and why. See local_catquizlab\local\sitting_recovery.
 *
 *     php local/catquizlab/cli/sitting_recovery.php --attemptid=46794 [--json]
 *     php local/catquizlab/cli/sitting_recovery.php --attemptid=46794 --decision=bind --engineattemptid=29462 \
 *         --note="operator: B is the sitting's" --execute
 *     php local/catquizlab/cli/sitting_recovery.php --attemptid=46794 --decision=newgeneration --note="…" --execute
 *     php local/catquizlab/cli/sitting_recovery.php --attemptid=46794 --decision=keep --note="…" --execute
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\sitting_recovery;

[$options] = cli_get_params(['attemptid' => 0, 'decision' => '', 'engineattemptid' => 0, 'note' => '', 'execute' => false,
    'json' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int) $options['attemptid'] <= 0) {
    echo "A decision on a sitting's engine attempt (#173). Reads only without --execute.\n"
        . "  --attemptid=N             The sitting.\n"
        . "  --decision=bind|newgeneration|keep\n"
        . "  --engineattemptid=N       For bind: the attempt that is the sitting's.\n"
        . "  --note=TEXT               Why (required to decide).\n"
        . "  --execute                 Decide; without it, show what is known and what would change.\n"
        . "  --json                    Machine-readable.\n";
    exit(0);
}
$attemptid = (int) $options['attemptid'];
$decision = (string) $options['decision'];
$state = sitting_recovery::inspect($attemptid);
$out = ['inspect' => $state];
if ($decision !== '' && $options['execute']) {
    $out['decision'] = sitting_recovery::decide(
        $attemptid,
        $decision,
        (string) $options['note'],
        (int) $options['engineattemptid'],
        (int) get_admin()->id
    );
} else if ($decision !== '') {
    $out['decision'] = ['dryrun' => true, 'decision' => $decision, 'engineattemptid' => (int) $options['engineattemptid'],
        'wouldretire' => $decision === sitting_recovery::NEW_GENERATION
            ? array_values(array_map(static fn($c) => $c['engineattemptid'], array_filter(
                (array) ($state['candidates'] ?? []),
                static fn($c) => !$c['retired']
            ))) : []];
}
if ($options['json']) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(empty($out['decision']) || !empty($out['decision']['ok']) || !empty($out['decision']['dryrun']) ? 0 : 1);
}
if (empty($state['found'])) {
    cli_error("Sitting {$attemptid} not found.");
}
$s = $state['sitting'];
echo "Sitting {$s['id']} (run {$s['runid']}), status {$s['status']}, tries {$s['tries']}, execution #{$s['executionid']}, "
    . 'bound engine attempt ' . ($s['engineattemptid'] ?: '-') . "\n";
echo 'Resolved: ' . ($state['resolved']['found'] ? 'engine attempt ' . $state['resolved']['engineattemptid']
    : $state['resolved']['reason']) . "\n";
echo "Engine attempts of the person on the activity:\n";
foreach ($state['candidates'] as $c) {
    echo sprintf(
        "  %d  usage %d  %-10s  %5d answered  created %s%s%s\n",
        $c['engineattemptid'],
        $c['qubaid'],
        $c['state'],
        $c['answered'],
        gmdate('Y-m-d H:i:s', $c['timecreated']) . 'Z',
        $c['bound'] ? '  BOUND' : '',
        $c['retired'] ? '  retired' : ''
    );
}
if (!empty($state['completion'])) {
    echo 'Completion: ' . $state['completion']['class'] . ' (' . $state['completion']['why'] . '), recovery '
        . $state['completion']['recovery'] . "\n";
}
if (isset($out['decision'])) {
    echo 'Decision: ' . json_encode($out['decision'], JSON_UNESCAPED_SLASHES) . "\n";
    if (empty($out['decision']['ok']) && empty($out['decision']['dryrun'])) {
        exit(1);
    }
}
