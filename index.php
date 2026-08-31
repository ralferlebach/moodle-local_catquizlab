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
        registry::STATUS_DRAFT     => 'badge-secondary',
        registry::STATUS_SCHEDULED => 'badge-info',
        registry::STATUS_RUNNING   => 'badge-primary',
        registry::STATUS_FINISHED  => 'badge-success',
        registry::STATUS_FAILED    => 'badge-danger',
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
        'section' => 'local_catquizlab',
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

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', $component));
echo $OUTPUT->render_from_template('local_catquizlab/manage', $templatecontext);
echo $OUTPUT->footer();
