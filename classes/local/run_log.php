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
 * What happened to a run, kept.
 *
 * A failed run's story used to be spread across the Moodle task log, the run's
 * manifest, the worker log, the interface and the database — and a reset
 * destroyed most of it, which is the wrong moment to lose it: what went wrong on
 * the second execution attempt is exactly what somebody needs while looking at
 * the third.
 *
 * So the log is append-only and survives everything except deleting the run. It
 * is numbered by execution attempt, because "it failed at materialise" means
 * something different the third time.
 *
 * Each entry can carry a measurement. That is not decoration: a provisioning
 * once used 549,727 queries and 36 seconds, and nothing recorded which stage
 * spent them.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_log {
    /** @var string The run was created. */
    public const RUN_CREATED = 'run_created';

    /** @var string Somebody asked for it to start. */
    public const START_REQUESTED = 'start_requested';

    /** @var string The preflight checks passed. */
    public const PREFLIGHT_PASSED = 'preflight_passed';

    /** @var string The preflight checks refused. */
    public const PREFLIGHT_FAILED = 'preflight_failed';

    /** @var string The orchestrator task was queued. */
    public const ORCHESTRATION_QUEUED = 'orchestration_queued';

    /** @var string The orchestrator started running. */
    public const ORCHESTRATION_STARTED = 'orchestration_started';

    /** @var string A provisioning stage began. */
    public const STAGE_STARTED = 'stage_started';

    /** @var string A provisioning stage finished. */
    public const STAGE_COMPLETED = 'stage_completed';

    /** @var string A provisioning stage failed. */
    public const STAGE_FAILED = 'stage_failed';

    /** @var string Provisioning finished and readiness passed. */
    public const PROVISIONING_READY = 'provisioning_ready';

    /** @var string The run failed. */
    public const RUN_FAILED = 'run_failed';

    /** @var string An attempt was claimed by a worker. */
    public const ATTEMPT_CLAIMED = 'attempt_claimed';

    /** @var string An attempt came back. */
    public const ATTEMPT_FINISHED = 'attempt_finished';

    /** @var string Results were aggregated. */
    public const AGGREGATED = 'aggregated';

    /** @var string The run finished. */
    public const RUN_FINISHED = 'run_finished';

    /** @var string Somebody reset it, which starts a new execution attempt. */
    public const RESET = 'reset';

    /** @var string Somebody re-checked a failed run. */
    public const RECHECKED = 'rechecked';

    /**
     * Record one event.
     *
     * @param int $runid The run.
     * @param string $event One of the constants above.
     * @param array $detail Reason, error or measurement.
     * @param string $stage The provisioning stage, where one applies.
     * @param array $measure dbqueries and duration, where they were taken.
     * @return int The log row id, or 0 when logging is impossible.
     */
    public static function record(
        int $runid,
        string $event,
        array $detail = [],
        string $stage = '',
        array $measure = []
    ): int {
        global $DB, $USER;

        if (!$DB->get_manager()->table_exists('local_catquizlab_runlog')) {
            // Logging must never be the reason something fails. A run that
            // completes without its story is worse than one with it, and far
            // better than one that dies trying to write it.
            return 0;
        }

        // The same event on the debug channel, so the console shows the run's
        // state changes interleaved with the actions that caused them — which is
        // the order somebody reads a defect in.
        debug_trace::record(debug_trace::LIFECYCLE, $event, [], 'ok', $detail, $runid);

        try {
            return (int) $DB->insert_record('local_catquizlab_runlog', (object) [
                'runid'       => $runid,
                'attemptno'   => self::current_attempt($runid),
                'event'       => $event,
                'stage'       => $stage !== '' ? $stage : null,
                'detail'      => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_SLASHES),
                'dbqueries'   => (int) ($measure['dbqueries'] ?? 0),
                'duration'    => round((float) ($measure['duration'] ?? 0), 3),
                // The same id the debug trace carries, so one click can be read
                // as one sequence across both.
                'correlationid' => debug_trace::correlation_id(),
                'taskclassname' => debug_trace::task_classname(),
                'userid'        => (int) ($USER->id ?? 0),
                'timecreated'   => time(),
            ]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Begin a measured step, returning a handle that records its cost.
     *
     * @param int $runid The run.
     * @param string $stage The stage being measured.
     * @return array A token for finish_step().
     */
    public static function start_step(int $runid, string $stage): array {
        global $DB;

        self::record($runid, self::STAGE_STARTED, [], $stage);

        return [
            'runid'   => $runid,
            'stage'   => $stage,
            'queries' => (int) $DB->perf_get_queries(),
            'time'    => microtime(true),
        ];
    }

    /**
     * Close a measured step.
     *
     * @param array $token From start_step().
     * @param bool $ok Whether it succeeded.
     * @param array $detail Reason or result.
     * @return void
     */
    public static function finish_step(array $token, bool $ok, array $detail = []): void {
        global $DB;

        self::record(
            (int) $token['runid'],
            $ok ? self::STAGE_COMPLETED : self::STAGE_FAILED,
            $detail,
            (string) $token['stage'],
            [
                'dbqueries' => (int) $DB->perf_get_queries() - (int) $token['queries'],
                'duration'  => microtime(true) - (float) $token['time'],
            ]
        );
    }

    /**
     * Which execution attempt this run is on.
     *
     * @param int $runid The run.
     * @return int
     */
    public static function current_attempt(int $runid): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_runlog')) {
            return 1;
        }

        $max = $DB->get_field_sql(
            'SELECT MAX(attemptno) FROM {local_catquizlab_runlog} WHERE runid = ?',
            [$runid]
        );

        return max(1, (int) $max);
    }

    /**
     * Start a new execution attempt, without discarding the last.
     *
     * @param int $runid The run.
     * @param string $why What prompted it.
     * @return int The new attempt number.
     */
    public static function new_attempt(int $runid, string $why = ''): int {
        global $DB;

        $next = self::current_attempt($runid) + 1;

        if ($DB->get_manager()->table_exists('local_catquizlab_runlog')) {
            $DB->insert_record('local_catquizlab_runlog', (object) [
                'runid'       => $runid,
                'attemptno'   => $next,
                'event'       => self::RESET,
                'detail'      => $why !== '' ? json_encode(['reason' => $why]) : null,
                'dbqueries'   => 0,
                'duration'    => 0,
                'userid'      => (int) ($GLOBALS['USER']->id ?? 0),
                'timecreated' => time(),
            ]);
        }

        return $next;
    }

    /**
     * The log of a run, newest attempt first.
     *
     * @param int $runid The run.
     * @param int|null $attemptno One execution attempt, or null for all.
     * @return array[]
     */
    public static function entries(int $runid, ?int $attemptno = null): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_runlog')) {
            return [];
        }

        $conditions = ['runid' => $runid];
        if ($attemptno !== null) {
            $conditions['attemptno'] = $attemptno;
        }

        $rows = [];
        foreach ($DB->get_records('local_catquizlab_runlog', $conditions, 'id ASC') as $row) {
            $detail = json_decode((string) $row->detail, true) ?: [];

            $rows[] = [
                'id'        => (int) $row->id,
                'correlationid' => (string) ($row->correlationid ?? ''),
                'taskclassname' => (string) ($row->taskclassname ?? ''),
                'attemptno' => (int) $row->attemptno,
                'event'     => $row->event,
                'stage'     => (string) $row->stage,
                'detail'    => $detail,
                'summary'   => self::summarise($detail),
                'dbqueries' => (int) $row->dbqueries,
                'duration'  => round((float) $row->duration, 3),
                // Said the way a person would: a stage that took eight hours is
                // not usefully described as 30720 seconds.
                'durationtext' => duration::human((float) $row->duration),
                // Flagged against the stage's own budget rather than one number
                // for everything: 900 queries is unremarkable for materialising
                // a pool and alarming for creating a course.
                'expensive' => self::over_budget(
                    (string) $row->stage,
                    (int) $row->dbqueries,
                    (int) ($detail['units'] ?? 1)
                ),
                'time'      => userdate((int) $row->timecreated, get_string('strftimedatetimeshort')),
            ];
        }

        return $rows;
    }

    /**
     * What a step cost, per stage, for the newest attempt.
     *
     * @param int $runid The run.
     * @return array[]
     */
    public static function cost_by_stage(int $runid): array {
        $costs = [];
        foreach (self::entries($runid, self::current_attempt($runid)) as $entry) {
            if ($entry['event'] !== self::STAGE_COMPLETED && $entry['event'] !== self::STAGE_FAILED) {
                continue;
            }
            $costs[] = [
                'stage'     => $entry['stage'],
                'dbqueries' => $entry['dbqueries'],
                'duration'  => $entry['duration'],
                'expensive' => $entry['expensive'],
            ];
        }

        return $costs;
    }

    /**
     * What a stage may cost before it is worth complaining about.
     *
     * Measured on this codebase: materialising 24 items took 937 queries, about
     * 39 per item, and the reported 549,727-query provisioning was the same rate
     * against a pool of some fourteen thousand. The budget is per stage and
     * generous — it is there to notice a change in the rate, not to police a
     * number.
     *
     * @var array<string, int>
     */
    public const QUERY_BUDGET = [
        'scales'      => 500,
        // Per item, not in total: this stage scales with the pool.
        //
        // Measured on this codebase: about 39 queries per item, of which 4 are
        // this plugin registering the item with the CAT engine and the rest is
        // Moodle's own question creation. So the reported 549,727 against some
        // fourteen thousand items is very largely core's cost, not a defect
        // here — which is worth knowing before anybody optimises the wrong
        // thing.
        //
        // The budget is set just above the measured rate rather than at a
        // comfortable multiple of it, so a change in this plugin's share is
        // caught rather than absorbed.
        'materialise' => 45,
        'container'   => 500,
        // Per person.
        'people'      => 200,
        'test'        => 2000,
        'readiness'   => 500,
        // Per attempt.
        'attempts'    => 100,
    ];

    /**
     * Whether a stage cost more than its budget allows.
     *
     * @param string $stage The stage.
     * @param int $queries What it used.
     * @param int $units How many items, people or attempts it handled.
     * @return bool
     */
    public static function over_budget(string $stage, int $queries, int $units = 1): bool {
        $budget = self::QUERY_BUDGET[$stage] ?? 0;
        if ($budget === 0) {
            return false;
        }

        // The per-unit stages are the ones that grow; the rest are flat.
        $scales = in_array($stage, ['materialise', 'people', 'attempts'], true);

        return $queries > ($scales ? $budget * max(1, $units) : $budget);
    }

    /**
     * A one-line rendering of a detail payload.
     *
     * @param array $detail The stored detail.
     * @return string
     */
    protected static function summarise(array $detail): string {
        if ($detail === []) {
            return '';
        }

        if (isset($detail['reason']) && is_string($detail['reason'])) {
            return $detail['reason'];
        }

        $parts = [];
        foreach ($detail as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = $key . ': ' . $value;
            }
        }

        return implode(', ', $parts);
    }
}
