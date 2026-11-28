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
 * The host's time limit as it applies to a run's engine attempts (P0 "[ENGINE-LIFECYCLE]" AK05, re-audit 27.11.2026).
 *
 * Read from the pinned engine (docs/dev/host-timeout.md): local_catquiz's scheduled task cancel_expired_attempts, every
 * five minutes, closes an open CAT attempt of an adaptive quiz once now − attempt.timecreated exceeds
 * - the test's own attempt limit × 1.5, when the test sets one (catquiz_handler::time_limits()), or
 * - the site's maximum_attempt_duration_hours (24 by default; 0 is none) when it does not — and a test with its time
 *   limit switched off (catquiz_includetimelimit 0, as the lab provisions every test) sets none.
 * Counted from the attempt's creation, not from its last activity; a pause of the lab does not stop it. The host closes
 * the attempt with stop code "timelimit" and the engine's status 9. Up to local_catquiz 2026112702/03 the lab could not
 * switch it off for its own tests without changing the engine's default for the whole site — which it does not do.
 * Since the engine's per-test policy (local_catquiz #178, expiry_policy) it keeps its own tests open: "none" for the
 * test of each run, no time limit shown to the person, nothing changed for other tests (keep_open(), at provisioning
 * and at resume). The policy is then read from the engine itself.
 *
 * This class reads the policy as the task would apply it (kept in the run's manifest when it is provisioned and when
 * it is resumed), and says for an open attempt when the host will close it: its deadline, or that it is not known.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class host_time_limit {
    /** @var string The engine's task that closes expired attempts. */
    public const TASK = '\local_catquiz\task\cancel_expired_attempts';

    /** @var float The buffer the task allows on a test's own limit (cancel_expired_attempts::BUFFER_TIME_FACTOR). */
    public const BUFFER = 1.5;

    /** @var string The test's own attempt limit applies. */
    public const SOURCE_TEST = 'test';

    /** @var string The site's default for tests without one applies. */
    public const SOURCE_SITE = 'site-default';

    /** @var string No limit closes the attempts. */
    public const SOURCE_NONE = 'none';

    /** @var string The engine or its task is not there: whether a limit applies is not known. */
    public const SOURCE_UNKNOWN = 'unknown';

    /** @var string The engine's own policy of an activity's attempts (local_catquiz #178, since 2026112703/04). */
    public const ENGINE_POLICY = '\local_catquiz\local\attempt\expiry_policy';

    /**
     * The test of a run is kept open by the engine's clean-up task (local_catquiz #178): the engine's per-test policy
     * "none", which shows no time limit to the person and changes nothing for other tests of the site.
     *
     * The lab's designs set no time limit; until the engine offered this, its tests fell back to the site's default and
     * the task closed a classical sitting of 2500 items after 24 hours (CLARA run 103). Where the engine has no such
     * policy (older pins), nothing is written and the task's rules apply as before — said in the manifest (record()).
     *
     * @param int $runid The run.
     * @return array{supported: bool, rows: int} Whether the engine offers the policy, and the settings rows written.
     */
    public static function keep_open(int $runid): array {
        global $DB;

        if (!class_exists(self::ENGINE_POLICY)) {
            return ['supported' => false, 'rows' => 0];
        }
        $instance = self::instance_of($runid);
        if ($instance <= 0) {
            return ['supported' => true, 'rows' => 0];
        }
        $class = self::ENGINE_POLICY;

        return ['supported' => true, 'rows' => (int) $class::set_for_activity($instance, $class::NONE)];
    }

    /**
     * The activity instance of a run's test.
     *
     * @param int $runid The run.
     * @return int 0 without one.
     */
    protected static function instance_of(int $runid): int {
        global $DB;

        $cmid = (int) $DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $runid]);

        return $cmid > 0 ? (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]) : 0;
    }

    /**
     * The policy for a run's attempts, as the engine's task applies it now.
     *
     * @param int $runid The run.
     * @return array source, seconds (null: none or unknown), counted, task, schedule, sitehours, testseconds, readat.
     */
    public static function policy(int $runid): array {
        global $DB;

        $out = ['source' => self::SOURCE_UNKNOWN, 'seconds' => null, 'counted' => 'from the attempt\'s creation',
            'pause' => 'not stopped by a pause of the lab', 'task' => self::TASK, 'schedule' => null, 'sitehours' => null,
            'testseconds' => null, 'readat' => time()];
        if (!class_exists(self::TASK) || !class_exists('\local_catquiz\catquiz_handler')) {
            return $out;
        }
        $task = $DB->get_record('task_scheduled', ['classname' => self::TASK], 'minute, hour, day, month, dayofweek, disabled');
        if ($task) {
            $out['schedule'] = (int) $task->disabled ? 'disabled' : implode(' ', [$task->minute, $task->hour, $task->day,
                $task->month, $task->dayofweek]);
        }
        $instance = self::instance_of($runid);
        // The engine's own reading where it has one (local_catquiz #178): its rules, its fallbacks, its words.
        if (class_exists(self::ENGINE_POLICY) && $instance > 0) {
            $class = self::ENGINE_POLICY;
            $said = $class::for_activity($instance)->describe();
            $out['enginesource'] = (string) $said['source'];
            $out['note'] = (string) ($said['note'] ?? '');
            $out['sitehours'] = $class::site_hours(get_config('local_catquiz', 'maximum_attempt_duration_hours'));
            if ($said['seconds'] === null) {
                return ['source' => self::SOURCE_NONE] + $out;
            }
            $source = in_array($said['source'], [$class::SOURCE_SITE], true) ? self::SOURCE_SITE : self::SOURCE_TEST;

            return ['source' => $source, 'seconds' => (int) $said['seconds']] + $out;
        }
        // As the task of older pins reads it: an unset setting is 0 there (intval(false ?? 24)), which means no limit.
        $hours = (int) intval(get_config('local_catquiz', 'maximum_attempt_duration_hours') ?? 24);
        $out['sitehours'] = $hours;
        $testlimit = null;
        if ($instance > 0 && $DB->get_manager()->table_exists('local_catquiz_tests')) {
            $tests = $DB->get_records_select(
                'local_catquiz_tests',
                "componentid = :instance AND component IN ('mod_adaptivequiz', 'adaptivequiz')",
                ['instance' => $instance],
                'id DESC',
                'id, json',
                0,
                1
            );
            if ($tests !== []) {
                $limits = \local_catquiz\catquiz_handler::time_limits(json_decode((string) reset($tests)->json) ?: null);
                $testlimit = $limits['attempt'] ?? null;
            }
        }
        $out['testseconds'] = $testlimit;
        if ($testlimit !== null && (int) $testlimit > 0) {
            return ['source' => self::SOURCE_TEST, 'seconds' => (int) round($testlimit * self::BUFFER)] + $out;
        }
        if ($hours <= 0) {
            return ['source' => self::SOURCE_NONE] + $out;
        }

        return ['source' => self::SOURCE_SITE, 'seconds' => $hours * HOURSECS] + $out;
    }

    /**
     * When the host will close an open engine attempt, and how long is left.
     *
     * @param int $engineattemptid The adaptivequiz_attempt.
     * @param array $policy policy().
     * @param int|null $now The clock.
     * @return array deadline (null: none or unknown), remaining (seconds, may be negative), known (bool), state.
     */
    public static function deadline(int $engineattemptid, array $policy, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $attempt = $engineattemptid > 0 && $DB->get_manager()->table_exists('adaptivequiz_attempt')
            ? $DB->get_record('adaptivequiz_attempt', ['id' => $engineattemptid], 'id, attemptstate, timecreated') : false;
        if (!$attempt) {
            return ['deadline' => null, 'remaining' => null, 'known' => false, 'state' => 'no-engine-attempt'];
        }
        if ((string) $attempt->attemptstate !== 'inprogress') {
            return ['deadline' => null, 'remaining' => null, 'known' => true, 'state' => (string) $attempt->attemptstate];
        }
        if ($policy['seconds'] === null) {
            return ['deadline' => null, 'remaining' => null, 'known' => $policy['source'] === self::SOURCE_NONE,
                'state' => 'inprogress'];
        }
        $deadline = (int) $attempt->timecreated + (int) $policy['seconds'];

        return ['deadline' => $deadline, 'remaining' => $deadline - $now, 'known' => true, 'state' => 'inprogress'];
    }

    /** @var int How long before the host's deadline an open attempt is said to be near it. */
    public const WARN_SECONDS = 2 * HOURSECS;

    /**
     * Say in each run's log which of its open engine attempts the host will close soon, or should have closed already:
     * at most once an hour per run (pipeline tick). Changes nothing; the decision what to do is a person's.
     *
     * @param int|null $now The clock.
     * @return array<int, array> Run id => what was said: near, past (how many), earliest (seconds left), sittings.
     */
    public static function warn_near(?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            return [];
        }
        $rows = $DB->get_recordset_sql(
            "SELECT a.id, a.runid, a.engineattemptid, aa.timecreated
               FROM {local_catquizlab_attempt} a
               JOIN {adaptivequiz_attempt} aa ON aa.id = a.engineattemptid
              WHERE a.engineattemptid > 0 AND aa.attemptstate = :inprogress
                    AND a.status IN (:queued, :running, :cancelled, :failed)",
            ['inprogress' => 'inprogress', 'queued' => attempt_scheduler::STATUS_QUEUED,
                'running' => attempt_scheduler::STATUS_RUNNING, 'cancelled' => attempt_scheduler::STATUS_CANCELLED,
                'failed' => attempt_scheduler::STATUS_FAILED]
        );
        $policies = [];
        $out = [];
        foreach ($rows as $row) {
            $runid = (int) $row->runid;
            $policies[$runid] = $policies[$runid] ?? self::policy($runid);
            if ($policies[$runid]['seconds'] === null) {
                continue;
            }
            $remaining = (int) $row->timecreated + (int) $policies[$runid]['seconds'] - $now;
            if ($remaining > self::WARN_SECONDS) {
                continue;
            }
            $out[$runid] = $out[$runid] ?? ['near' => 0, 'past' => 0, 'earliest' => PHP_INT_MAX, 'sittings' => []];
            $out[$runid][$remaining > 0 ? 'near' : 'past']++;
            $out[$runid]['earliest'] = min($out[$runid]['earliest'], $remaining);
            if (count($out[$runid]['sittings']) < 20) {
                $out[$runid]['sittings'][] = (int) $row->id;
            }
        }
        $rows->close();
        foreach ($out as $runid => $said) {
            $recent = $DB->record_exists_select(
                'local_catquizlab_runlog',
                'runid = :runid AND event = :event AND timecreated > :since',
                ['runid' => $runid, 'event' => run_log::TIME_LIMIT_NEAR, 'since' => $now - HOURSECS]
            );
            if (!$recent) {
                run_log::record($runid, run_log::TIME_LIMIT_NEAR, $said + ['policy' => $policies[$runid]['source'],
                    'seconds' => $policies[$runid]['seconds']]);
            }
        }

        return $out;
    }

    /**
     * Keep the policy in the run's manifest (when provisioned, when resumed): what the lab knew of the host's limit.
     *
     * @param int $runid The run.
     * @param string $when provisioned or resumed.
     * @return array The policy kept.
     */
    public static function record(int $runid, string $when): array {
        global $DB;

        // The lab's tests are kept open where the engine allows it per test; then the policy as the engine reads it.
        $kept = self::keep_open($runid);
        $policy = self::policy($runid) + ['when' => $when, 'keptopen' => $kept];
        $manifest = json_decode((string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runid]), true) ?: [];
        $manifest['hosttimelimit'] = $policy;
        $DB->set_field('local_catquizlab_run', 'manifestjson', json_encode($manifest, JSON_UNESCAPED_SLASHES), ['id' => $runid]);

        return $policy;
    }
}
