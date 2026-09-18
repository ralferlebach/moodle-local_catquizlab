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
 * Engine attempts that were created but never given a first question.
 *
 * When the CAT selection fails before item one, `mod_adaptivequiz` throws — and
 * the attempt row it had already written stays behind, `inprogress` with
 * `uniqueid = 0`, no stop reason and no finish time. It is not a running
 * attempt and it is not a finished one; it is a record of a start that did not
 * happen.
 *
 * One of those is a curiosity. The lab produces them by the hundred, because
 * every retry of a failing attempt makes another, so they need clearing up
 * rather than explaining. They distort attempt counts, they can be found again
 * by resume paths, and where an activity limits the number of attempts they can
 * consume that allowance.
 *
 * The right fix belongs upstream and is drafted in
 * `docs/design/issue-adaptivequiz-empty-attempt.md`: an attempt that fails
 * before its first question should be rolled back or explicitly closed, not
 * left open. Until then this class clears up after it.
 *
 * Scope is deliberately narrow. Only attempts belonging to this lab's simulated
 * persons are touched, and only those with no question usage at all. A row with
 * a `uniqueid` has answers attached and is somebody's data, whatever state it
 * is in.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_hygiene {
    /**
     * Count the empty engine attempts left behind by lab persons.
     *
     * @return int
     */
    public static function count_empty_attempts(): int {
        global $DB;

        if (!self::table_available()) {
            return 0;
        }

        return (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {adaptivequiz_attempt} aa
               JOIN {local_catquizlab_person} p ON p.moodleuserid = aa.userid
              WHERE aa.uniqueid = 0 AND aa.attemptstate = 'inprogress'"
        );
    }

    /**
     * The empty attempts, with the run they belong to.
     *
     * @param int $limit How many to return.
     * @return array[]
     */
    public static function list_empty_attempts(int $limit = 20): array {
        global $DB;

        if (!self::table_available()) {
            return [];
        }

        $rows = $DB->get_records_sql(
            "SELECT aa.id, aa.userid, aa.instance, aa.timemodified, p.runid, p.twinid
               FROM {adaptivequiz_attempt} aa
               JOIN {local_catquizlab_person} p ON p.moodleuserid = aa.userid
              WHERE aa.uniqueid = 0 AND aa.attemptstate = 'inprogress'
           ORDER BY aa.timemodified DESC",
            [],
            0,
            $limit
        );

        return array_values(array_map(static function (\stdClass $row): array {
            return [
                'attemptid' => (int) $row->id,
                'runid'     => (int) $row->runid,
                'twinid'    => (string) $row->twinid,
                'since'     => duration::human(time() - (int) $row->timemodified),
            ];
        }, $rows));
    }

    /**
     * Remove the empty engine attempts of this lab's persons.
     *
     * @return int How many were removed.
     */
    public static function purge_empty_attempts(): int {
        global $DB;

        if (!self::table_available()) {
            return 0;
        }

        $ids = $DB->get_fieldset_sql(
            "SELECT aa.id
               FROM {adaptivequiz_attempt} aa
               JOIN {local_catquizlab_person} p ON p.moodleuserid = aa.userid
              WHERE aa.uniqueid = 0 AND aa.attemptstate = 'inprogress'"
        );

        if ($ids === []) {
            return 0;
        }

        // Deleted rather than closed. A closed empty attempt still counts as an
        // attempt wherever attempts are counted, and it carries no information
        // worth keeping: the reason the start failed is on the lab attempt,
        // where the worker put it.
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'id');
        $DB->delete_records_select('adaptivequiz_attempt', 'id ' . $insql, $params);

        return count($ids);
    }

    /**
     * Whether the engine's attempt table is here to work with.
     *
     * @return bool
     */
    protected static function table_available(): bool {
        global $DB;

        return $DB->get_manager()->table_exists('adaptivequiz_attempt')
            && $DB->get_manager()->table_exists('local_catquizlab_person');
    }
}
