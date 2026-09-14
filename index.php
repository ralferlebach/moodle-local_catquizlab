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

// Experiments, with the actions the viewer is actually allowed to perform.
$experimentrows = [];
foreach (experiment_service::overview() as $row) {
    $editurl = new moodle_url('/local/catquizlab/experiment.php', ['id' => $row['id']]);

    $actions = [
        html_writer::link(
            new moodle_url('/local/catquizlab/results.php', ['experimentid' => $row['id']]),
            get_string('manage:results', $component),
            ['class' => 'mr-2']
        ),
        html_writer::link(
            new moodle_url('/local/catquizlab/compare.php', ['experimentid' => $row['id']]),
            get_string('manage:compare', $component),
            ['class' => 'mr-2']
        ),
    ];
    if ($canedit) {
        $actions[] = html_writer::link(
            new moodle_url('/local/catquizlab/experiment.php', [
                'id' => $row['id'], 'action' => 'duplicate', 'sesskey' => sesskey(),
            ]),
            get_string('manage:duplicate', $component),
            ['class' => 'mr-2']
        );
    }
    if ($canexport) {
        $actions[] = html_writer::link(
            new moodle_url('/local/catquizlab/experiment.php', [
                'id' => $row['id'], 'action' => 'export',
            ]),
            get_string('manage:exportjson', $component)
        );
    }

    $status = (int) $row['status'];
    $experimentrows[] = array_merge($row, [
        'status'       => $row['statuslabel'],
        'statusclass'  => local_catquizlab_status_class($status),
        'tierlabel'    => get_string_manager()->string_exists('tier:' . $row['tier'], $component)
            ? get_string('tier:' . $row['tier'], $component)
            : $row['tier'],
        'cells'        => $row['cells'] ?? '—',
        'modified'     => userdate($row['timemodified'], get_string('strftimedatetimeshort')),
        'editurl'      => $editurl->out(false),
        'actions'      => implode('', $actions),
    ]);
}

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
    'experiments' => count($experimentrows),
    'running'     => $DB->count_records('local_catquizlab_run', ['status' => registry::STATUS_RUNNING]),
    'finished'    => $DB->count_records('local_catquizlab_run', ['status' => registry::STATUS_FINISHED]),
    'failed'      => $DB->count_records('local_catquizlab_run', ['status' => registry::STATUS_FAILED]),
];
// The worker fleet and the attempt queue, beside the experiments. A pipeline
// that has stalled looks exactly like one that is merely slow unless the page
// says how many workers are alive and how long the queue is.
$workers = \local_catquizlab\local\worker_registry::summary();
$queue = [
    'queued'    => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_QUEUED]),
    'running'   => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_RUNNING]),
    'collected' => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_COLLECTED]),
    'failed'    => $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_FAILED]),
];

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
    'experiments' => ['hasany' => $experimentrows !== [], 'rows' => $experimentrows],
    'setupnotice' => $setupnotice,
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
$tab = optional_param('tab', 'experiments', PARAM_ALPHA);
if (!in_array($tab, ['experiments', 'setup', 'settings'], true)) {
    $tab = 'experiments';
}

$settingsform = null;
if ($tab === 'settings') {
    $settingsform = new \local_catquizlab\form\settings_form(
        new moodle_url('/local/catquizlab/index.php', ['tab' => 'settings'])
    );

    if ($data = $settingsform->get_data()) {
        require_capability('local/catquizlab:execute', $context);
        foreach (
            ['experimentcourseid', 'enabled', 'worker_base_url', 'worker_node_path',
            'worker_concurrency', 'worker_max_jobs'] as $name
        ) {
            if (isset($data->$name)) {
                set_config($name, $data->$name, $component);
            }
        }
        redirect(
            new moodle_url('/local/catquizlab/index.php', ['tab' => 'settings']),
            get_string('settingsform:saved', $component)
        );
    }

    $settingsform->set_data((object) [
        'experimentcourseid' => (int) get_config($component, 'experimentcourseid'),
        'enabled'            => (int) get_config($component, 'enabled'),
        'worker_base_url'    => (string) get_config($component, 'worker_base_url'),
        'worker_node_path'   => (string) get_config($component, 'worker_node_path'),
        'worker_concurrency' => (int) (get_config($component, 'worker_concurrency') ?: 1),
        'worker_max_jobs'    => (int) get_config($component, 'worker_max_jobs'),
    ]);
}

$tabs = [];
foreach (['experiments', 'setup', 'settings'] as $name) {
    $tabs[] = new tabobject(
        $name,
        new moodle_url('/local/catquizlab/index.php', ['tab' => $name]),
        get_string('tab:' . $name, $component)
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', $component));
echo $OUTPUT->tabtree($tabs, $tab);

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
