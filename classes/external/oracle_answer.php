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
 * External function: response oracle for the Puppeteer worker.
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
 * Returns the answer a simulated person gives to a presented item.
 *
 * The worker is a dumb executor: for every question it reads from the real
 * attempt UI it calls this function, which is where all simulation logic will
 * live — the ground-truth ability profile, the IRT likelihood of the model
 * under test, seeded randomness, and (for the DPF sensitivity conditions)
 * deliberate local deviations. Keeping it server-side means the worker never
 * embeds any of the experiment logic.
 *
 * Stub scope (round E0): the endpoint authenticates, validates its parameters
 * and returns a well-formed "not ready" response. The actual likelihood
 * computation against the catmodel_* subplugins lands in E3.4.
 */
class oracle_answer extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'runid'      => new external_value(PARAM_INT, 'Lab run id the attempt belongs to.'),
            'questionid' => new external_value(PARAM_INT, 'Moodle question id of the presented item.'),
        ]);
    }

    /**
     * Return the simulated answer for one presented item.
     *
     * @param int $runid Lab run id.
     * @param int $questionid Moodle question id of the presented item.
     * @return array The oracle response.
     */
    public static function execute(int $runid, int $questionid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'runid'      => $runid,
            'questionid' => $questionid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        // E3.4 will resolve the person's ground truth for $params['runid'],
        // compute the model likelihood for $params['questionid'] and draw a
        // seed-deterministic response. Until then the worker receives a clear
        // "not ready" signal rather than a fabricated answer.
        return [
            'ready'    => false,
            'fraction' => 0.0,
            'choice'   => -1,
            'message'  => get_string('oracle:notready', 'local_catquizlab'),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ready'    => new external_value(PARAM_BOOL, 'True when a real answer was computed; false while the oracle is a stub.'),
            'fraction' => new external_value(PARAM_FLOAT, 'Score fraction in [0,1] for dichotomous items (0 when not ready).'),
            'choice'   => new external_value(PARAM_INT, 'Chosen answer category for polytomous items, or -1 when not applicable.'),
            'message'  => new external_value(PARAM_TEXT, 'Human-readable status or explanation.'),
        ]);
    }
}
