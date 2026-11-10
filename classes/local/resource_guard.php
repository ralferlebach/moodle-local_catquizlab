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

    /** @var string[] The phases of a results page, in order (#147). */
    public const PHASES = ['start', 'filter', 'basis', 'tab', 'export'];

    /** @var string What the request was doing last, by {@see phase()}. */
    protected static string $phase = 'start';

    /** @var array<string, int> Peak memory in bytes at the end of each phase passed. */
    protected static array $peaks = [];

    /** @var string The memory limit when the guard was registered, before anything raised it. */
    protected static string $limit = '';

    /** @var callable|null What else to report with a memory exhaustion: returns name => value. */
    protected static $context = null;

    /**
     * Say what the request is doing now (#147): a page that runs out of memory says where.
     *
     * @param string $phase One of {@see PHASES}.
     * @return void
     */
    public static function phase(string $phase): void {
        self::$peaks[self::$phase] = memory_get_peak_usage();
        self::$phase = $phase;
    }

    /**
     * Which resource a fatal error is about, if any.
     *
     * @param array|null $error What error_get_last() returned.
     * @return string|null MEMORY, TIME, or null for anything else.
     */
    public static function classify(?array $error): ?string {
        if (!$error || !in_array((int) ($error['type'] ?? 0), [E_ERROR, E_CORE_ERROR], true)) {
            return null;
        }

        $message = (string) ($error['message'] ?? '');
        // Three wordings: "Allowed memory size of N bytes exhausted"; "Hard memory limit of N bytes exhausted"
        // (PHP 8.3, when the limit is lowered below what is in use); "Out of memory (allocated N)" (the system).
        if (preg_match('/Allowed memory size|memory limit of \d+ bytes exhausted|Out of memory/i', $message)) {
            return self::MEMORY;
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
     *     and "{phase}", and PHASES may give each phase its words.
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
        } else {
            $limit = (string) ini_get('max_execution_time');
        }
        $phases = (array) ($texts['phases'] ?? []);
        $text = strtr($texts[$kind], [
            '{limit}' => htmlspecialchars($limit, ENT_QUOTES),
            '{peak}'  => htmlspecialchars(self::megabytes(memory_get_peak_usage()), ENT_QUOTES),
            '{phase}' => htmlspecialchars((string) ($phases[self::$phase] ?? self::$phase), ENT_QUOTES),
        ]);

        return '<div class="alert alert-danger mt-3" data-region="catquizlab-resource-limit" data-kind="' . $kind
            . '" data-phase="' . htmlspecialchars(self::$phase, ENT_QUOTES) . '">' . $text . '</div>';
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
     * @param array|null $error What error_get_last() returned.
     * @return string|null The line, or null when the error is not a memory exhaustion.
     */
    public static function log_line(?array $error): ?string {
        if (self::classify($error) !== self::MEMORY) {
            return null;
        }
        $context = [];
        if (self::$context !== null) {
            try {
                $context = (array) (self::$context)();
            } catch (\Throwable $e) {
                $context = ['context' => 'unavailable'];
            }
        }
        $peaks = [];
        foreach (self::$peaks as $phase => $bytes) {
            $peaks[] = $phase . '=' . self::megabytes($bytes);
        }
        $fields = [
            'phase' => self::$phase,
            'peak' => self::megabytes(memory_get_peak_usage()),
            'limit' => ($bytes = self::limit_from((string) ($error['message'] ?? ''))) !== null
                ? self::megabytes($bytes) : self::configured_limit(),
            'phases' => implode(',', $peaks),
            'url' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        ] + $context;
        $line = 'local_catquizlab memory:';
        foreach ($fields as $name => $value) {
            $line .= ' ' . $name . '=' . str_replace(' ', '', (string) $value);
        }

        return $line;
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
        self::$context = $context;
        $phases = [];
        foreach (self::PHASES as $phase) {
            $phases[$phase] = get_string('results:phase' . $phase, $component);
        }
        $texts = [
            self::MEMORY => get_string('results:outofmemory', $component, (object) [
                'limit' => '{limit}', 'peak' => '{peak}', 'phase' => '{phase}',
            ]),
            self::TIME   => get_string('results:outoftime', $component, '{limit}'),
            'phases'     => $phases,
        ];

        \core_shutdown_manager::register_function(static function () use ($texts): void {
            $error = error_get_last();
            $line = self::log_line($error);
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
        });
    }
}
