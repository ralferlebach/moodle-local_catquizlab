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
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\situation;
use local_catquizlab\local\worker_registry;

/**
 * What the overview shows, small enough to ask for repeatedly.
 *
 * The overview is rendered once and then goes stale while workers carry on
 * behind it, so the only way to see the queue move was to reload the page —
 * during exactly the minutes when somebody is watching it move.
 *
 * This returns counts and the one-line verdict, nothing else: no run rows, no
 * markup. A polling endpoint that returns a page is a page that is fetched
 * every few seconds.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_status extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * The current state of workers, queue and the overall verdict.
     *
     * @return array
     */
    public static function execute(): array {
        global $DB;

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:view', $context);

        $workers = worker_registry::summary();
        $verdict = situation::assess();

        return [
            'liveworkers'    => (int) $workers['live'],
            'crashedworkers' => (int) $workers['crashed'],
            'queued'         => $DB->count_records(
                'local_catquizlab_attempt',
                ['status' => attempt_scheduler::STATUS_QUEUED]
            ),
            'running'        => $DB->count_records(
                'local_catquizlab_attempt',
                ['status' => attempt_scheduler::STATUS_RUNNING]
            ),
            'collected'      => $DB->count_records(
                'local_catquizlab_attempt',
                ['status' => attempt_scheduler::STATUS_COLLECTED]
            ),
            'failed'         => $DB->count_records(
                'local_catquizlab_attempt',
                ['status' => attempt_scheduler::STATUS_FAILED]
            ),
            'state'          => $verdict['state'],
            'headline'       => $verdict['headline'],
            'detail'         => $verdict['detail'],
            // The page reloads itself when this changes: a state change is
            // where the rest of the page — run rows, progress, buttons — stops
            // matching what the counters say.
            'changed'        => $verdict['state'],
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'liveworkers'    => new external_value(PARAM_INT, 'Workers reporting in.'),
            'crashedworkers' => new external_value(PARAM_INT, 'Workers that stopped reporting.'),
            'queued'         => new external_value(PARAM_INT, 'Attempts waiting.'),
            'running'        => new external_value(PARAM_INT, 'Attempts being played.'),
            'collected'      => new external_value(PARAM_INT, 'Attempts with a trace.'),
            'failed'         => new external_value(PARAM_INT, 'Attempts that failed.'),
            'state'          => new external_value(PARAM_ALPHA, 'The overall verdict.'),
            'headline'       => new external_value(PARAM_TEXT, 'One sentence about what is going on.'),
            'detail'         => new external_value(PARAM_TEXT, 'Why, when that helps.'),
            'changed'        => new external_value(PARAM_ALPHA, 'The verdict, for change detection.'),
        ]);
    }
}
