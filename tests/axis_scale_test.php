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

use local_catquizlab\output\axis_scale;
use local_catquizlab\output\scatter_chart;

/**
 * Plot conventions (#105, sections 3 to 9).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\output\axis_scale
 * @covers     \local_catquizlab\output\scatter_chart
 */
final class axis_scale_test extends \advanced_testcase {
    /**
     * Logit axes are symmetric around 0, with 0 as the middle tick and round steps.
     *
     * @return void
     */
    public function test_symmetric_axes(): void {
        // The issue's example: −6.65 … 7.98 had become "−6.65 −2.99 0.66 4.32 7.98".
        $axis = axis_scale::symmetric([-6.65, 7.98]);
        $this->assertSame([-8.0, -6.0, -4.0, -2.0, 0.0, 2.0, 4.0, 6.0, 8.0], $axis['ticks']);

        foreach ([[-0.8, 0.6], [-1.2, 2.4], [0.1, 0.3], [-3.7, -0.2]] as $values) {
            $axis = axis_scale::symmetric($values);
            $this->assertSame(-$axis['max'], $axis['min']);
            $this->assertContains(0.0, $axis['ticks']);
            $this->assertSame(0.0, $axis['ticks'][intdiv(count($axis['ticks']), 2)], 'zero in the middle');
            foreach ($axis['ticks'] as $tick) {
                $this->assertEqualsWithDelta(0.0, fmod($tick * 2, 1), 1e-9, 'integer or half: ' . $tick);
            }
        }

        // The configured ability range is covered even when the data stay inside it.
        $this->assertSame(3.0, axis_scale::symmetric([-1.2, 2.4], 3.0)['max']);
    }

    /**
     * Counts get integer ticks only.
     *
     * @return void
     */
    public function test_integer_axes(): void {
        // The issue's example: "4.55 12.53 20.5 28.48 36.45" for a number of items.
        $axis = axis_scale::integer([4.55, 36.45]);
        $this->assertSame([0.0, 10.0, 20.0, 30.0, 40.0], $axis['ticks']);
        foreach ([[1, 35], [10, 20], [3, 3]] as $values) {
            foreach (axis_scale::integer($values)['ticks'] as $tick) {
                $this->assertSame(floor($tick), $tick);
            }
        }
    }

    /**
     * A comparison plot: the same range on both axes, a square area, the identity at 45°.
     *
     * @return void
     */
    public function test_the_identity_line_is_the_diagonal(): void {
        $chart = new scatter_chart('t', 'x', 'y');
        $chart->set_points([['x' => -1.2, 'y' => -0.9], ['x' => 2.1, 'y' => 2.6]])->add_identity_line('y = x');
        $svg = $chart->render();

        $this->assertSame(1, preg_match(
            '/<line x1="([\d.]+)" y1="([\d.]+)" x2="([\d.]+)" y2="([\d.]+)"[^>]*stroke-dasharray/',
            $svg,
            $m
        ));
        $angle = rad2deg(atan2((float) $m[2] - (float) $m[4], (float) $m[3] - (float) $m[1]));
        $this->assertEqualsWithDelta(45.0, $angle, 0.01);
    }

    /**
     * A trajectory is joined, its band shaded, its points labelled, its basis named.
     *
     * @return void
     */
    public function test_a_trajectory_with_band_labels_and_basis(): void {
        $chart = new scatter_chart('t', 'x', 'y');
        $chart->set_axes(axis_scale::INTEGER, axis_scale::SYMMETRIC)
            ->set_points([
                ['x' => 1, 'y' => 0.4, 'label' => 'step 1'],
                ['x' => 2, 'y' => -0.1, 'label' => 'step 2'],
                ['x' => 3, 'y' => 0.2, 'label' => 'step 3'],
            ])
            ->set_connected()
            ->add_band([
                ['x' => 1, 'lo' => -1.6, 'hi' => 2.4],
                ['x' => 2, 'lo' => -1.5, 'hi' => 1.3],
                ['x' => 3, 'lo' => -0.9, 'hi' => 1.3],
            ], 'estimate ± SE')
            ->set_basis('Data: an experiment');
        $svg = $chart->render();

        $this->assertStringContainsString('data-region="trajectory"', $svg);
        $this->assertStringContainsString('data-region="band"', $svg);
        $this->assertStringContainsString('<title>step 2</title>', $svg);
        $this->assertStringContainsString('Data: an experiment', $svg);
    }
}
