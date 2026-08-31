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
 * for a known (ground-truth) calibration — pure and testable.
 *
 * {@see self::register_item()} delegates to {@see cat_item_provisioner}. This
 * class used to write local_catquiz_items itself when the engine API had not
 * created a row, which is precisely how a refused assignment turned into an
 * apparent success: the engine said no, and the lab inserted the row anyway.
 * The engine now owns its own tables.
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

        // The model comes from the experiment, resolved through the catalogue.
        // The old fallback to raschbirnbaum meant a 3PL run silently produced
        // 2PL item parameters whenever the caller forgot to pass the model.
        $model = (string) ($params['model'] ?? '');
        $model = $model !== '' && model_catalog::has($model)
            ? model_catalog::engine_key($model)
            : ($model !== '' ? $model : model_catalog::engine_key('2pl'));

        return [
            'componentid'    => $questionid,
            'componentname'  => 'question',
            'contextid'      => $contextid,
            'model'          => $model,
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
     * Delegates to {@see cat_item_provisioner}, which holds the engine boundary
     * and treats the engine's verdict as binding. This method used to ignore
     * the result of the engine API and then write the tables itself, so an
     * assignment the engine had refused still ended up looking successful.
     *
     * @param int $questionid The Moodle question id.
     * @param int $catscaleid The engine catscale id.
     * @param int $contextid The engine CAT context id.
     * @param array $params The item parameters (see build_itemparam).
     * @param array $options Passed through to the provisioner, e.g. 'verify'.
     * @return array{ok: bool, itemid: ?int, paramid: ?int, reason: ?string,
     *               questionid: int, catscaleid: int, engineerror: ?string}
     */
    public static function register_item(
        int $questionid,
        int $catscaleid,
        int $contextid,
        array $params,
        array $options = []
    ): array {
        return cat_item_provisioner::provision($questionid, $catscaleid, $contextid, $params, $options);
    }
}
