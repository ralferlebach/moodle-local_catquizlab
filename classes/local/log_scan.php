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

    /** @var int How many hours a report lists per category and file. */
    public const MAX_HOURS = 2000;

    /** @var string A session: sesskey, the session cookie, a session id. */
    public const CATEGORY_SESSION = 'session';

    /** @var string An access credential that is not a password: tokens, keys, signatures, codes. */
    public const CATEGORY_TOKEN = 'token or key';

    /** @var string A password. */
    public const CATEGORY_PASSWORD = 'password';

    /** @var string Personal data: an e-mail address. */
    public const CATEGORY_EMAIL = 'e-mail address';

    /** @var string Any other secret name the redactor knows. */
    public const CATEGORY_OTHER = 'other secret';

    /**
     * Look through one log file, plain or gzip.
     *
     * Only catquizlab's own lines — the fatal-error line and requests to /local/catquizlab/ — unless $all is set.
     *
     * @param string $file The file.
     * @param bool $all Every line, not only catquizlab's.
     * @param string $redactto A directory to write a copy without the values to; '' for none.
     * @return array file, lines, fatallines, fatallineswithquery, lineswithsecrets, names (name => count), categories
     *     (category => lines), windows (category => first, last, hours: 'YYYY-MM-DD HH:00 UTC' => lines), untimed
     *     (lines with a secret but no time the scan could read), firstline, lastline, linenumbers, redactedcopy, and
     *     error when the file could not be read. Never a value.
     */
    public static function scan_file(string $file, bool $all = false, string $redactto = ''): array {
        $entry = ['file' => $file, 'lines' => 0, 'fatallines' => 0, 'fatallineswithquery' => 0, 'lineswithsecrets' => 0,
            'names' => [], 'categories' => [], 'windows' => [], 'untimed' => 0, 'firstline' => null, 'lastline' => null,
            'linenumbers' => [], 'redactedcopy' => null];
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
                $time = self::line_time($line);
                $entry['untimed'] += $time === null ? 1 : 0;
                $categories = [];
                foreach (array_keys($names) as $name) {
                    $categories[self::category((string) $name)] = true;
                }
                foreach (array_keys($categories) as $category) {
                    $entry['categories'][$category] = ($entry['categories'][$category] ?? 0) + 1;
                    if ($time !== null) {
                        $window = $entry['windows'][$category] ?? ['first' => $time, 'last' => $time, 'hours' => []];
                        $window['first'] = min($window['first'], $time);
                        $window['last'] = max($window['last'], $time);
                        $hour = gmdate('Y-m-d H:00', $time) . ' UTC';
                        if (isset($window['hours'][$hour]) || count($window['hours']) < self::MAX_HOURS) {
                            $window['hours'][$hour] = ($window['hours'][$hour] ?? 0) + 1;
                        }
                        $entry['windows'][$category] = $window;
                    }
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
        ksort($entry['categories']);
        ksort($entry['windows']);
        foreach ($entry['windows'] as $category => $window) {
            ksort($window['hours']);
            $window['first'] = gmdate('Y-m-d\\TH:i:s\\Z', $window['first']);
            $window['last'] = gmdate('Y-m-d\\TH:i:s\\Z', $window['last']);
            $entry['windows'][$category] = $window;
        }

        return $entry;
    }

    /**
     * The log files under a directory, rotated and gzip ones included, sorted: *.log, *.log.N, *.gz, *_log, *.txt.
     *
     * @param string $dir The directory.
     * @return string[]
     */
    public static function files_in(string $dir): array {
        $files = [];
        if (!is_dir($dir)) {
            return [$dir];
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $dir,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
        ));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/(\\.log(\\.\\d+)?|\\.gz|_log|\\.txt)$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The category of a secret name as secret_names() returns it.
     *
     * @param string $name A normalised name, or "(e-mail address)".
     * @return string One of the CATEGORY_ constants.
     */
    public static function category(string $name): string {
        if ($name === '(e-mail address)' || strpos($name, 'mail') !== false) {
            return self::CATEGORY_EMAIL;
        }
        if (preg_match('/sesskey|moodlesession|sessionid|^sid$|cookie/', $name)) {
            return self::CATEGORY_SESSION;
        }
        if (preg_match('/pass|^pw$|^pwd$/', $name)) {
            return self::CATEGORY_PASSWORD;
        }
        if (preg_match('/token|key|secret|signature|credential|auth|code|otp|pin/', $name)) {
            return self::CATEGORY_TOKEN;
        }

        return self::CATEGORY_OTHER;
    }

    /**
     * When a log line was written, as far as its format says: Apache error log ("[Wed Nov 05 10:00:00.123456 2026]"),
     * Apache/Nginx access log ("[05/Nov/2026:10:00:00 +0100]"), PHP error log ("[05-Nov-2026 10:00:00 UTC]"), syslog
     * with a year-less date is not read (no year), ISO 8601 ("2026-11-05T10:00:00Z", "2026-11-05 10:00:00").
     *
     * @param string $line The line.
     * @return int|null Unix time, or null when the line has no time the scan can read.
     */
    public static function line_time(string $line): ?int {
        $patterns = [
            // Apache error log; fractions of a second are dropped.
            '/\\[(?:[A-Z][a-z]{2} )?([A-Z][a-z]{2}) (\\d{1,2}) (\\d{2}:\\d{2}:\\d{2})(?:\\.\\d+)? (\\d{4})\\]/' => 'error',
            // Access log.
            '/\\[(\\d{2})\\/([A-Z][a-z]{2})\\/(\\d{4}):(\\d{2}:\\d{2}:\\d{2}) ([+-]\\d{4})\\]/' => 'access',
            // PHP error log.
            '/\\[(\\d{2})-([A-Z][a-z]{2})-(\\d{4}) (\\d{2}:\\d{2}:\\d{2})(?: ([A-Za-z_\\/+\\-0-9]+))?\\]/' => 'php',
            // ISO 8601.
            '/(\\d{4}-\\d{2}-\\d{2})[T ](\\d{2}:\\d{2}:\\d{2})(?:\\.\\d+)?(Z|[+-]\\d{2}:?\\d{2})?/' => 'iso',
        ];
        foreach ($patterns as $pattern => $kind) {
            if (!preg_match($pattern, $line, $m)) {
                continue;
            }
            $text = match ($kind) {
                'error' => "{$m[2]} {$m[1]} {$m[4]} {$m[3]} UTC",
                'access' => "{$m[1]} {$m[2]} {$m[3]} {$m[4]} {$m[5]}",
                'php' => "{$m[1]} {$m[2]} {$m[3]} {$m[4]} " . (($m[5] ?? '') !== '' ? $m[5] : 'UTC'),
                'iso' => "{$m[1]} {$m[2]} " . (($m[3] ?? '') !== '' ? $m[3] : 'UTC'),
            };
            try {
                return (new \DateTimeImmutable($text))->getTimestamp();
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
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
