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
        if (stripos($message, 'Allowed memory size') !== false) {
            return self::MEMORY;
        }
        if (stripos($message, 'Maximum execution time') !== false) {
            return self::TIME;
        }

        return null;
    }

    /**
     * The message for a fatal error, or nothing.
     *
     * @param array|null $error What error_get_last() returned.
     * @param array $texts MEMORY and TIME => text with "{limit}" in it.
     * @return string|null HTML, or null when the error is not a resource limit.
     */
    public static function message_for(?array $error, array $texts): ?string {
        $kind = self::classify($error);
        if ($kind === null || !isset($texts[$kind])) {
            return null;
        }

        $limit = $kind === self::MEMORY ? ini_get('memory_limit') : ini_get('max_execution_time');
        $text = str_replace('{limit}', htmlspecialchars((string) $limit, ENT_QUOTES), $texts[$kind]);

        return '<div class="alert alert-danger mt-3">' . $text . '</div>';
    }

    /**
     * Register the guard for the rest of this request.
     *
     * The texts are resolved now, while there is memory to resolve them; at
     * shutdown after an exhaustion there is only the small reserve PHP keeps.
     *
     * @param string $component The component whose strings to use.
     * @return void
     */
    public static function register(string $component = 'local_catquizlab'): void {
        $texts = [
            self::MEMORY => get_string('results:outofmemory', $component, '{limit}'),
            self::TIME   => get_string('results:outoftime', $component, '{limit}'),
        ];

        \core_shutdown_manager::register_function(static function () use ($texts): void {
            $html = self::message_for(error_get_last(), $texts);
            if ($html !== null) {
                echo $html;
            }
        });
    }
}
