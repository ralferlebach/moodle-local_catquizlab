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
 * Run overview, run detail and run actions.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_catquizlab\local\registry;
use local_catquizlab\local\run_registry;

$runid = optional_param('runid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Recorded where the action is known and before it is carried out, so a
// defect reads as a sequence rather than as fragments in five logs.
if ($action !== '') {
    \local_catquizlab\local\debug_trace::record(
        \local_catquizlab\local\debug_trace::UI,
        $action,
        array_diff_key($_REQUEST, array_flip(['sesskey'])),
        'ok',
        [],
        (int) ($runid ?? 0)
    );
}
$page = optional_param('page', 0, PARAM_INT);

$filters = [];
foreach (['experimentid', 'replication'] as $key) {
    $value = optional_param($key, 0, PARAM_INT);
    if ($value > 0) {
        $filters[$key] = $value;
    }
}
$status = optional_param('status', '', PARAM_RAW_TRIMMED);
if ($status !== '') {
    $filters['status'] = (int) $status;
}
foreach (['strategy', 'model', 'variant', 'stratum', 'severity'] as $key) {
    $value = optional_param($key, '', PARAM_ALPHANUMEXT);
    if ($value !== '') {
        $filters[$key] = $value;
    }
}

admin_externalpage_setup('local_catquizlab_manage');

$context = context_system::instance();
$component = 'local_catquizlab';
$baseurl = new moodle_url('/local/catquizlab/runs.php', $filters);
$PAGE->set_url($baseurl);

require_capability('local/catquizlab:view', $context);

// State-changing actions. Each is a POST guarded by sesskey and the execute
// capability, so a link in a mail cannot cancel somebody's sweep.
// Experiment-level actions, before the run-scoped block below. They carry an
// experiment id and no run id, so inside that block they were unreachable:
// the button rendered, the form posted, the page came back without a word,
// and the experiment stayed a draft with no runs.
if ($action === 'prepareexperiment') {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    $target = required_param('experimentid', PARAM_INT);
    $result = \local_catquizlab\local\experiment_runner::prepare($target);

    $back = new moodle_url('/local/catquizlab/runs.php', ['experimentid' => $target]);
    $message = get_string(
        $result['ok'] ? 'runner:prepared' : 'runner:blocked',
        $component,
        (object) ['prepared' => $result['prepared'], 'total' => $result['total']]
    );

    foreach (array_slice($result['blockers'], 0, 3) as $blocker) {
        $message .= html_writer::empty_tag('br') . get_string('runner:blockerline', $component, (object) [
            'runid'   => $blocker['runid'] ?? 0,
            'cellkey' => $blocker['cellkey'] ?? '',
            'stage'   => $blocker['stage'] ?? '',
            'reason'  => $blocker['reason'] ?? '',
        ]);
    }

    redirect($back, $message, null, $result['ok']
        ? \core\output\notification::NOTIFY_SUCCESS
        : \core\output\notification::NOTIFY_WARNING);
}

if ($action === 'startexperiment') {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    $target = required_param('experimentid', PARAM_INT);
    $queued = \local_catquizlab\local\execution_queue::enqueue($target);

    $back = new moodle_url('/local/catquizlab/runs.php', ['experimentid' => $target]);

    redirect(
        $back,
        $queued['ok']
            ? get_string('runner:queuedat', $component, $queued['position'])
            : get_string('runner:notready', $component),
        null,
        $queued['ok']
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_WARNING
    );
}

// Every held run at once. The cause behind the reported installation's held
// runs — starting abilities seeded at 0.0 — is removed by the reset itself, so
// eight runs held for the same reason need one press, not eight.
if ($action === 'resetallheld') {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    $released = 0;
    $runs = 0;
    foreach ($DB->get_fieldset_select('local_catquizlab_run', 'id', 'status = ?', [registry::STATUS_FAILED]) as $heldid) {
        $reset = \local_catquizlab\local\circuit_breaker::reset_and_continue((int) $heldid);
        $released += (int) $reset['released'];
        $runs++;
    }

    redirect(
        new moodle_url('/local/catquizlab/runs.php'),
        get_string('circuit:resetall', $component, (object) ['runs' => $runs, 'released' => $released]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// An ad-hoc task that is waiting out a failure delay, tried again now. Moodle
// backs a failed task off for up to a day; on an installation somebody is
// watching, waiting a day to find out whether the fix worked is the wrong unit
// of time. Core's own field, cleared the way core clears it.
if ($action === 'requeuetask' && optional_param('taskid', 0, PARAM_INT) > 0) {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    $taskid = required_param('taskid', PARAM_INT);
    $record = $DB->get_record('task_adhoc', ['id' => $taskid]);

    if ($record && strpos((string) $record->classname, 'local_catquizlab') !== false) {
        $DB->update_record('task_adhoc', (object) [
            'id'          => $taskid,
            'faildelay'   => 0,
            'nextruntime' => time(),
        ]);
        $message = get_string('task:requeued', $component);
        $level = \core\output\notification::NOTIFY_SUCCESS;
    } else {
        $message = get_string('task:notours', $component);
        $level = \core\output\notification::NOTIFY_WARNING;
    }

    redirect(new moodle_url('/local/catquizlab/runs.php'), $message, null, $level);
}

// Debug artefacts as a ZIP (#107): one sitting, a whole run, or a run's failed
// sittings. Only for those allowed to see debug material: screenshots and page
// snapshots show what a simulated person saw, and a run's design.
if ($action === 'artefactzip' && $runid > 0) {
    require_sesskey();

    $scope = optional_param('scope', \local_catquizlab\local\artefact_store::SCOPE_RUN, PARAM_ALPHA);
    $zip = \local_catquizlab\local\artefact_store::zip_for_download(
        $scope,
        $runid,
        optional_param('attemptid', 0, PARAM_INT),
        $context
    );
    if ($zip === null) {
        redirect(
            new moodle_url('/local/catquizlab/runs.php', ['runid' => $runid]),
            get_string('artefacts:none', $component),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }
    send_file($zip, basename($zip), 0, 0, false, true, 'application/zip');
    die();
}

// One sitting, not the whole run. The recovery a person actually wants when
// they are looking at the row that failed.
if ($action === 'requeueattempt' && optional_param('attemptid', 0, PARAM_INT) > 0) {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    $attemptid = required_param('attemptid', PARAM_INT);
    $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);

    if ($attempt) {
        \local_catquizlab\local\attempt_history::record(
            $attemptid,
            \local_catquizlab\local\attempt_history::REQUEUED,
            ['detail' => (string) $attempt->lasterror]
        );
        $DB->update_record('local_catquizlab_attempt', (object) [
            'id' => $attemptid,
            'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED,
            'tries' => 0, 'nextruntime' => 0, 'lasterror' => null,
            'leaseowner' => null, 'leaseexpires' => 0, 'timemodified' => time(),
        ]);
        \local_catquizlab\local\run_lifecycle::reopen_for_work((int) $attempt->runid);
    }

    redirect(
        new moodle_url('/local/catquizlab/runs.php', ['runid' => (int) ($attempt->runid ?? 0)]),
        get_string('attempt:requeued', $component, $attemptid),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Every incomplete sitting of the run (#98): failed, stuck on an expired lease,
// or waiting out a delay. Collected sittings are never touched, and a sitting
// that keeps failing the same way is left for a deliberate single retry.
if ($action === 'requeueincomplete' && $runid > 0) {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    $done = \local_catquizlab\local\run_lifecycle::requeue_incomplete($runid);

    redirect(
        new moodle_url('/local/catquizlab/runs.php', ['runid' => $runid]),
        get_string('attempt:requeuedincomplete', $component, (object) $done),
        null,
        $done['skipped'] > 0
            ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_SUCCESS
    );
}

// The sittings that gave up, once more. Not the same as continuing a held run:
// this run was never held, it simply has sittings that failed three times and
// will otherwise never reach its planned number.
if ($action === 'requeuefailed' && $runid > 0) {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    $requeued = \local_catquizlab\local\run_lifecycle::requeue_failed($runid);

    redirect(
        new moodle_url('/local/catquizlab/runs.php', ['runid' => $runid]),
        $requeued > 0
            ? get_string('run:requeued', $component, $requeued)
            : get_string('run:nothingtorequeue', $component),
        null,
        $requeued > 0
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_INFO
    );
}

// Letting a held run try again, after somebody has dealt with the cause. Not
// offered automatically and not retried in a loop: the whole point of holding a
// run is that repeating it without a change repeats the failure.
if ($action === 'resetcircuit' && $runid > 0) {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    $reset = \local_catquizlab\local\circuit_breaker::reset_and_continue($runid);

    redirect(
        new moodle_url('/local/catquizlab/runs.php', ['runid' => $runid]),
        get_string('circuit:reset', $component, $reset['released']),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action !== '' && $runid > 0) {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
    $returnurl = new moodle_url('/local/catquizlab/runs.php', ['runid' => $runid]);

    if ($action === 'delete' || $action === 'deletedeep') {
        require_sesskey();
        require_capability('local/catquizlab:execute', $context);

        $deep = $action === 'deletedeep';
        $force = optional_param('force', 0, PARAM_BOOL);

        if (!optional_param('confirm', 0, PARAM_BOOL)) {
            // Irreversible, so it is confirmed against the run it names rather
            // than with a general "are you sure".
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(
                get_string($deep ? 'purge:confirmrundeep' : 'purge:confirmrun', $component, $runid),
                new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => $action, 'sesskey' => sesskey(),
                    'confirm' => 1, 'force' => $force,
                ]),
                $returnurl
            );
            echo $OUTPUT->footer();
            exit;
        }

        $result = \local_catquizlab\local\purger::delete_run($runid, $deep, (bool) $force);
        if (!$result['ok']) {
            redirect(
                $returnurl,
                get_string('purge:refused', $component, $result['reason']),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        $parts = [];
        $parts[] = \local_catquizlab\local\purger::counts_line($result['removed']);

        redirect(
            new moodle_url('/local/catquizlab/runs.php'),
            $parts === []
                ? get_string('purge:nothing', $component)
                : get_string('purge:done', $component, implode(', ', $parts))
        );
    }


    if ($action === 'repairaccess') {
        require_sesskey();
        require_capability('local/catquizlab:execute', $context);

        $result = \local_catquizlab\local\access_readiness::repair($runid);

        redirect(
            $returnurl,
            $result['fixed'] === []
                ? get_string('access:repairnothing', $component)
                : get_string('access:repaired', $component, implode(', ', $result['fixed'])),
            null,
            $result['ok']
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING
        );
    }

    if ($action === 'cleanscales') {
        require_sesskey();
        require_capability('local/catquizlab:execute', $context);

        $result = \local_catquizlab\local\scale_inventory::cleanup($runid);
        if (!$result['ok']) {
            redirect(
                $returnurl,
                $result['reason'] === 'nothing-to-clean'
                    ? get_string('scales:cleanupnothing', $component)
                    : get_string('scales:cleanuprefused', $component, $result['reason']),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        $parts = [];
        $parts[] = \local_catquizlab\local\purger::counts_line($result['removed']);

        redirect($returnurl, get_string('scales:cleanupdone', $component, implode(', ', $parts)));
    }

    if ($action === 'resetrerun') {
        require_sesskey();
        require_capability('local/catquizlab:execute', $context);

        if (!optional_param('confirm', 0, PARAM_BOOL)) {
            echo $OUTPUT->header();
            echo \local_catquizlab\output\shell::render('progress', 0);
            echo $OUTPUT->confirm(
                \local_catquizlab\local\run_lifecycle::reset_preview_message($runid, $component),
                new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => 'resetrerun', 'sesskey' => sesskey(), 'confirm' => 1,
                ]),
                $returnurl
            );
            echo $OUTPUT->footer();
            exit;
        }

        $result = \local_catquizlab\local\run_lifecycle::reset_and_rerun($runid);

        $parts = [];
        $parts[] = \local_catquizlab\local\purger::counts_line($result['removed']);

        redirect(
            $returnurl,
            $result['ok']
                ? get_string('run:resetrerun', $component, implode(', ', $parts) ?: '-')
                : get_string('run:resetrefused', $component, $result['reason'] ?: '-'),
            null,
            $result['ok']
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING
        );
    }

    if ($action === 'reset') {
        require_sesskey();
        require_capability('local/catquizlab:execute', $context);

        $result = \local_catquizlab\local\run_lifecycle::reset($runid);
        if (!$result['ok']) {
            redirect(
                $returnurl,
                get_string('run:resetrefused', $component, $result['reason']),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        $parts = [];
        $parts[] = \local_catquizlab\local\purger::counts_line($result['removed']);

        redirect($returnurl, get_string('run:reset', $component, implode(', ', $parts) ?: '-'));
    }

    if ($action === 'provision') {
        require_sesskey();
        require_capability('local/catquizlab:execute', $context);

        // Runs the orchestrator in this request rather than queueing it again:
        // the run is already waiting for a task, and queueing a second is how
        // somebody ends up with two.
        \core\session\manager::write_close();
        $result = \local_catquizlab\local\run_lifecycle::provision_now($runid);

        redirect(
            $returnurl,
            $result['ok']
                ? get_string('run:provisioned', $component)
                : get_string('run:provisionfailed', $component, $result['reason'] ?: '-'),
            null,
            $result['ok']
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING
        );
    }

    if ($action === 'recheck') {
        require_sesskey();
        require_capability('local/catquizlab:execute', $context);

        $result = \local_catquizlab\local\run_lifecycle::recheck($runid);
        redirect(
            $returnurl,
            $result['ok']
                ? get_string('run:rechecked', $component, $result['requeued'])
                : get_string('run:recheckfailed', $component, $result['reason']),
            null,
            $result['ok']
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING
        );
    }

    if ($action === 'start') {
        require_sesskey();
        require_capability('local/catquizlab:execute', $context);

        // The interface asks the lifecycle to start the run; it does not
        // orchestrate anything itself. A second copy of that decision here is a
        // second thing to keep in step with the tasks.
        $result = \local_catquizlab\local\run_lifecycle::start($runid, [], $context);
        if (!$result['started']) {
            redirect(
                $returnurl,
                get_string('run:startblocked', $component, $result['reason']),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        redirect($returnurl, get_string('run:started', $component, 1));
    }

    if ($action === 'cancel') {
        if (!registry::allowed_actions((int) $run->status)['cancel']) {
            redirect(
                $returnurl,
                get_string('run:cannotcancel', $component),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        // Cancelled, not failed: one records a decision, the other a defect,
        // and a list where both look alike hides the defects among the
        // decisions.
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_CANCELLED, ['id' => $runid]);
        $DB->set_field('local_catquizlab_run', 'timemodified', time(), ['id' => $runid]);
        \local_catquizlab\event\run_aborted::create([
            'objectid' => $runid,
            'context'  => $context,
        ])->trigger();
        redirect(
            $returnurl,
            get_string('notice:runcancelled', $component),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'reproduce') {
        // A reproduction is a new run with the original configuration: a new
        // id, the same seeds. Rewriting the original would destroy the record
        // of what it did.
        if (!registry::allowed_actions((int) $run->status)['reproduce']) {
            redirect(
                $returnurl,
                get_string('run:cannotreproduce', $component),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        $newid = \local_catquizlab\local\run_lifecycle::reproduce((int) $run->id);
        redirect(
            new moodle_url('/local/catquizlab/runs.php', ['runid' => $newid]),
            get_string('notice:runreproduced', $component),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    redirect($returnurl);
}

echo $OUTPUT->header();

// The same frame as every other CatQuizLab page: opening a run used to drop
// the reader out of the process they were in the middle of.
echo \local_catquizlab\output\shell::render('progress', optional_param('experimentid', 0, PARAM_INT));

// Without a run named, this is step 3 itself: what is happening, why, or why it
// is not. The answer used to need four pages — runs here, tasks and workers on
// the operations page, the queue on a third, recovery on a fourth — and holding
// the pieces together was left to the reader.
if ($runid === 0) {
    // This is the page somebody watches while a run is playing, so it is the
    // page that has to keep itself current. It was the one page without the
    // updater.
    // The interrupted-updating message, so the module can show it without a
    // second round trip at the moment the round trips are failing.
    $PAGE->requires->strings_for_js(['live:interrupted', 'live:reconnect'], $component);
    $PAGE->requires->js_call_amd('local_catquizlab/livestatus', 'init', [
        \local_catquizlab\external\live_status::current_shape(),
        optional_param('experimentid', 0, PARAM_INT),
    ]);

    echo $OUTPUT->render_from_template(
        'local_catquizlab/progress',
        \local_catquizlab\local\progress_view::context(optional_param('experimentid', 0, PARAM_INT))
    );

    // And then the full, filterable list below it. The view above answers "what
    // is happening"; the list answers "show me the ones matching this", and
    // replacing the second with the first would have taken the filters away.
}

// A single run: its coordinates, manifest and metrics.
if ($runid > 0) {
    $detail = run_registry::detail($runid);
    $run = $detail['run'];

    echo $OUTPUT->heading(get_string('heading:rundetail', $component, $runid));

    if ($detail['failure'] !== null) {
        echo $OUTPUT->notification($detail['failure'], \core\output\notification::NOTIFY_ERROR);
    }

    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm w-auto';
    $table->head = [get_string('preview:quantity', $component), get_string('preview:value', $component)];
    $table->data = [
        [get_string('run:experiment', $component), s($run['experiment'])],
        [get_string('run:cellkey', $component), s($run['cellkey'])],
        [get_string('run:status', $component), s($run['statuslabel'])],
        [get_string('form:strategy', $component), s($run['strategylabel']) . ' (' . s($run['strategy']) . ')'],
        [get_string('form:model', $component), s($run['modellabel'])],
        [get_string('form:variant', $component), s(run_registry::group_label('variant', $run['variant']))],
        [get_string('form:stratum', $component), s(run_registry::group_label('stratum', $run['stratum']))],
        [get_string('form:severity', $component), s(run_registry::group_label('severity', $run['severity']))],
        [get_string('form:replications', $component), $run['replication']],
        [get_string('form:seed', $component), $run['masterseed']],
        [get_string('run:runseed', $component), $run['seed']],
        [get_string('run:progress', $component), $run['progress'] . '%'],
    ];
    echo html_writer::table($table);

    // The parameters this run was given, and what the engine holds for its
    // test (#104): read back from the engine, not repeated from the
    // definition. Where a strategy does not use a parameter, it says so.
    $effective = \local_catquizlab\local\test_provisioner::effective_parameters(
        \local_catquizlab\local\run_registry::definition_for($DB->get_record('local_catquizlab_run', ['id' => $runid]))
    );
    $engine = \local_catquizlab\local\provisioning_check::compare($runid);
    $na = \local_catquizlab\local\strategy_parameters::NEUTRALISED;
    $fmt = static function ($value): string {
        if ($value === -1) {
            return get_string('budget:unlimited', 'local_catquizlab');
        }
        return is_scalar($value) ? (string) $value : json_encode($value);
    };
    $pair = static function (string $field) use ($engine, $fmt): string {
        return isset($engine['checked'][$field]) ? $fmt($engine['checked'][$field]['actual']) : '—';
    };
    $defined = static function ($value) use ($fmt): string {
        return \local_catquizlab\local\experiment_definition::is_unlimited($value)
            ? get_string('budget:unlimited', 'local_catquizlab')
            : $fmt($value);
    };
    $subscale = $effective['budgets']['subscale'];
    $se = $effective['se'];
    $paramtable = new html_table();
    $paramtable->attributes['class'] = 'generaltable table-sm w-auto';
    $paramtable->attributes['data-region'] = 'catquizlab-effective-parameters';
    $paramtable->head = [
        get_string('effective:parameter', $component),
        get_string('effective:defined', $component),
        get_string('effective:engine', $component),
    ];
    $paramtable->data = [
        [get_string('form:strategy', $component),
            s($effective['strategy']['label']) . ' (' . (int) $effective['strategy']['engineid'] . ')', $pair('strategy')],
        [get_string('form:globalmin', $component), $defined($effective['budgets']['global']['minitems']), $pair('minquestions')],
        [get_string('form:globalmax', $component), $defined($effective['budgets']['global']['maxitems']), $pair('maxquestions')],
        [get_string('form:subscalemin', $component), is_array($subscale) ? $defined($subscale['minitems']) : $na,
            $pair('minquestionspersubscale')],
        [get_string('form:subscalemax', $component), is_array($subscale) ? $defined($subscale['maxitems']) : $na,
            $pair('maxquestionspersubscale')],
        [get_string('form:semin', $component), is_array($se) ? $defined($se['min']) : $na, $pair('se_min')],
        [get_string('form:semax', $component), is_array($se) ? $defined($se['max']) : $na, $pair('se_max')],
    ];
    echo $OUTPUT->heading(get_string('effective:heading', $component), 4);
    echo html_writer::table($paramtable);
    if ($engine['checked'] !== []) {
        echo $OUTPUT->notification(
            $engine['ok'] ? get_string('effective:match', $component) : implode(' ', $engine['differences']),
            $engine['ok'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
        );
    }

    // One row per sitting that did not simply work: what was tried, by which
    // worker, how it ended, and what to do about it. Only the interesting
    // ones — a run of a thousand collected sittings has nothing to say here.
    // Which sittings, by what is wrong with them (#98). "problems" is the
    // default: failed, retried, or with an error on record.
    $show = optional_param('show', 'problems', PARAM_ALPHA);
    $reason = optional_param('reason', '', PARAM_ALPHANUMEXT);
    $scheduler = \local_catquizlab\local\attempt_scheduler::class;
    $filters = [
        // Ever failed or put back, read from the history: the sitting's own
        // row is cleared by the retry, and a filter on that row alone hid the
        // very sittings whose history the page exists to show.
        'problems'   => ['runid = :runid AND (status = :failed OR tries > 1 OR lasterror IS NOT NULL'
            . ' OR id IN (SELECT attemptid FROM {local_catquizlab_attemptlog}'
            . ' WHERE runid = :logrunid AND outcome IN (:logfailed, :logrequeued)))', []],
        'failed'     => ['runid = :runid AND status = :failed', []],
        'retrydelay' => ['runid = :runid AND status = :queued AND nextruntime > :now', []],
        'running'    => ['runid = :runid AND status = :running', []],
        'stale'      => ['runid = :runid AND status = :running AND leaseexpires < :now', []],
        'queued'     => ['runid = :runid AND status = :queued', []],
        'noengine'   => ['runid = :runid AND status IN (:collected, :failed)'
            . ' AND (engineattemptid IS NULL OR engineattemptid = 0)', []],
        'notrace'    => ['runid = :runid AND status = :collected AND tracejson IS NULL', []],
    ];
    if (!isset($filters[$show])) {
        $show = 'problems';
    }
    $filterparams = [
        'runid'     => $runid,
        'failed'    => $scheduler::STATUS_FAILED,
        'queued'    => $scheduler::STATUS_QUEUED,
        'running'   => $scheduler::STATUS_RUNNING,
        'collected' => $scheduler::STATUS_COLLECTED,
        'now'       => time(),
        'logrunid'    => $runid,
        'logfailed'   => \local_catquizlab\local\attempt_history::FAILED,
        'logrequeued' => \local_catquizlab\local\attempt_history::REQUEUED,
    ];
    // Only the placeholders each filter uses.
    preg_match_all('/:([a-z]+)/', $filters[$show][0], $used);
    $filterparams = array_intersect_key($filterparams, array_flip($used[1]));

    $choices = [];
    foreach (array_keys($filters) as $key) {
        $choices[] = $key === $show
            ? html_writer::tag('strong', get_string('attemptfilter:' . $key, $component))
            : html_writer::link(
                new moodle_url('/local/catquizlab/runs.php', ['runid' => $runid, 'show' => $key]),
                get_string('attemptfilter:' . $key, $component)
            );
    }

    // Or by the normalised reason an execution ended with (#107).
    $where = $filters[$show][0];
    if ($reason !== '') {
        $where = 'runid = :runid AND id IN (SELECT attemptid FROM {local_catquizlab_attemptlog}'
            . ' WHERE runid = :reasonrunid AND reasoncode = :reason)';
        $filterparams = ['runid' => $runid, 'reasonrunid' => $runid, 'reason' => $reason];
    }
    $codes = $DB->get_records_sql(
        'SELECT reasoncode, COUNT(1) AS n FROM {local_catquizlab_attemptlog}
          WHERE runid = :runid AND reasoncode IS NOT NULL GROUP BY reasoncode ORDER BY reasoncode',
        ['runid' => $runid]
    );
    $reasonchoices = [];
    foreach ($codes as $code) {
        $label = \local_catquizlab\local\reason_catalog::label((string) $code->reasoncode) . ' (' . (int) $code->n . ')';
        $reasonchoices[] = $code->reasoncode === $reason
            ? html_writer::tag('strong', s($label))
            : html_writer::link(
                new moodle_url('/local/catquizlab/runs.php', ['runid' => $runid, 'reason' => $code->reasoncode]),
                s($label),
                ['data-reason' => $code->reasoncode]
            );
    }

    $troubled = $DB->get_records_select(
        'local_catquizlab_attempt',
        $where,
        $filterparams,
        'id ASC',
        'id, personid, status, tries, engineattemptid, leaseowner, lasterror, timemodified',
        0,
        200
    );

    echo $OUTPUT->heading(get_string('attempt:diagnostics', $component), 4);
    echo html_writer::tag('p', get_string('attempt:diagnosticsexplain', $component), ['class' => 'text-muted']);
    echo html_writer::tag('p', implode(' · ', $choices), ['class' => 'small']);
    if ($reasonchoices !== []) {
        echo html_writer::tag(
            'p',
            get_string('reason:filter', $component) . ': ' . implode(' · ', $reasonchoices),
            ['class' => 'small', 'data-region' => 'catquizlab-reasons']
        );
    }
    $candebug = has_capability('local/catquizlab:debug', $context);
    if ($candebug) {
        $zipbutton = static function (string $scope, string $label, int $attemptid = 0) use ($runid): string {
            global $OUTPUT;
            return $OUTPUT->single_button(new moodle_url('/local/catquizlab/runs.php', [
                'runid' => $runid, 'action' => 'artefactzip', 'scope' => $scope,
                'attemptid' => $attemptid, 'sesskey' => sesskey(),
            ]), $label, 'post');
        };
        echo html_writer::div(
            $zipbutton(\local_catquizlab\local\artefact_store::SCOPE_RUN, get_string('artefacts:ziprun', $component))
            . $zipbutton(\local_catquizlab\local\artefact_store::SCOPE_FAILED, get_string('artefacts:zipfailed', $component)),
            'd-flex flex-wrap mb-2'
        );
    }

    echo html_writer::tag(
        'form',
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'requeueincomplete'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'runid', 'value' => $runid])
        . html_writer::tag('button', get_string('attempt:requeueincomplete', $component), [
            'type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary mb-2',
        ]),
        ['method' => 'post', 'action' => (new moodle_url('/local/catquizlab/runs.php'))->out(false)]
    );

    if ($troubled === []) {
        echo html_writer::tag('p', get_string('attemptfilter:none', $component), ['class' => 'text-muted small']);
    } else {
        $attempttable = new html_table();
        $attempttable->attributes['class'] = 'generaltable table-sm';
        $attempttable->head = [
            get_string('attempt:id', $component),
            get_string('attempt:person', $component),
            get_string('attempt:status', $component),
            get_string('attempt:tries', $component),
            get_string('attempt:engineattempt', $component),
            get_string('attempt:worker', $component),
            get_string('attempt:when', $component),
            get_string('attempt:error', $component),
            '',
        ];

        foreach ($troubled as $row) {
            $history = \local_catquizlab\local\attempt_history::of_attempt((int) $row->id);
            $error = \local_catquizlab\local\attempt_history::redact((string) ($row->lasterror ?? ''));
            if ($error === '') {
                // Cleared by a retry; the history still has it.
                $error = \local_catquizlab\local\attempt_history::last_failure((int) $row->id);
            }

            $lines = '';
            foreach ($history as $entry) {
                // The diagnosis in its fields where it could be taken apart
                // (#98); the reported text only where it could not.
                $facts = [];
                foreach (['phase', 'slot', 'page', 'errorcode', 'exception', 'error'] as $field) {
                    if (isset($entry['diagnosis'][$field]) && $entry['diagnosis'][$field] !== '') {
                        $facts[] = get_string('diagnosis:' . $field, $component) . ': '
                            . s((string) $entry['diagnosis'][$field]);
                    }
                }
                if (isset($entry['diagnosis']['file'])) {
                    $facts[] = s($entry['diagnosis']['file'] . ':' . ($entry['diagnosis']['line'] ?? '?'));
                }
                // The transport failure in its fields, and the browser's last
                // events before the failure (#100).
                if (!empty($entry['diagnosis']['transport']['wsfunction'])) {
                    $t = $entry['diagnosis']['transport'];
                    $facts[] = get_string('diagnosis:transport', $component) . ': ' . s(trim(
                        $t['wsfunction'] . ' ' . ($t['status'] ? 'HTTP ' . $t['status'] . ' ' : '')
                        . ($t['code'] ?? '') . ' ' . ($t['elapsedms'] ?? '') . ' ms'
                    ));
                }
                $browserevents = array_slice((array) ($entry['diagnosis']['browser']['events'] ?? []), -6);
                foreach ($browserevents as $event) {
                    $facts[] = s(($event['type'] ?? '') . ': ' . \core_text::substr((string) ($event['detail'] ?? ''), 0, 160));
                }
                if (!empty($entry['diagnosis']['artefacts']['path'])) {
                    $facts[] = get_string('diagnosis:artefacts', $component) . ': '
                        . s($entry['diagnosis']['artefacts']['path']) . ' ('
                        . s(implode(', ', (array) ($entry['diagnosis']['artefacts']['files'] ?? []))) . ')';
                }
                $text = $facts !== []
                    ? implode(' · ', $facts)
                    : s(\core_text::substr($entry['detail'], 0, 400));

                $link = '';
                if ($entry['correlationid'] !== '') {
                    $link = ' ' . html_writer::link(
                        new moodle_url('/local/catquizlab/logs.php', [
                            'correlationid' => $entry['correlationid'],
                            'hours'         => 0,
                        ]),
                        get_string('attempt:logofthis', $component)
                    );
                }

                $lines .= html_writer::tag(
                    'li',
                    get_string('attempt:historyline', $component, (object) [
                        'try'     => $entry['tryno'],
                        'outcome' => $entry['outcomelabel'],
                        'when'    => $entry['when'],
                    ]) . ($entry['reasonlabel'] !== ''
                        ? ' ' . html_writer::tag('span', s($entry['reasonlabel']), [
                            'class' => 'badge badge-secondary', 'data-reason' => $entry['reasoncode'],
                        ])
                        : '') . $link
                    . ($text !== '' ? html_writer::tag('div', $text, ['class' => 'text-muted']) : ''),
                    ['class' => $entry['failed'] ? 'text-danger' : '']
                );
            }

            // Which artefacts exist for this sitting, and when they were written.
            $artefacts = '';
            foreach (\local_catquizlab\local\artefact_store::list_for_attempt((int) $row->id) as $execution) {
                $names = array_map(static fn(array $f): string => $f['name'], $execution['files']);
                $written = max(array_map(static fn(array $f): int => (int) $f['time'], $execution['files']) ?: [0]);
                $artefacts .= html_writer::div(get_string('artefacts:line', $component, (object) [
                    'execution' => $execution['execution'],
                    'files'     => implode(', ', $names),
                    'written'   => $written > 0 ? userdate($written) : '—',
                ]), 'small text-muted', ['data-region' => 'catquizlab-artefacts']);
            }
            if ($artefacts !== '') {
                $lines .= html_writer::tag('li', $artefacts);
            }

            $action = '';
            if ($artefacts !== '' && has_capability('local/catquizlab:debug', $context)) {
                $action .= $OUTPUT->single_button(new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => 'artefactzip',
                    'scope' => \local_catquizlab\local\artefact_store::SCOPE_ATTEMPT,
                    'attemptid' => (int) $row->id, 'sesskey' => sesskey(),
                ]), get_string('artefacts:zipattempt', $component), 'post');
            }
            if ((int) $row->status === \local_catquizlab\local\attempt_scheduler::STATUS_FAILED) {
                $action .= html_writer::tag(
                    'form',
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'requeueattempt'])
                    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $row->id])
                    . html_writer::tag('button', get_string('attempt:requeueone', $component), [
                        'type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary',
                    ]),
                    ['method' => 'post', 'action' => (new moodle_url('/local/catquizlab/runs.php'))->out(false)]
                );
            }

            // Straight into the log, filtered to this sitting (#98): one click
            // from "this failed" to everything that was recorded about it.
            $logurl = new moodle_url('/local/catquizlab/logs.php', [
                'runid'     => $runid,
                // The sitting, under its own name since 0.7.13: "attemptno" is
                // the lifecycle attempt of a run, and would find nothing here.
                'attemptid' => (int) $row->id,
                'hours'     => 0,
            ]);

            $attempttable->data[] = [
                html_writer::link($logurl, $row->id, ['title' => get_string('attempt:openlog', $component)]),
                $row->personid,
                \local_catquizlab\local\attempt_scheduler::status_label((int) $row->status),
                $row->tries,
                $row->engineattemptid ?: '-',
                $row->leaseowner ?: '-',
                userdate((int) $row->timemodified),
                ($error !== '' ? html_writer::tag('div', s(\core_text::substr($error, 0, 300))) : '')
                    . ($lines !== '' ? html_writer::tag('ul', $lines, ['class' => 'small mb-0 pl-3']) : ''),
                $action,
            ];
        }

        echo html_writer::table($attempttable);
    }

    // Whether the simulated person can reach the test at all. An access failure
    // must never surface as a missing question: the two need completely
    // different responses, and only one of them is about the test.
    $access = \local_catquizlab\local\access_readiness::check($runid);
    if ($access['checks'] !== []) {
        echo $OUTPUT->heading(get_string('access:heading', $component), 4);
        echo $OUTPUT->notification(
            $access['summary'],
            $access['ok']
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_ERROR
        );

        if (!$access['ok']) {
            $accesstable = new html_table();
            foreach ($access['checks'] as $check) {
                $accesstable->data[] = [
                    $check['ok'] ? '&check;' : '&times;',
                    s($check['label']),
                    s($check['detail']),
                ];
            }
            echo html_writer::table($accesstable);

            echo $OUTPUT->single_button(
                new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => 'repairaccess', 'sesskey' => sesskey(),
                ]),
                get_string('access:repair', $component),
                'post'
            );
        }
    }

    // Whether the tree is sound, in the plugin's own terms. The old failure
    // was a database warning about a call; this is a statement about the run.
    $scalehealth = \local_catquizlab\local\scale_health::check($runid);
    if (!$scalehealth['ok'] || count($scalehealth['checks']) > 1) {
        echo $OUTPUT->heading(get_string('scalehealth:heading', $component), 4);
        echo $OUTPUT->notification(
            $scalehealth['summary'],
            $scalehealth['ok']
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_ERROR
        );

        $healthtable = new html_table();
        foreach ($scalehealth['checks'] as $check) {
            $healthtable->data[] = [
                $check['ok'] ? '&check;' : '&times;',
                s($check['label']),
                s($check['detail']),
            ];
        }
        echo html_writer::table($healthtable);
    }

    // The scale generations this run owns. One is the normal case and says
    // nothing; several is a data defect worth naming here, where somebody is
    // looking at the run it affects.
    $generations = \local_catquizlab\local\scale_inventory::generations($runid);
    if (count($generations) > 1) {
        echo $OUTPUT->heading(get_string('scales:heading', $component), 4);

        $scaletable = new html_table();
        $scaletable->head = ['Root', 'Context', get_string('scales:generations', $component), ''];
        foreach ($generations as $generation) {
            $scaletable->data[] = [
                $generation['rootscaleid'],
                $generation['contextid'],
                $generation['nodes'] . ' / ' . $generation['items'],
                $generation['current']
                    ? html_writer::tag('strong', get_string('scales:keep', $component))
                    : html_writer::tag(
                        'span',
                        get_string('scales:stale', $component),
                        ['class' => 'text-muted']
                    ),
            ];
        }
        echo html_writer::table($scaletable);

        echo $OUTPUT->single_button(
            new moodle_url('/local/catquizlab/runs.php', [
                'runid' => $runid, 'action' => 'cleanscales', 'sesskey' => sesskey(),
            ]),
            get_string('scales:cleanup', $component),
            'post'
        );
    }

    // What actually happened, kept across resets. A failed run's story used to
    // be spread over five places and a reset destroyed most of it.
    $log = \local_catquizlab\local\run_log::entries($runid);
    if ($log !== []) {
        echo $OUTPUT->heading(get_string('runlog:heading', $component), 4);

        $logtable = new html_table();
        $logtable->head = [
            get_string('runlog:attempt', $component),
            get_string('runlog:time', $component),
            get_string('runlog:event', $component),
            get_string('runlog:detail', $component),
            get_string('runlog:cost', $component),
        ];

        foreach (array_reverse($log) as $entry) {
            $cost = $entry['dbqueries'] > 0
                ? get_string('runlog:costvalue', $component, (object) [
                    'queries'  => $entry['dbqueries'],
                    'duration' => $entry['durationtext'],
                ])
                : '';
            if ($entry['expensive']) {
                $cost = html_writer::tag('strong', $cost) . ' ' . html_writer::tag(
                    'span',
                    get_string('runlog:overbudget', $component),
                    ['class' => 'text-danger small']
                );
            }

            $logtable->data[] = [
                '#' . $entry['attemptno'],
                $entry['time'],
                s($entry['event']) . ($entry['stage'] !== '' ? ' (' . s($entry['stage']) . ')' : ''),
                s($entry['summary']),
                $cost,
            ];
        }

        echo html_writer::table($logtable);
    }

    // A run that says FAILED and nothing else sends the reader to the database.
    // The reason was already recorded; it was simply never shown.
    $failure = \local_catquizlab\local\run_lifecycle::failure_details($runid);
    if ($failure['reason'] !== '') {
        // Its own variable: $detail is the run's data from run_registry::detail()
        // and is read further down for the manifest. Reusing the name replaced
        // an array with a string, and every later access to it — the
        // reproducibility manifest among them — then read a character out of
        // that string instead.
        $failurehtml = html_writer::tag('p', s($failure['reason']), ['class' => 'mb-1']);

        if ($failure['facts'] !== []) {
            $facts = $failure['facts'];
            $failurehtml .= html_writer::tag('p', get_string('run:readinessfacts', $component, (object) [
                'leaves' => (int) ($facts['leaves'] ?? 0),
                'items'  => (int) ($facts['items'] ?? 0),
                'usable' => (int) ($facts['usable'] ?? 0),
            ]), ['class' => 'mb-1 small']);
        }

        if ($failure['time'] > 0) {
            $failurehtml .= html_writer::tag(
                'p',
                userdate($failure['time'], get_string('strftimedatetimeshort')),
                ['class' => 'mb-0 small text-muted']
            );
        }

        echo $OUTPUT->notification(
            html_writer::tag('strong', get_string('run:failedreason', $component)) . $failurehtml,
            'notifyproblem',
            false
        );
    }

    // Reproducibility is not hidden behind convenience: the manifest that
    // pins this run down is on the page, not somewhere in the database.
    echo $OUTPUT->heading(get_string('heading:manifest', $component), 3);
    echo html_writer::tag(
        'pre',
        s(json_encode($detail['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ['class' => 'bg-light p-3 small', 'style' => 'max-height:24em;overflow:auto']
    );

    // Only the actions this status allows: a button that cannot work reads as
    // a defect in the suite rather than a property of the run.
    $allowed = $run['actions'];
    if (has_capability('local/catquizlab:execute', $context)) {
        $buttons = '';
        $buttons .= $OUTPUT->single_button(
            new moodle_url('/local/catquizlab/runs.php', [
                'runid' => $runid, 'action' => 'delete', 'sesskey' => sesskey(),
            ]),
            get_string('purge:deleterun', $component),
            'post'
        );
        $buttons .= $OUTPUT->single_button(
            new moodle_url('/local/catquizlab/runs.php', [
                'runid' => $runid, 'action' => 'deletedeep', 'sesskey' => sesskey(), 'force' => 1,
            ]),
            get_string('purge:deleterundeep', $component),
            'post'
        );
        if (!empty($allowed['reset'])) {
            $buttons .= $OUTPUT->single_button(
                new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => 'resetrerun', 'sesskey' => sesskey(),
                ]),
                get_string('action:resetrerun', $component),
                'post'
            );
        }
        if (!empty($allowed['reset'])) {
            $buttons .= $OUTPUT->single_button(
                new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => 'reset', 'sesskey' => sesskey(),
                ]),
                get_string('action:reset', $component),
                'post'
            );
        }
        if (!empty($allowed['provision'])) {
            $buttons .= $OUTPUT->single_button(
                new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => 'provision', 'sesskey' => sesskey(),
                ]),
                get_string('action:provision', $component),
                'post'
            );
        }
        if (!empty($allowed['recheck'])) {
            $buttons .= $OUTPUT->single_button(
                new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => 'recheck', 'sesskey' => sesskey(),
                ]),
                get_string('action:recheck', $component),
                'post'
            );
        }
        if (!empty($allowed['start'])) {
            $buttons .= $OUTPUT->single_button(
                new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => 'start', 'sesskey' => sesskey(),
                ]),
                get_string('action:startrun', $component),
                'post'
            );
        }
        if ($allowed['reproduce']) {
            $buttons .= $OUTPUT->single_button(
                new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => 'reproduce', 'sesskey' => sesskey(),
                ]),
                get_string('action:reproduce', $component),
                'post'
            );
        }
        if ($allowed['cancel']) {
            $buttons .= $OUTPUT->single_button(
                new moodle_url('/local/catquizlab/runs.php', [
                    'runid' => $runid, 'action' => 'cancel', 'sesskey' => sesskey(),
                ]),
                get_string('action:cancel', $component),
                'post'
            );
        }
        if ($buttons !== '') {
            echo html_writer::div($buttons, 'd-flex');
        }
    }

    if ($allowed['results']) {
        echo html_writer::link(
            new moodle_url('/local/catquizlab/results.php', ['experimentid' => $run['experimentid']]),
            get_string('action:openresults', $component),
            ['class' => 'btn btn-secondary mt-2']
        );
    }

    $origin = $detail['manifest']['config']['reproducedfrom'] ?? null;
    if ($origin !== null) {
        echo html_writer::div(
            get_string('run:reproducedfrom', $component, (int) $origin) . ' '
            . html_writer::link(
                new moodle_url('/local/catquizlab/runs.php', ['runid' => (int) $origin]),
                get_string('run:openorigin', $component)
            ),
            'mt-2 text-muted'
        );
    }

    echo $OUTPUT->footer();
    die();
}

// The filtered listing.
echo $OUTPUT->heading(get_string('heading:runs', $component));

$experimentid = $filters['experimentid'] ?? null;
$filterform = html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-3']);
$filterform .= html_writer::select(
    [0 => get_string('filter:anystatus', $component)] + run_registry::status_menu(),
    'status',
    $filters['status'] ?? 0,
    false,
    ['class' => 'custom-select mr-2', 'aria-label' => get_string('run:status', $component)]
);
foreach (['strategy', 'variant', 'stratum', 'severity'] as $factor) {
    $values = run_registry::factor_values($experimentid, $factor);
    if ($values === []) {
        continue;
    }
    $filterform .= html_writer::select(
        ['' => get_string('filter:any' . $factor, $component)] + $values,
        $factor,
        $filters[$factor] ?? '',
        false,
        ['class' => 'custom-select mr-2', 'aria-label' => get_string('form:' . $factor, $component)]
    );
}
if ($experimentid !== null) {
    $filterform .= html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'experimentid', 'value' => $experimentid,
    ]);
}
$filterform .= html_writer::empty_tag('input', [
    'type'  => 'submit',
    'class' => 'btn btn-secondary',
    'value' => get_string('filter:apply', $component),
]);
$filterform .= html_writer::end_tag('form');
echo $filterform;

$listing = run_registry::listing($filters, $page);

if ($listing['rows'] === []) {
    echo $OUTPUT->notification(get_string('manage:noruns', $component), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = [
        get_string('run:id', $component),
        get_string('run:experiment', $component),
        get_string('run:cellkey', $component),
        get_string('form:strategy', $component),
        get_string('form:model', $component),
        get_string('form:variant', $component),
        get_string('form:stratum', $component),
        get_string('run:status', $component),
        get_string('run:progress', $component),
    ];
    foreach ($listing['rows'] as $row) {
        $table->data[] = [
            html_writer::link(
                new moodle_url('/local/catquizlab/runs.php', ['runid' => $row['id']]),
                (string) $row['id']
            ),
            s($row['experiment']),
            s($row['cellkey']),
            s($row['strategylabel']),
            s($row['modellabel']),
            s(run_registry::group_label('variant', $row['variant'])),
            s(run_registry::group_label('stratum', $row['stratum'])),
            s($row['statuslabel']),
            $row['progress'] . '%',
        ];
    }
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($listing['total'], $page, run_registry::PER_PAGE, $baseurl);
}

echo $OUTPUT->footer();
