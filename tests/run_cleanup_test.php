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
 * Tests for run cleanup.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\run_cleanup;
use local_catquizlab\local\registry;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\user_provisioner;
use local_catquizlab\local\course_provisioner;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\scale_provisioner;

/**
 * Run cleanup tests.
 *
 * @covers \local_catquizlab\local\run_cleanup
 */
final class run_cleanup_test extends \advanced_testcase {
    /**
     * Fully provision a run: persons, users, course, queued attempts.
     *
     * @return int The run id.
     */
    protected function provisioned_run(): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        $definition = [
            'persons' => [
                'count'   => 4,
                'stratum' => 'conforming',
                'naming'  => ['pattern' => 'P-{stratum}-{index:03d}'],
            ],
            'pool'    => ['scales' => ['categories' => 1, 'subcategories' => 1]],
        ];
        person_generator::generate_and_persist($run->id, $definition, 42);
        user_provisioner::provision($run->id);
        course_provisioner::provision($run->id);
        attempt_scheduler::schedule($run->id);

        return $run->id;
    }

    /**
     * Cleanup clears the lab-store residue, deletes users and the course, resets the run.
     *
     * @return void
     */
    public function test_cleanup_resets_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->provisioned_run();
        $userids = $DB->get_fieldset_select('local_catquizlab_person', 'moodleuserid', 'runid = ?', [$runid]);
        $courseid = (int) $DB->get_field('local_catquizlab_run', 'courseid', ['id' => $runid]);

        ob_start();
        $counts = run_cleanup::cleanup($runid, ['course' => true]);
        ob_end_clean();

        $this->assertSame(4, $counts['persons']);
        $this->assertSame(4, $counts['users']);
        $this->assertSame($courseid, $counts['course']);

        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_person', ['runid' => $runid]));

        // The provisioned users are deleted.
        foreach ($userids as $userid) {
            $this->assertFalse($DB->record_exists('user', ['id' => $userid, 'deleted' => 0]));
        }
        // The suite-created course is gone.
        $this->assertFalse($DB->record_exists('course', ['id' => $courseid]));

        // The run itself survives, reset to draft with no course.
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
        $this->assertSame(registry::STATUS_DRAFT, (int) $run->status);
        $this->assertNull($run->courseid);
    }

    /**
     * Cleanup is idempotent.
     *
     * @return void
     */
    public function test_cleanup_is_idempotent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->provisioned_run();

        ob_start();
        run_cleanup::cleanup($runid, ['course' => true]);
        $second = run_cleanup::cleanup($runid, ['course' => true]);
        ob_end_clean();

        $this->assertSame(0, $second['persons']);
        $this->assertSame(0, $second['attempts']);
    }

    /**
     * A referenced (non-suite) course is not deleted.
     *
     * @return void
     */
    public function test_cleanup_keeps_referenced_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->set_field('local_catquizlab_run', 'courseid', $course->id, ['id' => $run->id]);

        ob_start();
        $counts = run_cleanup::cleanup($run->id, ['course' => true]);
        ob_end_clean();

        $this->assertSame(0, $counts['course']);
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
    }

    /**
     * With run => true the run row is deleted.
     *
     * @return void
     */
    public function test_cleanup_deletes_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->provisioned_run();

        ob_start();
        $counts = run_cleanup::cleanup($runid, ['course' => true, 'run' => true]);
        ob_end_clean();

        $this->assertTrue($counts['run']);
        $this->assertFalse($DB->record_exists('local_catquizlab_run', ['id' => $runid]));
    }

    /**
     * Cleanup removes the scale map (a lab-store table); engine teardown is a
     * no-op without the engine, so no items are reported.
     *
     * @return void
     */
    public function test_cleanup_removes_scalemap(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $now = time();
        $DB->insert_record('local_catquizlab_scalemap', (object) [
            'runid' => $run->id, 'catscaleid' => 101, 'parentcatscaleid' => 0, 'contextid' => 10,
            'level' => scale_provisioner::LEVEL_SUBSCALE, 'categoryindex' => 1, 'subscaleindex' => 1,
            'name' => '1:1', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $counts = run_cleanup::cleanup($run->id);
        $this->assertSame(0, $counts['items']);
        $this->assertSame(0, $DB->count_records('local_catquizlab_scalemap', ['runid' => $run->id]));
    }
}
