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
 * One start, then nobody (#151 DoD2, DoD5): a saved experiment started once, and the rest left to cron.
 *
 * What a person does is two things: save a definition, start it once. This script does exactly those, as an
 * administrator, and from then on does nothing a person would — no button, no page, no session. It only runs
 * Moodle's cron the way an installation does, as separate processes every --every seconds, and watches.
 *
 * On the way it does to the installation what happens to one left alone over a weekend:
 *   - cron stops for --cronpause seconds once the workers are playing, and starts again;
 *   - every worker process is killed once the first sitting is in (--killworkers), and nobody restarts them.
 * The pipeline has to recover from both by itself: the dead workers' claims lapse and are handed out again, new
 * workers are started by the tick.
 *
 * PASS (exit 0) only when, before --minutes run out:
 *   - the experiment is finished, every planned sitting collected, its results there;
 *   - its place in the line closed as finished.
 * Nothing but the start comes from a person: after it, this script only runs cron and reads.
 * Otherwise FAIL (exit 3), with the timeline. Exit 1 for a setup that cannot run at all.
 *
 *     php local/catquizlab/cli/one_start_check.php [--persons=3] [--strategy=fastest] [--minutes=30] [--every=20]
 *         [--cronpause=90] [--killworkers=1]
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_runner;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\experiment_status;

[$options] = cli_get_params([
    'help' => false, 'persons' => 3, 'strategy' => 'fastest', 'minutes' => 30, 'every' => 20, 'cronpause' => 90,
    'killworkers' => 1,
], ['h' => 'help']);
if ($options['help']) {
    echo "One start, then nobody (#151). --persons=3 --strategy=fastest --minutes=30 --every=20 --cronpause=90"
        . " --killworkers=1\n";
    exit(0);
}
global $DB;
\core\session\manager::set_user(get_admin());
$t0 = time();
$timeline = [];
$say = static function (string $what) use (&$timeline, $t0): void {
    $line = sprintf('%+5ds  %s', time() - $t0, $what);
    $timeline[] = $line;
    cli_writeln($line);
};
$cronlog = make_request_directory() . '/cron.log';
// Core's scripts: beside the plugins up to Moodle 5.0, in the project root above public/ from 5.1.
$cronphp = array_values(array_filter(
    [($CFG->root ?? '') . '/admin/cli/cron.php', $CFG->dirroot . '/admin/cli/cron.php'],
    static fn(string $file): bool => is_readable($file)
))[0] ?? '';
if ($cronphp === '') {
    cli_error('admin/cli/cron.php not found.');
}
$cron = static function () use ($cronphp, $cronlog): void {
    // As an installation runs it: its own process, its own PHP, its whole run.
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cronphp) . ' --keep-alive=0 >> ' . escapeshellarg($cronlog)
        . ' 2>&1');
};

// The installation, not the experiment (#151 AK5: a ready installation is the premise): as the smoke test does.
$base = getenv('CATLAB_BASE_URL') ?: ($CFG->wwwroot ?: 'http://127.0.0.1:8000');
set_config('worker_base_url', rtrim($base, '/'), 'local_catquizlab');
$node = getenv('CATLAB_NODE') ?: trim((string) @exec('command -v node 2>/dev/null'));
if ($node !== '' && is_executable($node)) {
    set_config('worker_node_path', $node, 'local_catquizlab');
}
\local_catquizlab\local\setup_wizard::run(true);
if (
    !\local_catquizlab\local\system_health::worker_modules_installed()
        || !\local_catquizlab\local\worker_runtime::browser_present()
) {
    \local_catquizlab\local\worker_runtime::ensure();
}
$cron();
if (empty(\local_catquizlab\local\setup_wizard::state()['ready'])) {
    cli_error('The installation cannot run experiments; nothing to check.');
}

// A person, 1: save a definition.
$definition = experiment_definition::example_baseline();
$definition['name'] = 'One start ' . date('His');
$definition['replications'] = 1;
$definition['strategy'] = (string) $options['strategy'];
$definition['persons']['count'] = max(1, (int) $options['persons']);
$definition['pool']['scales'] = ['categories' => 1, 'subcategories' => 3, 'itemspersubscale' => 12];
$definition['budgets']['global'] = ['minitems' => 15, 'maxitems' => 20];
$definition['budgets']['subscale'] = ['minitems' => 5, 'maxitems' => 15];
$definition['budgets']['se'] = ['min' => 0.35, 'max' => 1.0];
$experimentid = (int) experiment_service::save($definition)['id'];
$say("saved experiment {$experimentid}");

// A person, 2: start it, once.
$started = experiment_runner::start($experimentid);
if (empty($started['ok'])) {
    cli_error('The start was refused: ' . $started['reason'] . ' ' . $started['detail']);
}
$say('start accepted, number ' . $started['position'] . ' in the line');

// Nobody from here on. Cron, and watching.
$deadline = $t0 + 60 * max(1, (int) $options['minutes']);
$phase = '';
$paused = false;
$killed = false;
$every = max(5, (int) $options['every']);
$config = \local_catquizlab\local\worker_launcher::config_from_settings();
while (time() < $deadline) {
    $status = experiment_status::snapshot($experimentid, true);
    if ($status['phase'] !== $phase) {
        $phase = $status['phase'];
        $say("phase {$phase} — {$status['sittings']}");
    }
    if (
        in_array($phase, ['complete', 'completefailures', 'blocked', 'stopped'], true)
            && experiment_runner::state($experimentid)['state'] !== experiment_runner::STATE_RUNNING
    ) {
        break;
    }
    // A weekend's disturbances, each once.
    if (!$killed && !empty($options['killworkers']) && (int) $status['done'] > 0) {
        $pids = [];
        // The script's path as a pattern that does not match the shell running pgrep itself ("[/]home/…").
        $script = (string) $config['script'];
        $pattern = '[' . substr($script, 0, 1) . ']' . preg_quote(substr($script, 1));
        exec('pgrep -f ' . escapeshellarg($pattern), $pids);
        foreach ($pids as $pid) {
            if ((int) $pid > 0 && (int) $pid !== getmypid()) {
                @posix_kill((int) $pid, 9);
            }
        }
        $killed = true;
        $say('killed ' . count($pids) . ' worker process(es); nobody restarts them');
    }
    if (!$paused && (int) $options['cronpause'] > 0 && (int) $status['progress']['now']['workers'] > 0) {
        $paused = true;
        $say('cron stopped for ' . (int) $options['cronpause'] . ' s');
        sleep((int) $options['cronpause']);
        $say('cron started again');
        continue;
    }
    $cron();
    sleep($every);
}

// The verdict.
$problems = [];
$runner = experiment_runner::state($experimentid);
$planned = (int) $runner['attempts']['planned'];
$collected = (int) $runner['attempts']['collected'];
if ($runner['state'] !== experiment_runner::STATE_FINISHED) {
    $problems[] = 'not finished: ' . $runner['state'] . ' (' . $phase . ')';
}
if ($planned === 0 || $collected !== $planned) {
    $problems[] = "sittings collected {$collected} of {$planned}";
}
$results = $DB->count_records_sql(
    'SELECT COUNT(1) FROM {local_catquizlab_result} r JOIN {local_catquizlab_run} u ON u.id = r.runid
      WHERE u.experimentid = ?',
    [$experimentid]
);
if ($results === 0) {
    $problems[] = 'no results';
}
$entry = $DB->get_records('local_catquizlab_execqueue', ['experimentid' => $experimentid], 'id DESC', '*', 0, 1);
$entry = reset($entry);
if (!$entry || $entry->state !== execution_queue::FINISHED) {
    $problems[] = 'its place in the line is ' . ($entry ? $entry->state . ' (' . $entry->reason . ')' : 'gone');
}
if (!empty($options['killworkers']) && !$killed) {
    $problems[] = 'the workers were never killed (no sitting came in)';
}
$say(sprintf(
    'end: %s, %d/%d sittings, %d result rows, line %s',
    $runner['state'],
    $collected,
    $planned,
    $results,
    $entry ? $entry->state : '-'
));
foreach ($problems as $problem) {
    cli_writeln('  FAIL: ' . $problem);
}
cli_writeln($problems === [] ? 'PASS: one start, everything else by itself.' : 'FAIL');
exit($problems === [] ? 0 : 3);
