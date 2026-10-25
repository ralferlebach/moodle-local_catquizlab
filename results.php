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

// The budget and cell keys carry separators, so they are not ALPHANUMEXT.
foreach (['budget', 'cellkey'] as $key) {
    $value = optional_param($key, '', PARAM_TEXT);
    if ($value !== '') {
        $filter[$key] = $value;
    }
}

// Which sittings by validity (#118): every aggregated view the valid ones,
// the raw data all of them, marked — unless asked otherwise.
$validity = optional_param('validity', '', PARAM_ALPHA);
if (!in_array($validity, ['valid', 'invalid', 'unknown', 'all'], true)) {
    $validity = $tab === 'rawdata' ? 'all' : 'valid';
}
$filter['validity'] = $validity;
// Which verdict decides (#112): the engine's definitions for every strategy,
// or the engine's own verdict word for word.
$validityrule = optional_param('validityrule', 'uniform', PARAM_ALPHA);
$filter['validityrule'] = in_array($validityrule, ['uniform', 'engine'], true) ? $validityrule : 'uniform';

admin_externalpage_setup('local_catquizlab_manage');

$context = context_system::instance();

// What Moodle grants its own reports. An evaluation over every sitting of a
// large experiment is a report, and the default limit of a web request is not
// sized for one: this page died with "Allowed memory size exhausted" on an
// installation with nine thousand sittings. The reads are proportional to what
// is reported now, and this is the headroom the platform intends for pages
// like it.
raise_memory_limit(MEMORY_EXTRA);
$component = 'local_catquizlab';
$pageurl = new moodle_url('/local/catquizlab/results.php', $filter + ['tab' => $tab]);
$PAGE->set_url($pageurl);

require_capability('local/catquizlab:view', $context);

// The package is a file, so it is sent before the page starts — but after the
// page's login and access checks, and with the file library loaded: sent
// before both, every download ended in "Call to undefined function
// send_file()" (#121).
if (optional_param('action', '', PARAM_ALPHA) === 'package') {
    require_sesskey();
    require_once($CFG->libdir . '/filelib.php');
    $packageid = required_param('experimentid', PARAM_INT);

    send_file(
        \local_catquizlab\local\reproducibility::package_json($packageid),
        'catquizlab-experiment-' . $packageid . '.json',
        0,
        0,
        true,
        true,
        'application/json'
    );
    exit;
}

$query = new results_query($filter);

// Downloading takes exactly the filter that is on screen, so a reader cannot
// look at one selection and receive another.
$action = optional_param('action', '', PARAM_ALPHA);
if (in_array($action, ['csv', 'json', 'meta'], true)) {
    require_capability('local/catquizlab:export', $context);

    $level = optional_param('level', results_export::LEVEL_ATTEMPT, PARAM_ALPHA);
    if (!array_key_exists($level, results_export::levels())) {
        $level = results_export::LEVEL_ATTEMPT;
    }

    // Streamed rather than assembled: send_file() takes a finished string, so
    // the dataset and the formatted export were held at the same time.
    core_php_time_limit::raise(600);

    // TI@n in a step export only where it fits the time limit (#99): asked
    // for explicitly above the soft limit, never above the hard one — a
    // download cut off by its time limit is half a file.
    if ($level === results_export::LEVEL_STEP) {
        $cost = results_export::engine_info_cost($query);
        $asked = optional_param('engineinfo', -1, PARAM_INT);
        results_export::$engineinfo = $cost['seconds'] <= results_export::ENGINE_INFO_HARD_LIMIT
            && ($asked === 1 || ($asked === -1 && $cost['seconds'] <= results_export::ENGINE_INFO_SOFT_LIMIT));
    }

    // The metadata on their own, for a CSV — which cannot carry them (#115):
    // the rows are read and counted as for the file, so that both say the same.
    if ($action === 'meta') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'
            . str_replace('.json', '.metadata.json', results_export::filename($query, $level, 'json')) . '"');
        header('Cache-Control: no-store');
        $written = 0;
        foreach (results_export::iterate($query, $level)['rows'] as $unused) {
            $written++;
        }
        echo json_encode(
            results_export::metadata($query, $level, true, $written),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        die();
    }

    $format = $action === 'csv' ? 'csv' : 'json';
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'application/json') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="'
        . results_export::filename($query, $level, $format) . '"');
    header('Cache-Control: no-store');

    results_export::stream($query, $level, $format);
    die();
}
// If a resource limit is hit anyway — a time limit on a slow database, memory
// on a server configured below Moodle's own minimum — the page stops wherever
// it is. Mid-output that is a page with its tabs and nothing below them, which
// is what was reported. A fatal error is not catchable, but it does reach the
// shutdown handlers, and one of them can say what happened (#99).
\local_catquizlab\local\resource_guard::register($component);

// Counted before anything is read. A selection this large is declined with a
// number and a way forward, rather than rendered until the memory runs out —
// which looks like a page that simply stops after the tabs.
$size = $query->size_check();
if ($size['toolarge']) {
    echo $OUTPUT->header();
    echo \local_catquizlab\output\shell::render('results', optional_param('experimentid', 0, PARAM_INT));
    echo $OUTPUT->notification(
        get_string('results:toolarge', $component, (object) [
            'count' => $size['count'],
            'limit' => $size['limit'],
        ]),
        \core\output\notification::NOTIFY_WARNING
    );
    echo $OUTPUT->footer();
    die();
}

$page = new results_page($query, $tab, $filter);

if (!$page->tab_exists($tab)) {
    $tab = 'overview';
    $page = new results_page($query, $tab, $filter);
}

echo $OUTPUT->header();

// The same frame as every other CatQuizLab page: opening a run used to drop
// the reader out of the process they were in the middle of.
echo \local_catquizlab\output\shell::render('results', optional_param('experimentid', 0, PARAM_INT));

// The title first, then what the figures rest on (#130): the completeness
// warning and the reproducibility package used to stand above the page title,
// a large box and a button before anybody knew which page this was. They are
// in the analysis basis now — a short warning where the design is incomplete,
// the rest folded.
$reproexperiment = optional_param('experimentid', 0, PARAM_INT);
echo $OUTPUT->heading(get_string('heading:results', $component));
echo html_writer::tag('p', get_string('results:intro', $component), ['class' => 'text-muted']);

echo $page->render_filter_bar();
echo $page->render_tabs();
echo $page->render_provenance($reproexperiment);
echo $page->render_tab();

// PNG downloads (#108): drawn in the browser from each plot's own SVG, at twice
// the size, so that the file is sharp on a slide.
$PAGE->requires->js_amd_inline('
    require([], function() {
        document.addEventListener("click", function(e) {
            var button = e.target.closest("[data-download=\\"png\\"]");
            if (!button) {
                return;
            }
            var box = button.closest("[data-region=\\"catquizlab-plot-export\\"]") || button.parentNode;
            var link = box.querySelector("[data-download=\\"svg\\"]");
            if (!link) {
                return;
            }
            var image = new Image();
            image.onload = function() {
                var canvas = document.createElement("canvas");
                canvas.width = image.naturalWidth * 2;
                canvas.height = image.naturalHeight * 2;
                var ctx = canvas.getContext("2d");
                ctx.fillStyle = "#ffffff";
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
                var save = function(href) {
                    var a = document.createElement("a");
                    a.href = href;
                    a.download = button.getAttribute("data-filename") || "plot.png";
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                };
                // toBlob may hand back null (a canvas the browser will not encode);
                // the data URL is the same picture, just not streamed.
                canvas.toBlob(function(blob) {
                    save(blob ? URL.createObjectURL(blob) : canvas.toDataURL("image/png"));
                }, "image/png");
            };
            image.src = link.getAttribute("href");
        });
        // Said on the page, so that a test (and anybody looking) can tell a
        // button that does nothing yet from one that does nothing at all.
        document.documentElement.setAttribute("data-catquizlab-png", "ready");
    });
');

echo $OUTPUT->footer();
