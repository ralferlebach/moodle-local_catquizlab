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
 * How many items a sitting was given on each scale — or that this is not known.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The items administered per scale, reconstructed from the sitting's own steps (#113).
 *
 * The subscale export said "items = 0" for scales of sittings that had played
 * dozens of questions: the number came from the engine's debug context
 * (questionsperscale), and where that was missing or did not map, nothing
 * became zero. But zero means "no question of this scale was administered" —
 * and "could not be determined" is something else, which must not be read as it.
 *
 * The source of truth is what was administered: the questions of the sitting's
 * question usage, in the order Moodle recorded them (the trace's items), each
 * filed under exactly one scale by the lab's own item table. From that:
 *
 * - **direct**: the items filed under the scale itself — every item counted once;
 * - **cumulative**: those and the items of every scale below it, as the engine
 *   counts a scale's items;
 * - a **checksum**: the direct counts add up to the administered items that
 *   could be assigned.
 *
 * The count is **known** where every administered item could be assigned, and
 * **unknown** — never zero — where there are no steps to read, the run has no
 * item mapping, or an administered item is not in it: a count that leaves out
 * an item of unknown scale is a lower bound for every scale.
 *
 * The engine's own counts (its progress, or the debug context's
 * questionsperscale) are compared and every difference is a diagnosis; they are
 * used in place of the steps only where there are no steps and they are
 * consistent in themselves: the subscales' counts add up to the test's.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scale_exposure {
    /** @var string The counts are those of the sitting's own steps. */
    public const SOURCE_STEPS = 'steps';

    /** @var string No steps; the engine's counts, consistent in themselves. */
    public const SOURCE_ENGINE = 'engine';

    /** @var string The counts could not be determined. */
    public const SOURCE_UNKNOWN = 'unknown';

    /** @var string Nothing recorded of what was administered. */
    public const NO_STEPS = 'nosteps';

    /** @var string The run has no item-to-scale mapping. */
    public const NO_MAPPING = 'nomapping';

    /** @var string An administered item is not in the mapping. */
    public const UNMAPPED = 'unmapped';

    /** @var string The engine counts fewer items on a scale than were administered (pilot or unanswered items). */
    public const ENGINE_LOWER = 'enginelower';

    /** @var string The engine counts more items on a scale than were administered. */
    public const ENGINE_HIGHER = 'enginehigher';

    /** @var string The engine's counts do not add up: its subscales against its test. */
    public const ENGINE_INCONSISTENT = 'engineinconsistent';

    /** @var string The direct counts do not add up to the assigned items. */
    public const CHECKSUM = 'checksum';

    /** @var array[] The diagnostic classes of an item count: key, lowest, highest (null for open). */
    public const CLASSES = [
        ['key' => '0', 'min' => 0, 'max' => 0],
        ['key' => '1', 'min' => 1, 'max' => 1],
        ['key' => '2', 'min' => 2, 'max' => 2],
        ['key' => '3-4', 'min' => 3, 'max' => 4],
        ['key' => '5+', 'min' => 5, 'max' => null],
    ];

    /** @var string The class of a count that is not known. */
    public const CLASS_UNKNOWN = 'unknown';

    /**
     * The items administered per scale in one sitting.
     *
     * @param array $trace The sitting's trace: items (the question ids administered, in order),
     *      responses, and what the engine counted (enginevalidity, questionsperscale).
     * @param int $runid The run, for the item-to-scale mapping.
     * @param array|null $map Question id => the scale it is filed under and its ancestors;
     *      the run's own mapping by default.
     * @return array source, reason ('' where known), administered, assigned, unmapped (question ids),
     *      direct and cumulative (scale id => n), checksum (bool), diagnosis (codes),
     *      differences (scale id => [steps, engine]).
     */
    public static function reconstruct(array $trace, int $runid, ?array $map = null): array {
        $map = $map ?? engine_validity::question_scales($runid);
        $exposure = [
            'source' => self::SOURCE_UNKNOWN, 'reason' => '', 'administered' => 0, 'assigned' => 0,
            'unmapped' => [], 'direct' => [], 'cumulative' => [], 'checksum' => true,
            'diagnosis' => [], 'differences' => [],
        ];

        // What was administered: Moodle's own record of the question usage. An
        // older trace kept the responses only — the same questions, by key.
        $items = array_values((array) ($trace['items'] ?? []));
        if ($items === []) {
            $items = array_keys((array) ($trace['responses'] ?? []));
        }
        if ($items === []) {
            $items = array_keys((array) ($trace['progress']['responses'] ?? []));
        }
        $engine = self::engine_counts($trace);

        if ($items === []) {
            return self::without_steps($exposure, $engine, $map);
        }
        $exposure['administered'] = count($items);
        if ($map === []) {
            $exposure['reason'] = self::NO_MAPPING;
            $exposure['diagnosis'][] = self::NO_MAPPING;

            return $exposure;
        }

        $direct = [];
        $cumulative = [];
        foreach ($items as $questionid) {
            $chain = $map[(int) $questionid] ?? null;
            if ($chain === null || $chain === []) {
                $exposure['unmapped'][] = (int) $questionid;
                continue;
            }
            $exposure['assigned']++;
            // Exactly one scale of its own; and counted for every scale above it.
            $direct[$chain[0]] = ($direct[$chain[0]] ?? 0) + 1;
            foreach ($chain as $scaleid) {
                $cumulative[$scaleid] = ($cumulative[$scaleid] ?? 0) + 1;
            }
        }
        $exposure['direct'] = $direct;
        $exposure['cumulative'] = $cumulative;
        $exposure['checksum'] = array_sum($direct) === $exposure['assigned'];
        if (!$exposure['checksum']) {
            $exposure['diagnosis'][] = self::CHECKSUM;
        }
        if ($exposure['unmapped'] !== []) {
            // Every count is a lower bound now: the item may belong to any scale.
            $exposure['reason'] = self::UNMAPPED;
            $exposure['diagnosis'][] = self::UNMAPPED;

            return $exposure;
        }
        $exposure['source'] = self::SOURCE_STEPS;

        // The engine's counts beside them: a difference is said, not settled.
        foreach ($engine as $scaleid => $n) {
            $steps = (int) ($cumulative[$scaleid] ?? 0);
            if ($n === $steps) {
                continue;
            }
            $exposure['differences'][$scaleid] = ['steps' => $steps, 'engine' => $n];
            $code = $n < $steps ? self::ENGINE_LOWER : self::ENGINE_HIGHER;
            if (!in_array($code, $exposure['diagnosis'], true)) {
                $exposure['diagnosis'][] = $code;
            }
        }

        return $exposure;
    }

    /**
     * Without steps: the engine's counts where they are consistent, unknown otherwise.
     *
     * @param array $exposure The exposure so far.
     * @param array $engine Scale id => the engine's count.
     * @param array $map Question id => scale chain, to tell the scales nothing is filed under directly.
     * @return array
     */
    protected static function without_steps(array $exposure, array $engine, array $map): array {
        $exposure['reason'] = self::NO_STEPS;
        $exposure['diagnosis'][] = self::NO_STEPS;
        if ($engine === [] || $map === []) {
            return $exposure;
        }
        // The scales items are filed under, and the top of the tree.
        $own = [];
        $root = 0;
        foreach ($map as $chain) {
            $own[$chain[0]] = true;
            $root = (int) end($chain);
        }
        $sum = 0;
        foreach ($engine as $scaleid => $n) {
            $sum += isset($own[$scaleid]) ? $n : 0;
        }
        // Demonstrably consistent: what the engine counts on the scales that
        // hold items adds up to what it counts for the test.
        if ($root <= 0 || !isset($engine[$root]) || $sum !== $engine[$root] || $sum <= 0) {
            $exposure['diagnosis'][] = self::ENGINE_INCONSISTENT;

            return $exposure;
        }
        $exposure['source'] = self::SOURCE_ENGINE;
        $exposure['reason'] = '';
        $exposure['administered'] = $sum;
        $exposure['assigned'] = $sum;
        $exposure['cumulative'] = $engine;
        $exposure['direct'] = array_intersect_key($engine, $own);

        return $exposure;
    }

    /**
     * What the engine counted per scale: its progress where the trace keeps it, its debug context otherwise.
     *
     * @param array $trace The sitting's trace.
     * @return array<int, int> Scale id => items.
     */
    protected static function engine_counts(array $trace): array {
        $counts = [];
        $measure = $trace['enginevalidity'] ?? null;
        if (is_array($measure) && ($measure['source'] ?? '') !== 'trace') {
            foreach ((array) ($measure['scales'] ?? []) as $scaleid => $scale) {
                if (is_array($scale) && isset($scale['n']) && is_numeric($scale['n'])) {
                    $counts[(int) $scaleid] = (int) $scale['n'];
                }
            }
        }
        if ($counts !== []) {
            return $counts;
        }
        foreach ((array) ($trace['questionsperscale'] ?? []) as $scaleid => $n) {
            if (is_numeric($scaleid) && is_numeric($n)) {
                $counts[(int) $scaleid] = (int) $n;
            }
        }

        return $counts;
    }

    /**
     * The items administered on one scale: a number, zero included, or null where it is not known.
     *
     * @param array $exposure From reconstruct().
     * @param int $scaleid The scale.
     * @return int|null Null for unknown; 0 only where it is certain that none was administered.
     */
    public static function items(array $exposure, int $scaleid): ?int {
        if (($exposure['source'] ?? self::SOURCE_UNKNOWN) === self::SOURCE_UNKNOWN || $scaleid <= 0) {
            return null;
        }

        return (int) ($exposure['cumulative'][$scaleid] ?? 0);
    }

    /**
     * The diagnostic class of an item count.
     *
     * The classes say how much was asked on a scale — not whether that is
     * enough to estimate from: they are for looking, not for judging.
     *
     * @param int|null $items The count, null where it is not known.
     * @return string A key of CLASSES, or CLASS_UNKNOWN.
     */
    public static function class_of(?int $items): string {
        if ($items === null) {
            return self::CLASS_UNKNOWN;
        }
        foreach (self::CLASSES as $class) {
            if ($items >= $class['min'] && ($class['max'] === null || $items <= $class['max'])) {
                return $class['key'];
            }
        }

        return self::CLASS_UNKNOWN;
    }

    /**
     * A class as it is shown.
     *
     * @param string $key A key of CLASSES, or CLASS_UNKNOWN.
     * @return string
     */
    public static function class_label(string $key): string {
        return get_string('exposure:class_' . str_replace(['-', '+'], ['to', 'plus'], $key), 'local_catquizlab');
    }

    /**
     * A source, reason or diagnosis as it is shown.
     *
     * @param string $code One of the constants.
     * @return string
     */
    public static function label(string $code): string {
        return get_string('exposure:' . $code, 'local_catquizlab');
    }
}
