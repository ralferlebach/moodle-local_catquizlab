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
 * One run, one scale tree — found, named, and where necessary repaired.
 *
 * Provisioning used to build a fresh tree on every call, so a retried task or a
 * second "provision now" left a run owning several complete generations. 0.6.19
 * stopped new ones appearing; it did nothing for installations that already had
 * them, where a run could own a dozen roots and contexts.
 *
 * The symptom arrived late and unhelpfully: `get_record() found more than one
 * record!` from `root_scale()`, at the test stage, long after the second tree
 * was created. An ambiguity should be reported where it is, not where it
 * happens to be noticed.
 *
 * Which tree is the real one is decided the same way every time: the newest, by
 * the map row that created it. The older ones are generations that were already
 * abandoned when the next was built — nothing has used them since.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scale_inventory {
    /**
     * Every scale generation a run owns, newest first.
     *
     * @param int $runid The run.
     * @return array[] Each with rootscaleid, contextid, nodes and current.
     */
    public static function generations(int $runid): array {
        global $DB;

        $roots = $DB->get_records(
            'local_catquizlab_scalemap',
            ['runid' => $runid, 'level' => scale_provisioner::LEVEL_ROOT],
            'id DESC'
        );

        $generations = [];
        $first = true;
        foreach ($roots as $root) {
            $contextid = (int) $root->contextid;

            $generations[] = [
                'mapid'       => (int) $root->id,
                'rootscaleid' => (int) $root->catscaleid,
                'contextid'   => $contextid,
                'nodes'       => $DB->count_records('local_catquizlab_scalemap', [
                    'runid'     => $runid,
                    'contextid' => $contextid,
                ]),
                'items'       => self::count_engine_items($runid, $contextid),
                // The newest is the one everything provisioned last points at.
                'current'     => $first,
                'stale'       => !$first,
            ];
            $first = false;
        }

        return $generations;
    }

    /**
     * Whether this run owns more trees than it should.
     *
     * @param int $runid The run.
     * @return bool
     */
    public static function is_ambiguous(int $runid): bool {
        return count(self::generations($runid)) > 1;
    }

    /**
     * The root scale of a run, when there is exactly one.
     *
     * Several is not a question with a best answer. Picking the newest looks
     * reasonable and is a guess: the run's items were materialised into one of
     * them, and which one is not knowable from the map alone. So a run in that
     * state does not get a root — it gets recovery.
     *
     * @param int $runid The run.
     * @return int|null
     */
    public static function current_root(int $runid): ?int {
        $generations = self::generations($runid);

        if (count($generations) !== 1) {
            return null;
        }

        return (int) $generations[0]['rootscaleid'];
    }

    /**
     * The newest generation's root, for inventory and cleanup only.
     *
     * Deliberately separate from current_root(): this is what the cleanup keeps,
     * not what a test may be built on.
     *
     * @param int $runid The run.
     * @return int|null
     */
    public static function newest_root(int $runid): ?int {
        $generations = self::generations($runid);

        return $generations === [] ? null : (int) $generations[0]['rootscaleid'];
    }

    /**
     * Whether this run needs its scale tree repaired before it can run.
     *
     * @param int $runid The run.
     * @return bool
     */
    public static function recovery_required(int $runid): bool {
        return count(self::generations($runid)) > 1;
    }

    /**
     * What cleaning up would remove, before it removes it.
     *
     * @param int $runid The run.
     * @return array{ok: bool, keep: array|null, remove: array[], totals: array}
     */
    public static function preview_cleanup(int $runid): array {
        $generations = self::generations($runid);
        if (count($generations) < 2) {
            return ['ok' => false, 'keep' => $generations[0] ?? null, 'remove' => [], 'totals' => []];
        }

        $remove = array_slice($generations, 1);
        $totals = ['generations' => count($remove), 'nodes' => 0, 'items' => 0];
        foreach ($remove as $generation) {
            $totals['nodes'] += (int) $generation['nodes'];
            $totals['items'] += (int) $generation['items'];
        }

        return ['ok' => true, 'keep' => $generations[0], 'remove' => $remove, 'totals' => $totals];
    }

    /**
     * Remove every generation but the newest.
     *
     * The engine rows go with them: a scale nobody points at is worse than no
     * scale, because a selection that finds it will draw items from a tree the
     * run abandoned.
     *
     * @param int $runid The run.
     * @return array{ok: bool, removed: array, reason: string}
     */
    public static function cleanup(int $runid): array {
        global $DB;

        $preview = self::preview_cleanup($runid);
        if (!$preview['ok']) {
            return ['ok' => false, 'removed' => [], 'reason' => 'nothing-to-clean'];
        }

        // A worker mid-attempt is reading from one of these trees, and which
        // one is not worth guessing — but "has open attempts" is not the same
        // question. A run in this state hands out no work, so its claimed
        // attempts can never finish: refusing on their account made the repair
        // impossible for exactly the runs that need it.
        //
        // What matters is whether a worker is alive and holding one.
        $held = $DB->count_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status = :running AND leaseexpires > :now',
            [
                'runid'   => $runid,
                'running' => attempt_scheduler::STATUS_RUNNING,
                'now'     => time(),
            ]
        );

        if ($held > 0) {
            return ['ok' => false, 'removed' => [], 'reason' => 'run-is-being-played'];
        }

        // Claims nobody is holding go back, so the repair leaves a queue that
        // can be played once the tree is sound again.
        attempt_scheduler::reclaim_stale($runid, 0);

        $removed = ['generations' => 0, 'nodes' => 0, 'engineitems' => 0, 'enginescales' => 0];

        foreach ($preview['remove'] as $generation) {
            $contextid = (int) $generation['contextid'];

            $scaleids = $DB->get_fieldset_select(
                'local_catquizlab_scalemap',
                'catscaleid',
                'runid = ? AND contextid = ?',
                [$runid, $contextid]
            );
            $scaleids = array_values(array_filter(array_map('intval', $scaleids)));

            if ($scaleids !== [] && $DB->get_manager()->table_exists('local_catquiz_items')) {
                [$insql, $params] = $DB->get_in_or_equal($scaleids, SQL_PARAMS_NAMED, 'sc');
                $removed['engineitems'] += $DB->count_records_select(
                    'local_catquiz_items',
                    'catscaleid ' . $insql,
                    $params
                );
                $DB->delete_records_select('local_catquiz_items', 'catscaleid ' . $insql, $params);

                if ($DB->get_manager()->table_exists('local_catquiz_catscales')) {
                    $DB->delete_records_select('local_catquiz_catscales', 'id ' . $insql, $params);
                    $removed['enginescales'] += count($scaleids);
                }
            }

            $removed['nodes'] += $DB->count_records('local_catquizlab_scalemap', [
                'runid' => $runid, 'contextid' => $contextid,
            ]);
            $DB->delete_records('local_catquizlab_scalemap', ['runid' => $runid, 'contextid' => $contextid]);
            $removed['generations']++;
        }

        run_log::record($runid, run_log::RESET, [
            'reason' => 'scale duplicates cleaned',
            'generations' => $removed['generations'],
        ]);

        return ['ok' => true, 'removed' => array_filter($removed), 'reason' => ''];
    }

    /**
     * Every run that owns more than one scale tree.
     *
     * @return array[] Each with runid, cellkey and how many generations.
     */
    public static function affected_runs(): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT m.runid, COUNT(1) AS generations
               FROM {local_catquizlab_scalemap} m
              WHERE m.level = :level
           GROUP BY m.runid
             HAVING COUNT(1) > 1',
            ['level' => scale_provisioner::LEVEL_ROOT]
        );

        $affected = [];
        foreach ($rows as $row) {
            $affected[] = [
                'runid'       => (int) $row->runid,
                'cellkey'     => (string) $DB->get_field(
                    'local_catquizlab_run',
                    'cellkey',
                    ['id' => (int) $row->runid]
                ),
                'generations' => (int) $row->generations,
            ];
        }

        return $affected;
    }

    /**
     * How many engine items hang off a run's generation.
     *
     * @param int $runid The run.
     * @param int $contextid The CAT context of that generation.
     * @return int
     */
    protected static function count_engine_items(int $runid, int $contextid): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquiz_items')) {
            return 0;
        }

        $scaleids = $DB->get_fieldset_select(
            'local_catquizlab_scalemap',
            'catscaleid',
            'runid = ? AND contextid = ?',
            [$runid, $contextid]
        );
        $scaleids = array_values(array_filter(array_map('intval', $scaleids)));
        if ($scaleids === []) {
            return 0;
        }

        [$insql, $params] = $DB->get_in_or_equal($scaleids, SQL_PARAMS_NAMED, 'sc');

        return (int) $DB->count_records_select('local_catquiz_items', 'catscaleid ' . $insql, $params);
    }
}
