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
 * An experiment caught in one state of its execution, for the progress interface test (#125, #128).
 *
 * Two runs (fastest, allsubs), twenty sittings each, with an execution history
 * dated back from now. Nothing is played: the state is written as the worker
 * and the scheduler would have left it.
 *
 * Options: --state=running|calculating|blocked|finished|empty. Prints
 * PROGRESS_<STATE>_EXPERIMENTID=<id>.
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
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;

\core\session\manager::set_user(get_admin());

[$options] = cli_get_params(['state' => 'running']);
$state = (string) $options['state'];
if (!in_array($state, ['running', 'calculating', 'blocked', 'finished', 'empty'], true)) {
    cli_error('--state must be running, calculating, blocked, finished or empty');
}

$definition = experiment_definition::example_baseline();
$definition['name'] = 'Progress ' . $state . ' ' . date('Ymd-His');
$definition['sweep']['factors']['strategy'] = ['fastest', 'allsubs'];
$definition['persons']['count'] = 20;
$experimentid = (int) experiment_service::save($definition)['id'];
if ($state === 'empty') {
    cli_writeln('PROGRESS_EMPTY_EXPERIMENTID=' . $experimentid);
    exit(0);
}
experiment_service::create_sweep($experimentid);

// How many sittings of each run are collected, in flight; their durations.
$plan = [
    'running'     => [[20, 0], [8, 2]],
    'calculating' => [[1, 2], [0, 0]],
    'blocked'     => [[20, 0], [5, 0]],
    'finished'    => [[20, 0], [20, 0]],
][$state];
$durations = [12, 45];

$now = time();
// Run 1 takes 120 s in two slots, run 2's eight take 180 s: the last ones end just now.
$start = $now - ($state === 'calculating' ? 30 : ($state === 'finished' ? 1200 : 305));
$runs = array_values($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC'));
foreach ($runs as $index => $run) {
    [$collected, $inflight] = $plan[$index];
    $seconds = $durations[$index];
    run_lifecycle::set_status((int) $run->id, registry::STATUS_RUNNING, 'seeded');
    // Run 1 first, then run 2, in two slots: as the queue hands them out.
    $clock = $index === 0 ? $start : $start + 120;
    for ($i = 1; $i <= 20; $i++) {
        $personid = $DB->insert_record('local_catquizlab_person', (object) [
            'runid' => $run->id, 'twinid' => sprintf('r001-t%05d', $i), 'twinindex' => $i,
            'stratum' => 'conforming', 'severity' => 'none', 'abilityglobal' => 0.0,
            'profilejson' => json_encode(['global' => 0.0, 'categories' => []]),
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $status = attempt_scheduler::STATUS_QUEUED;
        if ($i <= $collected) {
            $status = attempt_scheduler::STATUS_COLLECTED;
        } else if ($i <= $collected + $inflight) {
            $status = attempt_scheduler::STATUS_RUNNING;
        }
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $personid, 'status' => $status,
            'tries' => $status === attempt_scheduler::STATUS_QUEUED ? 0 : 1,
            'leaseowner' => $status === attempt_scheduler::STATUS_RUNNING ? 'seed-worker' : null,
            // Held for a day, and the queued ones due in thirty: nothing on the
            // installation (a worker, the stale-lease task) touches a seeded state.
            'leaseexpires' => $status === attempt_scheduler::STATUS_RUNNING ? $now + DAYSECS : 0,
            'nextruntime' => $status === attempt_scheduler::STATUS_QUEUED ? $now + 30 * DAYSECS : 0,
            'runtimems' => $status === attempt_scheduler::STATUS_COLLECTED ? $seconds * 1000 : 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        if ($status === attempt_scheduler::STATUS_QUEUED) {
            continue;
        }
        $begin = $state === 'calculating' ? $start + $i : $clock + intdiv($i - 1, 2) * $seconds;
        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid, 'runid' => $run->id,
            'tryno' => 1, 'outcome' => attempt_history::STARTED, 'workerid' => 'seed-worker', 'timecreated' => $begin]);
        if ($status === attempt_scheduler::STATUS_COLLECTED) {
            // In a running experiment the last ones end just now, so the capacity window sees them.
            $end = $state === 'running' || $state === 'blocked' ? min($now - 1, $begin + $seconds) : $begin + $seconds;
            $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid, 'runid' => $run->id,
                'tryno' => 1, 'outcome' => attempt_history::COLLECTED, 'workerid' => 'seed-worker',
                'runtimems' => $seconds * 1000, 'timecreated' => $end]);
        }
    }
    if ($state === 'finished') {
        run_lifecycle::set_status((int) $run->id, registry::STATUS_FINISHED, 'seeded');
    }
}
if ($state === 'blocked') {
    run_lifecycle::set_paused((int) $runs[1]->id, true, 'circuit_breaker:submit_http_5xx', ['seeded' => true]);
}

cli_writeln('PROGRESS_' . strtoupper($state) . '_EXPERIMENTID=' . $experimentid);
