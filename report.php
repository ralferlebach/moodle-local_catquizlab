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
 * Report page for the CAT experiment suite.
 *
 * Shows the stored evaluation results of a run (metrics by scope, with a bar
 * chart) or the trend of key metrics across an experiment's runs (with a line
 * chart), using Moodle's built-in chart API. Reachable from the management page.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_catquizlab\local\report_builder;

$runid = optional_param('runid', 0, PARAM_INT);
$experimentid = optional_param('experimentid', 0, PARAM_INT);

$component = 'local_catquizlab';
$context = context_system::instance();

require_login();
require_capability('local/catquizlab:view', $context);

$url = new moodle_url('/local/catquizlab/report.php');
if ($runid) {
    $url->param('runid', $runid);
} else if ($experimentid) {
    $url->param('experimentid', $experimentid);
}

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('report:heading', $component));
$PAGE->set_heading(get_string('report:heading', $component));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report:heading', $component));

if ($runid) {
    local_catquizlab_render_run_report($runid, $component);
} else if ($experimentid) {
    local_catquizlab_render_experiment_report($experimentid, $component);
} else {
    echo $OUTPUT->notification(get_string('report:noselection', $component), 'info');
}

echo $OUTPUT->footer();

/**
 * Render the results of a single run: a metric table per scope and a bar chart.
 *
 * @param int $runid The run.
 * @param string $component The plugin component name.
 * @return void
 */
function local_catquizlab_render_run_report(int $runid, string $component): void {
    global $OUTPUT, $DB;

    if (!$DB->record_exists('local_catquizlab_run', ['id' => $runid])) {
        echo $OUTPUT->notification(get_string('report:nodata', $component), 'warn');
        return;
    }

    $report = report_builder::run_report($runid);
    echo $OUTPUT->heading(get_string('report:runtitle', $component, $report['cellkey']), 3);

    if ($report['scopes'] === []) {
        echo $OUTPUT->notification(get_string('report:nodata', $component), 'info');
        return;
    }

    foreach ($report['scopes'] as $scope => $metrics) {
        echo $OUTPUT->heading($scope, 4);
        $table = new html_table();
        $table->head = [get_string('report:metric', $component), get_string('report:value', $component)];
        foreach ($metrics as $metric => $value) {
            $table->data[] = [$metric, $value === null ? '—' : format_float($value, 4)];
        }
        echo html_writer::table($table);
    }

    $scalars = report_builder::run_scalars($runid);
    if ($scalars !== []) {
        $chart = new \core\chart_bar();
        $chart->set_title(get_string('report:runchart', $component));
        $series = new \core\chart_series(get_string('report:value', $component), array_values($scalars));
        $chart->add_series($series);
        $chart->set_labels(array_keys($scalars));
        echo $OUTPUT->render_chart($chart);
    }
}

/**
 * Render the trend of key metrics across an experiment's runs.
 *
 * @param int $experimentid The experiment.
 * @param string $component The plugin component name.
 * @return void
 */
function local_catquizlab_render_experiment_report(int $experimentid, string $component): void {
    global $OUTPUT, $DB;

    if (!$DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
        echo $OUTPUT->notification(get_string('report:nodata', $component), 'warn');
        return;
    }

    $report = report_builder::experiment_report($experimentid);
    $haschart = false;
    $chart = new \core\chart_line();
    $chart->set_title(get_string('report:experimentchart', $component));
    $labels = [];

    $table = new html_table();
    $table->head = [
        get_string('report:metric', $component),
        get_string('report:mean', $component),
        get_string('report:sd', $component),
        get_string('report:cv', $component),
        get_string('report:runs', $component),
    ];

    foreach ($report as $metric => $data) {
        $stability = $data['stability'];
        $table->data[] = [
            $metric,
            $stability['mean'] === null ? '—' : format_float($stability['mean'], 4),
            $stability['sd'] === null ? '—' : format_float($stability['sd'], 4),
            $stability['cv'] === null ? '—' : format_float($stability['cv'], 4),
            $stability['n'],
        ];
        if ($data['series'] !== []) {
            $chart->add_series(new \core\chart_series($metric, array_values($data['series'])));
            $labels = range(1, count($data['series']));
            $haschart = true;
        }
    }

    echo html_writer::table($table);

    if ($haschart) {
        $chart->set_labels(array_map('strval', $labels));
        echo $OUTPUT->render_chart($chart);
    }
}
