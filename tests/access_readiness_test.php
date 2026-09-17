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
 * Can the simulated person reach the test — asked as they would.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\access_readiness;

/**
 * Access readiness tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\access_readiness
 */
final class access_readiness_test extends \advanced_testcase {
    /**
     * A run with a course, an activity and one enrolled simulated person.
     *
     * @return array The run id and the course.
     */
    protected function make_reachable_run(): array {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;

        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $quiz = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $DB->set_field('local_catquizlab_run', 'testcmid', $quiz->cmid, ['id' => $runid]);
        $DB->insert_record('local_catquizlab_person', (object) [
            'runid' => $runid, 'twinid' => 't1', 'moodleuserid' => $user->id,
            'truetheta' => 0, 'profile' => 'conforming',
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        return [$runid, $course, $user, $quiz];
    }

    /**
     * Take a user out of a course.
     *
     * The data generator enrols but does not unenrol, so this goes through the
     * enrolment plugin the generator used.
     *
     * @param int $userid The user.
     * @param int $courseid The course.
     * @return void
     */
    protected function unenrol(int $userid, int $courseid): void {
        global $DB;

        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $userid);
    }

    /**
     * A reachable test passes every check.
     *
     * @return void
     */
    public function test_a_reachable_test_passes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$runid] = $this->make_reachable_run();

        $access = access_readiness::check($runid);

        $this->assertTrue($access['ok'], $access['summary']);
        $this->assertSame('', $access['code']);
    }

    /**
     * A hidden course is named as a hidden course.
     *
     * @return void
     */
    public function test_a_hidden_course_is_named(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$runid, $course] = $this->make_reachable_run();
        $DB->set_field('course', 'visible', 0, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);

        $access = access_readiness::check($runid);

        // This cost a whole debugging session: the worker logged in correctly,
        // reached the activity, and found "this course is currently
        // unavailable" where the start button belonged. What came back was "no
        // question was presented" — true, and three steps from the cause.
        $this->assertFalse($access['ok']);
        $this->assertSame(access_readiness::COURSE_HIDDEN, $access['code']);
    }

    /**
     * A hidden activity and a hidden course are different codes.
     *
     * @return void
     */
    public function test_a_hidden_activity_is_its_own_code(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$runid, $course, , $quiz] = $this->make_reachable_run();
        set_coursemodule_visible($quiz->cmid, 0);
        rebuild_course_cache($course->id, true);

        // Eight codes rather than one failure, because each needs a different
        // repair.
        $this->assertSame(access_readiness::ACTIVITY_NOT_VISIBLE, access_readiness::check($runid)['code']);
    }

    /**
     * An unenrolled person is named as unenrolled.
     *
     * @return void
     */
    public function test_an_unenrolled_person_is_named(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$runid, $course, $user] = $this->make_reachable_run();
        $this->unenrol($user->id, $course->id);

        $this->assertSame(access_readiness::NOT_ENROLLED, access_readiness::check($runid)['code']);
    }

    /**
     * Repair fixes what this plugin caused, and reports the rest.
     *
     * @return void
     */
    public function test_repair_undoes_what_the_plugin_did(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$runid, $course, $user] = $this->make_reachable_run();
        $DB->set_field('course', 'visible', 0, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);

        $result = access_readiness::repair($runid);

        $this->assertTrue($result['ok']);
        $this->assertContains(access_readiness::COURSE_HIDDEN, $result['fixed']);

        // A decision somebody made is not undone quietly: unenrolling was not
        // this plugin's doing, and reversing it silently would be worse than
        // reporting it.
        $this->unenrol($user->id, $course->id);
        $second = access_readiness::repair($runid);

        $this->assertFalse($second['ok']);
        $this->assertSame([], $second['fixed']);
        $this->assertSame(access_readiness::NOT_ENROLLED, $second['code']);
    }

    /**
     * Access is checked before the attempts are built.
     *
     * @return void
     */
    public function test_access_is_a_stage_before_attempts(): void {
        $this->resetAfterTest();

        $stages = \local_catquizlab\local\run_orchestrator::plan_stages();

        // A run whose people cannot open the activity should not have attempts
        // made for them.
        $this->assertSame(
            array_search('access', $stages, true) + 1,
            array_search('attempts', $stages, true)
        );
    }
}
