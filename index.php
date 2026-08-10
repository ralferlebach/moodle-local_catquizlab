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
 * Management page for the CAT experiment suite.
 *
 * Entry point of the plugin's UI, reachable from the navbar button (next to
 * the engine's CATQUIZ button) and from Site administration > Reports. In this
 * release it is the registry landing page: environment status and the list of
 * defined experiments. The create/edit forms and the run registry table follow
 * with E1.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Registers the page in the admin/reports tree, sets the system context and
// the admin report layout, and enforces the page capability defined in
// settings.php (local/catquizlab:manage).
admin_externalpage_setup('local_catquizlab_manage');

$component = 'local_catquizlab';

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage:heading', $component));
echo html_writer::div(get_string('manage:intro', $component), 'text-muted mb-4');

// Environment status: can experiments actually run on this site?
$envitems = [
    \local_catquizlab\local\environment::catquiz_available()
        ? [get_string('env:catquizfound', $component), 'text-success']
        : [get_string('env:catquizmissing', $component), 'text-danger'],
    \local_catquizlab\local\environment::adaptivequiz_available()
        ? [get_string('env:adaptivequizfound', $component), 'text-success']
        : [get_string('env:adaptivequizmissing', $component), 'text-danger'],
];
$envhtml = '';
foreach ($envitems as [$text, $class]) {
    $envhtml .= html_writer::div(s($text), $class);
}
echo $OUTPUT->heading(get_string('manage:environment', $component), 4);
echo html_writer::div($envhtml, 'mb-4');

// Whether the master switch is on. Provisioning and runs stay inert while off.
if (!get_config($component, 'enabled')) {
    echo $OUTPUT->notification(get_string('manage:disabled', $component), 'warning');
}

// The experiments defined so far.
echo $OUTPUT->heading(get_string('manage:experiments', $component), 4);

$experiments = $DB->get_records('local_catquizlab_experiment', null, 'timemodified DESC');
if (!$experiments) {
    echo $OUTPUT->notification(get_string('manage:noexperiments', $component), 'info');
} else {
    $statusmap = [
        0  => get_string('status:draft', $component),
        10 => get_string('status:scheduled', $component),
        20 => get_string('status:running', $component),
        30 => get_string('status:finished', $component),
        40 => get_string('status:failed', $component),
    ];
    $table = new html_table();
    $table->head = [
        get_string('manage:col_name', $component),
        get_string('manage:col_tier', $component),
        get_string('manage:col_status', $component),
    ];
    $table->attributes['class'] = 'generaltable';
    foreach ($experiments as $experiment) {
        $table->data[] = [
            format_string($experiment->name),
            s($experiment->tier),
            $statusmap[$experiment->status] ?? (string) $experiment->status,
        ];
    }
    echo html_writer::table($table);
}

// The create/edit surface is not wired yet; be explicit about it rather than
// showing a dead button.
echo html_writer::div(get_string('manage:createhint', $component), 'alert alert-secondary mt-3');

echo $OUTPUT->footer();
