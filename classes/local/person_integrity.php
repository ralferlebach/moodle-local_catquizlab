<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Whether a run's people are its twins, each once.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The integrity of a run's population (#116).
 *
 * A run materialises each digital twin once: the same twins across the cells of
 * an experiment are what makes a comparison paired. A twin twice in one run is
 * not a larger population but the same people counted again — and every one of
 * them was given a sitting of its own.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class person_integrity {
    /** @var string Why a stage or a schedule refuses a run with twins more than once. */
    public const REASON_DUPLICATE_TWINS = 'duplicate_twins';

    /** @var string Why the people stage fails when the people are not the planned ones. */
    public const REASON_POPULATION_MISMATCH = 'population_mismatch';

    /**
     * A run's people against its plan.
     *
     * @param int $runid The run.
     * @param int|null $expected The planned number of people, or null to check uniqueness only.
     * @return array{ok: bool, rows: int, distinct: int, untwinned: int, expected: ?int, duplicates: array[]}
     */
    public static function check_run(int $runid, ?int $expected = null): array {
        global $DB;

        $rows = $DB->count_records('local_catquizlab_person', ['runid' => $runid]);
        $untwinned = $DB->count_records_select(
            'local_catquizlab_person',
            "runid = :runid AND (twinid IS NULL OR twinid = '')",
            ['runid' => $runid]
        );
        $distinct = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT twinid) FROM {local_catquizlab_person}
              WHERE runid = :runid AND twinid IS NOT NULL AND twinid <> ''",
            ['runid' => $runid]
        );
        $duplicates = [];
        $groups = $DB->get_records_sql(
            "SELECT twinid, COUNT(1) AS n FROM {local_catquizlab_person}
              WHERE runid = :runid AND twinid IS NOT NULL AND twinid <> ''
           GROUP BY twinid HAVING COUNT(1) > 1 ORDER BY twinid",
            ['runid' => $runid]
        );
        foreach ($groups as $group) {
            $duplicates[] = ['twinid' => (string) $group->twinid, 'rows' => (int) $group->n];
        }
        // People from before twins (no twin id) cannot be checked for doubles;
        // they are counted, and a plan is compared with all rows.
        $ok = $duplicates === []
            && ($expected === null || ($rows === $expected && ($untwinned > 0 || $distinct === $expected)));

        return [
            'ok' => $ok, 'rows' => $rows, 'distinct' => $distinct, 'untwinned' => $untwinned,
            'expected' => $expected, 'duplicates' => $duplicates,
        ];
    }

    /**
     * Every run with a twin more than once, for the operator (#116, POP-004).
     *
     * Nothing is deleted here or anywhere else by the plugin: which rows go is
     * an operator's decision, and their sittings may already hold results.
     *
     * @param int $experimentid Restrict to one experiment, 0 for all.
     * @return array[] Per run: runid, experimentid, groups, extrarows, sittings on the extra rows.
     */
    public static function runs_with_duplicates(int $experimentid = 0): array {
        global $DB;

        $params = [];
        $where = '';
        if ($experimentid > 0) {
            $where = ' AND r.experimentid = :experimentid';
            $params['experimentid'] = $experimentid;
        }
        $rows = $DB->get_records_sql(
            "SELECT p.runid, r.experimentid, COUNT(DISTINCT p.twinid) AS groups, COUNT(1) - COUNT(DISTINCT p.twinid) AS extra
               FROM {local_catquizlab_person} p
               JOIN {local_catquizlab_run} r ON r.id = p.runid
              WHERE p.twinid IS NOT NULL AND p.twinid <> ''{$where}
                AND EXISTS (SELECT 1 FROM {local_catquizlab_person} d
                             WHERE d.runid = p.runid AND d.twinid = p.twinid AND d.id <> p.id)
           GROUP BY p.runid, r.experimentid
           ORDER BY p.runid",
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            // The sittings of the rows beyond the first of each twin.
            $sittings = (int) $DB->count_records_sql(
                "SELECT COUNT(1) FROM {local_catquizlab_attempt} a
                   JOIN {local_catquizlab_person} p ON p.id = a.personid
                  WHERE p.runid = :runid AND p.twinid IS NOT NULL AND p.twinid <> ''
                    AND p.id > (SELECT MIN(f.id) FROM {local_catquizlab_person} f
                                 WHERE f.runid = p.runid AND f.twinid = p.twinid)",
                ['runid' => $row->runid]
            );
            $out[] = [
                'runid' => (int) $row->runid, 'experimentid' => (int) $row->experimentid,
                'groups' => (int) $row->groups, 'extrarows' => (int) $row->extra, 'sittings' => $sittings,
            ];
        }

        return $out;
    }
}
