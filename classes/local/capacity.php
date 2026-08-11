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
 * Capacity: parallelisation batching and throughput estimation.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Plans parallel worker dispatch and estimates throughput (E3.6, milestone M1).
 *
 * {@see self::plan_batches()} splits a queue into concurrency-sized batches and
 * {@see self::stagger_offsets()} spaces per-attempt starts out in time, so many
 * workers can run without a thundering herd. {@see self::throughput()} turns the
 * collected attempt runtimes into a capacity picture — mean/median runtime and
 * the estimated attempts-per-minute at a given concurrency. All pure and testable.
 */
class capacity {
    /**
     * Split a queue of attempts into concurrency-sized batches.
     *
     * @param int $queued The number of queued attempts.
     * @param int $concurrency The maximum number run at once.
     * @return int[] The size of each batch.
     */
    public static function plan_batches(int $queued, int $concurrency): array {
        $queued = max(0, $queued);
        $concurrency = max(1, $concurrency);

        $batches = [];
        $remaining = $queued;
        while ($remaining > 0) {
            $size = min($concurrency, $remaining);
            $batches[] = $size;
            $remaining -= $size;
        }
        return $batches;
    }

    /**
     * Staggered start offsets (seconds) for a number of attempts.
     *
     * @param int $count The number of attempts.
     * @param int $intervalseconds The spacing between starts.
     * @return int[] The offset per attempt.
     */
    public static function stagger_offsets(int $count, int $intervalseconds): array {
        $count = max(0, $count);
        $interval = max(0, $intervalseconds);

        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $offsets[] = $i * $interval;
        }
        return $offsets;
    }

    /**
     * Estimate throughput from collected attempt runtimes.
     *
     * @param int[] $runtimesms The per-attempt wall-clock runtimes in milliseconds.
     * @param int $concurrency The number of attempts run in parallel.
     * @return array count, meanms, medianms, wallclockms (at concurrency) and perminute.
     */
    public static function throughput(array $runtimesms, int $concurrency = 1): array {
        $runtimes = array_values(array_filter(array_map('intval', $runtimesms), static fn($v) => $v > 0));
        $count = count($runtimes);
        $concurrency = max(1, $concurrency);

        if ($count === 0) {
            return ['count' => 0, 'meanms' => null, 'medianms' => null, 'wallclockms' => 0, 'perminute' => null];
        }

        $meanms = array_sum($runtimes) / $count;
        $wallclockms = (int) round(array_sum($runtimes) / $concurrency);
        $perminute = $wallclockms > 0 ? round($count / ($wallclockms / 60000.0), 3) : null;

        return [
            'count'       => $count,
            'meanms'      => (int) round($meanms),
            'medianms'    => self::median($runtimes),
            'wallclockms' => $wallclockms,
            'perminute'   => $perminute,
        ];
    }

    /**
     * Median of a non-empty integer list.
     *
     * @param int[] $values The values.
     * @return int
     */
    protected static function median(array $values): int {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        if ($n % 2 === 1) {
            return (int) $values[$mid];
        }
        return (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }
}
