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
    /** @var float The study's shift, in logits. */
    public const DEFAULT_SHIFT = 1.0;

    /** @var float The study's stretch factor. */
    public const DEFAULT_STRETCH = 1.25;

    /** @var string Gappy keeps the item count and redistributes difficulties. */
    public const GAP_MODE_FIXEDN = 'fixedn';

    /** @var string Gappy removes the items inside the band (schema-1 behaviour). */
    public const GAP_MODE_REMOVE = 'remove';

    /**
     * The recipe keys each variant accepts, with their defaults.
     *
     * Variants whose parameters carry scientific meaning are listed here so a
     * publication run can be required to state them rather than inherit a
     * generic code default.
     *
     * @var array<string, array<string, mixed>>
     */
    protected const RECIPE_DEFAULTS = [
        'ideal'            => [],
        'shifted'          => ['shift' => self::DEFAULT_SHIFT],
        'stretched'        => ['factor' => self::DEFAULT_STRETCH],
        'gappy'            => ['gapmin' => -0.5, 'gapmax' => 0.5, 'mode' => self::GAP_MODE_FIXEDN],
        'depleted'         => ['fraction' => 0.5],
        'calibrationerror' => ['fraction' => 0.1, 'sd' => 0.5],
        'taggingerror'     => ['fraction' => 0.1],
        'combined'         => ['steps' => []],
    ];

    /** @var string[] Variants whose recipe a publication run must state explicitly. */
    protected const PUBLICATION_REQUIRED = [
        'shifted'          => ['shift'],
        'stretched'        => ['factor'],
        'gappy'            => ['gapmin', 'gapmax'],
        'depleted'         => ['fraction'],
        'calibrationerror' => ['fraction', 'sd'],
        'taggingerror'     => ['fraction'],
    ];

    /**
     * Validate a variant recipe.
     *
     * @param string $variant The pool variant.
     * @param array $recipe The recipe as written in the definition.
     * @param bool $publication Whether this is a publication run.
     * @return string[] Human-readable errors; empty when the recipe is valid.
     */
    public static function validate_recipe(string $variant, array $recipe, bool $publication = false): array {
        $errors = [];
        if (!isset(self::RECIPE_DEFAULTS[$variant])) {
            return [get_string('mutator:unknownvariant', 'local_catquizlab', $variant)];
        }

        $label = 'pool.recipe';
        $unknown = array_diff(array_keys($recipe), array_keys(self::RECIPE_DEFAULTS[$variant]));
        if ($variant !== 'combined' && $unknown !== []) {
            $errors[] = get_string('def:unknownrecipekey', 'local_catquizlab', (object) [
                'variant' => $variant,
                'keys'    => implode(', ', $unknown),
            ]);
        }

        foreach (['fraction'] as $key) {
            if (isset($recipe[$key])) {
                if (!is_numeric($recipe[$key])) {
                    $errors[] = get_string('def:numeric', 'local_catquizlab', $label . '.' . $key);
                } else if ((float) $recipe[$key] < 0.0 || (float) $recipe[$key] > 1.0) {
                    $errors[] = get_string('def:fraction', 'local_catquizlab', $label . '.' . $key);
                }
            }
        }
        foreach (['shift', 'factor', 'sd', 'gapmin', 'gapmax'] as $key) {
            if (isset($recipe[$key]) && !is_numeric($recipe[$key])) {
                $errors[] = get_string('def:numeric', 'local_catquizlab', $label . '.' . $key);
            }
        }
        if (isset($recipe['factor']) && is_numeric($recipe['factor']) && (float) $recipe['factor'] <= 0.0) {
            $errors[] = get_string('def:positivefloat', 'local_catquizlab', $label . '.factor');
        }
        if (isset($recipe['sd']) && is_numeric($recipe['sd']) && (float) $recipe['sd'] < 0.0) {
            $errors[] = get_string('def:negative', 'local_catquizlab', $label . '.sd');
        }
        if (
            isset($recipe['gapmin'], $recipe['gapmax'])
                && is_numeric($recipe['gapmin']) && is_numeric($recipe['gapmax'])
                && (float) $recipe['gapmin'] > (float) $recipe['gapmax']
        ) {
            $errors[] = get_string('def:mingtmax', 'local_catquizlab', $label . '.gap');
        }
        if (isset($recipe['mode']) && !in_array($recipe['mode'], [self::GAP_MODE_FIXEDN, self::GAP_MODE_REMOVE], true)) {
            $errors[] = get_string('def:enum', 'local_catquizlab', $label . '.mode: fixedn|remove');
        }

        if ($variant === 'combined') {
            $steps = $recipe['steps'] ?? null;
            if (!is_array($steps) || $steps === []) {
                $errors[] = get_string('def:nonemptylist', 'local_catquizlab', $label . '.steps');
            } else {
                foreach ($steps as $i => $step) {
                    $stepvariant = $step['variant'] ?? null;
                    if (
                        !is_string($stepvariant) || $stepvariant === 'combined'
                            || !isset(self::RECIPE_DEFAULTS[$stepvariant])
                    ) {
                        $errors[] = get_string('mutator:unknownvariant', 'local_catquizlab', $label
                            . '.steps[' . $i . ']');
                        continue;
                    }
                    $errors = array_merge(
                        $errors,
                        self::validate_recipe($stepvariant, (array) ($step['recipe'] ?? []), $publication)
                    );
                }
            }
        } else if ($publication) {
            foreach (self::PUBLICATION_REQUIRED[$variant] ?? [] as $key) {
                if (!isset($recipe[$key])) {
                    $errors[] = get_string('def:recipeexplicit', 'local_catquizlab', (object) [
                        'variant' => $variant,
                        'key'     => $key,
                    ]);
                }
            }
        }

        return $errors;
    }

    /**
     * Fill a recipe with the documented defaults of its variant.
     *
     * @param string $variant The pool variant.
     * @param array $recipe The recipe from the definition.
     * @return array
     */
    public static function apply_recipe_defaults(string $variant, array $recipe): array {
        return $recipe + (self::RECIPE_DEFAULTS[$variant] ?? []);
    }

    /**
     * The documented default recipe of a variant.
     *
     * @param string $variant The pool variant.
     * @return array
     */
    public static function recipe_defaults(string $variant): array {
        return self::RECIPE_DEFAULTS[$variant] ?? [];
    }

    /**
     * All known variant names.
     *
     * @return string[]
     */
    public static function variants(): array {
        return array_keys(self::RECIPE_DEFAULTS);
    }

    /**
     * Give every item both views: the ground truth and what the engine is told.
     *
     * After a mutation some items carry a wrong stored difficulty or a wrong
     * tag and most do not. Filling the unaffected ones in means the rest of the
     * pipeline can read one pair of fields for every item, instead of guessing
     * which annotation a particular variant happens to have written.
     *
     * @param array $blueprint The mutated blueprint.
     * @return array
     */
    protected static function finalise_views(array $blueprint): array {
        foreach ($blueprint['categories'] as &$category) {
            foreach ($category['subscales'] as &$subscale) {
                foreach ($subscale['items'] as &$item) {
                    $item += [
                        'truecategory'     => $category['index'],
                        'truesubscale'     => $subscale['index'],
                        'storeddifficulty' => $item['difficulty'],
                        'miscalibrated'    => false,
                        'mistagged'        => false,
                    ];
                    $item += [
                        'assignedcategory' => $item['truecategory'],
                        'assignedsubscale' => $item['truesubscale'],
                    ];
                }
                unset($item);
            }
            unset($subscale);
        }
        unset($category);

        return $blueprint;
    }

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

        $recipe = self::apply_recipe_defaults($variant, $recipe);

        return self::finalise_views(self::retotal(self::$method($blueprint, $recipe)));
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
     * @param array $recipe Uses 'shift' (default +1.0 logit, the study value).
     * @return array
     */
    protected static function variant_shifted(array $blueprint, array $recipe): array {
        $shift = (float) ($recipe['shift'] ?? self::DEFAULT_SHIFT);
        return self::map_items($blueprint, static function (array $item) use ($shift): array {
            $item['difficulty'] = round($item['difficulty'] + $shift, 5);
            return $item;
        });
    }

    /**
     * Stretch true difficulties around the pool mean by a factor.
     *
     * @param array $blueprint The blueprint.
     * @param array $recipe Uses 'factor' (default x1.25, the study value).
     * @return array
     */
    protected static function variant_stretched(array $blueprint, array $recipe): array {
        $factor = (float) ($recipe['factor'] ?? self::DEFAULT_STRETCH);
        $mean = self::pool_mean($blueprint);
        return self::map_items($blueprint, static function (array $item) use ($factor, $mean): array {
            $item['difficulty'] = round($mean + $factor * ($item['difficulty'] - $mean), 5);
            return $item;
        });
    }

    /**
     * Open a difficulty gap while keeping the item count constant.
     *
     * The design treats gappy and depleted as two different disturbances: a
     * gappy pool is badly distributed, a depleted pool is small. Removing items
     * to make a gap would confound the two, so the items inside the band are
     * pushed out to its nearer edge instead. N stays constant and the pool now
     * has a hole with a pile-up on each side, which is what a real pool with a
     * missing difficulty range looks like.
     *
     * Setting 'mode' to 'remove' restores the older, N-reducing behaviour for
     * anyone who needs it; the design does not use it.
     *
     * @param array $blueprint The blueprint.
     * @param array $recipe Uses 'gapmin', 'gapmax' and 'mode' (fixedn|remove).
     * @return array
     */
    protected static function variant_gappy(array $blueprint, array $recipe): array {
        $gapmin = (float) ($recipe['gapmin'] ?? -0.5);
        $gapmax = (float) ($recipe['gapmax'] ?? 0.5);
        $mode = (string) ($recipe['mode'] ?? self::GAP_MODE_FIXEDN);
        $middle = ($gapmin + $gapmax) / 2.0;

        return self::map_items(
            $blueprint,
            static function (array $item) use ($gapmin, $gapmax, $middle, $mode): ?array {
                if ($item['difficulty'] < $gapmin || $item['difficulty'] > $gapmax) {
                    return $item;
                }
                if ($mode === self::GAP_MODE_REMOVE) {
                    return null;
                }
                $item['difficulty'] = round($item['difficulty'] <= $middle ? $gapmin : $gapmax, 5);
                $item['redistributed'] = true;
                return $item;
            }
        );
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
            $stored = $affected
                ? round($item['difficulty'] + self::normal(0.0, $sd), 5)
                : $item['difficulty'];
            // The storeddifficulty is what the engine is told; difficulty stays the
            // ground truth the oracle answers against. 'calibrated' is the
            // schema-1 name for the same value and is kept for compatibility.
            $item['storeddifficulty'] = $stored;
            $item['calibrated'] = $stored;
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
