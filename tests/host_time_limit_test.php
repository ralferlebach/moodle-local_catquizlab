<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_catquizlab;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\host_time_limit;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\run_log;

/**
 * The host's time limit as it applies to a run (P0 "[ENGINE-LIFECYCLE]" AK05, DoD01; re-audit 27.11.2026).
 *
 * As local_catquiz's cancel_expired_attempts reads it: the test's own attempt limit × 1.5 when it sets one, the site's
 * default otherwise (24 h), none when that is 0 — and a test with the time limit switched off, as the lab provisions
 * every test, sets none, so the site's default applies to it. Kept in the manifest when the run is provisioned; an
 * open attempt near its deadline is said in the run's log, once an hour.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\host_time_limit
 */
final class host_time_limit_test extends \advanced_testcase {
    /** @var int The activity instance. */
    protected const INSTANCE = 9201;

    /**
     * A run on an activity whose test has these settings.
     *
     * @param array|null $settings The test's settings (its json), null for no test row.
     * @return int The run.
     */
    protected function run_with(?array $settings): int {
        global $DB;

        if (!class_exists(host_time_limit::TASK) || !$DB->get_manager()->table_exists('local_catquiz_tests')) {
            $this->markTestSkipped('Needs local_catquiz with its clean-up task.');
        }
        /** @var \local_catquizlab_generator $lab */
        $lab = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $course = $this->getDataGenerator()->create_course();
        $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
            'instance' => self::INSTANCE, 'section' => 0, 'added' => time()]);
        $runid = (int) $lab->create_run(['status' => registry::STATUS_SCHEDULED, 'testcmid' => $cmid])->id;
        if ($settings !== null) {
            $DB->insert_record('local_catquiz_tests', (object) ['componentid' => self::INSTANCE,
                'component' => 'mod_adaptivequiz', 'json' => json_encode($settings), 'status' => 1, 'timecreated' => time(),
                'timemodified' => time()]);
        }

        return $runid;
    }

    /**
     * The sources, as the engine applies them (local_catquiz #178: its expiry_policy, read through the engine itself).
     *
     * @return void
     */
    public function test_the_policy_is_read_as_the_engine_applies_it(): void {
        $this->resetAfterTest();
        if (!class_exists(host_time_limit::ENGINE_POLICY)) {
            $this->markTestSkipped('Needs local_catquiz with its expiry policy (2026112703/04).');
        }
        set_config('maximum_attempt_duration_hours', 24, 'local_catquiz');

        // A test with its time limit switched off and no policy: the site's default applies.
        $lab = host_time_limit::policy($this->run_with(['catquiz_includetimelimit' => '0']));
        $this->assertSame([host_time_limit::SOURCE_SITE, 24 * HOURSECS, 'site-default', 24], [$lab['source'],
            $lab['seconds'], $lab['enginesource'], $lab['sitehours']]);
        $this->assertSame('from the attempt\'s creation', $lab['counted']);
        $this->assertSame('not stopped by a pause of the lab', $lab['pause']);
        $this->assertNotNull($lab['schedule'], 'the task is installed');

        // A test with a limit of its own: that limit, with the task's buffer of 1.5.
        $own = host_time_limit::policy($this->run_with(['catquiz_includetimelimit' => '1',
            'catquiz_timelimitgroup' => ['catquiz_maxtimeperattempt' => 2, 'catquiz_timeselect_attempt' => 'h']]));
        $this->assertSame([host_time_limit::SOURCE_TEST, 3 * HOURSECS, 'test-timelimit'], [$own['source'], $own['seconds'],
            $own['enginesource']]);

        // The lab keeps its tests open: the engine's policy "none" for that test, nothing else changed.
        $kept = $this->run_with(['catquiz_includetimelimit' => '0']);
        // Every settings row of the activity (this test's runs share one instance: three rows by now).
        $this->assertSame(['supported' => true, 'rows' => 3], host_time_limit::keep_open($kept));
        $none = host_time_limit::policy($kept);
        $this->assertSame([host_time_limit::SOURCE_NONE, null, 'test-policy-none'], [$none['source'], $none['seconds'],
            $none['enginesource']]);
        $this->assertSame(24, (int) get_config('local_catquiz', 'maximum_attempt_duration_hours'), 'the site\'s untouched');

        // The site's default switched off: none; not set: 24 hours (the engine's fix of intval(false)).
        set_config('maximum_attempt_duration_hours', 0, 'local_catquiz');
        $off = host_time_limit::policy($this->run_with(['catquiz_includetimelimit' => '0']));
        $this->assertSame([host_time_limit::SOURCE_NONE, null, 'site-default-none'], [$off['source'], $off['seconds'],
            $off['enginesource']]);
        unset_config('maximum_attempt_duration_hours', 'local_catquiz');
        $unset = host_time_limit::policy($this->run_with(['catquiz_includetimelimit' => '0']));
        $this->assertSame([host_time_limit::SOURCE_SITE, 24 * HOURSECS], [$unset['source'], $unset['seconds']]);
    }

    /**
     * An open attempt's deadline and what is left; a closed one has none; an unknown policy is not a deadline.
     *
     * @return void
     */
    public function test_an_open_attempt_has_its_deadline(): void {
        global $DB;
        $this->resetAfterTest();
        $now = 1800000000;
        $policy = ['source' => host_time_limit::SOURCE_SITE, 'seconds' => 24 * HOURSECS];
        $open = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => 1, 'userid' => 2, 'uniqueid' => 3,
            'attemptstate' => 'inprogress', 'attemptstopcriteria' => '', 'questionsattempted' => 1000,
            'timecreated' => $now - 23 * HOURSECS, 'timemodified' => $now]);
        $this->assertSame(
            ['deadline' => $now + HOURSECS, 'remaining' => HOURSECS, 'known' => true, 'state' => 'inprogress'],
            host_time_limit::deadline($open, $policy, $now)
        );
        $this->assertSame(
            ['deadline' => null, 'remaining' => null, 'known' => false, 'state' => 'inprogress'],
            host_time_limit::deadline($open, ['source' => host_time_limit::SOURCE_UNKNOWN, 'seconds' => null], $now)
        );
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $open]);
        $this->assertSame('complete', host_time_limit::deadline($open, $policy, $now)['state']);
        $this->assertFalse(host_time_limit::deadline(0, $policy, $now)['known']);
    }

    /**
     * Provisioned: the policy is kept with the run. Near the deadline: said in the run's log, once an hour.
     *
     * @return void
     */
    public function test_the_policy_is_kept_and_a_near_deadline_said(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('maximum_attempt_duration_hours', 24, 'local_catquiz');
        $runid = $this->run_with(['catquiz_includetimelimit' => '0']);
        run_lifecycle::provisioned($runid, true);
        $kept = json_decode((string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runid]), true);
        if (class_exists(host_time_limit::ENGINE_POLICY)) {
            // Provisioned: the test is kept open by the engine's own policy, and the manifest says so.
            $this->assertSame(
                [host_time_limit::SOURCE_NONE, null, 'provisioned', 'test-policy-none', 1],
                [$kept['hosttimelimit']['source'], $kept['hosttimelimit']['seconds'], $kept['hosttimelimit']['when'],
                $kept['hosttimelimit']['enginesource'],
                $kept['hosttimelimit']['keptopen']['rows']]
            );
            $this->assertSame([], host_time_limit::warn_near(time()), 'kept open: no deadline to warn of');
            // For the warnings below, the site's rules again (as a run of older pins has them).
            $class = host_time_limit::ENGINE_POLICY;
            $class::set_for_activity(self::INSTANCE, null);
        } else {
            $this->assertSame(
                [host_time_limit::SOURCE_SITE, 24 * HOURSECS, 'provisioned'],
                [$kept['hosttimelimit']['source'], $kept['hosttimelimit']['seconds'], $kept['hosttimelimit']['when']]
            );
        }

        $now = time();
        foreach ([23 * HOURSECS + 1800 => 'near', 25 * HOURSECS => 'past', 3 * HOURSECS => 'far'] as $age => $label) {
            $engine = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => self::INSTANCE,
                'userid' => 100 + strlen($label), 'uniqueid' => 0, 'attemptstate' => 'inprogress', 'attemptstopcriteria' => '',
                'questionsattempted' => 10, 'timecreated' => $now - $age, 'timemodified' => $now]);
            $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid, 'personid' => 0,
                'status' => attempt_scheduler::STATUS_RUNNING, 'engineattemptid' => $engine, 'tries' => 1,
                'timecreated' => $now, 'timemodified' => $now]);
        }
        $said = host_time_limit::warn_near($now);
        $this->assertSame([1, 1], [$said[$runid]['near'], $said[$runid]['past']]);
        $this->assertSame(1, $DB->count_records('local_catquizlab_runlog', ['runid' => $runid,
            'event' => run_log::TIME_LIMIT_NEAR]));
        host_time_limit::warn_near($now + 60);
        $this->assertSame(1, $DB->count_records('local_catquizlab_runlog', ['runid' => $runid,
            'event' => run_log::TIME_LIMIT_NEAR]), 'once an hour');
        $this->assertSame(3, $DB->count_records('local_catquizlab_attempt', ['runid' => $runid,
            'status' => attempt_scheduler::STATUS_RUNNING]), 'nothing acted on');
    }
}
