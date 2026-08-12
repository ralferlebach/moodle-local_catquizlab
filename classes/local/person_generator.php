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
 * Person ground-truth generator.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Generates the ground-truth ability profiles of a run's simulated persons (E2.3, first part).
 *
 * For each person it draws a global ability and, depending on the stratum,
 * category- and subscale-level deviations, producing the hierarchical θ profile
 * the response oracle will later answer against. Names come from the naming
 * engine (2.6.D). Generation is seed-deterministic and side-effect-free;
 * {@see self::persist()} writes the profiles to the lab store. Turning each
 * profile into an actual Moodle user and enrolling it (2.6.B/C) happens later in
 * provisioning, once courses and the CAT test exist — this class only fixes the
 * ground truth.
 *
 * The distribution parameters are read from the definition (with documented
 * first-cut defaults), so the statistical design stays in the experiment
 * definition rather than being hard-coded here.
 */
class person_generator {
    /**
     * Default (category σ, subscale σ) deviation per stratum, overridable via persons.variation.
     *
     * @var array
     */
    protected const STRATUM_VARIATION = [
        'conforming'        => [0.0, 0.0],
        'categoryvariation' => [0.5, 0.0],
        'subscalevariation' => [0.0, 0.5],
        'chaotic'           => [0.7, 0.7],
    ];

    /**
     * Generate the ground-truth profiles for a run's persons.
     *
     * @param array $definition The (per-cell) experiment definition.
     * @param int $seed Master seed for this run; identical seeds yield identical profiles.
     * @return array List of person descriptors (stratum, abilityglobal, label, profile).
     */
    public static function generate(array $definition, int $seed): array {
        $params = self::read_params($definition);
        if ($params['count'] < 1) {
            return [];
        }

        mt_srand($seed);

        $persons = [];
        for ($i = 1; $i <= $params['count']; $i++) {
            $global = self::normal($params['abilitymean'], $params['abilitysd']);
            $categories = self::build_categories($global, $params);
            $persons[] = [
                'stratum'       => $params['stratum'],
                'abilityglobal' => round($global, 5),
                'label'         => naming::expand($params['pattern'], [
                    'stratum' => $params['stratum'],
                    'index'   => $i,
                ]),
                'profile'       => [
                    'global'     => round($global, 5),
                    'categories' => $categories,
                ] + (empty($params['deviance']) ? [] : ['deviance' => $params['deviance']]),
            ];
        }
        return $persons;
    }

    /**
     * Generate profiles and persist them to the lab store in one call.
     *
     * @param int $runid The run the persons belong to.
     * @param array $definition The (per-cell) experiment definition.
     * @param int $seed Master seed for this run.
     * @return int The number of persons written.
     */
    public static function generate_and_persist(int $runid, array $definition, int $seed): int {
        return self::persist($runid, self::generate($definition, $seed));
    }

    /**
     * Persist generated person profiles as ground-truth rows.
     *
     * @param int $runid The run the persons belong to.
     * @param array $persons Person descriptors from {@see self::generate()}.
     * @return int The number of persons written.
     */
    public static function persist(int $runid, array $persons): int {
        global $DB;

        $now = time();
        $count = 0;
        $transaction = $DB->start_delegated_transaction();
        foreach ($persons as $person) {
            $DB->insert_record('local_catquizlab_person', (object) [
                'runid'         => $runid,
                'stratum'       => $person['stratum'],
                'abilityglobal' => $person['abilityglobal'],
                'profilejson'   => json_encode(
                    ['label' => $person['label']] + $person['profile'],
                    JSON_UNESCAPED_SLASHES
                ),
                'moodleuserid'  => null,
                'timecreated'   => $now,
                'timemodified'  => $now,
            ]);
            $count++;
        }
        $transaction->allow_commit();

        return $count;
    }

    /**
     * Read the generation parameters from the definition, applying defaults.
     *
     * @param array $definition The experiment definition.
     * @return array Normalised generation parameters.
     */
    protected static function read_params(array $definition): array {
        $persons = ($definition['persons'] ?? []) + [
            'count'       => 0,
            'stratum'     => 'conforming',
            'abilitymean' => 0.0,
            'abilitysd'   => 2.0,
            'naming'      => [],
            'variation'   => [],
        ];
        $naming = (array) $persons['naming'] + ['pattern' => 'P-{stratum}-{index:04d}'];
        $scales = (($definition['pool']['scales'] ?? [])) + [
            'categories'    => 10,
            'subcategories' => 10,
        ];
        $variation = (array) $persons['variation'];
        $base = self::STRATUM_VARIATION[$persons['stratum']] ?? [0.0, 0.0];

        return [
            'count'       => (int) $persons['count'],
            'stratum'     => (string) $persons['stratum'],
            'abilitymean' => (float) $persons['abilitymean'],
            'abilitysd'   => (float) $persons['abilitysd'],
            'pattern'     => (string) $naming['pattern'],
            'categories'  => (int) $scales['categories'],
            'subscales'   => (int) $scales['subcategories'],
            'catsd'       => (float) ($variation['category'] ?? $base[0]),
            'subsd'       => (float) ($variation['subscale'] ?? $base[1]),
            'deviance'    => isset($persons['deviance']) && is_array($persons['deviance']) ? $persons['deviance'] : [],
        ];
    }

    /**
     * Build the category/subscale θ tree for one person.
     *
     * @param float $global The person's global ability.
     * @param array $params Normalised generation parameters.
     * @return array The categories, each with its θ and subscale θ values.
     */
    protected static function build_categories(float $global, array $params): array {
        $categories = [];
        for ($c = 1; $c <= $params['categories']; $c++) {
            $ctheta = $global + ($params['catsd'] > 0 ? self::normal(0.0, $params['catsd']) : 0.0);
            $subscales = [];
            for ($s = 1; $s <= $params['subscales']; $s++) {
                $stheta = $ctheta + ($params['subsd'] > 0 ? self::normal(0.0, $params['subsd']) : 0.0);
                $subscales[] = ['index' => $s, 'theta' => round($stheta, 5)];
            }
            $categories[] = ['index' => $c, 'theta' => round($ctheta, 5), 'subscales' => $subscales];
        }
        return $categories;
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
