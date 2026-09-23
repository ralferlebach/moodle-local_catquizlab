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

            $workers[] = [
                'workerid'   => $worker->workerid,
                'status'     => status_report::worker($worker),
                'slot'       => (int) $worker->slot,
                'statename'  => $stale
                    ? get_string('worker:statestale', 'local_catquizlab')
                    : worker_registry::status_label($state),
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
                'uptime'     => duration::human(max(0, (int) $worker->timemodified - (int) $worker->timecreated)),
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

        return [
            // The two actions the whole interface reduces to, and the line of
            // experiments waiting their turn.
            'runner'      => $experimentid > 0
                ? experiment_runner::state($experimentid) + [
                    'experimentid' => $experimentid,
                    'queued'       => execution_queue::state_of($experimentid),
                    'formurl'      => (new \moodle_url('/local/catquizlab/runs.php'))->out(false),
                    'sesskey'      => sesskey(),
                ]
                : null,
            // The same snapshot the poll serves, so the first paint and every
            // update after it come from one place.
            'progress'    => $experimentid > 0 ? live_progress::snapshot($experimentid) : null,
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
            'situation'   => situation::assess(),
            'runs'        => ['hasany' => $runs !== [], 'rows' => $runs],
            'tasks'       => task_overview::state(),
            'workers'     => ['hasany' => $workers !== [], 'rows' => $workers],
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
            'sesskey'     => sesskey(),
            'experimentid' => $experimentid,
            'canexecute'  => has_capability('local/catquizlab:execute', \context_system::instance()),
        ];
    }
}
