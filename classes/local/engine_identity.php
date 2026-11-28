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
 * Which engine attempt and question usage belong to a sitting (#173).
 *
 * A sitting (local_catquizlab_attempt) is one simulated person sitting the run's test once. On the server that is one
 * activity attempt (adaptivequiz_attempt) of the person's account on the run's activity, and one question usage (its
 * uniqueid). Until 0.7.83 every reader took the newest activity attempt of that account on that activity: the worker's
 * starting count, the reconciliation of an uncertain submission, the engine attempt of a finished report and the
 * resumption of a stopped experiment. Whichever attempt was newest was taken as the sitting's, so a second attempt
 * would have been read, counted and collected without a word.
 *
 * The identity is now bound: the first execution that reaches a question binds the activity attempt behind the
 * usage the browser shows (sitting.engineattemptid), under the sitting's lock and only as the lease holder. From then
 * on every reader goes by the binding; a usage or attempt that does not match it is refused, not chosen. A sitting not
 * bound yet (one played by 0.7.83 or earlier, or none of whose executions reached a question) is resolved only when
 * exactly one activity attempt of the account exists on the activity; two or more are ambiguous and refused.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_identity {
    /** @var string The sitting does not exist. */
    public const SITTING_NOT_FOUND = 'sitting-not-found';

    /** @var string The sitting has no person account or its run no activity yet. */
    public const SITTING_INCOMPLETE = 'sitting-incomplete';

    /** @var string The run's activity is gone. */
    public const NO_TEST_INSTANCE = 'no-test-instance';

    /** @var string No activity attempt of the person's account on the activity: nothing started yet. */
    public const NO_ENGINE_ATTEMPT = 'no-engine-attempt';

    /** @var string Not bound, and more than one activity attempt of the account: which is the sitting's is not known. */
    public const AMBIGUOUS = 'ambiguous-engine-attempt';

    /** @var string The bound activity attempt is gone, or belongs to another account or activity. */
    public const IDENTITY_MISMATCH = 'identity-mismatch';

    /** @var string The question usage asked about is not the bound attempt's. */
    public const QUBA_MISMATCH = 'quba-mismatch';

    /** @var string The question usage shown belongs to no activity attempt of this account on this activity. */
    public const FOREIGN_USAGE = 'foreign-usage';

    /** @var string The caller does not hold the sitting's lease: it may not bind. */
    public const NOT_LEASE_HOLDER = 'not-lease-holder';

    /** @var string The engine's count is not a non-negative whole number. */
    public const INVALID_COUNT = 'invalid-count';

    /** @var string The engine attempt was taken out of the sitting by an explicit recovery decision (generations). */
    public const RETIRED = 'retired-engine-attempt';

    /** @var string A finished report, and the sitting's engine attempt is not complete on the server. */
    public const NOT_COMPLETE = 'engine-not-complete';

    /**
     * The sitting's account and the run's activity instance.
     *
     * @param \stdClass $sitting The local_catquizlab_attempt row.
     * @return array{0: int, 1: int, 2: string} User id, activity instance, and why not ('' when both are known).
     */
    public static function owner(\stdClass $sitting): array {
        global $DB;

        $userid = (int) $DB->get_field('local_catquizlab_person', 'moodleuserid', ['id' => (int) $sitting->personid]);
        $testcmid = (int) $DB->get_field('local_catquizlab_run', 'testcmid', ['id' => (int) $sitting->runid]);
        if ($userid <= 0 || $testcmid <= 0) {
            return [0, 0, self::SITTING_INCOMPLETE];
        }
        $instance = (int) $DB->get_field('course_modules', 'instance', ['id' => $testcmid]);
        if ($instance <= 0) {
            return [$userid, 0, self::NO_TEST_INSTANCE];
        }

        return [$userid, $instance, ''];
    }

    /**
     * The sitting's engine attempt as it stands on the server, without choosing among candidates.
     *
     * @param int $sittingid The sitting.
     * @param int $qubaid The question usage the caller sees, or 0 when it has none to compare.
     * @return array found, reason, engineattemptid, qubaid, bound, state, questionsattempted, slots, timemodified.
     */
    public static function resolve(int $sittingid, int $qubaid = 0): array {
        global $DB;

        $sitting = $DB->get_record('local_catquizlab_attempt', ['id' => $sittingid], 'id, runid, personid, engineattemptid');
        if (!$sitting) {
            return self::none(self::SITTING_NOT_FOUND);
        }
        // Without the activity module there is no activity attempt to find.
        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            return self::none(self::NO_ENGINE_ATTEMPT);
        }
        [$userid, $instance, $why] = self::owner($sitting);
        if ($why !== '') {
            return self::none($why);
        }
        $bound = (int) ($sitting->engineattemptid ?? 0);
        if ($bound > 0) {
            $attempt = $DB->get_record(
                'adaptivequiz_attempt',
                ['id' => $bound],
                'id, userid, instance, uniqueid, attemptstate, questionsattempted, timemodified'
            );
            if (!$attempt || (int) $attempt->userid !== $userid || (int) $attempt->instance !== $instance) {
                return self::none(self::IDENTITY_MISMATCH, $bound);
            }
            $answer = self::describe($attempt, true);
        } else {
            // Attempts an explicit decision retired from the sitting are no candidates (a new generation, #173).
            [$notretired, $params] = self::not_retired($sittingid);
            $candidates = $DB->get_records_select(
                'adaptivequiz_attempt',
                'instance = :instance AND userid = :userid' . $notretired,
                ['instance' => $instance, 'userid' => $userid] + $params,
                'id ASC',
                'id, userid, instance, uniqueid, attemptstate, questionsattempted, timemodified',
                0,
                2
            );
            if ($candidates === []) {
                return self::none(self::NO_ENGINE_ATTEMPT);
            }
            if (count($candidates) > 1) {
                return self::none(self::AMBIGUOUS);
            }
            $answer = self::describe(reset($candidates), false);
        }
        if ($answer['found'] && $qubaid > 0 && $qubaid !== $answer['qubaid']) {
            return ['qubaid' => $answer['qubaid']] + self::none(self::QUBA_MISMATCH, $answer['engineattemptid']);
        }

        return $answer;
    }

    /**
     * Bind the activity attempt behind the usage the browser shows to the sitting, or confirm the binding (#173).
     *
     * Call with the sitting's lock held. Only the execution holding the lease may bind (lease_fence: this worker, this
     * execution, a lease that has not run out); a usage that is not an attempt of the sitting's account on the run's
     * activity, a binding to another attempt, or a second attempt of the account beside the one shown is refused and
     * changes nothing.
     *
     * @param int $sittingid The sitting.
     * @param int $qubaid The question usage the browser shows.
     * @param string $workerid The calling worker.
     * @param int $execution The execution's identity (the claim's "executionid").
     * @return array As resolve(), plus 'newly' (true when this call made the binding) and 'fence' (why the caller does
     *     not hold the lease, '' when it does).
     */
    public static function bind(int $sittingid, int $qubaid, string $workerid, int $execution): array {
        global $DB;

        $sitting = $DB->get_record(
            'local_catquizlab_attempt',
            ['id' => $sittingid],
            'id, runid, personid, engineattemptid, status, leaseowner, executionid, leaseexpires'
        );
        if (!$sitting) {
            return self::none(self::SITTING_NOT_FOUND) + ['newly' => false, 'fence' => ''];
        }
        $fence = lease_fence::check($sitting, $workerid, $execution);
        if ($fence !== lease_fence::HOLDS) {
            return self::none(self::NOT_LEASE_HOLDER) + ['newly' => false, 'fence' => $fence];
        }
        [$userid, $instance, $why] = self::owner($sitting);
        if ($why !== '') {
            return self::none($why) + ['newly' => false, 'fence' => ''];
        }
        $shown = $qubaid > 0 ? $DB->get_record(
            'adaptivequiz_attempt',
            ['uniqueid' => $qubaid],
            'id, userid, instance, uniqueid, attemptstate, questionsattempted, timemodified'
        ) : false;
        if (!$shown || (int) $shown->userid !== $userid || (int) $shown->instance !== $instance) {
            return self::none(self::FOREIGN_USAGE) + ['newly' => false, 'fence' => ''];
        }
        if (in_array((int) $shown->id, self::retired($sittingid), true)) {
            return self::none(self::RETIRED, (int) $shown->id) + ['newly' => false, 'fence' => ''];
        }
        $bound = (int) ($sitting->engineattemptid ?? 0);
        if ($bound > 0) {
            if ($bound !== (int) $shown->id) {
                return self::none(self::IDENTITY_MISMATCH, $bound) + ['newly' => false, 'fence' => ''];
            }

            return self::describe($shown, true) + ['newly' => false, 'fence' => ''];
        }
        // Not bound yet: the attempt shown is the sitting's only if it is the account's only one on the activity —
        // apart from those an explicit decision retired from the sitting.
        [$notretired, $params] = self::not_retired($sittingid);
        $others = $DB->count_records_select(
            'adaptivequiz_attempt',
            'instance = :instance AND userid = :userid AND id <> :shown' . $notretired,
            ['instance' => $instance, 'userid' => $userid, 'shown' => (int) $shown->id] + $params
        );
        if ($others > 0) {
            return self::none(self::AMBIGUOUS) + ['newly' => false, 'fence' => ''];
        }
        $DB->set_field('local_catquizlab_attempt', 'engineattemptid', (int) $shown->id, ['id' => $sittingid]);

        return self::describe($shown, true) + ['newly' => true, 'fence' => ''];
    }

    /**
     * The engine attempt a finished report may be collected from (#173): the sitting's own, and complete.
     *
     * Fail-closed (re-audit 27.11.2026). Until 0.7.84 a sitting without an account or an activity, or an installation
     * without the activity module, had its reported attempt collected as reported, unchecked. A sitting whose owner,
     * activity or attempt cannot be established is now not collected: it is held with the reason. Collected is only
     * an attempt that is the sitting's (bound, or the account's only one on the activity), is the one the report
     * names when it names one, and is complete on the server — the worker's word that it saw the finish page does
     * not stand in for the engine's own state. Whether the trace is whole the collector decides (#138).
     *
     * @param int $sittingid The sitting.
     * @param int $reported The engine attempt the worker reported, 0 when it had none.
     * @return array{id: int, reason: string} The attempt to collect, or 0 and why not.
     */
    public static function for_collection(int $sittingid, int $reported): array {
        $state = self::resolve($sittingid);
        if (!$state['found']) {
            return ['id' => 0, 'reason' => (string) $state['reason']];
        }
        if ($reported > 0 && $reported !== (int) $state['engineattemptid']) {
            return ['id' => 0, 'reason' => self::IDENTITY_MISMATCH];
        }
        if ($state['state'] !== 'complete') {
            return ['id' => 0, 'reason' => self::NOT_COMPLETE];
        }

        return ['id' => (int) $state['engineattemptid'], 'reason' => ''];
    }

    /**
     * The sitting's engine attempt as the server reads it now, for the history (#176 AK02, re-audit 27.11.2026).
     *
     * The server's own numbers, read by the server at the moment it decides — never the worker's count: engine
     * attempt, question usage, whether it is bound, its state, the count the engine stored, the slots presented, when
     * and on which path it was read.
     *
     * @param int $sittingid The sitting.
     * @param string $source The path that read it (job_complete, sitting_bind, lease-recovery, fenced-report-recovery).
     * @return array
     */
    public static function snapshot(int $sittingid, string $source): array {
        $state = self::resolve($sittingid);
        $now = microtime(true);

        return [
            'at' => gmdate('Y-m-d\TH:i:s', (int) $now) . sprintf('.%03dZ', (int) (($now - floor($now)) * 1000)),
            'source' => $source,
            'found' => (bool) $state['found'],
            'reason' => (string) $state['reason'],
            'engineattemptid' => (int) $state['engineattemptid'],
            'qubaid' => (int) $state['qubaid'],
            'bound' => (bool) $state['bound'],
            'state' => (string) $state['state'],
            'questionsattempted' => (int) $state['questionsattempted'],
            'slots' => (int) $state['slots'],
        ];
    }

    /**
     * The engine attempts an explicit recovery decision retired from a sitting (local_catquizlab_retired).
     *
     * @param int $sittingid The sitting.
     * @return int[]
     */
    public static function retired(int $sittingid): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_retired')) {
            return [];
        }

        return array_map('intval', $DB->get_fieldset_select(
            'local_catquizlab_retired',
            'engineattemptid',
            'attemptid = :attemptid',
            ['attemptid' => $sittingid]
        ));
    }

    /**
     * A condition leaving out the retired attempts of a sitting, for a select on adaptivequiz_attempt.
     *
     * @param int $sittingid The sitting.
     * @return array{0: string, 1: array} ' AND id NOT IN (...)' or '', and its parameters.
     */
    protected static function not_retired(int $sittingid): array {
        global $DB;

        $retired = self::retired($sittingid);
        if ($retired === []) {
            return ['', []];
        }
        [$sql, $params] = $DB->get_in_or_equal($retired, SQL_PARAMS_NAMED, 'retired', false);

        return [' AND id ' . $sql, $params];
    }

    /**
     * An activity attempt as the worker and the reports read it.
     *
     * @param \stdClass $attempt The adaptivequiz_attempt row.
     * @param bool $bound Whether it is the sitting's bound attempt.
     * @return array
     */
    protected static function describe(\stdClass $attempt, bool $bound): array {
        global $DB;

        $count = $attempt->questionsattempted;
        if (!is_numeric($count) || (int) $count < 0 || (string) (int) $count !== trim((string) $count)) {
            return self::none(self::INVALID_COUNT, (int) $attempt->id);
        }
        $qubaid = (int) $attempt->uniqueid;
        $slots = $qubaid > 0 ? $DB->count_records('question_attempts', ['questionusageid' => $qubaid]) : 0;

        return [
            'found' => true,
            'reason' => '',
            'engineattemptid' => (int) $attempt->id,
            'qubaid' => $qubaid,
            'bound' => $bound,
            'state' => (string) $attempt->attemptstate,
            'questionsattempted' => (int) $count,
            'slots' => $slots,
            'timemodified' => (int) $attempt->timemodified,
        ];
    }

    /**
     * Nothing found, and why.
     *
     * @param string $reason The reason code.
     * @param int $engineattemptid The attempt it concerns, when there is one.
     * @return array
     */
    protected static function none(string $reason, int $engineattemptid = 0): array {
        return ['found' => false, 'reason' => $reason, 'engineattemptid' => $engineattemptid, 'qubaid' => 0,
            'bound' => false, 'state' => '', 'questionsattempted' => 0, 'slots' => 0, 'timemodified' => 0];
    }
}
