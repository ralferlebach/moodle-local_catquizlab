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
 * What of the engine and its host belongs to a run alone, and what of others shares an id with it (#161).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * A run's footprint in the CAT engine (local_catquiz), its host activity (mod_adaptivequiz) and Moodle, by id.
 *
 * **Owned** is what the run made and nobody else uses, found from the lab's own record and never by number alone:
 * - its activity (the run's testcmid) with its attempts and their question usages;
 * - the simulated people's CAT attempts, progress, abilities and traces, found by their accounts (each run creates its
 *   own; an account is the run's when its user name carries the run, or when the run's person row names a deleted
 *   one);
 * - the engine context, scales, items and item parameters of the run's scale map, the test settings of its activity;
 * - the questions it generated (the lab's item record).
 *
 * **At risk** are rows of others that share a number with the run's: a CAT attempt, a progress or an ability row of
 * somebody else whose attempt id equals one of the run's host or CAT attempt ids, or whose own id does. Two id spaces
 * meet there (adaptivequiz_attempt.id and local_catquiz_attempts.id), and a deletion that takes the one for the other
 * hits exactly these (adaptivequizcatmodel_catquiz #11). They are recorded before a deep deletion and must all be
 * there after it.
 *
 * Read only. The check after a deletion compares by id: what of the owned is still there, what of the at-risk is gone.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_footprint {
    /** @var string[] How local_catquiz_attempts.component names the activity, in both spellings. */
    public const COMPONENTS = ['mod_adaptivequiz', 'adaptivequiz'];

    /** @var int Ids per IN list. */
    protected const CHUNK = 500;

    /**
     * The footprint of a run.
     *
     * @param int $runid The run.
     * @return array{runid: int, users: int[], cmid: int, instance: int, contexts: int[], owned: array<string, int[]>,
     *     atrisk: array<string, int[]>}
     */
    public static function of_run(int $runid): array {
        global $DB;

        $dbman = $DB->get_manager();
        $has = static fn(string $table): bool => $dbman->table_exists($table);
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], 'id, testcmid');
        $out = ['runid' => $runid, 'users' => self::users($runid), 'cmid' => (int) ($run->testcmid ?? 0), 'instance' => 0,
            'contexts' => [], 'owned' => [], 'atrisk' => []];

        // The activity and its attempts.
        if ($out['cmid'] > 0 && $has('adaptivequiz')) {
            $cm = $DB->get_record_sql(
                'SELECT cm.id, cm.instance FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
                  WHERE cm.id = ? AND m.name = ?',
                [$out['cmid'], 'adaptivequiz']
            );
            if ($cm) {
                $out['instance'] = (int) $cm->instance;
                $out['owned']['course_modules'] = [(int) $cm->id];
                $out['owned']['adaptivequiz'] = $DB->record_exists('adaptivequiz', ['id' => $cm->instance])
                    ? [(int) $cm->instance] : [];
            }
        }
        $host = $out['instance'] > 0 && $has('adaptivequiz_attempt')
            ? self::ids('adaptivequiz_attempt', 'instance = ?', [$out['instance']]) : [];
        $out['owned']['adaptivequiz_attempt'] = $host;
        $usages = [];
        foreach (array_chunk($host, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $usages = array_merge($usages, $DB->get_fieldset_select(
                'adaptivequiz_attempt',
                'uniqueid',
                "id $insql AND uniqueid > 0",
                $params
            ));
        }
        $out['owned']['question_usages'] = array_values(array_unique(array_map('intval', $usages)));

        // The simulated people's engine rows, by their accounts; the activity's CAT attempts by the activity too.
        $users = $out['users'];
        $cat = [];
        if ($has('local_catquiz_attempts')) {
            $cat = self::by_users('local_catquiz_attempts', $users);
            if ($out['instance'] > 0) {
                [$compsql, $compparams] = $DB->get_in_or_equal(self::COMPONENTS);
                $cat = array_merge($cat, self::ids(
                    'local_catquiz_attempts',
                    "instanceid = ? AND component $compsql",
                    array_merge([$out['instance']], $compparams)
                ));
            }
            $cat = array_values(array_unique($cat));
            sort($cat);
        }
        $out['owned']['local_catquiz_attempts'] = $cat;
        foreach (['local_catquiz_progress', 'local_catquiz_personparams'] as $table) {
            $out['owned'][$table] = $has($table) ? self::by_users($table, $users) : [];
        }
        $out['owned']['local_catquiz_trace'] = $has('local_catquiz_trace') && $host !== []
            ? self::ids_in('local_catquiz_trace', 'adaptiveattemptid', $host) : [];

        // What the run provisioned in the engine, from its scale map.
        $map = $DB->get_records('local_catquizlab_scalemap', ['runid' => $runid], '', 'id, catscaleid, contextid');
        $scales = array_values(array_unique(array_filter(array_map(static fn($m): int => (int) $m->catscaleid, $map))));
        $out['contexts'] = array_values(array_unique(array_filter(array_map(static fn($m): int => (int) $m->contextid, $map))));
        $out['owned']['local_catquiz_catscales'] = $has('local_catquiz_catscales')
            ? self::ids_in('local_catquiz_catscales', 'id', $scales) : [];
        $out['owned']['local_catquiz_catcontext'] = $has('local_catquiz_catcontext')
            ? self::ids_in('local_catquiz_catcontext', 'id', $out['contexts']) : [];
        $items = $has('local_catquiz_items') ? self::ids_in('local_catquiz_items', 'catscaleid', $scales) : [];
        $out['owned']['local_catquiz_items'] = $items;
        $out['owned']['local_catquiz_itemparams'] = $has('local_catquiz_itemparams') && $items !== []
            ? self::ids_in('local_catquiz_itemparams', 'itemid', $items) : [];
        $out['owned']['local_catquiz_tests'] = $has('local_catquiz_tests') && $out['instance'] > 0
            ? self::ids('local_catquiz_tests', 'componentid = ? AND component = ?', [$out['instance'], 'mod_adaptivequiz']) : [];
        $questions = $has('local_catquizlab_item')
            ? $DB->get_fieldset_select('local_catquizlab_item', 'questionid', 'runid = ? AND questionid > 0', [$runid]) : [];
        $out['owned']['question'] = self::ids_in('question', 'id', array_map('intval', $questions));

        // Others' rows that share a number with the run's attempts, in either id space.
        $numbers = array_values(array_unique(array_merge($host, $cat)));
        if ($numbers !== []) {
            foreach (['local_catquiz_progress', 'local_catquiz_personparams'] as $table) {
                $out['atrisk'][$table] = $has($table) ? self::foreign($table, 'attemptid', $numbers, $users) : [];
            }
            if ($has('local_catquiz_attempts')) {
                $out['atrisk']['local_catquiz_attempts'] = array_values(array_unique(array_merge(
                    self::foreign('local_catquiz_attempts', 'id', $host, $users),
                    self::foreign('local_catquiz_attempts', 'attemptid', $cat, $users)
                )));
            }
            if ($has('adaptivequiz_attempt') && $cat !== []) {
                $out['atrisk']['adaptivequiz_attempt'] = array_values(array_diff(
                    self::ids_in('adaptivequiz_attempt', 'id', $cat),
                    $host
                ));
            }
        }
        foreach ($out['atrisk'] as $table => $ids) {
            sort($ids);
            $out['atrisk'][$table] = $ids;
        }

        return $out;
    }

    /**
     * What a deletion left of a footprint taken before it, and what of others it took.
     *
     * @param array $before From of_run(), taken before the deletion.
     * @param string[] $kept Owned tables not expected to be gone (a reset keeps the activity's settings, say).
     * @return array{left: array<string, int[]>, lost: array<string, int[]>, ok: bool}
     */
    public static function check(array $before, array $kept = []): array {
        $left = [];
        foreach ($before['owned'] as $table => $ids) {
            if ($ids === [] || in_array($table, $kept, true)) {
                continue;
            }
            $still = self::ids_in($table, 'id', $ids);
            if ($still !== []) {
                $left[$table] = $still;
            }
        }
        $lost = [];
        foreach ($before['atrisk'] as $table => $ids) {
            if ($ids === []) {
                continue;
            }
            $gone = array_values(array_diff($ids, self::ids_in($table, 'id', $ids)));
            if ($gone !== []) {
                $lost[$table] = $gone;
            }
        }

        return ['left' => $left, 'lost' => $lost, 'ok' => $left === [] && $lost === []];
    }

    /**
     * How many of each, for a preview or a report.
     *
     * @param array $bytable Table => ids (int[]).
     * @return array<string, int>
     */
    public static function counts(array $bytable): array {
        return array_filter(array_map('count', $bytable));
    }

    /**
     * A footprint's or a check's ids as one line: "table n (first ids …)".
     *
     * @param array $bytable Table => ids (int[]).
     * @return string
     */
    public static function line(array $bytable): string {
        $parts = [];
        foreach ($bytable as $table => $ids) {
            if ($ids !== []) {
                $parts[] = $table . ' ' . count($ids) . ' (' . implode(',', array_slice($ids, 0, 5))
                    . (count($ids) > 5 ? ',…' : '') . ')';
            }
        }

        return implode('; ', $parts);
    }

    /**
     * The run's simulated accounts: named with the run, or deleted after being the run's.
     *
     * @param int $runid The run.
     * @return int[]
     */
    public static function users(int $runid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT DISTINCT u.id, u.username, u.deleted
               FROM {local_catquizlab_person} p
               JOIN {user} u ON u.id = p.moodleuserid
              WHERE p.runid = ? AND p.moodleuserid > 0',
            [$runid]
        );
        $ids = [];
        foreach ($rows as $row) {
            // The lab stamps the run into the accounts it creates; a deleted account loses its name, and the run's
            // person row is what still says whose it was.
            if ((int) $row->deleted === 1 || str_contains((string) $row->username, '_r' . $runid . '_')) {
                $ids[] = (int) $row->id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Ids of a table by a condition.
     *
     * @param string $table The table.
     * @param string $select The condition.
     * @param array $params Its parameters.
     * @return int[]
     */
    protected static function ids(string $table, string $select, array $params): array {
        global $DB;

        $ids = array_map('intval', $DB->get_fieldset_select($table, 'id', $select, $params));
        sort($ids);

        return $ids;
    }

    /**
     * Ids of the rows whose column holds one of the values.
     *
     * @param string $table The table.
     * @param string $column The column.
     * @param int[] $values The values.
     * @return int[]
     */
    public static function ids_in(string $table, string $column, array $values): array {
        global $DB;

        if ($values === [] || !$DB->get_manager()->table_exists($table)) {
            return [];
        }
        $ids = [];
        foreach (array_chunk(array_values(array_unique($values)), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $ids = array_merge($ids, $DB->get_fieldset_select($table, 'id', "$column $insql", $params));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * Rows of the accounts.
     *
     * @param string $table A table with a userid.
     * @param int[] $users The accounts.
     * @return int[]
     */
    protected static function by_users(string $table, array $users): array {
        return self::ids_in($table, 'userid', $users);
    }

    /**
     * Others' rows whose column holds one of the numbers.
     *
     * @param string $table A table with a userid.
     * @param string $column The column.
     * @param int[] $numbers The numbers.
     * @param int[] $users The run's accounts, which are not others.
     * @return int[]
     */
    protected static function foreign(string $table, string $column, array $numbers, array $users): array {
        global $DB;

        $ids = [];
        foreach (array_chunk(array_values(array_unique($numbers)), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'n');
            $select = "$column $insql";
            if ($users !== []) {
                [$usersql, $userparams] = $DB->get_in_or_equal($users, SQL_PARAMS_NAMED, 'u', false);
                $select .= " AND userid $usersql";
                $params += $userparams;
            }
            $ids = array_merge($ids, $DB->get_fieldset_select($table, 'id', $select, $params));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
