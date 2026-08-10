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
 * Course provisioner: a course per run, with the run's users enrolled.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Sets up the course a run's simulated persons sit their attempts in (E2.4).
 *
 * It resolves the course for a run — an existing one when specified, otherwise a
 * new hidden course — enrols the run's provisioned users as students, and
 * records the course on the run. This half uses only core APIs (course and
 * enrolment), so it runs on any Moodle. Creating the actual adaptivequiz CAT
 * test in that course needs the host activity and is left to the engine-side
 * step; when it lands it fills local_catquizlab_run.testcmid.
 */
class course_provisioner {
    /**
     * Provision the course for a run and enrol its users.
     *
     * @param int $runid The run to set up.
     * @param array $options Optional: 'courseid' (reference an existing course),
     *                       'categoryid', 'fullname', 'shortname', 'role' (default 'student').
     * @return array{courseid: int, enrolled: int, testcmid: int, testready: bool} The outcome.
     */
    public static function provision(int $runid, array $options = []): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);

        $courseid = self::resolve_course($run, $options);

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => (string) ($options['role'] ?? 'student')]);
        $userids = $DB->get_fieldset_select(
            'local_catquizlab_person',
            'moodleuserid',
            'runid = :runid AND moodleuserid IS NOT NULL',
            ['runid' => $runid]
        );

        $enrolled = self::enrol_users($courseid, $userids, $roleid);

        $DB->set_field('local_catquizlab_run', 'courseid', $courseid, ['id' => $runid]);

        return [
            'courseid'  => $courseid,
            'enrolled'  => $enrolled,
            'testcmid'  => 0,
            // The CAT test can only be created when the host activity is present;
            // its actual creation is the engine-side step.
            'testready' => environment::adaptivequiz_available(),
        ];
    }

    /**
     * Resolve the course for a run: an option, the run's own course, an existing
     * course with the target shortname, or a freshly created hidden course.
     *
     * @param \stdClass $run The run record.
     * @param array $options Provisioning options.
     * @return int The course id.
     */
    protected static function resolve_course(\stdClass $run, array $options): int {
        global $DB;

        if (!empty($options['courseid']) && $DB->record_exists('course', ['id' => $options['courseid']])) {
            return (int) $options['courseid'];
        }
        if (!empty($run->courseid) && $DB->record_exists('course', ['id' => $run->courseid])) {
            return (int) $run->courseid;
        }

        $shortname = (string) ($options['shortname'] ?? ('catlab_run_' . $run->id));
        $existing = $DB->get_record('course', ['shortname' => $shortname]);
        if ($existing) {
            return (int) $existing->id;
        }

        $category = (int) ($options['categoryid'] ?? \core_course_category::get_default()->id);
        $course = create_course((object) [
            'fullname'  => (string) ($options['fullname'] ?? ('CAT lab run ' . $run->id)),
            'shortname' => $shortname,
            'category'  => $category,
            'format'    => 'topics',
            'visible'   => 0,
        ]);

        return (int) $course->id;
    }

    /**
     * Enrol the given users as students on the course, skipping those already enrolled.
     *
     * @param int $courseid The course id.
     * @param int[] $userids The user ids to enrol.
     * @param int $roleid The role id to assign.
     * @return int The number of users newly enrolled.
     */
    protected static function enrol_users(int $courseid, array $userids, int $roleid): int {
        global $DB;

        if (!$userids) {
            return 0;
        }

        $instance = self::manual_enrol_instance($courseid);
        $manual = enrol_get_plugin('manual');

        $count = 0;
        foreach ($userids as $userid) {
            if (!$DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid])) {
                $manual->enrol_user($instance, (int) $userid, $roleid);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Return the course's manual enrolment instance, creating it if necessary.
     *
     * @param int $courseid The course id.
     * @return \stdClass The enrol instance record.
     */
    protected static function manual_enrol_instance(int $courseid): \stdClass {
        global $DB;

        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
        if ($instance) {
            return $instance;
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $manual = enrol_get_plugin('manual');
        $instanceid = $manual->add_default_instance($course);
        if (!$instanceid) {
            $instanceid = $manual->add_instance($course);
        }
        return $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }
}
