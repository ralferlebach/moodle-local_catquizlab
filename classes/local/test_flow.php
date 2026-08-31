<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The course of a single test, and whether its precision target was reachable.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Reconstructs what happened during one attempt, step by step.
 *
 * Two sources feed this, and they carry different things.
 *
 * debug_info on the engine's attempt row is a list of per-step snapshots, so it
 * holds the ability trajectory: what the estimate was after each response. That
 * is what the ability curve is drawn from.
 *
 * The progress snapshot holds the scale lifecycle — which scales were active,
 * dropped or locked — and the item sequence, but only the *final* ability per
 * scale rather than a path: progress::update_ability() overwrites the value
 * each time. So progress cannot supply the trajectory, and debug_info cannot
 * supply the lifecycle; the view needs both and says which parts it is missing.
 *
 * The feasibility part answers a question a stop rule cannot answer for itself:
 * an SE target of 0.3 demands an information of 1/0.3² ≈ 11.1, and if the item
 * budget cannot deliver that much information, the test was going to end on
 * exhaustion no matter how good the selection was. Reporting such a run as a
 * stop-rule failure would blame the strategy for an arithmetic impossibility.
 */
class test_flow {
    /** @var string The step series has the ability trajectory from debug_info. */
    public const SOURCE_PROGRESS = 'progress';

    /** @var string The step series has the items but no ability trajectory. */
    public const SOURCE_DEBUG = 'debug';

    /** @var string Neither source was available. */
    public const SOURCE_NONE = 'none';

    /**
     * The information a precision target demands.
     *
     * From SE = 1/sqrt(I), so I = 1/SE².
     *
     * @param float $se The target standard error.
     * @return float|null The required test information, or null for a non-positive target.
     */
    public static function required_information(float $se): ?float {
        if ($se <= 0.0) {
            return null;
        }

        return round(1.0 / ($se * $se), 6);
    }

    /**
     * The standard error a given amount of information yields.
     *
     * @param float $information The test information.
     * @return float|null The standard error, or null for non-positive information.
     */
    public static function standard_error(float $information): ?float {
        if ($information <= 0.0) {
            return null;
        }

        return round(1.0 / sqrt($information), 6);
    }

    /**
     * The step series of one attempt.
     *
     * @param array $observation One row from {@see results_query::observations()}.
     * @return array{source: string, steps: array[], scales: array}
     */
    public static function steps(array $observation): array {
        $trace = (array) ($observation['trace'] ?? []);
        $progress = (array) ($trace['progress'] ?? []);
        $path = (array) ($trace['abilitypath'] ?? []);

        // The item sequence: from the progress snapshot when it survived, from
        // the trace's item list otherwise.
        $played = array_values((array) ($progress['playedquestions'] ?? []));
        $items = array_values((array) ($trace['items'] ?? []));

        if ($played === [] && $items === []) {
            return ['source' => self::SOURCE_NONE, 'steps' => [], 'scales' => []];
        }

        $count = $played !== [] ? count($played) : count($items);
        $steps = [];
        for ($index = 0; $index < $count; $index++) {
            $question = $played[$index] ?? null;
            $steps[] = [
                'step'       => $index + 1,
                'questionid' => $question !== null
                    ? (int) ($question['id'] ?? $question['questionid'] ?? 0)
                    : (int) ($items[$index] ?? 0),
                'scaleid'    => $question !== null ? (int) ($question['catscaleid'] ?? 0) : 0,
                'fraction'   => ($question !== null && isset($question['fraction']))
                    ? (float) $question['fraction']
                    : null,
                'ability'    => self::ability_at($path, $index),
            ];
        }

        $scales = [];
        if ($progress !== []) {
            $scales = [
                'active'  => array_values((array) ($progress['activescales'] ?? [])),
                'dropped' => array_values((array) ($progress['droppedscales'] ?? [])),
                'locked'  => array_values((array) ($progress['lockedscales'] ?? [])),
                'peritem' => (array) ($progress['playedquestionsbyscale'] ?? []),
            ];
        } else {
            $scales = ['peritem' => (array) ($trace['questionsperscale'] ?? [])];
        }

        return [
            // The trajectory is what distinguishes a full flow from a bare item
            // list, so it decides which source the view reports.
            'source' => $path !== [] ? self::SOURCE_PROGRESS : self::SOURCE_DEBUG,
            'steps'  => $steps,
            'scales' => $scales,
        ];
    }

    /**
     * Whether an attempt's precision target was reachable within its budget.
     *
     * The judgement rests on the information the administered items actually
     * carried. Where that is not recorded, the verdict is 'unknown' rather than
     * a guess: declaring a target infeasible on no evidence would excuse a
     * strategy that simply chose badly.
     *
     * @param array $observation One row from {@see results_query::observations()}.
     * @param array $cat The run's effective CAT parameters from its manifest.
     * @return array{verdict: string, required: float|null, achieved: float|null,
     *               setarget: float|null, seachieved: float|null, maxitems: int|null}
     */
    public static function feasibility(array $observation, array $cat): array {
        $setarget = isset($cat['se']['min']) ? (float) $cat['se']['min'] : null;
        $maxitems = isset($cat['budgets']['global']['maxitems'])
            ? (int) $cat['budgets']['global']['maxitems']
            : null;
        $required = $setarget === null ? null : self::required_information($setarget);

        $seachieved = $observation['se'] ?? null;
        $achieved = ($seachieved !== null && $seachieved > 0)
            ? round(1.0 / ($seachieved * $seachieved), 6)
            : null;

        $verdict = 'unknown';
        if ($required !== null && $achieved !== null) {
            if ($achieved >= $required) {
                $verdict = 'reached';
            } else if (!empty($observation['stopreached'])) {
                // The engine stopped on a criterion of its own while short of
                // the target: another criterion bit first.
                $verdict = 'stoppedearly';
            } else if ($maxitems !== null && (int) $observation['nitems'] >= $maxitems) {
                // The budget ran out before the target was met. Whether more
                // items would have helped is a separate question; what is
                // certain is that the run had no more to give.
                $verdict = 'budgetexhausted';
            } else {
                $verdict = 'missed';
            }
        }

        return [
            'verdict'    => $verdict,
            'required'   => $required,
            'achieved'   => $achieved,
            'setarget'   => $setarget,
            'seachieved' => $seachieved === null ? null : round((float) $seachieved, 6),
            'maxitems'   => $maxitems,
        ];
    }

    /**
     * Summarise the feasibility verdicts across a set of attempts.
     *
     * @param array $verdicts Results of {@see self::feasibility()}.
     * @return array<string, int> Verdict => count, plus 'n'.
     */
    public static function summarise_feasibility(array $verdicts): array {
        $counts = [
            'reached' => 0, 'stoppedearly' => 0,
            'budgetexhausted' => 0, 'missed' => 0, 'unknown' => 0,
        ];
        foreach ($verdicts as $verdict) {
            $key = $verdict['verdict'] ?? 'unknown';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $counts['n'] = count($verdicts);

        return $counts;
    }

    /**
     * The item budget an SE target implies, given a typical item information.
     *
     * A rough planning figure rather than a prediction: real items differ in
     * information and the estimate moves as the test proceeds. It answers "is
     * this target within an order of magnitude of the budget", which is what a
     * reader needs before blaming a strategy.
     *
     * @param float $setarget The target standard error.
     * @param float $iteminformation The typical information of one item.
     * @return int|null The implied number of items, or null when it is undefined.
     */
    public static function implied_items(float $setarget, float $iteminformation): ?int {
        $required = self::required_information($setarget);
        if ($required === null || $iteminformation <= 0.0) {
            return null;
        }

        return (int) ceil($required / $iteminformation);
    }

    /**
     * The ability estimate recorded after a given step.
     *
     * The path holds one snapshot per step, each a map of scale id to ability.
     * The figure shown is the one for the broadest scale in that snapshot,
     * which is the global estimate the engine was working with at the time.
     *
     * @param array $path The ability path from debug_info.
     * @param int $index The zero-based step index.
     * @return float|null Null when that step recorded nothing.
     */
    protected static function ability_at(array $path, int $index): ?float {
        $snapshot = $path[$index]['abilities'] ?? null;
        if (!is_array($snapshot) || $snapshot === []) {
            return null;
        }

        // The root scale has the lowest id of the run's scales, being created
        // first; taking the first entry keeps this independent of ordering.
        foreach ($snapshot as $ability) {
            if (is_numeric($ability)) {
                return round((float) $ability, 6);
            }
        }

        return null;
    }
}
