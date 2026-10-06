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

use local_catquizlab\local\axis_profiles;
use local_catquizlab\output\axis_scale;
use local_catquizlab\output\scatter_chart;

/**
 * SVG export and axis control (#108).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\axis_profiles
 * @covers     \local_catquizlab\output\scatter_chart
 */
final class axis_profiles_test extends \advanced_testcase {
    /**
     * A plot with a few points.
     *
     * @return scatter_chart
     */
    protected function chart(): scatter_chart {
        $chart = new scatter_chart('Recovery', 'True', 'Estimated');
        return $chart->set_points([['x' => -1.2, 'y' => -0.8], ['x' => 0.4, 'y' => 0.9], ['x' => 2.2, 'y' => 1.7]]);
    }

    /**
     * The SVG is a file of its own: vectors, text as text, its settings inside.
     *
     * @return void
     */
    public function test_the_svg_carries_its_settings(): void {
        $chart = $this->chart()->add_identity_line('y = x')->set_basis('Data: an experiment');
        $chart->set_export('recovery', 'exp12', ['experimentid' => 12, 'strategy' => 'fastest']);
        $html = $chart->render();

        preg_match('/download="(catquizlab-recovery-exp12-\d{8}-\d{6})\.svg"/', $html, $name);
        $this->assertNotEmpty($name, 'file name: plot type, context, timestamp');
        preg_match('/data:image\/svg\+xml;base64,([^"]+)/', $html, $m);
        $svg = base64_decode($m[1]);
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringContainsString('<text', $svg);
        $this->assertStringContainsString('<circle', $svg);
        $this->assertStringNotContainsString('<image', $svg);

        preg_match('/<metadata>(.*)<\/metadata>/s', $svg, $meta);
        $settings = json_decode(html_entity_decode($meta[1], ENT_QUOTES), true);
        $this->assertSame('recovery', $settings['plot_type']);
        $this->assertSame('shared', $settings['axis']['x']['mode']);
        $this->assertSame('fastest', $settings['filters']['strategy']);
        $this->assertSame('Data: an experiment', $settings['data_source']);
        $this->assertNotNull($settings['axis']['y']['tick_spacing']);

        $this->assertStringContainsString('-plot-settings.json"', $html);
        $this->assertStringContainsString('data-download="csv"', $html);
    }

    /**
     * Manual ranges and tick spacing, and forced symmetry.
     *
     * @return void
     */
    public function test_manual_scale_and_symmetry(): void {
        $chart = $this->chart()->set_axes(axis_scale::LINEAR, axis_scale::LINEAR)
            ->set_fixed_bounds(['ymin' => -3, 'ymax' => 3])->set_tick_spacing(null, 1.5);
        $chart->set_export('error', 'exp1', []);
        $svg = $chart->render();
        foreach (['>-3<', '>-1.5<', '>0<', '>1.5<', '>3<'] as $tick) {
            $this->assertStringContainsString($tick, $svg);
        }

        $chart = $this->chart()->set_axes(axis_scale::INTEGER, axis_scale::LINEAR)->force_symmetric();
        $chart->set_export('error', 'exp1', []);
        preg_match('/<metadata>(.*)<\/metadata>/s', $chart->render(), $meta);
        $settings = json_decode(html_entity_decode($meta[1], ENT_QUOTES), true);
        $this->assertSame(axis_scale::INTEGER, $settings['axis']['x']['mode'], 'a count stays a count');
        $this->assertSame(axis_scale::SYMMETRIC, $settings['axis']['y']['mode']);
        $this->assertSame(-$settings['axis']['y']['max'], $settings['axis']['y']['min']);
    }

    /**
     * A hand-set range on a comparison plot keeps the identity line the diagonal.
     *
     * @return void
     */
    public function test_a_manual_range_keeps_the_diagonal(): void {
        $chart = $this->chart()->add_identity_line('y = x')->set_fixed_bounds(['xmin' => -4, 'xmax' => 4]);
        $svg = $chart->render();
        preg_match('/<line x1="([\d.]+)" y1="([\d.]+)" x2="([\d.]+)" y2="([\d.]+)"[^>]*stroke-dasharray/', $svg, $m);
        $angle = rad2deg(atan2((float) $m[2] - (float) $m[4], (float) $m[3] - (float) $m[1]));
        $this->assertEqualsWithDelta(45.0, $angle, 0.01);
        $this->assertStringContainsString('>-4<', $svg);
    }

    /**
     * Profiles: built in, saved per user, the shared scale.
     *
     * @return void
     */
    public function test_profiles_are_saved_and_loaded(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $builtin = axis_profiles::builtin();
        $expected = ['Ability symmetric [-4, 4]', 'Error symmetric [-2, 2]', 'Integer test length', 'Shared comparison axes'];
        foreach ($expected as $name) {
            $this->assertArrayHasKey($name, $builtin);
        }
        $loaded = axis_profiles::resolve(['profile' => 'Ability symmetric [-4, 4]'])['settings'];
        $this->assertSame([-4.0, 4.0, 1], [$loaded['xmin'], $loaded['xmax'], $loaded['sym']]);

        axis_profiles::save('My ability axes', ['mode' => 'manual', 'ymin' => '-2,5', 'ymax' => '2.5', 'ytick' => '0.5']);
        $this->assertArrayHasKey('My ability axes', axis_profiles::all());
        $mine = axis_profiles::resolve(['profile' => 'My ability axes'])['settings'];
        $this->assertSame(2.5, $mine['ymax']);

        // A built-in profile is not overwritten.
        axis_profiles::save('Integer test length', ['mode' => 'manual', 'xtick' => 99]);
        $this->assertSame(5.0, axis_profiles::resolve(['profile' => 'Integer test length'])['settings']['xtick']);

        // The shared scale: saved once, used by every plot set to it.
        $this->assertSame(
            axis_profiles::AUTO,
            axis_profiles::resolve(['mode' => 'shared'])['settings']['mode'],
            'without a shared scale, a plot falls back to auto'
        );
        axis_profiles::save(axis_profiles::SHARED_NAME, ['mode' => 'manual', 'xmin' => -3, 'xmax' => 3]);
        $shared = axis_profiles::resolve(['mode' => 'shared'])['settings'];
        $this->assertSame(axis_profiles::SHARED, $shared['mode']);
        $this->assertSame(3.0, $shared['xmax']);
        $this->assertSame($shared, axis_profiles::resolve(['profile' => 'Shared comparison axes'])['settings']);
    }
}
