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
    public const OUTCOMES = [
        'target_se_reached',
        'max_items_reached',
        'fixed_form_complete',
        'fixed_form_incomplete',
        'subscale_rule_satisfied',
        'pool_exhausted',
        'no_eligible_item_remaining',
        'no_eligible_item_before_minimum',
        'ended_before_minimum',
        'timeout',
        'finished_other',
    ];

    /** @var string[] Ends that are a stop rule of the design, met (#118): the planned end. */
    public const DESIGN_STOPS = ['target_se_reached', 'max_items_reached', 'fixed_form_complete', 'subscale_rule_satisfied'];

    /**
     * Ends on a criterion, not on exhaustion: what "stop-rule success" has always counted.
     *
     * Not the maximum number of questions, not running out of items — those end a
     * test as the design allows, but without its criterion met. Kept apart from
     * DESIGN_STOPS (#118) so that the metric keeps its documented meaning.
     */
    public const CRITERION_STOPS = ['target_se_reached', 'fixed_form_complete', 'subscale_rule_satisfied'];

    /** @var string[] Ends that make a result invalid by themselves (#118): before the design allowed one. */
    public const INVALID_OUTCOMES = ['no_eligible_item_before_minimum', 'ended_before_minimum', 'fixed_form_incomplete'];

    /**
     * The engine's own end codes, as it stores them in local_catquiz_attempts.status
     * (\local_catquiz\local\status::$mapping): language-independent, unlike its stop text.
     */
    public const ENGINE_STATUS = [
        -1 => 'statusundefined', 0 => 'statusok', 1 => 'noremainingquestions', 2 => 'testiteminrelatedscale',
        3 => 'errorfetchnextquestion', 4 => 'reachedmaximumquestions', 5 => 'abortpersonabilitynotchanged',
        6 => 'emptyfirstquestionlist', 7 => 'errornoitems', 8 => 'exceededmaxattempttime', 9 => 'attemptclosedbytimelimit',
    ];

    /** @var array<string, string>|null The engine's stop texts in every installed language => its code. */
    protected static ?array $enginetexts = null;

    /**
     * The engine's end code: from its status number where there is one, else from its stop text (#118).
     *
     * The stop text is the engine's string in the language of the moment — "You
     * ran out of questions", "Keine weiteren Fragen" — so a text is matched
     * against the engine's strings in every installed language, not against
     * English patterns, which matched neither of those.
     *
     * @param string $stopreason The stop text.
     * @param int|null $status The engine's status number, if known.
     * @return string|null The code, such as "noremainingquestions"; null if unknown.
     */
    public static function engine_code(string $stopreason, ?int $status = null): ?string {
        if ($status !== null && isset(self::ENGINE_STATUS[$status]) && $status >= 0) {
            return self::ENGINE_STATUS[$status];
        }
        $text = \core_text::strtolower(trim($stopreason));
        if ($text === '') {
            return null;
        }
        if (in_array($text, self::ENGINE_STATUS, true)) {
            return $text;
        }
        // The stop keys of older traces, which the old list of stop texts knew.
        $legacy = ['maxquestions' => 'reachedmaximumquestions', 'maxitems' => 'reachedmaximumquestions',
            'nomoreitems' => 'noremainingquestions', 'nomorequestions' => 'noremainingquestions',
            'timeout' => 'exceededmaxattempttime', 'standarderror' => 'standarderror',
            'standarderrorpersubscale' => 'standarderror'];
        if (isset($legacy[$text])) {
            return $legacy[$text];
        }
        if (self::$enginetexts === null) {
            self::$enginetexts = [];
            $strings = get_string_manager();
            if ($strings->string_exists('noremainingquestions', 'local_catquiz')) {
                foreach (array_keys($strings->get_list_of_translations()) as $lang) {
                    foreach (self::ENGINE_STATUS as $code) {
                        if ($strings->string_exists($code, 'local_catquiz')) {
                            $label = \core_text::strtolower(trim($strings->get_string($code, 'local_catquiz', null, $lang)));
                            self::$enginetexts[$label] = $code;
                        }
                    }
                }
            }
        }

        return self::$enginetexts[$text] ?? null;
    }

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
     * The code of a sitting that ended as intended — from facts, not from wording (#106).
     *
     * The engine reports "maximum reached", "time exceeded", or "no remaining
     * questions"; the last covers several ends it does not tell apart. They are
     * told apart here from what is known about the sitting, in this order: the
     * engine's own statement; the classical test, which plays its form to the
     * end; the precision target, met when the final SE is at or below the
     * run's lower SE bound; a subscale strategy that dropped scales; every item
     * of the pool played; and otherwise no item left that the strategy would
     * choose. Whether a subscale rule blocked the test the engine does not
     * record, so that end is not claimed.
     *
     * @param string $stopreason The engine's stop reason.
     * @param array $facts strategy, finalse, semin, dropped (count), played (count), poolsize.
     * @return string One of OUTCOMES.
     */
    public static function outcome(string $stopreason, array $facts = []): string {
        // The engine's code first (#118): its number where it was read, its
        // text — in any installed language — where not, then the old patterns.
        $status = isset($facts['enginestatus']) && is_numeric($facts['enginestatus']) ? (int) $facts['enginestatus'] : null;
        $code = self::engine_code($stopreason, $status);
        if ($code === null) {
            if (preg_match('/maximum number of questions|reachedmaximumquestions|max\.? number of questions/i', $stopreason)) {
                $code = 'reachedmaximumquestions';
            } else if (preg_match('/time exc?eeded|exceededmaxattempttime|timelimit/i', $stopreason)) {
                $code = 'exceededmaxattempttime';
            } else if (
                preg_match(
                    '/noremainingquestions|no (more |remaining )?(questions|items)|ran out of questions/i',
                    $stopreason
                )
            ) {
                $code = 'noremainingquestions';
            }
        }

        if ($code === 'reachedmaximumquestions') {
            return 'max_items_reached';
        }
        // The engine spells it "Time exeeded" (attemptstatus_8); both codes count.
        if ($code === 'exceededmaxattempttime' || $code === 'attemptclosedbytimelimit') {
            return 'timeout';
        }
        $strategy = (string) ($facts['strategy'] ?? '');
        if ($strategy !== '' && strategy_catalog::fixed_form($strategy)) {
            // A fixed form is complete when it was played in full (#112): every
            // item of the scale. Fewer is a form broken off, whatever ended it.
            $form = (int) ($facts['poolsize'] ?? 0);
            if ($form > 0 && array_key_exists('played', $facts) && (int) $facts['played'] < $form) {
                return 'fixed_form_incomplete';
            }

            return 'fixed_form_complete';
        }

        // Before the minimum number of questions no end is a regular one (#118):
        // not even a standard error at its target — the design does not allow a
        // stop there. "No remaining questions" there is its own code.
        $played = (int) ($facts['played'] ?? 0);
        $minitems = (int) ($facts['minitems'] ?? 0);
        $minapplies = $strategy === '' || strategy_catalog::uses($strategy, 'globalmin');
        if ($minapplies && $minitems > 0 && $played < $minitems) {
            return $code === 'noremainingquestions' ? 'no_eligible_item_before_minimum' : 'ended_before_minimum';
        }

        $finalse = $facts['finalse'] ?? null;
        $semin = $facts['semin'] ?? null;
        // A trace that says itself it stopped on its standard error (older traces).
        if (
            $code === 'standarderror'
                || (is_numeric($finalse) && is_numeric($semin) && (float) $finalse <= (float) $semin)
        ) {
            return 'target_se_reached';
        }
        if ($strategy !== '' && strategy_catalog::uses_subscales($strategy) && (int) ($facts['dropped'] ?? 0) > 0) {
            return 'subscale_rule_satisfied';
        }
        if ((int) ($facts['poolsize'] ?? 0) > 0 && $played >= (int) $facts['poolsize']) {
            return 'pool_exhausted';
        }
        if ($code === 'noremainingquestions') {
            return 'no_eligible_item_remaining';
        }

        return 'finished_other';
    }

    /**
     * Whether an end is the planned one: a stop rule of the design, met (#118).
     *
     * @param string $code An outcome code.
     * @return bool
     */
    public static function is_design_stop(string $code): bool {
        return in_array($code, self::DESIGN_STOPS, true);
    }

    /**
     * Whether an end met a criterion — the success of the stop rules — not the maximum or exhaustion.
     *
     * @param string $code An outcome code.
     * @return bool
     */
    public static function is_criterion_stop(string $code): bool {
        return in_array($code, self::CRITERION_STOPS, true);
    }
}
