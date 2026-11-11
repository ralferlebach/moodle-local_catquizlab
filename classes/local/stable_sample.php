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
 * A sample of at most k rows that is the same on every reading (#147).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Bottom-k sampling: every row gets a key from a hash of its sitting, and the k rows with the smallest keys are kept.
 *
 * As even a chance for every sitting as the reservoir it replaces, but the same sitting is drawn on every reading of
 * the same selection, in whatever order the rows come: a plot looks the same each time the page is opened, and the
 * figures behind it can be compared before and after a change. The reservoir drew with random_int() and gave
 * another sample on every reading.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stable_sample {
    /** @var \SplPriorityQueue Kept rows, the largest key on top. */
    protected \SplPriorityQueue $heap;

    /** @var int Rows offered. */
    protected int $seen = 0;

    /**
     * A sample of at most $size rows, drawn under $salt (one sample of the same sittings is independent of another).
     *
     * @param int $size Rows at most.
     * @param string $salt What tells this sample from another of the same sittings.
     */
    public function __construct(
        /** @var int Rows at most. */
        protected int $size,
        /** @var string What tells this sample from another of the same sittings. */
        protected string $salt
    ) {
        $this->heap = new \SplPriorityQueue();
        $this->heap->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * Offer a row.
     *
     * @param string $id What identifies the row: its sitting, and its scale where a sitting has several rows.
     * @param array $row The row.
     * @return void
     */
    public function offer(string $id, array $row): void {
        $this->seen++;
        // Two hashes, so that ties are as rare as in sixty-four bits.
        $key = [crc32($this->salt . ':' . $id), crc32($id . ':' . $this->salt), $id];
        if ($this->heap->count() < $this->size) {
            $this->heap->insert($row, $key);
            return;
        }
        $top = $this->heap->top();
        if ($key < $top['priority']) {
            $this->heap->extract();
            $this->heap->insert($row, $key);
        }
    }

    /**
     * The sample, in the order of its keys.
     *
     * @return array[]
     */
    public function rows(): array {
        $entries = [];
        foreach (clone $this->heap as $entry) {
            $entries[] = $entry;
        }
        usort($entries, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return array_map(static fn(array $entry): array => $entry['data'], $entries);
    }

    /**
     * Rows offered.
     *
     * @return int
     */
    public function seen(): int {
        return $this->seen;
    }
}
