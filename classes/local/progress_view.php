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

namespace local_catquizlab\local;

/**
 * Step 3: what is happening, why, or why it is not.
 *
 * The parts of an answer existed and were spread across four pages: runs on
 * one, tasks and workers on another, the queue on a third, recovery actions on
 * a fourth. Answering "why is nothing moving" meant visiting all of them and
 * holding the pieces in your head, which is the work this view does instead.
 *
 * Five sections, ordered by what blocks what:
 *
 *   1. Runs and provisioning — what should be happening
 *   2. Tasks and pipeline    — what carries it
 *   3. Workers               — who does it
 *   4. Queue                 — what is waiting
 *   5. Recovery              — what to do when it is stuck
 *
 * Every row uses the state–reason–action contract, so a run, a worker and the
 * queue all say their piece the same way.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_view {
    /**
     * Everything step 3 shows.
     *
     * @param int $experimentid Restrict to one experiment, or 0 for all.
     * @return array
     */
    public static function context(int $experimentid = 0): array {
        global $DB;

        // One snapshot for the whole page, the same the header shows (#128).
        $status = experiment_status::snapshot($experimentid);

        $component = 'local_catquizlab';

        // Only what is not finished: this section answers "what is happening",
        // and a finished run is not. The filterable list below the view covers
        // everything, including these.
        $params = [];
        $where = 'status <> :finished';
        $params['finished'] = registry::STATUS_FINISHED;
        if ($experimentid > 0) {
            $where .= ' AND experimentid = :experimentid';
            $params['experimentid'] = $experimentid;
        }

        $runs = [];
        foreach ($DB->get_records_select('local_catquizlab_run', $where, $params, 'id DESC', '*', 0, 25) as $run) {
            $counts = run_lifecycle::attempt_counts((int) $run->id);
            $runs[] = [
                'id'       => (int) $run->id,
                'cellkey'  => $run->cellkey,
                'status'   => status_report::run($run),
                'progress' => get_string('report:progress', $component, (object) [
                    'done'  => $counts['collected'],
                    'total' => $counts['total'],
                ]),
                'url'      => (new \moodle_url('/local/catquizlab/runs.php', [
                    'runid' => (int) $run->id,
                ]))->out(false),
            ];
        }

        // Every worker, not only the live ones, with what the operations view
        // needs to answer "what happened to the one that was running this":
        // its slot, what it is playing, when it last reported, how much it has
        // done, how long it has been at it, and why it stopped.
        $workers = [];
        foreach (worker_registry::recent() as $worker) {
            $state = (int) $worker->status;
            $stale = $state === worker_registry::STATUS_RUNNING
                && (int) $worker->heartbeat < time() - worker_registry::HEARTBEAT_TIMEOUT;
            // Up, but not yet claiming: its staggered start is still ahead (#120).
            $waiting = worker_registry::waits_for($worker) > 0;

            $workers[] = [
                'workerid'   => $worker->workerid,
                // Straight into the log, filtered to this worker (#90).
                'logurl'     => (new \moodle_url('/local/catquizlab/logs.php', [
                    'workerid' => $worker->workerid, 'hours' => 24,
                ]))->out(false),
                'status'     => status_report::worker($worker),
                'slot'       => (int) $worker->slot,
                'statename'  => $stale
                    ? get_string('worker:statestale', 'local_catquizlab')
                    : ($waiting
                        ? get_string('worker:statewaiting', 'local_catquizlab')
                        // Asked to finish its sitting and stop: draining (#124).
                        : ((int) $worker->stoprequested > 0 && in_array($state, [worker_registry::STATUS_STARTING,
                            worker_registry::STATUS_RUNNING], true)
                            ? get_string('worker:statedraining', 'local_catquizlab')
                            : worker_registry::status_label($state))),
                'waiting'    => $waiting,
                'stale'      => $stale,
                'attemptid'  => (int) $worker->currentattempt,
                'runid'      => (int) $worker->currentattempt > 0
                    ? (int) $DB->get_field('local_catquizlab_attempt', 'runid', ['id' => $worker->currentattempt])
                    : 0,
                'jobsdone'   => (int) $worker->jobsdone,
                'heartbeat'  => (int) $worker->heartbeat > 0 ? userdate((int) $worker->heartbeat) : '-',
                'heartbeatago' => (int) $worker->heartbeat > 0
                    ? duration::ago((int) $worker->heartbeat)
                    : '-',
                // Since this process took its slot (#124); the row's age is the
                // identity's, which a restart reuses.
                'uptime'     => duration::human(max(0, (int) $worker->timemodified
                    - ((int) ($worker->timestarted ?? 0) ?: (int) $worker->timecreated))),
                'lasterror'  => (string) ($worker->lasterror ?? ''),
                'haserror'   => trim((string) ($worker->lasterror ?? '')) !== '',
                'log'        => worker_launcher::log_tail((string) $worker->workerid, 10),
            ];
        }

        // The queue of the experiment in view, not of the installation. The
        // page used to show A's progress over B's blocked sittings.
        $breakdown = attempt_scheduler::queue_breakdown($experimentid);

        // Runs owning more than one scale tree. A data defect rather than a
        // state, so it is shown where somebody is already asking why nothing
        // works, with the count that makes it concrete.
        $ambiguous = [];
        foreach (scale_inventory::affected_runs() as $affected) {
            $affected['url'] = (new \moodle_url('/local/catquizlab/runs.php', [
                'runid' => $affected['runid'],
            ]))->out(false);
            $ambiguous[] = $affected;
        }

        // Its own capability: the recording holds what every operator did, with
        // parameters, and running experiments is not a reason to read that.
        $candebug = has_capability('local/catquizlab:debug', \context_system::instance());
        $debug = ($candebug && debug_trace::enabled()) ? debug_trace::entries([], 60) : [];

        // The population, as the results report it (#117): the same numbers.
        $population = $experimentid > 0 ? population_plan::describe($experimentid) : null;

        return [
            'population'  => $population,
            // The two actions the whole interface reduces to, and the line of
            // experiments waiting their turn.
            'runner'      => $status !== null
                ? [
                    // The label of the one status; no "start" for an experiment
                    // already queued or running (#128).
                    'label'      => $status['label'],
                    // Create and start are the status bar's one primary action
                    // above; here only preparing again, for a blocked experiment.
                    'canstart'   => false,
                    'canprepare' => $status['phase'] === 'blocked' && !empty($status['runner']['canprepare']),
                ] + $status['runner'] + [
                    'experimentid' => $experimentid,
                    'queued'       => execution_queue::state_of($experimentid),
                    'formurl'      => (new \moodle_url('/local/catquizlab/runs.php'))->out(false),
                    'sesskey'      => sesskey(),
                ]
                : null,
            // The same snapshot the poll serves, so the first paint and every
            // update after it come from one place.
            // With the experiment's own status label (#128): the header, this
            // card and the run summary say the same word.
            'progress'    => $status !== null
                ? ['label' => $status['label'], 'reason' => $status['reason']] + $status['progress']
                : null,
            // Null when nothing is wrong, which is what keeps the recovery
            // section folded and out of a normal day's way.
            'recovery'    => recovery_advisor::advise($experimentid),
            'execqueue'   => ['hasany' => execution_queue::entries() !== [], 'rows' => execution_queue::entries()],
            'debug'       => [
                'enabled' => $candebug && debug_trace::enabled(),
                'retention' => get_string('debug:retention', 'local_catquizlab', (object) [
                    'entries' => debug_trace::KEEP,
                    'days'    => debug_trace::KEEP_SECONDS / DAYSECS,
                ]),
                'hasany'  => $debug !== [],
                'rows'    => $debug,
            ],
            'ambiguous'   => ['hasany' => $ambiguous !== [], 'rows' => $ambiguous],
            // The same scope and the same count as the first live poll, so the
            // page does not change its mind two seconds after it is drawn.
            'situation'   => situation::assess($experimentid, $breakdown),
            'runs'        => ['hasany' => $runs !== [], 'rows' => $runs],
            'tasks'       => task_overview::state(),
            'workers'     => ['hasany' => $workers !== [], 'rows' => $workers, 'count' => count($workers)],
            // Configured, in force, live, starting, waiting, draining, free (#124).
            'capacity'    => worker_capacity::snapshot(),
            // How starts and retries are spread, and when the waiting retries are due (#120).
            'loadspreading' => worker_launcher::load_spreading(),
            'retrywindow' => worker_launcher::retry_window_message($experimentid),
            // Named so the live updater knows where to write; the other cards
            // on this page are per-row and are refreshed by a reload when the
            // rows change.
            // The same counts the list below the card shows, taken once.
            'queuestatus' => status_report::queue($breakdown) + ['region' => 'catquizlab-queue'],
            // The event log lives on step 5, on purpose: one log surface, not
            // two. This is the way into it for whatever is being looked at
            // here, already filtered.
            'logsurl'     => (new \moodle_url('/local/catquizlab/logs.php', array_filter([
                'experimentid' => $experimentid ?: null,
                'hours'        => 24,
            ])))->out(false),
            'queue'       => $breakdown + [
                // Shown individually because each needs a different response,
                // and one "queued" number conflated all four.
                'hasblocked' => $breakdown['blocked'] > 0,
                'hasnotdue'  => $breakdown['notdue'] > 0,
                'haspaused'  => $breakdown['paused'] > 0,
            ],
            'pipelinestatus' => status_report::pipeline() + ['region' => 'catquizlab-pipeline'],
            'formurl'     => (new \moodle_url('/local/catquizlab/operations.php'))->out(false),
            // Where an action's message is shown: here, not on the setup tab.
            'returnurl'   => self::return_url(),
            'sesskey'     => sesskey(),
            'experimentid' => $experimentid,
            'canexecute'  => has_capability('local/catquizlab:execute', \context_system::instance()),
        ];
    }

    /**
     * This page as a local URL, for an action to come back to.
     *
     * @return string
     */
    protected static function return_url(): string {
        global $PAGE;

        try {
            return $PAGE->has_set_url() ? $PAGE->url->out_as_local_url(false) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
