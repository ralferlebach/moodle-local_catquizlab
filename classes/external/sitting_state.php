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

namespace local_catquizlab\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Where a sitting stands on the server, for a worker whose submission ended uncertainly (#134).
 *
 * A POST that ended in a 404, a 5xx, a timeout or a redirect nobody expected
 * says nothing certain about the answer it carried: it may have been stored
 * with only the response lost, or not stored at all. The browser cannot tell
 * which. The server can: the activity's attempt counts the questions answered,
 * its question usage the questions presented. The worker compares those with
 * what it has answered, and only then continues — answering again what was not
 * stored, never what was — or stops with the reason.
 *
 * Which attempt it reads is the sitting's own (#173): the one bound to it, or — before any execution bound one — the
 * account's only attempt on the activity (engine_identity). Two or more unbound attempts, an attempt of another account
 * or activity, or a usage other than the one the worker sees are said as such, never resolved by taking the newest.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sitting_state extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'The catquizlab sitting.'),
            'qubaid' => new external_value(
                PARAM_INT,
                'The question usage the worker sees, refused when it is not the sitting\'s (#173); 0: not compared.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * The state of the sitting's activity attempt.
     *
     * @param int $attemptid The catquizlab sitting.
     * @param int $qubaid The question usage the worker sees, or 0.
     * @return array
     */
    public static function execute(int $attemptid, int $qubaid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid, 'qubaid' => $qubaid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        return self::state((int) $params['attemptid'], (int) $params['qubaid']);
    }

    /**
     * The state, without the web service around it.
     *
     * @param int $attemptid The catquizlab sitting.
     * @param int $qubaid The question usage the caller sees, or 0.
     * @return array found, reason, engineattemptid, qubaid, bound, state, questionsattempted, slots, timemodified.
     */
    public static function state(int $attemptid, int $qubaid = 0): array {
        return \local_catquizlab\local\engine_identity::resolve($attemptid, $qubaid);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Whether the activity attempt was found.'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'Why not, otherwise.'),
            'engineattemptid' => new external_value(PARAM_INT, 'The adaptivequiz_attempt id.'),
            'qubaid' => new external_value(PARAM_INT, 'Its question usage (adaptivequiz_attempt.uniqueid).', VALUE_OPTIONAL),
            'bound' => new external_value(PARAM_BOOL, 'Whether it is bound to the sitting (#173).', VALUE_OPTIONAL),
            'state' => new external_value(PARAM_ALPHANUMEXT, 'Its attemptstate: inprogress or complete.'),
            'questionsattempted' => new external_value(PARAM_INT, 'Questions answered and stored.'),
            'slots' => new external_value(PARAM_INT, 'Questions presented: the slots of its question usage.'),
            'timemodified' => new external_value(PARAM_INT, 'When the attempt last changed.'),
        ]);
    }
}
