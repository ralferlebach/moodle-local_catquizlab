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
use local_catquizlab\local\compare_templates;
use local_catquizlab\output\axis_scale;
use local_catquizlab\output\scatter_chart;

/**
 * The optional parts of #105, #108 and #109.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\output\scatter_chart
 * @covers     \local_catquizlab\local\axis_profiles
 * @covers     \local_catquizlab\local\compare_templates
 */
final class optional_remainders_test extends \advanced_testcase {
    /**
     * A robust range leaves outliers out of the axis, and says how many (#105, section 6).
     *
     * @return void
     */
    public function test_a_robust_range_counts_what_it_leaves_out(): void {
        $points = [];
        for ($i = 0; $i < 200; $i++) {
            $points[] = ['x' => $i / 50 - 2, 'y' => sin($i) / 2];
        }
        $points[] = ['x' => 0.0, 'y' => 12.0];
        $points[] = ['x' => 1.0, 'y' => -9.0];
        $chart = (new scatter_chart('Error', 'True', 'Error'))->set_axes(axis_scale::SYMMETRIC, axis_scale::SYMMETRIC)
            ->set_points($points);
        $this->assertGreaterThanOrEqual(12.0, $chart->last_settings()['axis']['y']['max']);

        $chart = (new scatter_chart('Error', 'True', 'Error'))->set_axes(axis_scale::SYMMETRIC, axis_scale::SYMMETRIC)
            ->set_points($points)->set_robust();
        $html = $chart->render();
        $this->assertLessThanOrEqual(1.0, $chart->last_settings()['axis']['y']['max']);
        $this->assertTrue($chart->last_settings()['axis']['robust']);
        $this->assertStringContainsString('2 of 202 points lie outside the displayed range', $html);
    }

    /**
     * Profiles for an experiment, seen by everyone on it (#108).
     *
     * @return void
     */
    public function test_profiles_for_an_experiment(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        axis_profiles::save_for_experiment(42, 'Paper figure 3', ['mode' => 'manual', 'ymin' => -2, 'ymax' => 2]);
        $this->assertArrayHasKey('Paper figure 3', axis_profiles::all(42));
        $this->assertArrayNotHasKey('Paper figure 3', axis_profiles::all(43));
        $this->assertSame(2.0, axis_profiles::resolve(['profile' => 'Paper figure 3'], 42)['settings']['ymax']);

        // Another user sees it too; nobody overwrites a built-in profile.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertArrayHasKey('Paper figure 3', axis_profiles::all(42));
        axis_profiles::save_for_experiment(42, 'Integer test length', ['mode' => 'manual', 'xtick' => 99]);
        $this->assertSame(5.0, axis_profiles::resolve(['profile' => 'Integer test length'], 42)['settings']['xtick']);
    }

    /**
     * A comparison saved by name comes back as it was (#109).
     *
     * @return void
     */
    public function test_comparison_templates(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        compare_templates::save('SE by strategy', ['metric' => 'se', 'group' => 'strategy', 'scale' => 65,
            'layout' => 'multiples', 'ymin' => 0, 'ymax' => '1.5', 'xmin' => null]);
        $saved = compare_templates::mine()['SE by strategy'];
        $this->assertSame(['se', 'strategy', 65, 'multiples'], [$saved['metric'], $saved['group'], $saved['scale'],
            $saved['layout']]);
        $this->assertSame(1.5, $saved['ymax']);
        $this->assertNull($saved['xmin']);
    }

    /**
     * A plot becomes a PDF with its drawing as vectors, through Moodle's TCPDF (#108).
     *
     * @return void
     */
    public function test_a_plot_as_a_vector_pdf(): void {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $chart = (new scatter_chart('Recovery', 'True', 'Estimated'))
            ->set_points([['x' => -1.2, 'y' => -0.8], ['x' => 0.4, 'y' => 0.9], ['x' => 2.2, 'y' => 1.7]])
            ->add_identity_line('y = x');
        $chart->set_export('recovery', 'exp1', []);
        $html = $chart->render();
        preg_match('/data:image\/svg\+xml;base64,([^"]+)/', $html, $m);
        $svg = preg_replace('/<metadata>.*?<\/metadata>/s', '', base64_decode($m[1]));

        $pdf = new \pdf('L', 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->ImageSVG('@' . $svg, 10, 10, 277, 164);
        $bytes = $pdf->Output('', 'S');

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertStringNotContainsString('/Subtype /Image', $bytes, 'drawn as vectors, not as a picture');
        $this->assertStringContainsString('data-download="pdf"', $html);
        $this->assertStringContainsString('data-download="png"', $html);
    }
}
