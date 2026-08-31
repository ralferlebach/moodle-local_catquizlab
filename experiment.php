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
 * Create, edit and expand an experiment.
 *
 * The page resolves its parameters, checks the capability for exactly the
 * action it performs, calls the experiment service and renders the result. All
 * experiment logic lives in the service, which the CLI and the API use too.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_catquizlab\form\experiment_form;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_io;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\preset_library;

$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

admin_externalpage_setup('local_catquizlab_manage');

$context = context_system::instance();
$component = 'local_catquizlab';
$pageurl = new moodle_url('/local/catquizlab/experiment.php', $id ? ['id' => $id] : []);
$manageurl = new moodle_url('/local/catquizlab/index.php');
$PAGE->set_url($pageurl);

// Reading the editor needs only view rights; every state change below asks for
// the capability that belongs to that specific action.
require_capability('local/catquizlab:view', $context);

$existing = null;
$definition = [];
if ($id > 0) {
    $existing = $DB->get_record('local_catquizlab_experiment', ['id' => $id], '*', MUST_EXIST);
    $definition = json_decode((string) $existing->configjson, true) ?: [];
}

// Actions that change state.
// Each is a POST guarded by sesskey plus the capability for that action, so a
// link cannot start a sweep and a viewer cannot delete an experiment.

if ($action === 'export' && $id > 0) {
    require_capability('local/catquizlab:export', $context);
    $variant = optional_param('variant', experiment_io::VARIANT_DECLARATIVE, PARAM_ALPHA);
    if (!in_array($variant, [experiment_io::VARIANT_DECLARATIVE, experiment_io::VARIANT_NORMALISED], true)) {
        $variant = experiment_io::VARIANT_DECLARATIVE;
    }
    $json = experiment_io::export($id, $variant);
    send_file(
        $json,
        experiment_io::filename($id, $variant),
        0,
        0,
        true,
        true,
        'application/json'
    );
    die();
}

if ($action === 'duplicate' && $id > 0) {
    require_sesskey();
    require_capability('local/catquizlab:edit', $context);
    $copyid = experiment_service::duplicate($id);
    redirect(
        new moodle_url('/local/catquizlab/experiment.php', ['id' => $copyid]),
        get_string('notice:duplicated', $component),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'archive' && $id > 0) {
    require_sesskey();
    require_capability('local/catquizlab:edit', $context);
    experiment_service::archive($id);
    redirect(
        $manageurl,
        get_string('notice:archived', $component),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'delete' && $id > 0) {
    require_sesskey();
    require_capability('local/catquizlab:edit', $context);
    try {
        experiment_service::delete($id);
        redirect(
            $manageurl,
            get_string('notice:deleted', $component),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'createsweep' && $id > 0) {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);
    try {
        $result = experiment_service::create_sweep($id);
        redirect(
            $manageurl,
            get_string('notice:sweepcreated', $component, $result['created']),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// The editor.

$customdata = ['existing' => $existing];
$form = new experiment_form($pageurl->out(false), $customdata);

if ($form->is_cancelled()) {
    redirect($manageurl);
}

$preview = null;
$validation = null;
$notes = [];

if ($data = $form->get_data()) {
    require_capability('local/catquizlab:edit', $context);

    $submitted = experiment_form::to_definition((array) $data);
    try {
        $result = experiment_service::save($submitted, $id > 0 ? $id : null);
        redirect(
            new moodle_url('/local/catquizlab/experiment.php', ['id' => $result['id']]),
            get_string('notice:saved', $component),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
} else if ($id > 0) {
    $form->set_data(experiment_form::to_form_data($definition, $id));
    $validation = experiment_service::validate($definition);
    $preview = experiment_service::preview($definition);

    // Remarks that are neither defects nor doubts: an experiment with runs is
    // read-only, and a cited building block is worth naming.
    if (experiment_service::run_count($id) > 0) {
        $notes[] = get_string('editor:hasruns', $component);
    }
    $normalisedfornotes = $validation['normalised'];
    foreach (['poolpreset' => 'preset:kindpool', 'personspreset' => 'preset:kindpersons'] as $field => $label) {
        $presetid = (int) ($normalisedfornotes[$field] ?? 0);
        if ($presetid > 0 && ($preset = preset_library::get($presetid)) !== null) {
            $notes[] = get_string('editor:usesblock', $component, (object) [
                'kind' => get_string($label, $component),
                'name' => $preset['name'],
            ]);
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading($id > 0
    ? get_string('heading:editexperiment', $component)
    : get_string('heading:newexperiment', $component));

// The definition currently on screen: the stored one when editing, the
// defaults when creating. It drives the section summaries so the navigation
// describes the design rather than repeating the section titles.
$current = $id > 0
    ? (new experiment_definition($definition))->get_normalised()
    : experiment_definition::apply_defaults([]);

$sections = experiment_form::sections($current);

$validationcontext = false;
if ($validation !== null) {
    $validationcontext = [
        'valid'        => $validation['valid'],
        'checked'      => true,
        'errorcount'   => count($validation['errors']),
        'warningcount' => count($validation['warnings']),
        // Notes are informational remarks that neither block a run nor
        // question it; the count is shown so an empty panel is not mistaken
        // for an unchecked one.
        'infocount'    => count($notes),
        'errors'       => array_map(
            static fn(string $message): array => ['message' => $message],
            $validation['errors']
        ),
        'warnings'     => array_map(
            static fn(string $message): array => ['message' => $message],
            $validation['warnings']
        ),
        'notes'        => array_map(
            static fn(string $message): array => ['message' => $message],
            $notes
        ),
    ];
} else {
    $validationcontext = ['checked' => false, 'valid' => false];
}

$previewcontext = false;
if ($preview !== null && $preview['runs'] > 0) {
    $previewcontext = [
        'cells'        => $preview['cells'],
        'replications' => $preview['replications'],
        'runs'         => $preview['runs'],
        'attempts'     => $preview['attempts'],
        'large'        => $preview['large'],
        'cansweep'     => has_capability('local/catquizlab:execute', $context),
        'sesskey'      => sesskey(),
        'experimentid' => $id,
        'createurl'    => (new moodle_url('/local/catquizlab/experiment.php'))->out(false),
    ];
}

$exchangecontext = false;
if ($id > 0 && has_capability('local/catquizlab:export', $context)) {
    $exchangecontext = [
        'declarativeurl' => (new moodle_url('/local/catquizlab/experiment.php', [
            'id' => $id, 'action' => 'export', 'variant' => experiment_io::VARIANT_DECLARATIVE,
        ]))->out(false),
        'normalisedurl'  => (new moodle_url('/local/catquizlab/experiment.php', [
            'id' => $id, 'action' => 'export', 'variant' => experiment_io::VARIANT_NORMALISED,
        ]))->out(false),
    ];
}

echo $OUTPUT->render_from_template('local_catquizlab/experiment_editor', [
    'sections'   => $sections,
    'form'       => $form->render(),
    'validation' => $validationcontext,
    'preview'    => $previewcontext,
    'exchange'   => $exchangecontext,
]);

echo $OUTPUT->footer();
