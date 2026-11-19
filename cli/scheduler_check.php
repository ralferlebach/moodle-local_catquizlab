<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The scheduler under several experiments, several runs and many real workers (#163 DoD-03, DoD-CLARA-09).
 *
 * Builds, through the operators' own paths (e2e_prepare.php, "Start experiment", "Stop", "Resume", "Reset and
 * continue"):
 * - **O**, a backlog: one running run with --backlog sittings queued and due a day ago, held by the circuit breaker —
 *   the CLARA case of 17.11. (87 due, 0 claimable), only larger;
 * - **A** and **B**, two runs each, started in that order;
 * - **S**, one run, started after them;
 * - **C**, one run, started last and held by the circuit breaker from the beginning.
 *
 * Then --workers real workers (the browser worker) play, and every 3 s the script reads the line and every sitting:
 * 1. while A holds the line and plays, A is **stopped**; the line moves on (B), A is **resumed** and gets a place at
 *    its end;
 * 2. while B holds the line, C's run is **released** (reset and continue): C is given a place at the end;
 * 3. while S holds the line and plays, S is **stopped** and stays stopped;
 * 4. when everything else is through, the workers are stopped and **30 minutes of polling** follow on a fake clock: every
 *    30 s every stored time of the lab moves 30 s into the past (the same as 30 s passing), the line is asked to move
 *    on and the pipeline tick runs as cron would, and every experiment's state, place in the line and sitting counts are
 *    read as the progress page polls them — nothing may change;
 * 5. S is resumed, the workers start again and play it to the end.
 *
 * Checked throughout: one holder at most; every sitting in play is the holder's (no claim beside the line); no sitting
 * started while it was already in play (no double claim); the backlog never touched; statuses (experiment, line, runs,
 * sittings) consistent. At the end: every sitting of A, B, C and S collected, none failed or lost, O untouched and then
 * closed by a stop, the line empty, and the read-only inventory without a contradiction.
 *
 *     php local/catquizlab/cli/scheduler_check.php --workers=11 --persons=8 --backlog=1200 --out=/tmp/scheduler-11
 *
 * Writes timeline.jsonl (every sample), events.txt and summary.json to --out. Exit 0 on PASS, 1 on FAIL.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\acceptance_inventory;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\circuit_breaker;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\experiment_resume;
use local_catquizlab\local\experiment_runner;
use local_catquizlab\local\experiment_stop;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\worker_launcher;
use local_catquizlab\local\worker_registry;

[$options] = cli_get_params(['workers' => 11, 'persons' => 8, 'backlog' => 1200, 'minutes' => 40, 'out' => '',
    'poll-minutes' => 30, 'force' => false, 'help' => false]);
if ($options['help']) {
    echo "php cli/scheduler_check.php [--workers=11] [--persons=8] [--backlog=1200] [--minutes=40] [--out=DIR]\n";
    exit(0);
}
\core\session\manager::set_user(get_admin());
$workers = max(1, (int) $options['workers']);
$persons = max(1, (int) $options['persons']);
$out = (string) $options['out'] !== '' ? rtrim((string) $options['out'], '/') : make_request_directory();
check_dir_exists($out);
$timeline = fopen($out . '/timeline.jsonl', 'w');
$eventsfile = fopen($out . '/events.txt', 'w');
$t0 = time();
$say = static function (string $text) use ($t0, $eventsfile): void {
    $line = sprintf('%+6ds  %s', time() - $t0, $text);
    cli_writeln($line);
    fwrite($eventsfile, $line . "\n");
};
$problems = [];
$problem = static function (string $what) use (&$problems, $say): void {
    if (count($problems) < 200) {
        $problems[] = $what;
    }
    $say('PROBLEM: ' . $what);
};

$open = $DB->count_records_select(
    'local_catquizlab_attempt',
    'status IN (?, ?)',
    [attempt_scheduler::STATUS_QUEUED, attempt_scheduler::STATUS_RUNNING]
);
if ($open > 0 && !$options['force']) {
    cli_error("There are {$open} sittings queued or in play on this installation; this check needs it to itself (--force).");
}

/**
 * Provision an experiment through e2e_prepare.php.
 *
 * @param string $name Its name.
 * @param int $persons Persons per run.
 * @param int $runs Runs.
 * @return array{experimentid: int, runids: int[]}
 */
function local_catquizlab_scheduler_prepare(string $name, int $persons, int $runs): array {
    $cmd = [PHP_BINARY, __DIR__ . '/e2e_prepare.php', '--persons=' . $persons, '--replications=' . $runs,
        '--name=' . $name];
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $lines, $code);
    $values = [];
    foreach ($lines as $line) {
        if (preg_match('/^([a-z_]+)=(.*)$/', $line, $m)) {
            $values[$m[1]] = $m[2];
        }
    }
    if ($code !== 0 || empty($values['experimentid'])) {
        cli_error('e2e_prepare.php failed for ' . $name . ': ' . implode(' | ', array_slice($lines, -5)));
    }

    return ['experimentid' => (int) $values['experimentid'],
        'runids' => array_map('intval', explode(',', (string) ($values['runids'] ?? $values['runid'])))];
}

// The experiments.
$x = [];
$plan = ['O' => [1, 1], 'A' => [$persons, 2], 'B' => [$persons, 2], 'S' => [$persons, 1], 'C' => [$persons, 1]];
foreach ($plan as $key => [$n, $runs]) {
    $x[$key] = local_catquizlab_scheduler_prepare('Scheduler ' . $key, $n, $runs);
    $say(sprintf('Experiment %s = %d: runs %s', $key, $x[$key]['experimentid'], implode(',', $x[$key]['runids'])));
}
$name = array_flip(array_map(static fn(array $e): int => $e['experimentid'], $x));

// O: the backlog. Its run's sittings get a day-old queue time, --backlog more are added beside them (the claim never
// reaches them: the circuit breaker holds the run), and the run goes to running as on CLARA.
$orun = $x['O']['runids'][0];
$day = time() - DAYSECS;
$DB->set_field('local_catquizlab_attempt', 'timecreated', $day, ['runid' => $orun]);
$rows = [];
for ($i = 0; $i < (int) $options['backlog']; $i++) {
    $rows[] = (object) ['runid' => $orun, 'personid' => 0, 'status' => attempt_scheduler::STATUS_QUEUED, 'tries' => 0,
        'nextruntime' => 0, 'timecreated' => $day + $i, 'timemodified' => $day + $i];
    if (count($rows) >= 500) {
        $DB->insert_records('local_catquizlab_attempt', $rows);
        $rows = [];
    }
}
if ($rows) {
    $DB->insert_records('local_catquizlab_attempt', $rows);
}
run_lifecycle::set_status($orun, \local_catquizlab\local\registry::STATUS_RUNNING, 'scheduler-check-backlog');
run_lifecycle::set_paused($orun, true, circuit_breaker::PAUSE_REASON . ':submit_http_5xx');
$backlog = $DB->get_records_menu('local_catquizlab_attempt', ['runid' => $orun], '', 'id, status');
$say(sprintf('Backlog O: %d sittings queued and due since a day, run held by the circuit breaker.', count($backlog)));

foreach (['A', 'B', 'S', 'C'] as $key) {
    $start = experiment_runner::start($x[$key]['experimentid']);
    $say(sprintf('Start %s: %s (%s)', $key, $start['ok'] ? 'queued' : 'refused', $start['reason']));
    if (empty($start['ok'])) {
        cli_error('Experiment ' . $key . ' could not be started.');
    }
}
// C, started and waiting in the line, is then held by the circuit breaker (an experiment already held is not
// startable: "blocked").
run_lifecycle::set_paused($x['C']['runids'][0], true, circuit_breaker::PAUSE_REASON . ':submit_http_5xx');
$say('C held by the circuit breaker.');
execution_queue::take_turn();
$order = array_map(
    static fn(int $id): string => $name[$id] ?? (string) $id,
    array_column(execution_queue::entries(), 'experimentid')
);
$say('Line: ' . implode(' → ', $order) . '; holder ' . ($name[execution_queue::holder()] ?? '-'));

// Workers: as the smoke test and the line check set them up.
$base = getenv('CATLAB_BASE_URL') ?: ($CFG->wwwroot ?: 'http://127.0.0.1:8000');
set_config('worker_base_url', rtrim($base, '/'), 'local_catquizlab');
$node = getenv('CATLAB_NODE') ?: trim((string) @exec('command -v node 2>/dev/null'));
if ($node !== '' && is_executable($node)) {
    set_config('worker_node_path', $node, 'local_catquizlab');
}
\local_catquizlab\local\setup_wizard::run(true);
$ready = \local_catquizlab\local\system_health::worker_modules_installed()
    && \local_catquizlab\local\worker_runtime::browser_present();
if (!$ready) {
    \local_catquizlab\local\worker_runtime::ensure();
}
$concurrencybefore = get_config('local_catquizlab', 'worker_concurrency');
$execbefore = get_config('local_catquizlab', 'worker_exec_enabled');
register_shutdown_function(static function () use ($concurrencybefore, $execbefore): void {
    set_config('worker_concurrency', $concurrencybefore === false ? null : $concurrencybefore, 'local_catquizlab');
    set_config('worker_exec_enabled', $execbefore === false ? null : $execbefore, 'local_catquizlab');
});
set_config('worker_concurrency', $workers, 'local_catquizlab');

/**
 * Start the workers and keep them up.
 *
 * @return int Live workers.
 */
function local_catquizlab_scheduler_workers(): int {
    worker_registry::reap();
    worker_launcher::launch_pool(worker_launcher::config_from_settings());

    return count(worker_registry::live());
}

/**
 * Ask every live worker to stop and wait until none is live (or 120 s).
 *
 * @return int Live workers left.
 */
function local_catquizlab_scheduler_stop_workers(): int {
    set_config('worker_exec_enabled', 0, 'local_catquizlab');
    foreach (worker_registry::live() as $worker) {
        worker_registry::request_stop((string) $worker->workerid);
    }
    $until = time() + 120;
    while (time() < $until && count(worker_registry::live()) > 0) {
        sleep(3);
        worker_registry::reap();
        foreach (worker_registry::live() as $worker) {
            if ((int) $worker->pid > 0 && !file_exists('/proc/' . (int) $worker->pid)) {
                worker_registry::record_stop((string) $worker->workerid, 'scheduler-check');
            }
        }
    }

    return count(worker_registry::live());
}

/**
 * Every sitting of the five experiments, with its experiment.
 *
 * @param int[] $experimentids The experiments.
 * @return array<int, array{e: int, status: int}>
 */
function local_catquizlab_scheduler_sittings(array $experimentids): array {
    global $DB;

    [$in, $params] = $DB->get_in_or_equal($experimentids);
    $out = [];
    $rows = $DB->get_recordset_sql(
        "SELECT a.id, a.status, r.experimentid
           FROM {local_catquizlab_attempt} a
           JOIN {local_catquizlab_run} r ON r.id = a.runid
          WHERE r.experimentid {$in}",
        $params
    );
    foreach ($rows as $row) {
        $out[(int) $row->id] = ['e' => (int) $row->experimentid, 'status' => (int) $row->status];
    }

    return $out;
}

$ids = array_map(static fn(array $e): int => $e['experimentid'], $x);
$played = array_values(array_map(static fn(string $k): int => $x[$k]['experimentid'], ['A', 'B', 'S', 'C']));
$maxlive = 0;
$maxinplay = 0;
$holders = [];
$phase = 'play';

// One reading: line, holder, states, sittings by experiment and status; checked against the rules. $phase says what
// is going on; returns the reading.
$read = static function (string $phase) use (
    &$holders,
    &$maxlive,
    &$maxinplay,
    $ids,
    $name,
    $problem,
    $timeline,
    $t0,
    $orun,
    $backlog
): array {
    global $DB;

    $holder = execution_queue::holder();
    $running = $DB->count_records('local_catquizlab_execqueue', ['state' => execution_queue::RUNNING]);
    $sittings = local_catquizlab_scheduler_sittings(array_values($ids));
    $by = [];
    foreach ($sittings as $s) {
        $key = $name[$s['e']] ?? (string) $s['e'];
        $by[$key][$s['status']] = ($by[$key][$s['status']] ?? 0) + 1;
    }
    $states = [];
    foreach ($ids as $key => $id) {
        $states[$key] = ['state' => experiment_runner::state($id)['state'], 'line' => (string) execution_queue::state_of($id)];
    }
    $live = count(worker_registry::live());
    $maxlive = max($maxlive, $live);
    $inplay = array_filter($sittings, static fn(array $s): bool => $s['status'] === attempt_scheduler::STATUS_RUNNING);
    $maxinplay = max($maxinplay, count($inplay));
    if ($holder > 0 && (empty($holders) || end($holders)['holder'] !== $holder)) {
        $holders[] = ['t' => time() - $t0, 'holder' => $holder, 'name' => $name[$holder] ?? (string) $holder];
    }

    if ($running > 1) {
        $problem("{$running} experiments hold the line at once");
    }
    $beside = array_filter($inplay, static fn(array $s): bool => $s['e'] !== $holder);
    if ($beside) {
        $problem(count($beside) . ' sitting(s) in play beside the line (holder ' . ($name[$holder] ?? $holder) . '): '
            . implode(',', array_unique(array_map(static fn(array $s): string => $name[$s['e']] ?? (string) $s['e'], $beside))));
    }
    $now = $DB->get_records_menu('local_catquizlab_attempt', ['runid' => $orun], '', 'id, status');
    if ($now != $backlog && $phase !== 'end') {
        $problem('the backlog O changed');
    }
    foreach ($states as $key => $s) {
        $inline = in_array($s['line'], [execution_queue::RUNNING, execution_queue::WAITING], true);
        if ($s['state'] === experiment_runner::STATE_STOPPED && $inline) {
            $problem("{$key} is stopped and still in the line ({$s['line']})");
        }
        if ($key !== 'O' && $s['line'] === execution_queue::RUNNING && $holder !== $ids[$key]) {
            $problem("{$key} reads as holding the line, the holder is " . ($name[$holder] ?? $holder));
        }
    }
    $reading = ['t' => time() - $t0, 'phase' => $phase, 'holder' => $name[$holder] ?? ($holder ?: '-'), 'live' => $live,
        'inplay' => count($inplay), 'states' => $states, 'sittings' => $by];
    fwrite($timeline, json_encode($reading) . "\n");

    return $reading;
};

$step = 'A-stop';
$done = static function () use ($played): bool {
    global $DB;

    [$in, $params] = $DB->get_in_or_equal($played, SQL_PARAMS_NAMED, 'e');
    return !$DB->record_exists_sql(
        "SELECT 1 FROM {local_catquizlab_attempt} a JOIN {local_catquizlab_run} r ON r.id = a.runid
          WHERE r.experimentid {$in} AND r.status <> :cancelled AND a.status IN (:q, :r, :t)",
        $params + ['cancelled' => \local_catquizlab\local\registry::STATUS_CANCELLED,
            'q' => attempt_scheduler::STATUS_QUEUED, 'r' => attempt_scheduler::STATUS_RUNNING,
            't' => attempt_scheduler::STATUS_TRACEPENDING]
    );
};

$say("Workers: {$workers}.");
local_catquizlab_scheduler_workers();
$deadline = time() + (int) $options['minutes'] * MINSECS;
$resumeat = 0;
while (time() < $deadline) {
    sleep(3);
    $r = $read('play');
    $holder = execution_queue::holder();
    if ($step === 'A-stop' && $holder === $x['A']['experimentid'] && $r['inplay'] > 0) {
        $stop = experiment_stop::stop($x['A']['experimentid']);
        $said = array_intersect_key($stop, array_flip(['ok', 'reason', 'runs', 'cancelled', 'closed']));
        $say('Stop A while it plays (' . $r['inplay'] . ' in play): ' . json_encode($said));
        $step = 'A-resume';
        $resumeat = time() + 20;
    } else if ($step === 'A-resume' && time() >= $resumeat) {
        $resume = experiment_resume::resume($x['A']['experimentid']);
        $say('Resume A: ' . json_encode(array_intersect_key($resume, array_flip(['ok', 'reason', 'runs', 'requeued']))));
        $step = 'C-release';
    } else if ($step === 'C-release' && $holder === $x['B']['experimentid']) {
        $release = circuit_breaker::reset_and_continue($x['C']['runids'][0]);
        $say('Release C from the circuit breaker while B holds the line: ' . json_encode($release));
        $step = 'S-stop';
    } else if ($step === 'S-stop' && $holder === $x['S']['experimentid'] && $r['inplay'] > 0) {
        $stop = experiment_stop::stop($x['S']['experimentid']);
        $said = array_intersect_key($stop, array_flip(['ok', 'reason', 'runs', 'cancelled', 'closed']));
        $say('Stop S while it plays (' . $r['inplay'] . ' in play): ' . json_encode($said));
        $step = 'rest';
    } else if ($step === 'rest' && $done()) {
        break;
    }
    // What the tick does every minute: workers for free slots, traces that came late (#138); the claims move the line.
    local_catquizlab_scheduler_workers();
    attempt_scheduler::retry_pending_traces();
}
$say('Phase 1 ended at step ' . $step . '.');

// Phase 2: 30 minutes of polling on a fake clock, workers stopped, S stopped, the rest through.
$left = local_catquizlab_scheduler_stop_workers();
$say('Workers stopped (' . $left . ' still live).');
$before = $read('poll-start');
$fields = [
    'local_catquizlab_attempt' => ['nextruntime', 'leaseexpires', 'timecreated', 'timemodified'],
    'local_catquizlab_worker' => ['heartbeat', 'timestarted', 'timemodified', 'startsat'],
    'local_catquizlab_execqueue' => ['timecreated', 'timestarted', 'timefinished'],
    'local_catquizlab_run' => ['timecreated', 'timemodified'],
    'local_catquizlab_experiment' => ['timecreated', 'timemodified'],
];
$tick = new \local_catquizlab\task\pipeline_tick();
$polls = max(1, (int) $options['poll-minutes']) * 2;
for ($i = 1; $i <= $polls; $i++) {
    foreach ($fields as $table => $columns) {
        foreach ($columns as $column) {
            $DB->execute("UPDATE {{$table}} SET {$column} = {$column} - 30 WHERE {$column} > 30");
        }
    }
    execution_queue::take_turn();
    if ($i % 2 === 0) {
        ob_start();
        try {
            $tick->execute();
        } finally {
            ob_end_clean();
        }
    }
    $now = $read('poll');
    foreach (['A', 'B', 'C', 'S'] as $key) {
        if ($now['states'][$key] != $before['states'][$key]) {
            $problem(sprintf(
                'after %d min of polling %s reads %s, before %s',
                $i / 2,
                $key,
                json_encode($now['states'][$key]),
                json_encode($before['states'][$key])
            ));
        }
        if (($now['sittings'][$key] ?? []) != ($before['sittings'][$key] ?? [])) {
            $problem(sprintf('after %d min of polling the sittings of %s changed', $i / 2, $key));
        }
    }
}
$say(sprintf('Polled %d minutes on the fake clock: %s.', $polls / 2, $problems ? 'see problems' : 'nothing changed'));

// Phase 3: S resumed, played to the end.
set_config('worker_exec_enabled', 1, 'local_catquizlab');
$resume = experiment_resume::resume($x['S']['experimentid']);
$say('Resume S: ' . json_encode(array_intersect_key($resume, array_flip(['ok', 'reason', 'runs', 'requeued']))));
local_catquizlab_scheduler_workers();
$deadline = time() + (int) $options['minutes'] * MINSECS;
while (time() < $deadline) {
    sleep(3);
    $read('resume-S');
    if ($done()) {
        break;
    }
    local_catquizlab_scheduler_workers();
    attempt_scheduler::retry_pending_traces();
}
local_catquizlab_scheduler_stop_workers();
$final = $read('final');

// No sitting started while it was in play: per sitting, the history alternates "started" and an end.
[$in, $params] = $DB->get_in_or_equal($played);
$history = $DB->get_recordset_sql(
    "SELECT l.id, l.attemptid, l.outcome
       FROM {local_catquizlab_attemptlog} l
       JOIN {local_catquizlab_attempt} a ON a.id = l.attemptid
       JOIN {local_catquizlab_run} r ON r.id = a.runid
      WHERE r.experimentid {$in}
   ORDER BY l.attemptid, l.id",
    $params
);
$ends = [attempt_history::COLLECTED, attempt_history::FAILED, attempt_history::REQUEUED, attempt_history::ABANDONED,
    attempt_history::CANCELLED, attempt_history::STALE];
$openstart = [];
$doublestarts = 0;
$starts = 0;
foreach ($history as $event) {
    $id = (int) $event->attemptid;
    if ($event->outcome === attempt_history::STARTED) {
        $starts++;
        if (!empty($openstart[$id])) {
            $doublestarts++;
        }
        $openstart[$id] = true;
    } else if (in_array($event->outcome, $ends, true)) {
        $openstart[$id] = false;
    }
}
$history->close();
$backlogtouched = $DB->count_records_select('local_catquizlab_attemptlog', 'runid = ?', [$orun]);

// The backlog, closed the way an operator closes it.
$stopo = experiment_stop::stop($x['O']['experimentid']);
$ocancelled = $DB->count_records('local_catquizlab_attempt', ['runid' => $orun, 'status' => attempt_scheduler::STATUS_CANCELLED]);
$say('Stop O (the backlog): ' . $ocancelled . ' of ' . count($backlog) . ' sittings closed as cancelled.');

$sittings = local_catquizlab_scheduler_sittings($played);
$bystatus = [];
foreach ($sittings as $s) {
    $bystatus[$s['status']] = ($bystatus[$s['status']] ?? 0) + 1;
}
$inventory = (new acceptance_inventory())->run();
file_put_contents($out . '/inventory.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$contradictions = array_values(array_filter(
    $inventory['checks'],
    static fn(array $c): bool => $c['verdict'] === acceptance_inventory::CONTRADICTION
));
$entries = $DB->count_records_select(
    'local_catquizlab_execqueue',
    'state IN (?, ?)',
    [execution_queue::RUNNING, execution_queue::WAITING]
);

$checks = [
    'one holder at most, every sitting in play the holder\'s, the backlog untouched, stopped means out of the line, '
        . 'nothing changed in 30 minutes of polling' => $problems === [],
    "{$workers} workers were live at once (most: {$maxlive}), {$maxinplay} sittings in play at once at most"
        => $maxlive >= min($workers, 2 * $persons) && $maxinplay > 1,
    'every step happened (A stopped and resumed, C released, S stopped and resumed)' => $step === 'rest',
    'every sitting of A, B, C and S collected (' . json_encode($bystatus) . ')'
        => ($bystatus[attempt_scheduler::STATUS_COLLECTED] ?? 0) === count($sittings) && count($sittings) > 0,
    "no sitting started while it was in play ({$starts} starts, {$doublestarts} while in play)" => $doublestarts === 0,
    'the backlog O never started (' . $backlogtouched . ' history rows) and was closed by its stop' => $backlogtouched === 0
        && $ocancelled === count($backlog),
    'the line empty at the end (' . $entries . ' open entries)' => $entries === 0,
    'the read-only inventory finds no contradiction (' . count($contradictions) . ')' => $contradictions === [],
];
$pass = true;
foreach ($checks as $what => $ok) {
    $say(($ok ? 'ok    ' : 'FAIL  ') . $what);
    $pass = $pass && $ok;
}
foreach ($contradictions as $c) {
    $say('  contradiction: ' . $c['section'] . ' / ' . $c['name'] . ': ' . json_encode($c['value']));
}
$turns = array_map(static fn(array $h): string => $h['name'] . '@' . $h['t'] . 's', $holders);
$say('Holders in turn: ' . implode(' → ', $turns));
file_put_contents($out . '/summary.json', json_encode(['workers' => $workers, 'persons' => $persons,
    'backlog' => count($backlog), 'experiments' => $x, 'maxlive' => $maxlive, 'maxinplay' => $maxinplay,
    'holders' => $holders, 'sittings' => $bystatus, 'starts' => $starts, 'doublestarts' => $doublestarts,
    'problems' => $problems, 'checks' => $checks, 'pass' => $pass, 'db' => $DB->get_dbfamily(),
    'seconds' => time() - $t0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$say($pass ? 'PASS' : 'FAIL');
exit($pass ? 0 : 1);
