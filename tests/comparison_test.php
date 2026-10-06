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

use local_catquizlab\local\results_query;
use local_catquizlab\local\test_flow;
use local_catquizlab\output\histogram_chart;
use local_catquizlab\output\scatter_chart;

/**
 * Comparing related tests, and the simulated people (#109).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\test_flow
 * @covers     \local_catquizlab\output\scatter_chart
 * @covers     \local_catquizlab\output\histogram_chart
 */
final class comparison_test extends \advanced_testcase {
    /**
     * A trace over two leaf scales under one root, with the engine's scale standard errors.
     *
     * @return array
     */
    protected function trace(): array {
        $item = static fn(string $id, string $scale, float $a, float $b): array =>
            ['id' => $id, 'catscaleid' => $scale, 'model' => 'raschbirnbaum',
                'discrimination' => (string) $a, 'difficulty' => (string) $b, 'guessing' => '0'];
        $played = [$item('1', '5', 1.2, -0.5), $item('2', '6', 0.8, 0.3), $item('3', '5', 1.5, 1.1)];
        $path = [
            ['step' => 1, 'abilities' => ['1' => 0.4, '5' => 0.4]],
            ['step' => 2, 'abilities' => ['1' => -0.1, '5' => -0.1, '6' => -0.1]],
            ['step' => 3, 'abilities' => ['1' => 0.2, '5' => 0.3, '6' => -0.2]],
        ];
        // What the engine reports for scale 5: its own items at its final estimate.
        $ti5 = test_flow::item_information($played[0], 0.3) + test_flow::item_information($played[2], 0.3);
        $global = 0.0;
        foreach ($played as $p) {
            $global += test_flow::item_information($p, 0.2);
        }

        return [
            'items' => [1, 2, 3], 'nitems' => 3, 'information' => $global, 'finalse' => 1 / sqrt($global),
            'abilitypath' => $path,
            'scalestandarderrors' => ['5' => 1 / sqrt($ti5), '6' => 9.99],
            'progress' => ['playedquestions' => $played, 'responses' => [],
                'activescales' => [5], 'droppedscales' => [6], 'lockedscales' => []],
        ];
    }

    /**
     * Global series and scale series, with the engine check deciding what is shown.
     *
     * @return void
     */
    public function test_series_global_and_per_scale(): void {
        $trace = $this->trace();
        $flow = test_flow::steps(['trace' => $trace]);

        $ability = test_flow::series($flow, $trace, 'ability');
        $this->assertSame([0.4, -0.1, 0.2], array_column($ability['points'], 'y'));
        $this->assertSame([1, 2, 3], array_column($ability['points'], 'x'));

        // Scale 5 agrees with the engine: its SE per step is shown, from its own items.
        $se5 = test_flow::series($flow, $trace, 'se', 5, [5]);
        $this->assertTrue($se5['consistent']);
        $this->assertSame('active', $se5['status']);
        $this->assertEqualsWithDelta(
            1 / sqrt(test_flow::item_information($trace['progress']['playedquestions'][0], 0.4)),
            $se5['points'][0]['y'],
            1e-12
        );
        // Step 2 played an item of scale 6: scale 5's information is still its first item's.
        $this->assertEqualsWithDelta(
            1 / sqrt(test_flow::item_information($trace['progress']['playedquestions'][0], -0.1)),
            $se5['points'][1]['y'],
            1e-12
        );

        // Scale 6 does not agree: no SE, but its estimate still, and its status.
        $se6 = test_flow::series($flow, $trace, 'se', 6, [6]);
        $this->assertFalse($se6['consistent']);
        $this->assertSame([null, null, null], array_column($se6['points'], 'y'));
        $this->assertSame('dropped', $se6['status']);
        $this->assertSame([null, -0.1, -0.2], array_column(test_flow::series($flow, $trace, 'ability', 6, [6])['points'], 'y'));
    }

    /**
     * A scale's subtree follows the provisioned tree.
     *
     * @return void
     */
    public function test_scale_subtree(): void {
        global $DB;
        $this->resetAfterTest();
        foreach ([[10, 0], [11, 10], [12, 10], [13, 11]] as [$scale, $parent]) {
            $DB->insert_record('local_catquizlab_scalemap', (object) [
                'runid' => 77, 'catscaleid' => $scale, 'parentcatscaleid' => $parent, 'contextid' => 0,
                'level' => $parent === 0 ? 0 : 1, 'nodekey' => 'n' . $scale, 'generation' => 1, 'name' => 'S' . $scale,
                'timecreated' => time(),
            ]);
        }
        $this->assertEqualsCanonicalizing([10, 11, 12, 13], results_query::scale_subtree(77, 10));
        $this->assertEqualsCanonicalizing([11, 13], results_query::scale_subtree(77, 11));
        $this->assertSame([12], results_query::scale_subtree(77, 12));
    }

    /**
     * Several traces: one colour per group, a legend, hand-set ranges, downloads.
     *
     * @return void
     */
    public function test_several_traces_in_one_plot(): void {
        $chart = new scatter_chart('Compare', 'Step', 'Ability');
        $chart->add_series('Test 1', 'CAT (fastest)', [['x' => 1, 'y' => 0.2], ['x' => 2, 'y' => 0.5]])
            ->add_series('Test 2', 'Infer all subscales (allsubs)', [['x' => 1, 'y' => -0.3], ['x' => 2, 'y' => null],
                ['x' => 3, 'y' => 0.1]])
            ->set_fixed_bounds(['ymin' => -2, 'ymax' => 2, 'xmin' => null]);
        $svg = $chart->render();

        $this->assertSame(2, substr_count($svg, 'data-region="series"'));
        $this->assertStringContainsString('CAT (fastest)', $svg);
        $this->assertStringContainsString('Infer all subscales (allsubs)', $svg);
        // The hand-set y range, with its round ticks.
        $this->assertStringContainsString('>-2<', $svg);
        $this->assertStringContainsString('>2<', $svg);

        $links = $chart->download_links('compare', [['step' => 1, 'value' => 0.2]]);
        $this->assertStringContainsString('download="compare.svg"', $links);
        $this->assertStringContainsString('download="compare.csv"', $links);
        preg_match('/data:image\/svg\+xml;base64,([^"]+)/', $links, $m);
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', base64_decode($m[1]));
    }

    /**
     * The histogram counts every value once and marks the bounds.
     *
     * @return void
     */
    public function test_the_people_histogram(): void {
        $values = [-2.4, -1.1, -0.2, 0.0, 0.3, 0.9, 1.7, 2.8];
        $chart = (new histogram_chart('People', 'True ability', $values))->set_marks([-3, 3]);
        $bins = $chart->bins();
        $this->assertSame(count($values), array_sum($bins['counts']));
        $this->assertSame(-3.0, $bins['min']);
        $this->assertSame(3.0, $bins['max']);
        $this->assertSame(2, substr_count($chart->render(), 'data-region="bound"'));
    }
}
