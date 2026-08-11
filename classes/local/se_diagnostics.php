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
 * Standard-error-aware diagnostic measures.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * SE-aware diagnostics that judge recovery relative to the measurement error.
 *
 * These complement {@see diagnostics} by taking the per-subscale standard error
 * into account: a subscale is only a deficit when it lies clearly (more than a
 * tolerance in standard errors) below the reference, and an estimate agrees with
 * the truth when it lies within that tolerance. Pure and testable.
 */
class se_diagnostics {
    /**
     * SE-aware deficit labels: a subscale is a deficit when its value lies more
     * than `tolerance` standard errors below the reference.
     *
     * @param array $values The per-subscale values.
     * @param float $reference The reference ability (e.g. the person's global level or 0).
     * @param array $ses The standard error per subscale, aligned with $values.
     * @param float $tolerance The tolerance in standard errors (1.0, 2.0, ...).
     * @return array Boolean deficit labels aligned with $values.
     */
    public static function deficit_labels_se(array $values, float $reference, array $ses, float $tolerance = 1.0): array {
        $labels = [];
        foreach ($values as $key => $value) {
            $se = (float) ($ses[$key] ?? 0.0);
            $labels[$key] = ((float) $value) < ($reference - $tolerance * $se);
        }
        return $labels;
    }

    /**
     * Share of subscales whose estimate lies within `tolerance` standard errors of the truth.
     *
     * @param array $truevalues True values.
     * @param array $estvalues Estimated values, aligned.
     * @param array $ses Standard errors, aligned.
     * @param float $tolerance The tolerance in standard errors.
     * @return array n, within (count) and the fraction.
     */
    public static function agreement_within_se(array $truevalues, array $estvalues, array $ses, float $tolerance = 1.0): array {
        $truevalues = array_values($truevalues);
        $estvalues = array_values($estvalues);
        $ses = array_values($ses);
        $n = min(count($truevalues), count($estvalues), count($ses));

        $within = 0;
        for ($i = 0; $i < $n; $i++) {
            if (abs((float) $estvalues[$i] - (float) $truevalues[$i]) <= $tolerance * (float) $ses[$i]) {
                $within++;
            }
        }

        return [
            'n'         => $n,
            'within'    => $within,
            'fraction'  => $n > 0 ? round($within / $n, 6) : 0.0,
            'tolerance' => $tolerance,
        ];
    }
}
