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
 * The one place that decides what state a run is in.
 *
 * The guiding invariant: an experiment may only appear as executed when its
 * runs have been through the real execution path. An expanded sweep is not an
 * execution, a scheduled run is not an attempt, and an attempt without a trace
 * is not a result.
 *
 * Before this class the same decision was made in several places — the web
 * interface, the worker's claim and complete calls, the ad-hoc tasks — and they
 * could disagree. The visible symptom was an experiment marked "Executed" whose
 * runs were all still drafts at 0%: nothing had run, and the interface said the
 * opposite.
 *
 * The transitions:
 *
 *     DRAFT
 *       ↓ start requested
 *     SCHEDULED          run_orchestrator is queued
 *       ↓ provisioning succeeded
 *     READY              persons, test and attempt queue exist
 *       ↓ first attempt claimed
 *     RUNNING
 *       ↓ every attempt reached a terminal state
 *     AGGREGATING        aggregate_results queued exactly once
 *       ↓ aggregation succeeded
 *     FINISHED
 *
 * A mandatory operation that fails for good lands on FAILED; CANCELLED stays
 * separate, because one records a decision and the other a defect.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_lifecycle {
    /**
     * Start a run: check, mark it scheduled, hand it to the orchestrator.
     *
     * The one entry point for starting, used by the web interface and by tasks
     * alike. It deliberately contains no orchestration of its own — that lives
     * in run_orchestrator, and a second copy in the interface would be a second
     * thing to keep in step.
     *
     * @param int $runid The run to start.
     * @param array $options Passed through to the orchestrator.
     * @param \context|null $context Context for the capability check.
     * @return array{started: bool, reason: string}
     */
    public static function start(int $runid, array $options = [], ?\context $context = null): array {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run) {
            return ['started' => false, 'reason' => 'run-not-found'];
        }

        // Only a run that has not been through the path yet. Re-starting a
        // running one would give it a second attempt queue.
        if ((int) $run->status !== registry::STATUS_DRAFT) {
            return ['started' => false, 'reason' => 'run-not-in-draft'];
        }

        $preflight = preflight::check($context);
        if (!$preflight['ok']) {
            // Not started rather than started-and-silently-stuck: a run queued
            // without an engine or a course sits there looking started.
            return ['started' => false, 'reason' => preflight::summary($preflight)];
        }

        $DB->update_record('local_catquizlab_run', (object) [
            'id'           => $runid,
            'status'       => registry::STATUS_SCHEDULED,
            'timemodified' => time(),
        ]);
        self::refresh_experiment($runid);

        \local_catquizlab\task\orchestrate_run::queue($runid, $options);

        return ['started' => true, 'reason' => ''];
    }

    /**
     * Start every draft run of an experiment.
     *
     * @param int $experimentid The experiment.
     * @param array $options Passed through to the orchestrator.
     * @param \context|null $context Context for the capability check.
     * @return array{started: int, blocked: int, reason: string}
     */
    public static function start_drafts(int $experimentid, array $options = [], ?\context $context = null): array {
        global $DB;

        $runs = $DB->get_records('local_catquizlab_run', [
            'experimentid' => $experimentid,
            'status'       => registry::STATUS_DRAFT,
        ], 'id ASC', 'id');

        $started = 0;
        $blocked = 0;
        $reason = '';
        foreach ($runs as $run) {
            $result = self::start((int) $run->id, $options, $context);
            if ($result['started']) {
                $started++;
            } else {
                $blocked++;
                $reason = $reason !== '' ? $reason : $result['reason'];
            }
        }

        return ['started' => $started, 'blocked' => $blocked, 'reason' => $reason];
    }

    /**
     * Record that provisioning finished, successfully or not.
     *
     * @param int $runid The run.
     * @param bool $ok Whether every mandatory stage succeeded.
     * @param string $reason The failing stage and cause, when it did not.
     * @return void
     */
    public static function provisioned(int $runid, bool $ok, string $reason = ''): void {
        global $DB;

        if (!$ok) {
            self::fail($runid, $reason !== '' ? $reason : 'provisioning-failed');

            return;
        }

        $DB->update_record('local_catquizlab_run', (object) [
            'id'           => $runid,
            'status'       => registry::STATUS_READY,
            'timemodified' => time(),
        ]);
        self::refresh_experiment($runid);
    }

    /**
     * How many consecutive failures pause a run by themselves.
     *
     * A run whose attempts all fail the same way does not get better by being
     * retried 1600 times. Stopping it early keeps the queue free for work that
     * can succeed and leaves a diagnosable state instead of an exhausted one.
     *
     * @var int
     */
    public const FAILURE_STREAK_LIMIT = 10;

    /**
     * Hold a run's attempts back, or let them go again.
     *
     * A paused run keeps everything it has: its attempts stay queued and are
     * simply not handed out. Pausing is a decision an operator takes, so it
     * survives until it is taken back.
     *
     * @param int $runid The run.
     * @param bool $paused Whether it should be paused.
     * @param string $reason Why, when the pause is automatic.
     * @return void
     */
    public static function set_paused(int $runid, bool $paused, string $reason = ''): void {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run) {
            return;
        }

        $manifest = json_decode((string) $run->manifestjson, true) ?: [];
        if ($paused) {
            $manifest['lifecycle']['paused'] = true;
            $manifest['lifecycle']['pausedtime'] = time();
            $manifest['lifecycle']['pausedreason'] = $reason;
        } else {
            unset(
                $manifest['lifecycle']['paused'],
                $manifest['lifecycle']['pausedtime'],
                $manifest['lifecycle']['pausedreason']
            );
        }

        $DB->update_record('local_catquizlab_run', (object) [
            'id'           => $runid,
            'manifestjson' => json_encode($manifest, JSON_UNESCAPED_SLASHES),
            'timemodified' => time(),
        ]);
    }

    /**
     * Whether a run is holding its attempts back.
     *
     * @param int $runid The run.
     * @return bool
     */
    public static function is_paused(int $runid): bool {
        global $DB;

        $manifest = json_decode(
            (string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runid]),
            true
        ) ?: [];

        return !empty($manifest['lifecycle']['paused']);
    }

    /**
     * Why a run was paused, when it was paused automatically.
     *
     * @param int $runid The run.
     * @return string
     */
    public static function pause_reason(int $runid): string {
        global $DB;

        $manifest = json_decode(
            (string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runid]),
            true
        ) ?: [];

        return (string) ($manifest['lifecycle']['pausedreason'] ?? '');
    }

    /**
     * Pause a run that is failing its way through the queue.
     *
     * Called after each failed attempt. The streak is counted over the most
     * recent attempts rather than over all of them, so a run that failed early
     * and recovered is not punished for its history.
     *
     * @param int $runid The run.
     * @return bool Whether this call paused the run.
     */
    public static function check_failure_streak(int $runid): bool {
        global $DB;

        if (self::is_paused($runid)) {
            return false;
        }

        $recent = $DB->get_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status IN (:collected, :failed)',
            [
                'runid'     => $runid,
                'collected' => attempt_scheduler::STATUS_COLLECTED,
                'failed'    => attempt_scheduler::STATUS_FAILED,
            ],
            'timemodified DESC',
            'id, status',
            0,
            self::FAILURE_STREAK_LIMIT
        );

        if (count($recent) < self::FAILURE_STREAK_LIMIT) {
            return false;
        }

        foreach ($recent as $attempt) {
            if ((int) $attempt->status !== attempt_scheduler::STATUS_FAILED) {
                return false;
            }
        }

        self::set_paused(
            $runid,
            true,
            get_string('ops:autopaused', 'local_catquizlab', self::FAILURE_STREAK_LIMIT)
        );

        return true;
    }

    /**
     * Move a run to RUNNING when a worker claims its first attempt.
     *
     * Idempotent: later claims of the same run change nothing, and a run that
     * has moved past RUNNING is never pulled back.
     *
     * @param int $runid The run whose attempt was claimed.
     * @return bool Whether the status changed.
     */
    public static function attempt_claimed(int $runid): bool {
        global $DB;

        // One conditional UPDATE rather than a read followed by a write. Two
        // workers claiming the first two attempts of the same run reach this at
        // the same moment, and a read-then-write lets both believe they made
        // the transition — the second then repeats the side effects of the
        // first. The database decides here instead.
        //
        // READY only: a scheduled run has not finished provisioning, and the
        // claim refuses its attempts anyway (see is_runnable()). Accepting it
        // here would let a run skip the state that says its pool was checked.
        // The answer this returns is "did this call move the run", not "is the
        // run running" — a later claim on the same run must not report that it
        // made a transition that had already happened, or the caller repeats
        // whatever it does on a first claim.
        $status = (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]);
        if ($status !== registry::STATUS_READY) {
            return false;
        }

        // Conditional even so: callers hold a transaction that serialises the
        // claims, and the condition is what keeps that true if one ever does
        // not. An UPDATE that matches nothing is not an error here.
        $DB->execute(
            'UPDATE {local_catquizlab_run}
                SET status = :running, timemodified = :now
              WHERE id = :id AND status = :ready',
            [
                'running' => registry::STATUS_RUNNING,
                'now'     => time(),
                'id'      => $runid,
                'ready'   => registry::STATUS_READY,
            ]
        );

        self::refresh_experiment($runid);

        return true;
    }

    /**
     * React to an attempt reaching a terminal state.
     *
     * Called after every completion, successful or not. While any attempt is
     * still queued or running there is nothing to decide; once none is, the run
     * either goes to AGGREGATING or, if nothing usable came out of it, to
     * FAILED.
     *
     * @param int $runid The run the attempt belongs to.
     * @return string|null The new status name, or null when the run stays put.
     */
    public static function attempt_finished(int $runid): ?string {
        global $DB;

        if (self::has_open_attempts($runid)) {
            return null;
        }

        $status = (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]);
        if (registry::is_terminal($status) || $status === registry::STATUS_AGGREGATING) {
            // Already decided. Completion callbacks can arrive more than once —
            // a retried worker, a re-collected attempt — and the decision must
            // not be taken twice.
            return null;
        }

        $counts = self::attempt_counts($runid);
        if ($counts['collected'] === 0) {
            self::fail($runid, 'no-attempt-produced-a-trace');

            return 'failed';
        }

        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_AGGREGATING, ['id' => $runid]);
        self::refresh_experiment($runid);

        // Exactly once: the guard above means a second caller finds the run
        // already in AGGREGATING and returns before reaching this line.
        \local_catquizlab\task\aggregate_results::queue($runid);

        return 'aggregating';
    }

    /**
     * Record that aggregation finished a run.
     *
     * @param int $runid The run.
     * @param bool $ok Whether aggregation succeeded.
     * @param string $reason The failure reason, when it did not.
     * @return void
     */
    public static function aggregated(int $runid, bool $ok = true, string $reason = ''): void {
        global $DB;

        if (!$ok) {
            self::fail($runid, $reason !== '' ? $reason : 'aggregation-failed');

            return;
        }

        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $runid]);
        $DB->set_field('local_catquizlab_run', 'timemodified', time(), ['id' => $runid]);
        self::refresh_experiment($runid);
    }

    /**
     * Put a run into its failed state with a stated reason.
     *
     * @param int $runid The run.
     * @param string $reason A short, diagnosable reason.
     * @return void
     */
    public static function fail(int $runid, string $reason): void {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run) {
            return;
        }

        // The reason travels in the manifest, where a later reader looks for
        // what a run did. A status without a reason forces the next person to
        // reconstruct it from logs that may no longer exist.
        $manifest = json_decode((string) $run->manifestjson, true) ?: [];
        $manifest['lifecycle']['failedreason'] = $reason;
        $manifest['lifecycle']['failedtime'] = time();

        // The counts behind the verdict, so the interface can show what was
        // found rather than only that something was wrong.
        if (str_contains($reason, 'cat-not-ready')) {
            $readiness = cat_readiness::check($runid);
            $manifest['lifecycle']['readinessfacts'] = $readiness['facts'];
        }

        $DB->update_record('local_catquizlab_run', (object) [
            'id'           => $runid,
            'status'       => registry::STATUS_FAILED,
            'manifestjson' => json_encode($manifest, JSON_UNESCAPED_SLASHES),
            'timemodified' => time(),
        ]);

        // A failed run must not leave work behind. Its queued attempts are
        // closed rather than deleted: the history of what was planned is worth
        // keeping, and a worker playing attempts of a run that already failed
        // is worse than no check at all.
        self::close_open_attempts($runid, $reason);

        self::refresh_experiment($runid);
    }

    /**
     * Close the attempts of a run that will not run.
     *
     * Queued attempts become failed with the run's reason; running ones are
     * left to their worker, which will report back and find the run terminal.
     *
     * @param int $runid The run.
     * @param string $reason Why the run failed.
     * @return int How many attempts were closed.
     */
    public static function close_open_attempts(int $runid, string $reason = ''): int {
        global $DB;

        $queued = $DB->get_records('local_catquizlab_attempt', [
            'runid'  => $runid,
            'status' => attempt_scheduler::STATUS_QUEUED,
        ], '', 'id');

        foreach ($queued as $attempt) {
            $DB->update_record('local_catquizlab_attempt', (object) [
                'id'           => $attempt->id,
                'status'       => attempt_scheduler::STATUS_FAILED,
                'lasterror'    => \core_text::substr('run failed: ' . $reason, 0, 1000),
                'leaseowner'   => null,
                'leaseexpires' => 0,
                'timemodified' => time(),
            ]);
        }

        return count($queued);
    }

    /**
     * Run the provisioning of a scheduled run now, in this request.
     *
     * The orchestrator task normally does this, and a run can wait for it
     * indefinitely: the ad-hoc task may never have been queued, or was queued
     * while cron was not running. From the outside that is indistinguishable
     * from work in progress — "Scheduled, 0%", workers idle beside it.
     *
     * This does not set READY directly, and must not: READY means provisioning
     * succeeded and the pool was checked. It runs the same orchestrator the task
     * would have run, and lets it reach whatever conclusion it reaches.
     *
     * @param int $runid The run to provision.
     * @return array{ok: bool, reason: string}
     */
    public static function provision_now(int $runid): array {
        global $DB;

        $status = (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]);
        if ($status !== registry::STATUS_SCHEDULED) {
            return ['ok' => false, 'reason' => 'run-not-scheduled'];
        }

        $result = run_orchestrator::setup($runid);

        self::provisioned($runid, !empty($result['ok']), (string) ($result['reason'] ?? ''));

        return [
            'ok'     => !empty($result['ok']),
            'reason' => (string) ($result['reason'] ?? ''),
        ];
    }

    /**
     * Put a run back to draft, clearing what provisioning created.
     *
     * Re-checking suits a run whose cause was fixed outside it — a pool
     * enlarged, an engine installed. This is for the other case: a run that is
     * wrong in itself, or stuck in a state nothing moves it out of. Reproducing
     * it makes a second run and leaves the first sitting there; this returns
     * the one that exists to the state it started in.
     *
     * What is removed is what provisioning made: attempts, people, the scale
     * map, the item records. What is kept is the run, its cell and its seed —
     * the identity of the experiment condition, which is the point of a run.
     *
     * Engine-side objects are deliberately left alone. Deleting scales and
     * questions from under an engine that may be mid-attempt is a much bigger
     * promise than this needs to make, and re-provisioning reuses them.
     *
     * @param int $runid The run to reset.
     * @return array{ok: bool, removed: array, reason: string}
     */
    public static function reset(int $runid): array {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run) {
            return ['ok' => false, 'removed' => [], 'reason' => 'run-not-found'];
        }

        if ((int) $run->status === registry::STATUS_RUNNING && self::has_open_attempts($runid)) {
            // A worker is playing one of these right now. Resetting underneath
            // it would strand the claim it holds, which is the failure the
            // whole lease mechanism exists to prevent.
            return ['ok' => false, 'removed' => [], 'reason' => 'run-is-being-played'];
        }

        $removed = [];
        foreach ([
            'local_catquizlab_attempt'   => 'attempts',
            'local_catquizlab_person'    => 'people',
            'local_catquizlab_item'      => 'items',
            'local_catquizlab_scalemap'  => 'scales',
            'local_catquizlab_result'    => 'results',
        ] as $table => $label) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $count = $DB->count_records($table, ['runid' => $runid]);
            if ($count > 0) {
                $DB->delete_records($table, ['runid' => $runid]);
                $removed[$label] = $count;
            }
        }

        // The manifest keeps its configuration and loses its history: the cell
        // definition is what makes this run this run, the failure reason
        // describes an attempt at it that no longer exists.
        $manifest = json_decode((string) $run->manifestjson, true) ?: [];
        unset($manifest['lifecycle']);

        $DB->update_record('local_catquizlab_run', (object) [
            'id'           => $runid,
            'status'       => registry::STATUS_DRAFT,
            'testcmid'     => 0,
            'sectionid'    => 0,
            'manifestjson' => json_encode($manifest, JSON_UNESCAPED_SLASHES),
            'timemodified' => time(),
        ]);

        self::refresh_experiment($runid);

        return ['ok' => true, 'removed' => $removed, 'reason' => ''];
    }

    /**
     * Check a failed run again, and put it back if it can run now.
     *
     * A run that failed readiness because a pool was too small is not broken
     * for ever — the pool can be enlarged, a budget corrected, an engine
     * installed. Without this the only way back was to reproduce the run, which
     * loses the identity of the one that failed and makes the experiment's
     * history harder to read than it needs to be.
     *
     * @param int $runid The run to re-check.
     * @return array{ok: bool, reason: string, requeued: int}
     */
    public static function recheck(int $runid): array {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run || (int) $run->status !== registry::STATUS_FAILED) {
            return ['ok' => false, 'reason' => 'run-not-failed', 'requeued' => 0];
        }

        $readiness = cat_readiness::check($runid);
        if (!$readiness['ok']) {
            // Recorded again: the reason may have changed even when the verdict
            // has not, and the older one is no longer true.
            self::fail($runid, 'cat-not-ready: ' . cat_readiness::summary($readiness));

            return ['ok' => false, 'reason' => cat_readiness::summary($readiness), 'requeued' => 0];
        }

        // Attempts that were closed when the run failed go back into the queue.
        // Ones that genuinely failed while being played keep their history:
        // only the ones this plugin closed are reopened.
        $reopened = 0;
        foreach (
            $DB->get_records_select(
                'local_catquizlab_attempt',
                'runid = :runid AND status = :status AND ' . $DB->sql_like('lasterror', ':pattern'),
                ['runid' => $runid, 'status' => attempt_scheduler::STATUS_FAILED, 'pattern' => 'run failed:%'],
                '',
                'id'
            ) as $attempt
        ) {
            $DB->update_record('local_catquizlab_attempt', (object) [
                'id'           => $attempt->id,
                'status'       => attempt_scheduler::STATUS_QUEUED,
                'tries'        => 0,
                'nextruntime'  => 0,
                'lasterror'    => null,
                'timemodified' => time(),
            ]);
            $reopened++;
        }

        $manifest = json_decode((string) $run->manifestjson, true) ?: [];
        unset(
            $manifest['lifecycle']['failedreason'],
            $manifest['lifecycle']['failedtime'],
            $manifest['lifecycle']['readinessfacts']
        );

        $DB->update_record('local_catquizlab_run', (object) [
            'id'           => $runid,
            'status'       => registry::STATUS_READY,
            'manifestjson' => json_encode($manifest, JSON_UNESCAPED_SLASHES),
            'timemodified' => time(),
        ]);
        self::refresh_experiment($runid);

        return ['ok' => true, 'reason' => '', 'requeued' => $reopened];
    }

    /**
     * Why a run failed, and what the readiness check counted.
     *
     * Recorded at failure time and never read until now: a run said FAILED and
     * the reason sat in its manifest, where only somebody with database access
     * would find it. The facts are carried alongside because "not ready" is not
     * actionable and "2 usable items against a minimum of 4" is.
     *
     * @param int $runid The run.
     * @return array{reason: string, time: int, facts: array}
     */
    public static function failure_details(int $runid): array {
        global $DB;

        $manifest = json_decode(
            (string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runid]),
            true
        ) ?: [];

        $lifecycle = $manifest['lifecycle'] ?? [];

        return [
            'reason' => (string) ($lifecycle['failedreason'] ?? ''),
            'time'   => (int) ($lifecycle['failedtime'] ?? 0),
            'facts'  => (array) ($lifecycle['readinessfacts'] ?? []),
        ];
    }

    /**
     * Whether a run is in a state that may hand out work.
     *
     * @param int $runid The run.
     * @return bool
     */
    public static function is_runnable(int $runid): bool {
        global $DB;

        $status = (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]);

        // Only these two: a scheduled run has not been provisioned yet, and
        // everything past RUNNING is either aggregating or finished.
        return in_array($status, [registry::STATUS_READY, registry::STATUS_RUNNING], true);
    }

    /**
     * Whether a run still has attempts that could change its outcome.
     *
     * @param int $runid The run.
     * @return bool
     */
    public static function has_open_attempts(int $runid): bool {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal([
            attempt_scheduler::STATUS_QUEUED,
            attempt_scheduler::STATUS_RUNNING,
        ], SQL_PARAMS_NAMED, 'st');
        $params['runid'] = $runid;

        return $DB->record_exists_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status ' . $insql,
            $params
        );
    }

    /**
     * How a run's attempts are distributed across the states that matter.
     *
     * @param int $runid The run.
     * @return array{total: int, open: int, collected: int, failed: int}
     */
    public static function attempt_counts(int $runid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT status, COUNT(*) AS n
               FROM {local_catquizlab_attempt}
              WHERE runid = :runid
           GROUP BY status',
            ['runid' => $runid]
        );

        $counts = ['total' => 0, 'open' => 0, 'collected' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $n = (int) $row->n;
            $counts['total'] += $n;
            switch ((int) $row->status) {
                case attempt_scheduler::STATUS_QUEUED:
                case attempt_scheduler::STATUS_RUNNING:
                    $counts['open'] += $n;
                    break;
                case attempt_scheduler::STATUS_COLLECTED:
                    $counts['collected'] += $n;
                    break;
                default:
                    $counts['failed'] += $n;
            }
        }

        return $counts;
    }

    /**
     * The experiment status implied by the states of its runs.
     *
     * Derived rather than stored, so the two can never contradict each other.
     * The rule that matters: while every run is still a draft, the experiment
     * has not been executed, however many runs the sweep created.
     *
     * @param int $experimentid The experiment.
     * @return int One of experiment_service's status constants.
     */
    public static function experiment_status(int $experimentid): int {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT status, COUNT(*) AS n
               FROM {local_catquizlab_run}
              WHERE experimentid = :id
           GROUP BY status',
            ['id' => $experimentid]
        );

        if (!$rows) {
            // No runs at all: the experiment is whatever validation made it.
            $current = (int) $DB->get_field('local_catquizlab_experiment', 'status', ['id' => $experimentid]);

            return in_array($current, [
                experiment_service::STATUS_DRAFT,
                experiment_service::STATUS_VALIDATED,
            ], true) ? $current : experiment_service::STATUS_DRAFT;
        }

        $byrunstatus = [];
        $total = 0;
        foreach ($rows as $row) {
            $byrunstatus[(int) $row->status] = (int) $row->n;
            $total += (int) $row->n;
        }

        $draft = $byrunstatus[registry::STATUS_DRAFT] ?? 0;
        $finished = $byrunstatus[registry::STATUS_FINISHED] ?? 0;
        $failed = $byrunstatus[registry::STATUS_FAILED] ?? 0;
        $cancelled = $byrunstatus[registry::STATUS_CANCELLED] ?? 0;

        // Runs exist but none has left the draft state: the sweep was expanded,
        // nothing was executed. This is the case that used to read "Executed".
        if ($draft === $total) {
            return experiment_service::STATUS_EXPANDED;
        }

        if ($finished + $failed + $cancelled === $total) {
            return $finished > 0 ? experiment_service::STATUS_EXECUTED : experiment_service::STATUS_FAILED;
        }

        return experiment_service::STATUS_RUNNING;
    }

    /**
     * Write the derived experiment status back, for listings that read it.
     *
     * @param int $runid A run of the experiment to refresh.
     * @return void
     */
    public static function refresh_experiment(int $runid): void {
        global $DB;

        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);
        if ($experimentid <= 0) {
            return;
        }

        self::refresh_experiment_by_id($experimentid);
    }

    /**
     * Write the derived experiment status back.
     *
     * @param int $experimentid The experiment.
     * @return void
     */
    public static function refresh_experiment_by_id(int $experimentid): void {
        global $DB;

        $status = self::experiment_status($experimentid);
        $current = (int) $DB->get_field('local_catquizlab_experiment', 'status', ['id' => $experimentid]);
        if ($status === $current) {
            return;
        }

        $DB->update_record('local_catquizlab_experiment', (object) [
            'id'           => $experimentid,
            'status'       => $status,
            'timemodified' => time(),
        ]);
    }
}
