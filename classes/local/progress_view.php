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

        $workers = [];
        foreach (worker_registry::live() as $worker) {
            $workers[] = [
                'workerid' => $worker->workerid,
                'status'   => status_report::worker($worker),
                'log'      => worker_launcher::log_tail((string) $worker->workerid, 10),
            ];
        }

        $breakdown = attempt_scheduler::queue_breakdown();

        return [
            'situation'   => situation::assess(),
            'runs'        => ['hasany' => $runs !== [], 'rows' => $runs],
            'tasks'       => task_overview::state(),
            'workers'     => ['hasany' => $workers !== [], 'rows' => $workers],
            'queuestatus' => status_report::queue(),
            'queue'       => $breakdown + [
                // Shown individually because each needs a different response,
                // and one "queued" number conflated all four.
                'hasblocked' => $breakdown['blocked'] > 0,
                'hasnotdue'  => $breakdown['notdue'] > 0,
                'haspaused'  => $breakdown['paused'] > 0,
            ],
            'pipelinestatus' => status_report::pipeline(),
            'formurl'     => (new \moodle_url('/local/catquizlab/operations.php'))->out(false),
            'sesskey'     => sesskey(),
            'experimentid' => $experimentid,
            'canexecute'  => has_capability('local/catquizlab:execute', \context_system::instance()),
        ];
    }
}
