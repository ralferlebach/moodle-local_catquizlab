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
 * Long sittings checked end to end on the server's own records (#172 DoD 2, #173 DoD 2, #174 DoD 2).
 *
 * For every sitting of the experiment (or run): collected; its trace holds as many answers as asked (the whole pool
 * for a classical test); its engine attempt is the one bound to it, complete, and the only attempt of its account on
 * the run's activity; every execution after the first continued that attempt and started from the engine's count —
 * at least --min-baseline answers where asked; no execution failed other than by the faults injected on purpose; and
 * the engine's person parameters of the person are gone once it is collected. Exit 1 when one of these fails.
 *
 *     php local/catquizlab/cli/long_run_check.php --experimentid=N --answers=2500 [--min-baseline=1001]
 *         [--allowed-failures=execution_context_destroyed] [--json]
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
use local_catquizlab\local\engine_identity;

[$options] = cli_get_params(['experimentid' => 0, 'runid' => 0, 'answers' => 0, 'min-baseline' => 0,
    'allowed-failures' => '', 'allow-transient' => false, 'json' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || ((int) $options['experimentid'] <= 0 && (int) $options['runid'] <= 0)) {
    echo "Long sittings checked end to end (#172, #173, #174).\n"
        . "  --experimentid=N | --runid=N  What to check (the newest smoke experiment: see smoke.php --summary).\n"
        . "  --answers=N           Answers every trace must hold (0: the run's pool for a classical test, else any).\n"
        . "  --min-baseline=N      A retried sitting's later execution must start at N answers or more.\n"
        . "  --allowed-failures=a,b  Reason codes of failed executions injected on purpose.\n"
        . "  --allow-transient     Also a retried execution that failed for capacity (a login timing out under 20\n"
        . "                        browsers): counted and listed, never a broken invariant (#175).\n"
        . "  --json                As JSON.\n";
    exit(0);
}
global $DB;

$runids = (int) $options['runid'] > 0 ? [(int) $options['runid']]
    : array_keys($DB->get_records('local_catquizlab_run', ['experimentid' => (int) $options['experimentid']], 'id', 'id'));
$allowed = array_filter(array_map('trim', explode(',', (string) $options['allowed-failures'])));
if ($options['allow-transient']) {
    $allowed = array_merge($allowed, \local_catquizlab\local\circuit_breaker::CAPACITY_CAUSES, ['login_failed']);
}
$report = ['runs' => [], 'sittings' => 0, 'problems' => [], 'retried' => 0, 'baselines' => [], 'allowedfailures' => []];
$personparams = $DB->get_manager()->table_exists('local_catquiz_personparams');

foreach ($runids as $runid) {
    $budget = \local_catquizlab\local\sitting_budget::for_run((int) $runid);
    $want = (int) $options['answers'] > 0 ? (int) $options['answers'] : (int) $budget['expected'];
    $report['runs'][$runid] = ['pool' => $budget['limit'], 'expected' => $want, 'sittings' => 0];
    $contexts = $DB->get_fieldset_select(
        'local_catquizlab_scalemap',
        'DISTINCT contextid',
        'runid = ? AND contextid > 0',
        [$runid]
    );
    $sittings = $DB->get_records(
        'local_catquizlab_attempt',
        ['runid' => $runid],
        'id ASC',
        'id, runid, personid, status, tries, engineattemptid, tracejson'
    );
    foreach ($sittings as $s) {
        $report['sittings']++;
        $report['runs'][$runid]['sittings']++;
        $say = static function (string $what) use (&$report, $s): void {
            $report['problems'][] = 'sitting ' . $s->id . ': ' . $what;
        };
        if ((int) $s->status !== attempt_scheduler::STATUS_COLLECTED && (int) $s->status !== attempt_scheduler::STATUS_VALIDATED) {
            $say('not collected (' . attempt_scheduler::status_label((int) $s->status) . ')');
        }
        $trace = json_decode((string) $s->tracejson, true) ?: [];
        $answered = count(array_filter((array) ($trace['responses'] ?? []), static fn($r): bool => $r !== null));
        if ($want > 0 && $answered !== $want) {
            $say("trace holds $answered answers, $want expected");
        }
        $engine = engine_identity::resolve((int) $s->id);
        if (!$engine['found'] || !$engine['bound']) {
            $say('engine attempt not bound or not found (' . $engine['reason'] . ')');
        } else if ($engine['state'] !== 'complete') {
            $say('engine attempt ' . $engine['engineattemptid'] . ' is ' . $engine['state']);
        }
        [$userid, $instance] = engine_identity::owner($s);
        $attempts = $instance > 0 ? $DB->count_records('adaptivequiz_attempt', ['userid' => $userid, 'instance' => $instance]) : 0;
        if ($attempts !== 1) {
            $say("$attempts engine attempts of the account on the activity, 1 expected");
        }
        $history = attempt_history::of_attempt((int) $s->id);
        foreach ($history as $row) {
            if ($row['outcome'] === attempt_history::FAILED && in_array($row['reasoncode'], $allowed, true)) {
                $report['allowedfailures'][$row['reasoncode']] = ($report['allowedfailures'][$row['reasoncode']] ?? 0) + 1;
            }
            if ($row['outcome'] === attempt_history::FAILED && !in_array($row['reasoncode'], $allowed, true)) {
                $say('execution ' . $row['tryno'] . ' failed: ' . $row['reasoncode'] . ' ' . substr($row['detail'], 0, 200));
            }
            if (in_array($row['outcome'], [attempt_history::HELD, attempt_history::STALE], true)) {
                $say('execution ' . $row['tryno'] . ' ' . $row['outcome']);
            }
        }
        // Every execution after the first: its start the engine's count, of the same attempt (#173).
        if ((int) $s->tries > 1) {
            $report['retried']++;
            foreach ($history as $row) {
                $progress = (array) ($row['diagnosis']['progress'] ?? []);
                if ((int) $row['tryno'] < 2 || $progress === [] || $row['outcome'] === attempt_history::STARTED) {
                    continue;
                }
                $report['baselines'][] = (int) ($progress['baseline'] ?? -1);
                if ((int) ($progress['engineattemptid'] ?? 0) !== (int) $engine['engineattemptid']) {
                    $say('execution ' . $row['tryno'] . ' played engine attempt ' . (int) ($progress['engineattemptid'] ?? 0));
                }
                if ((int) $options['min-baseline'] > 0 && (int) ($progress['baseline'] ?? -1) < (int) $options['min-baseline']) {
                    $say('execution ' . $row['tryno'] . ' started at ' . ($progress['baseline'] ?? '?') . ' answers');
                }
            }
        }
        // Collected: the person's engine parameters in the run's contexts are gone (#174).
        if ($personparams && $contexts !== [] && $userid > 0 && (int) $s->status === attempt_scheduler::STATUS_COLLECTED) {
            [$insql, $params] = $DB->get_in_or_equal($contexts, SQL_PARAMS_NAMED, 'c');
            $left = $DB->count_records_select(
                'local_catquiz_personparams',
                "userid = :u AND contextid $insql",
                $params + ['u' => $userid]
            );
            if ($left > 0) {
                $say("$left engine person parameters left after collection");
            }
        }
    }
}
if ((int) $options['min-baseline'] > 0 && $report['retried'] === 0) {
    $report['problems'][] = 'no sitting was retried: the resume could not be checked';
}

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    cli_writeln(sprintf(
        '%d sitting(s) in %d run(s), %d retried; baselines of later executions: %s',
        $report['sittings'],
        count($report['runs']),
        $report['retried'],
        $report['baselines'] === [] ? '-' : implode(',', $report['baselines'])
    ));
    foreach ($report['problems'] as $problem) {
        cli_writeln('PROBLEM ' . $problem);
    }
    cli_writeln($report['problems'] === [] ? 'PASS' : 'FAIL');
}
exit($report['problems'] === [] && $report['sittings'] > 0 ? 0 : 1);
