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
 * Points where they are, identical ones as one density marker, a local axis around 625 (#143).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\output\scatter_chart
 * @covers     \local_catquizlab\output\axis_scale
 */
final class density_marker_test extends \advanced_testcase {
    /**
     * The test-length plot as results_page draws it.
     *
     * @param array[] $points The points.
     * @return scatter_chart
     */
    protected function length_chart(array $points): scatter_chart {
        $chart = new scatter_chart('Length vs SE', 'Items', 'SE');
        $chart->set_axes(axis_scale::INTEGER, axis_scale::LINEAR, ['yfromzero' => true, 'xlocal' => true]);

        return $chart->set_points($points);
    }

    /**
     * The point markers of a drawing.
     *
     * @param string $svg The drawing.
     * @return array[] cx, r, x, y, count, title.
     */
    protected function markers(string $svg): array {
        preg_match_all(
            '~<circle cx="([^"]+)" cy="([^"]+)" r="([^"]+)"[^>]*data-x="([^"]+)" data-y="([^"]+)" data-count="(\d+)"'
                . ' data-region="point">(?:<title>([^<]*)</title>)?</circle>~',
            $svg,
            $matches,
            PREG_SET_ORDER
        );

        return array_map(static fn(array $m): array => ['cx' => (float) $m[1], 'cy' => (float) $m[2],
            'r' => (float) $m[3], 'x' => (float) $m[4], 'y' => (float) $m[5], 'count' => (int) $m[6],
            'title' => html_entity_decode($m[7] ?? '', ENT_QUOTES)], $matches);
    }

    /**
     * The plot settings embedded in a drawing.
     *
     * @param string $svg The drawing.
     * @return array
     */
    protected function settings(string $svg): array {
        preg_match('~<metadata>(.*?)</metadata>~s', $svg, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    }

    /**
     * Data provider: how many identical sittings.
     *
     * @return array
     */
    public static function counts(): array {
        return ['1' => [1], '2' => [2], '10' => [10], '100' => [100], '625' => [625]];
    }

    /**
     * N sittings of exactly 625 questions and the same SE: one marker at exactly 625, its count, its area.
     *
     * @dataProvider counts
     * @param int $n How many.
     * @return void
     */
    public function test_identical_points_are_one_marker_at_their_value(int $n): void {
        $this->resetAfterTest();
        $points = array_fill(0, $n, ['x' => 625, 'y' => 0.31, 'label' => 'CLASSIC']);
        $svg = $this->length_chart($points)->render();
        $markers = $this->markers($svg);

        $this->assertCount(1, $markers);
        $this->assertSame($n, $markers[0]['count']);
        $this->assertSame(625.0, $markers[0]['x'], 'the value itself, not moved');
        $this->assertSame(0.31, $markers[0]['y']);
        $this->assertSame(scatter_chart::marker_radius($n), $markers[0]['r']);
        if ($n > 1) {
            $this->assertStringContainsString((string) $n, $markers[0]['title']);
        } else {
            $this->assertSame('CLASSIC', $markers[0]['title']);
        }

        // The axis is centred on 625, and the marker sits on its middle tick.
        $settings = $this->settings($svg);
        $this->assertSame([624, 625, 626], array_map('intval', $settings['axis']['x']['ticks']));
        $this->assertSame(['points' => $n, 'markers' => 1, 'largest' => $n, 'hidden' => 0,
            'size' => scatter_chart::SIZE_FUNCTION], $settings['markers']);
        preg_match_all('~<line x1="([^"]+)" y1="[^"]+" x2="\1" y2="[^"]+"~', $svg, $ticks);
        $this->assertContains((string) $markers[0]['cx'], array_map(
            static fn($v) => (string) (float) $v,
            $ticks[1]
        ), 'the marker is on the tick of 625');
    }

    /**
     * The size function: one point keeps radius 2.5, area grows with 1 + ln n, strictly.
     *
     * @return void
     */
    public function test_the_marker_area_grows_with_the_logarithm(): void {
        $this->assertSame(2.5, scatter_chart::marker_radius(1));
        $previous = 0.0;
        foreach ([1, 2, 10, 100, 625] as $n) {
            $r = scatter_chart::marker_radius($n);
            $this->assertGreaterThan($previous, $r);
            $this->assertEqualsWithDelta(1 + log($n), ($r / 2.5) ** 2, 0.02, "area at n = {$n}");
            $previous = $r;
        }
        $this->assertLessThan(8.0, scatter_chart::marker_radius(625), 'many, without covering the plot');
    }

    /**
     * Different values stay different markers; all points are accounted for; drawing twice draws the same.
     *
     * @return void
     */
    public function test_counts_add_up_and_the_drawing_is_deterministic(): void {
        $this->resetAfterTest();
        $points = [];
        for ($i = 0; $i < 625; $i++) {
            $points[] = ['x' => 625, 'y' => [0.30, 0.31, 0.32, 0.33, 0.301][$i % 5], 'label' => 's' . $i];
        }
        $first = $this->markers($this->length_chart($points)->render());
        $second = $this->markers($this->length_chart($points)->render());

        $this->assertCount(5, $first, '0.30 and 0.301 are different values and stay apart');
        $this->assertSame(625, array_sum(array_column($first, 'count')));
        $this->assertSame($first, $second);
        foreach ($first as $marker) {
            $this->assertSame(625.0, $marker['x']);
            $this->assertSame(125, $marker['count']);
        }
    }

    /**
     * Spread-out lengths keep the ordinary axis; a plot that does not ask for it keeps its zero.
     *
     * @return void
     */
    public function test_local_centring_only_where_values_are_close_and_asked_for(): void {
        $this->assertNull(axis_scale::local_integer([20, 625]));
        $this->assertNull(axis_scale::local_integer([620, 625]), 'wider than LOCAL_SPAN');
        $this->assertSame(
            [621.0, 622.0, 623.0, 624.0, 625.0, 626.0],
            axis_scale::local_integer([622, 625])['ticks']
        );

        $this->resetAfterTest();
        $mixed = $this->settings($this->length_chart([['x' => 20, 'y' => 0.4], ['x' => 625, 'y' => 0.3]])->render());
        $this->assertSame(0.0, (float) $mixed['axis']['x']['min']);

        $chart = new scatter_chart('t', 'x', 'y');
        $chart->set_axes(axis_scale::INTEGER, axis_scale::LINEAR, ['xfromzero' => true]);
        $plain = $this->settings($chart->set_points([['x' => 625, 'y' => 0.3]])->render());
        $this->assertSame(0.0, (float) $plain['axis']['x']['min'], 'no local axis unless the plot asks for one');
    }

    /**
     * The downloads describe the same points: the plot settings count them, the SVG carries their values.
     *
     * @return void
     */
    public function test_the_exports_carry_every_point(): void {
        $this->resetAfterTest();
        $points = array_fill(0, 100, ['x' => 625, 'y' => 0.31, 'label' => 'x']);
        $chart = $this->length_chart($points);
        $rows = array_map(static fn(array $p): array => ['items' => $p['x'], 'se' => $p['y']], $points);
        $links = $chart->download_links('plot', $rows);

        preg_match('~data:application/json;base64,([^"]+)"~', $links, $json);
        $settings = json_decode(base64_decode($json[1]), true);
        $this->assertSame(100, $settings['markers']['points']);
        $this->assertSame(1, $settings['markers']['markers']);

        preg_match('~data:image/svg\+xml;base64,([^"]+)"~', $links, $svg);
        $markers = $this->markers(base64_decode($svg[1]));
        $this->assertSame(100, $markers[0]['count']);

        preg_match('~data:text/csv;charset=utf-8;base64,([^"]+)"~', $links, $csv);
        $lines = array_filter(explode("\n", base64_decode($csv[1])));
        $this->assertCount(101, $lines, 'the CSV keeps every sitting: a header and 100 rows');
    }
}
