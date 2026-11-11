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
 * Existing server logs, looked through for what earlier versions may have written (#149).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Counts secret parameters in a log file by name and line, never by value, and can write a copy without them.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_scan {
    /** @var int How many line numbers a report lists per file. */
    public const MAX_LINENUMBERS = 200;

    /**
     * Look through one log file, plain or gzip.
     *
     * Only catquizlab's own lines — the fatal-error line and requests to /local/catquizlab/ — unless $all is set.
     *
     * @param string $file The file.
     * @param bool $all Every line, not only catquizlab's.
     * @param string $redactto A directory to write a copy without the values to; '' for none.
     * @return array file, lines, fatallines, fatallineswithquery, lineswithsecrets, names (name => count), firstline,
     *     lastline, linenumbers, redactedcopy, and error when the file could not be read.
     */
    public static function scan_file(string $file, bool $all = false, string $redactto = ''): array {
        $entry = ['file' => $file, 'lines' => 0, 'fatallines' => 0, 'fatallineswithquery' => 0, 'lineswithsecrets' => 0,
            'names' => [], 'firstline' => null, 'lastline' => null, 'linenumbers' => [], 'redactedcopy' => null];
        $gzip = (bool) preg_match('/\.gz$/', $file);
        $in = is_readable($file) ? ($gzip ? gzopen($file, 'rb') : fopen($file, 'rb')) : false;
        if (!$in) {
            $entry['error'] = 'not readable';
            return $entry;
        }
        $out = null;
        if ($redactto !== '') {
            check_dir_exists($redactto);
            $entry['redactedcopy'] = rtrim($redactto, '/') . '/' . basename($file) . '.redacted';
            $out = fopen($entry['redactedcopy'], 'wb');
        }
        $number = 0;
        while (($line = $gzip ? gzgets($in) : fgets($in)) !== false) {
            $number++;
            $own = strpos($line, 'local_catquizlab memory:') !== false;
            if ($own) {
                $entry['fatallines']++;
                $entry['fatallineswithquery'] += preg_match('/ url=\S*\?/', $line) ? 1 : 0;
            }
            $names = ($own || $all || strpos($line, '/local/catquizlab/') !== false) ? self::secret_names($line) : [];
            if ($names) {
                $entry['lineswithsecrets']++;
                foreach ($names as $name => $count) {
                    $entry['names'][$name] = ($entry['names'][$name] ?? 0) + $count;
                }
                $entry['firstline'] = $entry['firstline'] ?? $number;
                $entry['lastline'] = $number;
                if (count($entry['linenumbers']) < self::MAX_LINENUMBERS) {
                    $entry['linenumbers'][] = $number;
                }
            }
            if ($out) {
                // The old fatal line's url= loses its whole query, as the line now never has one.
                $clean = $own ? (string) preg_replace('/ url=(\S*?)\?\S*/', ' url=$1', $line) : $line;
                fwrite($out, $names ? log_redactor::redact_pairs($clean) : $clean);
            }
        }
        $entry['lines'] = $number;
        $gzip ? gzclose($in) : fclose($in);
        if ($out) {
            fclose($out);
        }
        ksort($entry['names']);

        return $entry;
    }

    /**
     * The secret parameters in one line: normalised name => how often; an e-mail address counts as "(e-mail address)".
     *
     * @param string $line The line.
     * @return array<string, int>
     */
    public static function secret_names(string $line): array {
        $names = [];
        preg_match_all(
            '/(?:^|[?&;\s,|(\["\'])([A-Za-z0-9_.%\[\]\-]{1,64})=([^&;?#\s"\'<>|,)]*)/',
            $line,
            $m,
            PREG_SET_ORDER
        );
        foreach ($m as $pair) {
            if ($pair[2] === '' || $pair[2] === log_redactor::REDACTED) {
                continue;
            }
            if (log_redactor::looks_like_email($pair[2])) {
                $names['(e-mail address)'] = ($names['(e-mail address)'] ?? 0) + 1;
            } else if (log_redactor::is_secret_name($pair[1])) {
                $name = log_redactor::normalise_name($pair[1]);
                $names[$name] = ($names[$name] ?? 0) + 1;
            }
        }

        return $names;
    }
}
