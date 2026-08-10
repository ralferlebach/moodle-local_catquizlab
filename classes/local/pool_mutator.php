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
 * Pool mutator: deterministic variants of the ideal pool blueprint.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Derives a mutated pool blueprint from the ideal one (E2.2).
 *
 * Given the ideal blueprint from {@see pool_planner}, it produces a variant per
 * the experimental design: shifting or stretching true difficulties, opening a
 * difficulty gap, depleting the pool, or annotating calibration/tagging errors.
 * Every mutation is a pure, seed-deterministic transformation of the blueprint
 * data — it never touches the question bank. In line with 2.6.A the variant is
 * a genuinely different item set (different items, difficulties or tags), not a
 * reinterpretation of the same items under another context.
 *
 * True item difficulty stays the ground truth: set-changing variants (gappy,
 * depleted) drop items and difficulty variants (shifted, stretched) rewrite the
 * true value, whereas import-error variants (calibrationerror, taggingerror)
 * only add annotations (a wrong stored parameter or a wrong tag) and leave the
 * true difficulty and true scale intact.
 */
class pool_mutator {
    /**
     * Apply a named variant to an ideal blueprint.
     *
     * @param array $blueprint The ideal blueprint from {@see pool_planner::plan()}.
     * @param string $variant One of the variant names (ideal, shifted, ...).
     * @param array $recipe Variant parameters.
     * @param int $seed Seed for the deterministic draws some variants use.
     * @return array The mutated blueprint (totals recomputed).
     * @throws \invalid_parameter_exception On an unknown variant.
     */
    public static function mutate(array $blueprint, string $variant, array $recipe, int $seed): array {
        $method = 'variant_' . $variant;
        if (!method_exists(self::class, $method)) {
            throw new \invalid_parameter_exception(
                get_string('mutator:unknownvariant', 'local_catquizlab', $variant)
            );
        }

        mt_srand($seed);

        return self::retotal(self::$method($blueprint, $recipe));
    }

    /**
     * Identity variant: the ideal pool unchanged.
     *
     * @param array $blueprint The blueprint.
     * @param array $recipe Unused.
     * @return array
     */
    protected static function variant_ideal(array $blueprint, array $recipe): array {
        unset($recipe);
        return $blueprint;
    }

    /**
     * Shift every true difficulty by a constant.
     *
     * @param array $blueprint The blueprint.
     * @param array $recipe Uses 'shift' (default 0.5).
     * @return array
     */
    protected static function variant_shifted(array $blueprint, array $recipe): array {
        $shift = (float) ($recipe['shift'] ?? 0.5);
        return self::map_items($blueprint, static function (array $item) use ($shift): array {
            $item['difficulty'] = round($item['difficulty'] + $shift, 5);
            return $item;
        });
    }

    /**
     * Stretch true difficulties around the pool mean by a factor.
     *
     * @param array $blueprint The blueprint.
     * @param array $recipe Uses 'factor' (default 1.5).
     * @return array
     */
    protected static function variant_stretched(array $blueprint, array $recipe): array {
        $factor = (float) ($recipe['factor'] ?? 1.5);
        $mean = self::pool_mean($blueprint);
        return self::map_items($blueprint, static function (array $item) use ($factor, $mean): array {
            $item['difficulty'] = round($mean + $factor * ($item['difficulty'] - $mean), 5);
            return $item;
        });
    }

    /**
     * Remove items whose true difficulty falls inside a gap band.
     *
     * @param array $blueprint The blueprint.
     * @param array $recipe Uses 'gapmin' (default -0.5) and 'gapmax' (default 0.5).
     * @return array
     */
    protected static function variant_gappy(array $blueprint, array $recipe): array {
        $gapmin = (float) ($recipe['gapmin'] ?? -0.5);
        $gapmax = (float) ($recipe['gapmax'] ?? 0.5);
        return self::map_items($blueprint, static function (array $item) use ($gapmin, $gapmax): ?array {
            if ($item['difficulty'] >= $gapmin && $item['difficulty'] <= $gapmax) {
                return null;
            }
            return $item;
        });
    }

    /**
     * Deterministically drop a fraction of the items.
     *
     * @param array $blueprint The blueprint.
     * @param array $recipe Uses 'fraction' (default 0.5), the share removed.
     * @return array
     */
    protected static function variant_depleted(array $blueprint, array $recipe): array {
        $fraction = min(1.0, max(0.0, (float) ($recipe['fraction'] ?? 0.5)));
        return self::map_items($blueprint, static function (array $item) use ($fraction): ?array {
            return self::uniform() < $fraction ? null : $item;
        });
    }

    /**
     * Annotate a fraction of items with a miscalibrated stored difficulty.
     *
     * The true difficulty is preserved; a 'calibrated' value (true + noise) is
     * added for the affected items, modelling a wrong stored item parameter.
     *
     * @param array $blueprint The blueprint.
     * @param array $recipe Uses 'fraction' (default 0.1) and 'sd' (default 0.5).
     * @return array
     */
    protected static function variant_calibrationerror(array $blueprint, array $recipe): array {
        $fraction = min(1.0, max(0.0, (float) ($recipe['fraction'] ?? 0.1)));
        $sd = (float) ($recipe['sd'] ?? 0.5);
        return self::map_items($blueprint, static function (array $item) use ($fraction, $sd): array {
            $affected = self::uniform() < $fraction;
            $item['calibrated'] = $affected
                ? round($item['difficulty'] + self::normal(0.0, $sd), 5)
                : $item['difficulty'];
            $item['miscalibrated'] = $affected;
            return $item;
        });
    }

    /**
     * Annotate a fraction of items with a wrong subscale/category tag.
     *
     * The true scale placement is preserved in the tree; 'assignedcategory' and
     * 'assignedsubscale' record the (possibly wrong) tag used at import.
     *
     * @param array $blueprint The blueprint.
     * @param array $recipe Uses 'fraction' (default 0.1).
     * @return array
     */
    protected static function variant_taggingerror(array $blueprint, array $recipe): array {
        $fraction = min(1.0, max(0.0, (float) ($recipe['fraction'] ?? 0.1)));
        $categorycount = count($blueprint['categories']);

        foreach ($blueprint['categories'] as &$category) {
            $subscalecount = count($category['subscales']);
            foreach ($category['subscales'] as &$subscale) {
                foreach ($subscale['items'] as &$item) {
                    $mistagged = self::uniform() < $fraction;
                    $item['truecategory'] = $category['index'];
                    $item['truesubscale'] = $subscale['index'];
                    $item['assignedcategory'] = $category['index'];
                    $item['assignedsubscale'] = $mistagged
                        ? self::other_index($subscale['index'], $subscalecount)
                        : $subscale['index'];
                    $item['mistagged'] = $mistagged;
                }
                unset($item);
            }
            unset($subscale);
            unset($subscalecount);
        }
        unset($category);
        unset($categorycount);

        return $blueprint;
    }

    /**
     * Apply a sequence of variants in order.
     *
     * @param array $blueprint The blueprint.
     * @param array $recipe Uses 'steps': a list of ['variant' => ..., 'recipe' => ...].
     * @return array
     */
    protected static function variant_combined(array $blueprint, array $recipe): array {
        foreach (($recipe['steps'] ?? []) as $step) {
            $variant = $step['variant'] ?? 'ideal';
            $method = 'variant_' . $variant;
            if (method_exists(self::class, $method)) {
                $blueprint = self::$method($blueprint, $step['recipe'] ?? []);
            }
        }
        return $blueprint;
    }

    /**
     * Map a callback over every item, dropping items for which it returns null.
     *
     * @param array $blueprint The blueprint.
     * @param callable $callback fn(array $item): ?array
     * @return array The blueprint with items transformed/filtered.
     */
    protected static function map_items(array $blueprint, callable $callback): array {
        foreach ($blueprint['categories'] as &$category) {
            foreach ($category['subscales'] as &$subscale) {
                $items = [];
                foreach ($subscale['items'] as $item) {
                    $result = $callback($item);
                    if ($result !== null) {
                        $items[] = $result;
                    }
                }
                $subscale['items'] = $items;
            }
            unset($subscale);
        }
        unset($category);
        return $blueprint;
    }

    /**
     * The mean true difficulty across all items.
     *
     * @param array $blueprint The blueprint.
     * @return float
     */
    protected static function pool_mean(array $blueprint): float {
        $sum = 0.0;
        $count = 0;
        foreach ($blueprint['categories'] as $category) {
            foreach ($category['subscales'] as $subscale) {
                foreach ($subscale['items'] as $item) {
                    $sum += $item['difficulty'];
                    $count++;
                }
            }
        }
        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * Recompute the totals block after items may have been added or removed.
     *
     * @param array $blueprint The blueprint.
     * @return array
     */
    protected static function retotal(array $blueprint): array {
        $subscales = 0;
        $items = 0;
        foreach ($blueprint['categories'] as $category) {
            $subscales += count($category['subscales']);
            foreach ($category['subscales'] as $subscale) {
                $items += count($subscale['items']);
            }
        }
        $blueprint['totals'] = [
            'categories' => count($blueprint['categories']),
            'subscales'  => $subscales,
            'items'      => $items,
        ];
        return $blueprint;
    }

    /**
     * Pick a subscale index different from the given one, within [1, count].
     *
     * @param int $current The current index.
     * @param int $count The number of subscales.
     * @return int A different index, or the same one when only one exists.
     */
    protected static function other_index(int $current, int $count): int {
        if ($count < 2) {
            return $current;
        }
        $other = $current;
        while ($other === $current) {
            $other = mt_rand(1, $count);
        }
        return $other;
    }

    /**
     * A uniform draw in (0, 1) from the seeded generator.
     *
     * @return float
     */
    protected static function uniform(): float {
        return (mt_rand() + 1) / (mt_getrandmax() + 2);
    }

    /**
     * A normal draw (Box-Muller) from the seeded generator.
     *
     * @param float $mean The mean.
     * @param float $sd The standard deviation.
     * @return float
     */
    protected static function normal(float $mean, float $sd): float {
        $z = sqrt(-2.0 * log(self::uniform())) * cos(2.0 * M_PI * self::uniform());
        return $mean + $sd * $z;
    }
}
