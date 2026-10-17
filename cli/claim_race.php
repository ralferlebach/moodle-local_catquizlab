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
 * Several claimers at the same instant: no sitting may be handed out twice.
 *
 * The claim read the oldest queued sitting and then marked it running, inside
 * a transaction that did not make the pair atomic. Eight processes claiming at
 * once got 62 sittings 200 times; in a load test two executions played the same
 * sitting and Moodle refused the second's answers (HTTP 404, out of sequence).
 * Since 0.7.42 claims are serialised by a lock. This script is the regression
 * check: it starts --processes copies of itself, each claiming --claims times
 * from the same instant, counts the sittings handed out more than once and
 * fails when there is one. The run's sittings are failed afterwards, so that no
 * real worker plays what the claimers took.
 *
 * Options: --runid=N (a run with queued sittings, e.g. from e2e_prepare.php),
 * --processes=8, --claims=25. Internal: --child, --workerid, --start.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params([
    'runid' => 0, 'processes' => 8, 'claims' => 25,
    'child' => false, 'workerid' => '', 'start' => 0,
]);

\core\session\manager::set_user(get_admin());

if (!empty($options['child'])) {
    // A claimer: wait for the common start, claim, print what was handed out.
    while (microtime(true) < (float) $options['start']) {
        usleep(1000);
    }
    for ($i = 0; $i < (int) $options['claims']; $i++) {
        $claim = \local_catquizlab\external\job_claim::execute((string) $options['workerid']);
        if (!empty($claim['hasjob'])) {
            echo (int) $claim['attemptid'], "\n";
        }
    }
    exit(0);
}

$runid = (int) $options['runid'];
if ($runid <= 0) {
    cli_error('--runid=N is required: a run with queued sittings.');
}
$processes = max(2, (int) $options['processes']);
$claims = max(1, (int) $options['claims']);
$queued = $DB->count_records('local_catquizlab_attempt', [
    'runid' => $runid, 'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED,
]);

$start = microtime(true) + 2.0;
$children = [];
for ($p = 1; $p <= $processes; $p++) {
    $command = [PHP_BINARY, __FILE__, '--child', '--workerid=claimrace-' . $p, '--claims=' . $claims, '--start=' . $start];
    $handle = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($handle)) {
        cli_error('Could not start claimer ' . $p);
    }
    $children[] = [$handle, $pipes];
}
$handedout = [];
$errors = [];
foreach ($children as [$handle, $pipes]) {
    foreach (preg_split('/\R/', (string) stream_get_contents($pipes[1]), -1, PREG_SPLIT_NO_EMPTY) as $id) {
        $handedout[] = (int) $id;
    }
    $error = trim((string) stream_get_contents($pipes[2]));
    if ($error !== '') {
        $errors[] = $error;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($handle);
}

$counts = array_count_values($handedout);
$twice = array_filter($counts, static fn(int $n): bool => $n > 1);
// What the claimers took is not for a real worker to play.
\local_catquizlab\local\attempt_scheduler::abort($runid);

cli_writeln(sprintf(
    'Run %d: %d queued; %d claimers × %d claims at once: %d handed out, %d distinct, %d handed out more than once.',
    $runid,
    $queued,
    $processes,
    $claims,
    count($handedout),
    count($counts),
    count($twice)
));
foreach (array_slice($twice, 0, 10, true) as $id => $n) {
    cli_writeln(sprintf('  sitting %d handed out %d times', $id, $n));
}
foreach (array_slice($errors, 0, 5) as $error) {
    cli_writeln('  claimer error: ' . substr($error, 0, 300));
}
if ($handedout === []) {
    cli_writeln('FAIL: nothing was handed out, so nothing was tested.');
    exit(1);
}
cli_writeln($twice === [] && $errors === [] ? 'PASS' : 'FAIL');
exit($twice === [] && $errors === [] ? 0 : 1);
