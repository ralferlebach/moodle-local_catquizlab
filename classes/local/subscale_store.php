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
 * The subscale results of a selection, held as packed numbers (#147).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * What the subscales tab reads of every subscale result, at fifty bytes a result instead of a few kilobytes.
 *
 * Nine thousand long sittings of twenty subscales are a hundred and eighty thousand subscale results. As arrays of
 * thirty fields each they were more than a request's memory; the tab reads eleven of the fields. Held here packed —
 * four floats, two integers, the flags and the four labels as numbers into a list of their values — and handed out one
 * row at a time, the same row as before for what the tab reads.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class subscale_store implements \Countable {
    /** @var string The packing of one row. */
    protected const FORMAT = 'dtruedelta/destdelta/derror/dlocalse/lpersonid/litems/Cflags/nsubscale/ncategory/nkey/nitemclass';

    /** @var string The same, for pack(). */
    protected const PACK = 'ddddllCnnnn';

    /** @var int Bytes of one row. */
    protected const SIZE = 49;

    /** @var string The rows. */
    protected string $data = '';

    /** @var int Rows held. */
    protected int $count = 0;

    /** @var array<string, array<string, int>> Label => number, per label field. */
    protected array $codes = ['subscale' => [], 'category' => [], 'key' => [], 'itemclass' => []];

    /** @var array<string, string[]> Number => label, per label field. */
    protected array $labels = ['subscale' => [], 'category' => [], 'key' => [], 'itemclass' => []];

    /**
     * Keep a subscale result.
     *
     * @param array $row A row of {@see results_query::each_subscale_observation()}.
     * @return void
     */
    public function add(array $row): void {
        $flags = ($row['localse'] === null ? 1 : 0) | (!empty($row['within1se']) ? 2 : 0) | (!empty($row['within2se']) ? 4 : 0)
            | (($row['items'] ?? null) === null ? 8 : 0) | (isset($row['itemclass']) ? 16 : 0);
        $this->data .= pack(
            self::PACK,
            (float) $row['truedelta'],
            (float) $row['estdelta'],
            (float) $row['error'],
            $row['localse'] === null ? 0.0 : (float) $row['localse'],
            (int) ($row['personid'] ?? 0),
            (int) ($row['items'] ?? 0),
            $flags,
            $this->code('subscale', (string) ($row['subscale'] ?? '')),
            $this->code('category', (string) ($row['category'] ?? '')),
            $this->code('key', (string) ($row['key'] ?? '')),
            $this->code('itemclass', (string) ($row['itemclass'] ?? ''))
        );
        $this->count++;
    }

    /**
     * The number of a label, given on first sight.
     *
     * @param string $field The label field.
     * @param string $value The label.
     * @return int
     */
    protected function code(string $field, string $value): int {
        if (!isset($this->codes[$field][$value])) {
            $this->codes[$field][$value] = count($this->labels[$field]);
            $this->labels[$field][] = $value;
        }

        return $this->codes[$field][$value];
    }

    /**
     * The rows, one at a time, in the order kept: what the tab reads of each, as it was.
     *
     * @param string|null $field Only the rows whose label field ...
     * @param string|null $value ... has this label.
     * @return \Generator<array>
     */
    public function rows(?string $field = null, ?string $value = null): \Generator {
        $want = $field === null ? null : ($this->codes[$field][(string) $value] ?? -1);
        for ($i = 0; $i < $this->count; $i++) {
            $r = unpack(self::FORMAT, $this->data, $i * self::SIZE);
            if ($want !== null && $r[$field] !== $want) {
                continue;
            }
            $row = [
                'truedelta' => $r['truedelta'],
                'estdelta' => $r['estdelta'],
                'error' => $r['error'],
                'localse' => $r['flags'] & 1 ? null : $r['localse'],
                'within1se' => (bool) ($r['flags'] & 2),
                'within2se' => (bool) ($r['flags'] & 4),
                'personid' => $r['personid'],
                'items' => $r['flags'] & 8 ? null : $r['items'],
                'subscale' => $this->labels['subscale'][$r['subscale']],
                'category' => $this->labels['category'][$r['category']],
                'key' => $this->labels['key'][$r['key']],
            ];
            if ($r['flags'] & 16) {
                $row['itemclass'] = $this->labels['itemclass'][$r['itemclass']];
            }
            yield $i => $row;
        }
    }

    /**
     * The labels a field takes, in the order first seen.
     *
     * @param string $field The label field.
     * @return string[]
     */
    public function labels(string $field): array {
        return $this->labels[$field];
    }

    /**
     * Rows held.
     *
     * @return int
     */
    public function count(): int {
        return $this->count;
    }
}
