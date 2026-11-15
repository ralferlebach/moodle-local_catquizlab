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
 * Core calls whose home moved between Moodle 4.5 and 5.3, in one place.
 *
 * Moodle 5.2 deprecated course_delete_module() for the course format's own
 * action (MDL-86856); Moodle 5.3 deprecated user_create_user() and
 * user_get_users_by_id() for \core\user (MDL-82650). The old functions still
 * work, and every call says so through debugging(): on 5.2 and 5.3 each one
 * was an "Unexpected debugging() call detected" notice in PHPUnit (#139).
 * The plugin supports 4.5 to 5.3, so it asks for the new call where the core
 * it runs on has one and uses the old one only where it does not.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The new core call where there is one, the old one on older Moodle.
 */
final class core_compat {
    /**
     * Create a user: \core\user::create_user() from Moodle 5.3, user_create_user() before.
     *
     * Both check the password policy when a password is given and $updatepassword is true, and both return the id.
     *
     * @param \stdClass $user The user to create.
     * @param bool $updatepassword Whether the authentication plugin sets the password.
     * @param bool $triggerevent Whether \core\event\user_created is triggered.
     * @return int The new user's id.
     */
    public static function create_user(\stdClass $user, bool $updatepassword = true, bool $triggerevent = true): int {
        global $CFG;
        if (method_exists(\core\user::class, 'create_user')) {
            return (int) \core\user::create_user($user, $updatepassword, $triggerevent);
        }
        require_once($CFG->dirroot . '/user/lib.php');
        return (int) user_create_user($user, $updatepassword, $triggerevent);
    }

    /**
     * Users by id: \core\user::get_users_by_id() from Moodle 5.3, user_get_users_by_id() before.
     *
     * @param int[] $userids The ids.
     * @return \stdClass[] The user records, keyed by id; ids without a user are left out.
     */
    public static function users_by_id(array $userids): array {
        global $CFG;
        if ($userids === []) {
            return [];
        }
        if (method_exists(\core\user::class, 'get_users_by_id')) {
            return \core\user::get_users_by_id($userids);
        }
        require_once($CFG->dirroot . '/user/lib.php');
        return user_get_users_by_id($userids);
    }

    /**
     * Delete a course module now, with the activity's own callbacks.
     *
     * From Moodle 5.2 through the course format's action (formatactions::cm()->delete()), before through
     * course_delete_module(). Both delete at once (not in the background), throw when the deletion fails, and do
     * nothing for a module that is not there.
     *
     * @param int $cmid The course module.
     */
    public static function delete_module(int $cmid): void {
        global $CFG, $DB;
        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            $courseid = $DB->get_field('course_modules', 'course', ['id' => $cmid]);
            if ($courseid !== false) {
                \core_courseformat\formatactions::cm((int) $courseid)->delete($cmid);
            }
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        course_delete_module($cmid);
    }
}
