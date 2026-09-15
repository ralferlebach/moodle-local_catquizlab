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
 * Actions on the tasks this plugin depends on.
 *
 * The view lives on the setup tab; this file performs what its buttons ask for
 * and sends the person back. Running a scheduled task from here is the answer
 * to the most common cause of a pipeline sitting still — cron not running —
 * without asking somebody to go and find it in the task administration.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_catquizlab\local\registry;

$action = required_param('action', PARAM_ALPHA);
$classname = optional_param('classname', '', PARAM_RAW_TRIMMED);

admin_externalpage_setup(registry::ADMIN_PAGE);

$component = 'local_catquizlab';
$context = context_system::instance();
$returnurl = new moodle_url('/local/catquizlab/index.php', ['tab' => 'setup']);

require_sesskey();
require_capability('local/catquizlab:execute', $context);

if ($action === 'runscheduled' && $classname !== '') {
    // Only this plugin's own tasks: a general "run any task" button on a plugin
    // page is a way to run somebody else's task by accident.
    if (!in_array($classname, \local_catquizlab\local\task_overview::SCHEDULED, true)) {
        redirect(
            $returnurl,
            get_string('task:notours', $component),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $task = \core\task\manager::get_scheduled_task($classname);
    if ($task === false) {
        redirect(
            $returnurl,
            get_string('task:notfound', $component),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // The task may take a while and writes as it goes; holding the session open
    // for it would block every other request from this person.
    \core\session\manager::write_close();

    $error = '';
    ob_start();
    try {
        $task->execute();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
    $output = trim((string) ob_get_clean());

    // The task's own output is worth keeping: mtrace() is how these tasks say
    // what they did, and discarding it leaves "the task ran" and nothing else.
    $message = $error !== ''
        ? get_string('task:ranwitherror', $component, $error)
        : get_string('task:ran', $component);
    if ($output !== '') {
        $message .= ' ' . \core_text::substr($output, 0, 400);
    }

    redirect($returnurl, $message, null, $error !== ''
        ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS);
}

redirect($returnurl);
