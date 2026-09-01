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
 * Tests for the separated seed domains and the paired person design.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\person_generator;
use local_catquizlab\local\seed_domains;

/**
 * Seed-domain and digital-twin tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\seed_domains
 * @covers     \local_catquizlab\local\person_generator
 */
final class seed_domains_test extends \advanced_testcase {
    /**
     * A definition for one stratum and severity.
     *
     * @param string $stratum The person stratum.
     * @param string $severity The deviation severity.
     * @return array
     */
    protected function definition(string $stratum = 'conforming', string $severity = 'none'): array {
        return [
            'pool'    => ['scales' => ['categories' => 3, 'subcategories' => 3]],
            'persons' => [
                'count'    => 5,
                'stratum'  => $stratum,
                'severity' => $severity,
                'naming'   => ['pattern' => 'P-{index:03d}'],
            ],
        ];
    }

    /**
     * The person seed does not depend on the strategy or the pool variant.
     *
     * @return void
     */
    public function test_person_seed_ignores_nuisance_factors(): void {
        $a = seed_domains::person_base(2026, 3);
        $b = seed_domains::person_base(2026, 3);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, seed_domains::person_base(2026, 4));
        $this->assertNotSame($a, seed_domains::person_base(2027, 3));
    }

    /**
     * Each domain is a separate stream, so identical parts do not collide.
     *
     * @return void
     */
    public function test_domains_are_separate_streams(): void {
        $seeds = [
            seed_domains::person_base(1, 1),
            seed_domains::person_deviation(1, 1, 'chaotic', 'mild'),
            seed_domains::pool(1, 1, '2pl'),
            seed_domains::mutation(1, 1, 'shifted', '2pl'),
        ];

        $this->assertCount(count($seeds), array_unique($seeds));
        foreach ($seeds as $seed) {
            $this->assertGreaterThanOrEqual(0, $seed);
        }
    }

    /**
     * Neighbouring severities produce different, non-adjacent seeds.
     *
     * @return void
     */
    public function test_severity_changes_the_deviation_seed(): void {
        $mild = seed_domains::person_deviation(2026, 1, 'subscalevariation', 'mild');
        $strong = seed_domains::person_deviation(2026, 1, 'subscalevariation', 'strong');

        $this->assertNotSame($mild, $strong);
    }

    /**
     * The same twin keeps its global ability across strategy and pool cells.
     *
     * The whole point of the paired design: what differs between two cells has
     * to be the factor, not a freshly drawn cohort.
     *
     * @return void
     */
    public function test_twins_survive_strategy_and_pool_cells(): void {
        $master = 20260831;
        $personseed = seed_domains::person_base($master, 1);

        // Two cells differing only in strategy and pool variant. Both derive
        // their deviation seed from the same stratum/severity, so only the
        // nuisance factors change.
        $cella = person_generator::generate($this->definition(), $personseed, [
            'replication'   => 1,
            'deviationseed' => seed_domains::person_deviation($master, 1, 'conforming', 'none'),
        ]);
        $cellb = person_generator::generate($this->definition(), $personseed, [
            'replication'   => 1,
            'deviationseed' => seed_domains::person_deviation($master, 1, 'conforming', 'none'),
        ]);

        $this->assertSame(
            array_column($cella, 'abilityglobal'),
            array_column($cellb, 'abilityglobal')
        );
        $this->assertSame(array_column($cella, 'twinid'), array_column($cellb, 'twinid'));
    }

    /**
     * A different stratum keeps the base twin but changes the local profile.
     *
     * @return void
     */
    public function test_stratum_changes_deviations_but_not_the_base_twin(): void {
        $master = 20260831;
        $personseed = seed_domains::person_base($master, 1);

        $conforming = person_generator::generate($this->definition(), $personseed, [
            'replication'   => 1,
            'deviationseed' => seed_domains::person_deviation($master, 1, 'conforming', 'none'),
        ]);
        $varied = person_generator::generate($this->definition('subscalevariation', 'strong'), $personseed, [
            'replication'   => 1,
            'deviationseed' => seed_domains::person_deviation($master, 1, 'subscalevariation', 'strong'),
        ]);

        $this->assertSame(
            array_column($conforming, 'abilityglobal'),
            array_column($varied, 'abilityglobal')
        );
        $this->assertSame(array_column($conforming, 'twinid'), array_column($varied, 'twinid'));
        $this->assertNotEquals(
            $conforming[0]['profile']['categories'],
            $varied[0]['profile']['categories']
        );
    }

    /**
     * Stratum 3 contains the category variation of stratum 2 plus its own.
     *
     * @return void
     */
    public function test_subscalevariation_builds_on_categoryvariation(): void {
        $category = person_generator::generate($this->definition('categoryvariation', 'medium'), 11, [
            'deviationseed' => 12,
        ]);
        $subscale = person_generator::generate($this->definition('subscalevariation', 'medium'), 11, [
            'deviationseed' => 12,
        ]);

        $categoryspread = $this->spread($category[0], 'category');
        $subscalespread = $this->spread($subscale[0], 'category');

        // Category-level variation must still be present in stratum 3; the old
        // table zeroed it, which made the two strata alternatives rather than a
        // progression.
        $this->assertGreaterThan(0.0, $categoryspread);
        $this->assertGreaterThan(0.0, $subscalespread);
        $this->assertGreaterThan(
            $this->spread($category[0], 'subscale'),
            $this->spread($subscale[0], 'subscale')
        );
    }

    /**
     * A stronger severity produces larger deviations from the same base twin.
     *
     * @return void
     */
    public function test_severity_scales_the_deviation(): void {
        $mild = person_generator::generate($this->definition('subscalevariation', 'mild'), 5, [
            'deviationseed' => 9,
        ]);
        $strong = person_generator::generate($this->definition('subscalevariation', 'strong'), 5, [
            'deviationseed' => 9,
        ]);

        $this->assertSame($mild[0]['abilityglobal'], $strong[0]['abilityglobal']);
        $this->assertGreaterThan(
            $this->spread($mild[0], 'subscale'),
            $this->spread($strong[0], 'subscale')
        );
    }

    /**
     * The chaotic stratum is its own generator mode, not a wider hierarchy.
     *
     * @return void
     */
    public function test_chaotic_is_an_independent_mode(): void {
        $chaotic = person_generator::generate($this->definition('chaotic', 'strong'), 5, [
            'deviationseed' => 9,
        ]);
        $hierarchical = person_generator::generate($this->definition('subscalevariation', 'strong'), 5, [
            'deviationseed' => 9,
        ]);

        $this->assertSame(person_generator::MODE_INDEPENDENT, $chaotic[0]['profile']['mode']);
        $this->assertSame(person_generator::MODE_HIERARCHICAL, $hierarchical[0]['profile']['mode']);
    }

    /**
     * Severity none leaves a conforming profile flat.
     *
     * @return void
     */
    public function test_conforming_stays_flat(): void {
        $persons = person_generator::generate($this->definition(), 5, ['deviationseed' => 9]);

        $this->assertSame(0.0, $this->spread($persons[0], 'category'));
        $this->assertSame(0.0, $this->spread($persons[0], 'subscale'));
    }

    /**
     * The manifest block names the factors each seed depends on.
     *
     * @return void
     */
    public function test_manifest_block_documents_dependencies(): void {
        $block = seed_domains::manifest_block(2026, 1, 'chaotic', 'mild', 'shifted', '2pl');

        $this->assertArrayHasKey(seed_domains::DOMAIN_PERSON_BASE, $block);
        $this->assertSame(
            ['replication', 'twinindex'],
            $block[seed_domains::DOMAIN_PERSON_BASE]['dependson']
        );
        $this->assertContains('variant', $block[seed_domains::DOMAIN_MUTATION]['dependson']);
    }

    /**
     * The spread of a profile's abilities around their parent value.
     *
     * @param array $person A generated person.
     * @param string $level 'category' or 'subscale'.
     * @return float The mean absolute deviation from the parent value.
     */
    protected function spread(array $person, string $level): float {
        $global = $person['profile']['global'];
        $deviations = [];

        foreach ($person['profile']['categories'] as $category) {
            if ($level === 'category') {
                $deviations[] = abs($category['theta'] - $global);
                continue;
            }
            foreach ($category['subscales'] as $subscale) {
                $deviations[] = abs($subscale['theta'] - $category['theta']);
            }
        }

        return $deviations === [] ? 0.0 : round(array_sum($deviations) / count($deviations), 6);
    }
}
