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
 * Whether a sitting's result may go into the results.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Three questions about a sitting, kept apart (#118).
 *
 * Did the engine finish it? Did it end as the design planned — a stop rule met?
 * And may its result go into the figures? A low standard error after seven of
 * fifteen questions is an engine that finished, a design stop not reached and a
 * result that is not valid: the design does not allow an end there.
 *
 * The one place results ask this: evaluate_attempt() for a sitting,
 * scale_reasons() for one scale's result of it (#112).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_validity {
    /** @var string Results of valid sittings only: the default of every aggregated view. */
    public const VALID = 'valid';

    /** @var string Results of invalid sittings only. */
    public const INVALID = 'invalid';

    /** @var string Every collected sitting, marked. */
    public const ALL = 'all';

    /** @var string No final ability, or not a number. */
    public const NO_FINAL_THETA = 'no_final_theta';

    /** @var string The engine ended the sitting on an error of its own. */
    public const ENGINE_ERROR = 'engine_error';

    /** @var string Nothing recorded of the items the sitting was given. */
    public const TRACE_INCOMPLETE = 'trace_incomplete';

    /** @var string The scale's mapping to a subscale of the design is not unique. */
    public const MAPPING_AMBIGUOUS = 'mapping_ambiguous';

    /** @var string[] The engine's own end codes that are errors, not ends. */
    public const ENGINE_ERRORS = ['errorfetchnextquestion', 'emptyfirstquestionlist', 'errornoitems', 'statusundefined'];

    /**
     * Whether a sitting's result may go into the figures — the one place that decides (#112).
     *
     * Every results view asks here, and nowhere else: the overview, the global
     * and local figures, detection, robustness, comparison, the exports. The
     * rules, each from the effective definition of the run and the engine's own
     * definitions — no number is written down here:
     *
     * - no technical failure (a failed sitting is not collected, and an engine
     *   that ended on an error of its own has not finished a test);
     * - a final ability that is a number;
     * - the items the sitting was given are on record;
     * - an end the design allows: not before the strategy's minimum number of
     *   questions, a fixed form played in full, a time limit only where the
     *   design allowed an end at that point — not valid as such, not invalid as
     *   such; running out of the pool is an end like any other, and so is the
     *   maximum number of questions;
     * - the engine's definitions of a valid result (engine_validity): standard
     *   error within its upper bound, the minimum of answered items, a response
     *   pattern that determines an ability.
     *
     * Two verdicts come of it: the uniform one — every rule for every strategy,
     * the default — and the engine's own, word for word. Which of them decides
     * is the caller's rule.
     *
     * @param array $trace The sitting's trace.
     * @param array $facts The run's design and the sitting's end: strategy, minitems, maxitems, semin,
     *      poolsize, played, finalse, dropped, enginestatus.
     * @param array|null $measure The engine's measures of the sitting (engine_validity::measure()).
     * @param array $thresholds The engine's thresholds of the run's test (engine_validity::thresholds()).
     * @param string $rule engine_validity::RULE_UNIFORM or RULE_ENGINE.
     * @return array valid, reasoncode (the first reason), reasons, endcode, played_items, answered_items,
     *      required_min_items, trace_complete, engine_complete, technical_failure, strategy, uniformvalid,
     *      enginevalid, enginefinished, designstop, criterionstop.
     */
    public static function evaluate_attempt(
        array $trace,
        array $facts,
        ?array $measure,
        array $thresholds,
        string $rule = engine_validity::RULE_UNIFORM
    ): array {
        $strategy = (string) ($facts['strategy'] ?? '');
        $played = (int) ($facts['played'] ?? ($trace['nitems'] ?? count((array) ($trace['items'] ?? []))));
        $finalse = $facts['finalse'] ?? null;
        $endcode = reason_catalog::outcome((string) ($trace['stopreason'] ?? ''), $facts);
        $end = self::evaluate($endcode);
        $reasons = $end['valid'] ? [] : [$endcode];

        // The engine's own word on how it ended, where it gave one.
        $status = isset($facts['enginestatus']) && is_numeric($facts['enginestatus']) ? (int) $facts['enginestatus'] : null;
        $enginecode = reason_catalog::engine_code((string) ($trace['stopreason'] ?? ''), $status);
        $enginecomplete = !in_array((string) $enginecode, self::ENGINE_ERRORS, true);
        if (!$enginecomplete) {
            $reasons[] = self::ENGINE_ERROR;
        }

        // The minimum the strategy holds a sitting to — none for a fixed form.
        $minapplies = $strategy === '' || strategy_catalog::uses($strategy, 'globalmin');
        $required = $minapplies ? (int) ($facts['minitems'] ?? 0) : 0;
        $fixedform = $strategy !== '' && strategy_catalog::fixed_form($strategy);
        // A time limit ends a test where it stands. Valid where the design
        // allowed an end there; not where a form was left unfinished or the
        // minimum not reached.
        if ($endcode === 'timeout') {
            $form = (int) ($facts['poolsize'] ?? 0);
            if (($fixedform && $form > 0 && $played < $form) || ($required > 0 && $played < $required)) {
                $reasons[] = 'timeout';
            }
        }

        if (!isset($trace['finaltheta']) || !is_numeric($trace['finaltheta'])) {
            $reasons[] = self::NO_FINAL_THETA;
        }
        // What was administered, question by question: the local figures and
        // the engine's rules are measured from it.
        $tracecomplete = (array) ($trace['items'] ?? []) !== [] || (array) ($trace['responses'] ?? []) !== []
            || (array) ($trace['progress']['responses'] ?? []) !== [];
        if (!$tracecomplete && $measure === null) {
            $reasons[] = self::TRACE_INCOMPLETE;
        }

        // The engine's definitions, every rule for every strategy.
        $reasons = array_values(array_unique(array_merge($reasons, engine_validity::judge_attempt(
            $measure,
            $thresholds,
            is_numeric($finalse) ? (float) $finalse : null
        ))));
        $uniformvalid = $reasons === [];
        $enginevalid = engine_validity::engine_attempt($measure);
        $valid = $rule === engine_validity::RULE_ENGINE ? $enginevalid === true : $uniformvalid;

        return [
            'valid' => $valid,
            'reasoncode' => $reasons[0] ?? ($valid ? '' : 'engine'),
            'reasons' => $reasons,
            'endcode' => $endcode,
            'played_items' => $played,
            'answered_items' => $measure !== null ? (int) ($measure['n'] ?? 0) : null,
            'required_min_items' => $required,
            'trace_complete' => $tracecomplete,
            'engine_complete' => $enginecomplete,
            // Only collected sittings are evaluated: a sitting that failed
            // technically has no result, and is counted where sittings are.
            'technical_failure' => false,
            'strategy' => $strategy,
            'uniformvalid' => $uniformvalid,
            'enginevalid' => $enginevalid,
            'enginefinished' => $enginecomplete,
            'designstop' => $end['designstop'],
            'criterionstop' => $end['criterionstop'],
        ];
    }

    /**
     * Whether one scale's result of a sitting may go into the local figures (#112, section 2).
     *
     * Beyond the sitting being valid — which the caller knows — a scale result
     * needs: at least one item actually administered on the scale, known from
     * the sitting's own steps (#113); a ground truth and an estimate, which is
     * what makes it a row at all; a mapping to one subscale of the design; and
     * the engine's definitions of a valid scale result.
     *
     * @param array|null $measured The engine's measures of the scale: n, fraction, se.
     * @param int|null $items Items administered on the scale; null where not known.
     * @param array $thresholds The engine's thresholds of the run's test.
     * @param bool $ambiguous Whether more than one engine scale maps to this subscale.
     * @return string[] Reasons it is not valid; empty when it is.
     */
    public static function scale_reasons(?array $measured, ?int $items, array $thresholds, bool $ambiguous = false): array {
        $reasons = [];
        if ($ambiguous) {
            $reasons[] = self::MAPPING_AMBIGUOUS;
        }
        if ($items === null && $measured === null) {
            // Nothing measured and the count unknown: "no item on this scale"
            // would be a statement nobody can make.
            $reasons[] = engine_validity::ITEMS_UNKNOWN;

            return $reasons;
        }
        if ($items === null) {
            $reasons[] = engine_validity::ITEMS_UNKNOWN;
        }

        return array_values(array_unique(array_merge($reasons, engine_validity::judge_scale($measured, $thresholds))));
    }

    /**
     * Evaluate a collected sitting by its end.
     *
     * @param string $reasoncode Its end, as reason_catalog::outcome() classifies it.
     * @return array{valid: bool, reason: string, enginefinished: bool, designstop: bool, criterionstop: bool}
     */
    public static function evaluate(string $reasoncode): array {
        $invalid = in_array($reasoncode, reason_catalog::INVALID_OUTCOMES, true);

        return [
            'valid' => !$invalid,
            'reason' => $invalid ? $reasoncode : '',
            // A collected sitting is one the engine finished; failures are not collected.
            'enginefinished' => true,
            'designstop' => reason_catalog::is_design_stop($reasoncode),
            // The stop rules' success: a criterion met, not the maximum or exhaustion.
            'criterionstop' => reason_catalog::is_criterion_stop($reasoncode),
        ];
    }
}
