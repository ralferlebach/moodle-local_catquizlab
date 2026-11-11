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
 * What a page says when it runs out of memory or time.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Turns a fatal resource error into a message instead of a blank page.
 *
 * Memory exhaustion and the execution time limit are fatal errors: nothing can
 * catch them, and a page that hits one mid-output simply stops — the results
 * page stopped after its tabs, twice, on a live installation. Fatal errors do
 * still reach shutdown functions, and one of those can say what happened.
 *
 * Deliberately free of Moodle calls in {@see message_for()}: the words are
 * passed in, so the classification can be tested in a process that really runs
 * out of memory, where loading Moodle is not an option.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_guard {
    /** @var string The fatal error was memory. */
    public const MEMORY = 'memory';

    /** @var string The fatal error was time. */
    public const TIME = 'time';

    /**
     * @var string The system had no memory to give, whatever PHP's limit was (#147): "Out of memory (allocated N)". Not
     *     PHP's limit, and not to be reported as one.
     */
    public const SYSTEM = 'system';

    /** @var string[] The phases of a results page, in order (#147). */
    public const PHASES = ['start', 'filter', 'basis', 'tab', 'export', 'done'];

    /** @var string[] The results page's tabs: the only values the log line takes for "tab" (#149). */
    public const TABS = ['overview', 'global', 'subscales', 'deficits', 'robustness', 'testflow', 'rawdata', 'export'];

    /**
     * @var array<string, string> What the page may add to the log line, and of which type (#149): int, tab or
     *     size ("12 MB"). Nothing else reaches the line — not the query string, not a filter value, not a name.
     */
    public const CONTEXT_FIELDS = [
        'experimentid' => 'int', 'runid' => 'int', 'replication' => 'int', 'tab' => 'tab', 'sittings' => 'int',
        'traces' => 'size', 'largestbatch' => 'size', 'read' => 'size',
    ];

    /** @var string What the request was doing last, by {@see phase()}. */
    protected static string $phase = 'start';

    /** @var array<string, int> Peak memory in bytes at the end of each phase passed. */
    protected static array $peaks = [];

    /** @var string The memory limit when the guard was registered, before anything raised it. */
    protected static string $limit = '';

    /** @var callable|null What else to report with a memory exhaustion: returns name => value. */
    protected static $context = null;

    /** @var array<string, array{peak: int, ms: int, queries: int}> Figures at the end of each phase and step (#147). */
    protected static array $marks = [];

    /** @var array{0: float, 1: int} When and after how many queries the current phase began. */
    protected static array $since = [0.0, 0];

    /** @var array{0: float, 1: int} When and after how many queries the current step began. */
    protected static array $stepsince = [0.0, 0];

    /** @var string The step within the phase, by {@see step()}; '' for none. */
    protected static string $step = '';

    /** @var string memory_limit when the guard was registered: after raise_memory_limit(). */
    protected static string $raised = '';

    /** @var int The output buffer level the page's own output starts at; -1 when not buffered. */
    protected static int $bufferlevel = -1;

    /** @var int Most phases and steps kept for the log line and the profile. */
    public const MAX_MARKS = 48;

    /**
     * Say what the request is doing now (#147): a page that runs out of memory says where.
     *
     * @param string $phase One of {@see PHASES}.
     * @return void
     */
    public static function phase(string $phase): void {
        self::$peaks[self::$phase] = memory_get_peak_usage();
        if (self::$step !== '') {
            self::mark(self::$phase . ':' . self::$step, self::$stepsince);
        }
        self::mark(self::$phase, self::$since);
        self::$stepsince = self::$since;
        self::$phase = in_array($phase, self::PHASES, true) ? $phase : 'other';
        self::$step = '';
    }

    /**
     * Say which part of a phase the request is in now (#147, AK3): the analysis basis and each tab are several
     * steps, and a page that runs out of memory says in which.
     *
     * @param string $step A short name: letters, digits and underscores.
     * @return void
     */
    public static function step(string $step): void {
        $step = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($step));
        if (self::$step !== '') {
            self::mark(self::$phase . ':' . self::$step, self::$stepsince);
        } else {
            // What the phase did before its first step.
            self::mark(self::$phase . ':-', self::$stepsince);
        }
        self::$step = substr($step, 0, 32);
    }

    /**
     * Note what the part now ending took — its peak, its time and its queries — and start the next from now.
     *
     * @param string $name The phase or "phase:step".
     * @param array $since When and after how many queries the part began; set to now.
     * @return void
     */
    protected static function mark(string $name, array &$since): void {
        [$started, $queries] = $since;
        $now = microtime(true);
        $count = self::queries();
        if ($started > 0.0 && count(self::$marks) < self::MAX_MARKS && !isset(self::$marks[$name])) {
            self::$marks[$name] = [
                'peak' => memory_get_peak_usage(),
                'ms' => (int) round(1000 * ($now - $started)),
                'queries' => max(0, $count - $queries),
            ];
        }
        $since = [$now, $count];
    }

    /**
     * Database queries so far in this request, where Moodle's database is there to ask.
     *
     * @return int
     */
    protected static function queries(): int {
        $db = $GLOBALS['DB'] ?? null;

        return is_object($db) && method_exists($db, 'perf_get_queries') ? (int) $db->perf_get_queries() : 0;
    }

    /**
     * What each phase and step took so far: name => peak bytes, milliseconds, queries (#147, AK3).
     *
     * @return array<string, array{peak: int, ms: int, queries: int}>
     */
    public static function marks(): array {
        return self::$marks;
    }

    /**
     * Discard what the page has written since {@see buffer()} and not sent yet — at a fatal error, so that the
     * message stands in a well-formed page rather than inside half a table or half a plot (#147, AK8).
     *
     * @return void
     */
    protected static function discard_partial_output(): void {
        if (self::$bufferlevel < 0) {
            return;
        }
        while (ob_get_level() > self::$bufferlevel) {
            ob_end_clean();
        }
        self::$bufferlevel = -1;
    }

    /**
     * Hold the page's analysis output until it is complete (#147, AK8): at a fatal error it is discarded, and the
     * message takes its place. Released by {@see release()}.
     *
     * @return void
     */
    public static function buffer(): void {
        self::$bufferlevel = ob_get_level();
        ob_start();
    }

    /**
     * Send what {@see buffer()} held: the analysis completed.
     *
     * @return void
     */
    public static function release(): void {
        if (self::$bufferlevel < 0) {
            return;
        }
        while (ob_get_level() > self::$bufferlevel) {
            ob_end_flush();
        }
        self::$bufferlevel = -1;
    }

    /**
     * Forget the phases, peaks and context of this request — for tests.
     *
     * @return void
     */
    public static function reset(): void {
        self::$phase = 'start';
        self::$peaks = [];
        self::$context = null;
        self::$marks = [];
        self::$since = [0.0, 0];
        self::$stepsince = [0.0, 0];
        self::$step = '';
        self::$raised = '';
        self::$bufferlevel = -1;
    }

    /**
     * Which resource a fatal error is about, if any.
     *
     * @param array|null $error What error_get_last() returned.
     * @return string|null MEMORY, SYSTEM, TIME, or null for anything else.
     */
    public static function classify(?array $error): ?string {
        if (!$error || !in_array((int) ($error['type'] ?? 0), [E_ERROR, E_CORE_ERROR], true)) {
            return null;
        }

        $message = (string) ($error['message'] ?? '');
        // PHP's limit, in two wordings: "Allowed memory size of N bytes exhausted"; "Hard memory limit of N bytes
        // exhausted" (PHP 8.3, when the limit is lowered below what is in use).
        if (preg_match('/Allowed memory size|memory limit of \d+ bytes exhausted/i', $message)) {
            return self::MEMORY;
        }
        // The system's: PHP asked for memory within its limit, or without one, and got none (#147, AK2).
        if (stripos($message, 'Out of memory') !== false) {
            return self::SYSTEM;
        }
        if (stripos($message, 'Maximum execution time') !== false) {
            return self::TIME;
        }

        return null;
    }

    /**
     * The memory limit a fatal error ran into, in bytes, from its own message; null when it does not say.
     *
     * Not ini_get(): by the time the shutdown functions run, Moodle's own shutdown handler has raised the limit to
     * unlimited so that it can finish, and the page said "limit -1" (#147).
     *
     * @param string $message The error message.
     * @return int|null
     */
    public static function limit_from(string $message): ?int {
        if (preg_match('/(?:Allowed memory size|memory limit) of (\d+) bytes/i', $message, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Bytes as a reader says them: "512 MB".
     *
     * @param int $bytes Bytes.
     * @return string
     */
    public static function megabytes(int $bytes): string {
        return (string) round($bytes / 1048576) . ' MB';
    }

    /**
     * The message for a fatal error, or nothing.
     *
     * @param array|null $error What error_get_last() returned.
     * @param array $texts MEMORY and TIME => text with "{limit}" in it; the memory text may also carry "{peak}"
     *     and "{phase}", 'phases' may give each phase its words, and 'requestid' the line with "{rid}" (#149).
     * @return string|null HTML, or null when the error is not a resource limit.
     */
    public static function message_for(?array $error, array $texts): ?string {
        $kind = self::classify($error);
        if ($kind === null || !isset($texts[$kind])) {
            return null;
        }

        if ($kind === self::MEMORY) {
            $bytes = self::limit_from((string) ($error['message'] ?? ''));
            $limit = $bytes !== null ? self::megabytes($bytes) : self::configured_limit();
        } else if ($kind === self::SYSTEM) {
            // PHP's limit is not what was reached: said as what it is, unlimited included.
            $limit = self::configured_limit();
            $limit = $limit === '' ? 'unlimited (-1)' : $limit;
        } else {
            $limit = (string) ini_get('max_execution_time');
        }
        $phases = (array) ($texts['phases'] ?? []);
        $text = strtr($texts[$kind], [
            '{limit}' => htmlspecialchars($limit, ENT_QUOTES),
            '{peak}'  => htmlspecialchars(self::megabytes(memory_get_peak_usage()), ENT_QUOTES),
            '{phase}' => htmlspecialchars((string) ($phases[self::$phase] ?? self::$phase), ENT_QUOTES),
        ]);

        // The id the server's log line carries (#149): a screenshot of this message finds its line.
        $rid = htmlspecialchars(log_redactor::request_id(), ENT_QUOTES);
        if (isset($texts['requestid'])) {
            $text .= ' <small class="d-block mt-1" data-region="catquizlab-request-id">'
                . strtr((string) $texts['requestid'], ['{rid}' => $rid]) . '</small>';
        }

        // Said to a screen reader at once (AK10), with the step it stopped in for whoever reports it.
        return '<div class="alert alert-danger mt-3" role="alert" data-region="catquizlab-resource-limit" '
            . 'data-step="' . htmlspecialchars(self::$step, ENT_QUOTES) . '" data-kind="' . $kind
            . '" data-phase="' . htmlspecialchars(self::$phase, ENT_QUOTES) . '" data-requestid="' . $rid . '">'
            . $text . '</div>';
    }

    /**
     * The limit as configured when the guard was registered, as a reader says it; '' if unknown.
     *
     * @return string
     */
    protected static function configured_limit(): string {
        $limit = self::$limit !== '' ? self::$limit : (string) ini_get('memory_limit');
        if ($limit === '-1' || $limit === '') {
            return '';
        }
        $bytes = function_exists('get_real_size') ? (int) get_real_size($limit) : (int) $limit;

        return $bytes > 0 ? self::megabytes($bytes) : $limit;
    }

    /**
     * One line for the server's error log, so that an administrator sees where and with what a page ran out.
     *
     * Only typed, allowed fields (#149): the script without its query, the phase, the memory figures, the request id
     * and what {@see CONTEXT_FIELDS} allows. The request's query string never reaches it — it may carry a session key,
     * a token or a personal filter value — and every value is one printable token of bounded length, so that nothing
     * sent with the request can start a line of its own in the log.
     *
     * @param array|null $error What error_get_last() returned.
     * @param array|null $server The request's server variables; $_SERVER when null.
     * @return string|null The line, or null when the error is not a memory exhaustion.
     */
    public static function log_line(?array $error, ?array $server = null): ?string {
        $kind = self::classify($error);
        if ($kind === null) {
            return null;
        }
        $server = $server ?? $_SERVER;
        $message = (string) ($error['message'] ?? '');
        $context = [];
        if (self::$context !== null) {
            try {
                $context = (array) (self::$context)();
            } catch (\Throwable $e) {
                $context = [];
            }
        }
        $peaks = [];
        foreach (self::$peaks as $phase => $bytes) {
            $peaks[] = $phase . '=' . self::megabytes($bytes);
        }
        $marks = [];
        foreach (self::$marks as $name => $mark) {
            $marks[] = $name . '=' . self::megabytes($mark['peak']) . '/' . $mark['ms'] . 'ms/' . $mark['queries'] . 'q';
        }
        $started = (float) ($server['REQUEST_TIME_FLOAT'] ?? 0);
        $tried = preg_match('/tried to allocate (\d+) bytes/i', $message, $m) ? self::megabytes((int) $m[1]) : '';
        $fields = [
            'rid' => log_redactor::request_id($server),
            'ridsource' => log_redactor::request_id_source(),
            // What happened, as PHP said it, without the message's free text (AK1, AK2).
            'kind' => $kind,
            'phase' => self::$phase,
            'step' => self::$step,
            'peak' => self::megabytes(memory_get_peak_usage()),
            'limit' => ($bytes = self::limit_from($message)) !== null
                ? self::megabytes($bytes) : self::configured_limit(),
            'phases' => implode(',', $peaks),
            'path' => log_redactor::script_path($server),
        ] + self::typed_context($context) + [
            'tried' => $tried,
            'current' => self::megabytes(memory_get_usage()),
            'limitini' => self::ini_limit((string) get_cfg_var('memory_limit')),
            'limitraised' => self::ini_limit(self::$raised !== '' ? self::$raised : self::$limit),
            'file' => self::short_file((string) ($error['file'] ?? '')),
            'line' => (int) ($error['line'] ?? 0),
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'maxexec' => (string) ini_get('max_execution_time') . 's',
            'runtime' => $started > 0 ? (int) round(1000 * (microtime(true) - $started)) . 'ms' : '',
            'queries' => self::queries(),
        ] + self::process_memory() + ['steps' => implode(',', $marks)];
        $line = 'local_catquizlab ' . ($kind === self::TIME ? 'time' : 'memory') . ':';
        foreach ($fields as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            // The step list is the longest field: up to MAX_MARKS entries, each a few dozen bytes.
            $line .= ' ' . $name . '=' . log_redactor::field(
                str_replace(' ', '', (string) $value),
                in_array($name, ['steps', 'phases'], true) ? 2000 : log_redactor::FIELD_MAX
            );
        }

        return $line;
    }

    /**
     * What the page added, reduced to {@see CONTEXT_FIELDS}: each field in its type, everything else dropped and
     * counted.
     *
     * @param array $context Name => value, as the page's callback returned it.
     * @return array<string, string|int>
     */
    public static function typed_context(array $context): array {
        $typed = [];
        $dropped = 0;
        foreach ($context as $name => $value) {
            $type = self::CONTEXT_FIELDS[$name] ?? null;
            $ok = is_scalar($value);
            if ($ok && $type === 'int') {
                $ok = is_int($value) || (is_string($value) && preg_match('/^\d{1,12}\z/', $value));
                $value = (int) $value;
            } else if ($ok && $type === 'tab') {
                $ok = in_array($value, self::TABS, true);
            } else if ($ok && $type === 'size') {
                $ok = (bool) preg_match('/^\d{1,9} ?MB\z/', (string) $value);
            } else {
                $ok = false;
            }
            if ($ok) {
                $typed[$name] = $value;
            } else {
                $dropped++;
            }
        }
        if ($dropped > 0) {
            $typed['dropped'] = $dropped;
        }

        return $typed;
    }

    /**
     * A memory_limit setting as a reader says it: "512MB", "-1", '' when unknown.
     *
     * @param string $setting The setting.
     * @return string
     */
    protected static function ini_limit(string $setting): string {
        if ($setting === '' || $setting === '-1') {
            return $setting;
        }
        $bytes = (int) $setting;
        if (preg_match('/^\s*(\d+)\s*([kmg])\s*$/i', $setting, $m)) {
            $bytes = (int) $m[1] * ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2])];
        }

        return $bytes > 0 ? self::megabytes($bytes) : $setting;
    }

    /**
     * Where a fatal error happened, without the server's directory layout: the path from the plugin or Moodle's root.
     *
     * @param string $file The file as PHP reported it.
     * @return string
     */
    protected static function short_file(string $file): string {
        if ($file === '') {
            return '';
        }
        $root = isset($GLOBALS['CFG']->dirroot) ? (string) $GLOBALS['CFG']->dirroot : '';
        if ($root !== '' && strpos($file, $root) === 0) {
            return ltrim(substr($file, strlen($root)), '/');
        }
        $parts = explode('/', str_replace('\\', '/', $file));

        return implode('/', array_slice($parts, -3));
    }

    /**
     * A small system file, or false where there is none — without a warning: at shutdown a warning would take the
     * place of the fatal error in error_get_last(), for this guard and for every handler after it.
     *
     * @param string $path The file.
     * @return string|false
     */
    protected static function read_small(string $path) {
        return is_file($path) && is_readable($path) ? file_get_contents($path, false, null, 0, 65536) : false;
    }

    /**
     * The process's memory as the system sees it (AK1): resident now and at its highest, and the memory cgroup's limit,
     * use and OOM kills where the system says (Linux). Nothing where it does not.
     *
     * @return array<string, string|int>
     */
    public static function process_memory(): array {
        $out = [];
        $status = self::read_small('/proc/self/status');
        if (is_string($status)) {
            foreach (['rss' => 'VmRSS', 'hwm' => 'VmHWM'] as $name => $key) {
                if (preg_match('/^' . $key . ':\s+(\d+) kB/m', $status, $m)) {
                    $out[$name] = self::megabytes(1024 * (int) $m[1]);
                }
            }
        }
        // Control group v2, then v1.
        $max = self::read_small('/sys/fs/cgroup/memory.max');
        $current = self::read_small('/sys/fs/cgroup/memory.current');
        $events = self::read_small('/sys/fs/cgroup/memory.events');
        if ($max === false) {
            $max = self::read_small('/sys/fs/cgroup/memory/memory.limit_in_bytes');
            $current = self::read_small('/sys/fs/cgroup/memory/memory.usage_in_bytes');
            $events = self::read_small('/sys/fs/cgroup/memory/memory.oom_control');
        }
        if (is_string($max) && trim($max) !== '') {
            $max = trim($max);
            $out['cgmax'] = preg_match('/^\d{1,18}$/', $max) ? self::megabytes((int) $max) : 'max';
        }
        if (is_string($current) && preg_match('/^\d{1,18}$/', trim($current))) {
            $out['cgcurrent'] = self::megabytes((int) trim($current));
        }
        if (is_string($events) && preg_match('/^oom_kill\s+(\d+)/m', $events, $m)) {
            $out['cgoomkills'] = (int) $m[1];
        }

        return $out;
    }

    /**
     * What the page adds to the log line: a callback returning name => value, reduced to {@see CONTEXT_FIELDS}.
     *
     * @param callable|null $context The callback, or null for none.
     * @return void
     */
    public static function context(?callable $context): void {
        self::$context = $context;
    }

    /**
     * Register the guard for the rest of this request.
     *
     * The texts are resolved now, while there is memory to resolve them; at
     * shutdown after an exhaustion there is only the small reserve PHP keeps.
     *
     * @param string $component The component whose strings to use.
     * @param callable|null $context What to add to the error log line: returns name => value.
     * @return void
     */
    public static function register(string $component = 'local_catquizlab', ?callable $context = null): void {
        self::$limit = (string) ini_get('memory_limit');
        self::$raised = self::$limit;
        self::$since = [microtime(true), self::queries()];
        self::$stepsince = self::$since;
        self::context($context);
        $phases = [];
        foreach (self::PHASES as $phase) {
            $phases[$phase] = get_string('results:phase' . $phase, $component);
        }
        $texts = [
            self::MEMORY => get_string('results:outofmemory', $component, (object) [
                'limit' => '{limit}', 'peak' => '{peak}', 'phase' => '{phase}',
            ]),
            self::TIME   => get_string('results:outoftime', $component, '{limit}'),
            self::SYSTEM => get_string('results:outofsystemmemory', $component, (object) [
                'limit' => '{limit}', 'peak' => '{peak}', 'phase' => '{phase}',
            ]),
            'phases'     => $phases,
            'requestid'  => get_string('results:requestid', $component, '{rid}'),
        ];

        \core_shutdown_manager::register_function(static function () use ($texts): void {
            self::at_shutdown($texts);
        });
    }

    /**
     * What the guard does at shutdown: after a resource error, discard the half-written analysis, write the log line
     * and show the message. Public so that a process without Moodle can register it (the tests' probes).
     *
     * @param array $texts The resolved texts, as {@see register()} resolves them.
     * @return void
     */
    public static function at_shutdown(array $texts): void {
        $error = error_get_last();
        if (self::classify($error) !== null) {
            // Half a table or half a plot goes; the message takes its place (AK8).
            self::discard_partial_output();
        }
        try {
            $line = self::log_line($error);
        } catch (\Throwable $e) {
            // The message on the page matters more than a complete line: never let the line cost the message.
            $line = 'local_catquizlab memory: kind=' . (string) self::classify($error) . ' logfailed=1';
        }
        if ($line !== null) {
            // The server's log on purpose, not debugging(): an administrator needs this line whatever the
            // debugging level, and after an exhaustion there is no page left to show it on.
            // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
            error_log($line);
        }
        $html = self::message_for($error, $texts);
        if ($html !== null) {
            echo $html;
        }
    }
}
