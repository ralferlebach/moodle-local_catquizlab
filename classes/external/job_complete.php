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
 * External function: a worker reports an attempt as finished.
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
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\attempt_collector;

/**
 * Records the outcome the worker reports after playing an attempt.
 *
 * The worker only reports status and timing; the trace itself is collected
 * server-side from the engine tables by a follow-up ad-hoc task (E3.5), so a
 * crashed worker can never punch a hole in the trace.
 *
 * Stub scope (round E0): authenticates, validates and acknowledges without
 * writing. The status transition and collect-task trigger land in E3.5.
 */
class job_complete extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Lab attempt id that was played.'),
            'status'    => new external_value(PARAM_ALPHA, 'Reported outcome: finished or failed.'),
            'runtimems' => new external_value(PARAM_INT, 'Wall-clock runtime of the attempt in milliseconds.', VALUE_DEFAULT, 0),
            'engineattemptid' => new external_value(PARAM_INT, 'The adaptivequiz_attempt id (0 when unknown).', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Acknowledge a reported attempt outcome.
     *
     * @param int $attemptid Lab attempt id that was played.
     * @param string $status Reported outcome (finished or failed).
     * @param int $runtimems Wall-clock runtime in milliseconds.
     * @param int $engineattemptid The adaptivequiz_attempt id, when known.
     * @return array The acknowledgement.
     */
    public static function execute(int $attemptid, string $status, int $runtimems = 0, int $engineattemptid = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid'       => $attemptid,
            'status'          => $status,
            'runtimems'       => $runtimems,
            'engineattemptid' => $engineattemptid,
        ]);
        unset($params);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        if (!$attempt) {
            return [
                'acknowledged' => false,
                'message'      => get_string('job:unknownattempt', 'local_catquizlab'),
            ];
        }

        $finished = ($status === 'finished');
        $update = (object) [
            'id'           => $attemptid,
            'status'       => $finished ? attempt_scheduler::STATUS_COLLECTED : attempt_scheduler::STATUS_FAILED,
            'timemodified' => time(),
        ];
        if ($runtimems > 0) {
            $update->runtimems = $runtimems;
        }
        if ($engineattemptid > 0) {
            $update->engineattemptid = $engineattemptid;
        }
        $DB->update_record('local_catquizlab_attempt', $update);

        // Pull the engine trace into the attempt when possible (no-op without the engine).
        if ($finished && $engineattemptid > 0) {
            attempt_collector::collect($attemptid);
        }

        return [
            'acknowledged' => true,
            'message'      => get_string('job:acknowledged', 'local_catquizlab'),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'acknowledged' => new external_value(PARAM_BOOL, 'True when the report was accepted.'),
            'message'      => new external_value(PARAM_TEXT, 'Human-readable status.'),
        ]);
    }
}
