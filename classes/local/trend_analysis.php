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
 * Trend and stability analyses over metric series.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Analyses how a metric behaves across replications or a sweep parameter (E4.3).
 *
 * Given a series of metric values it reports dispersion (stability across
 * replications), a linear trend (slope and correlation against an ordered
 * parameter, e.g. increasing pool degradation), and the convergence of the
 * running mean. A reader gathers a stored metric across the runs of an
 * experiment so the analyses run on real aggregated results. The statistics are
 * pure and testable; only the reader touches the database.
 */
class trend_analysis {
    /**
     * Gather a stored metric value across the runs of an experiment.
     *
     * @param int $experimentid The experiment.
     * @param string $metric The metric key (e.g. rmse, bias).
     * @param string $scope The result scope (default 'run').
     * @return float[] The values, ordered by replication then run id.
     */
    public static function metric_series(int $experimentid, string $metric, string $scope = 'run'): array {
        global $DB;

        $sql = "SELECT res.id, res.value
                  FROM {local_catquizlab_run} r
                  JOIN {local_catquizlab_result} res ON res.runid = r.id
                 WHERE r.experimentid = :eid AND res.metric = :metric AND res.scope = :scope
              ORDER BY r.replication ASC, r.id ASC";
        $rows = $DB->get_records_sql($sql, ['eid' => $experimentid, 'metric' => $metric, 'scope' => $scope]);

        $values = [];
        foreach ($rows as $row) {
            if ($row->value !== null) {
                $values[] = (float) $row->value;
            }
        }
        return $values;
    }

    /**
     * Dispersion of a metric across replications (reproducibility).
     *
     * @param array $values The metric values.
     * @return array n, mean, sd (sample), cv, min, max and range.
     */
    public static function stability(array $values): array {
        $values = array_values(array_map('floatval', $values));
        $n = count($values);
        if ($n === 0) {
            return ['n' => 0, 'mean' => null, 'sd' => null, 'cv' => null, 'min' => null, 'max' => null, 'range' => null];
        }

        $mean = array_sum($values) / $n;
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $sd = $n > 1 ? sqrt($variance / ($n - 1)) : 0.0;
        $cv = $mean != 0.0 ? $sd / abs($mean) : null;

        return [
            'n'     => $n,
            'mean'  => round($mean, 6),
            'sd'    => round($sd, 6),
            'cv'    => $cv === null ? null : round($cv, 6),
            'min'   => round(min($values), 6),
            'max'   => round(max($values), 6),
            'range' => round(max($values) - min($values), 6),
        ];
    }

    /**
     * Linear trend of a metric against an ordered parameter.
     *
     * @param array $xs The parameter values (e.g. degradation levels).
     * @param array $ys The metric values, aligned with $xs.
     * @return array n, slope, intercept, correlation and r-squared.
     */
    public static function linear_trend(array $xs, array $ys): array {
        $xs = array_values(array_map('floatval', $xs));
        $ys = array_values(array_map('floatval', $ys));
        $n = min(count($xs), count($ys));
        $empty = ['n' => $n, 'slope' => null, 'intercept' => null, 'correlation' => null, 'r2' => null];
        if ($n < 2) {
            return $empty;
        }

        $mx = array_sum(array_slice($xs, 0, $n)) / $n;
        $my = array_sum(array_slice($ys, 0, $n)) / $n;
        $sxy = $sxx = $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $mx;
            $dy = $ys[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        if ($sxx <= 0.0) {
            return $empty;
        }

        $slope = $sxy / $sxx;
        $correlation = $syy > 0.0 ? $sxy / sqrt($sxx * $syy) : null;

        return [
            'n'           => $n,
            'slope'       => round($slope, 6),
            'intercept'   => round($my - $slope * $mx, 6),
            'correlation' => $correlation === null ? null : round($correlation, 6),
            'r2'          => $correlation === null ? null : round($correlation * $correlation, 6),
        ];
    }

    /**
     * Convergence of the running mean of a value sequence.
     *
     * @param array $values The values in order (e.g. per replication).
     * @param float $tolerance The step change under which the running mean counts as settled.
     * @return array n, the running-mean series, whether it converged and at which index.
     */
    public static function convergence(array $values, float $tolerance = 0.01): array {
        $values = array_values(array_map('floatval', $values));
        $n = count($values);

        $running = [];
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $values[$i];
            $running[] = round($sum / ($i + 1), 6);
        }

        $convergedat = null;
        for ($i = 1; $i < $n; $i++) {
            if (abs($running[$i] - $running[$i - 1]) <= $tolerance) {
                $convergedat = $i;
                break;
            }
        }

        return [
            'n'           => $n,
            'running'     => $running,
            'converged'   => $convergedat !== null,
            'convergedat' => $convergedat,
        ];
    }
}
