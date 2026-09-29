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

use local_catquizlab\local\test_flow;

/**
 * Standard error and test information per step (#106).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\test_flow
 */
final class test_flow_metrics_test extends \advanced_testcase {
    /**
     * A trace of three steps, shaped as the engine keeps it, with its reported information.
     *
     * @param float $reportedinformation What the engine reports at the end.
     * @param string $model The items' model.
     * @return array
     */
    protected function trace(float $reportedinformation, string $model = 'raschbirnbaum'): array {
        $item = static function (string $id, string $scale, string $a, string $b) use ($model): array {
            return [
                'id' => $id, 'catscaleid' => $scale, 'model' => $model,
                'discrimination' => $a, 'difficulty' => $b, 'guessing' => '0',
            ];
        };
        $items = [$item('11', '5', '1.2', '-0.5'), $item('12', '6', '0.8', '0.3'), $item('13', '5', '1.5', '1.1')];
        $played = [];
        foreach ($items as $item) {
            $played[$item['id']] = $item;
        }

        return [
            'items' => [11, 12, 13],
            'nitems' => 3,
            'information' => $reportedinformation,
            'finalse' => 1 / sqrt($reportedinformation),
            'abilitypath' => [
                ['step' => 1, 'abilities' => ['1' => 0.4, '5' => 0.4]],
                ['step' => 2, 'abilities' => ['1' => -0.1, '5' => -0.1, '6' => -0.1]],
                ['step' => 3, 'abilities' => ['1' => 0.2, '5' => 0.2, '6' => 0.2]],
            ],
            'progress' => [
                'playedquestions' => $played,
                'responses' => [
                    '11' => ['questionid' => '11', 'fraction' => '1.000'],
                    '12' => ['questionid' => '12', 'fraction' => '0.000'],
                    '13' => ['questionid' => '13', 'fraction' => '1.000'],
                ],
                'activescales' => [5, 6], 'droppedscales' => [], 'lockedscales' => [],
            ],
        ];
    }

    /**
     * The information of a 2PL item: a²·P·(1−P).
     *
     * @return void
     */
    public function test_item_information_is_the_logistic_formula(): void {
        $item = ['model' => 'raschbirnbaum', 'discrimination' => 1.2, 'difficulty' => -0.5, 'guessing' => 0];
        $p = 1 / (1 + exp(-1.2 * (0.4 + 0.5)));
        $this->assertEqualsWithDelta(1.44 * $p * (1 - $p), test_flow::item_information($item, 0.4), 1e-12);

        // A model whose information is not computed here is not guessed at.
        $this->assertNull(test_flow::item_information(['model' => 'grmgeneralized'], 0.0));
    }

    /**
     * Where the last step agrees with the engine, every step has TI@n and SE.
     *
     * @return void
     */
    public function test_steps_carry_information_and_se_when_they_agree_with_the_engine(): void {
        // What the engine would report: the sum at the final estimate 0.2.
        $sum = 0.0;
        foreach ($this->trace(1.0)['progress']['playedquestions'] as $item) {
            $sum += test_flow::item_information($item, 0.2);
        }

        $flow = test_flow::steps(['trace' => $this->trace($sum)]);
        $this->assertTrue($flow['final']['consistent']);
        $this->assertCount(3, $flow['steps']);
        foreach ($flow['steps'] as $step) {
            $this->assertNotNull($step['ti']);
            $this->assertEqualsWithDelta(1 / sqrt($step['ti']), $step['se'], 1e-12);
        }
        $this->assertEqualsWithDelta($sum, $flow['steps'][2]['ti'], 1e-9);
        // Information grows with every item at a fixed ability; the first step
        // holds one item, the last three.
        $this->assertLessThan($flow['steps'][2]['ti'], $flow['steps'][0]['ti']);
        $this->assertSame(3, $flow['steps'][1]['scalesestimated']);
        // The score comes from the responses.
        $this->assertSame([1.0, 0.0, 1.0], array_column($flow['steps'], 'fraction'));
    }

    /**
     * Where it does not agree, nothing computed is shown.
     *
     * @return void
     */
    public function test_nothing_unconfirmed_is_shown(): void {
        $flow = test_flow::steps(['trace' => $this->trace(9.99)]);
        $this->assertFalse($flow['final']['consistent']);
        foreach ($flow['steps'] as $step) {
            $this->assertNull($step['ti']);
            $this->assertNull($step['se']);
        }
        // The engine's own numbers still stand.
        $this->assertSame(9.99, $flow['final']['engine_ti']);

        $flow = test_flow::steps(['trace' => $this->trace(1.0, 'grmgeneralized')]);
        $this->assertFalse($flow['final']['consistent']);
        $this->assertNull($flow['steps'][0]['ti']);
    }
}
