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
            // The page of experiment sections in "All experiments" (#158).
            'page' => new external_value(PARAM_INT, 'The page of the overview, from 0.', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * The current state of workers, queue, runs and the overall verdict.
     *
     * @param int $experimentid The experiment the page is showing, or 0.
     * @param int $page The page of experiment sections in "All experiments", from 0.
     * @return array
     */
    public static function execute(int $experimentid = 0, int $page = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), ['experimentid' => $experimentid, 'page' => $page]);
        $experimentid = (int) $params['experimentid'];
        $page = max(0, (int) $params['page']);

        global $DB;

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:view', $context);

        $workers = worker_registry::summary();
        $breakdown = attempt_scheduler::queue_breakdown($experimentid);
        // One snapshot per poll, for every region that shows the experiment (#128).
        $status = \local_catquizlab\local\experiment_status::snapshot($experimentid, true);
        $verdict = situation::assess($experimentid, $breakdown);

        // Scoped to the experiment in view. Counting the whole installation
        // beside a table that shows one experiment produced two truths on one
        // page, and "0 waiting" for the site was read as "0 waiting" for the
        // experiment somebody had just queued fifty sittings for. With no
        // experiment in view the counts are the site's, and say so.
        $scope = $experimentid > 0
            ? 'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = :experimentid) AND status = :status'
            : 'status = :status';
        $count = static function (int $status) use ($DB, $scope, $experimentid): int {
            // Only the placeholders the SQL has. Passing experimentid to the
            // site-wide form, which has no :experimentid, is a DML parameter
            // mismatch.
            $params = ['status' => $status];
            if ($experimentid > 0) {
                $params['experimentid'] = $experimentid;
            }

            return (int) $DB->count_records_select('local_catquizlab_attempt', $scope, $params);
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
            'regions'        => self::regions($experimentid),
            // The runs of the experiment in view, each carrying the same
            // verdict the page renders from. One snapshot, one source: the
            // header and the table disagreed because they asked two different
            // questions a few seconds apart.
            'runs'           => self::run_rows($experimentid),
            'experiment'     => self::experiment_summary($experimentid),
            'progress'       => $status !== null
                ? ['label' => $status['label'], 'reason' => $status['reason']] + $status['progress']
                : live_progress::snapshot($experimentid),
        ] + ($experimentid === 0 ? [
            // All experiments (#158): the same figures the page was drawn from, for this page of sections.
            'overview'       => self::overview_payload(\local_catquizlab\local\progress_overview::build($page)),
        ] : []) + ($status !== null ? [
            // The experiment's status bar (#128), from the same snapshot.
            'status'         => self::status_view($status),
        ] : []);
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

        global $OUTPUT;

        $rows = [];
        $runs = $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC');
        // One reading for every run of the poll, the same the progress card reads (#141 AK6, #160 AK12).
        $figures = \local_catquizlab\local\run_figures::read(array_keys($runs), time());
        // The site's queue once for every card, not once per run (#160 AK12).
        $sitequeue = attempt_scheduler::queue_breakdown();
        foreach ($runs as $run) {
            $f = $figures['runs'][(int) $run->id];
            $card = \local_catquizlab\local\status_report::run($run, $f, $figures['workers'], $sitequeue);
            $total = (int) $f['total'];
            $done = (int) $f['collected'];

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
                // The progress card's percentage: 99 until it really is 100 (#141 AK6).
                'percent' => $total > 0 ? ($done < $total ? min(99, (int) floor(100 * $done / $total)) : 100) : 0,
                'open'    => (int) $f['open'],
                'failed'  => (int) $f['failed'],
            ];
        }

        return $rows;
    }

    /**
     * The overview as the poll serves it: the figures the page shows, nothing it does not.
     *
     * @param array $overview progress_overview::build().
     * @return array
     */
    protected static function overview_payload(array $overview): array {
        $run = static fn(array $r): array => [
            'runid' => (int) $r['runid'], 'done' => (int) $r['done'], 'total' => (int) $r['total'],
            'percent' => (int) $r['percent'], 'held' => (bool) $r['held'], 'sittingstext' => (string) $r['sittingstext'],
            'statuslabel' => (string) $r['statuslabel'],
            'questions' => ['text' => (string) $r['questions']['text'],
                'discardedtext' => (string) $r['questions']['discardedtext']],
        ];
        $times = static fn(array $t): array => array_map(static fn(array $r): array => [
            'runid' => (int) $r['runid'], 'reliability' => (string) $r['reliability'],
            'text' => ['started' => $r['text']['started'], 'elapsed' => $r['text']['elapsed'],
                'remaining' => $r['text']['remaining'], 'finish' => $r['text']['finish'], 'own' => $r['text']['own'],
                'owntime' => $r['text']['owntime'], 'ahead' => $r['text']['ahead'], 'reason' => $r['text']['reason'],
                'basis' => $r['text']['basis']],
        ], $t['runs']);

        return [
            'totals' => [
                'done' => (int) $overview['totals']['done'], 'total' => (int) $overview['totals']['total'],
                'percent' => (int) $overview['totals']['percent'], 'text' => (string) $overview['totals']['text'],
                'sittingstext' => (string) $overview['sittingstext'],
                'questions' => ['text' => (string) $overview['totals']['questions']['text'],
                    'percent' => (int) $overview['totals']['questions']['percent']],
                'states' => array_map(
                    static fn(array $x): array => ['state' => $x['state'], 'count' => (int) $x['count']],
                    $overview['totals']['states']
                ),
            ],
            'time' => ['reliability' => (string) $overview['time']['reliability'],
                'text' => $overview['time']['text']],
            'experiments' => array_map(static fn(array $e): array => [
                'experimentid' => (int) $e['experimentid'], 'state' => (string) $e['state'], 'label' => (string) $e['label'],
                'reason' => (string) $e['reason'], 'done' => (int) $e['done'], 'total' => (int) $e['total'],
                'percent' => (int) $e['percent'], 'sittingstext' => (string) $e['sittingstext'],
                'questions' => ['text' => (string) $e['questions']['text'], 'percent' => (int) $e['questions']['percent']],
                'time' => ['reliability' => (string) $e['time']['reliability'], 'text' => [
                    'started' => $e['time']['text']['started'], 'elapsed' => $e['time']['text']['elapsed'],
                    'finish' => $e['time']['text']['finish'], 'remaining' => $e['time']['text']['remaining'],
                    'own' => $e['time']['text']['own']], 'runs' => $times($e['time'])],
                'runs' => array_map($run, $e['runs']),
            ], $overview['experiments']),
        ];
    }

    /**
     * The experiment's own headline figures.
     *
     * @param int $experimentid The experiment, or 0 for none.
     * @return array
     */
    protected static function experiment_summary(int $experimentid): array {
        $status = \local_catquizlab\local\experiment_status::snapshot($experimentid);
        if ($status === null) {
            return ['state' => '', 'label' => '', 'done' => 0, 'total' => 0, 'percent' => 0];
        }

        // The one status (#128): the runner's own label said "Running" while
        // the header said "Blocked".
        return [
            'state'   => (string) $status['phase'],
            'label'   => (string) $status['label'],
            'done'    => (int) $status['done'],
            'total'   => (int) $status['total'],
            'percent' => (int) $status['percent'],
        ];
    }

    /**
     * The status bar's fields, as the poll serves them.
     *
     * @param array $status experiment_status::snapshot().
     * @return array
     */
    protected static function status_view(array $status): array {
        return [
            'phase'       => (string) $status['phase'],
            'label'       => (string) $status['label'],
            'sittings'    => (string) $status['sittings'],
            'problems'    => (int) $status['problems'],
            'problemtext' => (string) $status['problemtext'],
            'reason'      => (string) $status['reason'],
            'technical'   => (string) $status['technical'],
            'computed'    => (int) $status['computed'],
            'computedtext' => (string) $status['computedtext'],
            'next'        => (string) $status['next']['kind'],
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
     * @param int $experimentid The experiment in view, or 0 for the installation.
     * @return array[]
     */
    protected static function regions(int $experimentid = 0): array {
        // One count for the card and for the numbers beside it, and scoped to
        // the experiment in view, so a poll answers for one thing. The worker
        // pool and the pipeline below stay site-wide, because they are — the
        // interface labels them as the execution environment rather than as
        // part of this experiment.
        $breakdown = \local_catquizlab\local\attempt_scheduler::queue_breakdown($experimentid);
        $queue = \local_catquizlab\local\status_report::queue($breakdown);
        $pipeline = \local_catquizlab\local\status_report::pipeline();
        $situation = \local_catquizlab\local\situation::assess($experimentid, $breakdown);

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
     * The overview's part of the answer (#158).
     *
     * @return external_single_structure
     */
    protected static function overview_structure(): external_single_structure {
        $text = static fn(string $what): external_value => new external_value(PARAM_TEXT, $what);
        $runtimes = new \core_external\external_multiple_structure(new external_single_structure([
            'runid' => new external_value(PARAM_INT, 'The run.'),
            'reliability' => new external_value(PARAM_ALPHA, 'How far to trust it.'),
            'text' => new external_single_structure(['started' => $text('Started.'), 'elapsed' => $text('Elapsed.'),
                'remaining' => $text('Until done.'), 'finish' => $text('When done.'), 'own' => $text('Own work.'),
                'owntime' => $text('Own processing time.'), 'ahead' => $text('Work ahead.'), 'reason' => $text('Why.'),
                'basis' => $text('On what its duration rests.')]),
        ]), 'Per run.');

        return new external_single_structure([
            'totals' => new external_single_structure([
                'done' => new external_value(PARAM_INT, 'Sittings done.'),
                'total' => new external_value(PARAM_INT, 'Sittings planned.'),
                'percent' => new external_value(PARAM_INT, 'From the exact denominator.'),
                'text' => $text('The population in words.'),
                'sittingstext' => $text('Done of planned sittings.'),
                'questions' => new external_single_structure(['text' => $text('Questions.'),
                    'percent' => new external_value(PARAM_INT, 'In questions.')]),
                'states' => new \core_external\external_multiple_structure(new external_single_structure([
                    'state' => new external_value(PARAM_ALPHA, 'A state.'),
                    'count' => new external_value(PARAM_INT, 'Experiments in it.'),
                ])),
            ]),
            'time' => new external_single_structure([
                'reliability' => new external_value(PARAM_ALPHA, 'How far to trust it.'),
                'text' => new external_single_structure(['status' => $text('Status.'), 'reason' => $text('Why.'),
                    'remaining' => $text('Remaining.'), 'finish' => $text('Finish.')]),
            ]),
            'experiments' => new \core_external\external_multiple_structure(new external_single_structure([
                'experimentid' => new external_value(PARAM_INT, 'The experiment.'),
                'state' => new external_value(PARAM_ALPHA, 'Its state.'),
                'label' => $text('Its state in words.'),
                'reason' => $text('Why.'),
                'done' => new external_value(PARAM_INT, 'Sittings done.'),
                'total' => new external_value(PARAM_INT, 'Sittings planned.'),
                'percent' => new external_value(PARAM_INT, 'How far along.'),
                'sittingstext' => $text('Done of planned sittings.'),
                'questions' => new external_single_structure(['text' => $text('Questions.'),
                    'percent' => new external_value(PARAM_INT, 'In questions.')]),
                'time' => new external_single_structure([
                    'reliability' => new external_value(PARAM_ALPHA, 'How far to trust it.'),
                    'text' => new external_single_structure(['started' => $text('Started.'), 'elapsed' => $text('Elapsed.'),
                        'finish' => $text('Finish.'), 'remaining' => $text('Remaining.'), 'own' => $text('Own work.')]),
                    'runs' => $runtimes,
                ]),
                'runs' => new \core_external\external_multiple_structure(new external_single_structure([
                    'runid' => new external_value(PARAM_INT, 'The run.'),
                    'done' => new external_value(PARAM_INT, 'Collected.'),
                    'total' => new external_value(PARAM_INT, 'Planned.'),
                    'percent' => new external_value(PARAM_INT, 'How far along.'),
                    'held' => new external_value(PARAM_BOOL, 'Held.'),
                    'sittingstext' => $text('Done of planned sittings.'),
                    'statuslabel' => $text('Its status.'),
                    'questions' => new external_single_structure(['text' => $text('Questions.'),
                        'discardedtext' => $text('Questions that do not count.')]),
                ])),
            ])),
        ], 'All experiments (#158), with experimentid 0 only.', VALUE_OPTIONAL);
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
                'sittingstext' => new external_value(
                    PARAM_TEXT,
                    'Done of planned sittings, in words (#141 AK8).',
                    VALUE_OPTIONAL
                ),
                'questions'    => new external_single_structure([
                    'done'        => new external_value(PARAM_INT, 'Questions answered, confirmed by the engine.'),
                    'expected'    => new external_value(
                        PARAM_INT,
                        'Questions expected; null where not determinable.',
                        VALUE_DEFAULT,
                        null,
                        NULL_ALLOWED
                    ),
                    'kind'        => new external_value(PARAM_ALPHA, 'exact, estimate, bound or unknown.'),
                    'kindlabel'   => new external_value(PARAM_TEXT, 'That, in words.'),
                    'percent'     => new external_value(PARAM_INT, 'How far along, in questions.'),
                    'hasexpected' => new external_value(PARAM_BOOL, 'Whether there is a denominator.'),
                    'text'        => new external_value(PARAM_TEXT, 'Done of expected, in words.'),
                    'discarded'   => new external_value(PARAM_INT, 'Answered in executions that do not count.'),
                    'discardedtext' => new external_value(PARAM_TEXT, 'That, in words, or empty.'),
                ], 'Progress in questions (#141).', VALUE_OPTIONAL),
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
                        'sittingstext' => new external_value(PARAM_TEXT, 'Done of planned sittings, in words.', VALUE_OPTIONAL),
                        'held'     => new external_value(PARAM_BOOL, 'Whether it was stopped.'),
                        'questions' => new external_single_structure([
                        'done'        => new external_value(PARAM_INT, 'Questions answered, confirmed by the engine.'),
                        'expected'    => new external_value(
                            PARAM_INT,
                            'Questions expected; null where not determinable.',
                            VALUE_DEFAULT,
                            null,
                            NULL_ALLOWED
                        ),
                        'kind'        => new external_value(PARAM_ALPHA, 'exact, estimate, bound or unknown.'),
                        'kindlabel'   => new external_value(PARAM_TEXT, 'That, in words.'),
                        'percent'     => new external_value(PARAM_INT, 'How far along, in questions.'),
                        'hasexpected' => new external_value(PARAM_BOOL, 'Whether there is a denominator.'),
                        'text'        => new external_value(PARAM_TEXT, 'Done of expected, in words.'),
                        'discarded'   => new external_value(PARAM_INT, 'Answered in executions that do not count.'),
                        'discardedtext' => new external_value(PARAM_TEXT, 'That, in words, or empty.'),
                        ], 'Progress in questions (#141).', VALUE_OPTIONAL),
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
                // Started, elapsed, finish, remaining (#125): computed on the
                // server with each poll, so a reload cannot move the start.
                'time'         => new external_single_structure([
                    'reliability' => new external_value(PARAM_ALPHA, 'How far to trust the times.'),
                    'computed'    => new external_value(PARAM_INT, 'When this snapshot was taken.'),
                    'text'        => new external_single_structure([
                        'started'   => new external_value(PARAM_TEXT, 'Started, with the time zone.'),
                        'elapsed'   => new external_value(PARAM_TEXT, 'Elapsed.'),
                        'finish'    => new external_value(PARAM_TEXT, 'Estimated or actual finish.'),
                        'remaining' => new external_value(PARAM_TEXT, 'Until all runs are done, waiting included.'),
                        'own'       => new external_value(PARAM_TEXT, 'The experiment\'s own remaining work (#160).'),
                        'status'    => new external_value(PARAM_TEXT, 'How far to trust it.'),
                        'reason'    => new external_value(PARAM_TEXT, 'Why there is no running estimate.'),
                        'method'    => new external_value(PARAM_TEXT, 'How it was computed.'),
                        'computed'  => new external_value(PARAM_TEXT, 'The snapshot time.'),
                    ]),
                    'runs'        => new \core_external\external_multiple_structure(
                        new external_single_structure([
                            'runid'       => new external_value(PARAM_INT, 'The run.'),
                            'reliability' => new external_value(PARAM_ALPHA, 'How far to trust it.'),
                            'text'        => new external_single_structure([
                                'started'   => new external_value(PARAM_TEXT, 'Started.'),
                                'elapsed'   => new external_value(PARAM_TEXT, 'Elapsed.'),
                                'remaining' => new external_value(PARAM_TEXT, 'Until the run is done, waiting included.'),
                                'finish'    => new external_value(PARAM_TEXT, 'When it is done.'),
                                'own'       => new external_value(PARAM_TEXT, 'Its own remaining work (#160).'),
                                'owntime'   => new external_value(PARAM_TEXT, 'Its own processing time at its workers now.'),
                                'ahead'     => new external_value(PARAM_TEXT, 'Work of other runs ahead of it, or empty.'),
                                'reason'    => new external_value(PARAM_TEXT, 'Why there is no figure, or empty.'),
                                'basis'     => new external_value(PARAM_TEXT, 'On what its duration rests (#160 AK10).'),
                            ]),
                            'own'         => new external_single_structure([
                                'sittings'     => new external_value(PARAM_INT, 'Sittings left.'),
                                'questions'    => new external_value(
                                    PARAM_INT,
                                    'Questions left; null where unknown.',
                                    VALUE_DEFAULT,
                                    null,
                                    NULL_ALLOWED
                                ),
                                'questionkind' => new external_value(PARAM_ALPHA, 'exact, estimate, bound or unknown.'),
                                'workseconds'  => new external_value(
                                    PARAM_INT,
                                    'Worker seconds left; null without a duration.',
                                    VALUE_DEFAULT,
                                    null,
                                    NULL_ALLOWED
                                ),
                                'basis'        => new external_value(PARAM_ALPHA, 'run, strategy, experiment, questions or none.'),
                                'slots'        => new external_value(PARAM_FLOAT, 'Workers it has now.'),
                                'seconds'      => new external_value(
                                    PARAM_INT,
                                    'Own processing time; null without workers.',
                                    VALUE_DEFAULT,
                                    null,
                                    NULL_ALLOWED
                                ),
                            ], 'Its own remaining work, from the run alone (#160).'),
                            'remaining'   => new external_value(
                                PARAM_INT,
                                'Seconds until done; null without a forecast.',
                                VALUE_DEFAULT,
                                null,
                                NULL_ALLOWED
                            ),
                            'finish'      => new external_value(
                                PARAM_INT,
                                'When done; null without a forecast.',
                                VALUE_DEFAULT,
                                null,
                                NULL_ALLOWED
                            ),
                        ]),
                        'Per run.'
                    ),
                ], 'The times.'),
            ], 'The progress snapshot the page renders from.'),
            'status'         => new external_single_structure([
                'phase'        => new external_value(PARAM_ALPHA, 'The phase.'),
                'label'        => new external_value(PARAM_TEXT, 'The phase, in words.'),
                'sittings'     => new external_value(PARAM_TEXT, 'Sittings done of planned.'),
                'problems'     => new external_value(PARAM_INT, 'Sittings failed for good plus runs held.'),
                'problemtext'  => new external_value(PARAM_TEXT, 'The problems, in words.'),
                'reason'       => new external_value(PARAM_TEXT, 'Why, where the phase needs one.'),
                'technical'    => new external_value(PARAM_TEXT, 'Worker figures, apart.'),
                'computed'     => new external_value(PARAM_INT, 'When the snapshot was taken.'),
                'computedtext' => new external_value(PARAM_TEXT, 'That time, in words.'),
                'next'         => new external_value(PARAM_ALPHA, 'The kind of the next step.'),
            ], 'The experiment status bar (#128).', VALUE_OPTIONAL),
            'overview'       => self::overview_structure(),
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
