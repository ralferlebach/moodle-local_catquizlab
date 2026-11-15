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
 * Engine rows without an owner, counted and dated, never changed (#161 AK6).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * A read-only inventory of the CAT engine's rows that have lost what they belong to.
 *
 * For the operator, not for the lab: whether and how such rows are removed is the site's decision and the engine's
 * business. Each kind is counted with its oldest and newest time and, where the table says, the component. "Of lab
 * runs" counts the rows the lab's own records still assign to an existing run (its simulated accounts); a row is never
 * assigned to a run that is gone.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_inventory {
    /**
     * The kinds of rows without an owner.
     *
     * @return array<string, array{count: int, oldest: ?int, newest: ?int, components: array<string, int>, oflabruns: int}>
     */
    public static function orphans(): array {
        global $DB;

        $dbman = $DB->get_manager();
        $has = static fn(string ...$tables): bool => array_reduce($tables, static fn(bool $ok, string $t): bool => $ok
            && $dbman->table_exists($t), true);
        [$compsql, $compparams] = $DB->get_in_or_equal(engine_footprint::COMPONENTS, SQL_PARAMS_NAMED, 'comp');
        $kinds = [];
        if ($has('local_catquiz_progress', 'local_catquiz_attempts')) {
            // The progress names its CAT attempt (local_catquiz_attempts.id) in the engine's current contract.
            $kinds['progress without its CAT attempt'] = ['local_catquiz_progress', 'p',
                'LEFT JOIN {local_catquiz_attempts} a ON a.id = p.attemptid', 'a.id IS NULL', [], 'p.timemodified', 'p.component'];
        }
        if ($has('local_catquiz_progress', 'local_catquiz_attempts', 'adaptivequiz_attempt')) {
            // Of those, the ones whose number is an activity attempt's: written under the earlier contract, or by a
            // deletion that took the one id for the other — which, cannot be told from the row.
            $kinds['progress without CAT attempt, numbered like an activity attempt'] = ['local_catquiz_progress', 'p',
                'LEFT JOIN {local_catquiz_attempts} a ON a.id = p.attemptid JOIN {adaptivequiz_attempt} h ON h.id = p.attemptid',
                'a.id IS NULL', [], 'p.timemodified', 'p.component'];
        }
        if ($has('local_catquiz_attempts', 'adaptivequiz_attempt')) {
            $kinds['CAT attempts without their activity attempt'] = ['local_catquiz_attempts', 'a',
                'LEFT JOIN {adaptivequiz_attempt} h ON h.id = a.attemptid', "h.id IS NULL AND a.component $compsql",
                $compparams, 'a.timemodified', 'a.component'];
        }
        if ($has('local_catquiz_personparams', 'local_catquiz_attempts')) {
            $kinds['abilities whose CAT attempt is gone'] = ['local_catquiz_personparams', 'pp',
                'LEFT JOIN {local_catquiz_attempts} a ON a.id = pp.attemptid', 'pp.attemptid > 0 AND a.id IS NULL', [],
                'pp.timemodified', null];
        }
        if ($has('local_catquiz_personparams')) {
            $kinds['abilities of deleted or missing accounts'] = ['local_catquiz_personparams', 'pp',
                'LEFT JOIN {user} u ON u.id = pp.userid', '(u.id IS NULL OR u.deleted = 1)', [], 'pp.timemodified', null];
        }
        if ($has('local_catquiz_itemparams', 'local_catquiz_items')) {
            $kinds['item parameters without their item'] = ['local_catquiz_itemparams', 'ip',
                'LEFT JOIN {local_catquiz_items} i ON i.id = ip.itemid', 'ip.itemid > 0 AND i.id IS NULL', [],
                'ip.timemodified', 'ip.componentname'];
        }
        if ($has('local_catquiz_items', 'local_catquiz_catscales')) {
            $kinds['items without their scale'] = ['local_catquiz_items', 'i',
                'LEFT JOIN {local_catquiz_catscales} s ON s.id = i.catscaleid', 's.id IS NULL', [], null,
                'i.componentname'];
        }
        if ($has('adaptivequiz_attempt', 'adaptivequiz')) {
            $kinds['activity attempts without their activity'] = ['adaptivequiz_attempt', 'h',
                'LEFT JOIN {adaptivequiz} q ON q.id = h.instance', 'q.id IS NULL', [], 'h.timemodified', null];
        }

        $labusers = self::lab_users();
        $out = [];
        foreach ($kinds as $kind => [$table, $alias, $join, $where, $params, $time, $component]) {
            // An item has no time of its own (its lastupdated is a flag): counted, not dated.
            $span = $time === null ? 'NULL AS oldest, NULL AS newest' : "MIN($time) AS oldest, MAX($time) AS newest";
            $row = $DB->get_record_sql(
                "SELECT COUNT(1) AS n, $span
                   FROM {{$table}} $alias $join
                  WHERE $where",
                $params
            );
            $components = [];
            if ($component !== null && (int) $row->n > 0) {
                $components = $DB->get_records_sql_menu(
                    "SELECT COALESCE($component, '') AS c, COUNT(1) FROM {{$table}} $alias $join WHERE $where
                   GROUP BY COALESCE($component, '')",
                    $params
                );
            }
            $oflab = 0;
            $byuser = !in_array($table, ['local_catquiz_items', 'local_catquiz_itemparams'], true);
            if ((int) $row->n > 0 && $labusers !== [] && $byuser) {
                foreach (array_chunk($labusers, 500) as $chunk) {
                    [$usersql, $userparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'lu');
                    $oflab += (int) $DB->count_records_sql(
                        "SELECT COUNT(1) FROM {{$table}} $alias $join WHERE $where AND $alias.userid $usersql",
                        $params + $userparams
                    );
                }
            }
            $out[$kind] = ['count' => (int) $row->n, 'oldest' => $row->oldest === null ? null : (int) $row->oldest,
                'newest' => $row->newest === null ? null : (int) $row->newest,
                'components' => array_map('intval', $components), 'oflabruns' => $oflab];
        }

        return $out;
    }

    /**
     * The simulated accounts of the lab runs that exist now.
     *
     * @return int[]
     */
    protected static function lab_users(): array {
        global $DB;

        $users = [];
        foreach ($DB->get_fieldset_select('local_catquizlab_run', 'id', '1 = 1', null) as $runid) {
            $users = array_merge($users, engine_footprint::users((int) $runid));
        }

        return array_values(array_unique($users));
    }
}
