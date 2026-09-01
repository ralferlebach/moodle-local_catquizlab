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
     * Base (category sigma, subscale sigma) deviation per stratum, scaled by severity.
     *
     * The strata are cumulative, which the previous table got wrong: it had
     * subscalevariation as [0.0, 0.5], removing the category variation instead
     * of adding to it. In the design, stratum 3 is stratum 2 plus an extra
     * subscale-level variation, so a run could not tell the two apart by their
     * category structure — they were different conditions, not a progression.
     *
     * conforming keeps a small tolerance rather than being exactly flat: the
     * design asks for local deviations below a stated uncertainty, not for
     * their absence. Set persons.variation to override any of this.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    protected const STRATUM_VARIATION = [
        'conforming'        => [0.0, 0.0],
        'categoryvariation' => [0.5, 0.0],
        'subscalevariation' => [0.5, 0.5],
        'chaotic'           => [1.0, 1.0],
    ];

    /** @var string The chaotic stratum draws local abilities largely independently. */
    public const MODE_INDEPENDENT = 'independent';

    /** @var string Every other stratum nests subscale in category in global. */
    public const MODE_HIERARCHICAL = 'hierarchical';

    /** @var array<string, float> Default multipliers for the severity levels. */
    protected const SEVERITY_SCALE = [
        'none'   => 0.0,
        'mild'   => 0.5,
        'medium' => 1.0,
        'strong' => 2.0,
    ];

    /**
     * Generate the ground-truth profiles for a run's persons.
     *
     * @param array $definition The (per-cell) experiment definition.
     * @param int $seed Seed of the base twins; identical seeds yield identical global abilities.
     * @param array $options 'deviationseed' for the local deviations, 'replication' for the twin key.
     * @return array List of person descriptors (stratum, abilityglobal, twinid, label, profile).
     */
    public static function generate(array $definition, int $seed, array $options = []): array {
        $params = self::read_params($definition);
        if ($params['count'] < 1) {
            return [];
        }

        $replication = (int) ($options['replication'] ?? 1);
        $deviationseed = (int) ($options['deviationseed'] ?? $seed);

        // Two draws from two sources. The base twin fixes the global ability
        // and depends only on the replication and the person index, so the same
        // twin turns up in every cell being compared. The deviations depend on
        // stratum and severity as well, because those are the factor. Drawing
        // both from one stream is what made a strategy change resample the
        // people and mix the factor with sampling noise.
        mt_srand($seed);
        $globals = [];
        for ($i = 1; $i <= $params['count']; $i++) {
            $globals[$i] = self::normal($params['abilitymean'], $params['abilitysd']);
        }

        mt_srand($deviationseed);
        $persons = [];
        for ($i = 1; $i <= $params['count']; $i++) {
            $global = $globals[$i];
            $categories = self::build_categories($global, $params);
            $persons[] = [
                'stratum'       => $params['stratum'],
                'severity'      => $params['severity'],
                'twinid'        => self::twin_id($replication, $i),
                'twinindex'     => $i,
                'abilityglobal' => round($global, 5),
                'label'         => naming::expand($params['pattern'], [
                    'stratum' => $params['stratum'],
                    'index'   => $i,
                ]),
                'profile'       => [
                    'global'     => round($global, 5),
                    'mode'       => $params['mode'],
                    'severity'   => $params['severity'],
                    'twinid'     => self::twin_id($replication, $i),
                    'categories' => $categories,
                    'variation'  => ['category' => $params['catsd'], 'subscale' => $params['subsd']],
                ] + (empty($params['deviance']) ? [] : ['deviance' => $params['deviance']]),
            ];
        }
        return $persons;
    }

    /**
     * The stable comparison key of a twin.
     *
     * It deliberately contains neither the strategy, the pool variant nor the
     * budget: those are the factors being compared, and a twin has to survive
     * them to be a twin at all.
     *
     * @param int $replication The replication number.
     * @param int $index The person index within the replication.
     * @return string
     */
    public static function twin_id(int $replication, int $index): string {
        return sprintf('r%03d-t%05d', $replication, $index);
    }

    /**
     * The multiplier a severity level applies to the stratum's base deviation.
     *
     * @param string $severity One of none, mild, medium, strong.
     * @param array $scale Optional overrides from persons.severityscale.
     * @return float
     */
    public static function severity_factor(string $severity, array $scale = []): float {
        if ($severity === 'none') {
            return 0.0;
        }
        if (isset($scale[$severity]) && is_numeric($scale[$severity])) {
            return (float) $scale[$severity];
        }
        return self::SEVERITY_SCALE[$severity] ?? 1.0;
    }

    /**
     * Generate profiles and persist them to the lab store in one call.
     *
     * @param int $runid The run the persons belong to.
     * @param array $definition The (per-cell) experiment definition.
     * @param int $seed Seed of the base twins.
     * @param array $options Options passed through to {@see self::generate()}.
     * @return int The number of persons written.
     */
    public static function generate_and_persist(int $runid, array $definition, int $seed, array $options = []): int {
        return self::persist($runid, self::generate($definition, $seed, $options));
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
                'twinid'        => (string) ($person['twinid'] ?? ''),
                'twinindex'     => (int) ($person['twinindex'] ?? 0),
                'severity'      => (string) ($person['severity'] ?? 'none'),
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
            'count'         => 0,
            'stratum'       => 'conforming',
            'severity'      => 'none',
            'abilitymean'   => 0.0,
            'abilitysd'     => 2.0,
            'naming'        => [],
            'variation'     => [],
            'severityscale' => [],
        ];
        $naming = (array) $persons['naming'] + ['pattern' => 'P-{stratum}-{index:04d}'];
        $scales = (($definition['pool']['scales'] ?? [])) + [
            'categories'    => 10,
            'subcategories' => 10,
        ];
        $variation = (array) $persons['variation'];
        $base = self::STRATUM_VARIATION[$persons['stratum']] ?? [0.0, 0.0];

        $stratum = (string) $persons['stratum'];
        $severity = (string) $persons['severity'];
        $factor = self::severity_factor($severity, (array) $persons['severityscale']);

        // Severity scales the stratum's base deviation. mild/medium/strong are
        // therefore the same condition at three magnitudes, which is what makes
        // them usable as a sweep factor rather than three unrelated settings.
        $catsd = isset($variation['category']) ? (float) $variation['category'] : $base[0] * $factor;
        $subsd = isset($variation['subscale']) ? (float) $variation['subscale'] : $base[1] * $factor;

        return [
            'count'       => (int) $persons['count'],
            'stratum'     => $stratum,
            'severity'    => $severity,
            'mode'        => $stratum === 'chaotic' ? self::MODE_INDEPENDENT : self::MODE_HIERARCHICAL,
            'abilitymean' => (float) $persons['abilitymean'],
            'abilitysd'   => (float) $persons['abilitysd'],
            'pattern'     => (string) $naming['pattern'],
            'categories'  => (int) $scales['categories'],
            'subscales'   => (int) $scales['subcategories'],
            'catsd'       => $catsd,
            'subsd'       => $subsd,
            'tolerance'   => (float) ($persons['tolerance'] ?? 0.0),
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
        $independent = $params['mode'] === self::MODE_INDEPENDENT;

        $categories = [];
        for ($c = 1; $c <= $params['categories']; $c++) {
            $ctheta = $global + ($params['catsd'] > 0 ? self::normal(0.0, $params['catsd']) : 0.0);
            $subscales = [];
            for ($s = 1; $s <= $params['subscales']; $s++) {
                // The chaotic stratum is a stress condition, not just a noisier
                // version of the others: its subscale abilities hang off the
                // global value rather than off their own category, so the
                // hierarchy a CAT engine assumes does not hold. Widening the
                // hierarchical sigma, as before, kept the structure intact and
                // so never actually stressed that assumption.
                $anchor = $independent ? $global : $ctheta;
                $stheta = $anchor + ($params['subsd'] > 0 ? self::normal(0.0, $params['subsd']) : 0.0);
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
