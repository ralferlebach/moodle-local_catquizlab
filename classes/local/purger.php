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

namespace local_catquizlab\local;

/**
 * Deleting things, properly and on purpose.
 *
 * Resetting a run keeps its identity and clears what provisioning made, which
 * suits a run somebody still wants. This is the other half: a run, a task, a
 * worker or a set of results that is simply in the way, and the only useful
 * thing left to do with it is make it stop existing.
 *
 * Everything here is irreversible, so each method is narrow about what it
 * touches and honest about what it leaves:
 *
 * - Only rows this plugin created. Engine attempts belonging to this lab's
 *   simulated persons count as ours; anybody else's do not, whatever state
 *   they are in.
 * - Engine-side objects — scales, questions, the activity — are removed only
 *   when asked for explicitly, because they are shared and a run may not be
 *   their only user.
 * - A run being played right now is refused rather than deleted from under its
 *   worker, which would strand the claim the worker holds.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purger {
    /**
     * What deleting an experiment would remove, before it is removed.
     *
     * An irreversible action needs to be able to say what it will do, and in
     * terms of the things it will take: not "this cannot be undone" but "this
     * removes 4 runs, 150 attempts, 96 questions and 1 adaptive quiz".
     *
     * @param int $experimentid The experiment.
     * @param bool $deep Whether the engine-side objects are included.
     * @return array{ok: bool, name: string, counts: array, blockers: string[]}
     */
    public static function preview_experiment(int $experimentid, bool $deep = true): array {
        global $DB;

        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid]);
        if (!$experiment) {
            return ['ok' => false, 'name' => '', 'counts' => [], 'blockers' => ['experiment-not-found']];
        }

        $runids = $DB->get_fieldset_select('local_catquizlab_run', 'id', 'experimentid = ?', [$experimentid]);
        $runids = array_map('intval', $runids);

        $counts = ['runs' => count($runids)];
        $blockers = [];

        if ($runids === []) {
            return ['ok' => true, 'name' => format_string($experiment->name), 'counts' => $counts, 'blockers' => []];
        }

        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'r');

        $tables = [
            'local_catquizlab_attempt'  => 'attempts',
            'local_catquizlab_person'   => 'people',
            'local_catquizlab_item'     => 'items',
            'local_catquizlab_scalemap' => 'scales',
            'local_catquizlab_result'   => 'results',
        ];

        foreach ($tables as $table => $label) {
            if ($DB->get_manager()->table_exists($table)) {
                $counts[$label] = $DB->count_records_select($table, 'runid ' . $insql, $params);
            }
        }

        // A run being played cannot be deleted from under its worker, and
        // saying so before the button is pressed beats saying so after.
        foreach ($runids as $runid) {
            $status = (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]);
            $playing = $status === registry::STATUS_RUNNING && run_lifecycle::has_open_attempts($runid);
            if ($playing) {
                $blockers[] = get_string('purge:runbeingplayed', 'local_catquizlab', $runid);
            }
        }

        if ($deep) {
            $counts['activities'] = $DB->count_records_select(
                'local_catquizlab_run',
                'id ' . $insql . ' AND testcmid > 0',
                $params
            );
            if ($DB->get_manager()->table_exists('local_catquizlab_item')) {
                $counts['questions'] = $DB->count_records_select(
                    'local_catquizlab_item',
                    'runid ' . $insql . ' AND questionid > 0',
                    $params
                );
            }
        }

        // The log is the one thing deliberately not counted as a loss: it goes
        // with the runs it describes, and by then there is nothing left for it
        // to describe.
        $counts['tasks'] = self::count_tasks_for_runs($runids);

        return [
            'ok'       => $blockers === [],
            'name'     => format_string($experiment->name),
            'counts'   => array_filter($counts),
            'blockers' => $blockers,
        ];
    }

    /**
     * How many of this plugin's tasks are queued for a set of runs.
     *
     * @param int[] $runids The runs.
     * @return int
     */
    protected static function count_tasks_for_runs(array $runids): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(task_overview::ADHOC, SQL_PARAMS_NAMED, 'cls');

        $count = 0;
        foreach ($DB->get_records_select('task_adhoc', 'classname ' . $insql, $params) as $row) {
            $data = json_decode((string) $row->customdata, true) ?: [];
            if (in_array((int) ($data['runid'] ?? 0), $runids, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Delete a run and everything the lab made for it.
     *
     * @param int $runid The run.
     * @param bool $deep Also remove the engine-side objects it created.
     * @param bool $force Delete even while an attempt is being played.
     * @return array{ok: bool, removed: array, reason: string}
     */
    public static function delete_run(int $runid, bool $deep = false, bool $force = false): array {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run) {
            return ['ok' => false, 'removed' => [], 'reason' => 'run-not-found'];
        }

        if (
            !$force && run_lifecycle::has_open_attempts($runid)
                && (int) $run->status === registry::STATUS_RUNNING
        ) {
            // Deleting under a worker strands the claim it holds. Forcing is
            // offered because a worker can be gone without having said so, and
            // then this is the only way out.
            return ['ok' => false, 'removed' => [], 'reason' => 'run-is-being-played'];
        }

        $removed = [];

        if ($deep) {
            $removed += self::delete_engine_objects($runid);
            // The accounts and enrolments this run created. Deep deletion is the
            // one that promises to leave nothing, and leaving a hundred
            // simulated students in the user list is leaving something.
            $removed += self::delete_simulated_people($runid);
        }

        $removed += self::delete_lab_rows($runid);

        // Its own queued tasks: leaving them behind means a task that wakes up
        // to provision a run that is gone.
        $removed['tasks'] = self::delete_tasks_for_run($runid);

        $experimentid = (int) $run->experimentid;

        // The log describes a run that is about to stop existing. Keeping it
        // would leave a history of something nobody can look at.
        if ($DB->get_manager()->table_exists('local_catquizlab_runlog')) {
            $removed['logentries'] = $DB->count_records('local_catquizlab_runlog', ['runid' => $runid]);
            $DB->delete_records('local_catquizlab_runlog', ['runid' => $runid]);
        }

        $DB->delete_records('local_catquizlab_run', ['id' => $runid]);
        $removed['run'] = 1;

        if ($experimentid > 0 && $DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
            run_lifecycle::refresh_experiment_by_id($experimentid);
        }

        return ['ok' => true, 'removed' => array_filter($removed), 'reason' => ''];
    }

    /**
     * Delete an experiment with all of its runs.
     *
     * @param int $experimentid The experiment.
     * @param bool $deep Also remove the engine-side objects.
     * @param bool $force Delete even while attempts are being played.
     * @return array{ok: bool, removed: array, reason: string}
     */
    public static function delete_experiment(int $experimentid, bool $deep = false, bool $force = false): array {
        global $DB;

        if (!$DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
            return ['ok' => false, 'removed' => [], 'reason' => 'experiment-not-found'];
        }

        // A worker mid-attempt is asked to finish and stop, not overruled.
        // Forcing past it strands the claim it holds and leaves a browser
        // playing a quiz whose questions are being deleted underneath it.
        $stopping = self::ask_workers_to_stop($experimentid);
        if ($stopping > 0 && !$force) {
            return [
                'ok'       => false,
                'removed'  => [],
                'reason'   => 'workers-stopping',
                'stopping' => $stopping,
            ];
        }

        $totals = ['runs' => 0];
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC', 'id') as $run) {
            $result = self::delete_run((int) $run->id, $deep, $force);
            if (!$result['ok']) {
                // Stopping rather than leaving half an experiment: a partial
                // delete is harder to reason about than none.
                return ['ok' => false, 'removed' => $totals, 'reason' => $result['reason']];
            }
            foreach ($result['removed'] as $key => $count) {
                $totals[$key] = ($totals[$key] ?? 0) + $count;
            }
            $totals['runs']++;
        }

        $DB->delete_records('local_catquizlab_experiment', ['id' => $experimentid]);
        $totals['experiment'] = 1;

        return ['ok' => true, 'removed' => array_filter($totals), 'reason' => ''];
    }

    /**
     * Ask any worker playing this experiment to finish and stop.
     *
     * @param int $experimentid The experiment.
     * @return int How many workers were asked.
     */
    protected static function ask_workers_to_stop(int $experimentid): int {
        global $DB;

        $owners = $DB->get_fieldset_sql(
            'SELECT DISTINCT a.leaseowner
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE r.experimentid = :experimentid
                AND a.status = :running
                AND a.leaseexpires > :now
                AND a.leaseowner IS NOT NULL',
            [
                'experimentid' => $experimentid,
                'running'      => attempt_scheduler::STATUS_RUNNING,
                'now'          => time(),
            ]
        );

        $asked = 0;
        foreach (array_filter($owners) as $workerid) {
            if (worker_registry::request_stop((string) $workerid)) {
                $asked++;
            }
        }

        return $asked;
    }

    /**
     * Delete the results of a run, keeping the run and its traces.
     *
     * @param int $runid The run.
     * @return int How many result rows went.
     */
    public static function delete_results(int $runid): int {
        global $DB;

        $count = 0;
        foreach (['local_catquizlab_result'] as $table) {
            if ($DB->get_manager()->table_exists($table)) {
                $count += $DB->count_records($table, ['runid' => $runid]);
                $DB->delete_records($table, ['runid' => $runid]);
            }
        }

        return $count;
    }

    /**
     * Remove this plugin's queued ad-hoc tasks.
     *
     * A task that cannot complete keeps being retried with a growing delay and
     * never goes away on its own. Moodle offers no way to drop one from the
     * queue, so a stuck task is permanent unless somebody deletes the row.
     *
     * @param int|null $runid Only this run's tasks, or null for all of them.
     * @return int How many were removed.
     */
    public static function kill_tasks(?int $runid = null): int {
        if ($runid !== null) {
            return self::delete_tasks_for_run($runid);
        }

        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(task_overview::ADHOC, SQL_PARAMS_NAMED, 'cls');
        $count = $DB->count_records_select('task_adhoc', 'classname ' . $insql, $params);
        $DB->delete_records_select('task_adhoc', 'classname ' . $insql, $params);

        return $count;
    }

    /**
     * Clear the worker registry and hand back everything it was holding.
     *
     * For workers that are gone without having said so: the slot stays busy
     * until the heartbeat lapses, and with a long attempt that is a long time
     * to wait for an installation somebody is trying to use.
     *
     * @return array{workers: int, attempts: int}
     */
    public static function kill_workers(): array {
        global $DB;

        $attempts = 0;
        foreach ($DB->get_records('local_catquizlab_worker', null, '', 'id, workerid') as $worker) {
            $attempts += attempt_scheduler::release_lease((string) $worker->workerid);
        }

        $workers = $DB->count_records('local_catquizlab_worker');
        $DB->delete_records('local_catquizlab_worker');

        return ['workers' => $workers, 'attempts' => $attempts];
    }

    /**
     * Stop everything: tasks, workers, and every queued attempt.
     *
     * The blunt instrument, for an installation that is stuck rather than
     * slow. Runs and results survive — this empties the pipeline, it does not
     * discard what has been measured.
     *
     * @return array{tasks: int, workers: int, attempts: int}
     */
    public static function kill_pipeline(): array {
        global $DB;

        $tasks = self::kill_tasks();
        $workers = self::kill_workers();

        $queued = $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_QUEUED]);
        $DB->execute(
            'UPDATE {local_catquizlab_attempt}
                SET status = :failed, lasterror = :reason, leaseowner = NULL, leaseexpires = 0,
                    timemodified = :now
              WHERE status IN (:queued, :running)',
            [
                'failed' => attempt_scheduler::STATUS_FAILED,
                'reason' => 'pipeline stopped by an operator',
                'now'    => time(),
                'queued' => attempt_scheduler::STATUS_QUEUED,
                'running' => attempt_scheduler::STATUS_RUNNING,
            ]
        );

        return ['tasks' => $tasks, 'workers' => $workers['workers'], 'attempts' => $queued];
    }

    /**
     * The lab's own rows for a run.
     *
     * @param int $runid The run.
     * @return array<string, int>
     */
    protected static function delete_lab_rows(int $runid): array {
        global $DB;

        $removed = [];
        foreach (
            [
            'local_catquizlab_attempt'  => 'attempts',
            'local_catquizlab_person'   => 'people',
            'local_catquizlab_item'     => 'items',
            'local_catquizlab_scalemap' => 'scales',
            'local_catquizlab_result'   => 'results',
            ] as $table => $label
        ) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $count = $DB->count_records($table, ['runid' => $runid]);
            if ($count > 0) {
                $DB->delete_records($table, ['runid' => $runid]);
                $removed[$label] = $count;
            }
        }

        return $removed;
    }

    /**
     * Remove the simulated people a run created, and their enrolments.
     *
     * Only accounts this plugin made: they are recognisable by the run that
     * owns them, and deleting a user somebody else created because it happened
     * to be enrolled in an experiment course would be unforgivable.
     *
     * @param int $runid The run.
     * @return array What went.
     */
    public static function delete_simulated_people(int $runid): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $people = $DB->get_records('local_catquizlab_person', ['runid' => $runid]);
        if ($people === []) {
            return [];
        }

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        $courseid = $run ? (int) $run->courseid : 0;

        $removed = ['enrolments' => 0, 'users' => 0];
        foreach ($people as $person) {
            $userid = (int) $person->moodleuserid;
            if ($userid === 0) {
                continue;
            }

            if ($courseid > 0) {
                $coursecontext = \context_course::instance($courseid);
                $enrolled = is_enrolled($coursecontext, (object) ['id' => $userid], '', false);

                foreach (enrol_get_instances($courseid, true) as $instance) {
                    $plugin = enrol_get_plugin($instance->enrol);
                    if ($plugin && $enrolled) {
                        $plugin->unenrol_user($instance, $userid);
                        $removed['enrolments']++;
                    }
                }
            }

            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);

            // The run number in the username is what this plugin stamps on the
            // accounts it creates, and it names the run being deleted. An
            // account without it belongs to somebody else, and deleting a real
            // user because they happened to be enrolled in an experiment course
            // would be unforgivable.
            $ours = $user && str_contains((string) $user->username, '_r' . $runid . '_');

            if ($ours) {
                delete_user($user);
                $removed['users']++;
            }
        }

        return array_filter($removed);
    }

    /**
     * A count and what it counts, in the reader's language.
     *
     * Every preview and every result of a reset or a deletion goes through
     * here. The keys come from several places — the run tables, the engine,
     * enrolments, accounts — and they used to be turned into text in six
     * places, each with its own idea of which keys existed: one asked for a
     * string that was not there and failed with a debugging notice, the other
     * five printed the internal key ("3 enginescales") to the person.
     *
     * @param string $label The internal key.
     * @param int $count How many.
     * @return string
     */
    public static function count_label(string $label, int $count): string {
        $manager = get_string_manager();
        $key = 'purge:count' . $label;

        $name = $manager->string_exists($key, 'local_catquizlab')
            ? get_string($key, 'local_catquizlab')
            : $label;

        return $count . ' ' . $name;
    }

    /**
     * A whole set of counts as one line.
     *
     * @param array $counts Label => count.
     * @return string
     */
    public static function counts_line(array $counts): string {
        $parts = [];
        foreach ($counts as $label => $count) {
            if ((int) $count !== 0) {
                $parts[] = self::count_label((string) $label, (int) $count);
            }
        }

        return $parts === [] ? '-' : implode(', ', $parts);
    }

    /**
     * The engine-side objects a run created: its activity and its questions.
     *
     * Public because a reset needs exactly this too. A reset that clears the lab
     * rows and leaves the engine objects behind loses the mapping that said
     * which objects belonged to which run — and an engine object nobody owns is
     * not a leftover, it is a scale a later selection can still find.
     *
     * Only what this run made, and only when asked. The scales and their items
     * can be shared with other runs of the same experiment, so they are removed
     * through the lab's own record of what it created rather than by walking
     * the engine's tables.
     *
     * @param int $runid The run.
     * @return array<string, int>
     */
    public static function delete_engine_objects(int $runid): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $removed = [];
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);

        // The adaptive quiz. Deleting it takes its attempts and question usages
        // with it, which is what makes this the deep option.
        if ($run && (int) $run->testcmid > 0) {
            $cm = get_coursemodule_from_id('adaptivequiz', (int) $run->testcmid, 0, false, IGNORE_MISSING);
            if ($cm) {
                try {
                    course_delete_module($cm->id);
                    $removed['activity'] = 1;
                } catch (\Throwable $e) {
                    // Reported by its absence from the tally rather than by
                    // failing the whole delete: the lab rows still have to go.
                    $removed['activityfailed'] = 1;
                }
            }
        }

        // The questions this run generated, through the lab's own item record.
        if ($DB->get_manager()->table_exists('local_catquizlab_item')) {
            $questionids = $DB->get_fieldset_select('local_catquizlab_item', 'questionid', 'runid = ?', [$runid]);
            $removed['questions'] = self::delete_questions(array_filter(array_map('intval', $questionids)));
        }

        // Engine items and their parameters, for the scales this run owns.
        $scaleids = $DB->get_fieldset_select('local_catquizlab_scalemap', 'catscaleid', 'runid = ?', [$runid]);
        $scaleids = array_values(array_filter(array_map('intval', $scaleids)));
        if ($scaleids !== [] && $DB->get_manager()->table_exists('local_catquiz_items')) {
            [$insql, $params] = $DB->get_in_or_equal($scaleids, SQL_PARAMS_NAMED, 'sc');
            $removed['engineitems'] = $DB->count_records_select('local_catquiz_items', 'catscaleid ' . $insql, $params);
            $DB->delete_records_select('local_catquiz_items', 'catscaleid ' . $insql, $params);

            if ($DB->get_manager()->table_exists('local_catquiz_catscales')) {
                $DB->delete_records_select('local_catquiz_catscales', 'id ' . $insql, $params);
                $removed['enginescales'] = count($scaleids);
            }
        }

        return $removed;
    }

    /**
     * Delete generated questions.
     *
     * @param int[] $questionids The questions.
     * @return int How many went.
     */
    protected static function delete_questions(array $questionids): int {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        $deleted = 0;
        foreach (array_unique($questionids) as $questionid) {
            try {
                question_delete_question($questionid);
                $deleted++;
            } catch (\Throwable $e) {
                // A question still referenced by an attempt refuses to go.
                // Counting only what actually went keeps the tally honest.
                continue;
            }
        }

        return $deleted;
    }

    /**
     * This plugin's queued tasks for one run.
     *
     * @param int $runid The run.
     * @return int How many were removed.
     */
    protected static function delete_tasks_for_run(int $runid): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(task_overview::ADHOC, SQL_PARAMS_NAMED, 'cls');

        $removed = 0;
        foreach ($DB->get_records_select('task_adhoc', 'classname ' . $insql, $params) as $row) {
            $data = json_decode((string) $row->customdata, true) ?: [];
            if ((int) ($data['runid'] ?? 0) === $runid) {
                $DB->delete_records('task_adhoc', ['id' => $row->id]);
                $removed++;
            }
        }

        return $removed;
    }
}
