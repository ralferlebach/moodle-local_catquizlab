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
 * Operations: system health, workers, the attempt queue and recovery.
 *
 * Everything here was previously only answerable over SSH and a database
 * client: whether a worker is ready, whether one is running, how many attempts
 * wait, which one is stuck and since when, and whether the pipeline is blocked
 * or merely slow. A plugin whose normal operation requires a shell is not
 * finished, however complete its features are.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\system_health;
use local_catquizlab\local\worker_launcher;
use local_catquizlab\local\worker_registry;

$action = optional_param('action', '', PARAM_ALPHA);
$runid = optional_param('runid', 0, PARAM_INT);

admin_externalpage_setup(registry::ADMIN_PAGE);

$component = 'local_catquizlab';
$context = context_system::instance();
$pageurl = new moodle_url('/local/catquizlab/index.php', ['tab' => 'setup']);
$PAGE->set_url($pageurl);

if ($action !== '') {
    require_sesskey();
    require_capability('local/catquizlab:execute', $context);

    if ($action === 'wizard' || $action === 'wizardstart') {
        // One button for the whole dependency chain, in order. Doing the
        // stages separately is still possible below; this is for the case where
        // somebody just wants the installation to work.
        $result = \local_catquizlab\local\setup_wizard::run($action === 'wizardstart');

        $message = $result['changed'] === []
            ? get_string('wizard:nothingchanged', $component)
            : get_string('wizard:changed', $component, implode(', ', $result['changed']));

        if (!$result['ready']) {
            $blockers = \local_catquizlab\local\setup_wizard::state()['blockers'];
            $message .= ' ' . get_string('wizard:notready', $component, implode(', ', $blockers));
        }

        redirect(
            $pageurl,
            $message,
            null,
            $result['ready']
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING
        );
    }

    if ($action === 'setupruntime') {
        $result = \local_catquizlab\local\worker_runtime::ensure();
        redirect(
            $pageurl,
            $result['ok']
                ? ($result['changed'] === []
                    ? get_string('runtime:setupnothing', $component)
                    : get_string('runtime:setupdone', $component, implode(', ', $result['changed'])))
                : get_string('runtime:setupfailed', $component, implode(', ', $result['missing'])),
            null,
            $result['ok']
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING
        );
    }

    if ($action === 'setupaccess') {
        // The whole access, not just the token: ten steps across four areas of
        // the administration, any one of which missing looks the same from
        // outside.
        $result = \local_catquizlab\local\worker_access::ensure();

        if (!$result['ok']) {
            $missing = [];
            foreach ($result['steps'] as $step) {
                if (!$step['ok']) {
                    $missing[] = $step['label'];
                }
            }
            redirect(
                $pageurl,
                get_string('access:setupfailed', $component, implode(', ', $missing)),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        redirect(
            $pageurl,
            $result['changed'] === []
                ? get_string('access:setupnothing', $component)
                : get_string('access:setupdone', $component, implode(', ', array_unique($result['changed']))),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'installbrowser') {
        $result = worker_launcher::install_browser(worker_launcher::config_from_settings());
        $SESSION->catquizlab_selftest = $result;
        redirect($pageurl);
    }

    if ($action === 'selftest') {
        $result = worker_launcher::self_test(worker_launcher::config_from_settings());
        $SESSION->catquizlab_selftest = $result;
        redirect($pageurl);
    }

    if ($action === 'setphpcli') {
        // Moodle's own setting, written the way Moodle writes it — and only by
        // somebody who may change site configuration, because that is whose
        // setting it is.
        require_capability('moodle/site:config', $context);

        $found = \local_catquizlab\local\setup_wizard::find_php_cli();
        if ($found === null) {
            redirect(
                $pageurl,
                get_string('health:phpclimissing', $component),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        set_config('pathtophp', $found);
        redirect($pageurl, get_string('health:phpcliset', $component, $found));
    }

    if ($action === 'killpipeline') {
        if (!optional_param('confirm', 0, PARAM_BOOL)) {
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(
                get_string('purge:confirmpipeline', $component),
                new moodle_url('/local/catquizlab/operations.php', [
                    'action' => 'killpipeline', 'sesskey' => sesskey(), 'confirm' => 1,
                ]),
                $pageurl
            );
            echo $OUTPUT->footer();
            exit;
        }

        $result = \local_catquizlab\local\purger::kill_pipeline();
        redirect($pageurl, get_string('purge:done', $component, sprintf(
            '%d tasks, %d workers, %d attempts',
            $result['tasks'],
            $result['workers'],
            $result['attempts']
        )));
    }

    if ($action === 'killtasks') {
        $count = \local_catquizlab\local\purger::kill_tasks();
        redirect($pageurl, $count > 0
            ? get_string('purge:done', $component, $count . ' tasks')
            : get_string('purge:nothing', $component));
    }

    if ($action === 'killworkers') {
        $result = \local_catquizlab\local\purger::kill_workers();
        redirect($pageurl, get_string('purge:done', $component, sprintf(
            '%d workers, %d attempts released',
            $result['workers'],
            $result['attempts']
        )));
    }

    if ($action === 'purgeempty') {
        $purged = \local_catquizlab\local\engine_hygiene::purge_empty_attempts();
        redirect(
            $pageurl,
            $purged > 0
                ? get_string('ops:emptypurged', $component, $purged)
                : get_string('ops:noempty', $component)
        );
    }

    if ($action === 'reap') {
        $reaped = worker_registry::reap();
        redirect(
            $pageurl,
            $reaped['workers'] > 0
                ? get_string('ops:reaped', $component, (object) $reaped)
                : get_string('ops:nothingtoreap', $component)
        );
    }

    if ($action === 'releaseorphans') {
        // The deliberate counterpart to the scheduled recovery: an operator who
        // knows the workers are gone should not have to wait out a timeout.
        $released = attempt_scheduler::reclaim_stale(null, 0);
        redirect(
            $pageurl,
            $released > 0
                ? get_string('ops:orphansreleased', $component, $released)
                : get_string('ops:noorphans', $component)
        );
    }

    if ($action === 'startworkers') {
        $result = worker_launcher::launch_pool(worker_launcher::config_from_settings());
        $launched = (int) ($result['launched'] ?? 0);
        redirect(
            $pageurl,
            $launched > 0
                ? get_string('ops:workersstarted', $component, $launched)
                : get_string('ops:workersnotstarted', $component, $result['reason'] ?? 'not-configured'),
            null,
            $launched > 0
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING
        );
    }

    if (($action === 'pauserun' || $action === 'resumerun') && $runid > 0) {
        $paused = $action === 'pauserun';
        run_lifecycle::set_paused($runid, $paused);
        redirect(
            $pageurl,
            get_string($paused ? 'ops:runpaused' : 'ops:runresumed', $component, $runid)
        );
    }
}

// Everything is shown on the plugin's own page now. This file stays for the
// action posts and for anyone who bookmarked it, and sends them there.
redirect(new moodle_url('/local/catquizlab/index.php', ['tab' => 'setup']));
