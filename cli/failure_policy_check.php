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
 * What became of an experiment's sittings after a deterministic or a transient failure (#175 DoD 2).
 *
 * --expect=held: a broken invariant (CATQUIZLAB_FAULTS=notstored@N) — every failed execution has its code
 *   (no_progress), every such sitting is held (failed with a "held" row) after one execution, none was put back in
 *   the queue, and once three in a row are held the run is paused by the breaker with that cause: the queue, the
 *   history and the run's state say so.
 * --expect=retried: a transient fault (CATQUIZLAB_FAULTS=endexecution@N) — every sitting was retried once, nothing
 *   held, the run not paused, and every sitting collected.
 *
 *     php local/catquizlab/cli/failure_policy_check.php --experimentid=N --expect=held|retried [--json]
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
use local_catquizlab\local\run_lifecycle;

[$options] = cli_get_params(['experimentid' => 0, 'expect' => '', 'json' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int) $options['experimentid'] <= 0 || !in_array($options['expect'], ['held', 'retried'], true)) {
    echo "What became of the sittings after a deterministic or a transient failure (#175).\n"
        . "  --experimentid=N   The experiment.\n"
        . "  --expect=held|retried\n"
        . "  --json             As JSON.\n";
    exit(0);
}
global $DB;

$report = ['expect' => $options['expect'], 'runs' => [], 'problems' => []];
foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => (int) $options['experimentid']], 'id ASC') as $run) {
    $sittings = $DB->get_records('local_catquizlab_attempt', ['runid' => $run->id], 'id ASC', 'id, status, tries');
    $statuses = [];
    $outcomes = [];
    $codes = [];
    $executions = [];
    foreach ($sittings as $s) {
        $label = attempt_scheduler::status_label((int) $s->status);
        $statuses[$label] = ($statuses[$label] ?? 0) + 1;
        $executions[(int) $s->tries] = ($executions[(int) $s->tries] ?? 0) + 1;
        foreach (attempt_history::of_attempt((int) $s->id) as $row) {
            $outcomes[$row['outcome']] = ($outcomes[$row['outcome']] ?? 0) + 1;
            if ($row['outcome'] === attempt_history::FAILED) {
                $codes[$row['reasoncode']] = ($codes[$row['reasoncode']] ?? 0) + 1;
            }
        }
    }
    $paused = run_lifecycle::is_paused((int) $run->id);
    $reason = run_lifecycle::pause_reason((int) $run->id);
    $report['runs'][(int) $run->id] = ['sittings' => count($sittings), 'statuses' => $statuses, 'executions' => $executions,
        'outcomes' => $outcomes, 'failurecodes' => $codes, 'paused' => $paused, 'pausereason' => $reason];
    $say = static function (string $what) use (&$report, $run): void {
        $report['problems'][] = 'run ' . $run->id . ': ' . $what;
    };
    if ($options['expect'] === 'held') {
        if (array_keys($codes) !== ['no_progress']) {
            $say('failure codes ' . json_encode($codes) . ', only no_progress expected');
        }
        if (($outcomes[attempt_history::HELD] ?? 0) !== ($outcomes[attempt_history::FAILED] ?? -1)) {
            $say('not every failed execution held');
        }
        if (($outcomes[attempt_history::REQUEUED] ?? 0) > 0) {
            $say(($outcomes[attempt_history::REQUEUED]) . ' put back in the queue');
        }
        ksort($executions);
        if (array_diff(array_keys($executions), [0, 1]) !== []) {
            $say('executions per sitting ' . json_encode($executions) . ': a held sitting is played once');
        }
        if (($outcomes[attempt_history::HELD] ?? 0) >= 3 && (!$paused || strpos($reason, 'no_progress') === false)) {
            $say('three held sittings and the run not paused for no_progress (' . $reason . ')');
        }
    } else {
        if (($outcomes[attempt_history::HELD] ?? 0) > 0 || $paused) {
            $say('held or paused after a transient fault');
        }
        if (($statuses[attempt_scheduler::status_label(attempt_scheduler::STATUS_COLLECTED)] ?? 0) !== count($sittings)) {
            $say('not every sitting collected: ' . json_encode($statuses));
        }
        if (($outcomes[attempt_history::REQUEUED] ?? 0) < count($sittings)) {
            $say('not every sitting retried');
        }
    }
}
if ($report['runs'] === []) {
    $report['problems'][] = 'no run';
}
echo $options['json'] ? json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    : implode("\n", array_merge(
        array_map(static fn($p) => 'PROBLEM ' . $p, $report['problems']),
        [json_encode($report['runs'], JSON_UNESCAPED_SLASHES), $report['problems'] === [] ? 'PASS' : 'FAIL']
    )) . "\n";
exit($report['problems'] === [] ? 0 : 1);
