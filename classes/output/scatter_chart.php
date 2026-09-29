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

    /** @var string How the x axis is scaled: one of axis_scale's modes. */
    protected string $xmode = axis_scale::LINEAR;

    /** @var string How the y axis is scaled. */
    protected string $ymode = axis_scale::LINEAR;

    /** @var array Axis options: xatleast, yatleast, xfromzero, yfromzero, jitter. */
    protected array $axisoptions = [];

    /** @var array[] Several traces in one plot: label, group, points (#109). */
    protected array $series = [];

    /** @var array|null Fixed axis ranges: xmin, xmax, ymin, ymax, any of them. */
    protected ?array $fixed = null;

    /** @var string[] Colours by group, distinguishable in print as on screen. */
    protected const PALETTE = ['#1b9e77', '#d95f02', '#7570b3', '#e7298a', '#66a61e', '#e6ab02', '#a6761d', '#666666'];

    /**
     * Add one trace, drawn as a joined line (#109).
     *
     * Traces of the same group share a colour and one legend entry.
     *
     * @param string $label What the trace is (sitting, strategy, run …).
     * @param string $group What colours and names it in the legend.
     * @param array[] $points Its points in order; points without a value are skipped.
     * @return self
     */
    public function add_series(string $label, string $group, array $points): self {
        $clean = [];
        foreach ($points as $point) {
            if (isset($point['x'], $point['y']) && is_numeric($point['x']) && is_numeric($point['y'])) {
                $clean[] = ['x' => (float) $point['x'], 'y' => (float) $point['y']];
            }
        }
        $this->series[] = ['label' => $label, 'group' => $group, 'points' => $clean];

        return $this;
    }

    /**
     * Fix the axis ranges by hand; unset ones follow the data.
     *
     * @param array $fixed xmin, xmax, ymin, ymax — numbers or null.
     * @return self
     */
    public function set_fixed_bounds(array $fixed): self {
        $this->fixed = array_filter($fixed, static fn($v): bool => $v !== null && $v !== '' && is_numeric($v));

        return $this;
    }

    /** @var string What the plot is based on, shown beneath it (#105, section 9). */
    protected string $basis = '';

    /**
     * Say what the plot is based on: experiment, runs, strategy, model, n, range, filter.
     *
     * @param string $basis The text.
     * @return self
     */
    public function set_basis(string $basis): self {
        $this->basis = $basis;

        return $this;
    }

    /** @var bool Whether the points form a sequence to be joined by a line. */
    protected bool $connected = false;

    /** @var array[] An uncertainty band: x, lower and upper y per point. */
    protected array $band = [];

    /** @var string What the band shows, for its legend. */
    protected string $bandlabel = '';

    /**
     * Join the points by a line, in the order given: a trajectory, not a cloud.
     *
     * @param bool $connected Whether to.
     * @return self
     */
    public function set_connected(bool $connected = true): self {
        $this->connected = $connected;

        return $this;
    }

    /**
     * Shade a band around the points, such as estimate ± standard error.
     *
     * @param array[] $band Per point: x, lo, hi.
     * @param string $label What it shows.
     * @return self
     */
    public function add_band(array $band, string $label): self {
        $this->band = array_values(array_filter($band, static fn(array $b): bool =>
            isset($b['x'], $b['lo'], $b['hi']) && is_numeric($b['lo']) && is_numeric($b['hi'])));
        $this->bandlabel = $label;

        return $this;
    }

    /**
     * How the axes are scaled (#105).
     *
     * Logit quantities (ability, error, deviation) symmetric around 0; counts
     * (test length, step) as integers; anything else linear with round ticks.
     *
     * @param string $x Mode of the x axis.
     * @param string $y Mode of the y axis.
     * @param array $options xatleast / yatleast: a half-range a symmetric axis
     *     covers at least; xfromzero / yfromzero: include 0; jitter: a spread
     *     for points on an integer x axis, in units of that axis.
     * @return self
     */
    public function set_axes(string $x, string $y, array $options = []): self {
        $this->xmode = $x;
        $this->ymode = $y;
        $this->axisoptions = $options;

        return $this;
    }

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
                $this->points[] = [
                    'x' => (float) $point['x'],
                    'y' => (float) $point['y'],
                    // What the point is, as a tooltip (#105): person, scale, values.
                    'label' => (string) ($point['label'] ?? ''),
                ];
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
        $seriespoints = [];
        foreach ($this->series as $trace) {
            $seriespoints = array_merge($seriespoints, $trace['points']);
        }
        if ($this->points === [] && $seriespoints === []) {
            return \html_writer::div(
                get_string('chart:nodata', 'local_catquizlab'),
                'alert alert-info'
            );
        }

        $bounds = $this->bounds();
        $plotwidth = self::WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
        $plotheight = self::HEIGHT - self::MARGIN_TOP - self::MARGIN_BOTTOM;
        // With an identity line the plotting area is square: the same range on
        // both axes only makes y = x a 45° line if a unit is as long on one
        // axis as on the other (AXIS-003). 560 × 310 had it at about 29°.
        if ($this->has_identity()) {
            $plotwidth = $plotheight;
        }

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
        foreach ($bounds['xticks'] as $value) {
            $x = $sx($value);
            $svg .= $this->line($x, self::MARGIN_TOP + $plotheight, $x, self::MARGIN_TOP + $plotheight + 5, '#666', 1);
            $svg .= $this->text($x, self::MARGIN_TOP + $plotheight + 18, self::format($value), 'middle');
        }
        foreach ($bounds['yticks'] as $value) {
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
        // Several traces (#109): one colour per group, joined in order, and a
        // legend naming each group once.
        $groups = [];
        foreach ($this->series as $trace) {
            if (!isset($groups[$trace['group']])) {
                $groups[$trace['group']] = self::PALETTE[count($groups) % count(self::PALETTE)];
            }
            $colour = $groups[$trace['group']];
            $coords = [];
            foreach ($trace['points'] as $point) {
                $coords[] = round($sx($point['x']), 2) . ',' . round($sy($point['y']), 2);
            }
            if (count($coords) >= 2) {
                $svg .= '<polyline points="' . implode(' ', $coords) . '" fill="none" stroke="' . $colour
                    . '" stroke-width="1.6" stroke-opacity="0.85" data-region="series"><title>' . s($trace['label'])
                    . '</title></polyline>';
            }
            foreach ($trace['points'] as $point) {
                $svg .= '<circle cx="' . round($sx($point['x']), 2) . '" cy="' . round($sy($point['y']), 2)
                    . '" r="2.5" fill="' . $colour . '"><title>' . s($trace['label']) . '</title></circle>';
            }
        }
        $row = 0;
        foreach ($groups as $group => $colour) {
            $ly = self::MARGIN_TOP + 12 + 16 * $row++;
            $svg .= '<rect x="' . (self::MARGIN_LEFT + 10) . '" y="' . ($ly - 8) . '" width="12" height="3"'
                . ' fill="' . $colour . '"/>';
            $svg .= $this->text(self::MARGIN_LEFT + 27, $ly - 3, (string) $group, 'start', $colour);
        }

        // The band first, then the line, then the points on top.
        if (count($this->band) >= 2) {
            $upper = [];
            $lower = [];
            foreach ($this->band as $b) {
                $upper[] = round($sx((float) $b['x']), 2) . ',' . round($sy((float) $b['hi']), 2);
                $lower[] = round($sx((float) $b['x']), 2) . ',' . round($sy((float) $b['lo']), 2);
            }
            $svg .= '<polygon points="' . implode(' ', array_merge($upper, array_reverse($lower)))
                . '" fill="#2c7fb8" fill-opacity="0.15" stroke="none" data-region="band"><title>'
                . s($this->bandlabel) . '</title></polygon>';
        }
        if ($this->connected && count($this->points) >= 2) {
            $coords = [];
            foreach ($this->points as $point) {
                $coords[] = round($sx($point['x']), 2) . ',' . round($sy($point['y']), 2);
            }
            $svg .= '<polyline points="' . implode(' ', $coords) . '" fill="none" stroke="#2c3e50" stroke-width="1.5"'
                . ' stroke-opacity="0.8" data-region="trajectory"/>';
        }

        $jitter = (float) ($this->axisoptions['jitter'] ?? 0.0);
        foreach ($this->points as $index => $point) {
            // A small, reproducible spread on an integer axis, so that tests
            // of equal length do not hide behind each other; the value itself
            // is unchanged.
            $dx = $jitter > 0 ? ((crc32((string) $index) % 1000) / 999 - 0.5) * 2 * $jitter : 0.0;
            $title = $point['label'] !== '' ? '<title>' . s($point['label']) . '</title>' : '';
            $svg .= '<circle cx="' . round($sx($point['x'] + $dx), 2) . '" cy="' . round($sy($point['y']), 2)
                . '" r="3" fill="#2c3e50" fill-opacity="0.45">' . $title . '</circle>';
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

        if ($this->basis !== '') {
            $svg .= \html_writer::div(s($this->basis), 'small text-muted mt-1', ['data-region' => 'catquizlab-plot-basis']);
        }

        return $svg;
    }

    /**
     * The plot as a downloadable SVG and its data as CSV (#109, #108).
     *
     * @param string $basename File name without extension.
     * @param array[] $rows The data behind the plot, one array per row.
     * @return string Links, or '' when there is nothing drawn.
     */
    public function download_links(string $basename, array $rows): string {
        $svg = $this->render();
        if (strpos($svg, '<svg') === false) {
            return '';
        }
        $drawing = substr($svg, strpos($svg, '<svg'));
        $drawing = substr($drawing, 0, strpos($drawing, '</svg>') + 6);
        if (strpos($drawing, 'xmlns=') === false) {
            $drawing = str_replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', $drawing);
        }
        $csv = '';
        if ($rows !== []) {
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, array_keys(reset($rows)));
            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
            }
            rewind($handle);
            $csv = (string) stream_get_contents($handle);
            fclose($handle);
        }
        $name = clean_filename($basename);
        $links = \html_writer::link(
            'data:image/svg+xml;base64,' . base64_encode($drawing),
            get_string('chart:downloadsvg', 'local_catquizlab'),
            ['download' => $name . '.svg', 'class' => 'btn btn-sm btn-outline-secondary mr-2', 'data-download' => 'svg']
        );
        if ($csv !== '') {
            $links .= \html_writer::link(
                'data:text/csv;charset=utf-8;base64,' . base64_encode($csv),
                get_string('chart:downloadcsv', 'local_catquizlab'),
                ['download' => $name . '.csv', 'class' => 'btn btn-sm btn-outline-secondary', 'data-download' => 'csv']
            );
        }

        return \html_writer::div($links, 'mb-3');
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
     * Whether the chart has an identity line.
     *
     * @return bool
     */
    protected function has_identity(): bool {
        foreach ($this->references as $reference) {
            if ($reference['kind'] === 'identity') {
                return true;
            }
        }

        return false;
    }

    /**
     * The ranges and ticks of both axes (#105).
     *
     * @return array{xmin: float, xmax: float, ymin: float, ymax: float, xticks: float[], yticks: float[]}
     */
    protected function bounds(): array {
        $all = $this->points;
        foreach ($this->series as $trace) {
            $all = array_merge($all, $trace['points']);
        }
        $xs = array_map('floatval', array_column($all, 'x'));
        $ys = array_map('floatval', array_column($all, 'y'));
        foreach ($this->references as $reference) {
            if ($reference['kind'] === 'horizontal') {
                $ys[] = (float) $reference['value'];
            }
        }
        foreach ($this->band as $b) {
            $ys[] = (float) $b['lo'];
            $ys[] = (float) $b['hi'];
        }

        // A comparison plot: one symmetric range for both axes, so that the
        // identity line is the diagonal (AXIS-003).
        if ($this->has_identity()) {
            $atleast = max((float) ($this->axisoptions['xatleast'] ?? 0), (float) ($this->axisoptions['yatleast'] ?? 0));
            $axis = axis_scale::symmetric(array_merge($xs, $ys), $atleast);
            return ['xmin' => $axis['min'], 'xmax' => $axis['max'], 'ymin' => $axis['min'], 'ymax' => $axis['max'],
                'xticks' => $axis['ticks'], 'yticks' => $axis['ticks']];
        }

        $x = $this->axis($this->xmode, $xs, 'x');
        $y = $this->axis($this->ymode, $ys, 'y');

        // Ranges set by hand (#109): the same scale across compared plots.
        if ($this->fixed) {
            $fx = axis_scale::linear([(float) ($this->fixed['xmin'] ?? $x['min']), (float) ($this->fixed['xmax'] ?? $x['max'])]);
            $fy = axis_scale::linear([(float) ($this->fixed['ymin'] ?? $y['min']), (float) ($this->fixed['ymax'] ?? $y['max'])]);
            $x = isset($this->fixed['xmin']) || isset($this->fixed['xmax']) ? $fx : $x;
            $y = isset($this->fixed['ymin']) || isset($this->fixed['ymax']) ? $fy : $y;
        }

        return ['xmin' => $x['min'], 'xmax' => $x['max'], 'ymin' => $y['min'], 'ymax' => $y['max'],
            'xticks' => $x['ticks'], 'yticks' => $y['ticks']];
    }

    /**
     * One axis by its mode.
     *
     * @param string $mode One of axis_scale's modes.
     * @param float[] $values The values on this axis.
     * @param string $which 'x' or 'y', for the options.
     * @return array{min: float, max: float, ticks: float[]}
     */
    protected function axis(string $mode, array $values, string $which): array {
        $fromzero = !empty($this->axisoptions[$which . 'fromzero']);
        switch ($mode) {
            case axis_scale::SYMMETRIC:
                return axis_scale::symmetric($values, (float) ($this->axisoptions[$which . 'atleast'] ?? 0));
            case axis_scale::INTEGER:
                return axis_scale::integer($values, $fromzero);
            default:
                return axis_scale::linear($values, $fromzero);
        }
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
