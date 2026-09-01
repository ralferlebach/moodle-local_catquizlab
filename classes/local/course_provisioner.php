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
 * Since issue #8 it no longer creates anything. A course per run meant a sweep
 * of a hundred replications produced a hundred courses for one condition, which
 * is unusable both administratively and for looking at what happened. The
 * course now comes from {@see experiment_container}, is shared by every run of
 * an experiment, and is configured by a person rather than invented here.
 *
 * What is left is the part that genuinely belongs to a run: enrolling its
 * simulated users into that course, idempotently, because many runs share it.
 * The link from a person to its run stays in local_catquizlab_person.runid and
 * is not implied by the enrolment.
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
        if ($courseid <= 0) {
            // No course to enrol into. Creating one here is exactly what this
            // class stopped doing, so the caller is told rather than surprised.
            return [
                'courseid'  => 0,
                'enrolled'  => 0,
                'testcmid'  => 0,
                'testready' => false,
                'failed'    => true,
                'reason'    => experiment_container::REASON_NO_COURSE,
            ];
        }

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
     * Resolve the course a run's users are enrolled into.
     *
     * An explicit option wins, then the experiment's shared course, then a
     * course an older run already sits in, then the configured course.
     * Nothing is created.
     *
     * @param \stdClass $run The run record.
     * @param array $options Provisioning options.
     * @return int The course id, or 0 when none can be resolved.
     */
    protected static function resolve_course(\stdClass $run, array $options): int {
        global $DB;

        if (!empty($options['courseid']) && $DB->record_exists('course', ['id' => $options['courseid']])) {
            return (int) $options['courseid'];
        }

        // The experiment's shared course, set by the container stage.
        if (!empty($run->experimentid)) {
            $container = experiment_container::existing((int) $run->experimentid);
            if ($container['courseid'] > 0 && $DB->record_exists('course', ['id' => $container['courseid']])) {
                return $container['courseid'];
            }
        }

        // A course an older run already sits in stays valid: the upgrade moves
        // nothing, so runs provisioned under the previous model keep working.
        if (!empty($run->courseid) && $DB->record_exists('course', ['id' => $run->courseid])) {
            return (int) $run->courseid;
        }

        // Finally the configured course itself, so enrolling works even when
        // this is called outside the orchestrator's container stage. Still
        // nothing is created — an unconfigured site resolves to nothing.
        $configured = experiment_container::configured_course();
        if ($configured > 0 && $DB->record_exists('course', ['id' => $configured])) {
            return $configured;
        }

        return 0;
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
