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
 * Ad-hoc task that aggregates a run's results.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\task;

use local_catquizlab\local\result_aggregator;
use local_catquizlab\local\subscale_evaluator;

/**
 * Computes and persists a run's evaluation results when run by cron.
 *
 * The run id (and optional pool size) travel in the task's custom data. Running
 * the aggregation as a task keeps large evaluations off the web request, so no
 * page can time out while metrics are computed. Aggregation only reads collected
 * traces and writes result rows, so it does not depend on the master switch.
 */
class aggregate_results extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:aggregateresults', 'local_catquizlab');
    }

    /**
     * Aggregate the results of the run named in the custom data.
     *
     * @return void
     */
    /**
     * Queue the aggregation of a run, once.
     *
     * @param int $runid The run to aggregate.
     * @param int|null $poolsize The pool size for exposure rates, when known.
     * @return void
     */
    public static function queue(int $runid, ?int $poolsize = null): void {
        $task = new self();
        $task->set_custom_data(['runid' => $runid, 'poolsize' => $poolsize]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Aggregate the run's results and finish it.
     *
     * @return void
     */
    public function execute(): void {
        // Announce which task this is, and continue the id of the click that
        // queued it: a failure inside a task otherwise reads as a failure from
        // nowhere.
        \local_catquizlab\local\debug_trace::enter_task(
            '\\local_catquizlab\\task\\aggregate_results',
            (string) ($this->get_custom_data()->correlationid ?? '')
        );

        $data = $this->get_custom_data();
        $runid = (int) ($data->runid ?? 0);
        if ($runid <= 0) {
            return;
        }
        $poolsize = isset($data->poolsize) ? (int) $data->poolsize : null;

        try {
            $count = result_aggregator::aggregate($runid, $poolsize);
            $dpf = subscale_evaluator::evaluate_run($runid);
        } catch (\Throwable $e) {
            // A run whose aggregation threw is not finished, and saying so is
            // the whole point: the alternative is a run that sits in
            // AGGREGATING for ever with nobody able to say why.
            \local_catquizlab\local\run_lifecycle::aggregated($runid, false, $e->getMessage());
            mtrace('local_catquizlab: aggregation failed for run ' . $runid . ': ' . $e->getMessage());

            return;
        }

        mtrace("local_catquizlab: aggregated {$count} result row(s) for run {$runid}"
            . " (DPF over {$dpf['n']} person(s)).");

        // The postcondition of aggregating a run: something was written. A run
        // that produced no result row has not been evaluated, whatever the
        // absence of an exception suggests.
        if ($count <= 0) {
            \local_catquizlab\local\run_lifecycle::aggregated($runid, false, 'aggregation-produced-no-results');
            mtrace('local_catquizlab: run ' . $runid . ' produced no result rows.');

            return;
        }

        // Finished first, then the event: a listener that reads the run status
        // must not find it still aggregating.
        \local_catquizlab\local\run_lifecycle::aggregated($runid, true);

        \local_catquizlab\event\run_aggregated::create([
            'objectid' => $runid,
            'context'  => \context_system::instance(),
        ])->trigger();
    }
}
