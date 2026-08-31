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
 * A scatterplot rendered as inline SVG.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\output;

/**
 * Draws a scatterplot with labelled axes and reference lines.
 *
 * Moodle's chart API covers bars, lines and pies but not scatter, and the
 * results specification asks for several: estimate against ground truth with an
 * identity line, error against ground truth with a zero line, standard error
 * against test length with the SE targets. Those are the plots where a reader
 * sees bias and spread directly, so they are worth drawing rather than
 * approximating with a bar chart.
 *
 * The output is static inline SVG. It needs no JavaScript, prints, and survives
 * being copied into a document. Since an SVG cannot be read by a screen reader
 * in any useful way, every plot carries a title, a description and an
 * accompanying summary table; the visual and the table come from the same
 * values.
 */
class scatter_chart {
    /** @var int Plot width in user units. */
    protected const WIDTH = 640;

    /** @var int Plot height in user units. */
    protected const HEIGHT = 380;

    /** @var int Left margin, leaving room for the y axis labels. */
    protected const MARGIN_LEFT = 62;

    /** @var int Bottom margin, leaving room for the x axis labels. */
    protected const MARGIN_BOTTOM = 52;

    /** @var int Top margin. */
    protected const MARGIN_TOP = 18;

    /** @var int Right margin. */
    protected const MARGIN_RIGHT = 18;

    /** @var array{x: float, y: float}[] The points. */
    protected array $points = [];

    /** @var array The reference lines. */
    protected array $references = [];

    /** @var string The x axis label, including its unit. */
    protected string $xlabel = '';

    /** @var string The y axis label, including its unit. */
    protected string $ylabel = '';

    /** @var string The accessible title. */
    protected string $title = '';

    /** @var string The accessible description: what one point is. */
    protected string $description = '';

    /**
     * Construct a plot.
     *
     * @param string $title The plot title.
     * @param string $xlabel The x axis label including its unit.
     * @param string $ylabel The y axis label including its unit.
     */
    public function __construct(string $title, string $xlabel, string $ylabel) {
        $this->title = $title;
        $this->xlabel = $xlabel;
        $this->ylabel = $ylabel;
    }

    /**
     * Add the points.
     *
     * @param array $points Each with numeric 'x' and 'y'.
     * @return self
     */
    public function set_points(array $points): self {
        $this->points = [];
        foreach ($points as $point) {
            if (isset($point['x'], $point['y']) && is_numeric($point['x']) && is_numeric($point['y'])) {
                $this->points[] = ['x' => (float) $point['x'], 'y' => (float) $point['y']];
            }
        }

        return $this;
    }

    /**
     * State what a single point represents, so an aggregated point is not read
     * as an individual observation.
     *
     * @param string $description The description.
     * @return self
     */
    public function set_description(string $description): self {
        $this->description = $description;

        return $this;
    }

    /**
     * Add the identity line y = x.
     *
     * @param string $label The line label.
     * @return self
     */
    public function add_identity_line(string $label): self {
        $this->references[] = ['kind' => 'identity', 'label' => $label];

        return $this;
    }

    /**
     * Add a horizontal reference line.
     *
     * @param float $y The y value.
     * @param string $label The line label.
     * @return self
     */
    public function add_horizontal_line(float $y, string $label): self {
        $this->references[] = ['kind' => 'horizontal', 'value' => $y, 'label' => $label];

        return $this;
    }

    /**
     * Render the plot as inline SVG.
     *
     * @return string The SVG markup, or an empty-state notice when there is nothing to plot.
     */
    public function render(): string {
        if ($this->points === []) {
            return \html_writer::div(
                get_string('chart:nodata', 'local_catquizlab'),
                'alert alert-info'
            );
        }

        $bounds = $this->bounds();
        $plotwidth = self::WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
        $plotheight = self::HEIGHT - self::MARGIN_TOP - self::MARGIN_BOTTOM;

        $sx = static function (float $x) use ($bounds, $plotwidth): float {
            $span = $bounds['xmax'] - $bounds['xmin'];
            $t = $span > 0 ? ($x - $bounds['xmin']) / $span : 0.5;
            return self::MARGIN_LEFT + $t * $plotwidth;
        };
        $sy = static function (float $y) use ($bounds, $plotheight): float {
            $span = $bounds['ymax'] - $bounds['ymin'];
            $t = $span > 0 ? ($y - $bounds['ymin']) / $span : 0.5;
            return self::MARGIN_TOP + (1 - $t) * $plotheight;
        };

        $titleid = 'catlabtitle' . uniqid();
        $descid = 'catlabdesc' . uniqid();

        $svg = '<svg viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '" '
            . 'class="local-catquizlab-chart" role="img" '
            . 'aria-labelledby="' . $titleid . ' ' . $descid . '" '
            . 'style="max-width:100%;height:auto;">';
        $svg .= '<title id="' . $titleid . '">' . s($this->title) . '</title>';
        $svg .= '<desc id="' . $descid . '">' . s($this->description) . '</desc>';

        // Axes.
        $svg .= $this->line(
            self::MARGIN_LEFT,
            self::MARGIN_TOP + $plotheight,
            self::MARGIN_LEFT + $plotwidth,
            self::MARGIN_TOP + $plotheight,
            '#666',
            1
        );
        $svg .= $this->line(
            self::MARGIN_LEFT,
            self::MARGIN_TOP,
            self::MARGIN_LEFT,
            self::MARGIN_TOP + $plotheight,
            '#666',
            1
        );

        // Ticks with their values, so a reader can put numbers on the points.
        foreach ($this->ticks($bounds['xmin'], $bounds['xmax']) as $value) {
            $x = $sx($value);
            $svg .= $this->line($x, self::MARGIN_TOP + $plotheight, $x, self::MARGIN_TOP + $plotheight + 5, '#666', 1);
            $svg .= $this->text($x, self::MARGIN_TOP + $plotheight + 18, self::format($value), 'middle');
        }
        foreach ($this->ticks($bounds['ymin'], $bounds['ymax']) as $value) {
            $y = $sy($value);
            $svg .= $this->line(self::MARGIN_LEFT - 5, $y, self::MARGIN_LEFT, $y, '#666', 1);
            $svg .= $this->text(self::MARGIN_LEFT - 9, $y + 4, self::format($value), 'end');
        }

        // Reference lines, drawn under the points.
        foreach ($this->references as $reference) {
            if ($reference['kind'] === 'identity') {
                $lo = max($bounds['xmin'], $bounds['ymin']);
                $hi = min($bounds['xmax'], $bounds['ymax']);
                $svg .= $this->line($sx($lo), $sy($lo), $sx($hi), $sy($hi), '#c0392b', 1.5, '6,4');
                $svg .= $this->text(
                    $sx($hi) - 4,
                    $sy($hi) - 6,
                    $reference['label'],
                    'end',
                    '#c0392b'
                );
            } else {
                $y = $sy((float) $reference['value']);
                $svg .= $this->line(self::MARGIN_LEFT, $y, self::MARGIN_LEFT + $plotwidth, $y, '#2980b9', 1.5, '6,4');
                $svg .= $this->text(self::MARGIN_LEFT + $plotwidth - 4, $y - 6, $reference['label'], 'end', '#2980b9');
            }
        }

        // Points. Semi-transparent, because overplotting is the norm with a
        // few hundred replications and solid dots would hide the density.
        foreach ($this->points as $point) {
            $svg .= '<circle cx="' . round($sx($point['x']), 2) . '" cy="' . round($sy($point['y']), 2)
                . '" r="3" fill="#2c3e50" fill-opacity="0.45"/>';
        }

        // Axis labels.
        $svg .= $this->text(
            self::MARGIN_LEFT + $plotwidth / 2,
            self::HEIGHT - 8,
            $this->xlabel,
            'middle'
        );
        $svg .= '<text x="14" y="' . (self::MARGIN_TOP + $plotheight / 2) . '" text-anchor="middle" '
            . 'font-size="12" fill="#333" transform="rotate(-90 14 '
            . (self::MARGIN_TOP + $plotheight / 2) . ')">' . s($this->ylabel) . '</text>';

        $svg .= '</svg>';

        return $svg;
    }

    /**
     * The plot with its accessible summary table underneath.
     *
     * @param array $summary Label => value pairs describing the plotted data.
     * @return string
     */
    public function render_with_summary(array $summary): string {
        $out = $this->render();

        if ($summary !== []) {
            $table = new \html_table();
            $table->attributes['class'] = 'generaltable table-sm w-auto mt-2';
            $table->head = [
                get_string('chart:quantity', 'local_catquizlab'),
                get_string('preview:value', 'local_catquizlab'),
            ];
            foreach ($summary as $label => $value) {
                $table->data[] = [$label, $value];
            }
            $out .= \html_writer::table($table);
        }

        return $out;
    }

    /**
     * The data bounds, padded so points do not sit on the axes.
     *
     * @return array{xmin: float, xmax: float, ymin: float, ymax: float}
     */
    protected function bounds(): array {
        $xs = array_column($this->points, 'x');
        $ys = array_column($this->points, 'y');

        $pad = static function (float $min, float $max): array {
            if ($min === $max) {
                // A single distinct value still needs a visible range.
                return [$min - 0.5, $max + 0.5];
            }
            $margin = ($max - $min) * 0.05;
            return [$min - $margin, $max + $margin];
        };

        [$xmin, $xmax] = $pad(min($xs), max($xs));
        [$ymin, $ymax] = $pad(min($ys), max($ys));

        // With an identity line the two axes must share a scale, or the line no
        // longer means "estimate equals truth".
        foreach ($this->references as $reference) {
            if ($reference['kind'] === 'identity') {
                $lo = min($xmin, $ymin);
                $hi = max($xmax, $ymax);
                return ['xmin' => $lo, 'xmax' => $hi, 'ymin' => $lo, 'ymax' => $hi];
            }
        }

        return ['xmin' => $xmin, 'xmax' => $xmax, 'ymin' => $ymin, 'ymax' => $ymax];
    }

    /**
     * Five evenly spaced tick values across a range.
     *
     * @param float $min The lower bound.
     * @param float $max The upper bound.
     * @return float[]
     */
    protected function ticks(float $min, float $max): array {
        $ticks = [];
        for ($i = 0; $i <= 4; $i++) {
            $ticks[] = $min + ($max - $min) * $i / 4;
        }

        return $ticks;
    }

    /**
     * An SVG line.
     *
     * @param float $x1 Start x.
     * @param float $y1 Start y.
     * @param float $x2 End x.
     * @param float $y2 End y.
     * @param string $colour Stroke colour.
     * @param float $width Stroke width.
     * @param string $dash Optional dash pattern.
     * @return string
     */
    protected function line(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        string $colour,
        float $width,
        string $dash = ''
    ): string {
        return '<line x1="' . round($x1, 2) . '" y1="' . round($y1, 2)
            . '" x2="' . round($x2, 2) . '" y2="' . round($y2, 2)
            . '" stroke="' . $colour . '" stroke-width="' . $width . '"'
            . ($dash !== '' ? ' stroke-dasharray="' . $dash . '"' : '') . '/>';
    }

    /**
     * An SVG text label.
     *
     * @param float $x The x position.
     * @param float $y The y position.
     * @param string $content The label.
     * @param string $anchor The text anchor.
     * @param string $colour The fill colour.
     * @return string
     */
    protected function text(float $x, float $y, string $content, string $anchor, string $colour = '#333'): string {
        return '<text x="' . round($x, 2) . '" y="' . round($y, 2) . '" text-anchor="' . $anchor
            . '" font-size="11" fill="' . $colour . '">' . s($content) . '</text>';
    }

    /**
     * Format a tick value compactly.
     *
     * @param float $value The value.
     * @return string
     */
    protected static function format(float $value): string {
        if (abs($value) >= 100) {
            return (string) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
