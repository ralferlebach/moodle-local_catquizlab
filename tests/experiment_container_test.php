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
 * Tests for the shared experiment course and its sections.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\experiment_container;
use local_catquizlab\local\run_orchestrator;

/**
 * Experiment-container tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_container
 */
final class experiment_container_test extends \advanced_testcase {
    /**
     * Create an experiment, optionally with runs.
     *
     * @param int $runs How many runs to attach.
     * @return \stdClass The experiment record.
     */
    protected function experiment(int $runs = 0): \stdClass {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment(['name' => 'Container demo']);

        for ($i = 1; $i <= $runs; $i++) {
            $generator->create_run(['experimentid' => $experiment->id, 'cellkey' => 'c' . $i, 'replication' => $i]);
        }

        return $experiment;
    }

    /**
     * Without a configured course nothing is provisioned and no course appears.
     *
     * @return void
     */
    public function test_no_configured_course(): void {
        global $DB;
        $this->resetAfterTest();

        $before = $DB->count_records('course');
        $experiment = $this->experiment();

        $outcome = experiment_container::provision((int) $experiment->id);

        // The suite creating a course by itself is what produced a hundred
        // courses for one condition; an unconfigured site now says so instead.
        $this->assertFalse($outcome['ok']);
        $this->assertSame(experiment_container::REASON_NO_COURSE, $outcome['reason']);
        $this->assertSame($before, $DB->count_records('course'));
    }

    /**
     * A configured course that has been deleted is reported, not recreated.
     *
     * @return void
     */
    public function test_configured_course_gone(): void {
        $this->resetAfterTest();

        set_config('experimentcourseid', 99999999, 'local_catquizlab');
        $experiment = $this->experiment();

        $outcome = experiment_container::provision((int) $experiment->id);

        $this->assertFalse($outcome['ok']);
        $this->assertSame(experiment_container::REASON_COURSE_MISSING, $outcome['reason']);
    }

    /**
     * Provisioning creates exactly one section, named after the experiment.
     *
     * @return void
     */
    public function test_creates_one_named_section(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        set_config('experimentcourseid', $course->id, 'local_catquizlab');
        $experiment = $this->experiment();

        $outcome = experiment_container::provision((int) $experiment->id);

        $this->assertTrue($outcome['ok']);
        $this->assertSame((int) $course->id, $outcome['courseid']);
        $this->assertGreaterThan(0, $outcome['sectionid']);

        $section = $DB->get_record('course_sections', ['id' => $outcome['sectionid']], '*', MUST_EXIST);
        $this->assertSame((int) $course->id, (int) $section->course);
        $this->assertStringContainsString('Experiment #' . $experiment->id, (string) $section->name);

        // The container is recorded on the experiment, so a later run can find
        // it without guessing.
        $stored = $DB->get_record('local_catquizlab_experiment', ['id' => $experiment->id], '*', MUST_EXIST);
        $this->assertSame((int) $course->id, (int) $stored->courseid);
        $this->assertSame($outcome['sectionid'], (int) $stored->sectionid);
    }

    /**
     * Provisioning twice reuses the section instead of adding another.
     *
     * @return void
     */
    public function test_section_provisioning_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        set_config('experimentcourseid', $course->id, 'local_catquizlab');
        $experiment = $this->experiment();

        $sectionsbefore = $DB->count_records('course_sections', ['course' => $course->id]);
        $first = experiment_container::provision((int) $experiment->id);
        $second = experiment_container::provision((int) $experiment->id);

        $this->assertSame($first['sectionid'], $second['sectionid']);
        $this->assertSame(
            $sectionsbefore + 1,
            $DB->count_records('course_sections', ['course' => $course->id])
        );
    }

    /**
     * Two experiments get one section each in the same course.
     *
     * @return void
     */
    public function test_two_experiments_share_the_course_with_separate_sections(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        set_config('experimentcourseid', $course->id, 'local_catquizlab');

        $first = experiment_container::provision((int) $this->experiment()->id);
        $second = experiment_container::provision((int) $this->experiment()->id);

        $this->assertSame($first['courseid'], $second['courseid']);
        $this->assertNotSame($first['sectionid'], $second['sectionid']);
    }

    /**
     * The section name carries the experiment's creation time, not the current one.
     *
     * @return void
     */
    public function test_section_name_uses_the_creation_time(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        set_config('experimentcourseid', $course->id, 'local_catquizlab');
        $experiment = $this->experiment();

        // A name that depended on when provisioning happened would make two
        // sections of the same experiment look like different ones.
        $created = time() - 86400;
        $DB->set_field('local_catquizlab_experiment', 'timecreated', $created, ['id' => $experiment->id]);
        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experiment->id], '*', MUST_EXIST);

        $name = experiment_container::section_name($experiment);

        $this->assertStringContainsString(
            userdate($created, get_string('strftimedatetimeshort')),
            $name
        );
    }

    /**
     * The activity name keeps the run id in front of the cell key.
     *
     * @return void
     */
    public function test_activity_name_leads_with_the_run_id(): void {
        $this->resetAfterTest();

        $run = (object) ['id' => 42, 'cellkey' => 'strategy=classic;variant=ideal', 'replication' => 4];

        $name = experiment_container::activity_name($run);

        // Course listings truncate long names, so the identifier has to come
        // first or a truncated activity cannot be told from its neighbour.
        $this->assertStringStartsWith('Run #42', $name);
        $this->assertStringContainsString('Rep 4', $name);
    }

    /**
     * The container stage runs before the test stage.
     *
     * @return void
     */
    public function test_container_precedes_the_test_stage(): void {
        $this->resetAfterTest();

        $stages = run_orchestrator::plan_stages();
        $container = array_search(run_orchestrator::STAGE_CONTAINER, $stages, true);
        $test = array_search(run_orchestrator::STAGE_TEST, $stages, true);
        $people = array_search(run_orchestrator::STAGE_PEOPLE, $stages, true);

        // The original bug in one assertion: the test stage asked for a course
        // that the pipeline had not created yet, returned null, and the run was
        // still reported as ok.
        $this->assertNotFalse($container);
        $this->assertLessThan($test, $container);
        $this->assertLessThan($test, $people);
    }

    /**
     * A test stage without an activity fails the run.
     *
     * @return void
     */
    public function test_a_run_without_an_activity_fails(): void {
        $this->resetAfterTest();

        $this->assertTrue(run_orchestrator::stage_failed(
            run_orchestrator::STAGE_TEST,
            ['failed' => false, 'testcmid' => 0]
        ));
        $this->assertFalse(run_orchestrator::stage_failed(
            run_orchestrator::STAGE_TEST,
            ['failed' => false, 'testcmid' => 17]
        ));
        $this->assertTrue(run_orchestrator::stage_failed(run_orchestrator::STAGE_TEST, null));
    }

    /**
     * The container stage fails when there is no course.
     *
     * @return void
     */
    public function test_container_stage_failure_stops_the_run(): void {
        $this->resetAfterTest();

        $this->assertTrue(run_orchestrator::stage_failed(
            run_orchestrator::STAGE_CONTAINER,
            ['ok' => false, 'failed' => true, 'reason' => experiment_container::REASON_NO_COURSE]
        ));
    }

    /**
     * Every container failure reason has a readable label.
     *
     * @return void
     */
    public function test_reasons_are_explained(): void {
        $this->resetAfterTest();

        foreach (
            [
            experiment_container::REASON_NO_COURSE,
            experiment_container::REASON_COURSE_MISSING,
            experiment_container::REASON_NO_SECTION,
            ] as $reason
        ) {
            $this->assertNotSame($reason, experiment_container::reason_label($reason));
        }
    }
}
