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
 * Tests for the declarative experiment definition and its validator.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;

/**
 * Experiment definition validator tests.
 *
 * @covers \local_catquizlab\local\experiment_definition
 */
final class experiment_definition_test extends \advanced_testcase {
    /**
     * The bundled baseline example validates cleanly.
     *
     * @return void
     */
    public function test_example_baseline_is_valid(): void {
        $def = new experiment_definition(experiment_definition::example_baseline());
        $result = $def->validate();

        $this->assertTrue($result['valid'], 'Baseline example should validate. Errors: '
            . implode('; ', $result['errors']));
        $this->assertSame([], $result['errors']);
    }

    /**
     * A definition round-trips through JSON.
     *
     * @return void
     */
    public function test_from_json_roundtrip(): void {
        $json = json_encode(experiment_definition::example_baseline());
        $def = experiment_definition::from_json($json);

        $this->assertTrue($def->validate()['valid']);
    }

    /**
     * Non-JSON input is rejected with a clear exception.
     *
     * @return void
     */
    public function test_from_json_rejects_garbage(): void {
        $this->expectException(\invalid_parameter_exception::class);
        experiment_definition::from_json('not json at all');
    }

    /**
     * Each individual defect is reported.
     *
     * @dataProvider defect_provider
     * @param callable $mutate Applies one defect to the baseline.
     * @return void
     */
    public function test_defects_are_detected(callable $mutate): void {
        $data = experiment_definition::example_baseline();
        $mutate($data);

        $result = (new experiment_definition($data))->validate();
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * One-defect mutations of the baseline, each of which must fail validation.
     *
     * @return array<string, array{0: callable}>
     */
    public static function defect_provider(): array {
        return [
            'missing name'        => [function (array &$d): void {
                unset($d['name']);
            }],
            'blank name'          => [function (array &$d): void {
                $d['name'] = '   ';
            }],
            'bad tier'            => [function (array &$d): void {
                $d['tier'] = 'silver';
            }],
            'bad model'           => [function (array &$d): void {
                $d['model'] = 'quantum';
            }],
            'bad strategy'        => [function (array &$d): void {
                $d['strategy'] = 'vibes';
            }],
            'zero replications'   => [function (array &$d): void {
                $d['replications'] = 0;
            }],
            'seed not int'        => [function (array &$d): void {
                $d['seed'] = 'abc';
            }],
            'missing pool'        => [function (array &$d): void {
                unset($d['pool']);
            }],
            'bad variant'         => [function (array &$d): void {
                $d['pool']['variant'] = 'wobbly';
            }],
            'missing scales'      => [function (array &$d): void {
                unset($d['pool']['scales']);
            }],
            'missing template'    => [function (array &$d): void {
                unset($d['pool']['questiontemplate']);
            }],
            'missing itemnaming'  => [function (array &$d): void {
                unset($d['pool']['itemnaming']);
            }],
            'missing persons'     => [function (array &$d): void {
                unset($d['persons']);
            }],
            'bad stratum'         => [function (array &$d): void {
                $d['persons']['stratum'] = 'lazy';
            }],
            'missing personnaming' => [function (array &$d): void {
                unset($d['persons']['naming']);
            }],
            'min gt max'          => [function (array &$d): void {
                $d['budgets']['minitems'] = 300;
            }],
            'no courses'          => [function (array &$d): void {
                $d['courses'] = [];
            }],
            'no tests'            => [function (array &$d): void {
                unset($d['tests']);
            }],
        ];
    }

    /**
     * Defaults are filled for a sparse definition.
     *
     * @return void
     */
    public function test_apply_defaults(): void {
        $normalised = experiment_definition::apply_defaults(['name' => 'x']);

        $this->assertSame('baseline', $normalised['tier']);
        $this->assertSame('raschbirnbaum', $normalised['model']);
        $this->assertSame(1, $normalised['replications']);
        $this->assertSame(10, $normalised['pool']['scales']['categories']);
        $this->assertSame(250, $normalised['budgets']['maxitems']);
        $this->assertSame(60, $normalised['timing']['faildelay']);
    }
}
