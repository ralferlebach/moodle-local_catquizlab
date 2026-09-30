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
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\test_provisioner;

/**
 * Category and subscale SD, and severity factors, set by the experiment (#102).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\person_generator
 */
final class person_variation_test extends \advanced_testcase {
    /**
     * A definition with persons settings on top of the baseline.
     *
     * @param array $persons Settings.
     * @return array
     */
    protected function definition(array $persons): array {
        $definition = experiment_definition::example_baseline();
        $definition['persons'] = $persons + $definition['persons'];

        return $definition;
    }

    /**
     * The SDs in force: the stratum times the severity, or set outright.
     *
     * @return void
     */
    public function test_the_sds_in_force(): void {
        // Subscale variation: base 0.5 / 0.5; medium is factor 1, strong 2.
        $medium = person_generator::deviation_sds($this->definition(['stratum' => 'subscalevariation', 'severity' => 'medium']));
        $this->assertSame([0.5, 0.5, false], [$medium['category'], $medium['subscale'], $medium['explicit']]);

        $strong = person_generator::deviation_sds($this->definition(['stratum' => 'subscalevariation', 'severity' => 'strong',
            'severityscale' => ['strong' => 3.0]]));
        $this->assertSame([1.5, 1.5], [$strong['category'], $strong['subscale']]);

        $explicit = person_generator::deviation_sds($this->definition(['stratum' => 'conforming', 'severity' => 'none',
            'variation' => ['category' => 0.8, 'subscale' => 0.3]]));
        $this->assertSame([0.8, 0.3, true], [$explicit['category'], $explicit['subscale'], $explicit['explicit']]);
    }

    /**
     * The generator draws with the SD set, measured on 2000 people.
     *
     * @return void
     */
    public function test_the_generator_draws_with_it(): void {
        $definition = $this->definition(['stratum' => 'conforming', 'severity' => 'none', 'count' => 2000,
            'distribution' => 'normal', 'abilitysd' => 1.0, 'abilityrange' => ['min' => -9, 'max' => 9],
            'variation' => ['category' => 0.8, 'subscale' => 0.0]]);
        $deviations = [];
        foreach (person_generator::generate($definition, 11) as $person) {
            foreach ($person['profile']['categories'] as $category) {
                $deviations[] = $category['theta'] - $person['abilityglobal'];
            }
        }
        $mean = array_sum($deviations) / count($deviations);
        $sd = sqrt(array_sum(array_map(static fn(float $d): float => ($d - $mean) ** 2, $deviations)) / (count($deviations) - 1));
        $this->assertEqualsWithDelta(0.8, $sd, 0.03);
    }

    /**
     * Validation, manifest and the form.
     *
     * @return void
     */
    public function test_validation_manifest_and_form(): void {
        $this->resetAfterTest();

        $bad = $this->definition(['variation' => ['category' => -0.2]]);
        $this->assertFalse((new experiment_definition($bad))->validate()['valid']);

        $definition = $this->definition(['stratum' => 'categoryvariation', 'severity' => 'mild',
            'severityscale' => ['mild' => 0.4, 'medium' => 1.0, 'strong' => 2.5], 'variation' => ['subscale' => 0.25]]);
        $ability = test_provisioner::effective_parameters($definition)['ability'];
        $this->assertSame(0.2, $ability['category_sd']);
        $this->assertSame(0.25, $ability['subscale_sd']);

        $data = experiment_form::to_form_data((new experiment_definition($definition))->get_normalised(), 0);
        $this->assertSame('', $data['catsd']);
        $back = experiment_form::to_definition($data);
        $this->assertEquals(['subscale' => 0.25], $back['persons']['variation']);
        $this->assertEquals(0.4, $back['persons']['severityscale']['mild']);
        $this->assertEquals(2.5, $back['persons']['severityscale']['strong']);
    }
}
