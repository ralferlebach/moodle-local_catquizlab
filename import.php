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
 * Import experiment settings from a JSON file.
 *
 * The uploaded file is untrusted input: it is decoded, its schema is checked
 * and it then goes through the same validation as a definition typed into the
 * editor. Nothing in it names a class, a callback or a path, and an import
 * never starts a sweep or a run by itself.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_catquizlab\form\import_form;
use local_catquizlab\local\experiment_io;

admin_externalpage_setup('local_catquizlab_manage');

$context = context_system::instance();
$component = 'local_catquizlab';
$pageurl = new moodle_url('/local/catquizlab/import.php');
$PAGE->set_url($pageurl);

require_capability('local/catquizlab:edit', $context);

$form = new import_form($pageurl->out(false));

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/catquizlab/index.php'));
}

$inspection = null;

if ($data = $form->get_data()) {
    $json = $form->get_file_content('definitionfile');
    if ($json === false || $json === '') {
        \core\notification::error(get_string('import:nofile', $component));
    } else {
        $inspection = experiment_io::inspect($json);

        // Storing happens only on the second submit, once the author has seen
        // the preview and chosen how to resolve any name conflict.
        $confirmed = !empty($data->confirmimport);
        if ($inspection['ok'] && $confirmed) {
            $mode = (string) ($data->conflictmode ?? experiment_io::CONFLICT_NEW);
            $targetid = $inspection['conflict']['id'] ?? null;
            try {
                $stored = experiment_io::store($inspection['definition'], $mode, $targetid);
                redirect(
                    new moodle_url('/local/catquizlab/experiment.php', ['id' => $stored['id']]),
                    get_string('notice:imported', $component),
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            } catch (moodle_exception $e) {
                \core\notification::error($e->getMessage());
            }
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('heading:import', $component));

if ($inspection !== null) {
    foreach ($inspection['errors'] as $message) {
        echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_ERROR);
    }
    foreach ($inspection['warnings'] as $message) {
        echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_WARNING);
    }
    foreach ($inspection['migrations'] as $message) {
        echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_INFO);
    }

    if ($inspection['ok']) {
        $definition = $inspection['definition'];
        $preview = $inspection['preview'];

        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm w-auto';
        $table->head = [get_string('preview:quantity', $component), get_string('preview:value', $component)];
        $table->data = [
            [get_string('form:name', $component), s((string) ($definition['name'] ?? ''))],
            [get_string('form:tier', $component), s((string) ($definition['tier'] ?? ''))],
            [get_string('form:model', $component), s((string) ($definition['model'] ?? ''))],
            [get_string('form:replications', $component), (int) ($definition['replications'] ?? 1)],
            [get_string('preview:cells', $component), (int) ($preview['cells'] ?? 0)],
            [get_string('preview:runs', $component), (int) ($preview['runs'] ?? 0)],
        ];

        echo $OUTPUT->heading(get_string('heading:importpreview', $component), 3);
        echo html_writer::table($table);

        if ($inspection['conflict'] !== null) {
            $conflict = $inspection['conflict'];
            echo $OUTPUT->notification(
                get_string('import:conflict', $component, (object) [
                    'name' => s($conflict['name']),
                    'runs' => $conflict['runs'],
                ]),
                \core\output\notification::NOTIFY_WARNING
            );
        }
    }
}

$form->display();

echo $OUTPUT->footer();
