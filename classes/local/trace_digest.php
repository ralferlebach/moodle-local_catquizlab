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
 * The part of a trace the results read (#147).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * A trace without the step-by-step records that only the test flow, the scale states and the transfer package read.
 *
 * A trace of a long sitting is mostly two records of every step: the engine's progress of each question played and the
 * ability path — more than nine tenths of 125 KB at 600 questions, of a megabyte with seventy scales. The results
 * page, its tabs and exports read neither: they read the end of the sitting, its items and responses. The digest is
 * the trace without those two records, written beside it when the trace is written, so that every pass over the
 * results reads a tenth of the bytes. What is dropped is listed, not what is kept: a key that a reader starts to
 * use later is in the digest unless it is one of these, and {@see \local_catquizlab\trace_digest_test} checks that
 * every observation is the same from the digest as from the trace.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class trace_digest {
    /** @var string[] Top-level records only the test flow and the transfer package read. */
    public const DROP = ['abilitypath'];

    /** @var string[] Records of the engine's progress that only the test flow and the scale states read. */
    public const DROP_PROGRESS = ['playedquestions', 'scalestatetrace', 'abilitytrace'];

    /**
     * @var bool Read whole traces instead of digests, as before 0.7.57 — only to show that both give the same
     *     results (tests, cli/compare_digests.php).
     */
    public static bool $wholetraces = false;

    /**
     * The digest of a decoded trace.
     *
     * @param array $trace The trace.
     * @return array
     */
    public static function of(array $trace): array {
        foreach (self::DROP as $key) {
            unset($trace[$key]);
        }
        if (isset($trace['progress']) && is_array($trace['progress'])) {
            foreach (self::DROP_PROGRESS as $key) {
                unset($trace['progress'][$key]);
            }
        }

        return $trace;
    }

    /**
     * The columns to write beside a trace: its digest and the digest's size; both null without a readable trace.
     *
     * @param string|null $json The trace as stored.
     * @return array{digestjson: string|null, digestbytes: int|null}
     */
    public static function fields(?string $json): array {
        $trace = $json === null || $json === '' ? null : json_decode($json, true);
        if (!is_array($trace)) {
            return ['digestjson' => null, 'digestbytes' => null];
        }
        $digest = (string) json_encode(self::of($trace), JSON_UNESCAPED_SLASHES);

        return ['digestjson' => $digest, 'digestbytes' => strlen($digest)];
    }

    /**
     * The decoded digest of a row read with {@see trace_reader::digest_fields()}: the stored one, or the one of the
     * trace where the row has none yet.
     *
     * @param \stdClass $row The row.
     * @return array
     */
    public static function decode(\stdClass $row): array {
        if (self::$wholetraces) {
            $trace = json_decode((string) ($row->tracejson ?? ''), true);
            if (!is_array($trace) && isset($row->id)) {
                global $DB;
                $trace = json_decode((string) $DB->get_field('local_catquizlab_attempt', 'tracejson', ['id' => $row->id]), true);
            }
            return is_array($trace) ? $trace : [];
        }
        if (isset($row->digestjson) && $row->digestjson !== null && $row->digestjson !== '') {
            $digest = json_decode((string) $row->digestjson, true);
            if (is_array($digest)) {
                return $digest;
            }
        }
        $trace = json_decode((string) ($row->tracejson ?? ''), true);

        return is_array($trace) ? self::of($trace) : [];
    }

    /**
     * Write the digest of every sitting that has a trace and none yet, at most $limit of them; for the task that
     * fills in sittings collected before 0.7.57.
     *
     * @param int $limit Sittings at most.
     * @param int $seconds Seconds at most.
     * @return int Sittings written.
     */
    public static function fill(int $limit = 5000, int $seconds = 60): int {
        global $DB;

        $done = 0;
        $until = microtime(true) + $seconds;
        $batches = trace_reader::batches('a.tracejson IS NOT NULL AND a.digestjson IS NULL', [], 'a.id, a.tracejson');
        foreach ($batches as $batch) {
            foreach ($batch as $row) {
                $DB->update_record('local_catquizlab_attempt', (object) (['id' => (int) $row->id]
                    + self::fields((string) $row->tracejson)));
                $row->tracejson = null;
                $done++;
            }
            if ($done >= $limit || microtime(true) > $until) {
                break;
            }
        }

        return $done;
    }

    /**
     * How many sittings have a trace and no digest yet.
     *
     * @return int
     */
    public static function missing(): int {
        global $DB;

        return $DB->count_records_select('local_catquizlab_attempt', 'tracejson IS NOT NULL AND digestjson IS NULL');
    }
}
