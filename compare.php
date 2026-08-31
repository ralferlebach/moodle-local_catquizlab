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
 * Compare experimental cells against each other.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_catquizlab\local\export_dataset;
use local_catquizlab\local\metrics;
use local_catquizlab\local\run_registry;

$experimentid = required_param('experimentid', PARAM_INT);
$groupby = optional_param('groupby', 'strategy', PARAM_ALPHA);
$metric = optional_param('metric', 'rmse', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHA);

admin_externalpage_setup('local_catquizlab_manage');

$context = context_system::instance();
$component = 'local_catquizlab';
$pageurl = new moodle_url('/local/catquizlab/compare.php', [
    'experimentid' => $experimentid,
    'groupby'      => $groupby,
    'metric'       => $metric,
]);
$PAGE->set_url($pageurl);

require_capability('local/catquizlab:view', $context);

$experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid], '*', MUST_EXIST);

$groupfactors = ['strategy', 'model', 'variant', 'stratum', 'severity'];
if (!in_array($groupby, $groupfactors, true)) {
    $groupby = 'strategy';
}

$comparablemetrics = ['rmse', 'bias', 'mae', 'correlation', 'testlength', 'se'];
if (!in_array($metric, $comparablemetrics, true)) {
    $metric = 'rmse';
}

// Exporting the comparison is a separate capability, because it takes data off
// the instance rather than just looking at it.
if ($action === 'export') {
    require_capability('local/catquizlab:export', $context);

    $runids = array_map(
        static fn(\stdClass $record): int => (int) $record->id,
        $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC')
    );
    $dataset = export_dataset::metrics($runids);

    $lines = [implode(',', $dataset['columns'])];
    foreach ($dataset['rows'] as $row) {
        $cells = [];
        foreach ($dataset['columns'] as $column) {
            $value = (string) ($row[$column] ?? '');
            $cells[] = '"' . str_replace('"', '""', $value) . '"';
        }
        $lines[] = implode(',', $cells);
    }

    send_file(
        implode("\n", $lines) . "\n",
        'catquizlab-metrics-' . $experimentid . '.csv',
        0,
        0,
        true,
        true,
        'text/csv'
    );
    die();
}

$rows = run_registry::compare($experimentid, $metric, $groupby);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('heading:compare', $component, format_string($experiment->name)));

// Choosing what to compare and on which metric.
$selector = html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-3']);
$selector .= html_writer::empty_tag('input', [
    'type' => 'hidden', 'name' => 'experimentid', 'value' => $experimentid,
]);
$groupmenu = [];
foreach ($groupfactors as $factor) {
    $groupmenu[$factor] = get_string('form:' . $factor, $component);
}
$selector .= html_writer::select($groupmenu, 'groupby', $groupby, false, [
    'class' => 'custom-select mr-2', 'aria-label' => get_string('compare:groupby', $component),
]);
$metricmenu = [];
foreach ($comparablemetrics as $name) {
    $metricmenu[$name] = $name;
}
$selector .= html_writer::select($metricmenu, 'metric', $metric, false, [
    'class' => 'custom-select mr-2', 'aria-label' => get_string('compare:metric', $component),
]);
$selector .= html_writer::empty_tag('input', [
    'type' => 'submit', 'class' => 'btn btn-secondary', 'value' => get_string('filter:apply', $component),
]);
$selector .= html_writer::end_tag('form');
echo $selector;

if ($rows === []) {
    echo $OUTPUT->notification(get_string('compare:nodata', $component), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm w-auto';
    $table->head = [
        get_string('compare:cell', $component),
        get_string('report:runs', $component),
        get_string('report:mean', $component),
        get_string('report:sd', $component),
        get_string('compare:ci95', $component),
    ];

    $chart = new \core\chart_bar();
    $chart->set_title(get_string('compare:charttitle', $component, $metric));
    $labels = [];
    $values = [];

    foreach ($rows as $row) {
        $interval = ($row['ci95lo'] === null)
            ? '—'
            : format_float($row['ci95lo'], 4) . ' … ' . format_float($row['ci95hi'], 4);
        $table->data[] = [
            s($row['label']),
            $row['n'],
            $row['mean'] === null ? '—' : format_float($row['mean'], 4),
            $row['sd'] === null ? '—' : format_float($row['sd'], 4),
            $interval,
        ];
        $labels[] = $row['label'];
        $values[] = $row['mean'] ?? 0.0;
    }

    echo html_writer::table($table);

    $chart->add_series(new \core\chart_series($metric, $values));
    $chart->set_labels($labels);
    echo $OUTPUT->render_chart($chart);

    // Robustness reads as a difference from the ideal pool, so when the pools
    // are what is being compared the ideal one is named as the reference.
    if ($groupby === 'variant') {
        echo html_writer::tag('p', get_string('compare:idealreference', $component), ['class' => 'text-muted']);
    }
}

if (has_capability('local/catquizlab:export', $context)) {
    echo html_writer::link(
        new moodle_url('/local/catquizlab/compare.php', [
            'experimentid' => $experimentid,
            'groupby'      => $groupby,
            'metric'       => $metric,
            'action'       => 'export',
        ]),
        get_string('action:exportcsv', $component),
        ['class' => 'btn btn-secondary']
    );
}

echo $OUTPUT->footer();
