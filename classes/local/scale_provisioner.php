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
 * Scale provisioner: create the engine CAT scale tree for a run.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Materialises a run's CAT context and scale tree, and records the mapping (E2.1).
 *
 * The pool blueprint has a global scale, some categories and their subscales.
 * {@see self::plan_scales()} turns a (categories, subcategories) shape into a flat
 * node plan with levels and profile indices — pure and testable. {@see self::provision()}
 * creates an engine context and the scale tree (local_catquiz_catcontext /
 * local_catquiz_catscales) and records, per created scale, a
 * local_catquizlab_scalemap row linking the engine catscale to the profile
 * (category/subscale index). {@see self::mapping_for()} reads that mapping so the
 * response oracle can resolve the subscale ability for a presented item. Creating
 * the engine rows needs the engine, so provisioning is a no-op without it.
 */
class scale_provisioner {
    /** @var int Scale-tree level for the global/root scale. */
    public const LEVEL_ROOT = 0;

    /** @var int Scale-tree level for a category scale. */
    public const LEVEL_CATEGORY = 1;

    /** @var int Scale-tree level for a subscale. */
    public const LEVEL_SUBSCALE = 2;

    /**
     * Plan the scale tree from a blueprint shape.
     *
     * @param array $blueprint Shape with 'categories' and 'subcategories' counts,
     *                         and optional 'name' for the root.
     * @return array[] Flat node plan; each node has level, categoryindex, subscaleindex, name.
     */
    public static function plan_scales(array $blueprint): array {
        $categories = max(0, (int) ($blueprint['categories'] ?? 0));
        $subcategories = max(0, (int) ($blueprint['subcategories'] ?? 0));
        // An empty name is not a name. A run with no swept factors has an empty
        // cell key, which reached this as '' and produced a nameless root scale
        // and children called " / K1.1" — unreadable in the CAT manager, and
        // the engine carries the name into its own feedback structures.
        $rootname = trim((string) ($blueprint['name'] ?? ''));
        if ($rootname === '') {
            $rootname = 'CATLab';
        }

        $nodes = [[
            'level'         => self::LEVEL_ROOT,
            'categoryindex' => null,
            'subscaleindex' => null,
            'name'          => $rootname,
        ]];

        for ($c = 1; $c <= $categories; $c++) {
            $nodes[] = [
                'level'         => self::LEVEL_CATEGORY,
                'categoryindex' => $c,
                'subscaleindex' => null,
                'name'          => $rootname . ' / K' . $c,
            ];
            for ($s = 1; $s <= $subcategories; $s++) {
                $nodes[] = [
                    'level'         => self::LEVEL_SUBSCALE,
                    'categoryindex' => $c,
                    'subscaleindex' => $s,
                    'name'          => $rootname . ' / K' . $c . '.' . $s,
                ];
            }
        }

        return $nodes;
    }

    /**
     * Create the engine context and scale tree for a run, recording the mapping.
     *
     * @param int $runid The run to materialise scales for.
     * @param array $blueprint The blueprint shape (see plan_scales).
     * @return array|null contextid, rootscaleid and the created scale count, or null without the engine.
     */
    public static function provision(int $runid, array $blueprint): ?array {
        global $DB, $USER;

        if (!environment::engine_available()) {
            return null;
        }

        $plan = self::plan_scales($blueprint);
        $now = time();
        $contextid = self::create_context($plan[0]['name'], $now, (int) ($USER->id ?? 0));

        $catscaleids = [];
        $rootscaleid = 0;
        foreach ($plan as $node) {
            $parentcatscaleid = self::resolve_parent($node, $catscaleids);
            $catscaleid = self::create_scale($node['name'], $parentcatscaleid, $contextid, $now);
            $catscaleids[self::node_key($node)] = $catscaleid;
            if ($node['level'] === self::LEVEL_ROOT) {
                $rootscaleid = $catscaleid;
            }

            $DB->insert_record('local_catquizlab_scalemap', (object) [
                'runid'            => $runid,
                'catscaleid'       => $catscaleid,
                'parentcatscaleid' => $parentcatscaleid,
                'contextid'        => $contextid,
                'level'            => $node['level'],
                'categoryindex'    => $node['categoryindex'],
                'subscaleindex'    => $node['subscaleindex'],
                'name'             => $node['name'],
                'timecreated'      => $now,
                'timemodified'     => $now,
            ]);
        }

        return ['contextid' => $contextid, 'rootscaleid' => $rootscaleid, 'count' => count($plan)];
    }

    /**
     * Read the profile mapping of a run's engine scale.
     *
     * @param int $runid The run.
     * @param int $catscaleid The engine catscale id.
     * @return array|null The mapping row (level, categoryindex, subscaleindex), or null.
     */
    public static function mapping_for(int $runid, int $catscaleid): ?array {
        global $DB;

        $row = $DB->get_record(
            'local_catquizlab_scalemap',
            ['runid' => $runid, 'catscaleid' => $catscaleid],
            'level, categoryindex, subscaleindex, contextid, name'
        );
        if (!$row) {
            return null;
        }

        return [
            'level'         => (int) $row->level,
            'categoryindex' => $row->categoryindex === null ? null : (int) $row->categoryindex,
            'subscaleindex' => $row->subscaleindex === null ? null : (int) $row->subscaleindex,
            'contextid'     => (int) $row->contextid,
            'name'          => (string) $row->name,
        ];
    }

    /**
     * Create an engine CAT context.
     *
     * @param string $name The context name.
     * @param int $now Timestamp.
     * @param int $userid The creating user.
     * @return int The new context id.
     */
    protected static function create_context(string $name, int $now, int $userid): int {
        global $DB;

        return (int) $DB->insert_record('local_catquiz_catcontext', (object) [
            'name'              => $name . ' context',
            'description'       => '',
            'descriptionformat' => FORMAT_HTML,
            'starttimestamp'    => $now,
            'endtimestamp'      => $now + YEARSECS,
            'json'              => '',
            'usermodified'      => $userid,
            'timecreated'       => $now,
            'timemodified'      => $now,
            'timecalculated'    => 0,
        ]);
    }

    /**
     * Create an engine catscale row.
     *
     * @param string $name The scale name.
     * @param int $parentid The parent catscale id (0 for root).
     * @param int $contextid The engine context id.
     * @param int $now Timestamp.
     * @return int The new catscale id.
     */
    protected static function create_scale(string $name, int $parentid, int $contextid, int $now): int {
        global $DB;

        return (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'name'          => $name,
            'description'   => '',
            'minscalevalue' => -3,
            'maxscalevalue' => 3,
            'parentid'      => $parentid,
            'contextid'     => $contextid,
            'timecreated'   => $now,
            'timemodified'  => $now,
        ]);
    }

    /**
     * Resolve the engine parent scale id of a planned node.
     *
     * @param array $node The plan node.
     * @param array $catscaleids Map of node key to created catscale id.
     * @return int
     */
    protected static function resolve_parent(array $node, array $catscaleids): int {
        if ($node['level'] === self::LEVEL_ROOT) {
            return 0;
        }
        if ($node['level'] === self::LEVEL_CATEGORY) {
            return (int) ($catscaleids['root'] ?? 0);
        }
        return (int) ($catscaleids['c' . $node['categoryindex']] ?? 0);
    }

    /**
     * Stable key for a plan node, used to link children to parents.
     *
     * @param array $node The plan node.
     * @return string
     */
    protected static function node_key(array $node): string {
        if ($node['level'] === self::LEVEL_ROOT) {
            return 'root';
        }
        if ($node['level'] === self::LEVEL_CATEGORY) {
            return 'c' . $node['categoryindex'];
        }
        return 'c' . $node['categoryindex'] . 's' . $node['subscaleindex'];
    }
}
