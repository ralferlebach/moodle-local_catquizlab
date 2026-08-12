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
use local_catquizlab\local\attempt_scheduler;

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
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'workerid' => $workerid,
        ]);
        unset($params);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        $none = [
            'hasjob'    => false,
            'runid'     => 0,
            'attemptid' => 0,
            'quizcmid'  => 0,
            'userid'    => 0,
            'message'   => get_string('job:none', 'local_catquizlab'),
        ];

        // Claim the oldest queued attempt inside a transaction so two workers
        // cannot pick up the same one.
        $transaction = $DB->start_delegated_transaction();

        $queued = $DB->get_records_select(
            'local_catquizlab_attempt',
            'status = :status AND nextruntime <= :now',
            ['status' => attempt_scheduler::STATUS_QUEUED, 'now' => time()],
            'nextruntime ASC, timecreated ASC, id ASC',
            '*',
            0,
            1
        );
        $attempt = reset($queued);
        if (!$attempt) {
            $transaction->allow_commit();
            return $none;
        }

        $DB->update_record('local_catquizlab_attempt', (object) [
            'id'           => $attempt->id,
            'status'       => attempt_scheduler::STATUS_RUNNING,
            'tries'        => (int) $attempt->tries + 1,
            'timemodified' => time(),
        ]);
        $run = $DB->get_record('local_catquizlab_run', ['id' => $attempt->runid]);
        $userid = (int) $DB->get_field('local_catquizlab_person', 'moodleuserid', ['id' => $attempt->personid]);

        $transaction->allow_commit();

        return [
            'hasjob'    => true,
            'runid'     => (int) $attempt->runid,
            'attemptid' => (int) $attempt->id,
            'quizcmid'  => $run ? (int) $run->testcmid : 0,
            'userid'    => $userid,
            'message'   => get_string('job:claimed', 'local_catquizlab'),
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
