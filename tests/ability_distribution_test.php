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

namespace local_catquizlab;

use local_catquizlab\form\experiment_form;
use local_catquizlab\local\ability_distribution;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\test_provisioner;

/**
 * The distribution of simulated abilities and the scale range (#102, #105).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\ability_distribution
 */
final class ability_distribution_test extends \advanced_testcase {
    /**
     * A definition with a given persons block on top of the baseline.
     *
     * @param array $persons Settings to add.
     * @param int $count How many people.
     * @return array
     */
    protected function definition(array $persons, int $count = 2000): array {
        $definition = experiment_definition::example_baseline();
        $definition['persons'] = $persons + ['count' => $count] + $definition['persons'];
        $definition['persons']['count'] = $count;

        return $definition;
    }

    /**
     * A definition saved before these settings existed draws exactly what it drew before.
     *
     * @return void
     */
    public function test_an_older_definition_draws_the_same_people(): void {
        $definition = $this->definition([], 5);
        unset(
            $definition['persons']['distribution'],
            $definition['persons']['abilityrange'],
            $definition['persons']['abilitymean'],
            $definition['persons']['abilitysd']
        );

        $people = person_generator::generate($definition, 42);

        // What person_generator did until 0.7.6: Box–Muller, N(μ = 0, σ = 2).
        mt_srand(42);
        $u1 = (mt_rand() + 1) / (mt_getrandmax() + 2);
        $u2 = (mt_rand() + 1) / (mt_getrandmax() + 2);
        $expected = 2.0 * sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);

        $this->assertSame(round($expected, 5), $people[0]['abilityglobal']);
        $this->assertFalse(ability_distribution::of($definition)['explicit']);
    }

    /**
     * Inconsistent settings are refused; an unbounded normal distribution is warned about.
     *
     * @return void
     */
    public function test_settings_are_validated_and_the_share_outside_is_named(): void {
        $bad = [
            ['distribution' => 'lognormal'],
            ['distribution' => 'normal', 'abilitysd' => 0],
            ['distribution' => 'normal', 'abilityrange' => ['min' => 2, 'max' => -2]],
            ['distribution' => 'normal', 'abilitymean' => 5, 'abilityrange' => ['min' => -3, 'max' => 3]],
        ];
        foreach ($bad as $persons) {
            $errors = [];
            $warnings = [];
            ability_distribution::validate($persons, $errors, $warnings);
            $this->assertNotEmpty($errors, json_encode($persons));
        }

        // The old default: 13.4 % outside ±3, said before anybody is generated.
        $errors = [];
        $warnings = [];
        ability_distribution::validate([], $errors, $warnings);
        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('13.4', $warnings[0]);

        // A truncated distribution cannot leave the range: no warning.
        $warnings = [];
        ability_distribution::validate(ability_distribution::RECOMMENDED + [
            'abilityrange' => ['min' => -3, 'max' => 3],
        ], $errors, $warnings);
        $this->assertSame([], $warnings);
    }

    /**
     * The expected share outside agrees with the normal distribution.
     *
     * @return void
     */
    public function test_the_expected_share_outside(): void {
        $params = ['distribution' => 'normal', 'mean' => 0.0, 'sd' => 2.0, 'min' => -3.0, 'max' => 3.0];
        // 2 · (1 − Φ(1.5)) = 0.13361.
        $this->assertEqualsWithDelta(0.13361, ability_distribution::share_outside($params), 0.0002);
        $this->assertEqualsWithDelta(0.5, ability_distribution::cdf(0.0), 1e-7);
        $this->assertEqualsWithDelta(0.97725, ability_distribution::cdf(2.0), 1e-4);
        $this->assertSame(0.0, ability_distribution::share_outside(['distribution' => 'truncated_normal'] + $params));
    }

    /**
     * Truncated means drawn again, not clamped — for global, category and subscale abilities.
     *
     * @return void
     */
    public function test_truncation_redraws_and_never_clamps(): void {
        $definition = $this->definition([
            'distribution' => 'truncated_normal', 'abilitymean' => 0.0, 'abilitysd' => 2.0,
            'abilityrange' => ['min' => -3.0, 'max' => 3.0],
        ], 3000);
        $params = ability_distribution::of($definition);
        $values = [];
        foreach (person_generator::generate($definition, 7) as $person) {
            $values[] = $person['abilityglobal'];
            foreach ($person['profile']['categories'] as $category) {
                $values[] = $category['theta'];
                foreach ($category['subscales'] as $subscale) {
                    $values[] = $subscale['theta'];
                }
            }
        }
        foreach ($values as $value) {
            $this->assertTrue(ability_distribution::inside($params, $value), (string) $value);
        }
        // Clamping would pile values up exactly at the bounds.
        $this->assertCount(0, array_filter($values, static fn(float $v): bool => abs(abs($v) - 3.0) < 1e-9));
    }

    /**
     * The manifest names every parameter; the form writes and reads them back.
     *
     * @return void
     */
    public function test_manifest_and_form_name_the_parameters(): void {
        $this->resetAfterTest();

        $definition = $this->definition([
            'distribution' => 'truncated_normal', 'abilitymean' => 0.25, 'abilitysd' => 1.5,
            'abilityrange' => ['min' => -4.0, 'max' => 4.0],
        ]);
        $ability = test_provisioner::effective_parameters($definition)['ability'];
        $this->assertSame('truncated_normal', $ability['distribution']);
        $this->assertSame(0.25, $ability['mean']);
        $this->assertSame(1.5, $ability['standard_deviation']);
        $this->assertSame(-4.0, $ability['lower_bound']);
        $this->assertSame(4.0, $ability['upper_bound']);
        $this->assertSame(['min' => -4.0, 'max' => 4.0], $ability['engine_scale_range']);

        $data = experiment_form::to_form_data((new experiment_definition($definition))->get_normalised(), 0);
        $back = experiment_form::to_definition($data);
        foreach (['distribution', 'abilitymean', 'abilitysd', 'abilityrange'] as $key) {
            $this->assertEquals($definition['persons'][$key], $back['persons'][$key], $key);
        }
    }
}
