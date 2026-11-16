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
 * Two experiments, real workers: the holder of the execution line is played first, the one started outside it after
 * (#163 DoD3, DoD8, AK10).
 *
 * --outside=RUNID: a ready run started outside the line (e2e_prepare.php), queued first.
 * --holder=RUNID: a ready run of another experiment, queued after it; its experiment is started ("Start experiment"
 * puts it in the line) and the line moves once, as a claim or the tick moves it. The run started outside is given a
 * place at the end. Then real workers (--workers, the browser worker) play everything, without a click and without a
 * tick: the claims move the line on when the holder is through.
 *
 * Checked: every sitting of the holder started before any sitting of the other one; the other one's first start came
 * after the holder's last sitting was finished (the line moves on only then); both experiments complete; the line says
 * how: the holder's entry finished, the other one's added by the line ("adopted", read while it waits; once it has
 * ended, its reason says why it ended). Prints the timeline.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\worker_launcher;
use local_catquizlab\local\worker_registry;

[$options] = cli_get_params(['outside' => 0, 'holder' => 0, 'workers' => 4, 'minutes' => 15]);
\core\session\manager::set_user(get_admin());

$outsiderun = (int) $options['outside'];
$holderrun = (int) $options['holder'];
if ($outsiderun <= 0 || $holderrun <= 0) {
    cli_error('--outside=RUNID and --holder=RUNID are required (two ready runs of two experiments, from e2e_prepare.php).');
}
$outside = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $outsiderun], MUST_EXIST);
$holder = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $holderrun], MUST_EXIST);
if ($outside === $holder) {
    cli_error('The two runs belong to the same experiment.');
}
$t0 = time();
$say = static function (string $text) use ($t0): void {
    cli_writeln(sprintf('%+5ds  %s', time() - $t0, $text));
};
$sittings = static function (int $experimentid): array {
    global $DB;

    return array_map('intval', $DB->get_fieldset_sql(
        'SELECT a.id FROM {local_catquizlab_attempt} a JOIN {local_catquizlab_run} r ON r.id = a.runid
          WHERE r.experimentid = ?',
        [$experimentid]
    ));
};
$mine = ['outside' => $sittings($outside), 'holder' => $sittings($holder)];
$say(sprintf(
    'Experiment %d (started outside the line, %d sitting(s), queued first); experiment %d (%d sitting(s)).',
    $outside,
    count($mine['outside']),
    $holder,
    count($mine['holder'])
));

// The holder is started the one way an operator starts an experiment; the line moves once, as a claim or the tick
// moves it: the run started outside gets its place at the end, the holder takes its turn.
$start = \local_catquizlab\local\experiment_runner::start($holder);
if (empty($start['ok'])) {
    cli_error('Experiment ' . $holder . ' could not be started: ' . $start['reason']);
}
execution_queue::take_turn();
$line = array_column(execution_queue::entries(), 'experimentid');
$say('Line: ' . implode(' → ', $line) . ' (holder ' . execution_queue::holder() . ').');
if (execution_queue::holder() !== $holder || array_slice($line, 0, 2) !== [$holder, $outside]) {
    cli_error('The line is not holder first, the run started outside behind it.');
}
// How the other got its place, read now: when its entry ends, its reason becomes why it ended (sittings-done).
$adoptedreason = (string) $DB->get_field_select(
    'local_catquizlab_execqueue',
    'reason',
    'experimentid = ? AND state = ?',
    [$outside, execution_queue::WAITING]
);
$say('The run started outside waits with the reason "' . $adoptedreason . '".');

// The installation, not the experiment, as the smoke test and the one-start check set it up: workers are only started
// with a worker token, execution enabled, base URL, node and browser. Without them none starts ("not-configured") and
// nothing is played (gate 0.7.65, legacy). Cron is not run here: the claims move the line, not a tick.
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

// Real workers, as many as asked for; the setting comes back however this ends.
$before = get_config('local_catquizlab', 'worker_concurrency');
register_shutdown_function(static function () use ($before): void {
    set_config('worker_concurrency', $before === false ? null : $before, 'local_catquizlab');
});
set_config('worker_concurrency', max(1, (int) $options['workers']), 'local_catquizlab');
worker_registry::reap();
$launch = worker_launcher::launch_pool(worker_launcher::config_from_settings());
$say('Workers started: ' . (int) $launch['launched'] . ' (' . $launch['reason'] . ').');
if ((int) $launch['launched'] === 0) {
    cli_error('No worker could be started: ' . $launch['reason']);
}

$deadline = time() + max(1, (int) $options['minutes']) * MINSECS;
$all = array_merge($mine['outside'], $mine['holder']);
[$in, $params] = $DB->get_in_or_equal($all);
$done = 0;
$holderdone = false;
while (time() < $deadline) {
    sleep(5);
    $done = $DB->count_records_select(
        'local_catquizlab_attempt',
        "id {$in} AND status IN (?, ?)",
        array_merge($params, [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_FAILED])
    );
    if (!$holderdone && execution_queue::holder() !== $holder) {
        $holderdone = true;
        $say('The line moved on: holder now ' . execution_queue::holder() . '.');
    }
    if ($done >= count($all)) {
        break;
    }
    // What the tick does every minute: workers for free slots (it moves nothing here — the claims do).
    worker_launcher::launch_pool(worker_launcher::config_from_settings());
}
$say(sprintf('%d of %d sittings finished.', $done, count($all)));

// The timeline from the sittings' history: when each was started and when it ended.
$events = $DB->get_records_select(
    'local_catquizlab_attemptlog',
    "attemptid {$in}",
    $params,
    'timecreated ASC, id ASC',
    'id, attemptid, outcome, timecreated'
);
$first = ['outside' => PHP_INT_MAX, 'holder' => PHP_INT_MAX];
$lastend = ['outside' => 0, 'holder' => 0];
$laststart = ['outside' => 0, 'holder' => 0];
foreach ($events as $event) {
    $who = in_array((int) $event->attemptid, $mine['holder'], true) ? 'holder' : 'outside';
    $t = (int) $event->timecreated;
    if ($event->outcome === attempt_history::STARTED) {
        $first[$who] = min($first[$who], $t);
        $laststart[$who] = max($laststart[$who], $t);
    } else {
        $lastend[$who] = max($lastend[$who], $t);
    }
    cli_writeln(sprintf('  %+5ds  %-7s sitting %d: %s', $t - $t0, $who, (int) $event->attemptid, $event->outcome));
}
$entries = $DB->get_records_select(
    'local_catquizlab_execqueue',
    'experimentid IN (?, ?)',
    [$holder, $outside],
    'id ASC',
    'id, experimentid, state, reason'
);
foreach ($entries as $entry) {
    cli_writeln(sprintf(
        '  line entry of experiment %d: %s (%s)',
        (int) $entry->experimentid,
        $entry->state,
        (string) $entry->reason
    ));
}

$checks = [
    'everything finished' => $done >= count($all),
    'every sitting of the holder started before any of the other' => $laststart['holder'] <= $first['outside'],
    'the other started only after the holder\'s last sitting had ended' => $lastend['holder'] <= $first['outside'],
    'the run started outside was given its place by the line' => $adoptedreason === execution_queue::ADOPTED,
];
$pass = true;
foreach ($checks as $what => $ok) {
    cli_writeln(($ok ? '  ok    ' : '  FAIL  ') . $what);
    $pass = $pass && $ok;
}
cli_writeln($pass ? 'PASS: the holder first, the run started outside after it, nothing played beside the line.'
    : 'FAIL');
exit($pass ? 0 : 1);
