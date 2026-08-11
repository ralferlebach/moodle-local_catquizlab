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
 * Item registrar: register a question as a CAT item with parameters.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Registers a Moodle question as an engine CAT item with IRT parameters (E2.1).
 *
 * {@see self::build_itemparam()} assembles the local_catquiz_itemparams record
 * for a known (ground-truth) calibration — pure and testable. {@see self::register_item()}
 * links the question to the scale via the engine (catscale::add_or_update_testitem_to_scale),
 * stores the item parameters and marks them active on the item. It needs the
 * engine and is a no-op without it.
 */
class item_registrar {
    /** @var int Item-parameter status for a known/calculated calibration. */
    public const STATUS_CALCULATED = 1;

    /**
     * Build the item-parameter record for a question.
     *
     * @param int $questionid The Moodle question id.
     * @param int $contextid The engine CAT context id.
     * @param array $params model, difficulty, discrimination, guessing.
     * @return array The local_catquiz_itemparams field set (without ids/timestamps).
     */
    public static function build_itemparam(int $questionid, int $contextid, array $params): array {
        $steps = array_values(array_map('floatval', $params['steps'] ?? []));
        $json = $steps !== [] ? json_encode(['steps' => $steps], JSON_UNESCAPED_SLASHES) : '';

        return [
            'componentid'    => $questionid,
            'componentname'  => 'question',
            'contextid'      => $contextid,
            'model'          => (string) ($params['model'] ?? 'raschbirnbaum'),
            'difficulty'     => round((float) ($params['difficulty'] ?? 0.0), 5),
            'discrimination' => round((float) ($params['discrimination'] ?? 1.0), 5),
            'guessing'       => round((float) ($params['guessing'] ?? 0.0), 5),
            'status'         => self::STATUS_CALCULATED,
            'json'           => $json,
        ];
    }

    /**
     * Register a question as an item of a scale and store its parameters.
     *
     * @param int $questionid The Moodle question id.
     * @param int $catscaleid The engine catscale id.
     * @param int $contextid The engine CAT context id.
     * @param array $params The item parameters (see build_itemparam).
     * @return int|null The item id, or null without the engine.
     */
    public static function register_item(int $questionid, int $catscaleid, int $contextid, array $params): ?int {
        global $DB;

        if (!environment::engine_available()) {
            return null;
        }

        if (class_exists('\local_catquiz\catscale')) {
            \local_catquiz\catscale::add_or_update_testitem_to_scale($catscaleid, $questionid);
        }

        $item = self::ensure_item($questionid, $catscaleid, $contextid);

        $now = time();
        $record = (object) (self::build_itemparam($questionid, $contextid, $params) + [
            'itemid'       => $item->id,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
        $paramid = $DB->insert_record('local_catquiz_itemparams', $record);

        $DB->update_record('local_catquiz_items', (object) [
            'id'           => $item->id,
            'activeparamid' => $paramid,
            'contextid'    => $contextid,
        ]);

        return (int) $item->id;
    }

    /**
     * Fetch the item row, creating it if the engine API did not.
     *
     * @param int $questionid The question id.
     * @param int $catscaleid The scale id.
     * @param int $contextid The context id.
     * @return \stdClass The item row (with id).
     */
    protected static function ensure_item(int $questionid, int $catscaleid, int $contextid): \stdClass {
        global $DB;

        $item = $DB->get_record('local_catquiz_items', [
            'componentid'   => $questionid,
            'componentname' => 'question',
            'catscaleid'    => $catscaleid,
        ]);
        if ($item) {
            return $item;
        }

        $now = time();
        $item = (object) [
            'componentname' => 'question',
            'componentid'   => $questionid,
            'catscaleid'    => $catscaleid,
            'contextid'     => $contextid,
            'lastupdated'   => $now,
            'status'        => self::STATUS_CALCULATED,
            'activeparamid' => 0,
        ];
        $item->id = $DB->insert_record('local_catquiz_items', $item);

        return $item;
    }
}
