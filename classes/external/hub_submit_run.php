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
 * External function: a node submits a completed run package to the hub.
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
 * Receives a run package (traces, ground truth, baseline, manifest) from a node.
 *
 * This is the node-to-hub half of the central recalculation path, modelled on
 * the engine's own hub/node transfer (response_submitter / collect_responses).
 * The hub verifies the integrity hash, stores the package and later
 * recalculates the evaluation with the identical metric library.
 *
 * Stub scope (round E0): authenticates, validates and recomputes the hash to
 * confirm integrity, then reports acceptance without persisting. Storage and
 * recalculation land in E5.
 */
class hub_submit_run extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'payload' => new external_value(PARAM_RAW, 'Run package as a JSON string.'),
            'hash'    => new external_value(PARAM_ALPHANUMEXT, 'SHA-256 hash of the payload for integrity checking.'),
        ]);
    }

    /**
     * Accept a submitted run package.
     *
     * @param string $payload Run package as a JSON string.
     * @param string $hash SHA-256 hash of the payload.
     * @return array The submission result.
     */
    public static function execute(string $payload, string $hash): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'payload' => $payload,
            'hash'    => $hash,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:hubtransfer', $context);

        // Integrity check is meaningful already; storage follows in E5.
        $computed = hash('sha256', $params['payload']);
        $ok = hash_equals($computed, $params['hash']);

        return [
            'accepted' => false,
            'verified' => $ok,
            'message'  => $ok
                ? get_string('hub:verifiednotstored', 'local_catquizlab')
                : get_string('hub:hashmismatch', 'local_catquizlab'),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'accepted' => new external_value(PARAM_BOOL, 'True when the package was stored (false while the hub is a stub).'),
            'verified' => new external_value(PARAM_BOOL, 'True when the payload hash matched.'),
            'message'  => new external_value(PARAM_TEXT, 'Human-readable status.'),
        ]);
    }
}
