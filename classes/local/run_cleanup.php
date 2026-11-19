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
 * it under the old per-run model, recognised by the catlab_run_ short name, and
 * only when no other run shares it) and the run row itself. It uses
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
     * @return array{attempts: int, results: int, persons: int, users: int, course: int, items: int, run: bool} What was removed.
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
            'items'    => 0,
            'run'      => false,
        ];

        // Engine-side artefacts materialised for this run, first and checked (#161 AK3): the lab rows below are what
        // leads to them, so a failing or incomplete engine deletion throws purge_incomplete before any of them goes.
        $counts['items'] = self::teardown_engine_artifacts($runid);

        $DB->delete_records('local_catquizlab_attemptlog', ['runid' => $runid]);
        $DB->delete_records('local_catquizlab_attempt', ['runid' => $runid]);
        $DB->delete_records('local_catquizlab_result', ['runid' => $runid]);
        // The twins' claims go with their persons: left behind they were orphans once the run went (220 found by the
        // acceptance inventory), and with the run kept they stopped the next provisioning from storing the persons.
        $DB->delete_records('local_catquizlab_twinclaim', ['runid' => $runid]);
        $DB->delete_records('local_catquizlab_person', ['runid' => $runid]);
        // The scale map is a lab-store table and is always safe to remove.
        $DB->delete_records('local_catquizlab_scalemap', ['runid' => $runid]);

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
     * Remove the engine-side artefacts a run materialised, as a deep deletion and a reset do (#161 AK3).
     *
     * The same steps and the same check: the run's footprint in the engine, its activity and Moodle is taken first;
     * purger::delete_engine_objects() deletes the activity through Moodle's own module deletion
     * (core_compat::delete_module(): course_delete_module(), from 5.2 the course format's action; stopping at once
     * if that fails), the items' parameters, the items, the scales, the questions, the abilities of the run's own
     * accounts and its emptied engine contexts; then nothing of the footprint may be left and no row of others
     * lost. Until 0.7.62 this path caught a failing activity deletion and went on, and left questions and abilities
     * behind.
     *
     * @param int $runid The run.
     * @return int The number of engine items removed.
     * @throws purge_incomplete When the activity could not be deleted, or something is left or lost.
     */
    protected static function teardown_engine_artifacts(int $runid): int {
        $before = engine_footprint::of_run($runid);
        $removed = purger::delete_engine_objects($runid);
        $check = engine_footprint::check($before);
        if (!$check['ok']) {
            throw new purge_incomplete($runid, $check);
        }
        return (int) ($removed['engineitems'] ?? 0);
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
     * Delete a legacy per-run course, if this run still has one.
     *
     * Since issue #8 runs share one configured course, and a shared course must
     * never be deleted because one of its runs was cleaned up — that would take
     * every other experiment in it down too. Only a course the suite created
     * under the old per-run model is removed, recognised by its short name, and
     * only when no other run still points at it.
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

        // The configured experiment course is never a cleanup target, whatever
        // it happens to be called.
        if ((int) $course->id === experiment_container::configured_course()) {
            return 0;
        }

        // Another run sharing it means it is not this run's to delete.
        $others = $DB->count_records_select(
            'local_catquizlab_run',
            'courseid = :courseid AND id <> :runid',
            ['courseid' => (int) $course->id, 'runid' => (int) $run->id]
        );
        if ($others > 0) {
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
        run_lifecycle::update_run($update, 'reset_run');
    }
}
