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
 * Results and evaluation.
 *
 * Every tab reads through results_query, so a figure in a chart and the same
 * figure in the table below it come from one computation.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\metrics;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\local\run_registry;
use local_catquizlab\output\results_page;

$tab = optional_param('tab', 'overview', PARAM_ALPHA);

$filter = [];
foreach (['experimentid', 'replication'] as $key) {
    $value = optional_param($key, 0, PARAM_INT);
    if ($value > 0) {
        $filter[$key] = $value;
    }
}
foreach (['tier', 'model', 'strategy', 'variant', 'stratum', 'severity'] as $key) {
    $value = optional_param($key, '', PARAM_ALPHANUMEXT);
    if ($value !== '') {
        $filter[$key] = $value;
    }
}

admin_externalpage_setup('local_catquizlab_manage');

$context = context_system::instance();
$component = 'local_catquizlab';
$pageurl = new moodle_url('/local/catquizlab/results.php', $filter + ['tab' => $tab]);
$PAGE->set_url($pageurl);

require_capability('local/catquizlab:view', $context);

$query = new results_query($filter);

// Downloading takes exactly the filter that is on screen, so a reader cannot
// look at one selection and receive another.
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'csv' || $action === 'json') {
    require_capability('local/catquizlab:export', $context);

    $level = optional_param('level', results_export::LEVEL_ATTEMPT, PARAM_ALPHA);
    if (!array_key_exists($level, results_export::levels())) {
        $level = results_export::LEVEL_ATTEMPT;
    }

    if ($action === 'csv') {
        $content = results_export::to_csv(results_export::dataset($query, $level));
        $mimetype = 'text/csv';
    } else {
        $content = results_export::to_json($query, $level);
        $mimetype = 'application/json';
    }

    send_file(
        $content,
        results_export::filename($query, $level, $action),
        0,
        0,
        true,
        true,
        $mimetype
    );
    die();
}
$page = new results_page($query, $tab, $filter);

if (!$page->tab_exists($tab)) {
    $tab = 'overview';
    $page = new results_page($query, $tab, $filter);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('heading:results', $component));
echo html_writer::tag('p', get_string('results:intro', $component), ['class' => 'text-muted']);

echo $page->render_filter_bar();
echo $page->render_tabs();
echo $page->render_provenance();
echo $page->render_tab();

echo $OUTPUT->footer();
