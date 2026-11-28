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
 * A run's questions by identity (#180): the projection the progress page shows, and the engine attempts behind it.
 *
 * Every engine attempt of the run's persons in its test once: bound to a sitting (by the sitting's state), retired by a
 * decision, or owned by no sitting — and the bindings that do not add up. Then, attempt by attempt, those that are not
 * simply collected or being played: queued with kept answers, on hold, ended, unknown, retired, foreign, missing.
 * Reads only; changes nothing.
 *
 *     php local/catquizlab/cli/question_progress.php --runid=121 [--json] [--limit=200]
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\question_progress;

[$options] = cli_get_params(['runid' => 0, 'json' => false, 'limit' => 200, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int) $options['runid'] <= 0) {
    echo "A run's questions by identity (#180), and the engine attempts behind them. Reads only.\n"
        . "  --runid=N   The run.\n"
        . "  --limit=N   Engine attempts listed at most (200).\n"
        . "  --json      As JSON.\n";
    exit(0);
}
$runid = (int) $options['runid'];
$limit = max(1, (int) $options['limit']);
$projection = question_progress::for_runs([$runid => []])[$runid];

// The sittings with a binding that is not simply collected or running, and the attempts no sitting owns.
$sql = "SELECT a.id AS sitting, a.status, a.tries, a.executionid, a.engineattemptid, aa.id AS host, aa.userid,
               aa.uniqueid AS qubaid, aa.attemptstate, aa.questionsattempted, p.moodleuserid
          FROM {local_catquizlab_attempt} a
     LEFT JOIN {local_catquizlab_person} p ON p.id = a.personid
     LEFT JOIN {adaptivequiz_attempt} aa ON aa.id = a.engineattemptid
         WHERE a.runid = :runid AND a.status NOT IN (:collected, :validated, :tracepending, :running)
      ORDER BY a.id";
$listed = [];
foreach (
    $DB->get_records_sql($sql, ['runid' => $runid, 'collected' => attempt_scheduler::STATUS_COLLECTED,
        'validated' => attempt_scheduler::STATUS_VALIDATED, 'tracepending' => attempt_scheduler::STATUS_TRACEPENDING,
        'running' => attempt_scheduler::STATUS_RUNNING], 0, $limit) as $row
) {
    $listed[] = ['kind' => 'sitting'] + (array) $row;
}
$sql = "SELECT aa.id AS host, aa.userid, aa.uniqueid AS qubaid, aa.attemptstate, aa.questionsattempted,
               (SELECT MAX(x.attemptid) FROM {local_catquizlab_retired} x WHERE x.engineattemptid = aa.id) AS retiredfrom
          FROM {local_catquizlab_run} r
          JOIN {course_modules} cm ON cm.id = r.testcmid
          JOIN {local_catquizlab_person} p ON p.runid = r.id
          JOIN {adaptivequiz_attempt} aa ON aa.instance = cm.instance AND aa.userid = p.moodleuserid
         WHERE r.id = :runid
               AND NOT EXISTS (SELECT 1 FROM {local_catquizlab_attempt} b WHERE b.runid = r.id AND b.engineattemptid = aa.id)
      ORDER BY aa.id";
foreach ($DB->get_records_sql($sql, ['runid' => $runid], 0, $limit) as $row) {
    $listed[] = ['kind' => $row->retiredfrom ? 'retired' : 'unowned'] + (array) $row;
}
$out = ['run' => $runid, 'projection' => $projection, 'listed' => $listed, 'limit' => $limit];
if ($options['json']) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
$p = $projection;
cli_writeln("Run {$runid}: {$p['done']} answered of " . ($p['expected'] ?? 'unknown') . " ({$p['kind']}), "
    . ($p['remaining'] ?? 'unknown') . ' ahead' . ($p['notahead'] !== null ? ", {$p['notahead']} not ahead" : ''));
foreach ($p['buckets'] as $name => [$n, $answers]) {
    cli_writeln(sprintf('  %-10s %6d attempt(s) %9d answered', $name, $n, $answers));
}
cli_writeln('  sittings: ' . json_encode($p['sittings']) . '; unbound open ' . $p['unbound'] . ', ambiguous persons '
    . $p['ambiguous']);
cli_writeln('  does not add up: ' . ($p['diagnostics'] === [] ? 'nothing' : implode(', ', $p['diagnostics'])));
cli_writeln(count($listed) . ' engine attempt(s) or sitting(s) listed (at most ' . $limit . ' of each):');
foreach ($listed as $row) {
    cli_writeln('  ' . json_encode($row));
}
