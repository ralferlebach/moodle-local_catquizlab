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
    'list-strategies' => false,
    'provisioning-only' => false,
    'case' => '',
    'concurrency' => 0,
    'no-navigation-failures' => false,
], ['h' => 'help']);

if ($options['help']) {
    cli_writeln(<<<EOT
Play one experiment from definition to results.

Options:
  --strategy=classic   Which CAT strategy to smoke.
  --persons=2          How many simulated people.
  --minanswers=15      How many answers an attempt must give to count.
  --minutes=5          How long to wait for the worker.
  --keep               Leave the experiment behind for inspection.
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
// Enough items to answer $minanswers of them without running the pool dry:
// an attempt that stops because there is nothing left to ask has not been
// stopped by the CAT, and reading that as a short test is reading the wrong
// thing.
$subscales = 3;
// The selection does not take items in order, it takes the one that suits the
// current estimate, so a pool sized exactly to the answer count runs out of
// suitable items well before it runs out of items.
$peritem = max(12, (int) ceil(($minanswers * 2) / $subscales));
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

$experimentid = (int) experiment_service::save($definition)['id'];
step('Defined experiment ' . $experimentid . '.', $started);

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

// 4. Run it: a real browser, against real questions.
//
// Other work on this installation is paused first. A worker takes whatever is
// claimable, so on a busy instance it would spend the whole timeout on
// somebody else's attempts and this test would report a failure that says
// nothing about the thing it was testing.
$otherwork = $DB->execute(
    'UPDATE {local_catquizlab_attempt}
        SET nextruntime = :later
      WHERE status = :queued
        AND runid NOT IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = :experimentid)',
    [
        'later'        => time() + HOURSECS,
        'queued'       => attempt_scheduler::STATUS_QUEUED,
        'experimentid' => $experimentid,
    ]
);
step('Other queued work deferred for the duration.');

// For a load test, several workers at once: the race of #100 showed under
// load, not in a single browser.
if ((int) $options['concurrency'] > 0) {
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

// 5. Wait for it to actually play them.
$started = microtime(true);
$collected = 0;
$failed = 0;
while (time() < $deadline) {
    sleep(5);

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
    if ($navigation > 0) {
        cli_error($navigation . ' execution(s) failed on navigation or transport.');
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
    'id, tracejson'
);

$shortest = PHP_INT_MAX;
$stopreasons = [];
foreach ($traces as $trace) {
    $decoded = json_decode((string) $trace->tracejson, true);

    // The trace's own count, not the number of fields in it. Counting the keys
    // gave a stable 13 for every strategy and every budget — which was the
    // number of things the trace records about an attempt, not the number of
    // questions the attempt answered. It answered twenty.
    $answered = (int) ($decoded['steps'] ?? ($decoded['nitems'] ?? 0));
    $shortest = min($shortest, $answered);

    if (!empty($decoded['stopreason'])) {
        $stopreasons[(string) $decoded['stopreason']] = true;
    }
}
if ($traces === [] || $shortest < $minanswers) {
    cli_error('Attempts answered fewer than ' . $minanswers . ' questions (shortest: '
        . ($shortest === PHP_INT_MAX ? 0 : $shortest) . ').');
}
step('Every collected attempt answered at least ' . $shortest . ' questions (needed '
    . $minanswers . '); stopped because: ' . (implode(', ', array_keys($stopreasons)) ?: 'unrecorded') . '.');

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

if ((int) ($named['n'] ?? 0) < 1) {
    cli_error('Results report no observations.');
}

$withestimate = (int) ($named['n'] ?? 0);


// Give the rest of the installation its queue back, whatever happened here.
$DB->execute(
    'UPDATE {local_catquizlab_attempt}
        SET nextruntime = 0
      WHERE status = :queued
        AND nextruntime > :now',
    ['queued' => attempt_scheduler::STATUS_QUEUED, 'now' => time()]
);

if (empty($options['keep'])) {
    \local_catquizlab\local\purger::delete_experiment($experimentid, true, true);
    step('Cleaned up.');
}

cli_writeln('');
cli_writeln('PASS: ' . $collected . ' attempts played, ' . $withestimate
    . ' with estimates, ' . $results . ' result rows.');
exit(0);

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
