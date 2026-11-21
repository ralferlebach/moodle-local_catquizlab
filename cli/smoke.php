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
 * One experiment, start to finish, against a real Moodle.
 *
 * Every other test in this plugin checks a part. This checks that the parts
 * connect: a definition becomes runs, runs become courses and questions and
 * people, a worker opens a real browser and answers real adaptive-quiz
 * questions through Moodle's own question engine, and the answers come back as
 * numbers somebody could put in a paper.
 *
 * It exists because the parts passed while the whole did not. A worker that
 * played exactly one attempt, a heartbeat refused for two releases, a status
 * card claiming a simulation that was not running: none of those were visible
 * to a unit test, and all of them were obvious the moment anybody watched an
 * experiment try to run.
 *
 * Exit code 0 only when attempts were collected and results exist.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_runner;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;
use local_catquizlab\local\worker_launcher;
use local_catquizlab\local\worker_registry;

[$options, $unrecognised] = cli_get_params([
    'help'     => false,
    'strategy' => 'classic',
    'persons'  => 2,
    'minutes'  => 5,
    'minanswers' => 15,
    'keep'     => false,
    'summary'  => '',
    'stall'    => 300,
    'list-strategies' => false,
    'provisioning-only' => false,
    'case' => '',
    'concurrency' => 0,
    'no-navigation-failures' => false,
    'expect-recovered' => 0,
    'expect-recovered-reasons' => '',
    'budget' => '',
    'pool' => 0,
    'require-all' => false,
    'strategies' => '',
    'eta-probe' => '',
    'watch-page' => '',
    'pairs' => '',
], ['h' => 'help']);

// What --summary=FILE says, written however the test ends (a cli_error() included): verdict "error" unless a later
// step set another.
$GLOBALS['smokesummary'] = ['verdict' => 'error', 'validity' => []];
if ((string) $options['summary'] !== '') {
    $summaryfile = (string) $options['summary'];
    register_shutdown_function(static function () use ($summaryfile): void {
        file_put_contents($summaryfile, json_encode($GLOBALS['smokesummary'], JSON_UNESCAPED_SLASHES) . "\n");
    });
}

if ($options['help']) {
    cli_writeln(<<<EOT
Play one experiment from definition to results.

Options:
  --strategy=classic   Which CAT strategy to smoke.
  --persons=2          How many simulated people.
  --minanswers=15      How many answers an attempt must give to count.
  --minutes=5          How long to wait for the worker.
  --require-all        Fail unless every queued sitting was collected or failed in that time (load matrix).
  --strategies=a,b     A sweep over these strategies: one run each, played by the same workers (#160).
  --eta-probe=FILE     Every 5 s a progress snapshot into FILE (JSON lines), each checked for contradictions; at the
                       end every forecast against the actual end of its run, summary in FILE.summary.json (#160).
  --watch-page=FILE    With --eta-probe: a browser signed in as a temporary manager reads the progress page every
                       5 s while the run is played (header and run rows, as its poll left them, every sixth time
                       freshly loaded) into FILE; each reading is checked against the snapshots (#141 DoD 2).
  --pairs=FILE         With several strategies: every played step from the step export, with its draw (seed, common
                       random number, true probabilities, category), as CSV in FILE. Fails when a twin answers the
                       same item differently in two runs, an answer was recorded otherwise than drawn, no item was
                       shared, or a run is not under RNG schema 2 (#155).
  --expect-recovered=N With CATQUIZLAB_FAULTS for the worker: at least N submissions reconciled, every question answered
                       exactly once (#134).
  --expect-recovered-reasons=CODE:N,…  And exactly N reconciliations with each of these reason codes, e.g.
                       submit_cdp_timeout:2 — one per injected slow response, not a loop of timeouts (#110).
  --keep               Leave the experiment behind for inspection.
  --summary=FILE       What the test came to, as JSON in FILE, however it ends: the experiment, sittings queued,
                       collected and failed at the time limit and after it, every sitting not finished at the limit with
                       its answers so far and how long since its last answer, the validity of every collected sitting,
                       and the verdict (pass, deadline, tooshort, noobservations, or error). For the load and trace
                       matrices (#110, #164 re-audit 19.11.2026), which classify a step from it.
  --stall=300          With --summary: a sitting with no answer for this many seconds at the time limit is stalled.
  --pool=N             Items in the pool, over three subscales (default: twice the answer floor); for the
                       engine's cost per pool size (#136 DoD 3).
  --budget=G1,G2,S1,S2,SE1,SE2
                       The run's budgets instead of the smoke defaults (#123):
                       global min/max items, subscale min/max items, SE min/max.
                       The answer floor is then the global minimum.
EOT);
    exit(0);
}

$GLOBALS['USER'] = get_admin();

// One at a time. Two of these running together defer each other's queues and
// share the worker slots, so each waits out its timeout on the other's work and
// reports a failure about the test rather than about the plugin. That happened,
// and it cost a strategy an undeserved FAIL.
// Moodle's own lock, not flock: a file lock is inherited by every process this
// script starts, and the worker it launches kept it for as long as it lived —
// so the second strategy found the first one "still running" after it had
// passed and printed its result.
$lockfactory = \core\lock\lock_config::get_lock_factory('local_catquizlab');
$lock = $lockfactory->get_lock('smoke', 5);
if (!$lock) {
    cli_error('Another smoke test is running. They cannot share an installation.');
}
register_shutdown_function(static function () use ($lock): void {
    $lock->release();
});

$strategy = (string) $options['strategy'];
$persons = max(1, (int) $options['persons']);
$deadline = time() + (max(1, (int) $options['minutes']) * MINSECS);
$minanswers = max(2, (int) $options['minanswers']);
// The budgets of an end-to-end contract case (#123), as the provisioning read-back test has them.
$budget = null;
if ((string) $options['budget'] !== '') {
    $parts = array_map('trim', explode(',', (string) $options['budget']));
    if (count($parts) !== 6 || array_filter($parts, static fn($v) => !is_numeric($v)) !== []) {
        cli_error('--budget takes six numbers: global min,max, subscale min,max, SE min,max.');
    }
    $budget = ['global' => ['minitems' => (int) $parts[0], 'maxitems' => (int) $parts[1]],
        'subscale' => ['minitems' => (int) $parts[2], 'maxitems' => (int) $parts[3]],
        'se' => ['min' => (float) $parts[4], 'max' => (float) $parts[5]]];
    $minanswers = max(1, (int) $parts[0]);
}

/**
 * Say what is happening, with the time it took.
 *
 * @param string $line What happened.
 * @param float $since When the step started.
 * @return void
 */
function step(string $line, float $since = 0.0): void {
    $suffix = $since > 0 ? sprintf(' (%.1fs)', microtime(true) - $since) : '';
    cli_writeln('  ' . $line . $suffix);
}

// The strategies the installed engine can actually play, one per line. The
// runner used to carry a fixed list with "balanced" in it, which this fork of
// the engine has no class for: readiness refused it, correctly, and the job
// failed for a strategy nobody could have run.
if (!empty($options['list-strategies'])) {
    foreach (\local_catquizlab\local\strategy_catalog::keys() as $key) {
        if (\local_catquizlab\local\strategy_catalog::runnable($key)) {
            cli_writeln($key);
        }
    }
    exit(0);
}

cli_heading('CatQuizLab smoke test: ' . $strategy);

// Set the installation up, then ask whether it is ready. This script used to
// only ask, and relied on whatever the surrounding job had arranged — which
// stopped being enough when readiness grew to cover the worker switch, the PHP
// binary and the browser cache. A test script that cannot prepare its own
// installation reports the job's setup, not the plugin.
$base = getenv('CATLAB_BASE_URL') ?: ($CFG->wwwroot ?: 'http://127.0.0.1:8000');
set_config('worker_base_url', rtrim($base, '/'), 'local_catquizlab');

$node = getenv('CATLAB_NODE') ?: trim((string) @exec('command -v node 2>/dev/null'));
if ($node !== '' && is_executable($node)) {
    set_config('worker_node_path', $node, 'local_catquizlab');
}

$setup = \local_catquizlab\local\setup_wizard::run(true);
step('Setup: ' . ($setup['changed'] === [] ? 'nothing to change' : implode(', ', $setup['changed'])));

// The worker's packages and its browser, installed into the dataroot where the
// worker looks for them.
$runtimeready = \local_catquizlab\local\system_health::worker_modules_installed()
    && \local_catquizlab\local\worker_runtime::browser_present();

if (!$runtimeready) {
    $started = microtime(true);
    $runtime = \local_catquizlab\local\worker_runtime::ensure();
    step('Runtime: ' . ($runtime['ok'] ? implode(', ', $runtime['changed']) ?: 'already there'
        : 'FAILED — ' . implode(' | ', array_slice((array) ($runtime['log'] ?? []), 0, 2))), $started);
}

// Cron, really run rather than asserted. Readiness asks whether cron runs at
// all, and on a fresh CI installation it never has — the job drives everything
// itself, so the honest way to satisfy that is to run one pass, not to write
// the timestamp it looks at.
if (!\local_catquizlab\local\setup_wizard::state()['ready']) {
    $started = microtime(true);
    ob_start();
    \core\cron::run_main_process(0);
    ob_end_clean();
    step('Cron: one pass run.', $started);
}

// Can this installation run anything at all. Asking after building an
// experiment is asking too late.
$wizard = \local_catquizlab\local\setup_wizard::state();
if (empty($wizard['ready'])) {
    foreach ($wizard['stages'] as $stage) {
        foreach ($stage['steps'] as $check) {
            if (empty($check['ok'])) {
                cli_writeln('  [ ] ' . $check['label'] . ' — ' . $check['detail']);
            }
        }
    }
    cli_error('Setup is incomplete; nothing can run.');
}
step('Setup complete.');

// 2. Define.
$started = microtime(true);
$definition = experiment_definition::example_baseline();
$definition['name'] = 'Smoke ' . $strategy . ' ' . date('His');
$definition['replications'] = 1;
$definition['persons']['count'] = $persons;
$definition['strategy'] = $strategy;
// Several runs, one per strategy, sharing the workers (#160 DoD04).
$strategies = array_values(array_filter(array_map('trim', explode(',', (string) $options['strategies']))));
if ($strategies !== []) {
    $definition['strategy'] = $strategies[0];
    $definition['sweep']['factors']['strategy'] = $strategies;
    $definition['name'] = 'Smoke ' . implode('+', $strategies) . ' ' . date('His');
}
$probe = (string) $options['eta-probe'] !== '' ? new smoke_eta_probe((string) $options['eta-probe']) : null;
// Enough items to answer $minanswers of them without running the pool dry:
// an attempt that stops because there is nothing left to ask has not been
// stopped by the CAT, and reading that as a short test is reading the wrong
// thing.
$subscales = 3;
// The selection does not take items in order, it takes the one that suits the
// current estimate, so a pool sized exactly to the answer count runs out of
// suitable items well before it runs out of items.
$peritem = max(12, (int) ceil(($minanswers * 2) / $subscales));
if ((int) $options['pool'] > 0) {
    $peritem = max(1, (int) ceil((int) $options['pool'] / $subscales));
    step('Pool: ' . ($peritem * $subscales) . ' items over ' . $subscales . ' subscales (--pool).');
}
$definition['pool']['scales'] = [
    'categories'       => 1,
    'subcategories'    => $subscales,
    'itemspersubscale' => $peritem,
];

$definition['budgets']['global'] = [
    'minitems' => $minanswers,
    'maxitems' => $minanswers + 5,
];

// The allsubs strategy visits every subscale, so each needs a floor high
// enough that the strategy is not finished before it has been anywhere: with
// a per-subscale minimum of one it satisfies itself in three questions and
// stops, which looks like a premature abort and is really the budget being met.
$definition['budgets']['subscale'] = [
    'minitems' => max(2, (int) ceil($minanswers / $subscales)),
    'maxitems' => $minanswers,
];

// The engine's own default precision. An earlier version of this script forced
// it down to 0.05 while chasing a question count that turned out to be a
// counting mistake here; there is no reason for a smoke test to ask for
// precision no real experiment would.
$definition['budgets']['se'] = ['min' => 0.35, 'max' => 1.0];
if ($budget !== null) {
    $definition['budgets'] = $budget + $definition['budgets'];
    step('Budgets of the case: ' . json_encode($budget));
}

// Issue #104's case: a shared maximum of 35 for a sweep over the classical
// test and two adaptive strategies, with 80 and 40 given to the latter as their
// own. The classical test must reach the engine unlimited, the others at 80
// and 40 — checked against the activities actually created.
$case104 = ['classic' => -1, 'allsubs' => 80, 'relsubs' => 40];
if ($options['case'] === '104') {
    $definition['strategy'] = 'classic';
    $definition['sweep']['factors']['strategy'] = array_keys($case104);
    $definition['budgets']['global'] = ['minitems' => 10, 'maxitems' => 35];
    $definition['budgetsbystrategy'] = [
        'allsubs' => ['global' => ['maxitems' => 80]],
        'relsubs' => ['global' => ['maxitems' => 40]],
    ];
}

// Issue #102's case: a range other than the ±3 that used to be hard-wired,
// with a truncated normal distribution. The root scale the engine holds must
// have exactly this range — read back, not assumed.
if ($options['case'] === '102') {
    $definition['persons']['distribution'] = 'truncated_normal';
    $definition['persons']['abilitymean'] = 0.0;
    $definition['persons']['abilitysd'] = 1.5;
    $definition['persons']['abilityrange'] = ['min' => -4.0, 'max' => 4.0];
}

$experimentid = (int) experiment_service::save($definition)['id'];
// From here on, the load guard's decisions belong to this test (#110: a step's verdict names the trips in it).
$experimentsince = time();
step('Defined experiment ' . $experimentid . '.', $started);
smoke_summary(['experimentid' => $experimentid, 'strategy' => $strategy]);

// 3. Prepare, in one action, as a person would.
$started = microtime(true);
$prepared = experiment_runner::prepare($experimentid);
foreach ($prepared['blockers'] as $blocker) {
    cli_writeln('  blocked: run ' . ($blocker['runid'] ?? 0)
        . ' at ' . ($blocker['stage'] ?? '') . ': ' . ($blocker['reason'] ?? ''));
}
if (!$prepared['ok']) {
    cli_error('Preparation failed.');
}
step('Prepared ' . $prepared['prepared'] . '/' . $prepared['total'] . ' runs.', $started);

// What the engine actually holds for each run's test, read back and compared
// with what the definition asks for (#101, #104). A parameter this strategy
// does not use must have reached the engine as its neutral stand-in, and one it
// does use as the definition's value — anything else is a run that tests
// something other than what its manifest says.
$started = microtime(true);
foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC', 'id') as $run) {
    $check = \local_catquizlab\local\provisioning_check::compare((int) $run->id);
    if ($options['case'] === '104') {
        $runstrategy = (string) (\local_catquizlab\local\run_registry::definition_for(
            $DB->get_record('local_catquizlab_run', ['id' => $run->id])
        )['strategy'] ?? '');
        $actualmax = $check['checked']['maxquestions']['actual'] ?? null;
        cli_writeln(sprintf(
            '  case 104: %-8s engine maxquestions %s (expected %d)',
            $runstrategy,
            json_encode($actualmax),
            $case104[$runstrategy] ?? 0
        ));
        if ($actualmax !== ($case104[$runstrategy] ?? null)) {
            cli_error('Case 104: ' . $runstrategy . ' reached the engine with maxquestions '
                . json_encode($actualmax) . ', expected ' . ($case104[$runstrategy] ?? '?') . '.');
        }
    }
    foreach ($check['checked'] as $field => $pair) {
        cli_writeln(sprintf('  engine %-24s %s', $field, json_encode($pair['actual'])));
    }
    if ($options['case'] === '102') {
        $scalemin = $check['checked']['scale_min']['actual'] ?? null;
        $scalemax = $check['checked']['scale_max']['actual'] ?? null;
        cli_writeln(sprintf(
            '  case 102: engine root scale [%s, %s] (expected [-4, 4])',
            json_encode($scalemin),
            json_encode($scalemax)
        ));
        if ($scalemin !== -4.0 || $scalemax !== 4.0) {
            cli_error('Case 102: the root scale reached the engine as [' . json_encode($scalemin) . ', '
                . json_encode($scalemax) . '], expected [-4, 4].');
        }
    }
    if (!$check['ok']) {
        foreach ($check['differences'] as $difference) {
            cli_writeln('  DIFFERENCE: ' . $difference);
        }
        cli_error('The engine holds different settings than the run defines.');
    }
}
step('Engine settings read back and match the definition.', $started);

if (!empty($options['provisioning-only'])) {
    \local_catquizlab\local\purger::delete_experiment($experimentid, true, true);
    cli_writeln('');
    cli_writeln('PASS (provisioning only): engine settings match for ' . $strategy . '.');
    exit(0);
}

$queued = $DB->count_records_select(
    'local_catquizlab_attempt',
    'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?)',
    [$experimentid]
);
if ($queued === 0) {
    cli_error('No attempts were queued.');
}
step($queued . ' attempts queued.');
smoke_summary(['queued' => $queued]);

// Every run exactly its planned twins, each once, each with one sitting (#116):
// a setup done twice at once used to store every twin twice and give each copy
// a sitting — ten sittings for five people, and nothing said so.
$populations = [];
foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
    $planned = \local_catquizlab\local\person_generator::planned_count(
        \local_catquizlab\local\run_registry::definition_for($run)
    );
    $check = \local_catquizlab\local\person_integrity::check_run((int) $run->id, $planned);
    $sittings = $DB->count_records('local_catquizlab_attempt', ['runid' => $run->id]);
    if (!$check['ok'] || $sittings !== $planned) {
        cli_error(sprintf(
            'Run %d: planned %d people, has %d rows for %d twins and %d sittings%s.',
            $run->id,
            $planned,
            $check['rows'],
            $check['distinct'],
            $sittings,
            $check['duplicates'] === [] ? '' : ' — twins more than once: ' . count($check['duplicates'])
        ));
    }
    $populations[] = $planned;
}
step(sprintf(
    'Population: %d runs, each its %s planned twins once, each with one sitting.',
    count($populations),
    implode('/', array_unique($populations))
));

// 4. Run it: a real browser, against real questions.
//
// This test's experiment takes the front of the execution line (#163). A worker claims only the sittings of the
// experiment holding the line, so on a busy installation this test would otherwise wait behind everything queued
// before it. Taken openly — the line says by whom and until when — and given back when the test ends, however it
// ends; whatever held the line before waits at its front and goes on afterwards. Other experiments' sittings are not
// touched: until 0.7.64 they were deferred for the duration instead and given their times back afterwards (#120).
// A deferral a killed older test left behind is given back first.
\local_catquizlab\local\queue_deferral::restore();
$front = \local_catquizlab\local\execution_queue::take_front(
    $experimentid,
    'cli/smoke.php',
    (max(1, (int) $options['minutes']) + 15) * MINSECS
);
if (empty($front['ok'])) {
    cli_error('The execution line could not be taken for this test (' . $front['reason'] . ').');
}
step('This experiment holds the execution line for the test'
    . ($front['preempted'] !== [] ? ' (experiment ' . implode(', ', $front['preempted']) . ' waits at its front meanwhile)' : '')
    . '.');
register_shutdown_function(static function () use ($experimentid): void {
    \local_catquizlab\local\execution_queue::release_front($experimentid, 'cli/smoke.php ended');
});

// For a load test, several workers at once: the race of #100 showed under
// load, not in a single browser.
if ((int) $options['concurrency'] > 0) {
    // For this test only: the installation's own setting comes back when it ends, however it ends.
    $concurrencybefore = get_config('local_catquizlab', 'worker_concurrency');
    register_shutdown_function(static function () use ($concurrencybefore): void {
        set_config('worker_concurrency', $concurrencybefore === false ? null : $concurrencybefore, 'local_catquizlab');
    });
    set_config('worker_concurrency', (int) $options['concurrency'], 'local_catquizlab');
}

$started = microtime(true);
worker_registry::reap();
$launch = worker_launcher::launch_pool(worker_launcher::config_from_settings());

if ((int) $launch['launched'] > 0) {
    step('Worker started and reported.', $started);
} else if (worker_registry::summary()['live'] > 0) {
    // The pipeline starts workers by itself, so on a live installation the
    // slots are often already taken — by processes that will pick these
    // attempts up exactly as a new one would. Refusing to continue here would
    // fail the test for the system working.
    step('Using the ' . worker_registry::summary()['live'] . ' worker(s) already running.', $started);
} else if (smoke_work_taken($experimentid) > 0) {
    // The work was already taken up — by a worker the preparation or the
    // pipeline started before this one. This worker found the queue empty and
    // left, which is correct, and the check above only asks who is running
    // now. Failing here reported a test as broken whose sittings were being
    // played or already collected.
    step('Work already taken up by an earlier worker.', $started);
} else {
    cli_writeln('  worker output: ' . substr((string) $launch['output'], -400));
    cli_error('No worker started and none running (' . $launch['reason'] . ').');
}
// The page as a signed-in person reads it while the run is played (#141 DoD 2).
$watcher = null;
if ($probe !== null && (string) $options['watch-page'] !== '') {
    $watcher = smoke_watch_start($experimentid, (string) $options['watch-page']);
    step('A browser reads the progress page alongside, as user ' . $watcher['username'] . '.');
}

// 5. Wait for it to actually play them.
$started = microtime(true);
$collected = 0;
$failed = 0;
$kicked = time();
while (time() < $deadline) {
    sleep(5);
    $probe?->take($experimentid);
    // What the pipeline tick does every minute (Worker CI on 995b4a7, legacy: the two workers "already running" were
    // the last of an earlier step's, on their way out; nobody took the queued sitting for four minutes): dead workers
    // reaped, free slots filled.
    if (time() - $kicked >= 60) {
        $kicked = time();
        worker_registry::reap();
        worker_launcher::launch_pool(worker_launcher::config_from_settings());
    }

    $collected = $DB->count_records_select(
        'local_catquizlab_attempt',
        'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?) AND status = ?',
        [$experimentid, attempt_scheduler::STATUS_COLLECTED]
    );
    $failed = $DB->count_records_select(
        'local_catquizlab_attempt',
        'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?) AND status = ?',
        [$experimentid, attempt_scheduler::STATUS_FAILED]
    );

    if ($collected + $failed >= $queued) {
        break;
    }
}
step($collected . ' collected, ' . $failed . ' failed.', $started);
// The last snapshot after the end: finished runs must say so, with nothing left (#160 AK08).
$probe?->take($experimentid);
if ($watcher !== null) {
    // One more reading of the finished state, then the browser is stopped and its user removed.
    sleep(6);
    smoke_watch_stop($watcher);
}
// What did not finish, each sitting with where it stands (audit 11.11.2026, #110/#134): before 0.7.59 a sitting
// left behind said nothing - in the load matrix one of twenty sat out the time limit in a retry's backoff after its
// worker failed ("DOM.resolveNode timed out") and every other worker had left the empty queue.
$unfinished = $DB->get_records_select(
    'local_catquizlab_attempt',
    'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?) AND status NOT IN (?, ?)',
    [$experimentid, attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_FAILED],
    'id ASC',
    'id, status, tries, nextruntime, leaseowner'
);
foreach ($unfinished as $sitting) {
    $last = $DB->get_records(
        'local_catquizlab_attemptlog',
        ['attemptid' => $sitting->id],
        'id DESC',
        'id, outcome, reasoncode, detail',
        0,
        1
    );
    $last = reset($last);
    cli_writeln(sprintf(
        '  NOT FINISHED: sitting %d, status %d, tries %d, next run %s, lease %s; last history: %s',
        $sitting->id,
        $sitting->status,
        $sitting->tries,
        (int) $sitting->nextruntime > time() ? 'in ' . ((int) $sitting->nextruntime - time()) . ' s' : 'due',
        (string) ($sitting->leaseowner ?? '') ?: '-',
        $last ? $last->outcome . ' ' . ($last->reasoncode ?? '') . ' ' . substr((string) $last->detail, 0, 120) : 'none'
    ));
}
$notfinished = count($unfinished);
// Where each one stood at the limit: its answers so far and how long since the last (#110, re-audit 19.11.2026): a
// sitting still answering is slow, one that stopped answering is stalled — two different findings.
$atlimit = [];
foreach ($unfinished as $sitting) {
    $engine = $DB->get_record_sql(
        'SELECT aa.id, aa.questionsattempted, aa.timemodified
           FROM {local_catquizlab_person} p
           JOIN {adaptivequiz_attempt} aa ON aa.userid = p.moodleuserid
          WHERE p.id = (SELECT personid FROM {local_catquizlab_attempt} WHERE id = ?)
       ORDER BY aa.id DESC',
        [$sitting->id],
        IGNORE_MULTIPLE
    );
    $atlimit[] = ['sitting' => (int) $sitting->id, 'status' => (int) $sitting->status,
        'answered' => $engine ? (int) $engine->questionsattempted : null,
        'idleseconds' => $engine ? time() - (int) $engine->timemodified : null];
    cli_writeln(sprintf(
        '    at the limit: sitting %d, %s answered, last answer %s',
        $sitting->id,
        $engine ? (string) (int) $engine->questionsattempted : 'nothing',
        $engine ? (time() - (int) $engine->timemodified) . ' s ago' : 'never'
    ));
}
$stall = max(1, (int) $options['stall']);
smoke_summary(['atlimit' => ['collected' => $collected, 'failed' => $failed, 'notfinished' => $notfinished,
    'stalled' => count(array_filter($atlimit, static fn(array $a): bool => $a['idleseconds'] === null
        || $a['idleseconds'] >= $stall)), 'sittings' => $atlimit], 'stallseconds' => $stall]);

// Everything above is the state at the time limit; everything below checks the sittings against each other and must
// not check a moving run (gate 12.11.2026): the workers kept playing after the limit, a sitting was collected between
// the reproduction package and the run's own contract, and the two differed for that alone. So this experiment's queued
// sittings are held back, the ones in play are given time to end, and only then is anything compared.
if ($notfinished > 0) {
    $started = microtime(true);
    $held = smoke_hold_own($experimentid, []);
    register_shutdown_function(static function () use (&$held): void {
        smoke_release_own($held);
    });
    $waituntil = time() + 240;
    while (($inplay = smoke_in_play($experimentid)) > 0 && time() < $waituntil) {
        sleep(5);
    }
    // A sitting whose execution failed in the meantime is queued again, with its retry's time: held back as well.
    $held = smoke_hold_own($experimentid, $held);
    $runs = 'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?) AND status = ?';
    $collected = $DB->count_records_select('local_catquizlab_attempt', $runs, [$experimentid, attempt_scheduler::STATUS_COLLECTED]);
    $failed = $DB->count_records_select('local_catquizlab_attempt', $runs, [$experimentid, attempt_scheduler::STATUS_FAILED]);
    step(sprintf(
        'Time limit: %d queued sitting(s) held back for the checks, %s; now %d collected, %d failed.',
        count($held),
        $inplay > 0 ? $inplay . ' still in play after 240 s' : 'the ones in play have ended',
        $collected,
        $failed
    ), $started);
    smoke_summary(['afterlimit' => ['collected' => $collected, 'failed' => $failed, 'held' => count($held),
        'inplay' => $inplay, 'seconds' => (int) round(microtime(true) - $started)]]);
}

// Every execution counts, not only the final state (#100). A navigation failure
// that a retry covered up is still a navigation failure: under load it is the
// one that, ten times over, stops a run.
if (!empty($options['no-navigation-failures'])) {
    [$insql, $inparams] = $DB->get_in_or_equal(
        array_keys($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], '', 'id')) ?: [0],
        SQL_PARAMS_NAMED,
        'run'
    );
    $executions = $DB->get_records_select(
        'local_catquizlab_attemptlog',
        'runid ' . $insql . ' AND outcome = :failed',
        $inparams + ['failed' => \local_catquizlab\local\attempt_history::FAILED],
        'id ASC',
        'id, attemptid, tryno, detail'
    );
    $started = microtime(true);
    $navigation = 0;
    foreach ($executions as $execution) {
        $pattern = '/Execution context was destroyed|did not reach the finish page|No expected state|fetch failed/i';
        if (preg_match($pattern, (string) $execution->detail)) {
            $navigation++;
            cli_writeln('  navigation failure: sitting ' . $execution->attemptid . ' try ' . $execution->tryno
                . ': ' . substr((string) $execution->detail, 0, 200));
        }
    }
    $total = (int) $DB->count_records_select(
        'local_catquizlab_attemptlog',
        'runid ' . $insql . ' AND outcome = :started',
        $inparams + ['started' => \local_catquizlab\local\attempt_history::STARTED]
    );
    step($total . ' executions, ' . count($executions) . ' failed, ' . $navigation . ' of them navigation or transport.', $started);
    // Every failed execution by its reason (#107), not only the navigation
    // ones: a load test that passes on navigation can still hide failures of
    // another kind, retried until they succeeded.
    $reasons = $DB->get_records_sql(
        "SELECT COALESCE(reasoncode, 'none') AS reason, COUNT(1) AS n
           FROM {local_catquizlab_attemptlog}
          WHERE runid $insql AND outcome = :failed
       GROUP BY COALESCE(reasoncode, 'none')
       ORDER BY COUNT(1) DESC",
        $inparams + ['failed' => \local_catquizlab\local\attempt_history::FAILED]
    );
    foreach ($reasons as $reason) {
        cli_writeln(sprintf('    %-28s %d', $reason->reason, $reason->n));
    }
    // For the step's verdict (#110, CI d23b39c0): every failed execution by cause, each marked as a capacity cause
    // (the server or the network overloaded, \local_catquizlab\local\circuit_breaker::CAPACITY_CAUSES) or not, and
    // what the load guard did meanwhile — the one place that knows both lists.
    $byreason = [];
    foreach ($reasons as $reason) {
        $byreason[(string) $reason->reason] = ['n' => (int) $reason->n,
            'capacity' => in_array((string) $reason->reason, \local_catquizlab\local\circuit_breaker::CAPACITY_CAUSES, true)];
    }
    $trips = $DB->get_records_select(
        'local_catquizlab_runlog',
        'event = :event AND timecreated >= :since',
        ['event' => \local_catquizlab\local\run_log::LOAD_THROTTLED, 'since' => $experimentsince],
        'id ASC',
        'id, timecreated, detail'
    );
    $drained = $DB->get_records_select(
        'local_catquizlab_runlog',
        'event = :event AND timecreated >= :since',
        ['event' => \local_catquizlab\local\run_log::WORKERS_DRAINING, 'since' => $experimentsince],
        'id ASC',
        'id, detail'
    );
    $askedtostop = 0;
    $drainlimit = null;
    foreach ($drained as $row) {
        $said = json_decode((string) $row->detail, true) ?: [];
        $askedtostop += (int) ($said['workers'] ?? 0);
        $drainlimit = (int) ($said['limit'] ?? 0);
    }
    $guard = ['trips' => count($trips), 'first' => $trips ? (int) reset($trips)->timecreated : null,
        'drained' => $askedtostop, 'limit' => $drainlimit];
    // And each of them, as it was recorded (up to 200): a cause counted is not a cause read (CI d23b39c0 had nine
    // state mismatches and one unknown failure, and no text of any of them in the artefacts).
    $failures = [];
    $failedrows = $DB->get_records_select(
        'local_catquizlab_attemptlog',
        'runid ' . $insql . ' AND outcome = :failed',
        $inparams + ['failed' => \local_catquizlab\local\attempt_history::FAILED],
        'id ASC',
        'id, attemptid, tryno, reasoncode, timecreated, detail',
        0,
        200
    );
    foreach ($failedrows as $row) {
        $failures[] = ['sitting' => (int) $row->attemptid, 'try' => (int) $row->tryno, 'reason' => $row->reasoncode,
            'time' => (int) $row->timecreated, 'detail' => \core_text::substr((string) $row->detail, 0, 600)];
    }
    smoke_summary(['executions' => ['total' => $total, 'failed' => count($executions), 'navigation' => $navigation,
        'reasons' => (object) $byreason, 'failures' => $failures], 'loadguard' => $guard]);
    if ($guard['trips'] > 0) {
        cli_writeln(sprintf('  Load guard: tripped %d time(s), first at %s; %d worker(s) asked to finish their sitting and '
            . 'stop, limit %s.', $guard['trips'], gmdate('H:i:s', $guard['first']), $guard['drained'], $guard['limit'] ?? '?'));
    }
    // Still in play after the limit and the wait: the run moves, and a comparison of sittings while it moves compares
    // two states (gate 12.11.2026). Said and stopped here, with what is known: nothing compared.
    if (!empty($inplay)) {
        // How the engine judged the sittings collected so far — read, not compared (CI 20.11.2026: without it the
        // step's verdict said "no valid sitting", having counted none).
        $observations = new \local_catquizlab\local\results_query(['experimentid' => $experimentid, 'validity' => 'all']);
        foreach ($observations->each_observation() as $observation) {
            $state = (string) $observation['validitystate'];
            $GLOBALS['smokesummary']['validity'][$state] = ($GLOBALS['smokesummary']['validity'][$state] ?? 0) + 1;
        }
        smoke_summary(['verdict' => 'overload']);
        cli_error($inplay . ' sitting(s) still in play after the limit and the wait: the run is still moving, no '
            . 'sitting compared' . ($navigation > 0 ? '; ' . $navigation . ' execution(s) failed on navigation or transport' : '')
            . '.');
    }
    $samples = $DB->get_records_select(
        'local_catquizlab_attemptlog',
        'runid ' . $insql . ' AND outcome = :failed',
        $inparams + ['failed' => \local_catquizlab\local\attempt_history::FAILED],
        'id ASC',
        'id, reasoncode, detail',
        0,
        3
    );
    foreach ($samples as $sample) {
        cli_writeln('    e.g. ' . ($sample->reasoncode ?? 'none') . ': ' . substr((string) $sample->detail, 0, 220));
    }
    // Said at the end, after every check of the sittings that were collected (#110, CI d23b39c0: the check stopped
    // the test before any of them, so the step's verdict had nothing to go on but "error").
    $navigationfailed = $navigation;
}

// Issue #134: submissions that ended uncertainly were reconciled with the
// server and continued — and no question was answered twice, none lost. Run
// with CATQUIZLAB_FAULTS set for the worker, e.g. lostresponse@5,lostrequest@9.
$expectrecovered = (int) $options['expect-recovered'];
if ($expectrecovered > 0) {
    [$insql, $inparams] = $DB->get_in_or_equal(
        array_keys($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], '', 'id')) ?: [0],
        SQL_PARAMS_NAMED,
        'rrun'
    );
    $recovered = $DB->get_records_select(
        'local_catquizlab_attemptlog',
        'runid ' . $insql . ' AND outcome = :recovered',
        $inparams + ['recovered' => \local_catquizlab\local\attempt_history::RECOVERED],
        'id ASC',
        'id, attemptid, reasoncode, detail'
    );
    foreach ($recovered as $row) {
        cli_writeln('  recovered: sitting ' . $row->attemptid . ' ' . $row->reasoncode . ': ' . $row->detail);
    }
    if (count($recovered) < $expectrecovered) {
        cli_error(count($recovered) . ' reconciled submission(s) recorded, ' . $expectrecovered . ' expected.');
    }
    // Exactly as many of each kind as were injected (#110): a slow response is one reconciliation, never a loop of
    // timeouts and retries.
    $byreason = array_count_values(array_map(static fn($row): string => (string) $row->reasoncode, $recovered));
    foreach (array_filter(explode(',', (string) $options['expect-recovered-reasons'])) as $expected) {
        [$code, $n] = array_pad(explode(':', trim($expected), 2), 2, '0');
        $got = $byreason[$code] ?? 0;
        step('reconciled with ' . $code . ': ' . $got . ' (expected ' . (int) $n . ')');
        if ($got !== (int) $n) {
            cli_error($got . ' reconciliation(s) with ' . $code . ', exactly ' . (int) $n . ' expected.');
        }
    }
    // Every question of every collected sitting answered exactly once.
    $sittings = $DB->get_records_select(
        'local_catquizlab_attempt',
        'runid ' . $insql . ' AND engineattemptid > 0',
        $inparams,
        '',
        'id, engineattemptid'
    );
    foreach ($sittings as $sitting) {
        // The same check the unit tests hold the recovery to (sitting_integrity, #133).
        $check = \local_catquizlab\local\sitting_integrity::check((int) $sitting->engineattemptid);
        step('sitting ' . $sitting->id . ': ' . $check['answered'] . ' answered, ' . $check['presented'] . ' presented, '
            . $check['distinct'] . ' distinct questions, answer steps per question '
            . implode('/', array_unique($check['steps'])));
        if (!$check['ok']) {
            cli_error('Sitting ' . $sitting->id . ': a question presented or answered twice, or an answer lost ('
                . implode(', ', $check['problems']) . ').');
        }
    }
}

if ($failed > 0) {
    $errors = $DB->get_records_select(
        'local_catquizlab_attempt',
        'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?) AND status = ?',
        [$experimentid, attempt_scheduler::STATUS_FAILED],
        'id ASC',
        'id, lasterror',
        0,
        3
    );
    foreach ($errors as $error) {
        cli_writeln('  attempt ' . $error->id . ': ' . substr((string) $error->lasterror, 0, 500));
    }
}

if ($collected === 0) {
    cli_error('No attempt was played to completion.');
}

// 6. More than one question each, or it was not an adaptive test.
// The per-item trace the worker wrote back. One answer proves nothing about an
// adaptive test: the second question is the first one that depended on how the
// first was answered, which is the mechanism under test.
$traces = $DB->get_records_select(
    'local_catquizlab_attempt',
    'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?) AND status = ?',
    [$experimentid, attempt_scheduler::STATUS_COLLECTED],
    'id ASC',
    'id, engineattemptid, tracejson'
);

$shortest = PHP_INT_MAX;
$stopreasons = [];
$short = [];
foreach ($traces as $trace) {
    $decoded = json_decode((string) $trace->tracejson, true);

    // The trace's own count, not the number of fields in it. Counting the keys
    // gave a stable 13 for every strategy and every budget — which was the
    // number of things the trace records about an attempt, not the number of
    // questions the attempt answered. It answered twenty.
    $answered = (int) ($decoded['steps'] ?? ($decoded['nitems'] ?? 0));
    $shortest = min($shortest, $answered);
    if ($answered < $minanswers) {
        $short[(int) $trace->id] = ['answered' => $answered, 'stopreason' => (string) ($decoded['stopreason'] ?? ''),
            'engineattemptid' => (int) ($trace->engineattemptid ?? 0)];
    }

    if (!empty($decoded['stopreason'])) {
        $stopreasons[(string) $decoded['stopreason']] = true;
    }
}
if ($traces === []) {
    cli_error('No collected attempt has a trace.');
}
// Too short fails the test too — after the contract and the ends below are checked and printed (#123): why a sitting
// ended early (the pool, a rule, the engine) is what the case is for.
$tooshort = $shortest < $minanswers;
step(($tooshort ? 'TOO SHORT: the shortest attempt answered ' : 'Every collected attempt answered at least ') . $shortest
    . ' questions (needed ' . $minanswers . '); stopped because: '
    . (implode(', ', array_keys($stopreasons)) ?: 'unrecorded') . '.');
// Each sitting that ended short, with the engine's own end code beside the trace's (audit 11.11.2026, #110): the cause
// per sitting, not one line for all of them.
if ($short !== []) {
    $enginestatus = [];
    $engineids = array_filter(array_column($short, 'engineattemptid'));
    if ($engineids !== [] && $DB->get_manager()->table_exists('local_catquiz_attempts')) {
        [$inengine, $engineparams] = $DB->get_in_or_equal(array_values($engineids), SQL_PARAMS_NAMED);
        $enginestatus = $DB->get_records_select_menu(
            'local_catquiz_attempts',
            "component IN ('adaptivequiz', 'mod_adaptivequiz') AND attemptid " . $inengine,
            $engineparams,
            '',
            'attemptid, status'
        );
    }
    foreach ($short as $id => $entry) {
        $status = $enginestatus[$entry['engineattemptid']] ?? null;
        cli_writeln(sprintf(
            '  short: sitting %d answered %d; trace stop "%s"; engine attempt %d status %s%s',
            $id,
            $entry['answered'],
            $entry['stopreason'],
            $entry['engineattemptid'],
            $status === null ? 'unknown' : (string) $status,
            $status !== null && (int) $status === 10 ? ' (the questions ran out before the minimum: the engine\'s end)' : ''
        ));
    }
}

// 7. Results: numbers, not just rows.
$started = microtime(true);
foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid]) as $run) {
    \local_catquizlab\local\result_aggregator::aggregate((int) $run->id);
}

$results = $DB->count_records_select(
    'local_catquizlab_result',
    'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?)',
    [$experimentid]
);
if ($results === 0) {
    cli_error('Attempts were collected but produced no results.');
}
step($results . ' result rows.', $started);

// The numbers themselves. Rows existing is not the same as rows meaning
// something: an aggregation that wrote zeros would pass a row count and fail
// anybody trying to use the result.
$metrics = $DB->get_records_select(
    'local_catquizlab_result',
    'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?)',
    [$experimentid],
    'metric ASC',
    'id, metric, scope, value'
);

$named = [];
foreach ($metrics as $metric) {
    $named[$metric->metric] = (float) $metric->value;
}

// Recovery of the true ability is what this whole apparatus exists to measure.
// Without it, everything above it ran and produced nothing worth having.
$wanted = ['n', 'rmse', 'bias'];
$missing = [];
foreach ($wanted as $key) {
    if (!array_key_exists($key, $named)) {
        $missing[] = $key;
    }
}

foreach (array_slice($named, 0, 8, true) as $key => $value) {
    cli_writeln(sprintf('  %-16s %.4f', $key, $value));
}

if ($missing !== []) {
    cli_error('Results are missing: ' . implode(', ', $missing));
}

// No valid observation fails the test — after the contract and the ends below are checked and printed (#123): a case
// whose sittings all ended invalid says why, sitting by sitting, instead of stopping before it.
$noobservations = (int) ($named['n'] ?? 0) < 1;

$withestimate = (int) ($named['n'] ?? 0);

// Issue #123: every parameter from the cell to the sittings, and every limit a
// sitting broke. Drift or a replaced value is a defect of the chain; a limit
// overstepped is a defect of the engine; a minimum the pool could not supply
// is a design violation, reported and not failed.
$started = microtime(true);
$defects = [];
foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC', 'id') as $run) {
    $contract = \local_catquizlab\local\run_contract::chain((int) $run->id);
    foreach ($contract['rows'] as $row) {
        if (
            in_array($row['status'], [\local_catquizlab\local\run_contract::DRIFT,
                \local_catquizlab\local\run_contract::REPLACED], true)
        ) {
            $defects[] = sprintf(
                'run %d %s: %s (requested %s, effective %s, engine %s)',
                $run->id,
                $row['param'],
                $row['status'],
                json_encode($row['requested']),
                json_encode($row['effective']),
                json_encode($row['provisioned'])
            );
        }
    }
    foreach ($contract['violations'] as $rule => $count) {
        $kind = \local_catquizlab\local\run_contract::RULES[$rule];
        cli_writeln(sprintf('  contract run %d: %s × %d (%s)', $run->id, $rule, $count, $kind));
        if ($kind === 'defect') {
            $defects[] = sprintf('run %d: %s × %d', $run->id, $rule, $count);
        }
    }
}
step('Parameter chain checked: ' . ($defects === [] ? 'no defect.' : count($defects) . ' defect(s).'), $started);
if ($defects !== []) {
    foreach ($defects as $defect) {
        cli_writeln('  DEFECT: ' . $defect);
    }
    cli_error('The run did not keep to its contract (#123).');
}

// Issue #123: the same end and the same length wherever a sitting is shown — the engine's own record, the analysis
// (attempt detail and every tab), the export of sittings, the reproduction package — sitting by sitting.
$started = microtime(true);
$disagreements = smoke_ends_agree($experimentid);
step('Ends compared sitting by sitting (engine, analysis, export, package): '
    . ($disagreements === [] ? 'all agree.' : count($disagreements) . ' disagreement(s).'), $started);
if ($disagreements !== []) {
    foreach ($disagreements as $line) {
        cli_writeln('  DISAGREES: ' . $line);
    }
    cli_error('A sitting ends differently in different places (#123).');
}

// Every place that shows a sitting says the same of it (#136 AK 5, re-audit 19.11.2026), under the dashboard's filter
// and with every sitting: the analysis the tables read, the CSV and the JSON export of the sittings and of the scale
// results, and the plots as drawn — validity, SE, TI@n, the engine's end, the limits broken, the engine's count, the
// items given per scale (the actual scale exposure, from the steps) — against this engine, whichever set is installed.
$started = microtime(true);
$outputs = smoke_outputs_agree($experimentid);
step('Outputs compared (analysis and tables, CSV, JSON, plots; filters valid and all): '
    . ($outputs['problems'] === [] ? 'all agree, ' . $outputs['facts'] . '.' : count($outputs['problems'])
    . ' disagreement(s).'), $started);
smoke_summary(['outputs' => $outputs]);
if ($outputs['problems'] !== []) {
    foreach (array_slice($outputs['problems'], 0, 40) as $line) {
        cli_writeln('  DISAGREES: ' . $line);
    }
    cli_error('A sitting is shown differently in different outputs (#136 AK 5).');
}

// The same items in every strategy cell of a replication (#154 DoD4): on this real setup, its runs provisioned in
// parallel by the workers, every pool keyed and every group of runs that must share a pool sharing it to the bit.
$started = microtime(true);
$identity = \local_catquizlab\local\item_identity::compare($experimentid);
foreach ($identity['groups'] as $group) {
    step(
        'Item identity ' . $group['label'] . ': ' . count($group['runs']) . ' run(s), '
        . implode('/', array_unique(array_column($group['runs'], 'items'))) . ' items, '
        . ($group['identical'] ? 'identical, pool ' . substr((string) $group['fingerprint'], 0, 16) : 'NOT identical') . '.',
        $started
    );
}
foreach ($identity['problems'] as $line) {
    cli_writeln('  IDENTITY: ' . $line);
}
if (!$identity['ok'] || $identity['legacy'] !== [] || $identity['groups'] === []) {
    cli_error('The runs of this experiment do not have keyed, identical pools where they must (#154).');
}

// The same twin answers the same item alike in every strategy cell (#155): from the step export, as an analyst reads it.
if ($strategies !== [] && count($strategies) > 1) {
    $started = microtime(true);
    $paired = smoke_paired_answers($experimentid, (string) $options['pairs']);
    step('Paired answers: ' . $paired['steps'] . ' played step(s) in ' . $paired['runs'] . ' runs, '
        . $paired['shared'] . ' item(s) presented to the same twin in two or more runs, '
        . count($paired['disagree']) . ' answered differently, ' . count($paired['unfaithful'])
        . ' recorded otherwise than drawn (RNG schema ' . implode(', ', $paired['schemas']) . ').', $started);
    foreach (array_slice($paired['disagree'], 0, 20) as $line) {
        cli_writeln('  DIFFERS: ' . $line);
    }
    foreach (array_slice($paired['unfaithful'], 0, 20) as $line) {
        cli_writeln('  NOT AS DRAWN: ' . $line);
    }
    if ($paired['schemas'] !== [2]) {
        cli_error('A run of this experiment was not drawn under RNG schema 2 (#155).');
    }
    if ($paired['shared'] === 0) {
        // Nothing compared is no evidence: the strategies never presented one item to one twin twice.
        cli_error('No item was presented to the same twin in two runs: nothing to compare (#155).');
    }
    if ($paired['disagree'] !== []) {
        cli_error('A twin answered the same item differently in two strategy cells (#155).');
    }
    if ($paired['unfaithful'] !== []) {
        cli_error('A played answer differs from the drawn one (#155).');
    }
}

// Every forecast against the actual end of its run (#160 DoD04/DoD05).
if ($probe !== null) {
    $report = $probe->finish($experimentid);
    foreach ($report['lines'] as $line) {
        cli_writeln('  ' . $line);
    }
    step('ETA probe: ' . $report['snapshots'] . ' snapshots, ' . count($report['violations'])
        . ' contradiction(s), ' . $report['parallel'] . ' with two or more runs in flight.');
    foreach ($report['violations'] as $line) {
        cli_writeln('  CONTRADICTS: ' . $line);
    }
    if ($report['violations'] !== []) {
        cli_error('The progress snapshot contradicted itself (#160).');
    }
    if ($report['runs'] >= 2 && $report['parallel'] === 0) {
        cli_error('No snapshot had two runs in flight at once: the parallel case of #160 was not exercised.');
    }
    if ($watcher !== null) {
        $watched = smoke_watch_check($watcher['file'], $probe->snapshots());
        foreach ($watched['lines'] as $line) {
            cli_writeln('  ' . $line);
        }
        step('Page read alongside: ' . $watched['readings'] . ' readings, ' . count($watched['violations'])
            . ' contradiction(s).');
        foreach ($watched['violations'] as $line) {
            cli_writeln('  CONTRADICTS: ' . $line);
        }
        if ($watched['violations'] !== []) {
            cli_error('The progress page contradicted the server (#141).');
        }
    }
}

if (!empty($navigationfailed)) {
    smoke_summary(['verdict' => 'navigation']);
    cli_error($navigationfailed . ' execution(s) failed on navigation or transport.'
        . ($notfinished > 0 ? ' ' . $notfinished . ' of ' . $queued . ' sittings did not finish in the time limit.' : ''));
}
if (!empty($options['require-all']) && $notfinished > 0) {
    smoke_summary(['verdict' => 'deadline']);
    cli_error($notfinished . ' of ' . $queued . ' sittings did not finish in ' . (int) $options['minutes']
        . ' minutes (listed above).');
}
if ($tooshort) {
    smoke_summary(['verdict' => 'tooshort']);
    cli_error('Attempts answered fewer than ' . $minanswers . ' questions (shortest: ' . $shortest . ').');
}
if ($noobservations) {
    smoke_summary(['verdict' => 'noobservations']);
    cli_error('Results report no observations.');
}

// The line goes on with whatever this test displaced.
\local_catquizlab\local\execution_queue::release_front($experimentid, 'cli/smoke.php done');

if (empty($options['keep'])) {
    \local_catquizlab\local\purger::delete_experiment($experimentid, true, true);
    step('Cleaned up.');
}

smoke_summary(['verdict' => 'pass', 'collected' => $collected, 'withestimate' => $withestimate, 'results' => $results]);
cli_writeln('');
cli_writeln('PASS: ' . $collected . ' attempts played, ' . $withestimate
    . ' with estimates, ' . $results . ' result rows.');
exit(0);

/**
 * Add to what --summary=FILE will say; the file is written when the test ends, however it ends.
 *
 * @param array $fields Fields to set.
 * @return void
 */
function smoke_summary(array $fields): void {
    $GLOBALS['smokesummary'] = $fields + ($GLOBALS['smokesummary'] ?? []);
}

/**
 * Sittings of this experiment already being played or collected.
 *
 * @param int $experimentid The smoke experiment.
 * @return int
 */
function smoke_work_taken(int $experimentid): int {
    global $DB;

    return (int) $DB->count_records_sql(
        'SELECT COUNT(1)
           FROM {local_catquizlab_attempt} a
           JOIN {local_catquizlab_run} r ON r.id = a.runid
          WHERE r.experimentid = :experimentid AND a.status IN (:running, :collected)',
        [
            'experimentid' => $experimentid,
            'running'      => attempt_scheduler::STATUS_RUNNING,
            'collected'    => attempt_scheduler::STATUS_COLLECTED,
        ]
    );
}

/**
 * Every collected sitting as each output shows it, compared (#136 AK 5): the analysis (what the tables show), the CSV
 * and the JSON export of the sittings and of the scale results, and the plots of the overview, the global tab and the
 * subscale tab as rendered — under the filters "valid" (the dashboard's) and "all".
 *
 * Compared: the filtered sample (the same sittings in analysis, CSV and JSON); per sitting validity state and verdicts,
 * SE, TI@n, the engine's end (finished, end code, terminal code), the limits it broke (max_questions_exceeded among
 * them), the engine's productive count against its own record; per scale result the items administered against the
 * steps (the actual scale exposure); the plots' points against the analysis.
 *
 * @param int $experimentid The smoke experiment.
 * @return array{problems: string[], facts: string}
 */
function smoke_outputs_agree(int $experimentid): array {
    global $DB, $PAGE;

    $problems = [];
    $facts = [];
    $same = static fn($a, $b): bool => ($a === null || $a === '') ? ($b === null || $b === '')
        : (is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) < 1e-9 : (string) $a === (string) $b);
    $parsecsv = static function (string $csv): array {
        $lines = array_values(array_filter(explode("\n", $csv), static fn(string $l): bool => $l !== ''));
        $head = str_getcsv(array_shift($lines), ',', '"', '');
        return array_map(static fn(string $l): array => array_combine($head, str_getcsv($l, ',', '"', '')), $lines);
    };
    $engineitems = [];
    if ($DB->get_manager()->table_exists('local_catquiz_attempts')) {
        $engineitems = $DB->get_records_sql_menu(
            "SELECT a.id, e.number_of_testitems_used
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_run} r ON r.id = a.runid
               JOIN {local_catquiz_attempts} e ON e.attemptid = a.engineattemptid
                    AND e.component IN ('adaptivequiz', 'mod_adaptivequiz')
              WHERE r.experimentid = ?",
            [$experimentid]
        );
    }
    $fields = ['validitystate', 'valid', 'uniformvalid', 'enginevalid', 'statisticalvalid', 'se', 'nitems',
        'enginefinished', 'designstopreached', 'endreasoncode', 'contractviolations', 'productiveitems', 'esttheta',
        'truetheta'];
    foreach (['valid', 'all'] as $validity) {
        $filter = ['experimentid' => $experimentid, 'validity' => $validity];
        $query = new \local_catquizlab\local\results_query($filter);
        $analysis = [];
        foreach ($query->each_observation() as $o) {
            $analysis[(int) $o['attemptid']] = $o;
        }
        $export = \local_catquizlab\local\results_export::class;
        $csvrows = $parsecsv($export::to_csv($export::dataset($query, $export::LEVEL_ATTEMPT)));
        $csv = array_combine(array_map(static fn(array $r): int => (int) $r['attemptid'], $csvrows), $csvrows) ?: [];
        $jsonrows = json_decode($export::to_json($query, $export::LEVEL_ATTEMPT), true)['data'] ?? [];
        $json = array_combine(array_map(static fn(array $r): int => (int) $r['attemptid'], $jsonrows), $jsonrows) ?: [];
        $ids = array_keys($analysis);
        sort($ids);
        foreach (['CSV' => $csv, 'JSON' => $json] as $name => $rows) {
            $theirs = array_keys($rows);
            sort($theirs);
            if ($theirs !== $ids) {
                $problems[] = "{$validity}: the analysis holds " . count($ids) . " sitting(s), the {$name} export "
                    . count($theirs) . ' — not the same sample';
            }
        }
        foreach ($analysis as $id => $o) {
            foreach ($fields as $field) {
                $values = ['analysis' => is_bool($o[$field] ?? null) ? (int) $o[$field] : ($o[$field] ?? null),
                    'csv' => $csv[$id][$field] ?? null, 'json' => is_bool($json[$id][$field] ?? null)
                    ? (int) $json[$id][$field] : ($json[$id][$field] ?? null)];
                if (is_array($values['analysis'])) {
                    $values['analysis'] = implode(';', $values['analysis']);
                }
                if (!$same($values['analysis'], $values['csv']) || !$same($values['analysis'], $values['json'])) {
                    $problems[] = "{$validity}: sitting {$id} {$field}: " . json_encode($values);
                }
            }
            foreach (['finaltiatn', 'finalti', 'engineverdict', 'terminalreasoncode'] as $field) {
                if (!$same($csv[$id][$field] ?? null, $json[$id][$field] ?? null)) {
                    $problems[] = "{$validity}: sitting {$id} {$field}: CSV " . json_encode($csv[$id][$field] ?? null)
                        . ', JSON ' . json_encode($json[$id][$field] ?? null);
                }
            }
            $enginecount = isset($engineitems[$id]) && $o['productiveitems'] !== null;
            if ($enginecount && (int) $engineitems[$id] !== (int) $o['productiveitems']) {
                $problems[] = "{$validity}: sitting {$id}: the engine's record counts {$engineitems[$id]} item(s), "
                    . "the analysis {$o['productiveitems']}";
            }
        }
        // Scale results: CSV and JSON alike, and the items given per scale as the steps count them.
        $subscsv = $parsecsv($export::to_csv($export::dataset($query, $export::LEVEL_SUBSCALE)));
        $subsjson = json_decode($export::to_json($query, $export::LEVEL_SUBSCALE), true)['data'] ?? [];
        if (count($subscsv) !== count($subsjson)) {
            $problems[] = "{$validity}: " . count($subscsv) . ' scale result(s) in the CSV, ' . count($subsjson) . ' in the JSON';
        }
        $key = static fn(array $r): string => $r['attemptid'] . ':' . $r['category'] . ':' . $r['subscale'];
        $byjson = [];
        foreach ($subsjson as $r) {
            $byjson[$key($r)] = $r;
        }
        $given = [];
        $givenby = [];
        foreach ($subscsv as $r) {
            foreach (['items', 'localse', 'estdelta', 'scalevalid', 'validitystate'] as $field) {
                if (array_key_exists($field, $r) && !$same($r[$field], $byjson[$key($r)][$field] ?? null)) {
                    $problems[] = "{$validity}: scale result {$key($r)} {$field}: CSV " . json_encode($r[$field])
                        . ', JSON ' . json_encode($byjson[$key($r)][$field] ?? null);
                }
            }
            if ((int) $r['items'] > 0) {
                $given[(int) $r['attemptid']][] = (int) $r['items'];
                $givenby[(int) $r['attemptid']][(string) ($r['subscale'] ?? '?')] = (int) $r['items'];
            }
        }
        $steps = [];
        $steprows = $export::dataset($query, $export::LEVEL_STEP)['rows'];
        foreach ($steprows as $step) {
            if (!empty($step['subscaleid'])) {
                $steps[(int) $step['attemptid']][(int) $step['subscaleid']] =
                    ($steps[(int) $step['attemptid']][(int) $step['subscaleid']] ?? 0) + 1;
            }
        }
        foreach ($steps as $id => $byscale) {
            $fromsteps = array_values($byscale);
            sort($fromsteps);
            $exported = $given[$id] ?? [];
            sort($exported);
            // With every scale result ("all") the two are the same; under "valid" an invalid scale result is not
            // exported, so what is exported is a part of what the steps count.
            $part = $exported;
            foreach ($fromsteps as $n) {
                $at = array_search($n, $part, true);
                if ($at !== false) {
                    unset($part[$at]);
                }
            }
            $differs = $validity === 'all' ? $fromsteps !== $exported : $part !== [];
            if (isset($given[$id]) && $differs) {
                // By scale, and the sitting's executions beside it (CI 20.11.2026, highestsub@50: one sitting of 50,
                // [17,19,29] against [20,20,25], nothing more to read): which scale, and whether it was resumed.
                $history = [];
                $rows = $DB->get_records(
                    'local_catquizlab_attemptlog',
                    ['attemptid' => $id],
                    'id ASC',
                    'id, tryno, outcome, reasoncode'
                );
                foreach ($rows as $row) {
                    $history[] = $row->tryno . ':' . $row->outcome . ($row->reasoncode ? ':' . $row->reasoncode : '');
                }
                $problems[] = "{$validity}: sitting {$id}: items per scale from the steps " . json_encode($fromsteps)
                    . ', in the scale results ' . json_encode($exported) . '; by scale, steps '
                    . json_encode((object) $byscale) . ', results ' . json_encode((object) ($givenby[$id] ?? []))
                    . '; its executions ' . json_encode($history);
            }
        }
        $facts[] = "{$validity}: " . count($ids) . ' sitting(s), ' . count($subscsv) . ' scale result(s)';

        // The plots as drawn, for the dashboard's filter: the points against the analysis.
        if ($validity !== 'valid') {
            continue;
        }
        if (!$PAGE->has_set_url()) {
            $PAGE->set_context(\context_system::instance());
            $PAGE->set_url('/local/catquizlab/results.php');
        }
        $withse = array_filter($analysis, static fn(array $o): bool => $o['se'] !== null);
        $withestimate = array_filter($analysis, static fn(array $o): bool => $o['esttheta'] !== null);
        $expected = [
            'overview' => array_map(static fn(array $o): array => [(float) $o['nitems'], (float) $o['se']], $withse),
            'global' => array_map(static fn(array $o): array => [(float) $o['truetheta'], (float) $o['esttheta']], $withestimate),
        ];
        foreach ($expected as $tab => $points) {
            $html = (new \local_catquizlab\output\results_page($query, $tab, $filter))->render_tab();
            $svg = explode('</svg>', substr($html, (int) strpos($html, '<svg ')))[0];
            preg_match_all('/data-x="([^"]*)" data-y="([^"]*)" data-count="(\d+)"/', $svg, $m, PREG_SET_ORDER);
            $drawn = [];
            foreach ($m as [, $x, $y, $n]) {
                for ($i = 0; $i < (int) $n; $i++) {
                    $drawn[] = [(float) $x, (float) $y];
                }
            }
            $points = array_values($points);
            usort($points, static fn($a, $b) => $a <=> $b);
            usort($drawn, static fn($a, $b) => $a <=> $b);
            $ok = count($points) === count($drawn);
            foreach ($points as $i => $p) {
                $ok = $ok && abs($p[0] - $drawn[$i][0]) < 5e-3 && abs($p[1] - $drawn[$i][1]) < 5e-3;
            }
            if (!$ok) {
                $problems[] = "valid: the first plot of the {$tab} tab draws " . count($drawn) . ' point(s), the analysis has '
                    . count($points) . ' — or they lie elsewhere';
            }
        }
        $facts[] = 'plots of overview and global tab, ' . implode(' and ', array_map('count', $expected)) . ' point(s)';
    }

    return ['problems' => $problems, 'facts' => implode('; ', $facts)];
}

/**
 * Every collected sitting's end and length, compared between the engine and every place that shows them (#123).
 *
 * The engine's code is read here from the engine's own tables (its status, the host's stop code) and the trace's stop
 * text, independently of the analysis; the analysis' observation is what the attempt detail and the tabs show; the
 * export row is what a CSV or JSON holds; the package's contract is what a reproduction package holds.
 *
 * @param int $experimentid The smoke experiment.
 * @return string[] One line per disagreement; empty when all agree.
 */
function smoke_ends_agree(int $experimentid): array {
    global $DB;

    $out = [];
    // The sittings first, and only these compared: one collected while the analysis and the export are read would be
    // missing from whichever was read before it - a difference of timing, not of ends.
    $sittings = $DB->get_records_sql(
        'SELECT a.id, a.engineattemptid, a.tracejson
           FROM {local_catquizlab_attempt} a
           JOIN {local_catquizlab_run} r ON r.id = a.runid
          WHERE r.experimentid = ? AND a.status = ?',
        [$experimentid, attempt_scheduler::STATUS_COLLECTED]
    );
    $query = new \local_catquizlab\local\results_query(['experimentid' => $experimentid, 'validity' => 'all']);
    $observations = [];
    foreach ($query->each_observation() as $observation) {
        $observations[(int) $observation['attemptid']] = $observation;
    }
    $export = [];
    foreach (
        \local_catquizlab\local\results_export::iterate(
            new \local_catquizlab\local\results_query(['experimentid' => $experimentid, 'validity' => 'all']),
            \local_catquizlab\local\results_export::LEVEL_ATTEMPT
        )['rows'] as $row
    ) {
        $export[(int) $row['attemptid']] = $row;
    }
    $hasengine = $DB->get_manager()->table_exists('local_catquiz_attempts');
    $hostcolumns = $DB->get_columns('adaptivequiz_attempt');
    foreach ($sittings as $sitting) {
        $id = (int) $sitting->id;
        $trace = json_decode((string) $sitting->tracejson, true) ?: [];
        $status = $hasengine ? $DB->get_field_select(
            'local_catquiz_attempts',
            'status',
            "component IN ('adaptivequiz', 'mod_adaptivequiz') AND attemptid = ?",
            [(int) $sitting->engineattemptid]
        ) : false;
        $host = isset($hostcolumns['stopcode'])
            ? $DB->get_field('adaptivequiz_attempt', 'stopcode', ['id' => (int) $sitting->engineattemptid]) : false;
        $engine = (string) \local_catquizlab\local\reason_catalog::engine_code(
            (string) ($trace['stopreason'] ?? ''),
            $status === false || $status === null ? null : (int) $status,
            $host === false || $host === null ? null : (string) $host
        );
        $observation = $observations[$id] ?? null;
        $row = $export[$id] ?? null;
        if ($observation === null || $row === null) {
            $out[] = 'sitting ' . $id . ': ' . ($observation === null ? 'not in the analysis' : 'not in the export');
            continue;
        }
        // The analysis classifies the engine's end into the lab's outcomes (reason_catalog::outcome()); where the
        // engine's own code fixes the outcome, it must be that one. Analysis, stored history and export say the same.
        $fixed = ['reachedmaximumquestions' => 'max_items_reached', 'exceededmaxattempttime' => 'timeout',
            'attemptclosedbytimelimit' => 'timeout'];
        $seen = ['analysis' => (string) $observation['endreasoncode'], 'export' => (string) $row['endreasoncode']];
        // The end as the history recorded it at collection, where it did: a reason code of a recovered or failed
        // execution before or after it (submit_connect_failed, worker_timeout …) is about that execution, not the end.
        $collectedcode = $DB->get_field_sql(
            'SELECT reasoncode FROM {local_catquizlab_attemptlog}
              WHERE attemptid = ? AND outcome = ? AND reasoncode IS NOT NULL
           ORDER BY id DESC',
            [$id, \local_catquizlab\local\attempt_history::COLLECTED],
            IGNORE_MULTIPLE
        );
        if ($collectedcode !== false && (string) $collectedcode !== '') {
            $seen['history'] = (string) $collectedcode;
        }
        if (count(array_unique($seen)) > 1 || (isset($fixed[$engine]) && $seen['analysis'] !== $fixed[$engine])) {
            $out[] = 'sitting ' . $id . ' end: ' . json_encode(['engine' => $engine] + $seen);
        }
        $lengths = ['trace' => (int) ($trace['nitems'] ?? count((array) ($trace['items'] ?? []))),
            'analysis' => (int) $observation['nitems'], 'export' => (int) $row['nitems']];
        if (count(array_unique($lengths)) > 1) {
            $out[] = 'sitting ' . $id . ' length: ' . json_encode($lengths);
        }
        $state = (string) $observation['validitystate'];
        $GLOBALS['smokesummary']['validity'][$state] = ($GLOBALS['smokesummary']['validity'][$state] ?? 0) + 1;
        cli_writeln(sprintf(
            '    sitting %d: engine status %s, host %s, stop text "%s" -> %s -> %s; %d items, SE %s, %s',
            $id,
            json_encode($status === false ? null : $status),
            json_encode($host === false ? null : $host),
            substr((string) ($trace['stopreason'] ?? ''), 0, 60),
            $engine ?: '?',
            $seen['analysis'],
            $lengths['trace'],
            $observation['se'] === null ? '-' : sprintf('%.3f', $observation['se']),
            $observation['validitystate']
        ));
    }
    // The package holds the same contract as the run - for the same sittings: where a sitting changed state between
    // reading the package and reading the run, the two are read again, and a run that never held still is said so.
    $differs = [];
    for ($try = 1; $try <= 10; $try++) {
        $before = smoke_sitting_states($experimentid);
        $package = \local_catquizlab\local\reproducibility::package($experimentid, false);
        $differs = [];
        foreach ($package['runs'] as $run) {
            $chain = \local_catquizlab\local\run_contract::chain((int) $run['id']);
            if (json_encode($run['contract']) !== json_encode($chain)) {
                $differs[] = (int) $run['id'];
            }
        }
        if (smoke_sitting_states($experimentid) === $before) {
            foreach ($differs as $runid) {
                $out[] = 'run ' . $runid . ': the package holds another contract than the run';
            }
            return $out;
        }
        sleep(2);
    }
    $out[] = 'the package and the runs could not be compared: sittings changed state during each of 10 readings';

    return $out;
}

/**
 * The state of every sitting of an experiment, to tell whether two readings saw the same run.
 *
 * @param int $experimentid The smoke experiment.
 * @return string A digest of id, status and last change of every sitting.
 */
function smoke_sitting_states(int $experimentid): string {
    global $DB;

    $rows = $DB->get_records_sql(
        'SELECT a.id, a.status, a.timemodified
           FROM {local_catquizlab_attempt} a
           JOIN {local_catquizlab_run} r ON r.id = a.runid
          WHERE r.experimentid = ?
       ORDER BY a.id ASC',
        [$experimentid]
    );
    $digest = '';
    foreach ($rows as $row) {
        // The heartbeat refreshes timemodified of a sitting in play; only a settled sitting's time says it changed.
        $digest .= $row->id . ':' . $row->status . ':'
            . ((int) $row->status === attempt_scheduler::STATUS_RUNNING ? '' : $row->timemodified) . ';';
    }

    return sha1($digest);
}

/**
 * Sittings of an experiment being played or waiting for their trace.
 *
 * @param int $experimentid The smoke experiment.
 * @return int
 */
function smoke_in_play(int $experimentid): int {
    global $DB;

    return $DB->count_records_select(
        'local_catquizlab_attempt',
        'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?) AND status IN (?, ?)',
        [$experimentid, attempt_scheduler::STATUS_RUNNING, attempt_scheduler::STATUS_TRACEPENDING]
    );
}

/**
 * Hold back an experiment's queued sittings, so no worker claims one while the smoke test checks the run.
 *
 * @param int $experimentid The smoke experiment.
 * @param array $held Sittings already held, id => their own nextruntime.
 * @return array Every sitting held, id => its own nextruntime.
 */
function smoke_hold_own(int $experimentid, array $held): array {
    global $DB;

    $until = time() + DAYSECS;
    $queued = $DB->get_records_sql_menu(
        'SELECT id, nextruntime FROM {local_catquizlab_attempt}
          WHERE status = ? AND nextruntime < ?
            AND runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = ?)',
        [attempt_scheduler::STATUS_QUEUED, $until - HOURSECS, $experimentid]
    );
    foreach (array_chunk(array_keys($queued), 500, true) as $chunk) {
        [$in, $params] = $DB->get_in_or_equal($chunk);
        $DB->execute(
            'UPDATE {local_catquizlab_attempt} SET nextruntime = ? WHERE status = ? AND id ' . $in,
            array_merge([$until, attempt_scheduler::STATUS_QUEUED], $params)
        );
    }

    return $held + array_map('intval', $queued);
}

/**
 * Give held sittings their own time back, where they are still queued: with --keep they are played as any other.
 *
 * @param array $held Sittings held, id => their own nextruntime.
 */
function smoke_release_own(array $held): void {
    global $DB;

    foreach ($held as $id => $nextruntime) {
        $DB->set_field_select(
            'local_catquizlab_attempt',
            'nextruntime',
            $nextruntime,
            'id = ? AND status = ?',
            [$id, attempt_scheduler::STATUS_QUEUED]
        );
    }
}

/**
 * The same twin's answers to the same item across the runs of a replication, from the exported steps (#155 DoD3).
 *
 * Runs that differ only in their strategy present items in different orders and numbers, but where two of them
 * present the same canonical item to the same twin, the answer must be the same: under RNG schema 2 it is drawn
 * from replication, twin and item alone. Read from the step export, as an analyst would read it: the questions the
 * engine really played, under their own ids per run, joined by twin and item key. Every played answer is also checked
 * against the draw the export recomputes (seed, common random number, true probabilities): what the engine recorded
 * is what the twin was told to answer, also where a response or a request was lost and recovered.
 *
 * @param int $experimentid The experiment.
 * @param string $file Where to write the pairs as CSV, or '' for nowhere.
 * @return array{shared: int, runs: int, steps: int, disagree: string[], unfaithful: string[], schemas: int[]}
 */
function smoke_paired_answers(int $experimentid, string $file): array {
    $seen = [];
    $schemas = [];
    $runs = [];
    $steps = 0;
    $unfaithful = [];
    $rows = \local_catquizlab\local\results_export::iterate(
        new \local_catquizlab\local\results_query(['experimentid' => $experimentid, 'validity' => 'all']),
        \local_catquizlab\local\results_export::LEVEL_STEP
    )['rows'];
    $columns = ['replication', 'twinid', 'itemkey', 'runid', 'strategy', 'attemptid', 'step', 'questionid', 'score',
        'rngschema', 'rngseed', 'uniform', 'trueability', 'trueprobs', 'responsecategory', 'oraclefraction'];
    $csv = $file !== '' ? fopen($file, 'w') : null;
    if ($csv) {
        fputcsv($csv, $columns);
    }
    foreach ($rows as $row) {
        $schemas[(int) $row['rngschema']] = (int) $row['rngschema'];
        if ((string) $row['itemkey'] === '' || (string) $row['twinid'] === '') {
            continue;
        }
        $steps++;
        $runs[(int) $row['runid']] = true;
        $where = 'run ' . $row['runid'] . ' sitting ' . $row['attemptid'] . ' step ' . $row['step'] . ' ('
            . $row['twinid'] . ' ' . $row['itemkey'] . ')';
        if (
            $row['score'] === null || $row['oraclefraction'] === '' || abs((float) $row['score']
                - (float) $row['oraclefraction']) > 1e-6
        ) {
            $unfaithful[] = $where . ': recorded ' . json_encode($row['score']) . ', drawn '
                . json_encode($row['oraclefraction']);
        }
        $seen[$row['replication'] . '|' . $row['twinid'] . '|' . $row['itemkey']][(int) $row['runid']] =
            json_encode([$row['responsecategory'], $row['score'] === null ? null : round((float) $row['score'], 6)]);
        if ($csv) {
            fputcsv($csv, array_map(static fn(string $c) => $row[$c], $columns));
        }
    }
    if ($csv) {
        fclose($csv);
    }
    $shared = 0;
    $disagree = [];
    foreach ($seen as $key => $byrun) {
        if (count($byrun) < 2) {
            continue;
        }
        $shared++;
        if (count(array_unique($byrun)) > 1) {
            $disagree[] = $key . ': ' . json_encode($byrun);
        }
    }
    ksort($schemas);

    return ['shared' => $shared, 'runs' => count($runs), 'steps' => $steps, 'disagree' => $disagree,
        'unfaithful' => $unfaithful, 'schemas' => array_values($schemas)];
}

/**
 * Start a browser that reads the experiment's progress page as a temporary manager (#141 DoD 2).
 *
 * @param int $experimentid The experiment.
 * @param string $file Where its readings go.
 * @return array{process: resource, userid: int, username: string, file: string}
 */
function smoke_watch_start(int $experimentid, string $file): array {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/user/lib.php');
    $config = worker_launcher::config_from_settings();
    $password = 'Wx9!' . random_string(24);
    $user = create_user_record('catquizlab_watch_' . time() . '_' . strtolower(random_string(4)), $password);
    // A complete profile, or the first page after signing in is the profile form, not the progress page.
    $user->firstname = 'Progress';
    $user->lastname = 'Reader';
    $user->email = $user->username . '@example.invalid';
    $DB->update_record('user', (object) ['id' => $user->id, 'firstname' => $user->firstname,
        'lastname' => $user->lastname, 'email' => $user->email]);
    role_assign(
        (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST),
        (int) $user->id,
        \context_system::instance()
    );
    @unlink($file);
    $environment = getenv();
    foreach (worker_launcher::runtime_environment($config) as $pair) {
        [$name, $value] = explode('=', $pair, 2);
        $environment[$name] = $value;
    }
    // In the environment, not the argument list: argv is readable by anyone on the machine.
    $environment['CATQUIZLAB_WATCH_USER'] = $user->username;
    $environment['CATQUIZLAB_WATCH_PASSWORD'] = $password;
    $command = [$config['node'] !== '' ? $config['node'] : 'node', $CFG->dirroot . '/local/catquizlab/worker/watch_progress.js',
        '--base-url=' . $config['baseurl'], '--experiment=' . $experimentid, '--out=' . $file];
    $log = ['file', $file . '.log', 'a'];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, null, $environment);
    if (!is_resource($process)) {
        cli_error('The page reader could not be started.');
    }

    return ['process' => $process, 'userid' => (int) $user->id, 'username' => $user->username, 'file' => $file];
}

/**
 * Stop the page reader and remove its user.
 *
 * @param array $watcher From smoke_watch_start().
 * @return void
 */
function smoke_watch_stop(array $watcher): void {
    global $DB;

    proc_terminate($watcher['process'], 15);
    for ($i = 0; $i < 40 && proc_get_status($watcher['process'])['running']; $i++) {
        usleep(500000);
    }
    if (proc_get_status($watcher['process'])['running']) {
        proc_terminate($watcher['process'], 9);
    }
    proc_close($watcher['process']);
    $user = $DB->get_record('user', ['id' => $watcher['userid']]);
    if ($user) {
        delete_user($user);
    }
}

/**
 * The page's readings against the snapshots taken in the same minutes (#141 DoD 2).
 *
 * The page shows what its last poll brought, a few seconds old: a reading may be behind the snapshot taken just
 * before it, never behind one taken ten seconds earlier, and never ahead of one taken five seconds later.
 *
 * @param string $file The readings.
 * @param array[] $snapshots The probe's snapshots.
 * @return array{readings: int, violations: string[], lines: string[]}
 */
function smoke_watch_check(string $file, array $snapshots): array {
    $readings = [];
    $errors = [];
    $skipped = 0;
    foreach (is_readable($file) ? file($file) : [] as $line) {
        $reading = json_decode($line, true);
        if (!is_array($reading)) {
            continue;
        }
        if (isset($reading['error'])) {
            $errors[] = (string) $reading['error'];
        } else if (isset($reading['skipped'])) {
            $skipped++;
        } else if ($reading['questions'] !== null) {
            $readings[] = $reading;
        }
    }
    $violations = [];
    if (count($readings) < 3) {
        $violations[] = 'the page was read ' . count($readings) . ' time(s) with a question count, at least 3 needed'
            . ($errors !== [] ? ': ' . implode('; ', $errors) : '');
    }
    // A retry may drop the answers of the try it replaces: a fall counts only where no sitting failed and no
    // execution broke off (its sitting back in the queue, see smoke_eta_probe::take()).
    $failed = false;
    foreach ($snapshots as $s) {
        $failed = $failed || (int) ($s['interrupted'] ?? 0) > 0;
        foreach ($s['runs'] as $r) {
            $failed = $failed || $r['failed'] > 0;
        }
    }
    $previous = null;
    $rows = [];
    foreach ($readings as $r) {
        $at = '@' . $r['at'] . ' (' . $r['source'] . '): ';
        if ($previous !== null && $r['questions'] < $previous['questions'] && !$failed) {
            $violations[] = $at . 'the header\'s question count fell from ' . $previous['questions'] . ' to '
                . $r['questions'];
        }
        foreach ($r['runs'] as $runid => $done) {
            if ($done !== null && isset($rows[$runid]) && $done < $rows[$runid] && !$failed) {
                $violations[] = $at . 'run ' . $runid . ': the row\'s question count fell from ' . $rows[$runid] . ' to '
                    . $done;
            }
            $rows[$runid] = $done ?? ($rows[$runid] ?? null);
        }
        [$before, $current, $after] = [null, null, null];
        foreach ($snapshots as $s) {
            if ($s['at'] <= $r['at'] - 10) {
                $before = $s;
            }
            if ($s['at'] <= $r['at']) {
                $current = $s;
            }
            if ($after === null && $s['at'] >= $r['at'] + 5) {
                $after = $s;
            }
        }
        if ($before !== null && $r['questions'] < $before['questions'] && !$failed) {
            $violations[] = $at . 'the page shows ' . $r['questions'] . ' questions, the server had '
                . $before['questions'] . ' ten seconds before';
        }
        if ($after !== null && $r['questions'] > $after['questions']) {
            $violations[] = $at . 'the page shows ' . $r['questions'] . ' questions, more than the server had after it ('
                . $after['questions'] . ')';
        }
        if ($current !== null && (int) $r['percent'] >= 100) {
            $queued = 0;
            foreach ($current['runs'] as $run) {
                $queued += max(0, $run['ownsittings'] - $run['inflight'] - $run['tracepending']);
            }
            if ($queued > 0) {
                $violations[] = $at . 'the page shows 100 % of the questions with ' . $queued . ' sittings queued';
            }
        }
        $previous = $r;
    }
    $loads = count(array_filter($readings, static fn(array $r): bool => $r['source'] === 'load'));
    $range = $readings !== [] ? 'from ' . $readings[0]['questions'] . ' to ' . end($readings)['questions'] . ' questions'
        : 'not read';

    return ['readings' => count($readings), 'violations' => $violations, 'lines' => [
        'The page was read ' . count($readings) . ' times while the run was played (' . $loads . ' freshly loaded, '
            . 'the rest as its poll left it; ' . $skipped . ' skipped while it reloaded itself); header ' . $range . '.',
    ]];
}

/**
 * Progress snapshots taken while the workers play, checked for contradictions and against the actual ends (#160).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class smoke_eta_probe {
    /** @var string Where the snapshots go. */
    protected string $file;

    /** @var array[] The compact snapshots. */
    protected array $taken = [];

    /** @var string[] Contradictions found. */
    protected array $violations = [];

    /**
     * Start an empty file.
     *
     * @param string $file Where the snapshots go.
     */
    public function __construct(string $file) {
        $this->file = $file;
        file_put_contents($file, '');
    }

    /**
     * The snapshots taken so far.
     *
     * @return array[]
     */
    public function snapshots(): array {
        return $this->taken;
    }

    /**
     * One snapshot, as the page gets it: kept, written and checked.
     *
     * @param int $experimentid The experiment.
     * @return void
     */
    public function take(int $experimentid): void {
        global $DB;

        \local_catquizlab\local\question_progress::reset_caches();
        $snapshot = \local_catquizlab\local\live_progress::snapshot($experimentid);
        $time = $snapshot['time'];
        $runs = [];
        foreach ($time['runs'] as $run) {
            $row = array_column($snapshot['runs'], null, 'runid')[$run['runid']];
            $runs[] = ['runid' => $run['runid'], 'done' => $row['done'], 'total' => $row['total'],
                'failed' => $row['failed'], 'inflight' => $row['inflight'], 'tracepending' => $row['tracepending'],
                'questions' => $row['questions']['done'], 'expected' => $row['questions']['expected'],
                'own' => $run['own']['workseconds'], 'ownsittings' => $run['own']['sittings'],
                'ownquestions' => $run['own']['questions'], 'reliability' => $run['reliability'],
                'remaining' => $run['remaining'], 'finish' => $run['finish'], 'margin' => $run['margin']];
        }
        // What the page's poll gets, a moment later (#141 AK6, #160 DoD04): the same figures or further along.
        $poll = \local_catquizlab\external\live_status::execute($experimentid, 0)['progress'];
        $pollruns = [];
        foreach ($poll['runs'] ?? [] as $row) {
            $pollruns[(int) $row['runid']] = ['done' => (int) $row['done'],
                'questions' => (int) ($row['questions']['done'] ?? 0), 'percent' => (int) ($row['questions']['percent'] ?? 0)];
        }
        // Executions that ended without a result so far: every claim adds a try, and a sitting playing or collected
        // accounts for one of them. A sitting whose execution broke off and that went back to the queue keeps its try,
        // so this grows exactly then — also where no sitting failed (gate 0.7.64, moodle53: a worker timed out, its
        // sitting was queued again, the count fell by the 20 questions of that try and the probe called it a
        // contradiction).
        $interrupted = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(a.tries), 0) - COALESCE(SUM(CASE WHEN a.status IN (:running, :collected, :tracepending,
                    :validated) THEN 1 ELSE 0 END), 0)
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE r.experimentid = :experimentid",
            ['running' => \local_catquizlab\local\attempt_scheduler::STATUS_RUNNING,
                'collected' => \local_catquizlab\local\attempt_scheduler::STATUS_COLLECTED,
                'tracepending' => \local_catquizlab\local\attempt_scheduler::STATUS_TRACEPENDING,
                'validated' => \local_catquizlab\local\attempt_scheduler::STATUS_VALIDATED,
                'experimentid' => $experimentid]
        );
        $line = ['at' => $time['computed'], 'state' => $snapshot['state'], 'done' => $snapshot['done'],
            'interrupted' => $interrupted,
            'total' => $snapshot['total'], 'questions' => $snapshot['questions']['done'],
            'reliability' => $time['reliability'], 'remaining' => $time['remaining'], 'finish' => $time['finish'],
            'range' => $time['range'], 'slots' => $time['capacity']['slots'], 'physical' => $time['capacity']['physical'],
            'runs' => $runs, 'poll' => ['done' => (int) $poll['done'], 'total' => (int) $poll['total'],
                'questions' => (int) ($poll['questions']['done'] ?? 0),
                'qpercent' => (int) ($poll['questions']['percent'] ?? 0), 'runs' => $pollruns]];
        $this->taken[] = $line;
        file_put_contents($this->file, json_encode($line) . "\n", FILE_APPEND);
        $this->check($line);
    }

    /**
     * The contradictions a snapshot must not have (#160 AK08, AK09).
     *
     * @param array $s A compact snapshot.
     * @return void
     */
    protected function check(array $s): void {
        $at = '@' . $s['at'] . ': ';
        $sum = static fn(string $key): int => array_sum(array_map(static fn(array $r): int => (int) $r[$key], $s['runs']));
        if ($sum('done') !== $s['done'] || $sum('total') !== $s['total']) {
            $this->violations[] = $at . 'the runs\' sittings do not add up to the experiment\'s';
        }
        if ($sum('questions') !== $s['questions']) {
            $this->violations[] = $at . 'the runs\' questions do not add up to the experiment\'s';
        }
        $open = 0;
        $latest = null;
        foreach ($s['runs'] as $r) {
            if ($r['ownsittings'] !== $r['total'] - $r['done'] - $r['failed']) {
                $this->violations[] = $at . 'run ' . $r['runid'] . ': own sittings are not total - done - failed';
            }
            if ($r['remaining'] !== null && $r['remaining'] < 0) {
                $this->violations[] = $at . 'run ' . $r['runid'] . ': negative remaining time';
            }
            if ($r['ownsittings'] === 0 && $r['reliability'] !== 'final') {
                $this->violations[] = $at . 'run ' . $r['runid'] . ': nothing left, yet not final';
            }
            if ($r['ownsittings'] > 0) {
                $open++;
                if ($r['remaining'] !== null && ($latest === null || $r['remaining'] > $latest)) {
                    $latest = $r['remaining'];
                }
            }
        }
        if ($open === 0 && $s['reliability'] !== 'final') {
            $this->violations[] = $at . 'every run finished, the experiment is not final';
        }
        if ($s['reliability'] === 'final' && (int) $s['remaining'] !== 0) {
            $this->violations[] = $at . 'final with time remaining';
        }
        // The question count only grows (#141 DoD): a retry may drop the answers of the try it replaces, so a
        // fall is a contradiction only where no sitting failed and no execution broke off in between.
        $previous = count($this->taken) >= 2 ? $this->taken[count($this->taken) - 2] : null;
        if ($previous !== null) {
            $failed = static fn(array $x): int => array_sum(array_map(static fn(array $r): int => (int) $r['failed'], $x['runs']));
            if (
                $s['questions'] < $previous['questions'] && $failed($s) === $failed($previous)
                    && ($s['interrupted'] ?? 0) === ($previous['interrupted'] ?? 0)
            ) {
                $this->violations[] = $at . 'questions answered fell from ' . $previous['questions'] . ' to ' . $s['questions'];
            }
            if ($s['done'] < $previous['done']) {
                $this->violations[] = $at . 'sittings done fell from ' . $previous['done'] . ' to ' . $s['done'];
            }
        }
        // Nothing reads as complete before it is: a run at 100 % of its questions has nothing open.
        foreach ($s['runs'] as $r) {
            $complete = $r['expected'] !== null && $r['questions'] >= $r['expected'];
            // In flight with every question of a fixed form answered is the last submit; queued is not.
            if ($complete && $r['ownsittings'] > $r['inflight'] + $r['tracepending']) {
                $this->violations[] = $at . 'run ' . $r['runid'] . ': all questions counted with sittings still queued';
            }
        }
        if (in_array($s['reliability'], ['estimate', 'provisional'], true) && $s['remaining'] !== $latest) {
            $this->violations[] = $at . 'the experiment\'s remaining time is not its last run\'s';
        }
        // The poll, read just after: the same total, nothing behind the snapshot, no 100 % while anything is open.
        if (isset($s['poll'])) {
            $p = $s['poll'];
            if ($p['total'] !== $s['total'] || $p['done'] < $s['done'] || $p['questions'] < $s['questions']) {
                $this->violations[] = $at . 'the poll disagrees with the snapshot: ' . json_encode($p);
            }
            if ($open > 0 && $p['qpercent'] >= 100 && $p['done'] < $p['total']) {
                $this->violations[] = $at . 'the poll shows 100 % of the questions with sittings open';
            }
            foreach ($s['runs'] as $r) {
                $pr = $p['runs'][$r['runid']] ?? null;
                if ($pr === null || $pr['done'] < $r['done'] || $pr['questions'] < $r['questions']) {
                    $this->violations[] = $at . 'run ' . $r['runid'] . ': the poll is behind the snapshot';
                }
            }
        }
    }

    /**
     * Every forecast against the actual end of its run, and the summary.
     *
     * @param int $experimentid The experiment.
     * @return array{snapshots: int, runs: int, parallel: int, violations: string[], lines: string[]}
     */
    public function finish(int $experimentid): array {
        global $DB;

        // When each run actually ended: its last collected or failed execution, where it has nothing left.
        $ends = [];
        $last = end($this->taken) ?: ['runs' => []];
        foreach ($last['runs'] as $r) {
            if ($r['ownsittings'] === 0) {
                $ends[$r['runid']] = (int) $DB->get_field_sql(
                    'SELECT MAX(timecreated) FROM {local_catquizlab_attemptlog} WHERE runid = ? AND outcome IN (?, ?)',
                    [$r['runid'], \local_catquizlab\local\attempt_history::COLLECTED,
                        \local_catquizlab\local\attempt_history::FAILED]
                );
            }
        }
        $errors = [];
        $relative = [];
        $inside = 0;
        $parallel = 0;
        $nearlydone = 0;
        foreach ($this->taken as $s) {
            $flying = array_filter($s['runs'], static fn(array $r): bool => $r['inflight'] > 0);
            if (count($flying) >= 2) {
                $parallel++;
                foreach ($flying as $r) {
                    $nearlydone += $r['total'] > 0 && $r['done'] >= 0.9 * $r['total'] ? 1 : 0;
                }
            }
            foreach ($s['runs'] as $r) {
                if (!isset($ends[$r['runid']]) || $r['finish'] === null || $r['ownsittings'] === 0) {
                    continue;
                }
                $actual = $ends[$r['runid']] - $s['at'];
                if ($actual <= 0) {
                    continue;
                }
                $errors[] = abs($r['finish'] - $ends[$r['runid']]);
                $relative[] = abs($r['remaining'] - $actual) / $actual;
                $margin = (float) $r['margin'];
                $inside += abs($r['remaining'] - $actual) <= $margin * $r['remaining'] ? 1 : 0;
            }
        }
        sort($relative);
        $n = count($errors);
        $summary = [
            'snapshots' => count($this->taken),
            'runs' => count($last['runs']),
            'parallel' => $parallel,
            'parallelwithnearlydone' => $nearlydone,
            'forecasts' => $n,
            'meanabserror' => $n ? round(array_sum($errors) / $n, 1) : null,
            'medianrelerror' => $n ? round($relative[intdiv($n, 2)], 3) : null,
            'p90relerror' => $n ? round($relative[min($n - 1, (int) floor(0.9 * $n))], 3) : null,
            'withinrange' => $n ? round($inside / $n, 3) : null,
            'violations' => $this->violations,
        ];
        file_put_contents($this->file . '.summary.json', json_encode($summary, JSON_PRETTY_PRINT));

        return $summary + ['lines' => [
            'ETA forecasts checked against actual ends: ' . $n . ' (from ' . count($this->taken) . ' snapshots)',
            'mean absolute error of the finish: ' . ($summary['meanabserror'] ?? '–') . ' s; median relative error '
                . ($summary['medianrelerror'] ?? '–') . ', 90th percentile ' . ($summary['p90relerror'] ?? '–')
                . '; actual end within the range: ' . ($summary['withinrange'] ?? '–'),
            'snapshots with two or more runs in flight: ' . $parallel . ' (of those runs ≥ 90 % done: ' . $nearlydone . ')',
        ]];
    }
}
