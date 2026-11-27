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
     * @var array<string, callable> Faults a test injects (#161 DoD3): 'activity' runs instead of deleting the activity
     *     (and may throw), 'afterengine' runs once the engine objects are gone (a host callback that took more).
     */
    protected static array $faults = [];

    /**
     * Inject a fault into deleting, for a test (#161 DoD3). Refused outside PHPUnit and Behat.
     *
     * @param string $fault 'activity' or 'afterengine'.
     * @param callable|null $fn What happens there; null takes the fault away.
     * @return void
     */
    public static function simulate(string $fault, ?callable $fn): void {
        if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST) && !(defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING)) {
            throw new \coding_exception('purger::simulate() is for tests only.');
        }
        if ($fn === null) {
            unset(self::$faults[$fault]);
        } else {
            self::$faults[$fault] = $fn;
        }
    }

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

    /** @var array<string, string> The tables of a run's engine footprint, with the name each count goes by (#161). */
    public const FOOTPRINT_LABELS = [
        'course_modules'             => 'activities',
        'adaptivequiz_attempt'       => 'hostattempts',
        'question_usages'            => 'usages',
        'local_catquiz_attempts'     => 'catattempts',
        'local_catquiz_progress'     => 'progress',
        // The engine's progress events (local_catquiz #177, 2026112700): they go with their head.
        'local_catquiz_progress_steps' => 'progresssteps',
        'local_catquiz_personparams' => 'abilities',
        'local_catquiz_trace'        => 'enginetraces',
        'local_catquiz_tests'        => 'enginetests',
        'local_catquiz_catcontext'   => 'contexts',
        'local_catquiz_catscales'    => 'enginescales',
        'local_catquiz_items'        => 'engineitems',
        'local_catquiz_itemparams'   => 'itemparams',
        'question'                   => 'questions',
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
                'counts' => $counts, 'blockers' => [], 'stopping' => [], 'kept' => [], 'atrisk' => 0, 'warnings' => []];
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

        // What of the engine, its activity and Moodle belongs to these runs alone (#161 AK1), from each run's footprint:
        // deleted with a deep deletion, kept without. Rows of others that share an attempt number are named apart:
        // they are not deleted, and are checked to be all there afterwards.
        $engine = [];
        $atrisk = 0;
        foreach ($runids as $runid) {
            $footprint = engine_footprint::of_run($runid);
            foreach (self::FOOTPRINT_LABELS as $table => $label) {
                $engine[$label] = ($engine[$label] ?? 0) + count($footprint['owned'][$table] ?? []);
            }
            $atrisk += array_sum(array_map('count', $footprint['atrisk']));
        }
        $engine = array_filter($engine);
        if ($deep) {
            $counts += $engine;
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
            // Not a deep deletion: what stays in the engine, said as staying (#161 AK5).
            'kept'     => $deep ? [] : $engine,
            'atrisk'   => $deep ? $atrisk : 0,
            'warnings' => $deep ? self::engine_warnings() : [],
        ];
    }

    /** @var int The first adaptivequizcatmodel_catquiz that deletes CAT progress by the CAT attempt (#161 AK7, its #11). */
    public const CATMODEL_FIXED = 2026101701;

    /**
     * What a deep deletion should know about the installed engine before it starts (#161 AK7).
     *
     * An older adaptivequizcatmodel_catquiz deletes local_catquiz_progress by the activity's attempt numbers
     * (adaptivequizcatmodel_catquiz #11): it leaves the run's own progress and takes others' with the same numbers. The
     * lab does not repair that callback; its check after the deletion stops it and keeps the run.
     *
     * @return string[]
     */
    public static function engine_warnings(): array {
        $installed = (int) (\core_plugin_manager::instance()->get_plugin_info('adaptivequizcatmodel_catquiz')->versiondb ?? 0);
        if ($installed > 0 && $installed < self::CATMODEL_FIXED) {
            return [get_string('purge:catmodelold', 'local_catquizlab', (object) ['installed' => $installed,
                'fixed' => self::CATMODEL_FIXED])];
        }

        return [];
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
            // What the run has in the engine, its activity and Moodle, and the rows of others that share a number with
            // it (#161): taken before anything goes, checked after. The run, its lab rows and its ids stay until the
            // check passes — they are what a retry and the operator need to find what was left.
            $before = engine_footprint::of_run($runid);
            deletion_log::event((int) $run->experimentid, 'engine-before', ['runid' => $runid,
                'owned' => engine_footprint::counts($before['owned']), 'atrisk' => engine_footprint::counts($before['atrisk'])]);
            $removed += self::delete_engine_objects($runid);
            if (isset(self::$faults['afterengine'])) {
                (self::$faults['afterengine'])($before);
            }
            // The accounts and enrolments this run created. Deep deletion is the
            // one that promises to leave nothing, and leaving a hundred
            // simulated students in the user list is leaving something.
            $removed += self::delete_simulated_people($runid);
            $check = engine_footprint::check($before);
            deletion_log::event((int) $run->experimentid, 'engine-after', ['runid' => $runid, 'ok' => $check['ok'],
                'left' => engine_footprint::counts($check['left']), 'lost' => engine_footprint::counts($check['lost'])]);
            if (!$check['ok']) {
                throw new purge_incomplete($runid, $check);
            }
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
        deletion_log::event($experimentid, 'finished', ['deep' => $deep, 'runs' => count($runids), 'left' => $left,
            'removed' => array_filter($totals)]);

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

    /** @var string Why kill_pipeline() closed a sitting, as stored with it (#166). */
    public const PIPELINE_STOPPED = 'pipeline_stopped_by_operator';

    /** @var string[] What 0.7.x before 2026111501 stored instead (`history:pipelinestopped`, English and German). */
    public const LEGACY_PIPELINE_TEXTS = ['pipeline stopped by an operator', 'Pipeline von einer Person angehalten'];

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
        $closing = $DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'id',
            'status IN (:queued, :running)',
            ['queued' => attempt_scheduler::STATUS_QUEUED, 'running' => attempt_scheduler::STATUS_RUNNING]
        );
        // Closed by an operator, not failed (#166): the reason in the same words in every language.
        $DB->execute(
            'UPDATE {local_catquizlab_attempt}
                SET status = :cancelled, lasterror = :reason, leaseowner = NULL, leaseexpires = 0,
                    timemodified = :now
              WHERE status IN (:queued, :running)',
            [
                'cancelled' => attempt_scheduler::STATUS_CANCELLED,
                'reason' => self::PIPELINE_STOPPED,
                'now'    => time(),
                'queued' => attempt_scheduler::STATUS_QUEUED,
                'running' => attempt_scheduler::STATUS_RUNNING,
            ]
        );

        attempt_history::record_many(
            $closing,
            attempt_history::CANCELLED,
            self::PIPELINE_STOPPED,
            get_string('history:pipelinestopped', 'local_catquizlab')
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
                // The question usages of the activity's attempts: the run's own, read before the activity goes.
                $usages = array_values(array_filter(array_map('intval', $DB->get_fieldset_select(
                    'adaptivequiz_attempt',
                    'uniqueid',
                    'instance = ?',
                    [(int) $cm->instance]
                ))));
                try {
                    if (isset(self::$faults['activity'])) {
                        (self::$faults['activity'])($cm);
                    } else {
                        core_compat::delete_module((int) $cm->id);
                    }
                    $removed['activity'] = 1;
                    // Moodle's question engine deletes a usage on MySQL and MariaDB only together with its question
                    // attempts (question_engine_data_mapper::delete_usage_records_for_mysql(), an inner join): the
                    // usage of an attempt that never got its first question stays. These are the run's own, of its
                    // activity's attempts, and empty; they go here (#161, seen on the MariaDB mirror).
                    $empty = [];
                    foreach (array_chunk($usages, 500) as $chunk) {
                        [$usql, $uparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'qu');
                        $empty = array_merge($empty, $DB->get_fieldset_select(
                            'question_usages',
                            'id',
                            "id {$usql} AND NOT EXISTS (SELECT 1 FROM {question_attempts} qa WHERE qa.questionusageid = "
                                . "{question_usages}.id)",
                            $uparams
                        ));
                    }
                    if ($empty !== []) {
                        $DB->delete_records_list('question_usages', 'id', array_map('intval', $empty));
                        $removed['usages'] = count($empty);
                    }
                } catch (\Throwable $e) {
                    // Nothing else goes (#161 AK2): the questions, scales and lab rows are what leads to the activity's
                    // attempts and to whatever its deletion left. Deleting them anyway counted an "adaptive quiz that
                    // could not be deleted" and reported the rest deleted. The run stays as it is; a retry starts over.
                    throw new purge_incomplete($runid, ['left' => ['course_modules' => [(int) $cm->id]], 'lost' => [],
                        'ok' => false], $e->getMessage());
                }
            }
        }

        // Engine items and their parameters, for the scales this run owns.
        $dbman = $DB->get_manager();
        $map = $DB->get_records('local_catquizlab_scalemap', ['runid' => $runid], '', 'id, catscaleid, contextid');
        $scaleids = array_values(array_unique(array_filter(array_map(static fn($m): int => (int) $m->catscaleid, $map))));
        $contextids = array_values(array_unique(array_filter(array_map(static fn($m): int => (int) $m->contextid, $map))));
        if ($scaleids !== [] && $dbman->table_exists('local_catquiz_items')) {
            [$insql, $params] = $DB->get_in_or_equal($scaleids, SQL_PARAMS_NAMED, 'sc');
            // The items' parameters first (#161): deleting only the items left every parameter row behind, pointing at
            // an item that no longer existed.
            $items = engine_footprint::ids_in('local_catquiz_items', 'catscaleid', $scaleids);
            if ($items !== [] && $dbman->table_exists('local_catquiz_itemparams')) {
                foreach (array_chunk($items, 500) as $chunk) {
                    [$itemsql, $itemparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'it');
                    $removed['itemparams'] = ($removed['itemparams'] ?? 0)
                        + $DB->count_records_select('local_catquiz_itemparams', 'itemid ' . $itemsql, $itemparams);
                    $DB->delete_records_select('local_catquiz_itemparams', 'itemid ' . $itemsql, $itemparams);
                }
            }
            $removed['engineitems'] = $DB->count_records_select('local_catquiz_items', 'catscaleid ' . $insql, $params);
            $DB->delete_records_select('local_catquiz_items', 'catscaleid ' . $insql, $params);

            if ($dbman->table_exists('local_catquiz_catscales')) {
                $DB->delete_records_select('local_catquiz_catscales', 'id ' . $insql, $params);
                $removed['enginescales'] = count($scaleids);
            }
        }

        // The questions this run generated, through the lab's own item record — after the engine's items and their
        // parameters (#161): deleting a question makes the engine drop its item and leave the item's parameters.
        if ($DB->get_manager()->table_exists('local_catquizlab_item')) {
            $questionids = $DB->get_fieldset_select('local_catquizlab_item', 'questionid', 'runid = ?', [$runid]);
            $removed['questions'] = self::delete_questions(array_filter(array_map('intval', $questionids)));
        }

        // The abilities the engine still holds for the run's own simulated accounts (#161): the activity's deletion
        // takes those of its attempts; any others of these accounts are the lab's alone. Found by account, never by an
        // attempt number.
        $users = engine_footprint::users($runid);
        if ($users !== [] && $dbman->table_exists('local_catquiz_personparams')) {
            $ids = engine_footprint::ids_in('local_catquiz_personparams', 'userid', $users);
            foreach (array_chunk($ids, 500) as $chunk) {
                $DB->delete_records_list('local_catquiz_personparams', 'id', $chunk);
            }
            if ($ids !== []) {
                $removed['abilities'] = count($ids);
            }
        }

        // The run's engine contexts, once no scale is left in them (#161): a reset or a deep deletion left them.
        if ($contextids !== [] && $dbman->table_exists('local_catquiz_catcontext')) {
            foreach ($contextids as $contextid) {
                if (
                    !$DB->record_exists('local_catquiz_catscales', ['contextid' => $contextid])
                        && $DB->record_exists('local_catquiz_catcontext', ['id' => $contextid])
                ) {
                    $DB->delete_records('local_catquiz_catcontext', ['id' => $contextid]);
                    $removed['contexts'] = ($removed['contexts'] ?? 0) + 1;
                }
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
