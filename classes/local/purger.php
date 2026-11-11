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
     * The lab's own tables that hold rows of a run, with the name each count goes by.
     *
     * One list for the preview and the delete (0.7.50): the delete had five tables and left the history of every
     * sitting, the twin claims, the pool variants, the export records and the transfer log of a deleted run behind,
     * rows pointing at a run that no longer existed.
     */
    protected const RUN_TABLES = [
        'local_catquizlab_attempt'    => 'attempts',
        'local_catquizlab_attemptlog' => 'history',
        'local_catquizlab_person'     => 'people',
        'local_catquizlab_twinclaim'  => 'twinclaims',
        'local_catquizlab_item'       => 'items',
        'local_catquizlab_scalemap'   => 'scales',
        'local_catquizlab_pool'       => 'pools',
        'local_catquizlab_result'     => 'results',
        'local_catquizlab_exportlog'  => 'exports',
        'local_catquizlab_transfer'   => 'transfers',
    ];

    /**
     * The lab's own tables that hold rows of an experiment as a whole.
     */
    protected const EXPERIMENT_TABLES = [
        'local_catquizlab_pool'      => 'pools',
        'local_catquizlab_exportlog' => 'exports',
        'local_catquizlab_execqueue' => 'queue',
    ];
    /**
     * What is still left of an experiment and its runs in the lab's tables (#145).
     *
     * @param int $experimentid The experiment.
     * @param int[] $runids Its runs, as they were before deleting began.
     * @return array<string, int> Table => rows left; empty when nothing is.
     */
    public static function left_of(int $experimentid, array $runids): array {
        global $DB;

        $left = [];
        $runs = $DB->count_records('local_catquizlab_run', ['experimentid' => $experimentid]);
        if ($runs > 0) {
            $left['local_catquizlab_run'] = $runs;
        }
        foreach (self::EXPERIMENT_TABLES as $table => $label) {
            if ($DB->get_manager()->table_exists($table)) {
                $count = $DB->count_records($table, ['experimentid' => $experimentid]);
                if ($count > 0) {
                    $left[$table] = $count;
                }
            }
        }
        foreach (array_chunk($runids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'run');
            foreach (self::RUN_TABLES as $table => $label) {
                if (!$DB->get_manager()->table_exists($table)) {
                    continue;
                }
                $count = $DB->count_records_select($table, 'runid ' . $insql, $params);
                if ($count > 0) {
                    $left[$table] = ($left[$table] ?? 0) + $count;
                }
            }
        }

        return $left;
    }

    /**
     * What deleting an experiment would remove, before it is removed.
     *
     * An irreversible action needs to be able to say what it will do, and in
     * terms of the things it will take: not "this cannot be undone" but "this
     * removes 4 runs, 150 attempts, 96 questions and 1 adaptive quiz".
     *
     * @param int $experimentid The experiment.
     * @param bool $deep Whether the engine-side objects are included.
     * @return array{ok: bool, name: string, rawname?: string, counts: array, blockers: string[], stopping: string[]}
     */
    public static function preview_experiment(int $experimentid, bool $deep = true): array {
        global $DB;

        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid]);
        if (!$experiment) {
            return ['ok' => false, 'name' => '', 'counts' => [], 'blockers' => ['experiment-not-found'], 'stopping' => []];
        }

        $runids = $DB->get_fieldset_select('local_catquizlab_run', 'id', 'experimentid = ?', [$experimentid]);
        $runids = array_map('intval', $runids);

        $counts = ['runs' => count($runids)];
        $blockers = [];

        if ($runids === []) {
            return ['ok' => true, 'name' => format_string($experiment->name), 'rawname' => (string) $experiment->name,
                'counts' => $counts, 'blockers' => [], 'stopping' => []];
        }

        [$insql, $params] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'r');

        foreach (self::RUN_TABLES as $table => $label) {
            if ($DB->get_manager()->table_exists($table)) {
                $counts[$label] = ($counts[$label] ?? 0) + $DB->count_records_select($table, 'runid ' . $insql, $params);
            }
        }

        // Runs being played are stopped first (0.7.49); saying so before the
        // button is pressed beats saying so after.
        $stopping = [];
        foreach ($runids as $runid) {
            $status = (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]);
            $playing = $status === registry::STATUS_RUNNING && run_lifecycle::has_open_attempts($runid);
            if ($playing) {
                $stopping[] = get_string('purge:runbeingplayed', 'local_catquizlab', $runid);
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
            'rawname'  => (string) $experiment->name,
            'counts'   => array_filter($counts),
            'blockers' => $blockers,
            'stopping' => $stopping,
        ];
    }

    /**
     * Whether a typed name confirms deleting an experiment (0.7.50).
     *
     * As a person reads a name, not byte for byte: whitespace of any kind and length counts as one space, tags
     * and entities as the text they show, case does not count. Either the stored name or the shown one confirms.
     *
     * @param string $typed What was typed.
     * @param string $stored The name as stored.
     * @param string $shown The name as shown (through format_string()).
     * @return bool
     */
    public static function name_confirms(string $typed, string $stored, string $shown): bool {
        $normal = static function (string $name): string {
            $name = html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (class_exists('\\Normalizer')) {
                $name = \Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name;
            }
            // Invisible characters (zero-width spaces, soft hyphens) go, every kind of space becomes one.
            $name = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $name) ?? $name;
            $name = preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', $name) ?? $name;

            return \core_text::strtolower(trim($name));
        };

        $typed = $normal($typed);
        if ($typed === '') {
            return false;
        }

        return $typed === $normal($stored) || $typed === $normal($shown);
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
     * Delete an experiment with all of its runs, stopping it first.
     *
     * @param int $experimentid The experiment.
     * @param bool $deep Also remove the engine-side objects.
     * @param bool $force Kept for callers; an experiment is always stopped before it is deleted now.
     * @return array{ok: bool, removed: array, reason: string, stopped?: array}
     */
    public static function delete_experiment(int $experimentid, bool $deep = false, bool $force = false): array {
        global $DB;

        if (!$DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
            return ['ok' => false, 'removed' => [], 'reason' => 'experiment-not-found'];
        }

        // Stopped first, then deleted (0.7.49). Deleting used to ask the workers playing the experiment to
        // finish and refuse until they had — while every tick started new workers for the sittings still
        // waiting, so the next try found the same situation: an experiment that could be neither stopped nor
        // deleted. The stop takes the work away instead: no run hands out a sitting any more, the ones being
        // played are closed, and their workers stop before their next answer. No claim is left to strand.
        $stopped = experiment_stop::stop($experimentid);

        $totals = ['runs' => 0];
        // Its runs, named now: once they are gone, rows still carrying their ids are only found by these (#145).
        $runids = array_map('intval', array_keys($DB->get_records(
            'local_catquizlab_run',
            ['experimentid' => $experimentid],
            '',
            'id'
        )));
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC', 'id') as $run) {
            $result = self::delete_run((int) $run->id, $deep, true);
            if (!$result['ok']) {
                // Stopping rather than leaving half an experiment: a partial
                // delete is harder to reason about than none.
                return ['ok' => false, 'removed' => $totals, 'reason' => $result['reason']];
            }
            // Each run's own "1 run" is counted once, as runs: the line said "1 run(s) … 1 run" before (0.7.50).
            unset($result['removed']['run']);
            foreach ($result['removed'] as $key => $count) {
                $totals[$key] = ($totals[$key] ?? 0) + $count;
            }
            $totals['runs']++;
        }

        // What belongs to the experiment as a whole (0.7.50): its pool definitions, its export records and its
        // entries in the execution queue, which outlived it before.
        foreach (self::EXPERIMENT_TABLES as $table => $label) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $count = $DB->count_records($table, ['experimentid' => $experimentid]);
            if ($count > 0) {
                $DB->delete_records($table, ['experimentid' => $experimentid]);
                $totals[$label] = ($totals[$label] ?? 0) + $count;
            }
        }

        // The experiment goes last, and only when nothing of it is left (#145, AK1): "gone" in the list is the end of
        // a complete deletion, never of a partial one. Anything left stops it here; the task is retried, and the list
        // shows the experiment as being deleted, with this message, until a retry finds nothing left.
        $left = self::left_of($experimentid, $runids);
        if ($left !== []) {
            $parts = [];
            foreach ($left as $table => $count) {
                $parts[] = $table . ' ' . $count;
            }
            throw new \moodle_exception('purge:incomplete', 'local_catquizlab', '', implode(', ', $parts));
        }
        $DB->delete_records('local_catquizlab_experiment', ['id' => $experimentid]);
        $totals['experiment'] = 1;

        return ['ok' => true, 'removed' => array_filter($totals), 'reason' => '', 'stopped' => $stopped];
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
                'reason' => get_string('history:pipelinestopped', 'local_catquizlab'),
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
        foreach (self::RUN_TABLES as $table => $label) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $count = $DB->count_records($table, ['runid' => $runid]);
            if ($count > 0) {
                $DB->delete_records($table, ['runid' => $runid]);
                $removed[$label] = $count;
            }
        }

        // The files a background export wrote for the run (#121) go with their records.
        $fs = get_file_storage();
        $context = \context_system::instance();
        foreach ($fs->get_area_files($context->id, 'local_catquizlab', 'export', 0, 'id', false) as $file) {
            if (strpos($file->get_filename(), 'run-' . $runid . '-') === 0) {
                $file->delete();
                $removed['exportfiles'] = ($removed['exportfiles'] ?? 0) + 1;
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

        $userids = array_values(array_unique(array_map('intval', $DB->get_fieldset_select(
            'local_catquizlab_person',
            'moodleuserid',
            'runid = ? AND moodleuserid > 0',
            [$runid]
        ))));
        if ($userids === []) {
            return [];
        }

        $courseid = (int) $DB->get_field('local_catquizlab_run', 'courseid', ['id' => $runid]);
        $removed = ['enrolments' => 0, 'users' => 0];

        // Read once per chunk, not once per person (0.7.49): the course's enrolment instances, the enrolments and the
        // accounts were each fetched per person, and every person was unenrolled one by one before delete_user(),
        // which unenrols anyway. That saved little — measured, about 3 of some 125 queries a person costs; the rest
        // is delete_user() itself, which is why deleting runs in the background now (task\delete_experiment).
        $instances = $courseid > 0 ? enrol_get_instances($courseid, true) : [];
        foreach (array_chunk($userids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $enrolled = $courseid > 0 ? $DB->get_records_sql_menu(
                "SELECT ue.userid, COUNT(1)
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :courseid AND ue.userid {$insql}
               GROUP BY ue.userid",
                $params + ['courseid' => $courseid]
            ) : [];
            $users = $DB->get_records_select('user', "id {$insql} AND deleted = 0", $params);

            foreach ($users as $user) {
                // The run number in the username is what this plugin stamps on the
                // accounts it creates, and it names the run being deleted. An
                // account without it belongs to somebody else, and deleting a real
                // user because they happened to be enrolled in an experiment course
                // would be unforgivable.
                $ours = str_contains((string) $user->username, '_r' . $runid . '_');
                $removed['enrolments'] += (int) ($enrolled[$user->id] ?? 0);

                if ($ours) {
                    // Takes the enrolments, role assignments and group memberships with it.
                    delete_user($user);
                    $removed['users']++;
                    continue;
                }

                // Somebody else's account: only out of the experiment course.
                if (!empty($enrolled[$user->id])) {
                    foreach ($instances as $instance) {
                        $plugin = enrol_get_plugin($instance->enrol);
                        if ($plugin) {
                            $plugin->unenrol_user($instance, (int) $user->id);
                        }
                    }
                }
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

        // Count and name placed by the language, not glued in English order.
        return get_string('purge:countline', 'local_catquizlab', (object) ['count' => $count, 'name' => $name]);
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
