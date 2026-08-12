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
    public function execute(): void {
        $data = $this->get_custom_data();
        $runid = (int) ($data->runid ?? 0);
        if ($runid <= 0) {
            return;
        }
        $poolsize = isset($data->poolsize) ? (int) $data->poolsize : null;

        $count = result_aggregator::aggregate($runid, $poolsize);
        $dpf = subscale_evaluator::evaluate_run($runid);
        mtrace("local_catquizlab: aggregated {$count} result row(s) for run {$runid}"
            . " (DPF over {$dpf['n']} person(s)).");

        \local_catquizlab\event\run_aggregated::create([
            'objectid' => $runid,
            'context'  => \context_system::instance(),
        ])->trigger();
    }
}
