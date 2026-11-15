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
 * Ad-hoc task that prepares a started experiment at its turn in the line (#151).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

use local_catquizlab\local\execution_queue;
use local_catquizlab\local\experiment_runner;

/**
 * Prepares an experiment off the web request: its runs, and every draft run started with its own setup task.
 *
 * Queued by the execution line when the experiment reaches its front (execution_queue::advance()), and again on
 * every tick while the experiment has runs to create or start — a task that failed for good, or never ran, is
 * replaced. Runs as the person who started the experiment, so that the run's checks ask about their rights.
 *
 * Safe to run again and twice at once: under a lock per experiment, it creates runs only when there are none and
 * starts only draft runs.
 */
class prepare_experiment extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:prepareexperiment', 'local_catquizlab');
    }

    /**
     * Prepare the experiment.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $experimentid = (int) ($data->experimentid ?? 0);
        \local_catquizlab\local\debug_trace::enter_task(
            '\\local_catquizlab\\task\\prepare_experiment',
            (string) ($data->correlationid ?? ''),
            (int) $this->get_id(),
            (int) $this->get_fail_delay(),
            (int) $this->get_attempts_available()
        );
        if ($experimentid <= 0 || !$DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
            return;
        }
        // Only while the line holds it: stopped, deleted or taken out meanwhile, it is not prepared after all.
        if (execution_queue::state_of($experimentid) !== execution_queue::RUNNING) {
            mtrace("local_catquizlab: experiment {$experimentid} is no longer in the line; not prepared.");
            return;
        }

        $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_prepare')->get_lock('experiment' . $experimentid, 0);
        if (!$lock) {
            // Another one is at it; the next tick asks again if anything is left.
            mtrace("local_catquizlab: experiment {$experimentid} is being prepared already.");
            return;
        }
        try {
            $result = experiment_runner::prepare_in_background($experimentid);
        } catch (\Throwable $e) {
            execution_queue::note_preparation($experimentid, execution_queue::PREPARING . ': ' . $e->getMessage());
            \local_catquizlab\local\debug_trace::record('task', 'prepare_failed', [
                'experimentid' => $experimentid, 'error' => \core_text::substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }

        // What it did, where the line shows it: preparing, or what it waits for.
        execution_queue::note_preparation(
            $experimentid,
            $result['ok'] ? execution_queue::PREPARING : execution_queue::PREPARING . ': ' . $result['reason']
        );
        \local_catquizlab\local\debug_trace::record('task', 'prepare_experiment', ['experimentid' => $experimentid] + $result);
        mtrace("local_catquizlab: experiment {$experimentid} prepared in the background: {$result['created']} run(s) created, "
            . "{$result['started']} started, {$result['blocked']} not started" . ($result['reason'] !== ''
                ? " ({$result['reason']})" : '') . '.');
    }

    /**
     * Queue the preparation of an experiment, once: a task already waiting for it is not doubled.
     *
     * @param int $experimentid The experiment.
     * @param int $userid Who started it; the task runs as them.
     * @return void
     */
    public static function queue(int $experimentid, int $userid = 0): void {
        $task = new self();
        $task->set_custom_data(['experimentid' => $experimentid]);
        $user = $userid > 0 ? \core_user::get_user($userid, 'id, deleted, suspended') : false;
        if ($user && empty($user->deleted) && empty($user->suspended)) {
            $task->set_userid($userid);
        }
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
