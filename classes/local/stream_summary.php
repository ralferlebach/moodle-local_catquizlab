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
 * Summary statistics of a group of sittings, gathered while streaming.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The overview's figures for one group, without holding its sittings.
 *
 * Means and correlations need only running sums. Medians and quartiles need
 * the values — so the values are kept, packed as eight-byte floats in a
 * string: fifty thousand of them are 400 kB, where fifty thousand observation
 * arrays were most of a gigabyte. The figures come out exactly as the
 * array-based computation gave them, because the quantiles are computed from
 * the same numbers by the same function.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stream_summary {
    /** @var string[] The fields whose distribution is reported. */
    public const FIELDS = ['nitems', 'se', 'error', 'runtimems'];

    /** @var array<string, string> Field => packed values. */
    protected array $values = [];

    /** @var int Sittings added. */
    protected int $n = 0;

    /** @var int Sittings whose stopping rule was met. */
    protected int $stopped = 0;

    /** @var array Running sums for ability recovery. */
    protected array $sums = ['n' => 0, 't' => 0.0, 'e' => 0.0, 'tt' => 0.0, 'ee' => 0.0, 'te' => 0.0,
        'err' => 0.0, 'errsq' => 0.0, 'abserr' => 0.0];

    /**
     * Add one sitting.
     *
     * @param array $row An observation, or any array with the same fields.
     * @return void
     */
    public function add(array $row): void {
        $this->n++;

        if (!empty($row['stopreached'])) {
            $this->stopped++;
        }

        foreach (self::FIELDS as $field) {
            if (isset($row[$field]) && is_numeric($row[$field])) {
                $this->values[$field] = ($this->values[$field] ?? '') . pack('e', (float) $row[$field]);
            }
        }

        // The same pairs metrics::ability_recovery() uses.
        $t = (float) ($row['truetheta'] ?? 0.0);
        $e = (float) ($row['esttheta'] ?? 0.0);
        $err = $e - $t;
        $this->sums['n']++;
        $this->sums['t'] += $t;
        $this->sums['e'] += $e;
        $this->sums['tt'] += $t * $t;
        $this->sums['ee'] += $e * $e;
        $this->sums['te'] += $t * $e;
        $this->sums['err'] += $err;
        $this->sums['errsq'] += $err * $err;
        $this->sums['abserr'] += abs($err);
    }

    /**
     * How many sittings were added.
     *
     * @return int
     */
    public function count(): int {
        return $this->n;
    }

    /**
     * The share of sittings that met their stopping rule, in percent.
     *
     * @return float
     */
    public function stop_rate(): float {
        return $this->n > 0 ? 100 * $this->stopped / $this->n : 0.0;
    }

    /**
     * The distribution of one field, as results_query::summarise() reports it.
     *
     * @param string $field One of FIELDS.
     * @return array
     */
    public function describe(string $field): array {
        $packed = $this->values[$field] ?? '';
        $values = $packed === '' ? [] : array_values(unpack('e*', $packed));

        return results_query::describe_values($values);
    }

    /**
     * Root mean squared error.
     *
     * @return float
     */
    public function rmse(): float {
        return $this->sums['n'] > 0 ? sqrt($this->sums['errsq'] / $this->sums['n']) : 0.0;
    }

    /**
     * Pearson correlation of true and estimated ability.
     *
     * @return float|null Null when either has no variance.
     */
    public function correlation(): ?float {
        $n = $this->sums['n'];
        if ($n < 2) {
            return null;
        }

        $cov = $this->sums['te'] - $this->sums['t'] * $this->sums['e'] / $n;
        $vt = $this->sums['tt'] - $this->sums['t'] ** 2 / $n;
        $ve = $this->sums['ee'] - $this->sums['e'] ** 2 / $n;

        if ($vt <= 0 || $ve <= 0) {
            return null;
        }

        return round($cov / sqrt($vt * $ve), 6);
    }
}
