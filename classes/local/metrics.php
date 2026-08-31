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
 * Metrics: evaluate collected attempts against the ground truth.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Computes the evaluation metrics of a run from its collected attempts (E4).
 *
 * Each attempt is a plain associative array carrying what the collect step reads
 * from a finished attempt: the person's ground-truth ability (truetheta), the
 * engine's final estimate (esttheta) and standard error (se), the test length
 * (nitems) and the items used (items). From a set of these the class derives
 * ability recovery (bias, RMSE, MAE, correlation), efficiency (test length and
 * precision) and item exposure. It is pure and side-effect-free — it evaluates
 * against the ground truth the plugin already holds, so it needs no engine and
 * is fully testable with synthetic traces.
 */
class metrics {
    /**
     * Ability recovery: how well the estimate tracks the true ability.
     *
     * @param array $attempts Attempts, each with 'truetheta' and 'esttheta'.
     * @return array The recovery statistics (n, bias, rmse, mae, correlation).
     */
    public static function ability_recovery(array $attempts): array {
        $true = [];
        $est = [];
        $errors = [];
        foreach ($attempts as $attempt) {
            $t = (float) ($attempt['truetheta'] ?? 0.0);
            $e = (float) ($attempt['esttheta'] ?? 0.0);
            $true[] = $t;
            $est[] = $e;
            $errors[] = $e - $t;
        }

        $n = count($errors);
        if ($n === 0) {
            return ['n' => 0, 'bias' => 0.0, 'rmse' => 0.0, 'mae' => 0.0, 'correlation' => null];
        }

        $bias = array_sum($errors) / $n;
        $rmse = sqrt(array_sum(array_map(static fn($x) => $x * $x, $errors)) / $n);
        $mae = array_sum(array_map('abs', $errors)) / $n;

        return [
            'n'           => $n,
            'bias'        => round($bias, 6),
            'rmse'        => round($rmse, 6),
            'mae'         => round($mae, 6),
            'correlation' => self::round_or_null(self::correlation($true, $est)),
        ];
    }

    /**
     * Efficiency: test length and final precision.
     *
     * @param array $attempts Attempts, each optionally with 'nitems' and 'se'.
     * @return array The efficiency statistics.
     */
    public static function efficiency(array $attempts): array {
        $lengths = [];
        $ses = [];
        foreach ($attempts as $attempt) {
            if (isset($attempt['nitems'])) {
                $lengths[] = (int) $attempt['nitems'];
            }
            if (isset($attempt['se'])) {
                $ses[] = (float) $attempt['se'];
            }
        }

        return [
            'nattempts'  => count($attempts),
            'meanlength' => $lengths ? round(array_sum($lengths) / count($lengths), 4) : 0.0,
            'minlength'  => $lengths ? min($lengths) : 0,
            'maxlength'  => $lengths ? max($lengths) : 0,
            'meanse'     => $ses ? round(array_sum($ses) / count($ses), 6) : null,
        ];
    }

    /**
     * Item exposure across the attempts.
     *
     * @param array $attempts Attempts, each optionally with an 'items' list.
     * @param int|null $poolsize Total items available, to report unused items.
     * @return array The exposure statistics (counts, rates, itemsused, maxrate, unused).
     */
    public static function exposure(array $attempts, ?int $poolsize = null): array {
        $counts = [];
        foreach ($attempts as $attempt) {
            foreach (($attempt['items'] ?? []) as $item) {
                $key = (string) $item;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);

        $nattempts = count($attempts);
        $rates = [];
        foreach ($counts as $key => $count) {
            $rates[$key] = $nattempts ? round($count / $nattempts, 6) : 0.0;
        }
        $used = count($counts);

        return [
            'nattempts' => $nattempts,
            'counts'    => $counts,
            'rates'     => $rates,
            'itemsused' => $used,
            'maxrate'   => $rates ? max($rates) : 0.0,
            'unused'    => $poolsize !== null ? max(0, $poolsize - $used) : null,
            'concentration' => self::concentration($rates, $poolsize),
        ];
    }

    /**
     * How unevenly the pool was used.
     *
     * A mean exposure rate says nothing about concentration: a pool where every
     * item is shown equally often and one where a tenth of the items carry the
     * whole test can have the same mean. The design cares about the second
     * case, so the primary figure here is the Gini coefficient over the
     * exposure rates — 0 when every item is used equally, approaching 1 when a
     * vanishing share of items carries everything.
     *
     * The Herfindahl index is reported alongside because it reacts more
     * sharply to a few dominant items, and the two disagreeing is itself
     * informative. Items never shown count as zero exposure, so a large unused
     * remainder raises the concentration rather than being ignored.
     *
     * @param array $rates Exposure rate per item.
     * @param int|null $poolsize Total items available, including unused ones.
     * @return array{n: int, gini: float|null, hhi: float|null, max: float, mean: float, above: float}
     */
    public static function concentration(array $rates, ?int $poolsize = null): array {
        $values = array_values(array_map('floatval', $rates));
        if ($poolsize !== null && $poolsize > count($values)) {
            $values = array_pad($values, $poolsize, 0.0);
        }

        $n = count($values);
        if ($n === 0) {
            return ['n' => 0, 'gini' => null, 'hhi' => null, 'max' => 0.0, 'mean' => 0.0, 'above' => 0.0];
        }

        sort($values);
        $total = array_sum($values);
        $mean = $total / $n;

        $gini = null;
        if ($total > 0.0) {
            // Sorted-values form of the Gini coefficient.
            $weighted = 0.0;
            foreach ($values as $i => $value) {
                $weighted += ($i + 1) * $value;
            }
            $gini = (2.0 * $weighted) / ($n * $total) - ($n + 1) / $n;
        }

        $hhi = null;
        if ($total > 0.0) {
            $sumsquares = 0.0;
            foreach ($values as $value) {
                $share = $value / $total;
                $sumsquares += $share * $share;
            }
            $hhi = $sumsquares;
        }

        // The share of items shown more often than twice the mean: a plain
        // reading of "how many items are carrying more than their share".
        $threshold = 2.0 * $mean;
        $above = 0;
        foreach ($values as $value) {
            if ($threshold > 0.0 && $value > $threshold) {
                $above++;
            }
        }

        return [
            'n'    => $n,
            'gini' => $gini === null ? null : round($gini, 6),
            'hhi'  => $hhi === null ? null : round($hhi, 6),
            'max'  => round(max($values), 6),
            'mean' => round($mean, 6),
            'above' => round($above / $n, 6),
        ];
    }

    /**
     * Full metric summary of a run's attempts.
     *
     * @param array $attempts The collected attempts.
     * @param int|null $poolsize Total items available, for exposure.
     * @return array The composed summary (abilityrecovery, efficiency, exposure).
     */
    public static function summarise(array $attempts, ?int $poolsize = null): array {
        return [
            'abilityrecovery' => self::ability_recovery($attempts),
            'efficiency'      => self::efficiency($attempts),
            'exposure'        => self::exposure($attempts, $poolsize),
        ];
    }

    /**
     * Pearson correlation of two equally long series, or null when undefined.
     *
     * @param float[] $xs First series.
     * @param float[] $ys Second series.
     * @return float|null
     */
    protected static function correlation(array $xs, array $ys): ?float {
        $n = count($xs);
        if ($n < 2) {
            return null;
        }
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;

        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $mx;
            $dy = $ys[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        if ($sxx <= 0.0 || $syy <= 0.0) {
            return null;
        }
        return $sxy / sqrt($sxx * $syy);
    }

    /**
     * Round a value to 6 decimals, preserving null.
     *
     * @param float|null $value The value.
     * @return float|null
     */
    protected static function round_or_null(?float $value): ?float {
        return $value === null ? null : round($value, 6);
    }
}
