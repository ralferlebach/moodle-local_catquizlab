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
 * Pool planner: the item blueprint of the ideal pool.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Plans the ideal item pool as a blueprint (E2.1, first part).
 *
 * It lays out the scale tree (categories × subcategories × items) and draws item
 * difficulties from the nested distributions of the experimental design: a
 * category mean ~ N(0, 2), a subscale mean ~ N(category mean, 0.75) and each
 * item difficulty ~ N(subscale mean, 0.5). Item names come from the naming
 * engine (2.6.D). Generation is seed-deterministic and side-effect-free.
 *
 * This is the item counterpart to {@see person_generator}: it fixes the item
 * ground truth as pure data. Materialising the blueprint into real questions via
 * the CAT engine's importer, and deriving the mutated pool variants (2.6.A,
 * E2.2), are engine-dependent follow-ups; this class does not touch the question
 * bank. Distribution parameters are read from the definition with defaults taken
 * from the design, so the design stays in the definition rather than hard-coded.
 */
class pool_planner {
    /**
     * Plan the ideal pool blueprint for a definition.
     *
     * @param array $definition The experiment definition.
     * @param int $seed Master seed; identical seeds yield identical blueprints.
     * @return array The blueprint: a category tree plus totals.
     */
    public static function plan(array $definition, int $seed): array {
        $params = self::read_params($definition);

        mt_srand($seed);

        $categories = [];
        for ($c = 1; $c <= $params['categories']; $c++) {
            $categorymean = self::normal($params['mean'], $params['categorysd']);
            $categories[] = [
                'index'     => $c,
                'mean'      => round($categorymean, 5),
                'subscales' => self::build_subscales($c, $categorymean, $params),
            ];
        }

        return [
            'categories' => $categories,
            'totals'     => [
                'categories' => $params['categories'],
                'subscales'  => $params['categories'] * $params['subscales'],
                'items'      => $params['categories'] * $params['subscales'] * $params['itemspersubscale'],
            ],
        ];
    }

    /**
     * Read the planning parameters from the definition, applying design defaults.
     *
     * @param array $definition The experiment definition.
     * @return array Normalised planning parameters.
     */
    protected static function read_params(array $definition): array {
        $scales = (($definition['pool']['scales'] ?? [])) + [
            'categories'       => 10,
            'subcategories'    => 10,
            'itemspersubscale' => 25,
        ];
        $naming = (array) ($definition['pool']['itemnaming'] ?? []) + [
            'pattern' => 'Q-{category}-{subscale}-{index:03d}',
        ];
        $difficulty = (array) ($definition['pool']['difficulty'] ?? []) + [
            'mean'       => 0.0,
            'categorysd' => 2.0,
            'subscalesd' => 0.75,
            'itemsd'     => 0.5,
        ];

        return [
            'categories'      => (int) $scales['categories'],
            'subscales'       => (int) $scales['subcategories'],
            'itemspersubscale' => (int) $scales['itemspersubscale'],
            'pattern'         => (string) $naming['pattern'],
            'mean'            => (float) $difficulty['mean'],
            'categorysd'      => (float) $difficulty['categorysd'],
            'subscalesd'      => (float) $difficulty['subscalesd'],
            'itemsd'          => (float) $difficulty['itemsd'],
        ];
    }

    /**
     * Build the subscales of one category.
     *
     * @param int $category The category index.
     * @param float $categorymean The category's difficulty mean.
     * @param array $params Normalised planning parameters.
     * @return array The subscales, each with its mean and its items.
     */
    protected static function build_subscales(int $category, float $categorymean, array $params): array {
        $subscales = [];
        for ($s = 1; $s <= $params['subscales']; $s++) {
            $subscalemean = self::normal($categorymean, $params['subscalesd']);
            $subscales[] = [
                'index' => $s,
                'mean'  => round($subscalemean, 5),
                'items' => self::build_items($category, $s, $subscalemean, $params),
            ];
        }
        return $subscales;
    }

    /**
     * Build the items of one subscale.
     *
     * @param int $category The category index.
     * @param int $subscale The subscale index.
     * @param float $subscalemean The subscale's difficulty mean.
     * @param array $params Normalised planning parameters.
     * @return array The items, each with a name and a difficulty.
     */
    protected static function build_items(int $category, int $subscale, float $subscalemean, array $params): array {
        $items = [];
        for ($i = 1; $i <= $params['itemspersubscale']; $i++) {
            $items[] = [
                'index'      => $i,
                'name'       => naming::expand($params['pattern'], [
                    'category' => $category,
                    'subscale' => $subscale,
                    'index'    => $i,
                ]),
                'difficulty' => round(self::normal($subscalemean, $params['itemsd']), 5),
            ];
        }
        return $items;
    }

    /**
     * Draw a normally distributed value (Box-Muller) from the seeded generator.
     *
     * @param float $mean The mean.
     * @param float $sd The standard deviation.
     * @return float
     */
    protected static function normal(float $mean, float $sd): float {
        $u1 = (mt_rand() + 1) / (mt_getrandmax() + 2);
        $u2 = (mt_rand() + 1) / (mt_getrandmax() + 2);
        $z = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
        return $mean + $sd * $z;
    }
}
