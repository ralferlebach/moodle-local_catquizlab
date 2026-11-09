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
 * Why nothing runs, in one command (incident 2026-11-09).
 *
 * Workers did not start and the answer took a dozen SQL queries and two shell sessions to assemble: six runs
 * created and never started, an execution queue held for 48 days by an experiment without runs. Everything that
 * answered it is here, read-only:
 *
 *   sudo -u www-data php local/catquizlab/cli/diagnose.php --experimentid=16
 *   sudo -u www-data php local/catquizlab/cli/diagnose.php --experimentid=16 --json
 *
 * Run it as the web server user from a directory that user may enter (cd /tmp), as cron runs it.
 * It changes nothing; tokens are never printed.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\experiment_runner;
use local_catquizlab\local\registry;
use local_catquizlab\local\setup_wizard;
use local_catquizlab\local\worker_capacity;
use local_catquizlab\local\worker_launcher;
use local_catquizlab\local\worker_registry;

[$options] = cli_get_params(['experimentid' => 0, 'json' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    cli_writeln('Read-only diagnosis of why nothing runs. --experimentid=N narrows to one experiment; --json for JSON.');
    exit(0);
}

global $DB;
$experimentid = (int) $options['experimentid'];
$breakdown = attempt_scheduler::queue_breakdown();
$last = json_decode((string) get_config('local_catquizlab', 'lastdispatch'), true) ?: [];
$config = worker_launcher::config_from_settings();
$setup = setup_wizard::state();

$runs = [];
if ($experimentid > 0) {
    $rows = $DB->get_records_sql(
        "SELECT r.id, r.cellkey, r.status, r.lasterror,
                (SELECT COUNT(1) FROM {local_catquizlab_person} p WHERE p.runid = r.id) AS digitwins,
                (SELECT COUNT(1) FROM {local_catquizlab_attempt} a WHERE a.runid = r.id AND a.status = :queued) AS queued,
                (SELECT COUNT(1) FROM {local_catquizlab_attempt} a WHERE a.runid = r.id AND a.status = :running) AS running,
                (SELECT COUNT(1) FROM {local_catquizlab_attempt} a WHERE a.runid = r.id AND a.status = :failed) AS failed
           FROM {local_catquizlab_run} r WHERE r.experimentid = :experimentid ORDER BY r.id",
        ['experimentid' => $experimentid, 'queued' => attempt_scheduler::STATUS_QUEUED,
            'running' => attempt_scheduler::STATUS_RUNNING, 'failed' => attempt_scheduler::STATUS_FAILED]
    );
    foreach ($rows as $row) {
        $runs[] = (array) $row;
    }
}

$report = [
    'time' => userdate(time()),
    'experiment' => $experimentid > 0 ? experiment_runner::state($experimentid) : null,
    'runs' => $runs,
    'queue' => $breakdown,
    'whynothing' => (int) $breakdown['claimable'] > 0 ? ''
        : worker_launcher::idle_text(worker_launcher::idle_reasons($breakdown, ($last['queue'] ?? []) + ['since' => 0])),
    'lastdispatch' => $last,
    'executionqueue' => array_values(array_map(static function ($entry) use ($DB): array {
        return [
            'experimentid' => (int) $entry->experimentid,
            'state' => $entry->state,
            'since' => userdate((int) ($entry->timestarted ?: $entry->timecreated)),
            'runs' => $DB->count_records('local_catquizlab_run', ['experimentid' => $entry->experimentid]),
            'orphaned' => $entry->state === execution_queue::RUNNING
                ? execution_queue::orphaned((int) $entry->experimentid) : '',
        ];
    }, $DB->get_records_select('local_catquizlab_execqueue', "state IN ('waiting', 'running')", null, 'position'))),
    'setuptasks' => array_values(array_map(static fn($task): array => [
        'run' => (int) (json_decode((string) $task->customdata, true)['runid'] ?? 0),
        'faildelay' => (int) $task->faildelay,
        'started' => $task->timestarted ? userdate((int) $task->timestarted) : '',
    ], $DB->get_records_select('task_adhoc', "classname LIKE '%orchestrate_run%'"))),
    'setupready' => !empty($setup['ready']),
    'setupblockers' => $setup['blockers'] ?? [],
    'workerconfig' => [
        'missing' => worker_launcher::missing($config),
        'node' => (string) $config['node'],
        'baseurl' => (string) $config['baseurl'],
        'token' => $config['token'] !== '' ? 'set (' . strlen($config['token']) . ' characters)' : 'missing',
    ],
    'capacity' => worker_capacity::effective(),
    'workers' => worker_registry::summary(),
    'draftruns' => $DB->count_records('local_catquizlab_run', ['status' => registry::STATUS_DRAFT]),
];

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

cli_heading('CatQuizLab diagnosis — ' . $report['time']);
if ($report['experiment'] !== null) {
    cli_writeln("Experiment {$experimentid}: {$report['experiment']['label']} ({$report['experiment']['state']})"
        . ($report['experiment']['canstartruns'] ? ' — runs can be started on the progress page' : ''));
    foreach ($runs as $run) {
        cli_writeln(sprintf(
            '  run %-6d %-26s status %-3d twins %-6d queued %-6d running %-4d failed %-4d %s',
            $run['id'],
            $run['cellkey'],
            $run['status'],
            $run['digitwins'],
            $run['queued'],
            $run['running'],
            $run['failed'],
            (string) $run['lasterror']
        ));
    }
}
cli_writeln("Sittings: claimable {$breakdown['claimable']}, not due {$breakdown['notdue']}, blocked {$breakdown['blocked']}, "
    . "paused {$breakdown['paused']}, running {$breakdown['running']}");
cli_writeln((int) $breakdown['claimable'] > 0
    ? 'Claimable sittings exist: the next tick starts workers for them.'
    : 'Why nothing would start: ' . $report['whynothing']);
cli_writeln('Last tick: ' . (isset($last['time']) ? userdate((int) $last['time']) : 'never') . ', launched '
    . (int) ($last['launched'] ?? 0) . ', reason ' . ((string) ($last['reason'] ?? '') ?: '–'));
cli_writeln('Execution queue:');
foreach ($report['executionqueue'] as $entry) {
    cli_writeln("  experiment {$entry['experimentid']}: {$entry['state']} since {$entry['since']}, {$entry['runs']} run(s)"
        . ($entry['orphaned'] !== '' ? " — {$entry['orphaned']} (closed by the next tick)" : ''));
}
if ($report['executionqueue'] === []) {
    cli_writeln('  empty');
}
cli_writeln('Setup tasks pending: ' . count($report['setuptasks']));
foreach ($report['setuptasks'] as $task) {
    cli_writeln("  run {$task['run']}" . ($task['faildelay'] > 0 ? ", failed before (retry delay {$task['faildelay']} s)" : '')
        . ($task['started'] !== '' ? ", running since {$task['started']}" : ''));
}
cli_writeln('Installation ready: ' . ($report['setupready'] ? 'yes' : 'no — ' . implode(', ', $report['setupblockers'])));
cli_writeln('Worker settings missing: ' . ($report['workerconfig']['missing'] === [] ? 'none'
    : implode(', ', $report['workerconfig']['missing'])) . '; token ' . $report['workerconfig']['token']);
cli_writeln("Capacity: limit {$report['capacity']['limit']} ({$report['capacity']['by']})");
cli_writeln("Workers: live {$report['workers']['live']}, starting {$report['workers']['starting']}, "
    . "crashed {$report['workers']['crashed']}, stopped {$report['workers']['stopped']}");
