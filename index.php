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
 * Entry point of the plugin's UI, reachable from the navbar button (next to the
 * engine's CATQUIZ button) and from Site administration > Reports. It is the
 * registry landing page: environment status, experiments and runs, each in a
 * collapsible section rendered from the local_catquizlab/manage template. The
 * create/edit forms follow with a later milestone.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_catquizlab\local\environment;
use local_catquizlab\local\registry;

// Registers the page in the admin/reports tree, sets the system context and the
// admin report layout, and enforces the page capability from settings.php
// (local/catquizlab:manage).
admin_externalpage_setup('local_catquizlab_manage');

$component = 'local_catquizlab';

$statusmap = [
    registry::STATUS_DRAFT     => get_string('status:draft', $component),
    registry::STATUS_SCHEDULED => get_string('status:scheduled', $component),
    registry::STATUS_RUNNING   => get_string('status:running', $component),
    registry::STATUS_FINISHED  => get_string('status:finished', $component),
    registry::STATUS_FAILED    => get_string('status:failed', $component),
];

// Environment status: can experiments actually run on this site?
$envitems = [
    environment::catquiz_available()
        ? ['text' => get_string('env:catquizfound', $component), 'class' => 'text-success']
        : ['text' => get_string('env:catquizmissing', $component), 'class' => 'text-danger'],
    environment::adaptivequiz_available()
        ? ['text' => get_string('env:adaptivequizfound', $component), 'class' => 'text-success']
        : ['text' => get_string('env:adaptivequizmissing', $component), 'class' => 'text-danger'],
];

// Experiments defined so far, with their run counts.
$experimentrows = [];
foreach ($DB->get_records('local_catquizlab_experiment', null, 'timemodified DESC') as $experiment) {
    $experimentrows[] = [
        'name'   => $experiment->name,
        'tier'   => $experiment->tier,
        'status' => $statusmap[$experiment->status] ?? (string) $experiment->status,
        'runs'   => registry::count_runs($experiment->id),
    ];
}

// Run registry: a status summary plus the most recent runs.
$summaryparts = [];
foreach (registry::global_status_summary() as $status => $count) {
    $summaryparts[] = ($statusmap[$status] ?? (string) $status) . ': ' . $count;
}
$runrows = [];
foreach (registry::recent_runs(100) as $run) {
    $runrows[] = [
        'experiment'  => $run->experimentname,
        'tier'        => $run->tier,
        'cell'        => $run->cellkey,
        'replication' => $run->replication,
        'seed'        => $run->seed,
        'status'      => $statusmap[$run->status] ?? (string) $run->status,
    ];
}

$templatecontext = [
    'intro'       => get_string('manage:intro', $component),
    'disabled'    => !get_config($component, 'enabled'),
    'environment' => ['items' => $envitems],
    'experiments' => [
        'hasany' => $experimentrows !== [],
        'rows'   => $experimentrows,
    ],
    'runs'        => [
        'hasany'     => $runrows !== [],
        'hassummary' => $summaryparts !== [],
        'summary'    => implode(' · ', $summaryparts),
        'rows'       => $runrows,
    ],
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage:heading', $component));
echo $OUTPUT->render_from_template('local_catquizlab/manage', $templatecontext);
echo $OUTPUT->footer();
