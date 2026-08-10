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
 * Tests for the course provisioner.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\course_provisioner;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\user_provisioner;

/**
 * Course provisioner tests.
 *
 * @covers \local_catquizlab\local\course_provisioner
 */
final class course_provisioner_test extends \advanced_testcase {
    /**
     * Create a run with four persons that have Moodle users.
     *
     * @return int The run id.
     */
    protected function run_with_users(): int {
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

        return $run->id;
    }

    /**
     * Provisioning creates a course, enrols the users and records the course.
     *
     * @return void
     */
    public function test_provision_creates_course_and_enrols(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with_users();

        $result = course_provisioner::provision($runid);

        $this->assertGreaterThan(0, $result['courseid']);
        $this->assertTrue($DB->record_exists('course', ['id' => $result['courseid']]));
        $this->assertSame(4, $result['enrolled']);

        $this->assertSame(
            (string) $result['courseid'],
            (string) $DB->get_field('local_catquizlab_run', 'courseid', ['id' => $runid])
        );

        $context = \context_course::instance($result['courseid']);
        $this->assertCount(4, get_enrolled_users($context));
    }

    /**
     * An existing course can be referenced instead of creating a new one.
     *
     * @return void
     */
    public function test_reference_existing_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $runid = $this->run_with_users();

        $result = course_provisioner::provision($runid, ['courseid' => $course->id]);

        $this->assertSame((int) $course->id, $result['courseid']);
        $this->assertSame(4, $result['enrolled']);
    }

    /**
     * Provisioning is idempotent: a second run enrols no one new and keeps the course.
     *
     * @return void
     */
    public function test_provision_is_idempotent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with_users();
        $first = course_provisioner::provision($runid);
        $second = course_provisioner::provision($runid);

        $this->assertSame(4, $first['enrolled']);
        $this->assertSame(0, $second['enrolled']);
        $this->assertSame($first['courseid'], $second['courseid']);
    }
}
