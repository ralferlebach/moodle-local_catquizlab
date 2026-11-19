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
 * The one read-only inventory of an installation's lab data, for an acceptance (re-audit 18.11.2026, section 3).
 *
 * Everything an operator used to be asked for one query at a time, in one pass and one report: the versions, the
 * schema, the status sums, due against claimable, the execution line, the circuit breaker's pauses, the workers and
 * their leases, orphans and duplicates, whether collected, cancelled and failed add up, results and exports, and the
 * tasks with the last dispatch. Only SELECTs: no update, no delete, no cron, no worker. The report says how many writes
 * the database saw while it ran (none, or it says so).
 *
 * Each check is a row: section, name, value, and a verdict — "ok", "contradiction" (two things that cannot both be
 * true) or "info" (a count without a right answer). What it cannot see it does not claim: HTTP 5xx, memory, browser
 * accessibility, worker races and file logs are measured in CI jobs, not read from tables.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class acceptance_inventory {
    /** @var string A count or state without a right answer. */
    public const INFO = 'info';

    /** @var string As it must be. */
    public const OK = 'ok';

    /** @var string Two things that cannot both be true. */
    public const CONTRADICTION = 'contradiction';

    /** @var array[] The checks so far. */
    protected array $checks = [];

    /** @var int The clock the report reads everything against. */
    protected int $now;

    /**
     * Inventory at a given time.
     *
     * @param int|null $now The clock; now by default.
     */
    public function __construct(?int $now = null) {
        $this->now = $now ?? time();
    }

    /**
     * The whole inventory.
     *
     * @return array{generated: string, now: int, checks: array[], summary: array, writes: int}
     */
    public function run(): array {
        global $DB;

        $writes = $DB->perf_get_writes();
        $this->versions();
        $this->schema();
        $this->statuses();
        $this->due_and_claimable();
        $this->line();
        $this->workers();
        $this->orphans();
        $this->disjoint();
        $this->results_and_exports();
        $this->tasks();
        $writes = $DB->perf_get_writes() - $writes;
        $this->add('readonly', 'database writes while the inventory ran', $writes, $writes === 0);

        $summary = [self::OK => 0, self::CONTRADICTION => 0, self::INFO => 0];
        foreach ($this->checks as $check) {
            $summary[$check['verdict']]++;
        }

        return [
            'generated' => gmdate('Y-m-d\TH:i:s\Z', $this->now),
            'now' => $this->now,
            'checks' => $this->checks,
            'summary' => $summary,
            'writes' => $writes,
        ];
    }

    /**
     * One check.
     *
     * @param string $section The section.
     * @param string $name What is checked.
     * @param mixed $value What was found.
     * @param bool|null $ok True: as it must be; false: a contradiction; null: a count without a right answer.
     * @return void
     */
    protected function add(string $section, string $name, $value, ?bool $ok = null): void {
        $this->checks[] = [
            'section' => $section,
            'name' => $name,
            'value' => $value,
            'verdict' => $ok === null ? self::INFO : ($ok ? self::OK : self::CONTRADICTION),
        ];
    }

    /**
     * Counts of a grouped query as value => count.
     *
     * @param string $sql SELECT k, COUNT(1) AS n … GROUP BY k.
     * @param array $params Its parameters.
     * @return array<string, int>
     */
    protected function counts(string $sql, array $params = []): array {
        global $DB;

        $out = [];
        foreach ($DB->get_recordset_sql($sql, $params) as $row) {
            $out[(string) $row->k] = (int) $row->n;
        }
        ksort($out);

        return $out;
    }

    /**
     * Moodle, the engine, the host, the catmodel and this plugin: installed version against the code's.
     *
     * @return void
     */
    protected function versions(): void {
        global $CFG;

        $this->add('versions', 'moodle', ['release' => $CFG->release ?? '', 'version' => (string) ($CFG->version ?? '')]);
        $manager = \core_plugin_manager::instance();
        foreach (['local_catquizlab', 'local_catquiz', 'mod_adaptivequiz', 'adaptivequizcatmodel_catquiz'] as $component) {
            $info = $manager->get_plugin_info($component);
            if (!$info) {
                $this->add('versions', $component, 'not present', $component === 'local_catquizlab' ? false : null);
                continue;
            }
            $installed = (string) get_config($component, 'version');
            $this->add('versions', $component, ['installed' => $installed, 'code' => (string) $info->versiondisk,
                'release' => (string) ($info->release ?? '')], $installed === (string) $info->versiondisk);
        }
    }

    /**
     * The plugin's tables against db/install.xml: missing tables, fields, indexes or differences.
     *
     * @return void
     */
    protected function schema(): void {
        global $CFG, $DB;

        $file = new \xmldb_file($CFG->dirroot . '/local/catquizlab/db/install.xml');
        if (!$file->loadXMLStructure() || !$file->getStructure()) {
            $this->add('schema', 'db/install.xml readable', false, false);
            return;
        }
        $structure = $file->getStructure();
        $tables = array_map(static fn($table) => $table->getName(), $structure->getTables());
        $missing = array_values(array_filter($tables, static fn(string $table): bool => !$DB->get_manager()->table_exists($table)));
        $this->add('schema', 'tables of db/install.xml', count($tables));
        $this->add('schema', 'tables missing', $missing, !$missing);
        $differences = [];
        foreach ($DB->get_manager()->check_database_schema($structure) as $table => $errors) {
            if (in_array($table, $tables, true)) {
                $differences[$table] = array_values($errors);
            }
        }
        $this->add('schema', 'differences to db/install.xml', $differences, !$differences);
    }

    /**
     * Status sums of experiments, runs and sittings.
     *
     * @return void
     */
    protected function statuses(): void {
        $this->add('status', 'experiments by status', $this->counts(
            'SELECT status AS k, COUNT(1) AS n FROM {local_catquizlab_experiment} GROUP BY status'
        ));
        $this->add('status', 'experiments being deleted', array_sum($this->counts(
            'SELECT 1 AS k, COUNT(1) AS n FROM {local_catquizlab_experiment} WHERE timedeleting > 0'
        )));
        $this->add('status', 'runs by status', $this->counts(
            'SELECT status AS k, COUNT(1) AS n FROM {local_catquizlab_run} GROUP BY status'
        ));
        $this->add('status', 'sittings by status', $this->counts(
            'SELECT status AS k, COUNT(1) AS n FROM {local_catquizlab_attempt} GROUP BY status'
        ));
    }

    /**
     * Queued and due is not claimable: the run's verdict as the claim would see it (idle_diagnosis).
     *
     * @return void
     */
    protected function due_and_claimable(): void {
        $verdicts = [];
        $queued = 0;
        $due = 0;
        $claimable = 0;
        $paused = [];
        foreach (idle_diagnosis::runs(0, $this->now) as $run) {
            $verdicts[$run['verdict']] = ($verdicts[$run['verdict']] ?? 0) + 1;
            $queued += $run['queued'];
            $due += $run['due'];
            $claimable += $run['verdict'] === idle_diagnosis::CLAIMABLE ? $run['due'] : 0;
            if ($run['paused']) {
                $paused[] = ['runid' => $run['runid'], 'experimentid' => $run['experimentid'], 'by' => $run['pausedby'],
                    'cause' => $run['cause']];
            }
        }
        ksort($verdicts);
        $this->add('queue', 'sittings queued', $queued);
        $this->add('queue', 'sittings queued and due (nextruntime <= now)', $due);
        $this->add('queue', 'sittings due and claimable now', $claimable);
        $this->add('queue', 'runs with work, by verdict', $verdicts);
        $this->add('queue', 'paused runs, with who paused them and why (run manifest)', $paused);
    }

    /**
     * The execution line: at most one holder, positions unique, nobody holding it for an experiment that is gone.
     *
     * @return void
     */
    protected function line(): void {
        global $DB;

        $this->add('line', 'entries by state', $this->counts(
            'SELECT state AS k, COUNT(1) AS n FROM {local_catquizlab_execqueue} GROUP BY state'
        ));
        $holders = $DB->get_records(
            'local_catquizlab_execqueue',
            ['state' => execution_queue::RUNNING],
            'position, id',
            'id, experimentid, position, timestarted'
        );
        $this->add('line', 'holders (state running)', array_values(array_map(static fn($row): array => [
            'experimentid' => (int) $row->experimentid, 'since' => (int) $row->timestarted], $holders)), count($holders) <= 1);
        $waiting = $DB->get_records(
            'local_catquizlab_execqueue',
            ['state' => execution_queue::WAITING],
            'position, id',
            'id, experimentid, position'
        );
        $positions = array_map(static fn($row): int => (int) $row->position, $waiting);
        $this->add('line', 'waiting, in line order (experiment ids)', array_values(array_map(
            static fn($row): int => (int) $row->experimentid,
            $waiting
        )));
        $this->add(
            'line',
            'waiting positions unique',
            count($positions) - count(array_unique($positions)),
            count($positions) === count(array_unique($positions))
        );
        $open = $DB->get_records_sql_menu(
            "SELECT experimentid, COUNT(1) AS n
               FROM {local_catquizlab_execqueue}
              WHERE state IN (:running, :waiting)
           GROUP BY experimentid
             HAVING COUNT(1) > 1",
            ['running' => execution_queue::RUNNING, 'waiting' => execution_queue::WAITING]
        );
        $this->add('line', 'experiments in the line more than once', array_map('intval', $open), !$open);
        $gone = (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {local_catquizlab_execqueue} q
          LEFT JOIN {local_catquizlab_experiment} e ON e.id = q.experimentid
              WHERE q.state IN (:running, :waiting) AND e.id IS NULL",
            ['running' => execution_queue::RUNNING, 'waiting' => execution_queue::WAITING]
        );
        $this->add('line', 'open line entries of experiments that no longer exist', $gone, $gone === 0);
    }

    /**
     * Workers, their current sitting and the sittings' leases.
     *
     * @return void
     */
    protected function workers(): void {
        global $DB;

        $cutoff = $this->now - worker_registry::HEARTBEAT_TIMEOUT;
        $this->add('workers', 'workers by status', $this->counts(
            'SELECT status AS k, COUNT(1) AS n FROM {local_catquizlab_worker} GROUP BY status'
        ));
        $this->add(
            'workers',
            'live workers (heartbeat within ' . worker_registry::HEARTBEAT_TIMEOUT . ' s)',
            $DB->count_records_select(
                'local_catquizlab_worker',
                'status IN (:s, :r) AND heartbeat >= :cutoff',
                ['s' => worker_registry::STATUS_STARTING, 'r' => worker_registry::STATUS_RUNNING, 'cutoff' => $cutoff]
            )
        );
        $stopped = $DB->count_records_select(
            'local_catquizlab_worker',
            'status IN (:st, :cr) AND currentattempt > 0',
            ['st' => worker_registry::STATUS_STOPPED, 'cr' => worker_registry::STATUS_CRASHED]
        );
        $this->add('workers', 'stopped or crashed workers still naming a sitting', $stopped, $stopped === 0);
        $twice = $DB->get_records_sql_menu(
            "SELECT currentattempt, COUNT(1) AS n
               FROM {local_catquizlab_worker}
              WHERE currentattempt > 0 AND status IN (:s, :r) AND heartbeat >= :cutoff
           GROUP BY currentattempt
             HAVING COUNT(1) > 1",
            ['s' => worker_registry::STATUS_STARTING, 'r' => worker_registry::STATUS_RUNNING, 'cutoff' => $cutoff]
        );
        $this->add('workers', 'sittings named by two live workers at once', array_map('intval', $twice), !$twice);
        $notrunning = (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {local_catquizlab_worker} w
               JOIN {local_catquizlab_attempt} a ON a.id = w.currentattempt
              WHERE w.status IN (:s, :r) AND w.heartbeat >= :cutoff AND a.status <> :running",
            ['s' => worker_registry::STATUS_STARTING, 'r' => worker_registry::STATUS_RUNNING, 'cutoff' => $cutoff,
                'running' => attempt_scheduler::STATUS_RUNNING]
        );
        $this->add('workers', 'live workers naming a sitting that is not in play', $notrunning);
        $expired = $DB->count_records_select(
            'local_catquizlab_attempt',
            'status = :running AND leaseexpires < :now',
            ['running' => attempt_scheduler::STATUS_RUNNING, 'now' => $this->now]
        );
        $this->add('workers', 'sittings in play with an expired lease (the next reap returns them)', $expired);
        $nolease = $DB->count_records_select(
            'local_catquizlab_attempt',
            "status = :running AND (leaseowner IS NULL OR leaseowner = '')",
            ['running' => attempt_scheduler::STATUS_RUNNING]
        );
        $this->add('workers', 'sittings in play without a lease owner', $nolease, $nolease === 0);
    }

    /**
     * Rows whose parent is gone, and rows that exist twice.
     *
     * @return void
     */
    protected function orphans(): void {
        global $DB;

        $pairs = [
            ['runs without their experiment', 'local_catquizlab_run', 'experimentid', 'local_catquizlab_experiment'],
            ['sittings without their run', 'local_catquizlab_attempt', 'runid', 'local_catquizlab_run'],
            ['persons without their run', 'local_catquizlab_person', 'runid', 'local_catquizlab_run'],
            ['results without their run', 'local_catquizlab_result', 'runid', 'local_catquizlab_run'],
            ['twin claims without their run', 'local_catquizlab_twinclaim', 'runid', 'local_catquizlab_run'],
            ['attempt history without its sitting', 'local_catquizlab_attemptlog', 'attemptid', 'local_catquizlab_attempt'],
        ];
        foreach ($pairs as [$name, $child, $field, $parent]) {
            $n = (int) $DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {{$child}} c
              LEFT JOIN {{$parent}} p ON p.id = c.{$field}
                  WHERE c.{$field} > 0 AND p.id IS NULL"
            );
            $this->add('orphans', $name, $n, $n === 0);
        }
        $duplicates = [
            ['sittings of the same person in the same run', 'local_catquizlab_attempt', 'runid, personid', 'personid > 0'],
            ['twins claimed twice in the same run', 'local_catquizlab_twinclaim', 'runid, twinid', '1 = 1'],
            ['engine attempts linked to two sittings', 'local_catquizlab_attempt', 'engineattemptid', 'engineattemptid > 0'],
        ];
        foreach ($duplicates as [$name, $table, $fields, $where]) {
            $n = (int) $DB->count_records_sql(
                "SELECT COUNT(1) FROM (SELECT {$fields} FROM {{$table}} WHERE {$where}
                                        GROUP BY {$fields} HAVING COUNT(1) > 1) d"
            );
            $this->add('duplicates', $name, $n, $n === 0);
        }
    }

    /**
     * Collected, cancelled and failed are apart and add up; a finished or cancelled run has nothing left to play.
     *
     * @return void
     */
    protected function disjoint(): void {
        global $DB;

        $collected = [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_TRACEPENDING,
            attempt_scheduler::STATUS_VALIDATED];
        [$in, $params] = $DB->get_in_or_equal($collected, SQL_PARAMS_NAMED, 'c');
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.status,
                    COUNT(a.id) AS total,
                    SUM(CASE WHEN a.status {$in} THEN 1 ELSE 0 END) AS collected,
                    SUM(CASE WHEN a.status = :cancelled THEN 1 ELSE 0 END) AS cancelled,
                    SUM(CASE WHEN a.status = :failed THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN a.status = :queued THEN 1 ELSE 0 END) AS queued,
                    SUM(CASE WHEN a.status = :running THEN 1 ELSE 0 END) AS inplay
               FROM {local_catquizlab_run} r
               JOIN {local_catquizlab_attempt} a ON a.runid = r.id
           GROUP BY r.id, r.status",
            $params + ['cancelled' => attempt_scheduler::STATUS_CANCELLED, 'failed' => attempt_scheduler::STATUS_FAILED,
                'queued' => attempt_scheduler::STATUS_QUEUED, 'running' => attempt_scheduler::STATUS_RUNNING]
        );
        $unknown = [];
        $leftover = [];
        $totals = ['collected' => 0, 'cancelled' => 0, 'failed' => 0, 'queued' => 0, 'inplay' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $sum = (int) $row->collected + (int) $row->cancelled + (int) $row->failed + (int) $row->queued
                + (int) $row->inplay;
            if ($sum !== (int) $row->total) {
                $unknown[] = (int) $row->id;
            }
            if (
                in_array((int) $row->status, [registry::STATUS_FINISHED, registry::STATUS_CANCELLED], true)
                    && ((int) $row->queued + (int) $row->inplay) > 0
            ) {
                $leftover[] = (int) $row->id;
            }
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row->$key;
            }
        }
        $this->add(
            'counts',
            'sittings: collected, cancelled, failed, queued, in play, total',
            $totals,
            $totals['collected'] + $totals['cancelled'] + $totals['failed'] + $totals['queued'] + $totals['inplay']
            === $totals['total']
        );
        $this->add('counts', 'runs whose sittings are in a status none of these', $unknown, !$unknown);
        $this->add('counts', 'finished or cancelled runs with sittings still queued or in play', $leftover, !$leftover);
    }

    /**
     * Results, provenance and exports.
     *
     * @return void
     */
    protected function results_and_exports(): void {
        global $DB;

        $this->add('results', 'result rows', $DB->count_records('local_catquizlab_result'));
        $this->add('results', 'runs with results', (int) $DB->count_records_sql(
            'SELECT COUNT(DISTINCT runid) FROM {local_catquizlab_result}'
        ));
        // Finished by the pipeline (its run log says so): aggregation wrote the results before the run was finished.
        // A run set to finished by other means (a seed, a test) says nothing about the pipeline and is counted apart.
        $missing = $DB->get_records_sql_menu(
            "SELECT r.id,
                    (SELECT COUNT(1) FROM {local_catquizlab_runlog} l WHERE l.runid = r.id AND l.event = :event) AS bypipeline
               FROM {local_catquizlab_run} r
              WHERE r.status = :finished
                AND EXISTS (SELECT 1 FROM {local_catquizlab_attempt} a WHERE a.runid = r.id AND a.status IN (:c1, :c2))
                AND NOT EXISTS (SELECT 1 FROM {local_catquizlab_result} s WHERE s.runid = r.id)",
            ['event' => run_log::RUN_FINISHED, 'finished' => registry::STATUS_FINISHED,
                'c1' => attempt_scheduler::STATUS_COLLECTED, 'c2' => attempt_scheduler::STATUS_VALIDATED]
        );
        $bypipeline = array_keys(array_filter($missing, static fn($n): bool => (int) $n > 0));
        $this->add(
            'results',
            'runs finished by aggregation with collected sittings but no results',
            array_map('intval', $bypipeline),
            !$bypipeline
        );
        $this->add(
            'results',
            'runs set to finished otherwise (seeded, tested), collected sittings, no results',
            count($missing) - count($bypipeline)
        );
        $notrace = $DB->count_records_select(
            'local_catquizlab_attempt',
            'status = :v AND engineattemptid = 0',
            ['v' => attempt_scheduler::STATUS_VALIDATED]
        );
        $this->add('results', 'validated sittings without an engine attempt (no provenance)', $notrace, $notrace === 0);
        $this->add('results', 'exports by format', $this->counts(
            'SELECT format AS k, COUNT(1) AS n FROM {local_catquizlab_exportlog} GROUP BY format'
        ));
    }

    /**
     * The plugin's scheduled and ad-hoc tasks, and the last dispatch the debug trace recorded.
     *
     * @return void
     */
    protected function tasks(): void {
        global $DB;

        $scheduled = [];
        foreach (
            $DB->get_records(
                'task_scheduled',
                ['component' => 'local_catquizlab'],
                'classname',
                'id, classname, lastruntime, nextruntime, faildelay, disabled'
            ) as $task
        ) {
            $scheduled[] = ['task' => (string) $task->classname, 'lastrun' => (int) $task->lastruntime,
                'nextrun' => (int) $task->nextruntime, 'faildelay' => (int) $task->faildelay,
                'disabled' => (int) $task->disabled];
        }
        $failing = array_values(array_filter($scheduled, static fn(array $task): bool => $task['faildelay'] > 0));
        $this->add('tasks', 'scheduled tasks', $scheduled);
        $this->add('tasks', 'scheduled tasks backing off after a failure', $failing);
        $this->add('tasks', 'ad-hoc tasks queued, by class', $this->counts(
            "SELECT classname AS k, COUNT(1) AS n FROM {task_adhoc} WHERE component = 'local_catquizlab' GROUP BY classname"
        ));
        $this->add('tasks', 'ad-hoc tasks backing off after a failure', (int) $DB->count_records_select(
            'task_adhoc',
            "component = 'local_catquizlab' AND faildelay > 0"
        ));
        $last = $DB->get_records_select(
            'local_catquizlab_debug',
            $DB->sql_like('taskclassname', ':c', false),
            ['c' => '%dispatch_worker%'],
            'id DESC',
            'id, action, outcome, detail, timecreated',
            0,
            1
        );
        $last = $last ? reset($last) : null;
        $this->add('tasks', 'last dispatch the debug trace recorded', $last ? ['time' => (int) $last->timecreated,
            'action' => (string) $last->action, 'outcome' => (string) $last->outcome,
            'detail' => log_redactor::field((string) $last->detail)] : 'none recorded');
        $this->add('tasks', 'enabled', (bool) get_config('local_catquizlab', 'enabled'));
    }
}
