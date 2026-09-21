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
use local_catquizlab\local\live_progress;
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
        return new external_function_parameters([
            // Which experiment the reader is looking at. Without it the poll
            // answered about the whole installation while the table beside it
            // showed one experiment, and the two disagreed by design.
            'experimentid' => new external_value(PARAM_INT, 'The experiment in view, or 0.', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * The current state of workers, queue, runs and the overall verdict.
     *
     * @param int $experimentid The experiment the page is showing, or 0.
     * @return array
     */
    public static function execute(int $experimentid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), ['experimentid' => $experimentid]);
        $experimentid = (int) $params['experimentid'];

        global $DB;

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:view', $context);

        $workers = worker_registry::summary();
        $verdict = situation::assess();

        // Scoped to the experiment in view. Counting the whole installation
        // beside a table that shows one experiment produced two truths on one
        // page, and "0 waiting" for the site was read as "0 waiting" for the
        // experiment somebody had just queued fifty sittings for. With no
        // experiment in view the counts are the site's, and say so.
        $scope = $experimentid > 0
            ? 'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = :experimentid) AND status = :status'
            : 'status = :status';
        $count = static function (int $status) use ($DB, $scope, $experimentid): int {
            return (int) $DB->count_records_select('local_catquizlab_attempt', $scope, [
                'experimentid' => $experimentid,
                'status'       => $status,
            ]);
        };

        return [
            'scope'          => $experimentid > 0 ? 'experiment' : 'site',
            'liveworkers'    => (int) $workers['live'],
            'crashedworkers' => (int) $workers['crashed'],
            'queued'         => $count(attempt_scheduler::STATUS_QUEUED),
            'running'        => $count(attempt_scheduler::STATUS_RUNNING),
            'collected'      => $count(attempt_scheduler::STATUS_COLLECTED),
            'failed'         => $count(attempt_scheduler::STATUS_FAILED),
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
            // The runs of the experiment in view, each carrying the same
            // verdict the page renders from. One snapshot, one source: the
            // header and the table disagreed because they asked two different
            // questions a few seconds apart.
            'runs'           => self::run_rows($experimentid),
            'experiment'     => self::experiment_summary($experimentid),
            'progress'       => live_progress::snapshot($experimentid),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    /**
     * Each run of an experiment, as the page shows it.
     *
     * Rendered from status_report, which is what builds the cards on the page
     * itself — so a row updated by a poll and a row drawn by a page load say
     * the same thing, because they came from the same place.
     *
     * @param int $experimentid The experiment, or 0 for none.
     * @return array[]
     */
    protected static function run_rows(int $experimentid): array {
        global $DB;

        if ($experimentid === 0) {
            return [];
        }

        $rows = [];
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
            $card = \local_catquizlab\local\status_report::run($run);
            $counts = \local_catquizlab\local\run_lifecycle::attempt_counts((int) $run->id);

            $total = (int) ($counts['total'] ?? 0);
            $done = (int) ($counts['collected'] ?? 0);

            global $OUTPUT;

            $rows[] = [
                'runid'   => (int) $run->id,
                'cellkey' => (string) $run->cellkey,
                'state'   => (string) $card['state'],
                'level'   => (string) $card['level'],
                'reason'  => (string) ($card['reason'] ?? ''),
                // The card as the page renders it. The poll used to update two
                // hidden spans beside the visible card, which therefore never
                // changed — and there is no second status logic in JavaScript
                // to do it with, by design.
                'cardhtml' => $OUTPUT->render_from_template('local_catquizlab/statuscard', $card),
                'done'    => $done,
                'total'   => $total,
                'percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
                'open'    => (int) ($counts['open'] ?? 0),
                'failed'  => (int) ($counts['failed'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * The experiment's own headline figures.
     *
     * @param int $experimentid The experiment, or 0 for none.
     * @return array
     */
    protected static function experiment_summary(int $experimentid): array {
        if ($experimentid === 0) {
            return ['state' => '', 'label' => '', 'done' => 0, 'total' => 0, 'percent' => 0];
        }

        $state = \local_catquizlab\local\experiment_runner::state($experimentid);
        $done = (int) $state['attempts']['collected'];
        $total = (int) $state['attempts']['planned'];

        return [
            'state'   => (string) $state['state'],
            'label'   => (string) $state['label'],
            'done'    => $done,
            'total'   => $total,
            'percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
        ];
    }

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
            'scope'          => new external_value(PARAM_ALPHA, 'experiment or site: what the counts cover.'),
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
            'runs'           => new \core_external\external_multiple_structure(
                new external_single_structure([
                    'runid'   => new external_value(PARAM_INT, 'The run.'),
                    'cellkey' => new external_value(PARAM_TEXT, 'Which design cell it is.'),
                    'state'   => new external_value(PARAM_TEXT, 'What it is doing, in words.'),
                    'level'   => new external_value(PARAM_ALPHA, 'good, watch or bad.'),
                    'reason'  => new external_value(PARAM_TEXT, 'Why, where there is a why.'),
                    'cardhtml' => new external_value(PARAM_RAW, 'The status card, rendered.'),
                    'done'    => new external_value(PARAM_INT, 'Attempts collected.'),
                    'total'   => new external_value(PARAM_INT, 'Attempts planned.'),
                    'percent' => new external_value(PARAM_INT, 'How far along.'),
                    'open'    => new external_value(PARAM_INT, 'Attempts not yet collected.'),
                    'failed'  => new external_value(PARAM_INT, 'Attempts that failed.'),
                ]),
                'The runs of the experiment in view.'
            ),
            'progress'       => new external_single_structure([
                'experimentid' => new external_value(PARAM_INT, 'The experiment.'),
                'state'        => new external_value(PARAM_ALPHA, 'The operational state.'),
                'label'        => new external_value(PARAM_TEXT, 'That state, in words.'),
                'reason'       => new external_value(PARAM_TEXT, 'Why, where a state needs one.'),
                'done'         => new external_value(PARAM_INT, 'Sittings collected.'),
                'total'        => new external_value(PARAM_INT, 'Sittings planned.'),
                'percent'      => new external_value(PARAM_INT, 'How far along.'),
                'runs'         => new \core_external\external_multiple_structure(
                    new external_single_structure([
                        'runid'    => new external_value(PARAM_INT, 'The run.'),
                        'cellkey'  => new external_value(PARAM_TEXT, 'Its design cell.'),
                        'done'     => new external_value(PARAM_INT, 'Collected.'),
                        'total'    => new external_value(PARAM_INT, 'Planned.'),
                        'percent'  => new external_value(PARAM_INT, 'How far along.'),
                        'inflight' => new external_value(PARAM_INT, 'Being played now.'),
                        'failed'   => new external_value(PARAM_INT, 'Failed for good.'),
                        'complete' => new external_value(PARAM_BOOL, 'Whether it is done.'),
                        'held'     => new external_value(PARAM_BOOL, 'Whether it was stopped.'),
                    ]),
                    'Per run.'
                ),
                'now'          => new external_single_structure([
                    'workers'   => new external_value(PARAM_INT, 'Live workers.'),
                    'starting'  => new external_value(PARAM_INT, 'Workers not yet reporting.'),
                    'inflight'  => new external_value(PARAM_INT, 'Sittings being played.'),
                    'queued'    => new external_value(PARAM_INT, 'Sittings waiting.'),
                    'collected' => new external_value(PARAM_INT, 'Sittings done.'),
                    'failed'    => new external_value(PARAM_INT, 'Sittings failed for good.'),
                ], 'What is happening this second.'),
            ], 'The progress snapshot the page renders from.'),
            'experiment'     => new external_single_structure([
                'state'   => new external_value(PARAM_TEXT, 'The experiment state.'),
                'label'   => new external_value(PARAM_TEXT, 'That state, in words.'),
                'done'    => new external_value(PARAM_INT, 'Attempts collected.'),
                'total'   => new external_value(PARAM_INT, 'Attempts planned.'),
                'percent' => new external_value(PARAM_INT, 'How far along.'),
            ], 'The experiment in view.'),
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
