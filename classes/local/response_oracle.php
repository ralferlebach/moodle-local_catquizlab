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
 * Response oracle: the IRT answer model of a simulated person.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Computes how a simulated person answers an item (E3.4).
 *
 * Given a person's ground-truth ability and an item's ground-truth parameters it
 * returns the probability of a correct response under the logistic IRT model and
 * draws a seed-deterministic answer from it. The three-parameter form covers the
 * one- and two-parameter models as special cases:
 *
 *     P(correct) = c + (1 - c) / (1 + exp(-a * (theta - b)))
 *
 * with a = discrimination (default 1), b = difficulty, c = guessing (default 0),
 * so the defaults give the Rasch/1PL model. It also resolves the relevant ability
 * from a person's hierarchical profile (global, per category, per subscale), which
 * is what lets the DPF conditions probe local ability deviations. It is pure and
 * side-effect-free; the response-oracle web service calls it once the item's
 * ground-truth difficulty is resolvable (after pool materialisation).
 */
class response_oracle {
    /**
     * Probability of a correct response under the logistic IRT model.
     *
     * @param float $theta The person's ability.
     * @param float $difficulty The item difficulty (b).
     * @param float $discrimination The item discrimination (a), default 1.0.
     * @param float $guessing The lower asymptote / guessing (c) in [0,1), default 0.0.
     * @return float The probability in [0,1].
     */
    public static function probability(
        float $theta,
        float $difficulty,
        float $discrimination = 1.0,
        float $guessing = 0.0
    ): float {
        $logistic = 1.0 / (1.0 + exp(-$discrimination * ($theta - $difficulty)));
        $p = $guessing + (1.0 - $guessing) * $logistic;

        return max(0.0, min(1.0, $p));
    }

    /**
     * Draw a seed-deterministic correct/incorrect response.
     *
     * The seed should encode the attempt and item (for example run seed, person
     * and question) so the same presentation always yields the same answer,
     * independent of call order.
     *
     * @param float $theta The person's ability.
     * @param float $difficulty The item difficulty (b).
     * @param int $seed Deterministic seed for this person/item presentation.
     * @param float $discrimination The item discrimination (a), default 1.0.
     * @param float $guessing The guessing parameter (c), default 0.0.
     * @return bool True for a correct response.
     */
    public static function respond(
        float $theta,
        float $difficulty,
        int $seed,
        float $discrimination = 1.0,
        float $guessing = 0.0
    ): bool {
        $p = self::probability($theta, $difficulty, $discrimination, $guessing);

        mt_srand($seed);
        $u = mt_rand() / (mt_getrandmax() + 1.0);

        return $u < $p;
    }

    /**
     * Category probabilities under the Generalized Partial Credit Model (GPCM).
     *
     * For m step parameters there are m+1 ordered categories (0..m). The
     * unnormalised log-score of category k is the cumulative sum of
     * a*(theta - step_j) up to k (category 0 scores zero); a softmax normalises.
     *
     * @param float $theta The person's ability.
     * @param float $discrimination The item discrimination (a).
     * @param float[] $steps The step parameters (b_1..b_m), in order.
     * @return float[] Probabilities for categories 0..m, summing to 1.
     */
    public static function gpcm_probabilities(float $theta, float $discrimination, array $steps): array {
        $scores = [0.0];
        $cumulative = 0.0;
        foreach (array_values($steps) as $step) {
            $cumulative += $discrimination * ($theta - $step);
            $scores[] = $cumulative;
        }

        $max = max($scores);
        $exponentials = array_map(static fn($score) => exp($score - $max), $scores);
        $total = array_sum($exponentials);

        return array_map(static fn($value) => $value / $total, $exponentials);
    }

    /**
     * Category probabilities under the Graded Response Model (GRM).
     *
     * Each threshold gives a cumulative probability P(X >= k) = logistic(a(theta - b_k));
     * the category probabilities are the successive differences, with P(X >= 0) = 1
     * and P(X >= m+1) = 0.
     *
     * @param float $theta The person's ability.
     * @param float $discrimination The item discrimination (a).
     * @param float[] $thresholds The category thresholds (b_1..b_m), in ascending order.
     * @return float[] Probabilities for categories 0..m, summing to 1.
     */
    public static function grm_probabilities(float $theta, float $discrimination, array $thresholds): array {
        $cumulative = [1.0];
        foreach (array_values($thresholds) as $threshold) {
            $cumulative[] = 1.0 / (1.0 + exp(-$discrimination * ($theta - $threshold)));
        }
        $cumulative[] = 0.0;

        $probabilities = [];
        $count = count($cumulative) - 1;
        for ($k = 0; $k < $count; $k++) {
            $probabilities[] = max(0.0, $cumulative[$k] - $cumulative[$k + 1]);
        }

        return $probabilities;
    }

    /**
     * Draw a seed-deterministic category from a polytomous model.
     *
     * @param float $theta The person's ability.
     * @param string $model The model key ('grm' for graded response, otherwise GPCM).
     * @param float $discrimination The item discrimination (a).
     * @param float[] $params The step (GPCM) or threshold (GRM) parameters.
     * @param int $seed Deterministic seed for this person/item presentation.
     * @return int The chosen category index (0-based).
     */
    public static function respond_polytomous(
        float $theta,
        string $model,
        float $discrimination,
        array $params,
        int $seed
    ): int {
        $probabilities = $model === 'grm'
            ? self::grm_probabilities($theta, $discrimination, $params)
            : self::gpcm_probabilities($theta, $discrimination, $params);

        mt_srand($seed);
        $draw = mt_rand() / (mt_getrandmax() + 1.0);

        $accumulated = 0.0;
        foreach ($probabilities as $category => $probability) {
            $accumulated += $probability;
            if ($draw < $accumulated) {
                return $category;
            }
        }

        return count($probabilities) - 1;
    }

    /**
     * Answer a presented item, dispatching by item type.
     *
     * A polytomous item (flagged and carrying step/threshold parameters) draws a
     * category via {@see self::respond_polytomous()} and reports it as the chosen
     * category with a proportional score fraction; a dichotomous item is scored
     * right/wrong via {@see self::respond()}. Pure and seed-deterministic.
     *
     * @param float $ability The person's ability on the item's scale.
     * @param array $item The item parameters (model, difficulty, discrimination,
     *                    guessing, and for polytomous items polytomous + steps).
     * @param int $seed Deterministic seed for this presentation.
     * @return array{fraction: float, choice: int} Score fraction in [0,1] and the
     *         chosen polytomous category (or -1 for dichotomous items).
     */
    public static function respond_item(float $ability, array $item, int $seed): array {
        $steps = array_values(array_map('floatval', $item['steps'] ?? []));
        $discrimination = (float) ($item['discrimination'] ?? 1.0);

        if (!empty($item['polytomous']) && count($steps) >= 1) {
            $model = stripos((string) ($item['model'] ?? ''), 'grm') !== false ? 'grm' : 'gpcm';
            $category = self::respond_polytomous($ability, $model, $discrimination, $steps, $seed);
            $maxcategory = count($steps);
            $fraction = $maxcategory > 0 ? $category / $maxcategory : 0.0;
            return ['fraction' => round($fraction, 6), 'choice' => $category];
        }

        $correct = self::respond(
            $ability,
            (float) ($item['difficulty'] ?? 0.0),
            $seed,
            $discrimination,
            (float) ($item['guessing'] ?? 0.0)
        );
        return ['fraction' => $correct ? 1.0 : 0.0, 'choice' => -1];
    }

    /**
     * Resolve the relevant ability from a person's hierarchical profile.
     *
     * Returns the subscale ability when a category and subscale are given, the
     * category ability when only a category is given, and the global ability
     * otherwise; missing levels fall back to the next higher level.
     *
     * @param array $profile The ground-truth profile (global + categories tree).
     * @param int|null $category The 1-based category index, or null for global.
     * @param int|null $subscale The 1-based subscale index, or null for category level.
     * @return float The resolved ability.
     */
    public static function ability_for(array $profile, ?int $category = null, ?int $subscale = null): float {
        $global = (float) ($profile['global'] ?? 0.0);
        if ($category === null) {
            return $global;
        }

        foreach (($profile['categories'] ?? []) as $cat) {
            if ((int) ($cat['index'] ?? 0) !== $category) {
                continue;
            }
            $categorytheta = (float) ($cat['theta'] ?? $global);
            return $subscale === null
                ? $categorytheta
                : self::subscale_theta($cat, $subscale, $categorytheta);
        }

        return $global;
    }

    /**
     * Resolve a subscale ability within a category, falling back when absent.
     *
     * @param array $category The category node.
     * @param int $subscale The 1-based subscale index.
     * @param float $fallback The value to use when the subscale is not present.
     * @return float
     */
    protected static function subscale_theta(array $category, int $subscale, float $fallback): float {
        foreach (($category['subscales'] ?? []) as $sub) {
            if ((int) ($sub['index'] ?? 0) === $subscale) {
                return (float) ($sub['theta'] ?? $fallback);
            }
        }
        return $fallback;
    }
}
