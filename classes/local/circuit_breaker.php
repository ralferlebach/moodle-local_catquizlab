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
 * Stop a run that is failing the same way over and over.
 *
 * A run whose sittings all fail for one reason will keep failing for that
 * reason: retrying the eleventh after ten identical failures produces an
 * eleventh identical failure, some minutes of a browser's time, and one more
 * line in a log nobody is reading yet. Worse, the experiment goes on reporting
 * itself as running, so somebody watching it sees progress that is only the
 * failure counter moving.
 *
 * So: after enough consecutive failures, stop, say why, and hold the rest back
 * until a person has looked. The recommended action is to read the error, not
 * to press retry — which is why "reset and continue" appears only after the
 * details have been shown.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class circuit_breaker {
    /** @var int Consecutive failures before a run is held. */
    public const THRESHOLD = 10;

    /** @var string The prefix of the pause reason the breaker sets (#133). */
    public const PAUSE_REASON = 'circuit_breaker';

    /** @var string[] Causes that say the server or the network was overloaded: worth retrying later. */
    public const CAPACITY_CAUSES = ['submit_http_5xx', 'submit_connect_timeout', 'submit_connect_failed', 'submit_cdp_timeout',
        'worker_timeout', 'fetch_failed', 'http_error', 'navigation_error', 'execution_context_destroyed', 'submit_no_state'];

    /** @var string[] Causes that repeat the same way whatever the load: retrying without a change repeats them. */
    public const DETERMINISTIC_CAUSES = ['engine_exception', 'submit_moodle_error_page', 'login_failed', 'submit_http_404',
        'submit_http_4xx', 'submit_unexpected_redirect', 'submit_state_mismatch'];

    /**
     * Whether this run has just tripped, and hold it if so.
     *
     * Held means paused, not failed (#133): the run keeps its status, its
     * queued sittings stay queued and out of reach, and the pause names its
     * cause — by reason code, counted over the streak — until somebody resumes
     * it. A failed run is terminal; a technical pause is not, and turning one
     * into the other was how run 51 ended as "failed" with 210 sittings never played.
     *
     * @param int $runid The run.
     * @return array{tripped: bool, failures: int, lasterror: string, cause?: string, category?: string, held?: int}
     */
    public static function check(int $runid): array {
        global $DB;

        $streak = self::failure_streak($runid);

        if ($streak['count'] < self::THRESHOLD) {
            return ['tripped' => false, 'failures' => $streak['count'], 'lasterror' => $streak['lasterror']];
        }

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run || registry::is_terminal((int) $run->status) || run_lifecycle::is_paused($runid)) {
            return ['tripped' => false, 'failures' => $streak['count'], 'lasterror' => $streak['lasterror']];
        }

        $held = $DB->count_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status = :queued',
            ['runid' => $runid, 'queued' => attempt_scheduler::STATUS_QUEUED]
        );
        arsort($streak['causes']);
        $cause = (string) (array_key_first($streak['causes']) ?? 'unknown_failure');
        $category = self::category($cause);

        run_lifecycle::set_paused($runid, true, self::PAUSE_REASON . ':' . $cause, [
            'failure_count' => $streak['count'],
            'cause'         => $cause,
            'category'      => $category,
            'causes'        => $streak['causes'],
            'held'          => $held,
            'last_error'    => $streak['lasterror'],
        ]);
        $DB->set_field('local_catquizlab_run', 'lasterror', $streak['lasterror'], ['id' => $runid]);

        return [
            'tripped'   => true,
            'failures'  => $streak['count'],
            'lasterror' => $streak['lasterror'],
            'cause'     => $cause,
            'category'  => $category,
            'held'      => $held,
        ];
    }

    /**
     * Whether a cause is about capacity, about something deterministic, or not known.
     *
     * @param string $cause A reason code.
     * @return string 'capacity', 'deterministic' or 'unknown'.
     */
    public static function category(string $cause): string {
        if (in_array($cause, self::CAPACITY_CAUSES, true)) {
            return 'capacity';
        }

        return in_array($cause, self::DETERMINISTIC_CAUSES, true) ? 'deterministic' : 'unknown';
    }

    /**
     * Whether a run is held by the breaker: paused by it, or failed by it before 0.7.38.
     *
     * @param \stdClass $run The run record (status, manifestjson).
     * @return bool
     */
    public static function is_held(\stdClass $run): bool {
        if ((int) $run->status === registry::STATUS_FAILED) {
            return true;
        }
        $manifest = json_decode((string) ($run->manifestjson ?? ''), true) ?: [];

        return !empty($manifest['lifecycle']['paused'])
            && strpos((string) ($manifest['lifecycle']['pausedreason'] ?? ''), self::PAUSE_REASON) === 0;
    }

    /**
     * The condition for held runs in SQL: failed, or paused by the breaker.
     *
     * @param string $alias The run table's alias, with its dot, or ''.
     * @return array [string $sql, array $params]
     */
    public static function held_sql(string $alias = ''): array {
        global $DB;

        return [
            '(' . $alias . 'status = :heldfailed OR (' . $alias . 'status IN (:heldready, :heldrunning) AND '
                . $DB->sql_like($alias . 'manifestjson', ':heldpattern') . '))',
            [
                'heldfailed' => registry::STATUS_FAILED,
                'heldready' => registry::STATUS_READY,
                'heldrunning' => registry::STATUS_RUNNING,
                'heldpattern' => '%"pausedreason":"' . self::PAUSE_REASON . '%',
            ],
        ];
    }

    /**
     * How many of this run's most recent sittings failed in a row, why, and by which causes.
     *
     * @param int $runid The run.
     * @return array{count: int, lasterror: string, causes: array<string, int>}
     */
    public static function failure_streak(int $runid): array {
        global $DB;

        $recent = $DB->get_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status IN (:failed, :collected)',
            [
                'runid'     => $runid,
                'failed'    => attempt_scheduler::STATUS_FAILED,
                'collected' => attempt_scheduler::STATUS_COLLECTED,
            ],
            'timemodified DESC, id DESC',
            'id, status, lasterror',
            0,
            self::THRESHOLD + 5
        );

        $count = 0;
        $lasterror = '';
        $failed = [];
        foreach ($recent as $attempt) {
            if ((int) $attempt->status !== attempt_scheduler::STATUS_FAILED) {
                break;
            }

            if ($lasterror === '') {
                $lasterror = (string) $attempt->lasterror;
            }

            $failed[] = (int) $attempt->id;
            $count++;
        }

        // The cause of each, from its last failed execution: one query (#133).
        $causes = [];
        if ($failed !== []) {
            [$insql, $params] = $DB->get_in_or_equal($failed, SQL_PARAMS_NAMED, 'fa');
            $rows = $DB->get_records_sql(
                "SELECT l.attemptid, l.reasoncode
                   FROM {local_catquizlab_attemptlog} l
                   JOIN (SELECT attemptid, MAX(id) AS lastid
                           FROM {local_catquizlab_attemptlog}
                          WHERE attemptid $insql AND outcome = :failedoutcome
                       GROUP BY attemptid) last ON last.lastid = l.id",
                $params + ['failedoutcome' => attempt_history::FAILED]
            );
            foreach ($failed as $id) {
                $code = (string) ($rows[$id]->reasoncode ?? '') ?: 'unknown_failure';
                $causes[$code] = ($causes[$code] ?? 0) + 1;
            }
        }

        return ['count' => $count, 'lasterror' => $lasterror, 'causes' => $causes];
    }

    /**
     * The failures of a run, grouped by what actually went wrong.
     *
     * Ten reports of one fault are one fault. Listing them separately makes a
     * single cause look like ten problems, and buries the one line somebody
     * needs under nine copies of itself.
     *
     * @param int $runid The run.
     * @return array[] Each with reason and count, commonest first.
     */
    public static function causes(int $runid): array {
        global $DB;

        $rows = $DB->get_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status = :failed',
            ['runid' => $runid, 'failed' => attempt_scheduler::STATUS_FAILED],
            '',
            'id, lasterror'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $key = self::normalise((string) $row->lasterror);
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['reason' => $key, 'count' => 0];
            }
            $grouped[$key]['count']++;
        }

        uasort($grouped, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'];
        });

        return array_values($grouped);
    }

    /**
     * Strip the parts of an error that differ between otherwise identical ones.
     *
     * Attempt ids, question ids, paths and timestamps are what makes ten
     * reports of one fault look like ten faults.
     *
     * @param string $error The message.
     * @return string
     */
    public static function normalise(string $error): string {
        $error = trim($error);
        if ($error === '') {
            return get_string('circuit:nomessage', 'local_catquizlab');
        }

        $error = preg_replace('/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\S*/', '<time>', $error);
        $error = preg_replace('/\battempt\s*#?\d+/i', 'attempt <n>', $error);
        $error = preg_replace('/\b(id|runid|questionid|userid|slot)\s*[=:]\s*\d+/i', '$1=<n>', $error);
        $error = preg_replace('#(/[\w./-]+){2,}#', '<path>', $error);
        $error = preg_replace('/\b[0-9a-f]{16,}\b/i', '<hash>', $error);
        $error = preg_replace('/\s+/', ' ', $error);

        return trim(\core_text::substr($error, 0, 300));
    }

    /**
     * Let a held run try again, once somebody has dealt with the cause.
     *
     * Idempotent, and only after a readiness check (#133): a run that is not
     * held is left as it is; one whose engine, pool or settings are not ready
     * stays held and says why. The failed sittings go back into the queue with
     * a history row each — their counters are cleared, their history is not.
     *
     * @param int $runid The run.
     * @return array{ok: bool, released: int, reason?: string}
     */
    public static function reset_and_continue(int $runid): array {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run) {
            return ['ok' => false, 'released' => 0, 'reason' => 'no-run'];
        }
        $failed = (int) $run->status === registry::STATUS_FAILED;
        if (!$failed && !run_lifecycle::is_paused($runid)) {
            // Nothing held: a second press, or a run somebody else resumed.
            return ['ok' => true, 'released' => 0, 'reason' => 'not-held'];
        }

        // Ready before running again: the readiness check provisioning uses,
        // on the test the run was provisioned with. Without the engine, or
        // without a provisioned test, there is nothing to check against.
        if (environment::engine_available() && (int) ($run->testcmid ?? 0) > 0) {
            $readiness = cat_readiness::check($runid);
            if (!$readiness['ok']) {
                run_log::record($runid, run_log::RECHECKED, ['resume' => 'refused', 'why' => cat_readiness::summary($readiness)]);

                return ['ok' => false, 'released' => 0, 'reason' => cat_readiness::summary($readiness)];
            }
        }

        // Starting abilities an earlier version seeded, if this run still has
        // them: they are the cause behind every "Division by zero" that tripped
        // this breaker on a run with fifty people, and retrying without
        // removing them repeats it exactly.
        user_provisioner::remove_seeded_parameters($runid);

        // The failed sittings go back in the queue with their counters cleared;
        // the ones that were held were never touched and need nothing. Each
        // gets a history row: what was cleared is said where it happened.
        $released = 0;
        foreach (
            $DB->get_records('local_catquizlab_attempt', [
                'runid' => $runid, 'status' => attempt_scheduler::STATUS_FAILED,
            ], 'id ASC', 'id, tries') as $attempt
        ) {
            $DB->update_record('local_catquizlab_attempt', (object) [
                'id' => $attempt->id, 'status' => attempt_scheduler::STATUS_QUEUED, 'tries' => 0, 'lasterror' => null,
                'nextruntime' => 0, 'leaseowner' => null, 'leaseexpires' => 0, 'timemodified' => time(),
            ]);
            attempt_history::record((int) $attempt->id, attempt_history::REQUEUED, [
                'tryno' => (int) $attempt->tries,
                'detail' => 'released after the circuit breaker: run resumed by an operator',
            ]);
            $released++;
        }

        run_lifecycle::set_paused($runid, false, '', ['released' => $released, 'by' => 'operator']);
        if ($failed) {
            // Held before 0.7.38, as failed: back to where it can hand out work.
            run_lifecycle::update_run((object) [
                'id' => $runid, 'status' => registry::STATUS_READY, 'lasterror' => null, 'timemodified' => time(),
            ], 'circuit_breaker_reset');
        } else {
            $DB->set_field('local_catquizlab_run', 'lasterror', null, ['id' => $runid]);
        }

        return ['ok' => true, 'released' => $released];
    }

    /**
     * Turn runs an earlier version held as failed into runs held as paused (#133, upgrade to 0.7.38).
     *
     * Before 0.7.38 a tripped breaker set the run to failed — terminal, as if
     * the run were over, with its queued sittings never played. A run whose
     * last status change says the breaker did it goes back to the status it
     * had, paused with the cause the breaker would name now. Nothing is
     * deleted or reset: the failed sittings stay failed until the run is resumed,
     * and every step is in the run's log.
     *
     * @return int How many runs were changed.
     */
    public static function migrate_failed_held(): int {
        global $DB;

        $changed = 0;
        foreach ($DB->get_records('local_catquizlab_run', ['status' => registry::STATUS_FAILED], 'id ASC', 'id') as $run) {
            $last = $DB->get_records(
                'local_catquizlab_runlog',
                ['runid' => (int) $run->id, 'event' => run_log::STATUS_CHANGED],
                'id DESC',
                'id, detail',
                0,
                1
            );
            $detail = $last === [] ? [] : (json_decode((string) reset($last)->detail, true) ?: []);
            if (($detail['why'] ?? '') !== 'circuit_breaker_tripped') {
                continue;
            }
            $back = ($detail['from'] ?? '') === 'ready' ? registry::STATUS_READY : registry::STATUS_RUNNING;
            run_lifecycle::update_run(
                (object) ['id' => (int) $run->id, 'status' => $back, 'timemodified' => time()],
                'circuit_breaker_migrated'
            );
            $streak = self::failure_streak((int) $run->id);
            arsort($streak['causes']);
            $cause = (string) (array_key_first($streak['causes']) ?? 'unknown_failure');
            run_lifecycle::set_paused((int) $run->id, true, self::PAUSE_REASON . ':' . $cause, [
                'migrated' => '0.7.38',
                'cause' => $cause,
                'category' => self::category($cause),
                'causes' => $streak['causes'],
            ]);
            $changed++;
        }

        return $changed;
    }
}
