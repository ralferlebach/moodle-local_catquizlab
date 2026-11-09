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
 * Ad-hoc task that deletes an experiment off the web request (0.7.49).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

use local_catquizlab\local\purger;

/**
 * Deletes an experiment in the background.
 *
 * Deleting deeply is slow by nature, not by a loop of the lab's: every simulated account goes through core's
 * delete_user() (about 80–125 queries each) and every generated question through question_delete_question()
 * (about 30). Six runs of 2000 people and 2500 items are some 1.5 million queries — minutes, which a web request
 * did not survive. The click now stops the experiment at once and leaves the deleting to this task.
 *
 * Safe to run again: what is already gone is skipped, so a task that died half way finishes on its retry.
 */
class delete_experiment extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:deleteexperiment', 'local_catquizlab');
    }

    /**
     * Delete the experiment.
     *
     * @return void
     */
    public function execute(): void {
        \local_catquizlab\local\debug_trace::enter_task(
            '\\local_catquizlab\\task\\delete_experiment',
            (string) ($this->get_custom_data()->correlationid ?? ''),
            (int) $this->get_id(),
            (int) $this->get_fail_delay(),
            (int) $this->get_attempts_available()
        );

        $data = $this->get_custom_data();
        $experimentid = (int) ($data->experimentid ?? 0);
        if ($experimentid <= 0) {
            return;
        }

        $started = microtime(true);
        try {
            $result = purger::delete_experiment($experimentid, !empty($data->deep), true);
        } catch (\Throwable $e) {
            // Said where the experiment is listed (0.7.50): a delete that failed had left the experiment looking
            // untouched, the reason only in the task log. Moodle retries the task; the list says so meanwhile.
            self::record_error($experimentid, $e->getMessage());
            throw $e;
        }
        if (!$result['ok']) {
            // Gone already (a second task, or a delete from the console): nothing left to do.
            mtrace("local_catquizlab: experiment {$experimentid} not deleted: {$result['reason']}.");
            return;
        }
        mtrace("local_catquizlab: experiment {$experimentid} deleted in " . round(microtime(true) - $started, 1) . ' s: '
            . purger::counts_line($result['removed']) . '.');
    }

    /**
     * Queue the deletion of an experiment, once.
     *
     * @param int $experimentid The experiment.
     * @param bool $deep Also the accounts, questions and activities its runs created.
     * @return void
     */
    public static function queue(int $experimentid, bool $deep): void {
        global $DB, $USER;

        // The experiment itself says it is being deleted (0.7.50), from the click on: the list read this off the
        // task queue before, and an experiment whose task had failed or gone looked as if nothing had happened.
        $DB->update_record('local_catquizlab_experiment', (object) [
            'id' => $experimentid, 'timedeleting' => time(), 'deleteerror' => null,
        ]);

        $task = new self();
        $task->set_custom_data([
            'experimentid' => $experimentid,
            'deep' => $deep,
            'correlationid' => \local_catquizlab\local\debug_trace::correlation_id(),
        ]);
        // Run as whoever asked for it, so the log names them.
        if (!empty($USER->id)) {
            $task->set_userid((int) $USER->id);
        }
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Keep why deleting an experiment failed, where the list can show it.
     *
     * @param int $experimentid The experiment.
     * @param string $message The error.
     * @return void
     */
    protected static function record_error(int $experimentid, string $message): void {
        global $DB;

        try {
            // Written inside a transaction the failure left open, it goes when the task runner rolls that back; the
            // stop rolls its own back (0.7.50), the deleting itself works outside one.
            if ($DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
                $DB->set_field('local_catquizlab_experiment', 'deleteerror', \core_text::substr($message, 0, 1000), [
                    'id' => $experimentid,
                ]);
            }
        } catch (\Throwable $ignored) {
            // The database is what failed; the task log keeps the error.
            unset($ignored);
        }
    }

    /**
     * Where the experiments being deleted stand.
     *
     * An experiment is being deleted from the confirmation on (its timedeleting), or while a task for it waits —
     * the latter also for one queued by 0.7.49, before the experiment kept the state itself.
     *
     * @return array<int, array{since: int, error: string, pending: bool}> By experiment id.
     */
    public static function states(): array {
        global $DB;

        $states = [];
        $pending = array_flip(self::pending());
        $rows = $DB->get_records_select(
            'local_catquizlab_experiment',
            'timedeleting > 0',
            null,
            '',
            'id, timedeleting, deleteerror'
        );
        foreach ($rows as $row) {
            $states[(int) $row->id] = [
                'since' => (int) $row->timedeleting,
                'error' => (string) ($row->deleteerror ?? ''),
                'pending' => isset($pending[(int) $row->id]),
            ];
        }
        foreach (array_keys($pending) as $id) {
            $states[$id] ??= ['since' => 0, 'error' => '', 'pending' => true];
        }

        return $states;
    }

    /**
     * The experiments waiting to be deleted.
     *
     * @return int[] Their ids.
     */
    public static function pending(): array {
        global $DB;

        $ids = [];
        $rows = $DB->get_records('task_adhoc', ['classname' => '\\' . self::class], '', 'id, customdata');
        foreach ($rows as $row) {
            $id = (int) (json_decode((string) $row->customdata, true)['experimentid'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
