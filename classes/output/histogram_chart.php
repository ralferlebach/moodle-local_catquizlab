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

/**
 * A histogram of simulated person parameters.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\output;

/**
 * How the simulated abilities are distributed, drawn (#109, section 4).
 *
 * Bins of a round width on an axis symmetric around 0, the configured ability
 * bounds as vertical lines — so a truncated distribution shows where it was cut,
 * and a normal one shows what fell outside.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class histogram_chart {
    /** @var int Drawing width. */
    protected const WIDTH = 640;

    /** @var int Drawing height. */
    protected const HEIGHT = 300;

    /** @var int Left margin. */
    protected const LEFT = 56;

    /** @var int Bottom margin. */
    protected const BOTTOM = 48;

    /** @var int Top margin. */
    protected const TOP = 16;

    /** @var int Right margin. */
    protected const RIGHT = 16;

    /** @var float[] The values. */
    protected array $values;

    /** @var float[] Vertical lines at these values (the bounds). */
    protected array $marks = [];

    /**
     * A histogram of these values.
     *
     * @param string $title The title.
     * @param string $xlabel The x label.
     * @param float[] $values The values.
     */
    public function __construct(
        /** @var string The title. */
        protected string $title,
        /** @var string The x label. */
        protected string $xlabel,
        array $values
    ) {
        $this->values = array_values(array_map('floatval', $values));
    }

    /**
     * Mark values on the axis, such as the ability bounds.
     *
     * @param float[] $marks The values.
     * @return self
     */
    public function set_marks(array $marks): self {
        $this->marks = array_values(array_map('floatval', $marks));

        return $this;
    }

    /**
     * Counts per bin.
     *
     * @return array{min: float, max: float, width: float, counts: int[], ticks: float[]}
     */
    public function bins(): array {
        $axis = axis_scale::symmetric(array_merge($this->values, $this->marks));
        $step = count($axis['ticks']) > 1 ? $axis['ticks'][1] - $axis['ticks'][0] : 1.0;
        // Two bins per tick interval, or four where there are many values.
        $width = $step / (count($this->values) > 500 ? 4 : 2);
        $n = max(1, (int) round(($axis['max'] - $axis['min']) / $width));
        $counts = array_fill(0, $n, 0);
        foreach ($this->values as $value) {
            $i = (int) floor(($value - $axis['min']) / $width);
            $counts[max(0, min($n - 1, $i))]++;
        }

        return ['min' => $axis['min'], 'max' => $axis['max'], 'width' => $width, 'counts' => $counts, 'ticks' => $axis['ticks']];
    }

    /**
     * The drawing.
     *
     * @return string
     */
    public function render(): string {
        if ($this->values === []) {
            return \html_writer::div(get_string('chart:nodata', 'local_catquizlab'), 'alert alert-info');
        }
        $bins = $this->bins();
        $plotw = self::WIDTH - self::LEFT - self::RIGHT;
        $ploth = self::HEIGHT - self::TOP - self::BOTTOM;
        $top = max(1, max($bins['counts']));
        $sx = static fn(float $x): float => self::LEFT + ($x - $bins['min']) / ($bins['max'] - $bins['min']) * $plotw;
        $sy = static fn(float $y): float => self::TOP + (1 - $y / $top) * $ploth;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT
            . '" role="img" aria-label="' . s($this->title) . '" style="max-width:100%;height:auto;">';
        foreach ($bins['counts'] as $i => $count) {
            if ($count === 0) {
                continue;
            }
            $x0 = $sx($bins['min'] + $i * $bins['width']);
            $x1 = $sx($bins['min'] + ($i + 1) * $bins['width']);
            $svg .= '<rect x="' . round($x0, 2) . '" y="' . round($sy($count), 2) . '" width="' . round(max(0.5, $x1 - $x0 - 1), 2)
                . '" height="' . round(self::TOP + $ploth - $sy($count), 2) . '" fill="#2c7fb8" fill-opacity="0.7"><title>'
                . $count . '</title></rect>';
        }
        $baseline = self::TOP + $ploth;
        $svg .= '<line x1="' . self::LEFT . '" y1="' . $baseline . '" x2="' . (self::LEFT + $plotw) . '" y2="' . $baseline
            . '" stroke="#666"/>';
        foreach ($bins['ticks'] as $tick) {
            $x = round($sx((float) $tick), 2);
            $svg .= '<line x1="' . $x . '" y1="' . $baseline . '" x2="' . $x . '" y2="' . ($baseline + 5) . '" stroke="#666"/>'
                . '<text x="' . $x . '" y="' . ($baseline + 18) . '" text-anchor="middle" font-size="11" fill="#333">'
                . rtrim(rtrim(number_format((float) $tick, 2, '.', ''), '0'), '.') . '</text>';
        }
        foreach ($this->marks as $mark) {
            $x = round($sx($mark), 2);
            $svg .= '<line x1="' . $x . '" y1="' . self::TOP . '" x2="' . $x . '" y2="' . $baseline
                . '" stroke="#c0392b" stroke-dasharray="5,4" data-region="bound"/>';
        }
        $svg .= '<text x="' . (self::LEFT + $plotw / 2) . '" y="' . (self::HEIGHT - 8) . '" text-anchor="middle" font-size="12"'
            . ' fill="#333">' . s($this->xlabel) . '</text>';
        $svg .= '<text x="' . (self::LEFT - 8) . '" y="' . (self::TOP + 10) . '" text-anchor="end" font-size="11" fill="#333">'
            . $top . '</text>';

        return $svg . '</svg>';
    }
}
