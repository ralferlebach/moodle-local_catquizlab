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
 * External function: a worker claims the next queued attempt job.
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
 * Hands the calling worker the next attempt to play, if any.
 *
 * Used by the queue-polling worker variant (E3.2), where timed ad-hoc tasks
 * enqueue attempt jobs and workers on a separate host claim them over the web
 * service. The direct-process-start variant does not need this endpoint.
 *
 * Stub scope (round E0): authenticates, validates and always reports that no
 * job is available. The real claim (atomic status transition on the attempt
 * table) lands in E3.2.
 */
class job_claim extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'workerid' => new external_value(PARAM_ALPHANUMEXT, 'Stable identifier of the calling worker.'),
        ]);
    }

    /**
     * Claim the next queued attempt job.
     *
     * @param string $workerid Stable identifier of the calling worker.
     * @return array The claim result.
     */
    public static function execute(string $workerid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'workerid' => $workerid,
        ]);
        unset($params);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        return [
            'hasjob'    => false,
            'runid'     => 0,
            'attemptid' => 0,
            'quizcmid'  => 0,
            'userid'    => 0,
            'message'   => get_string('job:none', 'local_catquizlab'),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hasjob'    => new external_value(PARAM_BOOL, 'True when a job was handed out.'),
            'runid'     => new external_value(PARAM_INT, 'Lab run id of the claimed job (0 when none).'),
            'attemptid' => new external_value(PARAM_INT, 'Lab attempt id to play (0 when none).'),
            'quizcmid'  => new external_value(PARAM_INT, 'Course-module id of the adaptivequiz to sit (0 when none).'),
            'userid'    => new external_value(PARAM_INT, 'Simulated user id to log in as (0 when none).'),
            'message'   => new external_value(PARAM_TEXT, 'Human-readable status.'),
        ]);
    }
}
