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
 * Bind the activity attempt the browser is in to its sitting, and say where it stands (#173).
 *
 * Called by every execution when it reaches its first question, before it answers anything: the first execution binds
 * the attempt behind the question usage it sees, every later one confirms it. The answer is the engine's own count at
 * that moment — the count the execution goes on from — and nothing else: a usage of another account or activity, a
 * second attempt beside the bound one, or a caller that does not hold the lease is refused, and the worker answers
 * nothing until it is cleared.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sitting_bind extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'The catquizlab sitting.'),
            'qubaid' => new external_value(PARAM_INT, 'The question usage the browser shows.'),
            'workerid' => new external_value(PARAM_ALPHANUMEXT, 'The calling worker: only the lease holder binds.'),
        ]);
    }

    /**
     * Bind, or confirm the binding, and return the attempt's state.
     *
     * @param int $attemptid The sitting.
     * @param int $qubaid The question usage the browser shows.
     * @param string $workerid The calling worker.
     * @return array
     */
    public static function execute(int $attemptid, int $qubaid, string $workerid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'qubaid' => $qubaid,
            'workerid' => $workerid,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        [$had, $answer] = \local_catquizlab\local\sitting_lock::run(
            (int) $params['attemptid'],
            \local_catquizlab\local\sitting_lock::REPORT_WAIT,
            static fn(): array => \local_catquizlab\local\engine_identity::bind(
                (int) $params['attemptid'],
                (int) $params['qubaid'],
                (string) $params['workerid']
            )
        );
        if (!$had) {
            // Busy is not an answer about the identity: the worker asks again, a bounded number of times.
            return ['found' => false, 'reason' => 'busy', 'engineattemptid' => 0, 'qubaid' => 0, 'bound' => false,
                'state' => '', 'questionsattempted' => 0, 'slots' => 0, 'timemodified' => 0, 'newly' => false];
        }
        if (!empty($answer['newly'])) {
            $history = \local_catquizlab\local\attempt_history::class;
            $history::record((int) $params['attemptid'], $history::BOUND, [
                'workerid' => (string) $params['workerid'],
                'engineattemptid' => (int) $answer['engineattemptid'],
                'detail' => 'usage ' . (int) $answer['qubaid'] . ', ' . (int) $answer['questionsattempted'] . ' answered, '
                    . (int) $answer['slots'] . ' presented',
            ]);
        }

        return $answer;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Whether the sitting\'s activity attempt is known and matches.'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'Why not, otherwise.'),
            'engineattemptid' => new external_value(PARAM_INT, 'The adaptivequiz_attempt id.'),
            'qubaid' => new external_value(PARAM_INT, 'Its question usage.'),
            'bound' => new external_value(PARAM_BOOL, 'Whether it is bound to the sitting.'),
            'state' => new external_value(PARAM_ALPHANUMEXT, 'Its attemptstate: inprogress or complete.'),
            'questionsattempted' => new external_value(PARAM_INT, 'Questions answered and stored.'),
            'slots' => new external_value(PARAM_INT, 'Questions presented: the slots of its question usage.'),
            'timemodified' => new external_value(PARAM_INT, 'When the attempt last changed.'),
            'newly' => new external_value(PARAM_BOOL, 'Whether this call made the binding.'),
        ]);
    }
}
