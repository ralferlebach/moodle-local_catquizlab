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
 * Is this run's scale tree sound — asked in the plugin's own terms.
 *
 * The old failure read:
 *
 *     mdb->get_record() found more than one record!
 *
 * which is a database warning, arriving at the test stage, about a call. What it
 * meant was that run #4 owned several root scales and several CAT contexts — a
 * statement about the run, available long before anything tried to build a test
 * on top of it.
 *
 * So this asks the whole question, not just the part that happened to throw: one
 * root, one context, every mapping in that context, every scale still present in
 * the engine, no duplicate keys, and the node count the blueprint called for.
 * Each check is separate because each has a different answer — a missing
 * subscale and a stray extra one are not the same problem.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scale_health {
    /**
     * Check one run's scale tree.
     *
     * @param int $runid The run.
     * @return array{ok: bool, checks: array[], summary: string, facts: array}
     */
    public static function check(int $runid): array {
        global $DB;

        $component = 'local_catquizlab';

        $rows = $DB->get_records('local_catquizlab_scalemap', ['runid' => $runid], 'level ASC, id ASC');
        if ($rows === []) {
            return [
                'ok'      => false,
                'checks'  => [
                    self::check_row(
                        'provisioned',
                        get_string('scalehealth:provisioned', $component),
                        false,
                        get_string('scalehealth:notprovisioned', $component)
                    ),
                ],
                'summary' => get_string('scalehealth:notprovisioned', $component),
                'facts'   => ['nodes' => 0],
            ];
        }

        $roots = [];
        $contexts = [];
        $scaleids = [];
        $keys = [];
        foreach ($rows as $row) {
            if ((int) $row->level === scale_provisioner::LEVEL_ROOT) {
                $roots[] = (int) $row->catscaleid;
            }
            $contexts[(int) $row->contextid] = true;
            $scaleids[] = (int) $row->catscaleid;
            $keys[] = $row->level . ':' . $row->categoryindex . ':' . $row->subscaleindex;
        }

        $checks = [];

        // One root, one context: the two the old failure was really about.
        $checks[] = self::check_row(
            'oneroot',
            get_string('scalehealth:oneroot', $component),
            count($roots) === 1,
            get_string('scalehealth:rootcount', $component, count($roots))
        );

        $checks[] = self::check_row(
            'onecontext',
            get_string('scalehealth:onecontext', $component),
            count($contexts) === 1,
            get_string('scalehealth:contextcount', $component, count($contexts))
        );

        // Every scale the map names still existing in the engine. A map row
        // pointing at a deleted scale is worse than a missing row: everything
        // downstream materialises into a scale nobody can select from.
        $present = 0;
        if ($DB->get_manager()->table_exists('local_catquiz_catscales') && $scaleids !== []) {
            [$insql, $params] = $DB->get_in_or_equal(array_unique($scaleids), SQL_PARAMS_NAMED, 'sc');
            $present = $DB->count_records_select('local_catquiz_catscales', 'id ' . $insql, $params);
        }
        $expected = count(array_unique($scaleids));
        $checks[] = self::check_row(
            'enginescales',
            get_string('scalehealth:enginescales', $component),
            $present === $expected,
            get_string('scalehealth:enginescalecount', $component, (object) [
                'present'  => $present,
                'expected' => $expected,
            ])
        );

        // Two map rows describing the same logical node: the tree would then
        // depend on which one a query returned.
        $duplicates = count($keys) - count(array_unique($keys));
        $checks[] = self::check_row(
            'uniquekeys',
            get_string('scalehealth:uniquekeys', $component),
            $duplicates === 0,
            $duplicates === 0
                ? ''
                : get_string('scalehealth:duplicatekeys', $component, $duplicates)
        );

        // Parent references that point somewhere. A node whose parent is not in
        // this run's map belongs to a tree the run does not own, and the engine
        // will walk it anyway.
        $known = [];
        foreach ($rows as $row) {
            $known[(int) $row->catscaleid] = true;
        }

        $orphans = [];
        foreach ($rows as $row) {
            $parent = (int) $row->parentcatscaleid;
            if ($parent !== 0 && !isset($known[$parent])) {
                $orphans[] = (string) $row->nodekey;
            }
        }
        $checks[] = self::check_row(
            'parents',
            get_string('scalehealth:parents', $component),
            $orphans === [],
            $orphans === [] ? '' : get_string('scalehealth:orphannodes', $component, implode(', ', $orphans))
        );

        // A cycle makes the tree infinite, and every walk of it a hang rather
        // than an error.
        $checks[] = self::check_row(
            'acyclic',
            get_string('scalehealth:acyclic', $component),
            !self::has_cycle($rows),
            ''
        );

        // The keys the blueprint calls for, present and no others. A missing
        // subscale and a stray extra one are different defects, so they are
        // reported separately.
        $expectedkeys = self::expected_keys($runid);
        if ($expectedkeys !== []) {
            $actualkeys = [];
            foreach ($rows as $row) {
                $actualkeys[] = (string) $row->nodekey;
            }
            $actualkeys = array_unique($actualkeys);

            $missing = array_diff($expectedkeys, $actualkeys);
            $unexpected = array_diff($actualkeys, $expectedkeys);

            $checks[] = self::check_row(
                'expectedkeys',
                get_string('scalehealth:expectedkeys', $component),
                $missing === [],
                $missing === [] ? '' : get_string('scalehealth:missingkeys', $component, implode(', ', $missing))
            );

            $checks[] = self::check_row(
                'nounexpectedkeys',
                get_string('scalehealth:nounexpected', $component),
                $unexpected === [],
                $unexpected === []
                    ? ''
                    : get_string('scalehealth:unexpectedkeys', $component, implode(', ', $unexpected))
            );
        }

        // The shape the blueprint asked for, where the run still knows it.
        $blueprint = self::blueprint_nodes($runid);
        if ($blueprint > 0) {
            $checks[] = self::check_row(
                'nodecount',
                get_string('scalehealth:nodecount', $component),
                count($rows) === $blueprint,
                get_string('scalehealth:nodecountvalue', $component, (object) [
                    'actual'   => count($rows),
                    'expected' => $blueprint,
                ])
            );
        }

        $ok = true;
        foreach ($checks as $check) {
            $ok = $ok && !empty($check['ok']);
        }

        $codes = [];
        foreach ($checks as $check) {
            if (empty($check['ok'])) {
                $codes[] = (string) $check['id'];
            }
        }

        return [
            'ok'      => $ok,
            'checks'  => $checks,
            // The failing check ids, for anything that has to branch on this
            // rather than read it.
            'codes'   => $codes,
            'summary' => $ok
                ? get_string('scalehealth:consistent', $component, (object) [
                    'contexts' => count($contexts),
                    'roots'    => count($roots),
                    'nodes'    => count($rows),
                ])
                : get_string('scalehealth:inconsistent', $component),
            'facts'   => [
                'roots'      => count($roots),
                'contexts'   => count($contexts),
                'nodes'      => count($rows),
                'scales'     => $expected,
                'present'    => $present,
                // The ids themselves, so a report names the objects rather
                // than only counting them.
                'rootids'    => $roots,
                'contextids' => array_keys($contexts),
            ],
        ];
    }

    /**
     * Whether the parent references form a cycle.
     *
     * @param array $rows The map rows.
     * @return bool
     */
    protected static function has_cycle(array $rows): bool {
        $parents = [];
        foreach ($rows as $row) {
            $parents[(int) $row->catscaleid] = (int) $row->parentcatscaleid;
        }

        foreach (array_keys($parents) as $start) {
            $seen = [];
            $node = $start;

            // Bounded by the number of nodes: a walk longer than the tree has
            // revisited something, and following it further only confirms it.
            while ($node !== 0 && isset($parents[$node])) {
                if (isset($seen[$node])) {
                    return true;
                }
                $seen[$node] = true;
                $node = $parents[$node];
            }
        }

        return false;
    }

    /**
     * The logical keys this run's blueprint calls for.
     *
     * @param int $runid The run.
     * @return string[]
     */
    protected static function expected_keys(int $runid): array {
        global $DB;

        $manifest = json_decode(
            (string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runid]),
            true
        ) ?: [];

        $scales = $manifest['config']['definition']['pool']['scales'] ?? null;
        if (!is_array($scales)) {
            return [];
        }

        $categories = max(0, (int) ($scales['categories'] ?? 0));
        $subcategories = max(0, (int) ($scales['subcategories'] ?? 0));
        if ($categories === 0) {
            return [];
        }

        $keys = ['root'];
        for ($c = 1; $c <= $categories; $c++) {
            $keys[] = 'c' . $c;
            for ($sub = 1; $sub <= $subcategories; $sub++) {
                $keys[] = 'c' . $c . 's' . $sub;
            }
        }

        return $keys;
    }

    /**
     * How many nodes this run's blueprint calls for.
     *
     * @param int $runid The run.
     * @return int Zero when the run no longer records one.
     */
    protected static function blueprint_nodes(int $runid): int {
        global $DB;

        $manifest = json_decode(
            (string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runid]),
            true
        ) ?: [];

        $scales = $manifest['config']['definition']['pool']['scales'] ?? null;
        if (!is_array($scales)) {
            return 0;
        }

        $categories = max(0, (int) ($scales['categories'] ?? 0));
        $subcategories = max(0, (int) ($scales['subcategories'] ?? 0));
        if ($categories === 0) {
            return 0;
        }

        // Root, one node per category, and one per subscale under each.
        return 1 + $categories + ($categories * $subcategories);
    }

    /**
     * Build one check row.
     *
     * @param string $id Stable identifier.
     * @param string $label What is checked.
     * @param bool $ok Whether it holds.
     * @param string $detail What was found.
     * @return array
     */
    protected static function check_row(string $id, string $label, bool $ok, string $detail): array {
        return ['id' => $id, 'label' => $label, 'ok' => $ok, 'failed' => !$ok, 'detail' => $detail];
    }
}
