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
 * Run cleanup: reset or remove a run's provisioning residue.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Removes what a run provisioned, bringing it back to a defined state (E2.5).
 *
 * By default it clears the run's lab-store residue (attempts, results and person
 * rows) and deletes the Moodle users the run created, then resets the run to
 * draft. Optionally it also deletes the run's course (only when the suite created
 * it, recognised by the catlab_run_ short name) and the run row itself. It uses
 * only core APIs, is idempotent, and never touches courses it did not create, so
 * a referenced existing course is left intact.
 */
class run_cleanup {
    /**
     * Clean up a run.
     *
     * @param int $runid The run to clean up.
     * @param array $options Optional: 'users' (default true) delete linked users,
     *                       'course' (default false) delete a suite-created course,
     *                       'run' (default false) delete the run row too.
     * @return array{attempts: int, results: int, persons: int, users: int, course: int, run: bool} What was removed.
     */
    public static function cleanup(int $runid, array $options = []): array {
        global $DB;

        $deleteusers = $options['users'] ?? true;
        $deletecourse = $options['course'] ?? false;
        $deleterun = $options['run'] ?? false;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);

        $persons = $DB->get_records('local_catquizlab_person', ['runid' => $runid]);
        $userids = [];
        foreach ($persons as $person) {
            if (!empty($person->moodleuserid)) {
                $userids[] = (int) $person->moodleuserid;
            }
        }

        $counts = [
            'attempts' => $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]),
            'results'  => $DB->count_records('local_catquizlab_result', ['runid' => $runid]),
            'persons'  => count($persons),
            'users'    => 0,
            'course'   => 0,
            'run'      => false,
        ];

        $DB->delete_records('local_catquizlab_attempt', ['runid' => $runid]);
        $DB->delete_records('local_catquizlab_result', ['runid' => $runid]);
        $DB->delete_records('local_catquizlab_person', ['runid' => $runid]);

        if ($deleteusers) {
            $counts['users'] = self::delete_users($userids);
        }
        if ($deletecourse) {
            $counts['course'] = self::maybe_delete_course($run);
        }

        if ($deleterun) {
            $DB->delete_records('local_catquizlab_run', ['id' => $runid]);
            $counts['run'] = true;
        } else {
            self::reset_run($runid, $counts['course'] > 0);
        }

        return $counts;
    }

    /**
     * Delete the given (still-live) Moodle users.
     *
     * @param int[] $userids The user ids.
     * @return int The number of users deleted.
     */
    protected static function delete_users(array $userids): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $deleted = 0;
        foreach ($userids as $userid) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
            if ($user) {
                delete_user($user);
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Delete the run's course, but only if the suite created it.
     *
     * @param \stdClass $run The run record.
     * @return int The deleted course id, or 0 if nothing was deleted.
     */
    protected static function maybe_delete_course(\stdClass $run): int {
        global $DB, $CFG;

        if (empty($run->courseid)) {
            return 0;
        }
        $course = $DB->get_record('course', ['id' => $run->courseid]);
        if (!$course || strpos((string) $course->shortname, 'catlab_run_') !== 0) {
            return 0;
        }

        require_once($CFG->dirroot . '/course/lib.php');
        delete_course($course, false);

        return (int) $run->courseid;
    }

    /**
     * Reset the run to draft after a cleanup that keeps the run row.
     *
     * @param int $runid The run id.
     * @param bool $coursecleared Whether the course was deleted (clear courseid then).
     * @return void
     */
    protected static function reset_run(int $runid, bool $coursecleared): void {
        global $DB;

        $update = (object) [
            'id'           => $runid,
            'status'       => registry::STATUS_DRAFT,
            'timemodified' => time(),
        ];
        if ($coursecleared) {
            $update->courseid = null;
        }
        $DB->update_record('local_catquizlab_run', $update);
    }
}
