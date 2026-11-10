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

namespace local_catquizlab\local;

/**
 * Reads stored traces in batches bounded by rows and by bytes (#147, #110).
 *
 * A trace is the copy of one sitting's engine record: forty kilobytes for twenty questions, megabytes for a sitting
 * of several hundred questions with many subscales. Batches of a fixed number of sittings were therefore of any size:
 * five hundred long sittings came to more than the 512 MB of a web request. On MySQL and MariaDB this is worse than
 * it looks, because Moodle's driver buffers every result in PHP's own memory (MYSQLI_STORE_RESULT) — a recordset
 * over a run is the whole run in memory, before the first row is read. PostgreSQL buffers outside PHP's limit, which
 * is why the development site never showed it.
 *
 * Each batch here is at most {@see ROWS} sittings and at most {@see BYTES} bytes of trace; a single trace larger
 * than that is a batch of its own. The sizes come from local_catquizlab_attempt.tracebytes, written with the trace,
 * or are measured where an older row has none.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class trace_reader {
    /** @var int Sittings looked at per query. */
    public const ROWS = 500;

    /** @var int Bytes of trace read per query. */
    public const BYTES = 16777216;

    /** @var int|null A smaller byte budget, for tests; null in production. */
    public static ?int $budget = null;

    /** @var int The largest batch read in this request, in bytes of trace. */
    protected static int $largest = 0;

    /** @var int Bytes of trace read in this request. */
    protected static int $read = 0;

    /**
     * The size of a trace in SQL: the stored size, or the measured one where an older row has none.
     *
     * @param string $alias The table alias of local_catquizlab_attempt, '' for none.
     * @return string
     */
    public static function bytes_sql(string $alias = 'a'): string {
        global $DB;

        $prefix = $alias === '' ? '' : $alias . '.';

        return 'CASE WHEN ' . $prefix . 'tracebytes IS NULL THEN ' . $DB->sql_length($prefix . 'tracejson')
            . ' ELSE ' . $prefix . 'tracebytes END';
    }

    /**
     * The sittings a condition selects, in id order, batch by batch.
     *
     * @param string $where A condition on local_catquizlab_attempt, aliased "a"; named parameters only.
     * @param array $params Its parameters.
     * @param string $fields What to read, aliased: "a.id" first.
     * @param string $join Joins for the fields (applied when the rows are read, not when they are chosen).
     * @return \Generator<array<int, \stdClass>> Batches keyed by sitting id.
     */
    public static function batches(string $where, array $params, string $fields, string $join = ''): \Generator {
        global $DB;

        $lastid = 0;
        do {
            $sizes = $DB->get_records_sql_menu(
                'SELECT a.id, ' . self::bytes_sql('a') . ' AS bytes
                   FROM {local_catquizlab_attempt} a
                  WHERE (' . $where . ') AND a.id > :tracereaderlastid
               ORDER BY a.id ASC',
                $params + ['tracereaderlastid' => $lastid],
                0,
                self::ROWS
            );
            foreach (self::chunks($sizes, self::$budget ?? self::BYTES) as $ids) {
                // A group is a run of consecutive ids of the selection: read as a range under the same condition,
                // which the database answers from the primary key like the batches before (a list of five hundred
                // ids made the PostgreSQL pass more than twice as slow).
                $rows = $DB->get_records_sql(
                    'SELECT ' . $fields . ' FROM {local_catquizlab_attempt} a ' . $join
                    . ' WHERE (' . $where . ') AND a.id >= :tracereaderfirst AND a.id <= :tracereaderlast'
                    . ' ORDER BY a.id ASC',
                    $params + ['tracereaderfirst' => reset($ids), 'tracereaderlast' => end($ids)]
                );
                $bytes = 0;
                foreach ($ids as $id) {
                    $bytes += (int) $sizes[$id];
                }
                self::$largest = max(self::$largest, $bytes);
                self::$read += $bytes;
                yield $rows;
            }
            if ($sizes !== []) {
                $lastid = (int) array_key_last($sizes);
            }
        } while (count($sizes) === self::ROWS);
    }

    /**
     * Split sittings into consecutive groups of at most a budget of bytes each, one oversized sitting alone.
     *
     * @param array $sizes Sitting id => bytes, in id order.
     * @param int $budget Bytes a group may hold.
     * @return int[][] Groups of ids.
     */
    public static function chunks(array $sizes, int $budget): array {
        $groups = [];
        $group = [];
        $bytes = 0;
        foreach ($sizes as $id => $size) {
            $size = max(0, (int) $size);
            if ($group !== [] && $bytes + $size > $budget) {
                $groups[] = $group;
                $group = [];
                $bytes = 0;
            }
            $group[] = (int) $id;
            $bytes += $size;
        }
        if ($group !== []) {
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * What this request has read: the largest batch and the total, in bytes of trace.
     *
     * @return array{largest: int, read: int}
     */
    public static function figures(): array {
        return ['largest' => self::$largest, 'read' => self::$read];
    }

    /**
     * Forget the figures (tests).
     *
     * @return void
     */
    public static function reset(): void {
        self::$largest = 0;
        self::$read = 0;
        self::$budget = null;
    }
}
