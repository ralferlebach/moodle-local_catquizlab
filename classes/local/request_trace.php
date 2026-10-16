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
 * What became of each request the worker made, as the server saw it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * One line per worker request: status, duration, connection, and the exception behind an error (#110).
 *
 * The web server's access log knows a request's status and, configured for it,
 * its duration and whether the client went away. It does not know why Moodle
 * answered 500: an exception Moodle catches and turns into an error page is
 * logged nowhere unless debugging is on. Six hundred such answers in two
 * experiments left no trace but their status.
 *
 * So the plugin keeps its own line for every request that carries the worker's
 * correlation header — the same as the access log's, and what Moodle saw. In a
 * file, not the database: when a request fails, the database is the first
 * suspect, and the line must not depend on it.
 *
 * Requests without the header — every real user's — are not touched.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_trace {
    /** @var string The header the worker sends with every request of a sitting. */
    public const HEADER_CORRELATION = 'X-CatQuizLab-Correlation';

    /** @var string The header naming the sitting and its execution: "attemptid.execution". */
    public const HEADER_ATTEMPT = 'X-CatQuizLab-Attempt';

    /** @var string The directory under the data root the lines are written to, one file per day. */
    public const DIRECTORY = 'catquizlab/requests';

    /** @var int[] PHP errors that end a request. */
    protected const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    /** @var bool Whether this request is being traced. */
    protected static bool $active = false;

    /** @var array The request as it began: correlation, sitting, method, path. */
    protected static array $request = [];

    /** @var array|null The exception Moodle turned into an error page, if any. */
    protected static ?array $exception = null;

    /**
     * Begin tracing, if the request is the worker's: the after_config hook.
     *
     * @param \core\hook\after_config $hook The hook.
     * @return void
     */
    public static function after_config(\core\hook\after_config $hook): void {
        self::begin($_SERVER);
    }

    /**
     * Begin tracing a request described by these server variables.
     *
     * @param array $server The request's server variables.
     * @return bool Whether it is traced.
     */
    public static function begin(array $server): bool {
        if (self::$active || (defined('CLI_SCRIPT') && CLI_SCRIPT && !(defined('PHPUNIT_TEST') && PHPUNIT_TEST))) {
            return false;
        }
        $correlation = self::header($server, self::HEADER_CORRELATION);
        if ($correlation === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $correlation)) {
            return false;
        }
        $attempt = self::header($server, self::HEADER_ATTEMPT);
        [$attemptid, $execution] = array_map('intval', explode('.', $attempt . '.0')) + [0, 0];

        self::$active = true;
        self::$exception = null;
        self::$request = [
            'correlationid' => $correlation,
            'attemptid' => $attemptid,
            'execution' => $execution,
            'method' => (string) ($server['REQUEST_METHOD'] ?? ''),
            'path' => self::redact_uri((string) ($server['REQUEST_URI'] ?? '')),
            'started' => (float) ($server['REQUEST_TIME_FLOAT'] ?? microtime(true)),
        ];
        // Every entry this request leaves carries the worker's id (#107, #110).
        debug_trace::continue_correlation($correlation);

        // The exception behind an error page: noted, then handed on unchanged
        // to the handler Moodle installed, which renders the page as before.
        $previous = set_exception_handler(null);
        set_exception_handler(static function (\Throwable $e) use ($previous): void {
            self::$exception = self::describe($e);
            if (is_callable($previous)) {
                $previous($e);
            } else {
                throw $e;
            }
        });
        register_shutdown_function([self::class, 'finish']);

        return true;
    }

    /**
     * Note an exception Moodle handled without its exception handler — a web service's, for instance.
     *
     * @param \Throwable $e The exception.
     * @return void
     */
    public static function note_exception(\Throwable $e): void {
        if (self::$active) {
            self::$exception = self::describe($e);
        }
    }

    /**
     * Write the request's line: at shutdown, after Moodle has answered.
     *
     * @return void
     */
    public static function finish(): void {
        if (!self::$active) {
            return;
        }
        self::$active = false;
        try {
            $line = self::record(
                self::$request,
                (int) (http_response_code() ?: 200),
                microtime(true),
                connection_status(),
                memory_get_peak_usage(true),
                self::$exception,
                error_get_last()
            );
            self::write($line);
        } catch (\Throwable $e) {
            // A line that cannot be written must not become a second failure.
            $e = null;
        }
    }

    /**
     * The line for a request — kept apart from finish() so that it can be tested.
     *
     * @param array $request The request as it began.
     * @param int $status The HTTP status sent.
     * @param float $now The time it ended.
     * @param int $connection connection_status(): 0 normal, 1 aborted by the client, 2 timed out.
     * @param int $peakbytes Peak memory.
     * @param array|null $exception The exception behind an error page.
     * @param array|null $lasterror error_get_last().
     * @return array
     */
    public static function record(
        array $request,
        int $status,
        float $now,
        int $connection,
        int $peakbytes,
        ?array $exception,
        ?array $lasterror
    ): array {
        $fatal = null;
        if ($lasterror !== null && in_array((int) ($lasterror['type'] ?? 0), self::FATAL, true)) {
            $fatal = [
                'message' => self::redact_text((string) $lasterror['message']),
                'file' => (string) ($lasterror['file'] ?? ''),
                'line' => (int) ($lasterror['line'] ?? 0),
            ];
        }
        // As the access log's %X: what became of the connection.
        $states = [];
        if ($connection & CONNECTION_ABORTED) {
            $states[] = 'aborted';
        }
        if ($connection & CONNECTION_TIMEOUT) {
            $states[] = 'timeout';
        }

        return [
            'time' => gmdate('Y-m-d\TH:i:s', (int) $request['started'])
                . sprintf('.%03dZ', (int) (fmod($request['started'], 1) * 1000)),
            'correlationid' => (string) $request['correlationid'],
            'attemptid' => (int) $request['attemptid'],
            'execution' => (int) $request['execution'],
            'method' => (string) $request['method'],
            'path' => (string) $request['path'],
            'status' => $status,
            'durationms' => (int) round(1000 * max(0.0, $now - (float) $request['started'])),
            'connection' => $states === [] ? 'normal' : implode('+', $states),
            'peakmb' => round($peakbytes / 1048576, 1),
            'pid' => (int) getmypid(),
            'exception' => $exception,
            'fatal' => $fatal,
        ];
    }

    /**
     * Describe an exception: class, message, Moodle's error code and debug info, where it was thrown.
     *
     * @param \Throwable $e The exception.
     * @return array
     */
    public static function describe(\Throwable $e): array {
        global $CFG;

        $root = isset($CFG->dirroot) ? rtrim((string) $CFG->dirroot, '/') . '/' : '';
        $strip = static fn(string $path): string =>
            $root !== '' && strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
        $out = [
            'class' => get_class($e),
            'message' => self::redact_text($e->getMessage()),
            'errorcode' => $e instanceof \moodle_exception ? (string) $e->errorcode : '',
            'debuginfo' => $e instanceof \moodle_exception ? self::redact_text((string) ($e->debuginfo ?? '')) : '',
            'file' => $strip($e->getFile()),
            'line' => $e->getLine(),
        ];
        // The first frames: where in Moodle, the engine or the plugin it happened.
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 8) as $frame) {
            $frames[] = (isset($frame['file']) ? $strip($frame['file']) . ':' . ($frame['line'] ?? 0) : '?')
                . ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
        }
        $out['trace'] = $frames;
        if ($e->getPrevious() !== null) {
            $out['previous'] = get_class($e->getPrevious()) . ': ' . self::redact_text($e->getPrevious()->getMessage());
        }

        return $out;
    }

    /**
     * Append a line to the day's file.
     *
     * @param array $line The line.
     * @return void
     */
    public static function write(array $line): void {
        global $CFG;

        $dir = $CFG->dataroot . '/' . self::DIRECTORY;
        if (!is_dir($dir) && !@mkdir($dir, $CFG->directorypermissions ?? 02777, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . '/' . substr((string) $line['time'], 0, 10) . '.jsonl';
        @file_put_contents(
            $file,
            json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /** @var int The most lines read() hands back: the newest, the rest stays on disk (#132). */
    public const READ_LIMIT = 5000;

    /**
     * The lines of the days from $from to $until, oldest first — at most the newest $limit of them.
     *
     * Bounded (#132): a day of a load test is several hundred thousand lines,
     * and holding them all as arrays ran PHP out of memory on the logs page.
     * Whoever needs more walks each() and keeps what it needs.
     *
     * @param int $from Unix time.
     * @param int $until Unix time.
     * @param int $limit The most lines to keep, newest; 0 for READ_LIMIT.
     * @return array[]
     */
    public static function read(int $from, int $until, int $limit = 0): array {
        $limit = $limit > 0 ? $limit : self::READ_LIMIT;
        $kept = [];
        foreach (self::each($from, $until) as $line) {
            $kept[] = $line;
            // Trimmed in batches by position in the file (the order the requests
            // ended in), with a margin of $limit for a long request that began
            // before shorter ones it ended after.
            if (count($kept) >= 4 * $limit) {
                $kept = array_slice($kept, -2 * $limit);
            }
        }
        usort($kept, static fn(array $a, array $b): int => $a['unixms'] <=> $b['unixms']);

        return array_slice($kept, -$limit);
    }

    /** @var int How many bytes of trace files one view reads at most, from the newest back (#132). */
    public const SCAN_BYTES = 256 * 1048576;

    /**
     * Which parts of which day files a read of $from to $until covers, newest first within the byte budget (#132).
     *
     * The budget is spent from the end: the newest day file first, back to
     * the oldest. A file that does not fit any more is read from an offset —
     * its newest part — and older files are skipped. What was left out is
     * said, not hidden: 'skippedbytes' is the part of the window not read.
     *
     * @param int $from Unix time.
     * @param int $until Unix time.
     * @param int $maxbytes The budget; 0 for no limit.
     * @return array files (list of [path, offset], oldest first), bytes (to read), skippedbytes (left out).
     */
    public static function scan_plan(int $from, int $until, int $maxbytes = 0): array {
        global $CFG;

        $plan = ['files' => [], 'bytes' => 0, 'skippedbytes' => 0];
        $dir = $CFG->dataroot . '/' . self::DIRECTORY;
        if (!is_dir($dir)) {
            return $plan;
        }
        $days = [];
        for ($day = strtotime(gmdate('Y-m-d', $from) . ' 00:00:00 UTC'); $day <= $until; $day += 86400) {
            $file = $dir . '/' . gmdate('Y-m-d', $day) . '.jsonl';
            if (is_readable($file)) {
                $days[] = $file;
            }
        }
        $budget = $maxbytes > 0 ? $maxbytes : PHP_INT_MAX;
        foreach (array_reverse($days) as $file) {
            $size = (int) @filesize($file);
            if ($budget <= 0) {
                $plan['skippedbytes'] += $size;
                continue;
            }
            $offset = max(0, $size - $budget);
            $plan['skippedbytes'] += $offset;
            $plan['bytes'] += $size - $offset;
            $budget -= $size - $offset;
            array_unshift($plan['files'], [$file, $offset]);
        }

        return $plan;
    }

    /**
     * The lines of the days from $from to $until, one at a time, in the order they were written.
     *
     * Streamed (#132): one line in memory at a time, and a line outside the
     * window is passed over on its time stamp without being decoded — the
     * time is the first field of every line record() writes.
     *
     * @param int $from Unix time.
     * @param int $until Unix time.
     * @param array|null $plan A scan_plan() for the same window; the whole window when not given.
     * @return \Generator<array> Each line, with 'unixms' added.
     */
    public static function each(int $from, int $until, ?array $plan = null): \Generator {
        $plan ??= self::scan_plan($from, $until);
        $lower = $from * 1000;
        $upper = ($until + 1) * 1000;
        foreach ($plan['files'] as [$file, $offset]) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                if ($offset > 0) {
                    fseek($handle, $offset);
                    // The rest of a line cut in two by the offset.
                    fgets($handle);
                }
                while (($raw = fgets($handle)) !== false) {
                    $ms = self::stamp_ms($raw);
                    if ($ms !== null && ($ms < $lower || $ms > $upper)) {
                        continue;
                    }
                    $line = json_decode($raw, true);
                    if (!is_array($line) || !isset($line['time'])) {
                        continue;
                    }
                    $line['unixms'] = $ms ?? self::time_ms((string) $line['time']);
                    if ($line['unixms'] < $lower || $line['unixms'] > $upper) {
                        continue;
                    }
                    yield $line;
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * The time of a raw line from its leading field, without decoding it.
     *
     * @param string $raw A line as written: {"time":"2026-10-12T08:15:00.123Z",...
     * @return int|null Milliseconds, or null when the line does not begin that way.
     */
    protected static function stamp_ms(string $raw): ?int {
        if (strncmp($raw, '{"time":"', 9) !== 0 || strlen($raw) < 33 || $raw[32] !== 'Z') {
            return null;
        }

        return self::time_ms(substr($raw, 9, 24));
    }

    /**
     * Milliseconds since the epoch of a time as record() writes it.
     *
     * @param string $time 2026-10-12T08:15:00.123Z
     * @return int
     */
    protected static function time_ms(string $time): int {
        // Consecutive lines mostly share their second; strtotime() was most of
        // the time a large day file took to read.
        static $second = '';
        static $unix = 0;
        $prefix = substr($time, 0, 19);
        if ($prefix !== $second) {
            $second = $prefix;
            $unix = (int) strtotime($prefix . ' UTC');
        }

        return $unix * 1000 + (int) substr($time, 20, 3);
    }

    /**
     * Remove the files older than so many days.
     *
     * @param int $days Days to keep.
     * @return int Files removed.
     */
    public static function purge_older_than(int $days): int {
        global $CFG;

        $dir = $CFG->dataroot . '/' . self::DIRECTORY;
        $removed = 0;
        $limit = gmdate('Y-m-d', time() - max(1, $days) * 86400);
        foreach (glob($dir . '/*.jsonl') ?: [] as $file) {
            if (basename($file, '.jsonl') < $limit && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * A header's value from the server variables.
     *
     * @param array $server The server variables.
     * @param string $name The header.
     * @return string
     */
    protected static function header(array $server, string $name): string {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return trim((string) ($server[$key] ?? ''));
    }

    /**
     * A request URI without the secrets it may carry.
     *
     * @param string $uri The URI.
     * @return string
     */
    public static function redact_uri(string $uri): string {
        return (string) preg_replace('/((?:^|[?&])(?:sesskey|wstoken|token|password|key)=)[^&#]*/i', '$1[redacted]', $uri);
    }

    /**
     * Text without the secrets it may carry.
     *
     * @param string $text The text.
     * @return string
     */
    protected static function redact_text(string $text): string {
        return attempt_history::redact($text);
    }

    /**
     * Forget the request — for tests.
     *
     * @return void
     */
    public static function reset(): void {
        self::$active = false;
        self::$request = [];
        self::$exception = null;
    }
}
