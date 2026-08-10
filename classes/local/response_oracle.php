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
