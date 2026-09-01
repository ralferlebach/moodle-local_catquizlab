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
 * Reusable item-pool and person building blocks.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\preset_library;

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$fromexperiment = optional_param('fromexperiment', 0, PARAM_INT);
$kind = optional_param('kind', preset_library::KIND_POOL, PARAM_ALPHA);

admin_externalpage_setup('local_catquizlab_manage');

$context = context_system::instance();
$component = 'local_catquizlab';
$pageurl = new moodle_url('/local/catquizlab/presets.php');
$PAGE->set_url($pageurl);

require_capability('local/catquizlab:view', $context);

if ($action === 'delete' && $id > 0) {
    require_sesskey();
    require_capability('local/catquizlab:edit', $context);
    try {
        preset_library::delete($id);
        redirect(
            $pageurl,
            get_string('preset:deleted', $component),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Lifting a block out of an existing experiment is the path that actually gets
// used: an author builds a pool once in the editor and then wants it again.
if ($action === 'extract' && $fromexperiment > 0) {
    require_sesskey();
    require_capability('local/catquizlab:edit', $context);
    if (!in_array($kind, preset_library::kinds(), true)) {
        $kind = preset_library::KIND_POOL;
    }

    $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $fromexperiment], '*', MUST_EXIST);
    $definition = json_decode((string) $experiment->configjson, true) ?: [];
    $normalised = (new experiment_definition($definition))->get_normalised();
    $payload = preset_library::extract($normalised, $kind);

    $name = $experiment->name . ' — ' . get_string('preset:kind' . $kind, $component);
    $newid = preset_library::save(
        $kind,
        $name,
        $payload,
        get_string('preset:extractedfrom', $component, format_string($experiment->name))
    );

    redirect(
        $pageurl,
        get_string('preset:extracted', $component, format_string($name)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('heading:presets', $component));
echo html_writer::tag('p', get_string('preset:intro', $component), ['class' => 'text-muted']);

$rows = preset_library::listing();

if ($rows === []) {
    echo $OUTPUT->notification(get_string('preset:none', $component), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = [
        get_string('preset:kind', $component),
        get_string('form:name', $component),
        get_string('preset:summary', $component),
        get_string('preset:fingerprint', $component),
        get_string('preset:usecount', $component),
        get_string('manage:col_actions', $component),
    ];

    foreach ($rows as $row) {
        $actions = '';
        if (has_capability('local/catquizlab:edit', $context) && !$row['locked']) {
            $actions = html_writer::link(
                new moodle_url($pageurl, ['action' => 'delete', 'id' => $row['id'], 'sesskey' => sesskey()]),
                get_string('delete'),
                ['class' => 'text-danger']
            );
        } else if ($row['locked']) {
            $actions = html_writer::tag('span', get_string('preset:lockedshort', $component), [
                'class' => 'text-muted small',
            ]);
        }

        $table->data[] = [
            s($row['kindlabel']),
            s($row['name']),
            s($row['summary']),
            // The fingerprint is what lets a reader confirm two experiments
            // really shared a blueprint rather than merely sharing a name.
            html_writer::tag('code', s($row['fingerprint'])),
            $row['usecount'],
            $actions,
        ];
    }

    echo html_writer::table($table);
}

// Offer to lift a block out of any existing experiment.
if (has_capability('local/catquizlab:edit', $context)) {
    $experiments = $DB->get_records_menu('local_catquizlab_experiment', null, 'name ASC', 'id, name');
    if ($experiments) {
        echo $OUTPUT->heading(get_string('preset:extractheading', $component), 3);
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false),
            'class' => 'form-inline mb-3']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'extract']);
        echo html_writer::select($experiments, 'fromexperiment', '', false, [
            'class' => 'custom-select mr-2',
            'aria-label' => get_string('preset:extractfrom', $component),
        ]);
        echo html_writer::select([
            preset_library::KIND_POOL    => get_string('preset:kindpool', $component),
            preset_library::KIND_PERSONS => get_string('preset:kindpersons', $component),
        ], 'kind', preset_library::KIND_POOL, false, [
            'class' => 'custom-select mr-2',
            'aria-label' => get_string('preset:kind', $component),
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'class' => 'btn btn-secondary',
            'value' => get_string('preset:extractsubmit', $component),
        ]);
        echo html_writer::end_tag('form');
    }
}

echo $OUTPUT->footer();
