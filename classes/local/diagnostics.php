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
 * Diagnostics: ranking and detection measures for deficit recovery.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Measures how well the algorithm recovers a person's true ability deficits (E4.2).
 *
 * A person's true and estimated abilities are given per subscale as two aligned
 * numeric arrays; a lower value means a larger deficit. From these the class
 * derives how well the estimate ranks and detects the true deficits: the Spearman
 * rank correlation, the Top-k deficit agreement, nDCG@k over the deficit ranking,
 * and — turning values into deficit labels at a threshold — the confusion matrix
 * of detected vs. true deficits with precision, recall, F1, accuracy and
 * specificity. It is pure and side-effect-free, evaluating against the ground
 * truth the plugin already holds, and is fully testable without any engine.
 */
class diagnostics {
    /**
     * Full diagnostic summary for one person's subscale profile.
     *
     * @param array $truevalues True abilities per subscale (lower = larger deficit).
     * @param array $estvalues Estimated abilities per subscale, aligned with $truevalues.
     * @param int $k The k for the Top-k and nDCG measures.
     * @param float $threshold Deficit threshold: a value below it counts as a deficit.
     * @return array The composed diagnostics (spearman, topk, ndcg, confusion).
     */
    public static function evaluate(array $truevalues, array $estvalues, int $k, float $threshold): array {
        return [
            'spearman'  => self::spearman($truevalues, $estvalues),
            'topk'      => self::topk_agreement($truevalues, $estvalues, $k),
            'ndcg'      => self::ndcg_at_k($truevalues, $estvalues, $k),
            'confusion' => self::confusion(
                self::deficit_labels($truevalues, $threshold),
                self::deficit_labels($estvalues, $threshold)
            ),
        ];
    }

    /**
     * Spearman rank correlation between the true and estimated profiles.
     *
     * @param array $truevalues True values.
     * @param array $estvalues Estimated values.
     * @return float|null The correlation, or null when undefined.
     */
    public static function spearman(array $truevalues, array $estvalues): ?float {
        $truevalues = array_values($truevalues);
        $estvalues = array_values($estvalues);
        if (count($truevalues) !== count($estvalues) || count($truevalues) < 2) {
            return null;
        }
        return self::round_or_null(self::pearson(self::ranks($truevalues), self::ranks($estvalues)));
    }

    /**
     * Overlap of the k most-deficient subscales between true and estimate.
     *
     * @param array $truevalues True values.
     * @param array $estvalues Estimated values.
     * @param int $k The number of most-deficient subscales to compare.
     * @return array The k, the overlap count and the overlap fraction.
     */
    public static function topk_agreement(array $truevalues, array $estvalues, int $k): array {
        $kk = max(0, min($k, count($truevalues)));
        $truetop = array_slice(self::ascending_indices($truevalues), 0, $kk);
        $esttop = array_slice(self::ascending_indices($estvalues), 0, $kk);
        $overlap = count(array_intersect($truetop, $esttop));

        return [
            'k'        => $k,
            'overlap'  => $overlap,
            'fraction' => $kk > 0 ? round($overlap / $kk, 6) : 0.0,
        ];
    }

    /**
     * Normalised discounted cumulative gain of the estimated deficit ranking.
     *
     * Relevance grades come from the true deficit order (most deficient scores
     * highest); the estimated top-k ordering is scored against the ideal one.
     *
     * @param array $truevalues True values.
     * @param array $estvalues Estimated values.
     * @param int $k The cut-off.
     * @return float nDCG@k in [0,1].
     */
    public static function ndcg_at_k(array $truevalues, array $estvalues, int $k): float {
        $n = count($truevalues);
        if ($n === 0) {
            return 0.0;
        }
        $kk = max(0, min($k, $n));

        $trueorder = self::ascending_indices($truevalues);
        $relevance = array_fill(0, $n, 0.0);
        foreach ($trueorder as $position => $index) {
            $relevance[$index] = (float) ($n - $position);
        }

        $dcg = self::dcg(array_slice(self::ascending_indices($estvalues), 0, $kk), $relevance);
        $idcg = self::dcg(array_slice($trueorder, 0, $kk), $relevance);

        return $idcg > 0.0 ? round($dcg / $idcg, 6) : 0.0;
    }

    /**
     * Confusion matrix of detected vs. true deficit labels.
     *
     * @param array $truedeficit Boolean true-deficit labels per subscale.
     * @param array $estdeficit Boolean detected-deficit labels, aligned.
     * @return array tp, fp, fn, tn and the derived rates.
     */
    public static function confusion(array $truedeficit, array $estdeficit): array {
        [$tp, $fp, $fn, $tn] = self::tally($truedeficit, $estdeficit);
        return ['tp' => $tp, 'fp' => $fp, 'fn' => $fn, 'tn' => $tn] + self::rates($tp, $fp, $fn, $tn);
    }

    /**
     * Turn per-subscale values into boolean deficit labels at a threshold.
     *
     * @param array $values The values.
     * @param float $threshold The threshold.
     * @param bool $below When true, values below the threshold are deficits.
     * @return array Boolean labels aligned with $values.
     */
    public static function deficit_labels(array $values, float $threshold, bool $below = true): array {
        $labels = [];
        foreach ($values as $key => $value) {
            $labels[$key] = $below ? ($value < $threshold) : ($value > $threshold);
        }
        return $labels;
    }

    /**
     * Count true/false positives and negatives.
     *
     * @param array $truedeficit True labels.
     * @param array $estdeficit Detected labels.
     * @return array A list: [tp, fp, fn, tn].
     */
    protected static function tally(array $truedeficit, array $estdeficit): array {
        $tp = $fp = $fn = $tn = 0;
        foreach ($truedeficit as $key => $traw) {
            $expected = !empty($traw);
            $detected = !empty($estdeficit[$key]);
            if ($expected) {
                $detected ? $tp++ : $fn++;
            } else {
                $detected ? $fp++ : $tn++;
            }
        }
        return [$tp, $fp, $fn, $tn];
    }

    /**
     * Derive precision, recall, F1, accuracy and specificity from the counts.
     *
     * @param int $tp True positives.
     * @param int $fp False positives.
     * @param int $fn False negatives.
     * @param int $tn True negatives.
     * @return array The derived rates (null when undefined).
     */
    protected static function rates(int $tp, int $fp, int $fn, int $tn): array {
        $precision = self::safe_div($tp, $tp + $fp);
        $recall = self::safe_div($tp, $tp + $fn);
        $f1 = ($precision !== null && $recall !== null && ($precision + $recall) > 0.0)
            ? 2.0 * $precision * $recall / ($precision + $recall)
            : null;

        return [
            'precision'   => self::round_or_null($precision),
            'recall'      => self::round_or_null($recall),
            'f1'          => self::round_or_null($f1),
            'accuracy'    => self::round_or_null(self::safe_div($tp + $tn, $tp + $fp + $fn + $tn)),
            'specificity' => self::round_or_null(self::safe_div($tn, $tn + $fp)),
        ];
    }

    /**
     * Discounted cumulative gain of an ordered index list.
     *
     * @param int[] $orderedindices Item indices in ranked order.
     * @param float[] $relevance Relevance grade per index.
     * @return float
     */
    protected static function dcg(array $orderedindices, array $relevance): float {
        $dcg = 0.0;
        $rank = 1;
        foreach ($orderedindices as $index) {
            $dcg += ($relevance[$index] ?? 0.0) / log($rank + 1, 2);
            $rank++;
        }
        return $dcg;
    }

    /**
     * Positions of the values sorted ascending (lowest first).
     *
     * @param array $values The values.
     * @return int[] Indices ordered by ascending value.
     */
    protected static function ascending_indices(array $values): array {
        $values = array_values($values);
        $indices = array_keys($values);
        usort($indices, static fn($a, $b) => $values[$a] <=> $values[$b]);
        return $indices;
    }

    /**
     * Fractional ranks (1-based, averaged over ties) of the values.
     *
     * @param array $values The values.
     * @return float[] Ranks aligned with the input order.
     */
    protected static function ranks(array $values): array {
        $n = count($values);
        $order = self::ascending_indices($values);
        $ranks = array_fill(0, $n, 0.0);

        $i = 0;
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && $values[$order[$j + 1]] == $values[$order[$i]]) {
                $j++;
            }
            $averagerank = ($i + $j) / 2.0 + 1.0;
            for ($p = $i; $p <= $j; $p++) {
                $ranks[$order[$p]] = $averagerank;
            }
            $i = $j + 1;
        }
        return $ranks;
    }

    /**
     * Pearson correlation of two aligned series, or null when undefined.
     *
     * @param float[] $xs First series.
     * @param float[] $ys Second series.
     * @return float|null
     */
    protected static function pearson(array $xs, array $ys): ?float {
        $n = count($xs);
        if ($n < 2) {
            return null;
        }
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;

        $sxy = $sxx = $syy = 0.0;
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
     * Divide, returning null when the denominator is zero.
     *
     * @param float $numerator The numerator.
     * @param float $denominator The denominator.
     * @return float|null
     */
    protected static function safe_div(float $numerator, float $denominator): ?float {
        return $denominator > 0.0 ? $numerator / $denominator : null;
    }

    /**
     * Round to 6 decimals, preserving null.
     *
     * @param float|null $value The value.
     * @return float|null
     */
    protected static function round_or_null(?float $value): ?float {
        return $value === null ? null : round($value, 6);
    }
}
