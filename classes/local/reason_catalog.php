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
 * Why an execution ended, as one code among a fixed set.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Normalised reason codes for how an execution ended (#107).
 *
 * The reported text of a failure differs with the page, the language and the
 * engine version; "did not reach the finish page after 33 answer(s)" and "…
 * after 1 answer(s)" are the same kind of failure. A code per kind makes
 * failures countable, filterable and comparable across runs — and it covers the
 * sittings that ended as they should, whose reason is just as much a result.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reason_catalog {
    /** @var string[] Codes of a sitting that ended as the test intended. */
    public const OUTCOMES = ['target_se_reached', 'max_items_reached', 'pool_exhausted', 'finished_other'];

    /** @var string[] Codes of a technical failure, most specific first. */
    public const FAILURES = [
        'execution_context_destroyed',
        'navigation_error',
        'fetch_failed',
        'http_error',
        'login_failed',
        'engine_exception',
        'worker_timeout',
        'circuit_breaker_pause',
        'manual_stop',
        'unknown_failure',
    ];

    /**
     * Every code, for a filter.
     *
     * @return array<string, string> Code => label.
     */
    public static function all(): array {
        $codes = [];
        foreach (array_merge(self::OUTCOMES, self::FAILURES) as $code) {
            $codes[$code] = self::label($code);
        }

        return $codes;
    }

    /**
     * A code in words.
     *
     * @param string $code The code.
     * @return string
     */
    public static function label(string $code): string {
        $key = 'reason:' . $code;

        return get_string_manager()->string_exists($key, 'local_catquizlab')
            ? get_string($key, 'local_catquizlab')
            : $code;
    }

    /**
     * The code of a technical failure, from what was reported about it.
     *
     * @param string $message The reported failure.
     * @param array $diagnosis The stored diagnosis (transport, browser events …).
     * @return string One of FAILURES.
     */
    public static function failure(string $message, array $diagnosis = []): string {
        $events = array_column((array) ($diagnosis['browser']['events'] ?? []), 'type');
        $status = (int) ($diagnosis['transport']['status'] ?? 0);

        $checks = [
            'execution_context_destroyed' => stripos($message, 'Execution context was destroyed') !== false,
            'login_failed'                => (bool) preg_match('/Login as .* failed|Invalid login/i', $message),
            'http_error'                  => $status >= 400 || (bool) preg_match('/HTTP (ERROR )?5\d\d|HTTP 4\d\d/i', $message),
            'fetch_failed'                => stripos($message, 'fetch failed') !== false
                || !empty($diagnosis['transport']['wsfunction']),
            'worker_timeout'              => (bool) preg_match('/lease expired|stopped reporting|timed? ?out/i', $message)
                && stripos($message, 'No expected state') === false,
            'engine_exception'            => !empty($diagnosis['exception'])
                || (bool) preg_match('/Moodle showed an error page|server replay|Division by zero|Exception/i', $message),
            'navigation_error'            => (bool) preg_match(
                '/did not reach the finish page|No expected state|Navigation after|No question was presented/i',
                $message
            ) || in_array('context-lost', $events, true),
            'circuit_breaker_pause'       => stripos($message, 'failure-streak') !== false,
            'manual_stop'                 => (bool) preg_match('/cancelled|manual/i', $message),
        ];
        foreach ($checks as $code => $matches) {
            if ($matches) {
                return $code;
            }
        }

        return 'unknown_failure';
    }

    /**
     * The code of a sitting that ended as intended, from its stop reason.
     *
     * @param string $stopreason What the engine or the trace reports.
     * @return string One of OUTCOMES.
     */
    public static function outcome(string $stopreason): string {
        if (preg_match('/standard error|\bse\b|precision/i', $stopreason)) {
            return 'target_se_reached';
        }
        if (preg_match('/maximum number of questions|max(imum)? ?items/i', $stopreason)) {
            return 'max_items_reached';
        }
        if (preg_match('/no (more )?(questions|items)|pool|exhausted/i', $stopreason)) {
            return 'pool_exhausted';
        }

        return 'finished_other';
    }
}
