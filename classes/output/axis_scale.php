<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Axis ranges and ticks that a reader can use.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\output;

/**
 * The plot conventions of #105, in one place.
 *
 * Axes used to run from the data's minimum to its maximum plus five per cent,
 * with five evenly spaced ticks in between: "4.55 12.53 20.5 28.48 36.45" for a
 * number of items, "−6.65 −2.99 0.66 4.32 7.98" for an ability. Here:
 *
 * - AXIS-001/002: quantities on the logit scale (ability, error, deviation)
 *   get a range symmetric around 0, with 0 as the middle tick;
 * - AXIS-004: ticks are integers or simple halves — steps of 0.5, 1, 2 or 5
 *   times a power of ten, never an arbitrary fraction of the span;
 * - counts (test length, step) get integer ticks only.
 *
 * AXIS-003 — the same range on both axes of a comparison plot — is the
 * chart's: it passes the values of both axes to symmetric().
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class axis_scale {
    /** @var string Range symmetric around 0, with 0 as a tick. */
    public const SYMMETRIC = 'symmetric';

    /** @var string Integer ticks only. */
    public const INTEGER = 'integer';

    /** @var string A plain range with round ticks. */
    public const LINEAR = 'linear';

    /**
     * A round step for a span, aiming at a number of intervals.
     *
     * 1, 2 or 5 times a power of ten — for spans of a few logits that means
     * 0.5, 1 or 2.
     *
     * @param float $span The span to cover.
     * @param int $intervals Roughly how many intervals.
     * @return float
     */
    public static function nice_step(float $span, int $intervals = 6): float {
        if (!($span > 0)) {
            return 1.0;
        }
        $raw = $span / max(1, $intervals);
        $magnitude = 10 ** floor(log10($raw));
        foreach ([1, 2, 5, 10] as $factor) {
            if ($factor * $magnitude >= $raw) {
                return $factor * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    /**
     * A range symmetric around 0 that holds every value.
     *
     * @param float[] $values The values.
     * @param float $atleast A half-range to cover at least (the ability bounds, say).
     * @return array{min: float, max: float, ticks: float[]}
     */
    public static function symmetric(array $values, float $atleast = 0.0): array {
        $half = $atleast;
        foreach ($values as $value) {
            $half = max($half, abs((float) $value));
        }
        if (!($half > 0)) {
            $half = 1.0;
        }

        // Steps a reader can count on a logit scale; the one that gives four
        // to ten intervals across the whole range.
        $step = 0.5;
        foreach ([0.5, 1.0, 2.0, 5.0, 10.0, 20.0, 50.0] as $candidate) {
            $step = $candidate;
            if ((2 * ceil($half / $candidate)) <= 10) {
                break;
            }
        }
        $bound = ceil($half / $step - 1e-9) * $step;

        return ['min' => -$bound, 'max' => $bound, 'ticks' => self::ticks(-$bound, $bound, $step)];
    }

    /**
     * An integer range with integer ticks.
     *
     * @param float[] $values The values.
     * @param bool $fromzero Whether the range starts at 0 (counts usually do).
     * @return array{min: float, max: float, ticks: float[]}
     */
    public static function integer(array $values, bool $fromzero = true): array {
        $min = $values === [] ? 0.0 : floor(min($values));
        $max = $values === [] ? 1.0 : ceil(max($values));
        if ($fromzero) {
            $min = min(0.0, $min);
        }
        if ($max <= $min) {
            $max = $min + 1;
        }
        $step = max(1.0, self::nice_step($max - $min));
        $step = ceil($step);
        $min = floor($min / $step) * $step;
        $max = ceil($max / $step) * $step;

        return ['min' => $min, 'max' => $max, 'ticks' => self::ticks($min, $max, $step)];
    }

    /**
     * A plain range with round ticks.
     *
     * @param float[] $values The values.
     * @param bool $fromzero Whether 0 must be included (a standard error, say).
     * @return array{min: float, max: float, ticks: float[]}
     */
    public static function linear(array $values, bool $fromzero = false): array {
        $min = $values === [] ? 0.0 : (float) min($values);
        $max = $values === [] ? 1.0 : (float) max($values);
        if ($fromzero) {
            $min = min(0.0, $min);
        }
        if ($max <= $min) {
            [$min, $max] = [$min - 0.5, $max + 0.5];
        }
        $step = self::nice_step($max - $min);
        $min = floor($min / $step + 1e-9) * $step;
        $max = ceil($max / $step - 1e-9) * $step;

        return ['min' => $min, 'max' => $max, 'ticks' => self::ticks($min, $max, $step)];
    }

    /**
     * Tick values from min to max in steps, without floating-point drift.
     *
     * @param float $min The first tick.
     * @param float $max The last tick.
     * @param float $step The step.
     * @return float[]
     */
    protected static function ticks(float $min, float $max, float $step): array {
        $ticks = [];
        $count = (int) round(($max - $min) / $step);
        for ($i = 0; $i <= $count; $i++) {
            $value = round($min + $i * $step, 6);
            $ticks[] = $value == 0.0 ? 0.0 : $value;
        }

        return $ticks;
    }
}
