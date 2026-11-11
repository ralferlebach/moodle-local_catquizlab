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
 * Request traces the size a load test leaves, written and removed again (#132).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Writes request trace lines as request_trace writes them, marked so that they can be told apart and taken out again.
 *
 * The logs page ran out of memory on a day file of a few hundred thousand lines (#132). An acceptance on the server
 * needs that size in the server's own trace files, and the server's own lines left as they were afterwards.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class trace_seed {
    /** @var int One line in this many is a server error with an exception. */
    public const ERROR_EVERY = 997;

    /**
     * Write $lines lines spread evenly over the $seconds before $until.
     *
     * @param int $lines How many.
     * @param int $seconds Over how long.
     * @param string $mark The correlation id of every line begins with this: how they are found again.
     * @param int[] $attemptids The sittings the lines belong to, in turn; none for lines of no sitting.
     * @param int|null $until The end of the span; now less a minute when not given.
     * @return array lines, errors (server errors written), errorsbyattempt, bytes, files, first, last (unix ms)
     */
    public static function write(int $lines, int $seconds, string $mark, array $attemptids = [], ?int $until = null): array {
        global $CFG;

        $dir = $CFG->dataroot . '/' . request_trace::DIRECTORY;
        if (!is_dir($dir) && !mkdir($dir, $CFG->directorypermissions ?? 02777, true) && !is_dir($dir)) {
            throw new \moodle_exception('error', 'error', '', null, 'Cannot create ' . $dir);
        }
        $attemptids = array_values(array_map('intval', $attemptids));
        $until = $until ?? time() - 60;
        $start = $until - max(1, $seconds);
        $handles = [];
        $out = ['lines' => 0, 'errors' => 0, 'bytes' => 0, 'files' => [], 'first' => null, 'last' => null];
        $exception = request_trace::describe(new \dml_write_exception('Deadlock found when trying to get lock', 'UPDATE', []));
        for ($i = 0; $i < $lines; $i++) {
            $started = $start + $seconds * $i / max(1, $lines);
            $error = $i % self::ERROR_EVERY === 0;
            $line = request_trace::record(
                ['correlationid' => $mark . ($i % 50), 'execution' => 1, 'method' => 'POST',
                    'attemptid' => $attemptids === [] ? 0 : $attemptids[$i % count($attemptids)],
                    'path' => '/mod/adaptivequiz/attempt.php?cmid=' . (1000 + $i % 7) . '&seed=' . $i, 'started' => $started],
                $error ? 500 : 200,
                $started + 0.2 + ($i % 13) / 10,
                0,
                (25 + $i % 40) * 1048576,
                $error ? $exception : null,
                null,
                ['queries' => 40 + $i % 60, 'rssmb' => 90.0 + $i % 50, 'hwmmb' => 140.0]
            );
            $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            $file = $dir . '/' . substr($line['time'], 0, 10) . '.jsonl';
            if (!isset($handles[$file])) {
                $new = !file_exists($file);
                $handles[$file] = fopen($file, 'ab');
                // A file this script begins must stay writable for the web server, which appends to it next.
                if ($new) {
                    @chmod($file, $CFG->filepermissions ?? 0666);
                }
                $out['files'][$file] = 0;
            }
            fwrite($handles[$file], $json);
            $out['files'][$file]++;
            $out['bytes'] += strlen($json);
            $out['lines']++;
            $out['errors'] += $error ? 1 : 0;
            $ms = (int) floor($started * 1000);
            $out['first'] = $out['first'] ?? $ms;
            $out['last'] = $ms;
        }
        array_map('fclose', $handles);

        return $out;
    }

    /**
     * Take every line whose correlation id begins with $mark out of the trace files again; the others stay as they were.
     *
     * Each file is written anew beside itself and renamed over it: a reader sees the old file or the new one, never half.
     * Lines the server writes while a file is rewritten could be lost, so this is for a quiet moment — after the test.
     *
     * @param string $mark The mark given to {@see write()}.
     * @return int Lines removed.
     */
    public static function remove(string $mark): int {
        global $CFG;

        $removed = 0;
        $needle = '"correlationid":"' . $mark;
        foreach (glob($CFG->dataroot . '/' . request_trace::DIRECTORY . '/*.jsonl') ?: [] as $file) {
            $in = fopen($file, 'rb');
            if ($in === false) {
                continue;
            }
            $tmp = $file . '.unseed';
            $out = fopen($tmp, 'wb');
            $here = 0;
            while (($raw = fgets($in)) !== false) {
                if (strpos($raw, $needle) !== false) {
                    $here++;
                    continue;
                }
                fwrite($out, $raw);
            }
            fclose($in);
            fclose($out);
            if ($here === 0) {
                unlink($tmp);
                continue;
            }
            $removed += $here;
            if (filesize($tmp) === 0) {
                unlink($tmp);
                unlink($file);
            } else {
                rename($tmp, $file);
            }
        }

        return $removed;
    }
}
