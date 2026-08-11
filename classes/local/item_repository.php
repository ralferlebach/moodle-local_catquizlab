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
 * Item repository: read active item parameters from the engine.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Reads the CAT engine's active item parameters (E2.1 / E3.4 wiring).
 *
 * Given the engine context of a run's CAT test, it resolves the IRT parameters
 * of a presented question — or of every item in a scale subtree — from the
 * engine tables (local_catquiz_items joined to its active local_catquiz_itemparams,
 * with the scale subtree gathered by a recursive walk over local_catquiz_catscales),
 * exactly as the Wunderbyte simulation scripts do. The response oracle uses these
 * parameters to produce model-consistent answers.
 *
 * The reads need the engine, so the query methods return null / an empty list
 * when it is absent (CI and stand-alone stay green). The row shaping,
 * {@see self::shape_params()}, is pure and testable.
 */
class item_repository {
    /**
     * Read the active parameters of a single presented question.
     *
     * @param int $contextid The engine context id of the CAT test.
     * @param int $questionid The Moodle question id of the presented item.
     * @return array|null The shaped parameters, or null when unavailable / not found.
     */
    public static function for_question(int $contextid, int $questionid): ?array {
        global $DB;

        if (!environment::engine_available()) {
            return null;
        }

        $sql = "SELECT lci.componentid AS questionid, lci.catscaleid,
                       lcip.model, lcip.difficulty, lcip.discrimination, lcip.guessing, lcip.json
                  FROM {local_catquiz_items} lci
                  JOIN {local_catquiz_itemparams} lcip ON lcip.id = lci.activeparamid
                 WHERE lci.contextid = :contextid AND lci.componentid = :questionid";
        $row = $DB->get_record_sql($sql, ['contextid' => $contextid, 'questionid' => $questionid]);

        return $row ? self::shape_params($row) : null;
    }

    /**
     * Read the active parameters of every item in a scale subtree.
     *
     * @param int $contextid The engine context id of the CAT test.
     * @param int $catscaleid The root scale id of the subtree.
     * @return array Map of question id to shaped parameters.
     */
    public static function for_scale(int $contextid, int $catscaleid): array {
        global $DB;

        if (!environment::engine_available()) {
            return [];
        }

        $sql = "WITH RECURSIVE scaletree AS (
                    SELECT id FROM {local_catquiz_catscales} WHERE id = :scaleid
                    UNION ALL
                    SELECT c.id
                      FROM {local_catquiz_catscales} c
                      JOIN scaletree s ON c.parentid = s.id
                )
                SELECT lci.componentid AS questionid, lci.catscaleid,
                       lcip.model, lcip.difficulty, lcip.discrimination, lcip.guessing
                  FROM {local_catquiz_items} lci
                  JOIN {local_catquiz_itemparams} lcip ON lcip.id = lci.activeparamid
                 WHERE lci.contextid = :contextid
                   AND lci.catscaleid IN (SELECT id FROM scaletree)";
        $rows = $DB->get_records_sql($sql, ['scaleid' => $catscaleid, 'contextid' => $contextid]);

        $items = [];
        foreach ($rows as $row) {
            $items[(int) $row->questionid] = self::shape_params($row);
        }
        return $items;
    }

    /**
     * Shape a raw engine row into an item-parameter array with model defaults.
     *
     * A missing discrimination defaults to 1.0 and a missing guessing to 0.0, so
     * a Rasch item without those columns behaves as the 1PL model expects.
     *
     * @param \stdClass $row The raw row (questionid, catscaleid, model, difficulty, discrimination, guessing).
     * @return array The shaped parameters.
     */
    public static function shape_params(\stdClass $row): array {
        $shape = [
            'questionid'     => (int) $row->questionid,
            'catscaleid'     => (int) ($row->catscaleid ?? 0),
            'model'          => (string) ($row->model ?? ''),
            'difficulty'     => (float) ($row->difficulty ?? 0.0),
            'discrimination' => isset($row->discrimination) && $row->discrimination !== null
                ? (float) $row->discrimination
                : 1.0,
            'guessing'       => isset($row->guessing) && $row->guessing !== null
                ? (float) $row->guessing
                : 0.0,
            'polytomous'     => false,
            'steps'          => [],
        ];

        // Polytomous items carry their category thresholds in the params json.
        $extra = isset($row->json) ? json_decode((string) $row->json, true) : null;
        if (is_array($extra) && !empty($extra['steps']) && is_array($extra['steps'])) {
            $shape['steps'] = array_values(array_map('floatval', $extra['steps']));
            $shape['polytomous'] = true;
        }

        return $shape;
    }
}
