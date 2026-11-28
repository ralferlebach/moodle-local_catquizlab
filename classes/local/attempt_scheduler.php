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
 * Attempt scheduler: materialises a run's attempt queue.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Creates the queued attempt rows a run needs, one per provisioned person (E3.1).
 *
 * Scheduling is pure lab-store bookkeeping: it inserts an attempt row at status
 * "queued" for every person of the run that has a Moodle user and no attempt
 * yet, and marks the run scheduled. It performs no engine work and starts no
 * worker — the timed ad-hoc task {@see \local_catquizlab\task\schedule_attempts}
 * calls it, and the collect/execute steps act on the rows it produces.
 */
class attempt_scheduler {
    /** @var string Tag of the failure row for a sitting whose worker was found dead (#134). */
    public const WORKER_GONE = '[worker:gone]';

    /** @var string Tag of the failure row for a sitting whose lease ran out unrenewed (#134). */
    public const LEASE_LAPSED = '[lease:lapsed]';

    /** @var int Attempt status: queued, not yet played. */
    public const STATUS_QUEUED = 0;

    /** @var int Attempt status: being played by a worker. */
    public const STATUS_RUNNING = 10;

    /** @var int Attempt status: trace collected from the engine. */
    public const STATUS_COLLECTED = 20;

    /**
     * @var int Finished in the engine, its trace not (yet) collected (#138): neither collected nor valid nor
     *      finished in any figure; collected again later, given up as a technical non-result after TRACE_RETRIES.
     */
    public const STATUS_TRACEPENDING = 25;

    /** @var int How many times a missing trace is looked for before the sitting is given up (#138). */
    public const TRACE_RETRIES = 5;

    /** @var int Seconds between two looks for a missing trace. */
    public const TRACE_RETRY_DELAY = 60;

    /** @var int Attempt status: collected trace validated. */
    public const STATUS_VALIDATED = 30;

    /** @var int Attempt status: failed — a technical end, its tries spent or its run failed. */
    public const STATUS_FAILED = 40;

    /**
     * @var int Attempt status: closed by an operator — the experiment or the run was stopped (#166). Not a failure:
     *      nothing went wrong with the sitting, somebody decided it should not be played now. Counted apart from
     *      failed everywhere, and the one an experiment resumed plays again (#165). Terminal like failed otherwise.
     */
    public const STATUS_CANCELLED = 45;

    /** @var int[] The statuses a sitting ends in: collected, validated, failed, closed by an operator. */
    public const TERMINAL = [self::STATUS_COLLECTED, self::STATUS_VALIDATED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    /** @var int Maximum number of tries before an attempt is failed for good. */
    public const MAX_TRIES = 3;

    /** @var int Retry backoff base in seconds (multiplied by the try count). */
    public const RETRY_BACKOFF = 60;

    /**
     * The status a stuck/failed attempt should take given how often it was tried.
     *
     * @param int $tries How many times it has been tried.
     * @param int $maxtries The retry ceiling.
     * @return int STATUS_QUEUED to retry, or STATUS_FAILED when exhausted.
     */
    public static function retry_status(int $tries, int $maxtries = self::MAX_TRIES): int {
        return $tries < $maxtries ? self::STATUS_QUEUED : self::STATUS_FAILED;
    }

    /**
     * Reclaim attempts stuck in "running" longer than the timeout.
     *
     * A crashed worker leaves an attempt running forever; this requeues it (with
     * a backoff) when tries remain, or fails it when they are exhausted.
     *
     * @param int|null $runid Limit to one run, or null for all runs.
     * @param int $timeoutseconds The staleness threshold in seconds.
     * @return int The number of attempts reclaimed (requeued or failed).
     */
    /**
     * A sitting's state as a person needs it (#175 AK05): on hold (resumable once the cause is dealt with) apart from
     * failed for good (its retries used up), and a technical or incomplete end (completion contract, #179) apart from
     * a regular one. The status alone says "failed" or "collected" for both.
     *
     * @param \stdClass $sitting Its row: status, tries, lasterror, completionjson.
     * @param array $history Its history (attempt_history::of_attempt()).
     * @return array{code: string, label: string}
     */
    public static function state_in_words(\stdClass $sitting, array $history): array {
        $status = (int) $sitting->status;
        if ($status === self::STATUS_FAILED) {
            // Held: an invariant was broken (#175), or the identity is in doubt (#173). Resumable once somebody dealt
            // with the cause — the last word of the history after any requeue says so.
            $last = '';
            foreach ($history as $entry) {
                if (
                    in_array($entry['outcome'], [attempt_history::HELD, attempt_history::REQUEUED, attempt_history::RESUMED,
                        attempt_history::FAILED, attempt_history::DECIDED], true)
                ) {
                    $last = $entry['outcome'];
                }
            }
            if ($last === attempt_history::DECIDED) {
                return ['code' => 'decided', 'label' => get_string('attemptstate:decided', 'local_catquizlab')];
            }
            $held = $last === attempt_history::HELD || strpos((string) ($sitting->lasterror ?? ''), '[identity:') === 0;
            return $held
                ? ['code' => 'held', 'label' => get_string('attemptstate:held', 'local_catquizlab')]
                : ['code' => 'exhausted', 'label' => get_string(
                    'attemptstate:exhausted',
                    'local_catquizlab',
                    (int) $sitting->tries
                )];
        }
        if (in_array($status, [self::STATUS_COLLECTED, self::STATUS_VALIDATED, self::STATUS_TRACEPENDING], true)) {
            $class = (string) ((json_decode((string) ($sitting->completionjson ?? ''), true) ?: [])['class'] ?? '');
            if (
                in_array($class, [completion_contract::TECHNICAL, completion_contract::INCOMPLETE,
                    completion_contract::UNKNOWN], true)
            ) {
                return ['code' => $class, 'label' => get_string('attemptstate:' . $class, 'local_catquizlab')];
            }
        }

        return ['code' => 'status' . $status, 'label' => self::status_label($status)];
    }

    /**
     * A sitting's status in words.
     *
     * @param int $status One of the STATUS_ constants.
     * @return string
     */
    public static function status_label(int $status): string {
        $keys = [
            self::STATUS_QUEUED    => 'attemptstatus:queued',
            self::STATUS_RUNNING   => 'attemptstatus:running',
            self::STATUS_COLLECTED => 'attemptstatus:collected',
            self::STATUS_TRACEPENDING => 'attemptstatus:tracepending',
            self::STATUS_VALIDATED => 'attemptstatus:validated',
            self::STATUS_FAILED    => 'attemptstatus:failed',
            self::STATUS_CANCELLED => 'attemptstatus:cancelled',
        ];

        return get_string($keys[$status] ?? 'attemptstatus:unknown', 'local_catquizlab');
    }

    /**
     * How the queue breaks down for an operator, not for the database.
     *
     * `QUEUED` is a storage state and was being read as "waiting for a worker",
     * which is only one of the things it can mean. An attempt can be claimable
     * now, not due yet after a failure, held back because its run is not in a
     * state that hands out work, or paused by a decision somebody took. Those
     * four need four different responses, and one number gave them one.
     *
     * And a fifth since 0.7.65 (#163): sittings of a run that could hand out work, but whose experiment does not hold
     * the execution line. A worker claims only the holder's, so these wait for their experiment's turn — counted as
     * "waitingturn", not as claimable, which would have workers started for work they may not take.
     *
     * @param int $experimentid Restrict to one experiment, or 0 for the installation.
     * @return array{claimable: int, notdue: int, blocked: int, paused: int, waitingturn: int,
     *               running: int, collected: int, failed: int, queued: int}
     */
    public static function queue_breakdown(int $experimentid = 0): array {
        global $DB;

        // Scoped to one experiment when the caller names one. A page showing
        // experiment A used to put A's progress beside the whole
        // installation's queue: "18 of 50" over "150 sittings blocked" when
        // the 150 belonged to experiment B. The counts and the card now answer
        // for the same thing; the worker pool and the pipeline stay
        // site-wide, because they are, and the interface says so.
        $scope = '';
        $scopeparams = [];
        if ($experimentid > 0) {
            $scope = ' AND runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = :experimentid)';
            $scopeparams['experimentid'] = $experimentid;
        }

        // One grouped query for the terminal states instead of one count per
        // state: three separate counts over fifty thousand sittings cost about
        // 450 ms, and a live poll asks for them every two seconds.
        $bystatus = $DB->get_records_sql(
            'SELECT status, COUNT(1) AS n
               FROM {local_catquizlab_attempt}
              WHERE 1 = 1' . $scope . '
           GROUP BY status',
            $scopeparams
        );
        $count = static function (int $status) use ($bystatus): int {
            return (int) ($bystatus[$status]->n ?? 0);
        };

        // Of the failed, those on hold (#175 AK05, #181 AK06): their engine attempt still open, resumable after a
        // decision — the projection's "held" (question_progress). The rest failed for good.
        $heldsittings = (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {local_catquizlab_attempt} a
               JOIN {adaptivequiz_attempt} aa ON aa.id = a.engineattemptid
              WHERE a.status = :failedheld AND aa.attemptstate = 'inprogress'"
                . ($experimentid > 0 ? ' AND a.runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = :heldexp)'
                    : ''),
            ['failedheld' => self::STATUS_FAILED] + ($experimentid > 0 ? ['heldexp' => $experimentid] : [])
        );

        $now = time();
        $counts = [
            'experimentid' => $experimentid,
            'claimable' => 0,
            'notdue'    => 0,
            'blocked'   => 0,
            'paused'    => 0,
            'waitingturn' => 0,
            // The experiment whose sittings a worker asking now is handed (#163): the line's holder, or the one a
            // claim would make the holder.
            'holder'    => execution_queue::next_holder(),
            'running'   => $count(self::STATUS_RUNNING),
            'collected' => $count(self::STATUS_COLLECTED),
            // Failed for good; those on hold apart (heldsittings), never both.
            'failed'    => $count(self::STATUS_FAILED) - $heldsittings,
            'heldsittings' => $heldsittings,
            // Closed by an operator (#166): not failed.
            'cancelled' => $count(self::STATUS_CANCELLED),
            // Finished, waiting for its trace (#138): neither collected nor failed.
            'tracepending' => $count(self::STATUS_TRACEPENDING),
            'pausecauses' => [],
            // Why the sittings of each run that cannot hand out work wait (#133 AK2): its state and, where it failed,
            // the reason it failed with.
            'blockcauses' => [],
        ];

        // Grouped by run so the run's state is read once rather than per
        // attempt: a queue of 1600 would otherwise be 1600 lookups to draw one
        // line of text.
        $rows = $DB->get_records_sql(
            'SELECT a.runid, r.experimentid, r.status AS runstatus, r.manifestjson,
                    SUM(CASE WHEN a.nextruntime <= :now THEN 1 ELSE 0 END) AS due,
                    COUNT(1) AS total
               FROM {local_catquizlab_attempt} a
               JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE a.status = :queued'
              . ($experimentid > 0 ? ' AND r.experimentid = :experimentid' : '')
              . ' GROUP BY a.runid, r.experimentid, r.status, r.manifestjson',
            ['now' => $now, 'queued' => self::STATUS_QUEUED] + $scopeparams
        );

        // Which runs cannot hand out work for want of a single scale generation: one query for all of them, not
        // one per run (the poll of "All experiments" asked twice per run, #160 AK12).
        $recovery = scale_inventory::recovery_required_many(array_keys($rows));
        foreach ($rows as $row) {
            $total = (int) $row->total;
            $due = (int) $row->due;

            $manifest = json_decode((string) $row->manifestjson, true) ?: [];
            if (!empty($manifest['lifecycle']['paused'])) {
                $counts['paused'] += $total;
                // Why, per run (#133): a pause by the circuit breaker names its cause.
                $reason = (string) ($manifest['lifecycle']['pausedreason'] ?? '');
                $cause = strpos($reason, circuit_breaker::PAUSE_REASON) === 0
                    ? reason_catalog::label(substr($reason, strlen(circuit_breaker::PAUSE_REASON) + 1))
                    : get_string('queue:pausedbyoperator', 'local_catquizlab');
                $counts['pausecauses'][] = ['runid' => (int) $row->runid, 'cause' => $cause, 'waiting' => $total,
                    'line' => get_string(
                        'queue:pausecause',
                        'local_catquizlab',
                        (object) ['runid' => (int) $row->runid, 'cause' => $cause, 'waiting' => $total]
                    )];
                continue;
            }

            // The same as run_lifecycle::is_runnable(), from what this query already read.
            $runnable = in_array((int) $row->runstatus, [registry::STATUS_READY, registry::STATUS_RUNNING], true)
                && !isset($recovery[(int) $row->runid]);
            if (!$runnable) {
                // Queued against a run that cannot hand out work: scheduled,
                // failed, finished. The attempts are real and unreachable, and
                // showing them as waiting invites waiting for them.
                $counts['blocked'] += $total;
                if (isset($recovery[(int) $row->runid])) {
                    $cause = get_string('queue:blockrecovery', 'local_catquizlab');
                } else {
                    $cause = run_registry::status_label((int) $row->runstatus);
                    $failed = (string) ($manifest['lifecycle']['failedreason'] ?? '');
                    if ((int) $row->runstatus === registry::STATUS_FAILED && $failed !== '') {
                        $cause .= ': ' . reason_catalog::text($failed);
                    }
                }
                $counts['blockcauses'][] = ['runid' => (int) $row->runid, 'cause' => $cause, 'waiting' => $total,
                    'line' => get_string(
                        'queue:blockcause',
                        'local_catquizlab',
                        (object) ['runid' => (int) $row->runid, 'cause' => $cause, 'waiting' => $total]
                    )];
                continue;
            }

            // Not the line's holder (#163): its sittings wait for the experiment's turn, due or not.
            if ((int) $row->experimentid !== $counts['holder']) {
                $counts['waitingturn'] += $total;
                continue;
            }

            $counts['claimable'] += $due;
            $counts['notdue'] += $total - $due;
        }

        $counts['queued'] = $counts['claimable'] + $counts['notdue']
            + $counts['blocked'] + $counts['paused'] + $counts['waitingturn'];

        return $counts;
    }

    /**
     * Whether there is work a worker could actually pick up right now.
     *
     * @return bool
     */
    public static function has_claimable_work(): bool {
        return self::queue_breakdown()['claimable'] > 0;
    }

    /**
     * Hand back everything a named worker was holding.
     *
     * Called when a worker is found to have died. This is the deliberate
     * counterpart to the timeout below: here we know the holder is gone, so
     * there is nothing to guess about.
     *
     * @param string $workerid The worker whose claims lapse.
     * @return int How many attempts went back into the queue.
     */
    public static function release_lease(string $workerid): int {

        global $DB;

        $held = $DB->get_records_select(
            'local_catquizlab_attempt',
            'status = :status AND leaseowner = :owner',
            ['status' => self::STATUS_RUNNING, 'owner' => $workerid],
            '',
            'id, tries'
        );

        $now = time();
        foreach ($held as $attempt) {
            $DB->set_field('local_catquizlab_attempt', 'leaseowner', null, ['id' => $attempt->id]);
            $DB->set_field('local_catquizlab_attempt', 'leaseexpires', 0, ['id' => $attempt->id]);
            self::record_lost_execution((int) $attempt->id, (int) $attempt->tries, $workerid, self::WORKER_GONE);
            self::apply_retry((int) $attempt->id, (int) $attempt->tries, $now, 'lease_released');
        }

        return count($held);
    }

    /**
     * Record why an attempt failed, for the interface and for the next reader.
     *
     * @param int $attemptid The attempt.
     * @param string $error The worker's own reason.
     * @return void
     */
    public static function record_error(int $attemptid, string $error): void {
        // Never a session key in a stored error; see attempt_history::redact().
        $error = attempt_history::redact($error);

        global $DB;

        if (trim($error) === '') {
            return;
        }

        // Trimmed, because a stack trace in a list column helps nobody, and the
        // first line is what says what happened.
        $DB->set_field(
            'local_catquizlab_attempt',
            'lasterror',
            \core_text::substr(trim($error), 0, 1000),
            ['id' => $attemptid]
        );
    }

    /**
     * Hand back attempts whose claim has lapsed.
     *
     * The lease decides where there is one; the timeout is the fallback for
     * attempts claimed before leases existed.
     *
     * @param int|null $runid Restrict to one run, or null for all of them.
     * @param int $timeoutseconds How long a leaseless claim may sit untouched.
     * @return int How many attempts went back into the queue.
     */
    public static function reclaim_stale(?int $runid, int $timeoutseconds): int {

        global $DB;

        $now = time();

        // The lease decides, and the timeout is the fallback for attempts that
        // predate leases. Keying on timemodified alone was the defect: a worker
        // refreshes it while it works, so a stuck attempt and a slow one looked
        // the same.
        $params = ['status' => self::STATUS_RUNNING, 'cutoff' => $now - $timeoutseconds, 'now' => $now];
        $where = 'status = :status AND ((leaseexpires > 0 AND leaseexpires < :now)'
            . ' OR (leaseexpires = 0 AND timemodified < :cutoff))';
        if ($runid !== null) {
            $where .= ' AND runid = :runid';
            $params['runid'] = $runid;
        }

        $stale = $DB->get_records_select('local_catquizlab_attempt', $where, $params, '', 'id, tries, leaseowner, runid');
        $reclaimed = 0;
        foreach ($stale as $attempt) {
            // Under the sitting's lock, and only if its lease is still lapsed (#138): a completion report that came in
            // meanwhile has ended it, and a heartbeat has renewed it. A sitting another process holds now is left for
            // the next tick.
            [$had, $done] = sitting_lock::run((int) $attempt->id, 0, static function () use (
                $DB,
                $attempt,
                $where,
                $params,
                $now
            ): bool {
                $still = $DB->get_record_select(
                    'local_catquizlab_attempt',
                    'id = :attemptid AND ' . $where,
                    ['attemptid' => $attempt->id] + $params,
                    'id, tries, leaseowner, runid'
                );
                if (!$still) {
                    return false;
                }
                self::reclaim_one($still, $now);
                return true;
            });
            if ($had && $done) {
                $reclaimed++;
            }
        }
        return $reclaimed;
    }

    /**
     * Hand back one sitting whose lease has lapsed, with its lock held: collected if the engine finished it,
     * otherwise retried or failed.
     *
     * @param \stdClass $attempt The sitting (id, tries, leaseowner, runid).
     * @param int $now The clock.
     * @return void
     */
    protected static function reclaim_one(\stdClass $attempt, int $now): void {
        global $DB;

        $DB->set_field('local_catquizlab_attempt', 'leaseowner', null, ['id' => $attempt->id]);
        $DB->set_field('local_catquizlab_attempt', 'leaseexpires', 0, ['id' => $attempt->id]);

        // Not handed blindly to the next execution (#133): the server says
        // first what became of it. An attempt the engine has completed is
        // collected, not played again.
        $state = \local_catquizlab\external\sitting_state::state((int) $attempt->id);
        if ($state['found'] && $state['state'] === 'complete') {
            // The engine attempt is written with the status the collector sets, in one update: collected only
            // with a trace (#138), otherwise waiting for it.
            $collected = self::collect_or_hold(
                (int) $attempt->id,
                (string) ($attempt->leaseowner ?? ''),
                (int) $state['engineattemptid'],
                [],
                'lease-recovery'
            );
            attempt_history::record((int) $attempt->id, $collected ? attempt_history::COLLECTED
                : attempt_history::TRACE_PENDING, [
                'tryno' => (int) $attempt->tries,
                'workerid' => (string) ($attempt->leaseowner ?? ''),
                'engineattemptid' => (int) $state['engineattemptid'],
                'detail' => get_string(
                    $collected ? 'history:leasecollected' : 'history:leasetracepending',
                    'local_catquizlab',
                    (int) $state['questionsattempted']
                ),
                'extra' => ['server' => engine_identity::snapshot((int) $attempt->id, 'lease-recovery')],
            ]);
            run_lifecycle::attempt_finished((int) $attempt->runid);
            return;
        }
        // The code first, for whoever counts causes; what the engine knew in words.
        $why = 'lease_expired ' . ($state['found']
            ? get_string('history:leaseengine', 'local_catquizlab', (object) [
                'worker' => (string) ($attempt->leaseowner ?? '?'), 'state' => $state['state'],
                'answered' => (int) $state['questionsattempted'], 'presented' => (int) $state['slots'],
            ])
            : get_string('history:leasenoengine', 'local_catquizlab', (object) [
                'worker' => (string) ($attempt->leaseowner ?? '?'), 'reason' => $state['reason'],
            ]));
        self::record_lost_execution(
            (int) $attempt->id,
            (int) $attempt->tries,
            (string) ($attempt->leaseowner ?? ''),
            self::LEASE_LAPSED
        );
        self::apply_retry((int) $attempt->id, (int) $attempt->tries, $now, $why);
    }

    /**
     * What a fenced report leaves to be done, decided by the server alone (#173, re-audit 27.11.2026).
     *
     * A report from an execution that does not hold the lease (lease_fence) changes nothing it says: not the status,
     * not the trace, not the binding. Two cases still need doing, and both are decided from the server's own state,
     * exactly as the lease recovery decides them:
     * - the sitting is still running on a lease that has run out: handed back now (reclaim_one()), collected when the
     *   engine has completed its own attempt, retried otherwise;
     * - the sitting went back to the queue, and its own engine attempt is complete on the server (the old execution's
     *   last answer was stored after the recovery had looked): collected now, not played again — a second sitting of
     *   the same person would be the double sitting of #134.
     * Idempotent: a collected, waiting, held, failed or cancelled sitting, or one another execution holds on a lease
     * that has not run out, is left as it is however often a report comes.
     *
     * Called with the sitting's lock held.
     *
     * @param int $attemptid The sitting.
     * @param int $execution The identity of the execution whose report was fenced, for the record.
     * @param int|null $now The clock.
     * @return string 'reclaimed', 'collected', 'tracepending', or '' when there is nothing to do.
     */
    public static function recover_after_fenced_report(int $attemptid, int $execution, ?int $now = null): string {
        global $DB;

        $now = $now ?? time();
        $sitting = $DB->get_record(
            'local_catquizlab_attempt',
            ['id' => $attemptid],
            'id, runid, status, tries, leaseowner, leaseexpires'
        );
        if (!$sitting) {
            return '';
        }
        if (lease_fence::lapsed($sitting, $now)) {
            self::reclaim_one($sitting, $now);

            return 'reclaimed';
        }
        if ((int) $sitting->status !== self::STATUS_QUEUED) {
            return '';
        }
        $state = \local_catquizlab\external\sitting_state::state($attemptid);
        if (!$state['found'] || $state['state'] !== 'complete') {
            return '';
        }
        $collected = self::collect_or_hold($attemptid, '', (int) $state['engineattemptid'], [], 'late-report');
        attempt_history::record($attemptid, $collected ? attempt_history::COLLECTED : attempt_history::TRACE_PENDING, [
            'tryno' => (int) $sitting->tries,
            'engineattemptid' => (int) $state['engineattemptid'],
            'detail' => get_string('history:fencedrecovered', 'local_catquizlab', (object) [
                'execution' => $execution,
                'engine' => (int) $state['engineattemptid'],
                'answered' => (int) $state['questionsattempted'],
            ]),
            'extra' => ['server' => engine_identity::snapshot($attemptid, 'fenced-report-recovery')],
        ]);
        run_lifecycle::attempt_finished((int) $sitting->runid);

        return $collected ? 'collected' : 'tracepending';
    }

    /**
     * The failure of an execution nobody reported: its worker died or stopped renewing the lease (#134).
     *
     * A worker that fails reports it, and its report is the history's failure row with a reason code. A worker that is
     * killed reports nothing: before 0.7.59 its sitting went back into the queue with only a "requeued" row, the
     * export's transport events stayed empty, and a sitting played twice looked like one played once (Kampagne B,
     * audit 11.11.2026: a worker killed with SIGKILL in the middle of a sitting).
     *
     * @param int $attemptid The sitting.
     * @param int $tries Its execution.
     * @param string $workerid The worker that held it.
     * @param string $tag WORKER_GONE or LEASE_LAPSED; reason_catalog reads it as worker_timeout.
     * @return void
     */
    protected static function record_lost_execution(int $attemptid, int $tries, string $workerid, string $tag): void {
        attempt_history::record($attemptid, attempt_history::FAILED, [
            'tryno' => $tries,
            'workerid' => $workerid !== '' ? $workerid : null,
            'detail' => $tag . ' ' . get_string(
                $tag === self::WORKER_GONE ? 'history:workergone' : 'history:leaselapsed',
                'local_catquizlab',
                $workerid !== '' ? $workerid : '?'
            ),
        ]);
    }

    /**
     * Keep a sitting's lease while its worker is still on it (#133).
     *
     * The lease used to be set once, at the claim, for five minutes, and never
     * renewed. A sitting that took longer — long classical tests, a loaded
     * server — lost it while its worker was still answering: it went back into
     * the queue, its person became claimable, and a second worker logged in as
     * the same person into the same attempt. Run 51 recorded 303 such
     * "lease_expired" requeues. Every heartbeat now renews the lease of the
     * sitting its worker names — only while that worker still holds it.
     *
     * Fenced (#173, re-audit 27.11.2026): renewed is only the lease of the execution that holds it — this worker, this
     * execution, and a lease that has not run out. A lease that ran out is lost, also before the recovery hands the
     * sitting back: renewing it would revive an execution the recovery may be collecting or requeuing at that moment.
     * One conditional update, read back: a reclaim between the two is seen as a lost lease.
     *
     * @param string $workerid The worker reporting.
     * @param int $attemptid The sitting it says it is playing.
     * @param int $execution The execution's identity (the claim's "executionid").
     * @param int|null $now The clock.
     * @return bool Whether it still holds it (and now for longer).
     */
    public static function renew_lease(string $workerid, int $attemptid, int $execution, ?int $now = null): bool {
        global $DB;

        if ($workerid === '' || $attemptid <= 0 || $execution <= 0) {
            return false;
        }
        $now = $now ?? time();
        $until = $now + worker_registry::HEARTBEAT_TIMEOUT;
        $where = 'id = :id AND status = :running AND leaseowner = :owner AND executionid = :execution';
        $params = ['id' => $attemptid, 'running' => self::STATUS_RUNNING, 'owner' => $workerid, 'execution' => $execution];
        $DB->execute(
            "UPDATE {local_catquizlab_attempt} SET leaseexpires = :until WHERE {$where} AND leaseexpires >= :now",
            $params + ['until' => $until, 'now' => $now]
        );

        return $DB->record_exists_select(
            'local_catquizlab_attempt',
            "{$where} AND leaseexpires = :until",
            $params + ['until' => $until]
        );
    }

    /**
     * Record a failed attempt, requeuing it with backoff when tries remain.
     *
     * @param int $attemptid The attempt.
     * @return int The resulting status.
     */
    public static function retry_or_fail(int $attemptid): int {
        global $DB;

        $tries = (int) $DB->get_field('local_catquizlab_attempt', 'tries', ['id' => $attemptid]);
        return self::apply_retry($attemptid, $tries, time());
    }

    /**
     * Hold a sitting whose execution broke an invariant: failed now, not retried, kept as it is (#175).
     *
     * A worker that found an answer beyond the run's pool, no progress the server confirms, or an engine attempt that
     * is not the sitting's would find the same when played again; retried until its tries run out, eighteen such
     * sittings were nineteen executions of a thousand answers each (CLARA run 103). The sitting is failed with its
     * code instead, its engine attempt and the engine's person parameters are left as they are, and it stays where a
     * person can put it back (run_lifecycle::requeue_failed(), the breaker's resume) once the cause is dealt with.
     *
     * @param int $attemptid The sitting.
     * @param string $code The reason code.
     * @return int The resulting status (STATUS_FAILED).
     */
    public static function hold(int $attemptid, string $code): int {
        global $DB;

        $tries = (int) $DB->get_field('local_catquizlab_attempt', 'tries', ['id' => $attemptid]);
        $DB->update_record('local_catquizlab_attempt', (object) [
            'id' => $attemptid,
            'status' => self::STATUS_FAILED,
            'nextruntime' => 0,
            'timemodified' => time(),
        ]);
        attempt_history::record($attemptid, attempt_history::HELD, [
            'tryno' => $tries,
            'reasoncode' => $code,
            'detail' => get_string('history:held', 'local_catquizlab', reason_catalog::label($code)),
        ]);
        $runid = (int) $DB->get_field('local_catquizlab_attempt', 'runid', ['id' => $attemptid]);
        if ($runid > 0) {
            circuit_breaker::check($runid);
        }

        return self::STATUS_FAILED;
    }

    /**
     * Abort a run: close every sitting that has not reached a terminal state, as cancelled by an operator (#166).
     *
     * @param int $runid The run.
     * @return int The number of attempts aborted.
     */
    public static function abort(int $runid): int {

        global $DB;

        $active = [self::STATUS_QUEUED, self::STATUS_RUNNING];
        [$insql, $params] = $DB->get_in_or_equal($active, SQL_PARAMS_NAMED);
        $params['runid'] = $runid;
        $ids = $DB->get_fieldset_select('local_catquizlab_attempt', 'id', "runid = :runid AND status $insql", $params);
        $count = count($ids);

        // A decision, not a defect: cancelled, with the reason in the same words in every language.
        $DB->execute(
            "UPDATE {local_catquizlab_attempt}
                SET status = :cancelled, lasterror = :reason, leaseowner = NULL, leaseexpires = 0, timemodified = :now
              WHERE runid = :runid AND status $insql",
            $params + ['cancelled' => self::STATUS_CANCELLED, 'reason' => 'aborted_by_operator', 'now' => time()]
        );
        attempt_history::record_many(
            $ids,
            attempt_history::CANCELLED,
            'aborted_by_operator',
            get_string('history:runaborted', 'local_catquizlab')
        );

        if ($count > 0) {
            \local_catquizlab\event\run_aborted::create([
                'objectid' => $runid,
                'context'  => \context_system::instance(),
            ])->trigger();
        }

        return $count;
    }

    /**
     * The random share of a retry's wait, in seconds: 0 to the setting retry_spread (#120).
     *
     * @return int
     */
    public static function retry_spread(): int {
        $max = get_config('local_catquizlab', 'retry_spread');
        $max = $max === false || $max === '' ? 300 : max(0, (int) $max);

        return $max > 0 ? random_int(0, $max) : 0;
    }

    /**
     * When the sittings waiting for a retry are due: the earliest and the latest (#120).
     *
     * @param int $experimentid Restrict to one experiment, 0 for the installation.
     * @return array{n: int, first: int, last: int}
     */
    public static function retry_window(int $experimentid = 0): array {
        global $DB;

        $sql = 'SELECT COUNT(1) AS n, MIN(a.nextruntime) AS first, MAX(a.nextruntime) AS last
                  FROM {local_catquizlab_attempt} a
                 WHERE a.status = :queued AND a.nextruntime > :now AND a.tries > 0';
        $params = ['queued' => self::STATUS_QUEUED, 'now' => time()];
        if ($experimentid > 0) {
            $sql .= ' AND a.runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = :experimentid)';
            $params['experimentid'] = $experimentid;
        }
        $row = $DB->get_record_sql($sql, $params);

        return ['n' => (int) ($row->n ?? 0), 'first' => (int) ($row->first ?? 0), 'last' => (int) ($row->last ?? 0)];
    }

    /**
     * Apply the retry decision to one attempt.
     *
     * @param int $attemptid The attempt.
     * @param int $tries The current try count.
     * @param int $now The current time.
     * @param string $why Why the sitting is retried or given up, for the history.
     * @return int The resulting status.
     */
    protected static function apply_retry(int $attemptid, int $tries, int $now, string $why = 'failed'): int {
        global $DB;

        $status = self::retry_status($tries);
        $update = (object) [
            'id'           => $attemptid,
            'status'       => $status,
            'timemodified' => $now,
        ];
        $spread = 0;
        if ($status === self::STATUS_QUEUED) {
            // Scattered over up to retry_spread seconds (#120): sittings that
            // failed in the same minute used to be retried in the same minute,
            // the whole cohort started together, and that start overheated the
            // server again — the waves of 02.10. at 09:47 and 10:03.
            $spread = self::retry_spread();
            $update->nextruntime = $now + self::RETRY_BACKOFF * max(1, $tries) + $spread;
        }
        $DB->update_record('local_catquizlab_attempt', $update);

        // Every retry decision is recorded (#90): requeued — after which
        // backoff, when — or given up, and why. The failure itself is recorded
        // where it was reported; this is what was decided about it.
        if ($status === self::STATUS_QUEUED) {
            attempt_history::record($attemptid, attempt_history::REQUEUED, [
                'tryno'  => $tries,
                'detail' => get_string('history:retry', 'local_catquizlab', (object) [
                    'why' => $why, 'after' => (int) $update->nextruntime - $now,
                    'backoff' => self::RETRY_BACKOFF * max(1, $tries), 'spread' => $spread,
                    'at' => date('c', (int) $update->nextruntime),
                ]),
            ]);
        } else if ($status === self::STATUS_FAILED) {
            attempt_history::record($attemptid, attempt_history::ABANDONED, [
                'tryno'  => $tries,
                'detail' => get_string('history:notriesleft', 'local_catquizlab', (object) ['why' => $why, 'tries' => $tries]),
            ]);
        }

        // A sitting that has given up is the moment to ask whether this run is
        // failing the same way over and over. Asking later — on a page load, in
        // a scheduled task — means the pool spends minutes more on sittings
        // that will fail identically.
        if ($status === self::STATUS_FAILED) {
            $runid = (int) $DB->get_field('local_catquizlab_attempt', 'runid', ['id' => $attemptid]);
            if ($runid > 0) {
                circuit_breaker::check($runid);
            }
        }

        return $status;
    }

    /**
     * Create the queued attempts for a run.
     *
     * @param int $runid The run to schedule.
     * @return int The number of attempts newly created.
     */
    public static function schedule(int $runid): int {

        global $DB;

        $now = time();
        // Not one sitting more than there are twins (#116, POP-003): a run whose
        // people are damaged is blocked, not given a sitting per extra row.
        $integrity = person_integrity::check_run($runid);
        if ($integrity['duplicates'] !== []) {
            run_log::record($runid, run_log::SETUP_SKIPPED, ['why' => person_integrity::REASON_DUPLICATE_TWINS,
                'duplicates' => count($integrity['duplicates'])]);
            throw new \moodle_exception('duplicatetwins', 'local_catquizlab', '', (object) [
                'run' => $runid, 'n' => count($integrity['duplicates']),
            ]);
        }
        $persons = $DB->get_records_select(
            'local_catquizlab_person',
            'runid = :runid AND moodleuserid IS NOT NULL',
            ['runid' => $runid],
            'id ASC'
        );

        // One read of the sittings that exist and one batched write of the missing ones: two queries per person
        // made 4000 of a 2000-person run's setup (incident 2026-11-09).
        $existing = array_flip(array_map('intval', $DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'personid',
            'runid = :runid',
            ['runid' => $runid]
        )));
        $rows = [];
        foreach ($persons as $person) {
            if (isset($existing[(int) $person->id])) {
                continue;
            }
            $rows[] = (object) [
                'runid'           => $runid,
                'personid'        => $person->id,
                'engineattemptid' => null,
                'status'          => self::STATUS_QUEUED,
                'tracejson'       => null,
                'tracebytes'      => null,
                'runtimems'       => null,
                'timecreated'     => $now,
                'timemodified'    => $now,
            ];
        }
        $DB->insert_records('local_catquizlab_attempt', $rows);
        $created = count($rows);

        if ($created > 0) {
            run_lifecycle::set_status($runid, registry::STATUS_SCHEDULED, 'sittings_scheduled');
        }

        return $created;
    }

    /**
     * Queue the ad-hoc task that schedules a run's attempts.
     *
     * @param int $runid The run to schedule.
     * @return void
     */
    public static function queue(int $runid): void {

        $task = new \local_catquizlab\task\schedule_attempts();
        $task->set_custom_data(['runid' => $runid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Collect a finished sitting's trace — or hold it as waiting for its trace (#138).
     *
     * "Collected" means: a trace is stored with it. Where the engine has nothing yet (its attempt was finished but
     * its data is not there, or not readable), the sitting is not collected, not valid, not finished: it waits,
     * lease released, for the next look (retry_pending_traces()). It is not played again: its engine attempt
     * exists, and a second sitting of the same person would be the double sitting of #134.
     *
     * Called with the sitting's lock held (sitting_lock). Either way one update writes the new status together with
     * the engine attempt and the caller's fields ($set): collected with its trace, or waiting for it.
     *
     * @param int $attemptid The sitting.
     * @param string $workerid Who reported it, for the record.
     * @param int $engineattemptid The engine attempt, when the caller has just learnt it; 0 for the stored one.
     * @param array $set Further fields of the sitting to write with the new status (such as the runtime).
     * @param string $source The path that collects, kept with the completion verdict (worker-report, late-report, …).
     * @return bool Whether it is collected now.
     */
    public static function collect_or_hold(
        int $attemptid,
        string $workerid = '',
        int $engineattemptid = 0,
        array $set = [],
        string $source = 'collector'
    ): bool {
        global $DB;

        attempt_collector::set_source($source);
        try {
            $trace = attempt_collector::collect($attemptid, $engineattemptid, $set);
        } finally {
            attempt_collector::set_source('collector');
        }
        if ($trace !== null) {
            return true;
        }
        // The verdict on how it ended is kept also when the trace is not (completion_contract): a time limit closed
        // it, the engine failed — said now, not only once a trace arrives.
        $completion = attempt_collector::last_completion();
        if ($completion !== null) {
            $set['completionjson'] = json_encode($completion, JSON_UNESCAPED_SLASHES);
        }
        $engine = $engineattemptid > 0 ? $engineattemptid
            : (int) $DB->get_field('local_catquizlab_attempt', 'engineattemptid', ['id' => $attemptid]);
        // What the trace lacked, by the contract's codes, then what the engine says about it (#138).
        $defects = attempt_collector::last_defects();
        $DB->update_record('local_catquizlab_attempt', (object) ([
            'id' => $attemptid,
            'status' => self::STATUS_TRACEPENDING,
            'leaseowner' => null,
            'leaseexpires' => 0,
            'nextruntime' => time() + self::TRACE_RETRY_DELAY,
            'lasterror' => \core_text::substr('trace_missing: ' . ($defects !== [] ? '[' . implode(',', $defects) . '] ' : '')
                . engine_validity::unmeasured_reason($engine), 0, 1000),
            'timemodified' => time(),
        ] + ($engine > 0 ? ['engineattemptid' => $engine] : []) + $set));

        return false;
    }

    /**
     * Look again for the traces that were missing; give up those that stay missing (#138).
     *
     * Called by the pipeline tick. A trace that arrives late is collected then; one that is still missing after
     * TRACE_RETRIES looks is a technical non-result: failed, with the cause, never counted as collected or valid.
     *
     * @param int|null $now The clock.
     * @param callable|null $collect The collection, attempt id => trace or null; the collector's by default (tests).
     * @return array{collected: int, waiting: int, givenup: int}
     */
    public static function retry_pending_traces(?int $now = null, ?callable $collect = null): array {
        global $DB;

        $now = $now ?? time();
        $collect = $collect ?? static function (int $id): ?array {
            attempt_collector::set_source('trace-pending');
            try {
                return attempt_collector::collect($id);
            } finally {
                attempt_collector::set_source('collector');
            }
        };
        $out = ['collected' => 0, 'waiting' => 0, 'givenup' => 0];
        $pending = $DB->get_records_select(
            'local_catquizlab_attempt',
            'status = :status AND nextruntime <= :now',
            ['status' => self::STATUS_TRACEPENDING, 'now' => $now],
            'id ASC',
            'id, runid, tries',
            0,
            200
        );
        foreach ($pending as $attempt) {
            // Under the sitting's lock, and only if it still waits (#138): a worker report, the lease recovery or a
            // run's re-collection may have collected it since the list was read. A sitting another process holds
            // now is left for the next tick, not counted as a look.
            [$had, $result] = sitting_lock::run((int) $attempt->id, 0, static function () use (
                $DB,
                $attempt,
                $collect,
                $now
            ): ?string {
                $status = $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $attempt->id]);
                if ((int) $status !== self::STATUS_TRACEPENDING) {
                    return null;
                }
                return self::look_for_trace($attempt, $collect, $now);
            });
            if ($had && $result !== null) {
                $out[$result]++;
                run_lifecycle::attempt_finished((int) $attempt->runid);
            }
        }

        return $out;
    }

    /**
     * One look for a sitting's missing trace, with its lock held: collected, still waiting, or given up.
     *
     * @param \stdClass $attempt The sitting (id, runid, tries).
     * @param callable $collect The collection, attempt id => trace or null.
     * @param int $now The clock.
     * @return string 'collected', 'waiting' or 'givenup'.
     */
    protected static function look_for_trace(\stdClass $attempt, callable $collect, int $now): string {
        global $DB;

        $looks = self::trace_looks((int) $attempt->id);
        attempt_collector::clear_defects();
        if ($collect((int) $attempt->id) !== null) {
            attempt_history::record((int) $attempt->id, attempt_history::COLLECTED, [
                'tryno' => (int) $attempt->tries,
                'detail' => get_string('history:tracearrived', 'local_catquizlab', $looks),
            ]);
            return 'collected';
        }
        // What the trace still lacks, by the contract's codes (#138); without codes the reason stays as it was.
        $defects = attempt_collector::last_defects();
        $lasterror = $defects !== [] ? ['lasterror' => 'trace_missing: [' . implode(',', $defects) . ']'] : [];
        if ($looks >= self::TRACE_RETRIES) {
            // Given up with everything needed to find it again (#138 AK4): the lab sitting, the engine attempt and what
            // the engine holds of it, the workers' correlation ids, what the trace lacked and how often it was looked
            // for. The lookup can be opened again (reopen_trace_lookup(), cli/recover_traces.php).
            $provenance = self::trace_provenance((int) $attempt->id, $looks + 1, $defects, $now);
            $DB->update_record('local_catquizlab_attempt', (object) (['id' => $attempt->id,
                'status' => self::STATUS_FAILED, 'timemodified' => $now] + $lasterror));
            attempt_history::record((int) $attempt->id, attempt_history::ABANDONED, [
                'tryno' => (int) $attempt->tries,
                'engineattemptid' => $provenance['engineattemptid'] ?: null,
                'detail' => get_string('history:tracegivenup', 'local_catquizlab', $looks),
                'extra' => ['trace' => $provenance],
            ]);
            return 'givenup';
        }
        $DB->update_record('local_catquizlab_attempt', (object) (['id' => $attempt->id,
            'nextruntime' => $now + self::TRACE_RETRY_DELAY, 'timemodified' => $now] + $lasterror));
        attempt_history::record((int) $attempt->id, attempt_history::TRACE_PENDING, [
            'tryno' => (int) $attempt->tries,
            'detail' => get_string('history:tracestillmissing', 'local_catquizlab', (object) [
                'look' => $looks + 1, 'of' => self::TRACE_RETRIES,
            ]),
        ]);
        return 'waiting';
    }

    /**
     * How often a sitting's missing trace has been looked for since its lookup was last opened (#138).
     *
     * @param int $attemptid The sitting.
     * @return int
     */
    protected static function trace_looks(int $attemptid): int {
        global $DB;

        $reopened = (int) $DB->get_field_sql(
            'SELECT MAX(id) FROM {local_catquizlab_attemptlog} WHERE attemptid = ? AND outcome = ?',
            [$attemptid, attempt_history::REQUEUED]
        );

        return $DB->count_records_select(
            'local_catquizlab_attemptlog',
            'attemptid = ? AND outcome = ? AND id > ?',
            [$attemptid, attempt_history::TRACE_PENDING, $reopened]
        );
    }

    /**
     * Where a sitting whose trace never came can be found, and what was found of it (#138 AK4).
     *
     * @param int $attemptid The sitting.
     * @param int $looks How often its trace was looked for.
     * @param string[] $defects What the trace lacked at the last look, by the contract's codes.
     * @param int $now The clock.
     * @return array labattemptid, runid, personid, engineattemptid, engine (the host's attempt: found, state,
     *     questionsattempted, questionusage), lookup (what the engine says about the sitting), defects, looks,
     *     correlationids (the executions' ids, oldest first), firstmissing, givenup.
     */
    public static function trace_provenance(int $attemptid, int $looks, array $defects, int $now): array {
        global $DB;

        $sitting = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid], 'id, runid, personid, engineattemptid');
        $engineid = (int) ($sitting->engineattemptid ?? 0);
        $engine = ['found' => false];
        if ($engineid > 0 && $DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $row = $DB->get_record('adaptivequiz_attempt', ['id' => $engineid], 'id, attemptstate, questionsattempted, uniqueid');
            if ($row) {
                $engine = ['found' => true, 'state' => (string) $row->attemptstate,
                    'questionsattempted' => (int) $row->questionsattempted, 'questionusage' => (int) $row->uniqueid];
            }
        }
        $history = $DB->get_records(
            'local_catquizlab_attemptlog',
            ['attemptid' => $attemptid],
            'id ASC',
            'id, outcome, correlationid, timecreated'
        );
        $correlations = [];
        $firstmissing = null;
        foreach ($history as $row) {
            if ((string) $row->correlationid !== '' && !in_array($row->correlationid, $correlations, true)) {
                $correlations[] = (string) $row->correlationid;
            }
            if ($row->outcome === attempt_history::TRACE_PENDING && $firstmissing === null) {
                $firstmissing = (int) $row->timecreated;
            }
        }

        return [
            'labattemptid' => $attemptid,
            'runid' => (int) ($sitting->runid ?? 0),
            'personid' => (int) ($sitting->personid ?? 0),
            'engineattemptid' => $engineid,
            'engine' => $engine,
            'lookup' => $engineid > 0 ? engine_validity::unmeasured_reason($engineid) : 'noengineattempt',
            'defects' => array_values($defects),
            'looks' => $looks,
            'correlationids' => array_slice($correlations, -10),
            'firstmissing' => $firstmissing,
            'givenup' => $now,
        ];
    }

    /**
     * Open the lookup of a sitting whose trace was given up again (#138 AK4): it waits for its trace once more, with
     * the full number of looks, and the next look is due now. Only a sitting given up for a missing trace; one that
     * failed otherwise is played again with run_lifecycle::requeue_failed() instead.
     *
     * @param int $attemptid The sitting.
     * @param string $by Who opened it, for the history.
     * @return bool Whether it waits for its trace now.
     */
    public static function reopen_trace_lookup(int $attemptid, string $by = ''): bool {
        global $DB;

        [$had, $reopened] = sitting_lock::run($attemptid, sitting_lock::COLLECT_WAIT, static function () use (
            $DB,
            $attemptid,
            $by
        ): bool {
            $row = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid], 'id, status, lasterror, tries');
            if (!$row || (int) $row->status !== self::STATUS_FAILED || !self::gave_up_trace($attemptid)) {
                return false;
            }
            $now = time();
            $DB->update_record('local_catquizlab_attempt', (object) ['id' => $attemptid,
                'status' => self::STATUS_TRACEPENDING, 'nextruntime' => $now, 'leaseowner' => null, 'leaseexpires' => 0,
                'timemodified' => $now]);
            attempt_history::record($attemptid, attempt_history::REQUEUED, [
                'tryno' => (int) $row->tries,
                'detail' => get_string('history:tracereopened', 'local_catquizlab', $by !== '' ? $by : '-'),
            ]);
            return true;
        });

        return $had && $reopened;
    }

    /**
     * Whether a sitting's last end was giving up its missing trace.
     *
     * @param int $attemptid The sitting.
     * @return bool
     */
    public static function gave_up_trace(int $attemptid): bool {
        global $DB;

        $last = $DB->get_records(
            'local_catquizlab_attemptlog',
            ['attemptid' => $attemptid],
            'id DESC',
            'id, outcome, diagnosis',
            0,
            1
        );
        $last = reset($last);

        return $last && $last->outcome === attempt_history::ABANDONED
            && isset(json_decode((string) $last->diagnosis, true)['trace']);
    }

    /**
     * The sittings of a run whose trace was given up, each with its provenance as recorded then.
     *
     * @param int $runid The run.
     * @return array[] Sitting id => provenance (see trace_provenance()).
     */
    public static function given_up_traces(int $runid): array {
        global $DB;

        $out = [];
        $failed = $DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'id',
            'runid = ? AND status = ?',
            [$runid, self::STATUS_FAILED]
        );
        foreach ($failed as $id) {
            if (!self::gave_up_trace((int) $id)) {
                continue;
            }
            $last = $DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $id], 'id DESC', 'id, diagnosis', 0, 1);
            $out[(int) $id] = json_decode((string) reset($last)->diagnosis, true)['trace'];
        }

        return $out;
    }
}
