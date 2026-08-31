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

        $DB->delete_records('local_catquizlab_attempt', ['runid' => $runid]);
        $DB->delete_records('local_catquizlab_result', ['runid' => $runid]);
        $DB->delete_records('local_catquizlab_person', ['runid' => $runid]);

        // Engine-side artefacts materialised for this run (guarded; no-op without engine).
        $counts['items'] = self::teardown_engine_artifacts($runid);
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
     * Remove the engine-side artefacts a run materialised: the delete-adaptivequiz
     * instance, then the run's items, item parameters and scale tree/context.
     *
     * Recognised from the run's scale map. Guarded by the engine environment, so it
     * is a no-op (returning 0) when the engine is absent or the run materialised
     * nothing.
     *
     * @param int $runid The run.
     * @return int The number of engine items removed.
     */
    protected static function teardown_engine_artifacts(int $runid): int {
        global $DB;

        if (!environment::engine_available()) {
            return 0;
        }

        $map = $DB->get_records('local_catquizlab_scalemap', ['runid' => $runid]);
        if (!$map) {
            return 0;
        }
        // Delete the adaptivequiz test instance created for this run, if any.
        self::delete_test_module($runid);

        // Remove the run's items and item parameters, then the scale tree/context.
        $contextids = [];
        $items = 0;
        foreach ($map as $node) {
            $contextids[(int) $node->contextid] = true;
            $items += self::delete_scale_items((int) $node->catscaleid, (int) $node->contextid);
            $DB->delete_records('local_catquiz_catscales', ['id' => (int) $node->catscaleid]);
        }
        foreach (array_keys($contextids) as $contextid) {
            if ($contextid > 0 && !$DB->record_exists('local_catquiz_catscales', ['contextid' => $contextid])) {
                $DB->delete_records('local_catquiz_catcontext', ['id' => $contextid]);
            }
        }

        return $items;
    }

    /**
     * Delete the adaptivequiz test module a run created, if present.
     *
     * @param int $runid The run.
     * @return void
     */
    protected static function delete_test_module(int $runid): void {
        global $DB, $CFG;

        $testcmid = (int) $DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $runid]);
        if ($testcmid <= 0 || !environment::adaptivequiz_available()) {
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        try {
            course_delete_module($testcmid);
        } catch (\Throwable $e) {
            debugging('local_catquizlab: could not delete test module: ' . $e->getMessage());
        }
    }

    /**
     * Delete the items and item parameters of a scale within a context.
     *
     * @param int $catscaleid The scale.
     * @param int $contextid The context.
     * @return int The number of item rows removed.
     */
    protected static function delete_scale_items(int $catscaleid, int $contextid): int {
        global $DB;

        $items = $DB->get_records('local_catquiz_items', ['catscaleid' => $catscaleid, 'contextid' => $contextid]);
        foreach ($items as $item) {
            $DB->delete_records(
                'local_catquiz_itemparams',
                ['componentid' => $item->componentid, 'contextid' => $contextid]
            );
        }
        $DB->delete_records('local_catquiz_items', ['catscaleid' => $catscaleid, 'contextid' => $contextid]);
        return count($items);
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
        $DB->update_record('local_catquizlab_run', $update);
    }
}
