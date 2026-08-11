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
 * Report builder: assemble result data for the report page.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Gathers stored results into report-ready structures (E4.5).
 *
 * For a single run it groups the stored metric rows by scope (run and each
 * stratum) and exposes the key run-scope scalars for charting. For an experiment
 * it assembles, per metric, the value series across runs together with its
 * stability, ready for a trend chart. It only reads the run and result tables,
 * so it is testable without the engine; the page layer turns these structures
 * into tables and Moodle charts.
 */
class report_builder {
    /** @var string[] The default run-scope scalars shown for a run. */
    public const RUN_SCALARS = ['bias', 'rmse', 'mae', 'correlation', 'meanse', 'meanlength'];

    /** @var string[] The default metrics tracked across an experiment. */
    public const EXPERIMENT_METRICS = ['rmse', 'bias', 'correlation'];

    /**
     * Group a run's stored results by scope and metric.
     *
     * @param int $runid The run.
     * @return array{runid: int, cellkey: string, scopes: array<string, array<string, float|null>>}
     */
    public static function run_report(int $runid): array {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
        $results = $DB->get_records('local_catquizlab_result', ['runid' => $runid], 'scope ASC, metric ASC');

        $scopes = [];
        foreach ($results as $result) {
            $scopes[$result->scope][$result->metric] = $result->value === null ? null : (float) $result->value;
        }

        return ['runid' => $runid, 'cellkey' => (string) $run->cellkey, 'scopes' => $scopes];
    }

    /**
     * The key run-scope scalar metrics of a run, for a bar chart.
     *
     * @param int $runid The run.
     * @param string[]|null $metrics Which metrics (defaults to RUN_SCALARS).
     * @return array<string, float> Present, non-null metrics as label => value.
     */
    public static function run_scalars(int $runid, ?array $metrics = null): array {
        $metrics = $metrics ?? self::RUN_SCALARS;
        $runscope = self::run_report($runid)['scopes']['run'] ?? [];

        $scalars = [];
        foreach ($metrics as $metric) {
            if (array_key_exists($metric, $runscope) && $runscope[$metric] !== null) {
                $scalars[$metric] = $runscope[$metric];
            }
        }
        return $scalars;
    }

    /**
     * Per-metric value series across an experiment's runs, with stability.
     *
     * @param int $experimentid The experiment.
     * @param string[]|null $metrics Which metrics (defaults to EXPERIMENT_METRICS).
     * @return array<string, array{series: float[], stability: array}>
     */
    public static function experiment_report(int $experimentid, ?array $metrics = null): array {
        $metrics = $metrics ?? self::EXPERIMENT_METRICS;

        $report = [];
        foreach ($metrics as $metric) {
            $series = trend_analysis::metric_series($experimentid, $metric, 'run');
            $report[$metric] = [
                'series'    => $series,
                'stability' => trend_analysis::stability($series),
            ];
        }
        return $report;
    }
}
