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
 * Stop, resume, finish (#165 DoD-01/02, #166): real workers, the real engine, nobody clicking a run.
 *
 * A person saves an experiment and starts it; while the workers play it, the person stops it (what "Stop experiment"
 * does), and resumes it (what "Resume experiment" does after its preview). From then on only cron runs, as an
 * installation runs it. On the way:
 *   - the stop comes while sittings are in play: their workers' reports arrive after it (the race with the last
 *     worker) and must not change anything;
 *   - the resume is asked twice; the second must find nothing.
 *
 * PASS (exit 0) only when, before --minutes run out:
 *   - the stop closed what was open as cancelled, nothing as failed, and kept every collected sitting;
 *   - after the resume every planned sitting is collected, each once: as many collected as planned, at most one
 *     finished engine attempt per simulated person, the sittings collected before the stop with the same trace;
 *   - nothing failed, nothing left cancelled, results there, the place in the line closed as finished;
 *   - the history of each sitting the stop closed says cancelled, then resumed (or collected after the stop).
 * Otherwise FAIL (exit 3), with the timeline. Exit 1 for a setup that cannot run at all.
 *
 *     php local/catquizlab/cli/resume_check.php [--persons=12] [--strategy=fastest] [--stopafter=2] [--minutes=30]
 *         [--every=15]
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
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_resume;
use local_catquizlab\local\experiment_runner;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\experiment_status;
use local_catquizlab\local\experiment_stop;

[$options] = cli_get_params([
    'help' => false, 'persons' => 12, 'strategy' => 'fastest', 'stopafter' => 2, 'minutes' => 30, 'every' => 15,
], ['h' => 'help']);
if ($options['help']) {
    echo "Stop, resume, finish (#165). --persons=12 --strategy=fastest --stopafter=2 --minutes=30 --every=15\n";
    exit(0);
}
global $DB;
\core\session\manager::set_user(get_admin());
$t0 = time();
$say = static function (string $what) use ($t0): void {
    cli_writeln(sprintf('%+5ds  %s', time() - $t0, $what));
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
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cronphp) . ' --keep-alive=0 >> ' . escapeshellarg($cronlog)
        . ' 2>&1');
};

// The installation, as the smoke test and the one-start check set it up.
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

$definition = experiment_definition::example_baseline();
$definition['name'] = 'Resume check ' . date('His');
$definition['replications'] = 1;
$definition['strategy'] = (string) $options['strategy'];
$definition['persons']['count'] = max(3, (int) $options['persons']);
$definition['pool']['scales'] = ['categories' => 1, 'subcategories' => 3, 'itemspersubscale' => 12];
$definition['budgets']['global'] = ['minitems' => 15, 'maxitems' => 20];
$definition['budgets']['subscale'] = ['minitems' => 5, 'maxitems' => 15];
$definition['budgets']['se'] = ['min' => 0.35, 'max' => 1.0];
$experimentid = (int) experiment_service::save($definition)['id'];
$started = experiment_runner::start($experimentid);
if (empty($started['ok'])) {
    cli_error('The start was refused: ' . $started['reason'] . ' ' . $started['detail']);
}
$say("experiment {$experimentid} saved and started, number {$started['position']} in the line");

$deadline = $t0 + 60 * max(2, (int) $options['minutes']);
$every = max(5, (int) $options['every']);
$problems = [];
$sittings = static function () use ($DB, $experimentid): array {
    return $DB->get_records_sql(
        'SELECT a.id, a.status, a.tracejson, a.engineattemptid, a.personid, a.leaseowner
           FROM {local_catquizlab_attempt} a
           JOIN {local_catquizlab_run} r ON r.id = a.runid
          WHERE r.experimentid = ?
       ORDER BY a.id',
        [$experimentid]
    );
};
$count = static fn(array $rows, int $status): int => count(array_filter(
    $rows,
    static fn(\stdClass $row): bool => (int) $row->status === $status
));

// 1. Played until --stopafter sittings are in and another is in play, then stopped.
// Looked at every two seconds, cron every --every seconds: the moment is short when workers are many.
$stopafter = max(1, (int) $options['stopafter']);
$stopped = null;
$lastcron = 0;
while (time() < $deadline) {
    $rows = $sittings();
    $collected = $count($rows, attempt_scheduler::STATUS_COLLECTED) + $count($rows, attempt_scheduler::STATUS_VALIDATED);
    $inplay = $count($rows, attempt_scheduler::STATUS_RUNNING);
    if ($collected >= $stopafter && ($inplay > 0 || $collected >= count($rows) - 1)) {
        $stopped = experiment_stop::stop($experimentid);
        $say("stopped with {$collected} collected and {$inplay} in play: " . json_encode($stopped));
        break;
    }
    if (time() - $lastcron >= $every) {
        $cron();
        $lastcron = time();
    }
    sleep(2);
}
if ($stopped === null) {
    cli_writeln('  FAIL: never got to ' . $stopafter . ' collected sittings with one in play');
    exit(3);
}
$before = $sittings();
$keep = [];
foreach ($before as $row) {
    if (in_array((int) $row->status, [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_VALIDATED], true)) {
        $keep[(int) $row->id] = sha1((string) $row->tracejson);
    }
}
$closed = array_keys(array_filter(
    $before,
    static fn(\stdClass $row): bool => (int) $row->status === attempt_scheduler::STATUS_CANCELLED
));
$failedatstop = $count($before, attempt_scheduler::STATUS_FAILED);
$say(sprintf('after the stop: %d kept, %d cancelled, %d failed', count($keep), count($closed), $failedatstop));
if ($closed === []) {
    $problems[] = 'the stop closed nothing (the experiment had finished before it)';
}
if ($failedatstop > 0) {
    $problems[] = "{$failedatstop} sitting(s) failed at the stop (#166: a stop cancels)";
}
if (experiment_status::snapshot($experimentid, true)['phase'] !== 'stopped') {
    $problems[] = 'not "stopped" after the stop';
}

// 2. The race with the last worker: cron and the workers' late reports for a while, nothing may change.
for ($i = 0; $i < 3; $i++) {
    $cron();
    sleep($every);
}
$late = $sittings();
foreach ($closed as $id) {
    if ((int) $late[$id]->status !== attempt_scheduler::STATUS_CANCELLED) {
        $problems[] = "sitting {$id} changed after the stop to status {$late[$id]->status}";
    }
}

// 3. Resumed, as "Resume experiment" does after its preview; asked twice.
$preview = experiment_resume::preview($experimentid);
$say('preview: ' . json_encode($preview['totals']));
$resumed = experiment_resume::resume($experimentid);
$say('resumed: ' . json_encode(array_diff_key($resumed, ['queue' => 1])) . ', line ' . json_encode($resumed['queue']));
if (empty($resumed['ok'])) {
    $problems[] = 'the resume was refused: ' . $resumed['reason'];
}
$again = experiment_resume::resume($experimentid);
if (!empty($again['ok']) || $again['reason'] !== 'nothing') {
    $problems[] = 'a second resume did something: ' . json_encode($again);
}

// 4. Nobody from here on: cron, and watching.
$phase = '';
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
    $cron();
    sleep($every);
}

// The verdict.
$after = $sittings();
$planned = count($after);
$collected = $count($after, attempt_scheduler::STATUS_COLLECTED) + $count($after, attempt_scheduler::STATUS_VALIDATED);
$runner = experiment_runner::state($experimentid);
if ($runner['state'] !== experiment_runner::STATE_FINISHED) {
    $problems[] = 'not finished: ' . $runner['state'] . ' (' . $phase . ')';
}
if ($planned === 0 || $collected !== $planned) {
    $problems[] = "sittings collected {$collected} of {$planned}";
}
foreach ([attempt_scheduler::STATUS_FAILED => 'failed', attempt_scheduler::STATUS_CANCELLED => 'cancelled'] as $s => $label) {
    if ($count($after, $s) > 0) {
        $problems[] = $count($after, $s) . " sitting(s) {$label} at the end";
    }
}
foreach ($keep as $id => $hash) {
    if (!isset($after[$id]) || sha1((string) $after[$id]->tracejson) !== $hash) {
        $problems[] = "sitting {$id}, collected before the stop, is not as it was";
    }
}
// Each sitting collected once: one engine attempt per sitting, and no simulated person with two finished attempts.
$engineids = array_filter(array_map(static fn(\stdClass $row): int => (int) $row->engineattemptid, $after));
if (count($engineids) !== count(array_unique($engineids))) {
    $problems[] = 'an engine attempt is collected for two sittings';
}
$doubles = $DB->get_records_sql(
    "SELECT p.id, COUNT(aa.id) AS n
       FROM {local_catquizlab_person} p
       JOIN {local_catquizlab_run} r ON r.id = p.runid
       JOIN {course_modules} cm ON cm.id = r.testcmid
       JOIN {adaptivequiz_attempt} aa ON aa.userid = p.moodleuserid AND aa.instance = cm.instance
      WHERE r.experimentid = :experimentid AND aa.attemptstate = :complete
   GROUP BY p.id
     HAVING COUNT(aa.id) > 1",
    ['experimentid' => $experimentid, 'complete' => 'complete']
);
if ($doubles !== []) {
    $problems[] = count($doubles) . ' simulated person(s) with more than one finished engine attempt';
}
// The history of what the stop closed: cancelled, then resumed or collected after the stop.
foreach ($closed as $id) {
    $outcomes = $DB->get_fieldset_select('local_catquizlab_attemptlog', 'outcome', 'attemptid = ? ORDER BY id', [$id]);
    $cancelledat = array_search(attempt_history::CANCELLED, $outcomes, true);
    $resumedat = array_search(attempt_history::RESUMED, $outcomes, true);
    if ($cancelledat === false || $resumedat === false || $resumedat < $cancelledat) {
        $problems[] = "sitting {$id}: history " . implode(' → ', $outcomes);
    }
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
$say(sprintf(
    'end: %s, %d/%d sittings collected (%d kept from before the stop, %d closed by it and played or collected after),'
        . ' %d result rows, line %s',
    $runner['state'],
    $collected,
    $planned,
    count($keep),
    count($closed),
    $results,
    $entry ? $entry->state : '-'
));
foreach ($problems as $problem) {
    cli_writeln('  FAIL: ' . $problem);
}
cli_writeln($problems === [] ? 'PASS: stopped, resumed, finished; nothing collected twice, nothing lost.' : 'FAIL');
exit($problems === [] ? 0 : 3);
