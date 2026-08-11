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
 * External function: a node fetches recalculated results from the hub.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns the metric results the hub recalculated for a submitted run.
 *
 * The hub-to-node half of the central recalculation path. A node calls this to
 * collect the results the hub computed with the identical metric library, which
 * also serves as an independent cross-check of the node's own local numbers.
 *
 * Stub scope (round E0): authenticates, validates and reports that no results
 * are available yet. Result retrieval lands in E5.
 */
class hub_fetch_results extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'runref' => new external_value(PARAM_ALPHANUMEXT, 'Run reference the node used when submitting the package.'),
        ]);
    }

    /**
     * Fetch recalculated results for a submitted run.
     *
     * @param string $runref Run reference used at submission time.
     * @return array The fetch result.
     */
    public static function execute(string $runref): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'runref' => $runref,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:hubtransfer', $context);

        global $DB;
        $runid = (int) $DB->get_field('local_catquizlab_run', 'id', ['cellkey' => $params['runref']]);
        if (!$runid) {
            return [
                'available'   => false,
                'resultsjson' => '',
                'message'     => get_string('hub:noresults', 'local_catquizlab'),
            ];
        }

        $metrics = \local_catquizlab\local\export_dataset::metrics([$runid]);

        return [
            'available'   => true,
            'resultsjson' => json_encode($metrics['rows'], JSON_UNESCAPED_SLASHES),
            'message'     => get_string('hub:resultsfound', 'local_catquizlab', $runid),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'available'   => new external_value(PARAM_BOOL, 'True when recalculated results are ready.'),
            'resultsjson' => new external_value(PARAM_RAW, 'Recalculated results as JSON (empty when unavailable).'),
            'message'     => new external_value(PARAM_TEXT, 'Human-readable status.'),
        ]);
    }
}
