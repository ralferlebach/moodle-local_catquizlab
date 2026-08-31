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
     * Gather a stored metric value across the replications of one cell.
     *
     * A cell — one full factor combination — is the only unit whose spread is
     * replication noise. Pooling an experiment's runs regardless of cell mixes
     * that noise with the systematic differences between conditions, and the
     * resulting standard deviation answers no question anyone asked: it is
     * large precisely when the experiment worked.
     *
     * @param int $experimentid The experiment.
     * @param string $metric The metric key (e.g. rmse, bias).
     * @param string $scope The result scope (default 'run').
     * @param string|null $cellkey Restrict to one cell; null returns every cell separately.
     * @return array<string, float[]> Cell key => values, ordered by replication.
     */
    public static function metric_series_by_cell(
        int $experimentid,
        string $metric,
        string $scope = 'run',
        ?string $cellkey = null
    ): array {
        global $DB;

        $params = ['eid' => $experimentid, 'metric' => $metric, 'scope' => $scope];
        $where = 'r.experimentid = :eid AND res.metric = :metric AND res.scope = :scope';
        if ($cellkey !== null) {
            $where .= ' AND r.cellkey = :cellkey';
            $params['cellkey'] = $cellkey;
        }

        $sql = "SELECT res.id, res.value, r.cellkey
                  FROM {local_catquizlab_run} r
                  JOIN {local_catquizlab_result} res ON res.runid = r.id
                 WHERE {$where}
              ORDER BY r.cellkey ASC, r.replication ASC, r.id ASC";
        $rows = $DB->get_records_sql($sql, $params);

        $series = [];
        foreach ($rows as $row) {
            if ($row->value !== null) {
                $series[(string) $row->cellkey][] = (float) $row->value;
            }
        }

        return $series;
    }

    /**
     * Within-cell dispersion of a metric, one entry per cell.
     *
     * @param int $experimentid The experiment.
     * @param string $metric The metric key.
     * @param string $scope The result scope.
     * @return array<string, array> Cell key => the statistics from {@see self::stability()}.
     */
    public static function stability_by_cell(int $experimentid, string $metric, string $scope = 'run'): array {
        $out = [];
        foreach (self::metric_series_by_cell($experimentid, $metric, $scope) as $cellkey => $values) {
            $out[$cellkey] = self::stability($values);
        }

        return $out;
    }

    /**
     * Gather a stored metric value across the runs of an experiment.
     *
     * @deprecated since 0.2.7. Pooling every run of an experiment mixes
     * replication noise with the differences between experimental conditions,
     * so the dispersion it produces is not interpretable. Use
     * {@see self::metric_series_by_cell()} and aggregate within a cell first.
     *
     * @param int $experimentid The experiment.
     * @param string $metric The metric key (e.g. rmse, bias).
     * @param string $scope The result scope (default 'run').
     * @return float[] The values, ordered by replication then run id.
     */
    public static function metric_series(int $experimentid, string $metric, string $scope = 'run'): array {
        $values = [];
        foreach (self::metric_series_by_cell($experimentid, $metric, $scope) as $cellvalues) {
            foreach ($cellvalues as $value) {
                $values[] = $value;
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
