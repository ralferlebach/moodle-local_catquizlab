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
 * Privacy provider for local_catquizlab.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use context;
use context_system;

/**
 * Privacy provider.
 *
 * The lab store links a simulated person's ground-truth ability profile to the
 * Moodle user provisioned to embody it (local_catquizlab_person.moodleuserid).
 * That link makes the row personal data, so the plugin declares it and answers
 * export/delete requests at the system context.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data the plugin stores.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_catquizlab_person',
            [
                'runid'         => 'privacy:metadata:local_catquizlab_person:runid',
                'moodleuserid'  => 'privacy:metadata:local_catquizlab_person:moodleuserid',
                'stratum'       => 'privacy:metadata:local_catquizlab_person:stratum',
                'abilityglobal' => 'privacy:metadata:local_catquizlab_person:abilityglobal',
                'profilejson'   => 'privacy:metadata:local_catquizlab_person:profilejson',
            ],
            'privacy:metadata:local_catquizlab_person'
        );
        return $collection;
    }

    /**
     * Return the contexts that contain data for the given user.
     *
     * @param int $userid The user to search.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if ($DB->record_exists('local_catquizlab_person', ['moodleuserid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Return the users who have data in the given (system) context.
     *
     * @param userlist $userlist The userlist to add matching users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof context_system) {
            return;
        }

        $userids = $DB->get_fieldset_select(
            'local_catquizlab_person',
            'DISTINCT moodleuserid',
            'moodleuserid IS NOT NULL'
        );
        if ($userids) {
            $userlist->add_users($userids);
        }
    }

    /**
     * Export all lab-store data for the approved contexts of a user.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!self::has_system_context($contextlist->get_contexts())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $rows = $DB->get_records('local_catquizlab_person', ['moodleuserid' => $userid]);
        if (!$rows) {
            return;
        }

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'runid'         => $row->runid,
                'stratum'       => $row->stratum,
                'abilityglobal' => $row->abilityglobal,
                'profile'       => $row->profilejson,
            ];
        }

        writer::with_context(context_system::instance())->export_data(
            [get_string('pluginname', 'local_catquizlab')],
            (object) ['persons' => $data]
        );
    }

    /**
     * Delete all lab-store person data in the given (system) context.
     *
     * The synthetic person rows are throwaway experiment fixtures, so the row is
     * removed outright rather than anonymised.
     *
     * @param context $context The context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if ($context instanceof context_system) {
            $DB->delete_records_select('local_catquizlab_person', 'moodleuserid IS NOT NULL');
        }
    }

    /**
     * Delete a user's lab-store person data across their approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete in.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (!self::has_system_context($contextlist->get_contexts())) {
            return;
        }
        $DB->delete_records('local_catquizlab_person', [
            'moodleuserid' => $contextlist->get_user()->id,
        ]);
    }

    /**
     * Delete the data of the approved users in the given (system) context.
     *
     * @param approved_userlist $userlist The approved users to delete.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_catquizlab_person', "moodleuserid $insql", $params);
    }

    /**
     * Whether the given context list contains the system context.
     *
     * @param context[] $contexts The contexts to check.
     * @return bool
     */
    protected static function has_system_context(array $contexts): bool {
        foreach ($contexts as $context) {
            if ($context instanceof context_system) {
                return true;
            }
        }
        return false;
    }
}
