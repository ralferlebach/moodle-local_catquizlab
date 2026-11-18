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
 * Options: --state=running|calculating|blocked|finished|empty|parallel. Prints
 * PROGRESS_<STATE>_EXPERIMENTID=<id>. --state=overview seeds three experiments for "All experiments" (#158),
 * --state=library 55 planned ones for the A–Z order (#153); they print OVERVIEW_* and LIBRARY_* lines.
 * --state=stopped: the CLARA case, six runs of 1000 stopped with 1018 collected (#141, #158, #166).
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
if (
    !in_array($state, ['running', 'calculating', 'blocked', 'finished', 'empty', 'parallel', 'overview', 'library',
        'stopped'], true)
) {
    cli_error('--state must be running, calculating, blocked, finished, empty, parallel, overview, library or stopped');
}

// The CLARA case of 15.11.2026 (#141 DoD-CLARA-02, #158, #166): six runs of 1000 sittings, stopped by the operator
// with 1018 collected — 997 in the third run, 21 in the sixth, none in the others. Stopped the real way
// (experiment_stop::stop(), as "Stop experiment" does), so that every page reads what a stop leaves. Prints
// STOPPED_EXPERIMENTID and STOPPED_RUNS=<id:collected,…> in run order.
if ($state === 'stopped') {
    $now = time();
    $definition = experiment_definition::example_baseline();
    // A name A–Z puts first, so that "All experiments" shows it on its first page wherever it runs.
    $definition['name'] = '0 Stopped six ' . date('Ymd-His');
    $experimentid = (int) experiment_service::save($definition)['id'];
    $runs = [];
    foreach ([0, 0, 997, 0, 0, 21] as $r => $collected) {
        $strategy = $r % 2 ? 'fastest' : 'classic';
        $runid = (int) $DB->insert_record('local_catquizlab_run', (object) ['experimentid' => $experimentid,
            'cellkey' => 'strategy=' . $strategy . '|r' . $r, 'seed' => 1, 'replication' => 1,
            'status' => registry::STATUS_RUNNING,
            'manifestjson' => json_encode(['config' => ['strategy' => ['key' => $strategy],
                'definition' => ['strategy' => $strategy]]]),
            'usermodified' => 0, 'timecreated' => $now - HOURSECS, 'timemodified' => $now]);
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $done = $i < $collected;
            $rows[] = (object) ['runid' => $runid, 'personid' => 0,
                'status' => $done ? attempt_scheduler::STATUS_COLLECTED : attempt_scheduler::STATUS_QUEUED,
                'tries' => $done ? 1 : 0, 'runtimems' => $done ? 20000 : null, 'leaseexpires' => 0,
                'nextruntime' => 0, 'timecreated' => $now - HOURSECS, 'timemodified' => $now];
        }
        $DB->insert_records('local_catquizlab_attempt', $rows);
        $runs[] = $runid . ':' . $collected;
    }
    \local_catquizlab\local\experiment_stop::stop($experimentid);
    cli_writeln('STOPPED_EXPERIMENTID=' . $experimentid);
    cli_writeln('STOPPED_RUNS=' . implode(',', $runs));
    exit(0);
}

// Issue #153: 55 planned experiments under one prefix, with umlauts, case, numbers and equal names, so the library,
// the selector and the overview can be checked for A–Z past 50.
if ($state === 'library') {
    $prefix = 'Order ' . date('Ymd-His') . ' ';
    $names = ['zebra', 'Äpfel', 'apfel', 'Apfel', 'Öl', 'oel', 'Run 10', 'Run 9', 'beta', 'Beta', 'ätzend', 'Zürich'];
    for ($i = 1; count($names) < 55; $i++) {
        $names[] = 'Experiment ' . $i;
    }
    foreach ($names as $name) {
        $definition = experiment_definition::example_baseline();
        $definition['name'] = $prefix . $name;
        experiment_service::save($definition);
    }
    cli_writeln('LIBRARY_COUNT=' . count($names));
    cli_writeln('LIBRARY_PREFIX=' . $prefix);
    exit(0);
}

// Issue #158: three experiments for "All experiments", named so they sort A-Z apart from everything else here.
// Not started (two draft runs), running (30 runs, one paused, one held) and finished (two runs). Queued sittings
// are due in thirty days: nothing on the installation touches them.
if ($state === 'overview') {
    $now = time();
    $stamp = date('Ymd-His');
    $ids = [];
    foreach (['a running' => 30, 'b finished' => 2, 'c planned' => 2] as $label => $runs) {
        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Overview ' . $stamp . ' ' . $label;
        $experimentid = (int) experiment_service::save($definition)['id'];
        $ids[$label] = $experimentid;
        for ($r = 0; $r < $runs; $r++) {
            $strategy = $r % 2 ? 'fastest' : 'classic';
            $status = $label === 'c planned' ? registry::STATUS_DRAFT
                : ($label === 'b finished' ? registry::STATUS_FINISHED : registry::STATUS_RUNNING);
            $runid = (int) $DB->insert_record('local_catquizlab_run', (object) ['experimentid' => $experimentid,
                'cellkey' => 'strategy=' . $strategy . '|r' . $r, 'seed' => 1, 'replication' => 1, 'status' => $status,
                'manifestjson' => json_encode(['config' => ['strategy' => ['key' => $strategy],
                    'definition' => ['strategy' => $strategy]]]),
                'usermodified' => 0, 'timecreated' => $now, 'timemodified' => $now]);
            for ($i = 0; $i < 4; $i++) {
                $done = $label === 'b finished' || ($label === 'a running' && $i < 2);
                $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid,
                    'personid' => 0, 'status' => $done ? attempt_scheduler::STATUS_COLLECTED : attempt_scheduler::STATUS_QUEUED,
                    'tries' => $done ? 1 : 0, 'runtimems' => $done ? 20000 : null, 'leaseexpires' => 0,
                    'nextruntime' => $done ? 0 : $now + 30 * DAYSECS, 'timecreated' => $now, 'timemodified' => $now]);
                if ($done) {
                    $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid,
                        'runid' => $runid, 'tryno' => 1, 'outcome' => attempt_history::STARTED,
                        'timecreated' => $now - 600 + 30 * $i]);
                    $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid,
                        'runid' => $runid, 'tryno' => 1, 'outcome' => attempt_history::COLLECTED, 'runtimems' => 20000,
                        'timecreated' => $now - 580 + 30 * $i]);
                }
            }
            if ($label === 'a running' && $r === 3) {
                run_lifecycle::set_paused($runid, true, 'seeded');
            }
            if ($label === 'a running' && $r === 5) {
                run_lifecycle::set_paused($runid, true, 'circuit_breaker:submit_http_5xx', ['seeded' => true]);
            }
        }
    }
    foreach ($ids as $label => $id) {
        cli_writeln('OVERVIEW_' . strtoupper(explode(' ', $label)[1]) . '_EXPERIMENTID=' . $id);
    }
    cli_writeln('OVERVIEW_PREFIX=Overview ' . $stamp);
    exit(0);
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
    // Issue #160: the first run nearly done (17 of 20, its last three created first), the second being played.
    'parallel'    => [[17, 0], [5, 2]],
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
            // The nearly finished run's last sittings were created first: first in the claim order (#160).
            'timecreated' => $state === 'parallel' && $index === 0 ? $now - HOURSECS : $now, 'timemodified' => $now,
        ]);
        if ($status === attempt_scheduler::STATUS_QUEUED) {
            continue;
        }
        $begin = $state === 'calculating' ? $start + $i : $clock + intdiv($i - 1, 2) * $seconds;
        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid, 'runid' => $run->id,
            'tryno' => 1, 'outcome' => attempt_history::STARTED, 'workerid' => 'seed-worker', 'timecreated' => $begin]);
        if ($status === attempt_scheduler::STATUS_COLLECTED) {
            // In a running experiment the last ones end just now, so the capacity window sees them.
            $end = in_array($state, ['running', 'blocked', 'parallel'], true) ? min($now - 1, $begin + $seconds)
                : $begin + $seconds;
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
