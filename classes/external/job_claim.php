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
 * External function: a worker claims the next queued attempt job.
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

/**
 * Hands the calling worker the next attempt to play, if any.
 *
 * Used by the queue-polling worker variant (E3.2), where timed ad-hoc tasks
 * enqueue attempt jobs and workers on a separate host claim them over the web
 * service. The direct-process-start variant does not need this endpoint.
 *
 * One claim at a time, site-wide, and only a sitting of the experiment that
 * holds the execution line (#163, see execution_queue).
 */
class job_claim extends external_api {
    /** @var int Seconds a claim waits for another claim to finish. */
    public const LOCK_TIMEOUT = 10;

    /**
     * @var int The worker contract this server speaks (#173): a worker that does not send it, or sends an older one,
     *     is given no sitting. It would play without binding its engine attempt and count from an unverified start.
     */
    public const WORKER_CONTRACT = 1;

    /** @var int Seconds a worker is asked to wait while the holder's last sittings are in play (#163). */
    public const IN_PLAY_WAIT = 20;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'workerid' => new external_value(PARAM_ALPHANUMEXT, 'Stable identifier of the calling worker.'),
            'contract' => new external_value(
                PARAM_INT,
                'The worker contract the caller speaks (#173); a worker without one is given nothing.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Claim the next queued attempt job.
     *
     * @param string $workerid Stable identifier of the calling worker.
     * @param int $contract The worker contract the caller speaks; this server's own when called from PHP.
     * @return array The claim result.
     */
    public static function execute(string $workerid, int $contract = self::WORKER_CONTRACT): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'workerid' => $workerid,
            'contract' => $contract,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        $none = [
            'hasjob'    => false,
            'runid'     => 0,
            'attemptid' => 0,
            'quizcmid'  => 0,
            'userid'    => 0,
            'username'  => '',
            'experimentid' => 0,
            'execution' => 0,
            'correlationid' => '',
            'message'   => get_string('job:none', 'local_catquizlab'),
        ];

        // A worker of an older contract gets nothing (#173): it would answer from a starting count it never checked
        // and into whichever engine attempt it found. Said, not guessed around.
        if ((int) $params['contract'] < self::WORKER_CONTRACT) {
            return ['message' => get_string('job:outdatedworker', 'local_catquizlab', self::WORKER_CONTRACT)] + $none;
        }

        // A worker asked to stop, or in a slot above the limit in force, gets
        // nothing new (#124): it finishes what it plays and goes.
        if (!\local_catquizlab\local\worker_capacity::may_claim((string) $params['workerid'])) {
            return ['message' => get_string('job:draining', 'local_catquizlab')] + $none;
        }

        // One claim at a time, site-wide (#135 re-audit, 17.10.2026). The claim
        // reads the oldest queued sitting and then marks it running; a database
        // transaction does not make that pair atomic. Under PostgreSQL's (and
        // MariaDB's) default isolation two workers read the same queued row and
        // both mark it. A burst of eight workers handed 62 sittings out 200
        // times; in the 300-sitting load test two executions played attempt 71
        // at once, and Moodle refused the second's answers as out of sequence
        // (HTTP 404). The lock is held until the claim is committed, so the next
        // claimer reads it as running. A claim takes milliseconds; a worker that
        // waits longer than the timeout is told so and asks again.
        $factory = \core\lock\lock_config::get_lock_factory('local_catquizlab_claim');
        $lock = $factory->get_lock('claim', self::LOCK_TIMEOUT);
        if (!$lock) {
            // Not an empty queue: the worker asks again instead of stopping.
            return ['message' => get_string('job:busy', 'local_catquizlab'), 'retry' => true] + $none;
        }
        try {
            return self::claim_next($params, $none);
        } finally {
            $lock->release();
        }
    }

    /**
     * Hand out the next sitting of the experiment holding the line; called with the claim lock held.
     *
     * Bound to the line (#163). Until 0.7.65 this read every ready run of the installation, oldest due time first: the
     * line said one experiment ran while workers played another's sittings, and sittings never started (due time 0)
     * went before any retry. Now only the holder's sittings are handed out (execution_queue::holder()), and among
     * them by their place: the order they were queued in (timecreated, then id). The due time decides only from when a
     * sitting may be handed out: a retry keeps its place and is taken as soon as its backoff is over, and nothing
     * queued later goes before it.
     *
     * @param array $params The validated parameters.
     * @param array $none The answer when there is nothing to hand out.
     * @return array The claim result.
     */
    protected static function claim_next(array $params, array $none): array {
        global $DB;

        // Nobody holds the line, or its holder has nothing left to hand out: the line moves now, not at the next tick.
        $holder = \local_catquizlab\local\execution_queue::holder();
        $attempt = $holder > 0 ? self::next_of($holder) : null;
        if ($attempt === null) {
            $holder = \local_catquizlab\local\execution_queue::take_turn();
            $attempt = $holder > 0 ? self::next_of($holder) : null;
        }

        $transaction = $DB->start_delegated_transaction();
        // Read again inside the transaction: the sitting is still queued (a stop or a reclaim may have closed it).
        if ($attempt !== null) {
            $attempt = $DB->get_record(
                'local_catquizlab_attempt',
                ['id' => $attempt->id, 'status' => attempt_scheduler::STATUS_QUEUED]
            ) ?: null;
        }
        if (!$attempt) {
            $transaction->allow_commit();

            return ['nextdue' => self::next_due($holder)] + $none;
        }

        $DB->update_record('local_catquizlab_attempt', (object) [
            'id'           => $attempt->id,
            'status'       => attempt_scheduler::STATUS_RUNNING,
            // The claim names its holder and says when it lapses. An
            // unattributed claim can only be recovered by a timeout that
            // guesses whether the worker is slow or gone.
            'leaseowner'   => $params['workerid'],
            'leaseexpires' => time() + \local_catquizlab\local\worker_registry::HEARTBEAT_TIMEOUT,
            'tries'        => (int) $attempt->tries + 1,
            'timemodified' => time(),
        ]);

        // The execution starts here, and the history says so before anything
        // can go wrong with it.
        \local_catquizlab\local\attempt_history::record(
            (int) $attempt->id,
            \local_catquizlab\local\attempt_history::STARTED,
            ['workerid' => $params['workerid'], 'tryno' => (int) $attempt->tries + 1]
        );

        // The documented lifecycle is READY → first attempt claimed → RUNNING,
        // and this is the moment it happens. Inside the transaction with the
        // claim: a run whose attempt is being played must not be able to look
        // READY to anything that reads it in between, and a claim that is
        // rolled back must not leave the run marked as running.
        \local_catquizlab\local\run_lifecycle::attempt_claimed((int) $attempt->runid);

        // Claiming work is a sign of life, so the registry hears about it at
        // the same time rather than waiting for the worker's own heartbeat.
        \local_catquizlab\local\worker_registry::heartbeat($params['workerid']);

        $run = $DB->get_record('local_catquizlab_run', ['id' => $attempt->runid]);
        $userid = (int) $DB->get_field('local_catquizlab_person', 'moodleuserid', ['id' => $attempt->personid]);
        // The username travels with the job. The worker used to derive it as
        // catlab_user_<id>, but the provisioner makes usernames unique per run
        // and produces names like catlab_r9_p-conforming-0001 — so the worker
        // could never have logged in, and nothing noticed because no worker had
        // tried against a real installation.
        $username = $userid > 0
            ? (string) $DB->get_field('user', 'username', ['id' => $userid])
            : '';

        $transaction->allow_commit();
        // The most answers a sitting of this run can hold (#172): the run's pool, or unknown.
        $budget = \local_catquizlab\local\sitting_budget::for_run((int) $attempt->runid);

        return [
            'hasjob'    => true,
            'runid'     => (int) $attempt->runid,
            'attemptid' => (int) $attempt->id,
            'quizcmid'  => $run ? (int) $run->testcmid : 0,
            'userid'    => $userid,
            'username'  => $username,
            // Where this execution's artefacts belong (#107): experiment, run,
            // attempt and which try this is.
            'experimentid' => $run ? (int) $run->experimentid : 0,
            'execution' => (int) $attempt->tries + 1,
            // The id the start of this execution was recorded under: the worker
            // carries it in its artefacts and sends it with every request of the
            // sitting, so that browser, server and history meet (#107, #110).
            'correlationid' => \local_catquizlab\local\debug_trace::correlation_id(),
            'budget' => (int) $budget['limit'],
            'budgetkind' => (string) $budget['kind'],
            'expectedlength' => (int) $budget['expected'],
            'contract' => self::WORKER_CONTRACT,
            'message'   => get_string('job:claimed', 'local_catquizlab'),
        ];
    }

    /**
     * The next sitting of an experiment a worker may take now, or null (#163).
     *
     * The experiment's runs that hand out work are read first, and the query asks only for their sittings. Fifty
     * candidates of the whole installation, of which the unusable ones were skipped afterwards, found nothing as soon
     * as fifty sittings of paused runs came first; a run found unusable here is left out of the next page instead,
     * so the search ends with a sitting or with every run looked at.
     *
     * And never a person who is already sitting the test somewhere else. Two workers logging in as the same simulated
     * person open the same adaptive quiz attempt, and Moodle rejects the second one's answers:
     * "adaptivequiz/uniquenotpartofattempt". A person sits the test once at a time, which is also what the experiment
     * assumes.
     *
     * @param int $experimentid The experiment holding the line.
     * @return \stdClass|null The sitting.
     */
    protected static function next_of(int $experimentid): ?\stdClass {
        global $DB;

        $runids = array_map('intval', $DB->get_fieldset_select(
            'local_catquizlab_run',
            'id',
            'experimentid = :experimentid AND status IN (:ready, :running)',
            ['experimentid' => $experimentid, 'ready' => \local_catquizlab\local\registry::STATUS_READY,
                'running' => \local_catquizlab\local\registry::STATUS_RUNNING]
        ));
        $usable = [];
        while ($runids !== []) {
            [$insql, $inparams] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'run');
            $now = time();
            $queued = $DB->get_records_select(
                'local_catquizlab_attempt',
                "status = :status AND nextruntime <= :now AND runid {$insql}
                   AND personid NOT IN (
                       SELECT DISTINCT busy.personid
                         FROM {local_catquizlab_attempt} busy
                        WHERE busy.status = :inflight
                          AND busy.leaseexpires > :leasenow
                          AND busy.personid > 0
                   )",
                $inparams + [
                    'status'   => attempt_scheduler::STATUS_QUEUED,
                    'now'      => $now,
                    'inflight' => attempt_scheduler::STATUS_RUNNING,
                    'leasenow' => $now,
                ],
                'timecreated ASC, id ASC',
                '*',
                0,
                50
            );
            if ($queued === []) {
                return null;
            }
            foreach ($queued as $candidate) {
                $runid = (int) $candidate->runid;
                if (!isset($usable[$runid])) {
                    $usable[$runid] = self::hands_out_work($runid);
                }
                if ($usable[$runid]) {
                    return $candidate;
                }
            }
            // Every candidate of this page belonged to a run that hands out nothing: the next page without those runs.
            $runids = array_values(array_filter($runids, static fn(int $runid): bool => $usable[$runid] ?? true));
        }

        return null;
    }

    /**
     * Whether a run may hand out a sitting now; a run with a twin more than once is failed on the way (#117).
     *
     * @param int $runid The run.
     * @return bool
     */
    protected static function hands_out_work(int $runid): bool {
        // Two conditions, and the run status is the one that matters most: a failed or cancelled run must never hand
        // out work again, however its attempts got into the queue. A pause can change at any moment, so it is
        // checked here and not taken from the run list.
        if (\local_catquizlab\local\run_lifecycle::is_paused($runid)) {
            return false;
        }
        // A run with a twin more than once is not executed (#117): its results would count people twice. It is
        // failed, with the reason, rather than left to hand out nothing — which would look like a run that hangs.
        if (\local_catquizlab\local\person_integrity::has_duplicates($runid)) {
            \local_catquizlab\local\run_lifecycle::fail(
                $runid,
                get_string('integrity:runblocked', 'local_catquizlab', $runid)
            );

            return false;
        }

        return \local_catquizlab\local\run_lifecycle::is_runnable($runid);
    }

    /**
     * Seconds until the next queued sitting of the line's holder may be claimed; 0 when there is none.
     *
     * An empty claim is not always an empty queue: a sitting whose execution failed waits out its retry's backoff,
     * and a person busy in another sitting is passed over for now. A worker told how long that is waits for it instead
     * of leaving (audit 11.11.2026: in the load matrix one of twenty sittings sat out the whole time limit in a backoff
     * after every worker had left the queue as empty). Only the holder's sittings count (#163): another experiment's
     * retry is not work this worker may take, and waiting for it would keep the worker for nothing.
     *
     * @param int $holder The experiment holding the line, 0 for none.
     * @return int Seconds, at least 1 where something is queued.
     */
    protected static function next_due(int $holder): int {
        global $DB;

        if ($holder <= 0) {
            return 0;
        }
        // Its runs that will hand out work once a sitting is due: not a paused one, whose sittings would have a worker
        // asking every second until somebody resumes it.
        $runids = array_values(array_filter(
            array_map('intval', $DB->get_fieldset_select(
                'local_catquizlab_run',
                'id',
                'experimentid = :experimentid AND status IN (:ready, :running)',
                ['experimentid' => $holder, 'ready' => \local_catquizlab\local\registry::STATUS_READY,
                    'running' => \local_catquizlab\local\registry::STATUS_RUNNING]
            )),
            static fn(int $runid): bool => !\local_catquizlab\local\run_lifecycle::is_paused($runid)
        ));
        if ($runids === []) {
            return 0;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'run');
        $next = $DB->get_field_sql(
            "SELECT MIN(nextruntime) FROM {local_catquizlab_attempt} WHERE status = :status AND runid {$insql}",
            $inparams + ['status' => attempt_scheduler::STATUS_QUEUED]
        );
        if ($next === null || $next === false) {
            // Nothing queued, but sittings in play (#163): when they are done the line moves on to the next experiment,
            // and a worker that asks again then is handed its sittings instead of having left.
            $inplay = $DB->record_exists_select(
                'local_catquizlab_attempt',
                "status = :running AND runid {$insql}",
                $inparams + ['running' => attempt_scheduler::STATUS_RUNNING]
            );

            return $inplay ? self::IN_PLAY_WAIT : 0;
        }

        return max(1, (int) $next - time());
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hasjob'    => new external_value(PARAM_BOOL, 'True when a job was handed out.'),
            'runid'     => new external_value(PARAM_INT, 'Lab run id of the claimed job (0 when none).'),
            'attemptid' => new external_value(PARAM_INT, 'Lab attempt id to play (0 when none).'),
            'quizcmid'  => new external_value(PARAM_INT, 'Course-module id of the adaptivequiz to sit (0 when none).'),
            'userid'    => new external_value(PARAM_INT, 'Simulated user id to log in as (0 when none).'),
            'username'  => new external_value(
                PARAM_USERNAME,
                'Username to log in as. Supplied by the server because the provisioner chooses it.',
                VALUE_OPTIONAL,
                ''
            ),
            'experimentid' => new external_value(PARAM_INT, 'Experiment of the claimed run (0 when none).', VALUE_OPTIONAL, 0),
            'correlationid' => new external_value(
                PARAM_ALPHANUMEXT,
                'Correlation id of this execution, as recorded in its history.',
                VALUE_OPTIONAL,
                ''
            ),
            'retry' => new external_value(
                PARAM_BOOL,
                'True when no job was handed out because another claim held the queue: ask again, the queue is not empty.',
                VALUE_OPTIONAL,
                false
            ),
            'execution'    => new external_value(PARAM_INT, 'Which try of the sitting this is (0 when none).', VALUE_OPTIONAL, 0),
            'nextdue' => new external_value(
                PARAM_INT,
                'Without a job: seconds until the next queued sitting may be claimed (a retry\'s backoff, a busy person); '
                    . '0 when nothing is queued.',
                VALUE_OPTIONAL,
                0
            ),
            'budget' => new external_value(
                PARAM_INT,
                'The most answers a sitting of this run can hold: its pool (#172); 0 when unknown.',
                VALUE_OPTIONAL,
                0
            ),
            'budgetkind' => new external_value(PARAM_ALPHA, 'pool or unknown (#172).', VALUE_OPTIONAL, 'unknown'),
            'expectedlength' => new external_value(
                PARAM_INT,
                'The length the design fixes or caps (the classical form, a maximum); 0 when it does not (#172).',
                VALUE_OPTIONAL,
                0
            ),
            'contract' => new external_value(PARAM_INT, 'The worker contract of this server (#173).', VALUE_OPTIONAL, 0),
            'message'   => new external_value(PARAM_TEXT, 'Human-readable status.'),
        ]);
    }
}
