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
 * A sitting finished in the engine whose trace has vanished, reported the way a worker reports it (#138).
 *
 * The engine attempt exists and is complete, but its question usage is gone — the controlled form of "the
 * engine has not persisted, or has lost, what was answered". The completion goes through the worker's own
 * web-service function, job_complete, with the real collector behind it: nothing is stood in for.
 *
 * Prints TRACE_RUNID=<id> and TRACE_ATTEMPTID=<id>. With --giveup, the pipeline's retries are run on an
 * advanced clock until the sitting is given up, as cron would after TRACE_RETRIES looks.
 *
 * Development and CI only.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\external\job_complete;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;

[$options] = cli_get_params(['giveup' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "Seed a sitting whose engine trace has vanished. --giveup runs the trace retries to the end.\n";
    exit(0);
}

global $DB;

\core\session\manager::set_user(get_admin());
if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
    cli_error('mod_adaptivequiz is not installed.');
}

$definition = experiment_definition::example_baseline();
$definition['name'] = 'Vanished trace ' . time();
$experimentid = (int) experiment_service::save($definition)['id'];
$runid = (int) $DB->insert_record('local_catquizlab_run', (object) [
    'experimentid' => $experimentid, 'cellkey' => 'strategy=fastest', 'replication' => 1, 'seed' => 42,
    'status' => registry::STATUS_RUNNING,
    'manifestjson' => json_encode(['config' => ['definition' => $definition, 'strategy' => ['key' => 'fastest']]]),
    'timecreated' => time(), 'timemodified' => time(),
]);
$user = create_user_record('catquizlab_trace_' . $runid, random_string(16));
$personid = (int) $DB->insert_record('local_catquizlab_person', (object) [
    'runid' => $runid, 'twinid' => 't00001', 'twinindex' => 1, 'severity' => 'none', 'stratum' => 'conforming',
    'abilityglobal' => 0.0, 'profilejson' => json_encode(['global' => 0.0]), 'moodleuserid' => $user->id,
    'timecreated' => time(), 'timemodified' => time(),
]);
// Complete, twelve answered — and no question usage behind it: the trace is gone.
$engine = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
    'instance' => 0, 'userid' => $user->id, 'uniqueid' => 0, 'attemptstate' => 'complete',
    'attemptstopcriteria' => '', 'questionsattempted' => 12, 'timecreated' => time(), 'timemodified' => time(),
]);
$worker = 'catquizlab-exec-trace';
$attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
    'runid' => $runid, 'personid' => $personid, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
    'leaseowner' => $worker, 'leaseexpires' => time() + 600, 'timecreated' => time(), 'timemodified' => time(),
]);
attempt_history::record($attemptid, attempt_history::STARTED, ['workerid' => $worker, 'tryno' => 1]);

// The worker's report, through the worker's own function.
$result = job_complete::execute($attemptid, 'finished', 4200, $engine);
$status = (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $attemptid]);
if ($status !== attempt_scheduler::STATUS_TRACEPENDING) {
    cli_error("Expected the sitting to wait for its trace, found status {$status}: " . $result['message']);
}

if ($options['giveup']) {
    $now = time();
    for ($look = 0; $look <= attempt_scheduler::TRACE_RETRIES; $look++) {
        $now += attempt_scheduler::TRACE_RETRY_DELAY + 1;
        attempt_scheduler::retry_pending_traces($now);
    }
    $status = (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $attemptid]);
    if ($status !== attempt_scheduler::STATUS_FAILED) {
        cli_error("Expected the sitting to be given up, found status {$status}.");
    }
}

echo 'TRACE_RUNID=' . $runid . PHP_EOL;
echo 'TRACE_ATTEMPTID=' . $attemptid . PHP_EOL;
