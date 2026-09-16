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
        foreach ($result['removed'] as $label => $count) {
            $parts[] = $count . ' ' . $label;
        }

        redirect(
            new moodle_url('/local/catquizlab/runs.php'),
            $parts === []
                ? get_string('purge:nothing', $component)
                : get_string('purge:done', $component, implode(', ', $parts))
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
        foreach ($result['removed'] as $label => $count) {
            $parts[] = $count . ' ' . $label;
        }

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
        $now = time();
        if (!registry::allowed_actions((int) $run->status)['reproduce']) {
            redirect(
                $returnurl,
                get_string('run:cannotreproduce', $component),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        $copy = clone $run;
        unset($copy->id);
        $copy->status = registry::STATUS_DRAFT;
        // The new run says which run it came from, so a reproduction can be
        // told from an original without comparing seeds by hand.
        $manifest = json_decode((string) $run->manifestjson, true) ?: [];
        $manifest['config']['reproducedfrom'] = (int) $run->id;
        $copy->manifestjson = json_encode($manifest, JSON_UNESCAPED_SLASHES);
        $copy->courseid = null;
        $copy->testcmid = null;
        $copy->timecreated = $now;
        $copy->timemodified = $now;
        $newid = (int) $DB->insert_record('local_catquizlab_run', $copy);
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
                    'duration' => $entry['duration'],
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
