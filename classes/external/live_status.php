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
            // A reload is the fallback, not the mechanism. It happens when the
            // shape of the page changes — a run appearing, a worker arriving —
            // because those add rows, and adding rows from JavaScript would be
            // a second renderer disagreeing with the first.
            'changed'        => $verdict['state'],
            'shape'          => self::shape(),
            'regions'        => self::regions(),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    /**
     * The shape the page is being rendered with, for the live updater.
     *
     * Counts, not contents: a run finishing changes its row and is patched; a
     * run appearing changes how many rows there are and is not.
     *
     * @return string
     */
    public static function current_shape(): string {
        return self::shape();
    }

    /**
     * A fingerprint of what the page is made of.
     *
     * @return string
     */
    protected static function shape(): string {
        global $DB;

        return implode(':', [
            $DB->count_records('local_catquizlab_run'),
            $DB->count_records('local_catquizlab_worker'),
            $DB->count_records_select(
                'local_catquizlab_run',
                'status <> :finished',
                ['finished' => \local_catquizlab\local\registry::STATUS_FINISHED]
            ),
        ]);
    }

    /**
     * The text of each region the page keeps current.
     *
     * Text rather than markup: the page already has the elements, and sending
     * HTML for them would mean two places deciding what a status card looks
     * like.
     *
     * @return array[]
     */
    protected static function regions(): array {
        $queue = \local_catquizlab\local\status_report::queue();
        $pipeline = \local_catquizlab\local\status_report::pipeline();
        $situation = \local_catquizlab\local\situation::assess();

        return [
            // The situation names its sentence 'headline'; its 'state' is the
            // machine-readable level.
            ['name' => 'catquizlab-situation-state', 'text' => (string) $situation['headline']],
            ['name' => 'catquizlab-situation-reason', 'text' => (string) $situation['detail']],
            ['name' => 'catquizlab-queue-state', 'text' => (string) $queue['state']],
            ['name' => 'catquizlab-queue-reason', 'text' => (string) $queue['reason']],
            ['name' => 'catquizlab-pipeline-state', 'text' => (string) $pipeline['state']],
            ['name' => 'catquizlab-pipeline-reason', 'text' => (string) $pipeline['reason']],
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
            'shape'          => new external_value(PARAM_TEXT, 'A fingerprint of how many rows the page has.'),
            'regions'        => new \core_external\external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'The data-region to write into.'),
                    'text' => new external_value(PARAM_TEXT, 'What it should say.'),
                ]),
                'The regions the page keeps current.'
            ),
        ]);
    }
}
