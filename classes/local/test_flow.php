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
                // The score is kept with the responses, not with the question:
                // read from the question alone, it was always empty (#106).
                'fraction'   => self::score_of($question, $progress),
                'ability'    => self::ability_at($path, $index),
                // How many scales had an estimate after this step: what the
                // engine's ability path records. Which were active, dropped or
                // locked at that moment it does not record — only at the end.
                'scalesestimated' => isset($path[$index]['abilities']) ? count((array) $path[$index]['abilities']) : null,
            ];
        }
        $metrics = self::information_path($steps, $played, $trace);

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
            'steps'  => $metrics['steps'],
            'scales' => $scales,
            'final'  => $metrics['final'],
        ];
    }

    /** @var string[] The metrics a trace can be compared by (#109). */
    public const METRICS = ['ability', 'se', 'ti', 'scales'];

    /**
     * One metric of one sitting, step by step, globally or for one scale (#109).
     *
     * For a scale: its estimate after each step from the ability path, and
     * its test information from the items in its subtree at that estimate —
     * the engine's own arithmetic, checked on the last step against the
     * standard error the engine reports for the scale (a leaf from its own
     * items, a parent from all items below it). Where the check fails the
     * scale's SE and information are null. Which scales were active at a given
     * step the engine does not record: that metric has no per-step values for
     * a scale.
     *
     * @param array $flow What steps() returned.
     * @param array $trace The collected trace.
     * @param string $metric One of METRICS.
     * @param int $scaleid 0 for the global ability, else a catscale id.
     * @param int[] $subtree The scale and every scale below it.
     * @return array{points: array[], consistent: bool, status: string}
     */
    public static function series(array $flow, array $trace, string $metric, int $scaleid = 0, array $subtree = []): array {
        $points = [];
        if ($scaleid === 0) {
            $field = ['ability' => 'ability', 'se' => 'se', 'ti' => 'ti', 'scales' => 'scalesestimated'][$metric] ?? 'ability';
            foreach ($flow['steps'] as $step) {
                $points[] = ['x' => $step['step'], 'y' => $step[$field]];
            }
            return ['points' => $points, 'consistent' => (bool) ($flow['final']['consistent'] ?? false), 'status' => ''];
        }

        $path = array_values((array) ($trace['abilitypath'] ?? []));
        $played = array_values((array) ($trace['progress']['playedquestions'] ?? []));
        $subtree = array_map('intval', $subtree === [] ? [$scaleid] : $subtree);
        $values = [];
        foreach ($flow['steps'] as $index => $step) {
            $abilities = (array) ($path[$index]['abilities'] ?? []);
            $theta = isset($abilities[$scaleid]) ? (float) $abilities[$scaleid] : null;
            $ti = null;
            if ($theta !== null) {
                $ti = 0.0;
                for ($j = 0; $j <= $index && $j < count($played); $j++) {
                    if (!in_array((int) ($played[$j]['catscaleid'] ?? 0), $subtree, true)) {
                        continue;
                    }
                    $information = self::item_information((array) $played[$j], $theta);
                    if ($information === null) {
                        $ti = null;
                        break;
                    }
                    $ti += $information;
                }
            }
            $values[] = ['step' => $step['step'], 'theta' => $theta, 'ti' => $ti,
                'se' => ($ti !== null && $ti > 0) ? 1.0 / sqrt($ti) : null];
        }

        $engine = $trace['scalestandarderrors'][$scaleid] ?? null;
        $last = $values === [] ? null : $values[count($values) - 1];
        $consistent = is_numeric($engine) && $last !== null && $last['se'] !== null
            && abs($last['se'] - (float) $engine) / max((float) $engine, 1e-9) < 0.01;

        foreach ($values as $value) {
            $y = null;
            if ($metric === 'ability') {
                $y = $value['theta'];
            } else if ($metric === 'se' && $consistent) {
                $y = $value['se'];
            } else if ($metric === 'ti' && $consistent) {
                $y = $value['ti'];
            }
            $points[] = ['x' => $value['step'], 'y' => $y];
        }

        $progress = (array) ($trace['progress'] ?? []);
        $status = '';
        foreach (['active' => 'activescales', 'dropped' => 'droppedscales', 'locked' => 'lockedscales'] as $label => $key) {
            if (in_array($scaleid, array_map('intval', (array) ($progress[$key] ?? [])), true)) {
                $status = $label;
            }
        }

        return ['points' => $points, 'consistent' => $consistent, 'status' => $status];
    }

    /**
     * The score of a played question, from wherever the engine kept it.
     *
     * @param array|null $question The played question.
     * @param array $progress The progress snapshot.
     * @return float|null
     */
    protected static function score_of(?array $question, array $progress): ?float {
        if ($question === null) {
            return null;
        }
        if (isset($question['fraction']) && is_numeric($question['fraction'])) {
            return (float) $question['fraction'];
        }
        $id = (string) ($question['id'] ?? $question['questionid'] ?? '');
        foreach ((array) ($progress['responses'] ?? []) as $key => $response) {
            if ((string) $key === $id || (string) ($response['questionid'] ?? '') === $id) {
                return isset($response['fraction']) && is_numeric($response['fraction']) ? (float) $response['fraction'] : null;
            }
        }

        return null;
    }

    /**
     * Test information and standard error after every step (#106).
     *
     * The engine keeps the item parameters of every question played and the
     * estimate after every step, but neither the information nor the standard
     * error per step. Both follow from those: TI@n is the sum of the item
     * informations at the estimate after step n, and SE = 1/√TI@n. Whether this
     * is the engine's own arithmetic is checked on the last step against the
     * information and standard error the engine does report; where they do not
     * agree — a model whose information is not computed here, a missing
     * estimate — every computed value is withheld (null, shown as N/A) rather
     * than shown unconfirmed.
     *
     * @param array[] $steps The steps built so far.
     * @param array[] $played The played questions, in order, with their parameters.
     * @param array $trace The collected trace.
     * @return array{steps: array[], final: array}
     */
    public static function information_path(array $steps, array $played, array $trace): array {
        $enginetime = isset($trace['information']) && is_numeric($trace['information']) ? (float) $trace['information'] : null;
        $enginese = isset($trace['finalse']) && is_numeric($trace['finalse']) ? (float) $trace['finalse'] : null;

        foreach ($steps as $index => $step) {
            $ti = null;
            if ($step['ability'] !== null && count($played) > $index) {
                $ti = 0.0;
                for ($j = 0; $j <= $index; $j++) {
                    $information = self::item_information((array) $played[$j], (float) $step['ability']);
                    if ($information === null) {
                        $ti = null;
                        break;
                    }
                    $ti += $information;
                }
            }
            $steps[$index]['ti'] = $ti;
            $steps[$index]['se'] = ($ti !== null && $ti > 0) ? 1.0 / sqrt($ti) : null;
        }

        $last = $steps === [] ? null : $steps[count($steps) - 1];
        $computed = $last['ti'] ?? null;
        $consistent = $computed !== null && $enginetime !== null && $enginetime > 0
            && abs($computed - $enginetime) / $enginetime < 0.001;

        if (!$consistent) {
            foreach ($steps as $index => $step) {
                $steps[$index]['ti'] = null;
                $steps[$index]['se'] = null;
            }
        }

        return [
            'steps' => $steps,
            'final' => [
                'ti'         => $consistent ? $computed : $enginetime,
                'se'         => $enginese ?? ($consistent ? 1.0 / sqrt((float) $computed) : null),
                'engine_ti'  => $enginetime,
                'engine_se'  => $enginese,
                'consistent' => $consistent,
            ],
        ];
    }

    /**
     * Fisher information of a dichotomous logistic item at an ability.
     *
     * For the models whose parameters are a, b and c — Rasch, Birnbaum (2PL)
     * and their three-parameter form: I = a² · ((P − c)² / (1 − c)²) · (1 − P) / P.
     * Anything else returns null and is not guessed at.
     *
     * @param array $question A played question with model, discrimination, difficulty, guessing.
     * @param float $theta The ability.
     * @return float|null
     */
    public static function item_information(array $question, float $theta): ?float {
        $model = (string) ($question['model'] ?? '');
        if (!in_array($model, ['rasch', 'raschbirnbaum', 'mixedraschbirnbaum'], true)) {
            return null;
        }
        $a = $model === 'rasch' ? 1.0 : (float) ($question['discrimination'] ?? 1.0);
        $b = (float) ($question['difficulty'] ?? 0.0);
        $c = $model === 'mixedraschbirnbaum' ? (float) ($question['guessing'] ?? 0.0) : 0.0;
        if ($c < 0.0 || $c >= 1.0) {
            return null;
        }
        $p = $c + (1.0 - $c) / (1.0 + exp(-$a * ($theta - $b)));
        if ($p <= 0.0 || $p >= 1.0) {
            return 0.0;
        }

        return $a * $a * (($p - $c) ** 2 / (1.0 - $c) ** 2) * ((1.0 - $p) / $p);
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
