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
 * Can the simulated person actually reach the test — asked as they would.
 *
 * Provisioning enrols people and creates an activity, and then checks that the
 * objects exist. They did. The course was hidden, so every enrolled student was
 * told the course was unavailable, and the worker logged in correctly, reached
 * the activity and found that sentence where the start button belonged.
 *
 * What came back was "No question was presented; the attempt never started" —
 * true, and three steps from the cause. An access failure must never be reported
 * as a missing question: the two need completely different responses, and only
 * one of them is about the test.
 *
 * So this asks Moodle the questions the user's own browser will ask, before the
 * run is called READY, and names which one fails.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_readiness {
    /** @var string The course is hidden from its students. */
    public const COURSE_HIDDEN = 'course-hidden';

    /** @var string The course refuses the user for some other reason. */
    public const COURSE_NOT_ACCESSIBLE = 'course-not-accessible';

    /** @var string The person is not enrolled. */
    public const NOT_ENROLLED = 'user-not-enrolled';

    /** @var string The enrolment exists and is suspended. */
    public const ENROLMENT_SUSPENDED = 'enrolment-suspended';

    /** @var string The user account is suspended or unconfirmed. */
    public const USER_INACTIVE = 'user-inactive';

    /** @var string The activity is hidden. */
    public const ACTIVITY_NOT_VISIBLE = 'activity-not-visible';

    /** @var string An availability restriction is blocking it. */
    public const AVAILABILITY_RESTRICTED = 'availability-restricted';

    /** @var string The person may not attempt the quiz. */
    public const MISSING_CAPABILITY = 'missing-attempt-capability';

    /** @var string There is no activity to reach. */
    public const NO_ACTIVITY = 'no-activity';

    /** @var string There are no people to check. */
    public const NO_PEOPLE = 'no-people';

    /**
     * Check one run, through one of its simulated people.
     *
     * One person, not all of them: they are provisioned identically, and asking
     * Moodle the same eight questions two hundred times answers nothing the
     * first did not.
     *
     * @param int $runid The run.
     * @return array{ok: bool, checks: array[], code: string, summary: string}
     */
    public static function check(int $runid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');

        $component = 'local_catquizlab';

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run || (int) $run->testcmid === 0) {
            return self::verdict(false, [], self::NO_ACTIVITY, $component);
        }

        $people = $DB->get_records('local_catquizlab_person', ['runid' => $runid], 'id ASC', '*', 0, 1);
        if ($people === []) {
            return self::verdict(false, [], self::NO_PEOPLE, $component);
        }

        $person = reset($people);
        $user = $DB->get_record('user', ['id' => (int) $person->moodleuserid]);
        if (!$user) {
            return self::verdict(false, [], self::NO_PEOPLE, $component);
        }

        $cm = get_coursemodule_from_id('adaptivequiz', (int) $run->testcmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return self::verdict(false, [], self::NO_ACTIVITY, $component);
        }

        $course = $DB->get_record('course', ['id' => $cm->course]);
        $checks = [];
        $code = '';

        // The account itself.
        $active = (int) $user->suspended === 0 && (int) $user->confirmed === 1 && (int) $user->deleted === 0;
        $checks[] = self::check_row('user', get_string('access:useractive', $component), $active);
        if (!$active) {
            $code = $code ?: self::USER_INACTIVE;
        }

        // The course, as this user sees it. `visible = 0` is checked on its own
        // rather than folded into accessibility, because it is the one that
        // produced the silent failure and it has its own fix.
        $coursevisible = (int) $course->visible === 1;
        $checks[] = self::check_row('coursevisible', get_string('access:coursevisible', $component), $coursevisible);
        if (!$coursevisible) {
            $code = $code ?: self::COURSE_HIDDEN;
        }

        $coursecontext = \context_course::instance($course->id);
        $enrolled = is_enrolled($coursecontext, $user, '', true);
        $checks[] = self::check_row('enrolled', get_string('access:enrolled', $component), $enrolled);
        if (!$enrolled) {
            // Enrolled at all, or enrolled and suspended: the same symptom for
            // the user and a different repair.
            $code = $code ?: (is_enrolled($coursecontext, $user, '', false)
                ? self::ENROLMENT_SUSPENDED
                : self::NOT_ENROLLED);
        }

        $accessible = self::can_access_course($course, $user);
        $checks[] = self::check_row('courseaccess', get_string('access:courseaccess', $component), $accessible);
        if (!$accessible) {
            $code = $code ?: self::COURSE_NOT_ACCESSIBLE;
        }

        // The activity, through the same modinfo the user's page build uses.
        $uservisible = false;
        $availability = '';
        try {
            $modinfo = get_fast_modinfo($course, $user->id);
            $cminfo = $modinfo->get_cm($cm->id);
            $uservisible = (bool) $cminfo->uservisible;
            $availability = (string) $cminfo->availableinfo;
        } catch (\Throwable $e) {
            $uservisible = false;
        }

        $checks[] = self::check_row(
            'activityvisible',
            get_string('access:activityvisible', $component),
            $uservisible,
            $availability
        );
        if (!$uservisible) {
            $code = $code ?: ($availability !== ''
                ? self::AVAILABILITY_RESTRICTED
                : self::ACTIVITY_NOT_VISIBLE);
        }

        $cancapability = has_capability(
            'mod/adaptivequiz:attempt',
            \context_module::instance($cm->id),
            $user
        );
        $checks[] = self::check_row('capability', get_string('access:attemptcapability', $component), $cancapability);
        if (!$cancapability) {
            $code = $code ?: self::MISSING_CAPABILITY;
        }

        return self::verdict($code === '', $checks, $code, $component);
    }

    /**
     * Repair what can be repaired, and say what could not.
     *
     * Only the causes this plugin created: a course it made and hid, an
     * enrolment it made and suspended. A capability somebody removed on purpose
     * or an availability restriction somebody wrote are decisions, and undoing
     * a decision quietly is worse than reporting it.
     *
     * @param int $runid The run.
     * @return array{ok: bool, fixed: string[], code: string}
     */
    public static function repair(int $runid): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $before = self::check($runid);
        if ($before['ok']) {
            return ['ok' => true, 'fixed' => [], 'code' => ''];
        }

        $fixed = [];
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        $cm = $run && (int) $run->testcmid > 0
            ? get_coursemodule_from_id('adaptivequiz', (int) $run->testcmid, 0, false, IGNORE_MISSING)
            : null;

        if ($cm) {
            // The course this plugin created, hidden. It was hidden on purpose
            // once, and that purpose was wrong.
            $course = $DB->get_record('course', ['id' => $cm->course]);
            if ($course && (int) $course->visible === 0) {
                $DB->set_field('course', 'visible', 1, ['id' => $course->id]);
                $DB->set_field('course', 'visibleold', 1, ['id' => $course->id]);
                rebuild_course_cache((int) $course->id, true);
                $fixed[] = self::COURSE_HIDDEN;
            }

            // The activity, likewise.
            if ((int) $cm->visible === 0) {
                set_coursemodule_visible($cm->id, 1);
                rebuild_course_cache((int) $cm->course, true);
                $fixed[] = self::ACTIVITY_NOT_VISIBLE;
            }
        }

        $after = self::check($runid);

        return ['ok' => $after['ok'], 'fixed' => $fixed, 'code' => $after['code']];
    }

    /**
     * Whether the course would let this user in.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user The user.
     * @return bool
     */
    protected static function can_access_course(\stdClass $course, \stdClass $user): bool {
        try {
            return can_access_course($course, $user, '', true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Assemble the verdict.
     *
     * @param bool $ok Whether access holds.
     * @param array[] $checks The individual checks.
     * @param string $code The first failing code.
     * @param string $component For the strings.
     * @return array
     */
    protected static function verdict(bool $ok, array $checks, string $code, string $component): array {
        return [
            'ok'      => $ok,
            'checks'  => $checks,
            'code'    => $code,
            'summary' => $ok
                ? get_string('access:reachable', $component)
                : get_string('access:code:' . $code, $component),
        ];
    }

    /**
     * Build one check row.
     *
     * @param string $id Stable identifier.
     * @param string $label What is checked.
     * @param bool $ok Whether it holds.
     * @param string $detail Extra information, where there is any.
     * @return array
     */
    protected static function check_row(string $id, string $label, bool $ok, string $detail = ''): array {
        return ['id' => $id, 'label' => $label, 'ok' => $ok, 'failed' => !$ok, 'detail' => $detail];
    }
}
