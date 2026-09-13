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
 * Operations: system health, workers, the attempt queue and recovery.
 *
 * Everything here was previously only answerable over SSH and a database
 * client: whether a worker is ready, whether one is running, how many attempts
 * wait, which one is stuck and since when, and whether the pipeline is blocked
 * or merely slow. A plugin whose normal operation requires a shell is not
 * finished, however complete its features are.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\system_health;
use local_catquizlab\local\worker_launcher;
use local_catquizlab\local\worker_registry;

$action = optional_param('action', '', PARAM_ALPHA);
$runid = optional_param('runid', 0, PARAM_INT);

admin_externalpage_setup(registry::ADMIN_PAGE);

$component = 'local_catquizlab';
$context = context_system::instance();
$pageurl = new moodle_url('/local/catquizlab/operations.php');
$PAGE->set_url($pageurl);

if ($action !== '') {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    if ($action === 'createtoken') {
        $created = \local_catquizlab\local\worker_setup::ensure_token();
        redirect(
            $pageurl,
            get_string($created ? 'ops:tokencreated' : 'ops:tokenexists', $component),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'installbrowser') {
        $result = worker_launcher::install_browser(worker_launcher::config_from_settings());
        $SESSION->catquizlab_selftest = $result;
        redirect($pageurl);
    }

    if ($action === 'selftest') {
        $result = worker_launcher::self_test(worker_launcher::config_from_settings());
        $SESSION->catquizlab_selftest = $result;
        redirect($pageurl);
    }

    if ($action === 'reap') {
        $reaped = worker_registry::reap();
        redirect(
            $pageurl,
            $reaped['workers'] > 0
                ? get_string('ops:reaped', $component, (object) $reaped)
                : get_string('ops:nothingtoreap', $component)
        );
    }

    if ($action === 'releaseorphans') {
        // The deliberate counterpart to the scheduled recovery: an operator who
        // knows the workers are gone should not have to wait out a timeout.
        $released = attempt_scheduler::reclaim_stale(null, 0);
        redirect(
            $pageurl,
            $released > 0
                ? get_string('ops:orphansreleased', $component, $released)
                : get_string('ops:noorphans', $component)
        );
    }

    if ($action === 'startworkers') {
        $result = worker_launcher::launch_pool(worker_launcher::config_from_settings());
        $launched = (int) ($result['launched'] ?? 0);
        redirect(
            $pageurl,
            $launched > 0
                ? get_string('ops:workersstarted', $component, $launched)
                : get_string('ops:workersnotstarted', $component, $result['reason'] ?? 'not-configured'),
            null,
            $launched > 0
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING
        );
    }

    if (($action === 'pauserun' || $action === 'resumerun') && $runid > 0) {
        $paused = $action === 'pauserun';
        run_lifecycle::set_paused($runid, $paused);
        redirect(
            $pageurl,
            get_string($paused ? 'ops:runpaused' : 'ops:runresumed', $component, $runid)
        );
    }
}

$health = system_health::health();

$workers = array_map(static function (\stdClass $worker): array {
    return [
        'workerid'  => $worker->workerid,
        'slot'      => (int) $worker->slot,
        'jobsdone'  => (int) $worker->jobsdone,
        'heartbeat' => userdate((int) $worker->heartbeat, get_string('strftimedatetimeshort')),
        'lasterror' => $worker->lasterror,
    ];
}, worker_registry::live());

$queue = [
    'queued'    => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_QUEUED]),
    'running'   => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_RUNNING]),
    'collected' => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_COLLECTED]),
    'failed'    => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_FAILED]),
];

// The claims held longest: "which attempt is stuck, and since when" was one of
// the questions that needed a database client.
$stuck = array_values(array_map(static function (\stdClass $row): array {
    return [
        'attemptid' => (int) $row->id,
        'runid'     => (int) $row->runid,
        'owner'     => $row->leaseowner,
        'tries'     => (int) $row->tries,
        'since'     => format_time(time() - (int) $row->timemodified),
        'lasterror' => $row->lasterror,
    ];
}, $DB->get_records(
    'local_catquizlab_attempt',
    ['status' => attempt_scheduler::STATUS_RUNNING],
    'timemodified ASC',
    'id, runid, leaseowner, tries, timemodified, lasterror',
    0,
    10
)));

$inflight = array_values(array_map(static function (\stdClass $run) use ($component, $pageurl): array {
    $counts = run_lifecycle::attempt_counts((int) $run->id);
    $paused = run_lifecycle::is_paused((int) $run->id);

    return [
        'runid'     => (int) $run->id,
        'cellkey'   => $run->cellkey,
        'status'    => \local_catquizlab\local\run_registry::status_label((int) $run->status),
        'total'     => $counts['total'],
        'open'      => $counts['open'],
        'collected' => $counts['collected'],
        'failed'    => $counts['failed'],
        'paused'    => $paused,
        'pauseurl'  => (new moodle_url($pageurl, [
            'action'  => $paused ? 'resumerun' : 'pauserun',
            'runid'   => (int) $run->id,
            'sesskey' => sesskey(),
        ]))->out(false),
    ];
}, $DB->get_records_select(
    'local_catquizlab_run',
    'status IN (:scheduled, :ready, :running, :aggregating)',
    [
        'scheduled'   => registry::STATUS_SCHEDULED,
        'ready'       => registry::STATUS_READY,
        'running'     => registry::STATUS_RUNNING,
        'aggregating' => registry::STATUS_AGGREGATING,
    ],
    'id ASC',
    'id, cellkey, status',
    0,
    20
)));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ops:heading', $component));

echo $OUTPUT->render_from_template('local_catquizlab/operations', [
    'health'     => $health,
    'workers'    => ['hasany' => $workers !== [], 'rows' => $workers],
    'queue'      => $queue,
    'stuck'      => ['hasany' => $stuck !== [], 'rows' => $stuck],
    'inflight'   => ['hasany' => $inflight !== [], 'rows' => $inflight],
    'sesskey'    => sesskey(),
    'actionurl'  => $pageurl->out(false),
    'cantoken'   => system_health::worker_token() === null,
    // Kept for one page load: a self-test result is worth reading once, and
    // storing it would turn a diagnostic into state to maintain.
    'selftest'   => (static function () {
        global $SESSION;
        $result = $SESSION->catquizlab_selftest ?? null;
        unset($SESSION->catquizlab_selftest);
        if ($result === null) {
            return null;
        }

        return [
            'ok'      => (int) $result['exitcode'] === 0,
            'output'  => $result['output'],
            'command' => $result['command'],
        ];
    })(),
]);

echo $OUTPUT->footer();
