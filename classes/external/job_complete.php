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
 * External function: a worker reports an attempt as finished.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\attempt_collector;

/**
 * Records the outcome the worker reports after playing an attempt.
 *
 * The worker only reports status and timing; the trace itself is collected
 * server-side from the engine tables by a follow-up ad-hoc task (E3.5), so a
 * crashed worker can never punch a hole in the trace.
 *
 * Stub scope (round E0): authenticates, validates and acknowledges without
 * writing. The status transition and collect-task trigger land in E3.5.
 */
class job_complete extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Lab attempt id that was played.'),
            'status'    => new external_value(PARAM_ALPHA, 'Reported outcome: finished or failed.'),
            'runtimems' => new external_value(PARAM_INT, 'Wall-clock runtime of the attempt in milliseconds.', VALUE_DEFAULT, 0),
            'engineattemptid' => new external_value(PARAM_INT, 'The adaptivequiz_attempt id (0 when unknown).', VALUE_DEFAULT, 0),
            'message'         => new external_value(
                PARAM_RAW,
                'The worker\'s own reason when the attempt did not finish, kept so a retried '
                    . 'attempt says why rather than only showing a rising try count.',
                VALUE_DEFAULT,
                ''
            ),
            'diagnostics'     => new external_value(
                PARAM_RAW,
                'What the browser saw, as JSON: navigation, console and request events, response statuses, '
                    . 'transport errors, and where the artefacts of a failure were written (#100, #107).',
                VALUE_DEFAULT,
                ''
            ),
            'workerid'        => new external_value(
                PARAM_ALPHANUMEXT,
                'The reporting worker: a report from one that no longer holds the lease is recorded, not applied (#133).',
                VALUE_DEFAULT,
                ''
            ),
            'reason'          => new external_value(
                PARAM_ALPHANUMEXT,
                'Why it failed, as a code of the reason catalogue (#175); without it, or with an unknown one, the '
                    . 'code is read from the message as before.',
                VALUE_DEFAULT,
                ''
            ),
            // Last, as in execute(): Moodle hands the parameters over in this order (a report of 0.7.85 before
            // this order failed in every web service call: its code went into the execution, #173).
            'executionid'     => new external_value(
                PARAM_INT,
                'The execution\'s identity the worker was handed the sitting with (the claim\'s "executionid"). With '
                    . 'the worker, the lease the report is applied under (#173, re-audit 27.11.2026); without it the '
                    . 'report is fenced.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Acknowledge a reported attempt outcome.
     *
     * @param int $attemptid Lab attempt id that was played.
     * @param string $status Reported outcome (finished or failed).
     * @param int $runtimems Wall-clock runtime in milliseconds.
     * @param int $engineattemptid The adaptivequiz_attempt id, when known.
     * @param string $message The worker's reason, when the attempt did not finish.
     * @param string $diagnostics What the browser saw, as JSON.
     * @param string $workerid The reporting worker; '' from workers before 0.7.38.
     * @param string $reason The failure's code (#175); '' to read it from the message.
     * @param int $execution The execution's identity the worker was handed the sitting with (#173).
     * @return array The acknowledgement.
     */
    public static function execute(
        int $attemptid,
        string $status,
        int $runtimems = 0,
        int $engineattemptid = 0,
        string $message = '',
        string $diagnostics = '',
        string $workerid = '',
        string $reason = '',
        int $execution = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid'       => $attemptid,
            'status'          => $status,
            'runtimems'       => $runtimems,
            'engineattemptid' => $engineattemptid,
            'message'         => $message,
            'diagnostics'     => $diagnostics,
            'workerid'        => $workerid,
            'reason'          => $reason,
            'executionid'     => $execution,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        // One end of a sitting at a time (#138): the same report sent twice (#134), the lease recovery and the look for
        // a missing trace take the same lock, and each reads the sitting again under it. A sitting held for longer
        // than the wait is not changed here; its lease runs out and the recovery collects it from the engine.
        [$had, $answer] = \local_catquizlab\local\sitting_lock::run(
            (int) $params['attemptid'],
            \local_catquizlab\local\sitting_lock::REPORT_WAIT,
            static fn(): array => self::apply($params)
        );
        if (!$had) {
            return [
                'acknowledged' => false,
                'message'      => get_string('job:busy', 'local_catquizlab'),
            ];
        }

        return $answer;
    }

    /**
     * Apply a report, with the sitting's lock held.
     *
     * @param array $params The validated parameters of execute().
     * @return array The acknowledgement.
     */
    protected static function apply(array $params): array {
        global $DB;

        $attemptid = (int) $params['attemptid'];
        $status = (string) $params['status'];
        $runtimems = (int) $params['runtimems'];
        $engineattemptid = (int) $params['engineattemptid'];

        $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        if (!$attempt) {
            return [
                'acknowledged' => false,
                'message'      => get_string('job:unknownattempt', 'local_catquizlab'),
            ];
        }

        // Applied is only the report of the execution that holds the lease (#133, #173): this worker, the execution
        // it was handed the sitting as, and a lease that has not run out (lease_fence). Until 0.7.84 the worker's name
        // was asked for alone, and only when it was sent: an old execution of a worker started again under the same
        // name, or a report without a name, was applied to the sitting of the execution after it. A fenced report is
        // recorded and changes nothing it says — not the status, not the trace, not the binding. What is left to do,
        // the server decides from its own state (recover_after_fenced_report()): a lapsed lease is handed back, a
        // sitting whose own engine attempt is complete is collected — once, however often the report comes.
        $reporter = (string) $params['workerid'];
        $execution = (int) $params['executionid'];
        $fence = \local_catquizlab\local\lease_fence::check($attempt, $reporter, $execution);
        if ($fence !== \local_catquizlab\local\lease_fence::HOLDS) {
            \local_catquizlab\local\attempt_history::record($attemptid, \local_catquizlab\local\attempt_history::STALE, [
                'workerid' => $reporter !== '' ? $reporter : null,
                'executionid' => $execution,
                'detail' => sprintf(
                    'report of %s, execution #%d (%s), fenced (%s), not applied: %s',
                    $reporter !== '' ? $reporter : '?',
                    $execution,
                    $status,
                    $fence,
                    (string) $params['message']
                ),
                'extra' => (array) (json_decode((string) $params['diagnostics'], true) ?: []) + ['fence' => $fence,
                    'reported' => ['status' => $status, 'engineattemptid' => $engineattemptid, 'runtimems' => $runtimems],
                    'server' => \local_catquizlab\local\engine_identity::snapshot($attemptid, 'job_complete:fenced')],
            ]);
            $recovered = attempt_scheduler::recover_after_fenced_report($attemptid, $execution);

            return [
                'acknowledged' => false,
                'message'      => get_string('job:stale', 'local_catquizlab'),
                'fence'        => $fence,
                'recovered'    => $recovered,
            ];
        }

        // The engine attempt a finished report is collected from is the sitting's own (#173): the one bound to it,
        // or — for a sitting no execution bound — the account's only attempt on the activity. The worker's id, when
        // it sends one, has to be that one; a report naming another attempt, or a sitting with two unbound attempts,
        // is not collected: it is failed with the reason and held for a person to look at.
        // What the server itself reads of the sitting's engine attempt now (#176 AK02): kept with the outcome, beside
        // what the worker reports — the worker's count is the worker's, this is the engine's.
        $server = \local_catquizlab\local\engine_identity::snapshot($attemptid, 'job_complete');
        $identityproblem = '';
        if ($status === 'finished') {
            $resolved = \local_catquizlab\local\engine_identity::for_collection($attemptid, $engineattemptid);
            $engineattemptid = (int) $resolved['id'];
            $identityproblem = (string) $resolved['reason'];
        }

        // A finished attempt has an engine attempt behind it. Without one there
        // is nothing to collect a trace from, so accepting the report would
        // record a completed attempt that produced no data — and the run would
        // look done while holding nothing.
        $finished = ($status === 'finished') && $engineattemptid > 0;
        if ($status === 'finished' && $engineattemptid <= 0) {
            debugging(
                'Worker reported attempt ' . $attemptid . ' as finished without an engine attempt of its own ('
                    . $identityproblem . ').',
                DEBUG_DEVELOPER
            );
        }
        // Why it failed, as a code, decided before anything else happens to the sitting (#175): the retry decision
        // and the breaker read it, so it is recorded first.
        $message = (string) ($params['message'] ?? '');
        if ($status === 'finished' && !$finished) {
            $message = '[identity:' . ($identityproblem !== '' ? $identityproblem : 'none') . '] '
                . get_string('job:noengineidentity', 'local_catquizlab', $identityproblem)
                . ($message !== '' ? ' | ' . $message : '');
        }
        $extra = (array) (json_decode((string) ($params['diagnostics'] ?? ''), true) ?: []);
        $code = $finished ? '' : \local_catquizlab\local\reason_catalog::reported((string) $params['reason'], $message, $extra);

        // Collected means: with a trace (#138). Set where the collector has one.
        $collected = false;
        if ($finished) {
            // The runtime and the engine attempt are written with the new status, in one update (#138): no reader
            // sees a sitting with its engine attempt and still "running", or "collected" without its trace.
            $set = $runtimems > 0 ? ['runtimems' => $runtimems] : [];

            // Pull the engine trace into the attempt when possible (no-op without the engine).
            if ($engineattemptid > 0) {
                // The status "collected" and the trace become visible together: the collector
                // writes both in one update. The status used to be set first, and a
                // reader in between saw a collected sitting with no trace — the
                // smoke test read 0 answered questions for a CLASSIC sitting of 120
                // (#136, local run against the engine heads, 18.10.2026).
                // Collected only with a trace (#138). Without one the sitting waits for it — not collected,
                // not valid, not finished — and is looked at again by the pipeline tick.
                // The engine's person parameters of this person are not touched here (#174): the collector removes
                // them once the trace is stored, and a sitting still waiting for its trace needs them — its standard
                // errors are read from them.
                $collected = attempt_scheduler::collect_or_hold(
                    $attemptid,
                    $reporter,
                    $engineattemptid,
                    $set,
                    'worker-report'
                );
            }
        } else {
            // The worker's own reason for a failure, kept where the interface can
            // show it. Without this a retried attempt gives no clue why, and the
            // person looking has only a rising try count.
            \local_catquizlab\local\attempt_scheduler::record_error(
                $attemptid,
                $message !== '' ? $message : 'worker reported failure'
            );
        }

        // What this execution did, kept where a retry cannot erase it — and, for a failure, with its code, before the
        // retry decision and the breaker read it (#175).
        $logid = \local_catquizlab\local\attempt_history::record(
            $attemptid,
            $finished
                ? ($collected ? \local_catquizlab\local\attempt_history::COLLECTED
                    : \local_catquizlab\local\attempt_history::TRACE_PENDING)
                : \local_catquizlab\local\attempt_history::FAILED,
            [
                // The worker is not a parameter of this call; it holds the
                // lease, which is cleared just below. Read before it goes.
                'workerid'        => (string) ($attempt->leaseowner ?? ''),
                'engineattemptid' => (int) ($params['engineattemptid'] ?? 0),
                'runtimems'       => (int) ($params['runtimems'] ?? 0),
                'detail'          => $finished ? (string) ($params['message'] ?? '') : $message,
                'tryno'           => (int) $attempt->tries,
                'reasoncode'      => $finished ? null : $code,
                // The browser's own record, kept with the diagnosis (#100) —
                // and how long the lease was held, from the claim (#133).
                'extra'           => (array) (json_decode((string) ($params['diagnostics'] ?? ''), true) ?: [])
                    + ['lease' => self::lease_held($attemptid, (int) $attempt->tries, (int) ($attempt->leaseexpires ?? 0)),
                        'server' => $server],
            ]
        );

        // Every submission that ended uncertainly and was reconciled with the
        // server keeps a row of its own, with its cause (#134).
        if (!empty($extra['reconciliations']) && is_array($extra['reconciliations'])) {
            \local_catquizlab\local\attempt_history::record_reconciliations($attemptid, $extra['reconciliations'], [
                'workerid' => (string) ($attempt->leaseowner ?? ''),
                'tryno' => (int) $attempt->tries,
            ]);
        }

        // A failure documented in moodledata beside the worker's artefacts,
        // with the run's design, the reason code and a log excerpt (#107). A
        // finished sitting is documented once its stop reason is collected.
        if (!$finished && $logid > 0) {
            \local_catquizlab\local\artefact_store::document(
                $attemptid,
                \local_catquizlab\local\attempt_history::FAILED,
                (string) $DB->get_field('local_catquizlab_attemptlog', 'reasoncode', ['id' => $logid]),
                $message
            );
        }

        // What becomes of a failed sitting, by its code (#175). A worker that found an invariant broken — an answer
        // beyond the run's pool, no progress the server confirms, another identity than the sitting's — fails the
        // same way when played again: the sitting is held, its engine attempt and person parameters kept, until a
        // person puts it back. Anything else is retried with backoff while tries remain, as before. The engine's
        // person parameters are kept in both cases (#174): the retry continues the same engine attempt, which goes
        // on from its own intermediate estimates.
        if (!$finished) {
            if (\local_catquizlab\local\circuit_breaker::holds($code)) {
                attempt_scheduler::hold($attemptid, $code);
            } else {
                attempt_scheduler::retry_or_fail($attemptid);
            }
        }

        // The lease is over either way: the attempt is no longer being played.
        $DB->set_field('local_catquizlab_attempt', 'leaseowner', null, ['id' => $attemptid]);
        $DB->set_field('local_catquizlab_attempt', 'leaseexpires', 0, ['id' => $attemptid]);

        // A run failing the same way over and over does not improve by being
        // retried. The breaker holds it as failed with the cause, blocks the
        // experiment, and keeps the remaining sittings out of the pool — this is
        // the path the worker actually reports through, so the breaker has to
        // be here and not only on the scheduler's retry path.
        if (!$finished) {
            \local_catquizlab\local\circuit_breaker::check((int) $attempt->runid);
        }

        \local_catquizlab\local\run_lifecycle::attempt_finished((int) $attempt->runid);

        return [
            'acknowledged' => true,
            'message'      => get_string('job:acknowledged', 'local_catquizlab'),
        ];
    }

    /**
     * How long this execution held its sitting, and how much lease it had left (#133).
     *
     * @param int $attemptid The sitting.
     * @param int $tryno The execution.
     * @param int $leaseexpires When its lease would have run out.
     * @return array heldseconds, leftseconds.
     */
    protected static function lease_held(int $attemptid, int $tryno, int $leaseexpires): array {
        global $DB;

        $started = $DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $attemptid, 'tryno' => $tryno,
            'outcome' => \local_catquizlab\local\attempt_history::STARTED], 'id DESC', 'id, timecreated', 0, 1);
        $since = $started === [] ? 0 : (int) reset($started)->timecreated;

        return [
            'heldseconds' => $since > 0 ? time() - $since : null,
            'leftseconds' => $leaseexpires > 0 ? $leaseexpires - time() : null,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'acknowledged' => new external_value(PARAM_BOOL, 'True when the report was accepted.'),
            // Not PARAM_TEXT: the message can carry a replayed exception with
            // angle brackets and newlines, and the input side already had to
            // be widened for exactly that.
            'message'      => new external_value(PARAM_RAW, 'Human-readable status.'),
            'fence'        => new external_value(
                PARAM_ALPHANUMEXT,
                'Why the report was not applied: the reporter does not hold the lease (#173); empty when it was.',
                VALUE_OPTIONAL
            ),
            'recovered'    => new external_value(
                PARAM_ALPHA,
                'What the server did from its own state after a fenced report: reclaimed, collected, tracepending; '
                    . 'empty for nothing.',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
