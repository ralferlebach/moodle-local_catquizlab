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
 * Landing page of the CAT experiment suite.
 *
 * Shows an overview panel, the primary actions, the experiments and the most
 * recent runs. Everything factual comes from the services; this file resolves
 * parameters and builds the template context.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\environment;
use local_catquizlab\local\experiment_container;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_registry;

admin_externalpage_setup('local_catquizlab_manage');

$component = 'local_catquizlab';
$context = context_system::instance();
$canedit = has_capability('local/catquizlab:edit', $context);
$canexecute = has_capability('local/catquizlab:execute', $context);
$canexport = has_capability('local/catquizlab:export', $context);

$runsurl = new moodle_url('/local/catquizlab/runs.php');

// Environment status: can experiments actually run on this site?
$envitems = [
    environment::catquiz_available()
        ? ['text' => get_string('env:catquizfound', $component), 'class' => 'text-success']
        : ['text' => get_string('env:catquizmissing', $component), 'class' => 'text-danger'],
    environment::adaptivequiz_available()
        ? ['text' => get_string('env:adaptivequizfound', $component), 'class' => 'text-success']
        : ['text' => get_string('env:adaptivequizmissing', $component), 'class' => 'text-danger'],
];

/**
 * The bootstrap badge class for a run or experiment status.
 *
 * @param int $status The status value.
 * @return string
 */
function local_catquizlab_status_class(int $status): string {
    $map = [
        registry::STATUS_DRAFT       => 'badge-secondary',
        registry::STATUS_SCHEDULED   => 'badge-info',
        registry::STATUS_READY       => 'badge-info',
        registry::STATUS_RUNNING     => 'badge-primary',
        registry::STATUS_AGGREGATING => 'badge-primary',
        registry::STATUS_FINISHED    => 'badge-success',
        registry::STATUS_FAILED      => 'badge-danger',
        // Cancelled is a decision, not a defect, so it is not shown in the
        // colour that means "something is wrong".
        registry::STATUS_CANCELLED   => 'badge-warning',
    ];

    return $map[$status] ?? 'badge-secondary';
}

// The experiment library (#129): one row per experiment with where it stands,
// one main action fitting that, the rest under "More", and deleting apart from
// everything that keeps the experiment. Searchable and sortable by name, state
// and last change, as plain GET parameters.
$canpurge = has_capability('local/catquizlab:purge', $context);
$search = trim(optional_param('q', '', PARAM_TEXT));
$sort = optional_param('sort', 'modified', PARAM_ALPHA);
if (!in_array($sort, ['modified', 'name', 'state'], true)) {
    $sort = 'modified';
}
$allexperiments = experiment_service::overview();
$library = \local_catquizlab\local\experiment_status::library(array_column($allexperiments, 'id'));
$listed = array_values(array_filter($allexperiments, static fn(array $row): bool => $search === ''
    || \core_text::strpos(\core_text::strtolower($row['name']), \core_text::strtolower($search)) !== false));
$phaseorder = array_flip(\local_catquizlab\local\experiment_status::PHASES);
usort($listed, static function (array $a, array $b) use ($sort, $library, $phaseorder): int {
    if ($sort === 'name') {
        return strnatcasecmp($a['name'], $b['name']);
    }
    if ($sort === 'state') {
        return ($phaseorder[$library[$a['id']]['phase']] <=> $phaseorder[$library[$b['id']]['phase']])
            ?: $b['timemodified'] <=> $a['timemodified'];
    }
    return $b['timemodified'] <=> $a['timemodified'];
});
$experimentrows = [];
foreach ($listed as $row) {
    $editurl = new moodle_url('/local/catquizlab/experiment.php', ['id' => $row['id']]);
    $state = $library[$row['id']];

    // The one main action, by where the experiment stands.
    if (in_array($state['phase'], ['complete', 'completefailures'], true)) {
        $main = ['label' => get_string('status:nextresults', $component),
            'url' => (new moodle_url('/local/catquizlab/results.php', ['experimentid' => $row['id']]))->out(false)];
    } else if (in_array($state['phase'], ['collecting', 'starting', 'blocked', 'preparing'], true)) {
        $main = ['label' => get_string('status:nextprogress', $component),
            'url' => (new moodle_url('/local/catquizlab/runs.php', ['experimentid' => $row['id']]))->out(false)];
    } else {
        $main = ['label' => get_string('manage:open', $component), 'url' => $editurl->out(false)];
    }

    // The others, under "More": links, and a form where something is created.
    $more = [
        ['label' => get_string('manage:results', $component), 'link' => true,
            'url' => (new moodle_url('/local/catquizlab/results.php', ['experimentid' => $row['id']]))->out(false)],
        ['label' => get_string('manage:compare', $component), 'link' => true,
            'url' => (new moodle_url('/local/catquizlab/compare.php', ['experimentid' => $row['id']]))->out(false)],
    ];
    if ($canedit) {
        $more[] = ['label' => get_string('manage:duplicate', $component), 'post' => true,
            'url' => (new moodle_url('/local/catquizlab/experiment.php'))->out(false),
            'id' => $row['id'], 'action' => 'duplicate', 'sesskey' => sesskey()];
    }
    if ($canexport) {
        $more[] = ['label' => get_string('manage:exportjson', $component), 'link' => true,
            'url' => (new moodle_url('/local/catquizlab/experiment.php', ['id' => $row['id'], 'action' => 'export']))->out(false)];
    }

    $experimentrows[] = array_merge($row, [
        'state'        => $state['label'],
        'phase'        => $state['phase'],
        'statebadge'   => ['ok' => 'badge-success', 'active' => 'badge-info', 'warn' => 'badge-warning',
            'problem' => 'badge-danger'][$state['tone']],
        // Running ones show how far, not how many workers (#129 AK 2).
        'progress'     => $state['total'] > 0 ? get_string('status:sittings', $component, (object) $state) : '',
        'hasprogress'  => $state['total'] > 0,
        'percent'      => $state['percent'],
        'tierlabel'    => get_string_manager()->string_exists('tier:' . $row['tier'], $component)
            ? get_string('tier:' . $row['tier'], $component)
            : $row['tier'],
        'cells'        => $row['cells'] ?? '—',
        'modified'     => userdate($row['timemodified'], get_string('strftimedatetimeshort')),
        'editurl'      => $editurl->out(false),
        'main'         => $main,
        'more'         => $more,
        // Deleting outright, apart from everything that keeps the experiment; a
        // page that shows what goes and asks for the name, then a POST (#129).
        'candelete'    => $canpurge,
        'deleteurl'    => (new moodle_url('/local/catquizlab/experiment.php', [
            'id' => $row['id'], 'action' => 'delete', 'sesskey' => sesskey(),
        ]))->out(false),
    ]);
}
$librarycontext = [
    'q' => $search,
    'hassearch' => $search !== '',
    'sorts' => array_map(static fn(string $key): array => [
        'key' => $key,
        'label' => get_string('manage:sort' . $key, $component),
        'selected' => $key === $sort,
    ], ['modified', 'name', 'state']),
    'actionurl' => (new moodle_url('/local/catquizlab/index.php'))->out(false),
    'reseturl' => (new moodle_url('/local/catquizlab/index.php', ['tab' => 'experiments']))->out(false),
    'total' => count($allexperiments),
    'shown' => count($experimentrows),
];

// The most recent runs; the full, filterable listing lives on runs.php.
$recent = run_registry::listing([], 0, 10);
$runrows = [];
foreach ($recent['rows'] as $row) {
    $runrows[] = [
        'id'            => $row['id'],
        'experiment'    => $row['experiment'],
        'strategylabel' => $row['strategylabel'],
        'variantlabel'  => run_registry::group_label('variant', $row['variant']),
        'stratumlabel'  => run_registry::group_label('stratum', $row['stratum']),
        'statuslabel'   => $row['statuslabel'],
        'statusclass'   => local_catquizlab_status_class((int) $row['status']),
        'progress'      => $row['progress'],
        'progressclass' => (int) $row['status'] === registry::STATUS_FAILED ? 'bg-danger' : '',
        'progresslabel' => get_string('run:progressof', $component, (object) [
            'done'  => $row['attemptsdone'],
            'total' => $row['attempts'],
        ]),
        'detailurl'     => (new moodle_url('/local/catquizlab/runs.php', ['runid' => $row['id']]))->out(false),
    ];
}

// The overview panel: how much is there, and how much of it is in trouble.
$counts = [
    'experiments' => count($allexperiments),
    'running'     => $DB->count_records('local_catquizlab_run', ['status' => registry::STATUS_RUNNING]),
    'finished'    => $DB->count_records('local_catquizlab_run', ['status' => registry::STATUS_FINISHED]),
    'failed'      => $DB->count_records('local_catquizlab_run', ['status' => registry::STATUS_FAILED]),
];
// The worker fleet and the attempt queue, beside the experiments. A pipeline
// that has stalled looks exactly like one that is merely slow unless the page
// says how many workers are alive and how long the queue is.
$workers = \local_catquizlab\local\worker_registry::summary();
// Split into what an operator can act on: claimable now, not due yet after a
// failure, blocked by the run's state, or paused. One number for all four
// answered a question nobody asked.
$queue = attempt_scheduler::queue_breakdown();

// Work waiting with nobody to do it is the one combination that never resolves
// itself, so it is named rather than left to be inferred from two numbers.
$queue['stalled'] = $queue['queued'] > 0 && $workers['live'] === 0;

// The most recent failure reasons, because a rising retry count without a
// reason tells an operator nothing they can act on.
$recenterrors = array_values($DB->get_records_select(
    'local_catquizlab_attempt',
    'lasterror IS NOT NULL',
    [],
    'timemodified DESC',
    'id, runid, tries, lasterror',
    0,
    5
));

// The one thing a fresh installation needs to know: is it ready, and if not,
// where does it go. Leaving that on a page an administrator has to already know
// about is how the setup ends up being done by hand instead.
// The one line that says whether this is working, above the numbers it is
// drawn from. Four correct figures that contradict each other at a glance are
// worse than one sentence.
$situation = \local_catquizlab\local\situation::assess();

$setupstate = \local_catquizlab\local\setup_wizard::state();
$setupnotice = $setupstate['ready'] ? null : [
    'blockers' => implode(', ', array_slice($setupstate['blockers'], 0, 4)),
    'opsurl'   => (new moodle_url('/local/catquizlab/operations.php'))->out(false),
];

$overview = [
    [
        'count' => $counts['experiments'],
        'label' => get_string('overview:experiments', $component),
        'url'   => '#experiments',
        'class' => 'text-primary',
    ],
    [
        'count' => $counts['running'],
        'label' => get_string('overview:running', $component),
        'url'   => (new moodle_url($runsurl, ['status' => registry::STATUS_RUNNING]))->out(false),
        'class' => 'text-primary',
    ],
    [
        'count' => $counts['finished'],
        'label' => get_string('overview:finished', $component),
        'url'   => (new moodle_url($runsurl, ['status' => registry::STATUS_FINISHED]))->out(false),
        'class' => 'text-success',
    ],
    [
        'count' => $counts['failed'],
        'label' => get_string('overview:failed', $component),
        'url'   => (new moodle_url($runsurl, ['status' => registry::STATUS_FAILED]))->out(false),
        'class' => 'text-danger',
    ],
];

// Where the suite will provision. Without it nothing is created silently, so
// the state has to be visible before someone starts a sweep.
$course = experiment_container::course();
$containercontext = [
    'configured'  => $course !== null,
    'coursename'  => $course !== null ? format_string($course->fullname) : '',
    'courseurl'   => $course !== null
        ? (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false)
        : '',
    'settingsurl' => (new moodle_url('/admin/settings.php', [
        'section' => \local_catquizlab\local\registry::SETTINGS_SECTION,
    ]))->out(false),
];

$templatecontext = [
    'intro'       => get_string('manage:intro', $component),
    'container'   => $containercontext,
    'canedit'     => $canedit,
    'newurl'      => (new moodle_url('/local/catquizlab/experiment.php'))->out(false),
    'importurl'   => (new moodle_url('/local/catquizlab/import.php'))->out(false),
    'presetsurl'  => (new moodle_url('/local/catquizlab/presets.php'))->out(false),
    'runsurl'     => $runsurl->out(false),
    'resultsurl'  => (new moodle_url('/local/catquizlab/results.php'))->out(false),
    'overview'    => $overview,
    'environment' => ['items' => $envitems],
    'disabled'    => !get_config($component, 'enabled'),
    'experiments' => ['hasany' => $experimentrows !== [], 'rows' => $experimentrows,
        'library' => $librarycontext, 'none' => $allexperiments === [],
        'nomatch' => $allexperiments !== [] && $experimentrows === []],
    'situation'   => $situation,
    // Superseded by the verdict above when it already says the installation is
    // not ready: two warnings about one thing is one too many.
    'setupnotice' => $situation['state'] === \local_catquizlab\local\situation::NOTREADY
        ? null
        : $setupnotice,
    'workers'     => $workers + ['queue' => $queue, 'hasslot' => $workers['live'] > 0],
    'queue'       => $queue,
    'recenterrors' => $recenterrors === [] ? null : ['rows' => array_map(static function ($row): array {
        return [
            'attemptid' => (int) $row->id,
            'runid'     => (int) $row->runid,
            'tries'     => (int) $row->tries,
            'lasterror' => (string) $row->lasterror,
        ];
    }, $recenterrors)],
    'runs'        => [
        'hasany'     => $runrows !== [],
        'hassummary' => $recent['total'] > count($runrows),
        'summary'    => get_string('manage:runsummary', $component, (object) [
            'shown' => count($runrows),
            'total' => $recent['total'],
        ]),
        'rows'       => $runrows,
        'runsurl'    => $runsurl->out(false),
    ],
];

// One page. Everything an operator does — look at experiments, set the
// installation up, watch it run, change what it runs with — is reachable from
// here without leaving the plugin. Splitting these across three pages meant
// knowing which page held which half.
// Named and ordered by the work, not by the objects behind them: set the
// installation up, define what to run, watch it run, look at what came out.
// Somebody doing this for the first time should be able to follow the numbers.
$tab = optional_param('tab', 'experiments', PARAM_ALPHA);
if (!in_array($tab, ['experiments', 'setup', 'results', 'settings'], true)) {
    $tab = 'experiments';
}

// Deliberately no redirect to the setup tab for an unready installation. It
// was tempting — there is one thing worth doing and this is not the tab for it
// — but somebody who opens the plugin to look at their experiments should find
// their experiments. The banner at the top of this tab says what is missing and
// links to where it is fixed, which is the same information without moving the
// page out from under the reader.

$settingsform = null;
if ($tab === 'settings') {
    $settingsform = new \local_catquizlab\form\settings_form(
        new moodle_url('/local/catquizlab/index.php', ['tab' => 'settings'])
    );

    if ($data = $settingsform->get_data()) {
        // Every submission of a form is recorded with what was entered (#90),
        // without the session key and anything that is a password or a token.
        \local_catquizlab\local\debug_trace::record(
            \local_catquizlab\local\debug_trace::UI,
            'settings_form_submitted',
            \local_catquizlab\local\debug_trace::submitted((array) $data),
            'ok'
        );
        require_capability('local/catquizlab:execute', $context);
        // Every field the form offers. The list was written by hand and
        // debuglevel was left out of it, so the form showed the setting, read
        // it back, and silently discarded what anybody chose: switched on,
        // saved, and off again with no action in between.
        //
        // Taken from the form itself now, so a field that is added to the form
        // is saved without anybody remembering this list.
        foreach (\local_catquizlab\form\settings_form::saved_fields() as $name) {
            if (isset($data->$name)) {
                set_config($name, $data->$name, $component);
            }
        }
        // A lowered limit takes effect now, by draining, and is logged (#124).
        \local_catquizlab\local\worker_capacity::note_change();
        redirect(
            new moodle_url('/local/catquizlab/index.php', ['tab' => 'settings']),
            get_string('settingsform:saved', $component)
        );
    }

    $current = [];
    foreach (\local_catquizlab\form\settings_form::saved_fields() as $name) {
        $current[$name] = get_config($component, $name);
    }
    // The two that need a value when nothing is stored yet.
    $current['worker_concurrency'] = (int) ($current['worker_concurrency'] ?: 1);
    $current['worker_safety_cap'] = (int) ($current['worker_safety_cap'] ?: 0);
    $current['debuglevel'] = (string) ($current['debuglevel'] ?: 'off');

    $settingsform->set_data((object) $current);
}

$tabs = [];
foreach (['setup', 'experiments', 'results', 'settings'] as $name) {
    $tabs[] = new tabobject(
        $name,
        $name === 'results'
            ? new moodle_url('/local/catquizlab/results.php')
            : new moodle_url('/local/catquizlab/index.php', ['tab' => $name]),
        get_string('tab:' . $name, $component)
    );
}

echo $OUTPUT->header();

// The same frame as every other page. The old tabtree was cut by object —
// "experiments and runs", "settings" — so there was no place that meant "what is
// happening right now", and settings sat beside the work as if it were a step.
echo \local_catquizlab\output\shell::render(
    $tab === 'setup' ? \local_catquizlab\output\shell::STEP_PREPARE
        : \local_catquizlab\output\shell::STEP_PLAN,
    optional_param('experimentid', 0, PARAM_INT)
);

if ($tab === 'experiments') {
    // Keeps the counters current while workers run, so watching a queue drain
    // does not mean reloading the page during exactly the minutes somebody is
    // watching it.
    $PAGE->requires->strings_for_js(['live:interrupted', 'live:reconnect', 'eta:asof', 'eta:stale'], 'local_catquizlab');
    $PAGE->requires->js_call_amd('local_catquizlab/livestatus', 'init', [
        \local_catquizlab\external\live_status::current_shape(),
        optional_param('experimentid', 0, PARAM_INT),
    ]);
}

if ($tab === 'experiments') {
    echo $OUTPUT->render_from_template('local_catquizlab/manage', $templatecontext);
} else if ($tab === 'setup') {
    echo $OUTPUT->render_from_template(
        'local_catquizlab/operations',
        \local_catquizlab\local\operations_view::context()
    );
} else {
    $settingsform->display();
}

echo $OUTPUT->footer();
