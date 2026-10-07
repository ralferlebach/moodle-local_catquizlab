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
 * What each targeted mode finds, against the ground truth and beside the baselines.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Mode-specific detection (#114).
 *
 * "Deficit detection" was one tab and one set of figures for strategies that
 * are after different things: the weakest subscales (lowestsub), the strongest
 * (highestsub), the relevant ones (relsubs), all of them (allsubs), or none in
 * particular (classic). Pooled, the figures meant nothing. Here every targeted
 * mode is evaluated on its own, for what it is after:
 *
 * - **deficit oriented** — the true targets are the subscales with
 *   Δs,true ≤ −threshold;
 * - **strength oriented** — those with Δs,true ≥ +threshold;
 * - **relevant scales** — the scales relevant for the person's competence
 *   band, which is what the engine's strategy is after ("Skalen im
 *   Kompetenzbereich abprüfen"). Which scales those are for a simulated person
 *   is not defined here yet: until it is, this mode has no set of true targets,
 *   and so no recall, precision or ranking — only its local recovery, and that
 *   beside the baselines.
 *
 * The quality of a mode is measured against the simulated ground truth, always.
 * allsubs and classic are **modes to compare with, not a truth**: they answer
 * how much of the same local information a broader or a classical test gets
 * for the same people. So the comparison is paired wherever the same simulated
 * person sat both: the twin of a person in the baseline's run, never another
 * person in its place — and only the scales with a valid result in both
 * sittings go into a paired difference.
 *
 * Only valid sittings and valid scale results enter (#112), and every figure
 * carries what it is made of: sittings, pairs, scale results — against all
 * there were.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mode_detection {
    /** @var string The mode after the relevant scales. */
    public const RELEVANT = 'relevant';

    /** @var string The mode after the deficits. */
    public const DEFICIT = 'deficit';

    /** @var string The mode after the strengths. */
    public const STRENGTH = 'strength';

    /** @var string The baseline that covers every subscale. */
    public const ALLSUBS = 'allsubs';

    /** @var string The classical test as a baseline. */
    public const CLASSIC = 'classic';

    /** @var array<string, string> Strategy => its role here. A strategy without an entry has none, and is not shown. */
    public const ROLES = [
        'relsubs'    => self::RELEVANT,
        'lowestsub'  => self::DEFICIT,
        'highestsub' => self::STRENGTH,
        'allsubs'    => self::ALLSUBS,
        'classic'    => self::CLASSIC,
    ];

    /** @var string[] The targeted modes, in the order they are shown. */
    public const TARGETS = [self::RELEVANT, self::DEFICIT, self::STRENGTH];

    /** @var string[] The modes whose true targets are defined: a scale of a person is one, or is not. */
    public const DEFINED_TARGETS = [self::DEFICIT, self::STRENGTH];

    /**
     * Whether a mode's true targets are defined, so that finding them can be measured.
     *
     * @param string $target One of TARGETS.
     * @return bool
     */
    public static function has_targets(string $target): bool {
        return in_array($target, self::DEFINED_TARGETS, true);
    }

    /** @var string[] The baselines. */
    public const BASELINES = [self::ALLSUBS, self::CLASSIC];

    /** @var int[] The k of the top-k measures. */
    public const TOPK = [1, 3, 5];

    /** @var string[] What makes two sittings sittings of the same simulated person under the same conditions. */
    public const PAIR_ON = ['experimentid', 'tier', 'model', 'variant', 'strength', 'stratum', 'severity', 'twinid'];

    /**
     * The role of a strategy, or null where it has none.
     *
     * @param string $strategy The strategy key.
     * @return string|null
     */
    public static function role(string $strategy): ?string {
        return self::ROLES[$strategy] ?? null;
    }

    /**
     * Whether a deviation is a target of a mode.
     *
     * @param float $delta A local deviation, true or estimated.
     * @param string $target One of TARGETS.
     * @param float $threshold The threshold in logits.
     * @return bool
     */
    public static function is_target(float $delta, string $target, float $threshold): bool {
        $threshold = abs($threshold);
        if ($target === self::STRENGTH) {
            return $delta >= $threshold;
        }
        if ($target === self::DEFICIT) {
            return $delta <= -$threshold;
        }

        // No definition, no target: nothing is called one by guesswork.
        return false;
    }

    /**
     * A deviation as a mode ranks it: the lowest value is the strongest target.
     *
     * @param float $delta A local deviation.
     * @param string $target One of TARGETS.
     * @return float
     */
    public static function oriented(float $delta, string $target): float {
        return $target === self::STRENGTH ? -$delta : $delta;
    }

    /**
     * The key two sittings share when they are of the same simulated person under the same conditions.
     *
     * Everything but the strategy — and the budget, which a strategy may have
     * of its own. Empty where the sitting has no twin: it cannot be paired.
     *
     * @param array $observation An observation.
     * @return string
     */
    public static function pair_key(array $observation): string {
        if ((string) ($observation['twinid'] ?? '') === '') {
            return '';
        }
        $parts = [];
        foreach (self::PAIR_ON as $field) {
            $parts[] = (string) ($observation[$field] ?? '');
        }

        return implode('|', $parts);
    }

    /**
     * What one sitting contributes: compact, because fifty thousand of them are held at once.
     *
     * @param array $observation An observation of every validity, with its detail (profile, trace).
     * @param array $scalemapindex Engine scale id => "category:subscale".
     * @param string $rule engine_validity::RULE_UNIFORM or RULE_ENGINE.
     * @param float $threshold The threshold in logits.
     * @return array|null role, strategy, pairkey, valid, attemptid, withitems, truth (per target: number of true
     *      targets), scales (subscale key => [true deviation, estimated deviation]) — of valid scale results;
     *      null for a strategy without a role.
     */
    public static function sitting(
        array $observation,
        array $scalemapindex,
        string $rule = engine_validity::RULE_UNIFORM,
        float $threshold = local_analysis::DEFAULT_DEFICIT_THRESHOLD
    ): ?array {
        $role = self::role((string) ($observation['strategy'] ?? ''));
        if ($role === null) {
            return null;
        }
        $valid = !array_key_exists('valid', $observation) || (bool) $observation['valid'];
        $truth = array_fill_keys(self::TARGETS, 0);
        $profile = subscale_evaluator::profile_subscales((array) ($observation['profile'] ?? []));
        $global = (float) $profile['global'];
        foreach ($profile['subscales'] as $truetheta) {
            foreach (self::TARGETS as $target) {
                $truth[$target] += self::is_target((float) $truetheta - $global, $target, $threshold) ? 1 : 0;
            }
        }

        $withitems = 0;
        $scales = [];
        local_analysis::$unestimated = 0;
        foreach (local_analysis::subscale_rows($observation, $scalemapindex) as $row) {
            if ($row['items'] === null || (int) $row['items'] <= 0) {
                continue;
            }
            $withitems++;
            if ($valid && local_analysis::row_is_valid($row, $rule)) {
                $scales[$row['key']] = [(float) $row['truedelta'], (float) $row['estdelta']];
            }
        }
        $included = count($scales);
        // Scales with an item but no estimate belong to what there was.
        $withitems += local_analysis::$unestimated;

        return [
            'role' => $role,
            'strategy' => (string) $observation['strategy'],
            'pairkey' => self::pair_key($observation),
            'valid' => $valid,
            'attemptid' => (int) ($observation['attemptid'] ?? 0),
            'withitems' => $withitems,
            'truth' => $truth,
            'included' => $included,
            // Packed: a baseline covers every subscale, and all of its sittings are held to be paired.
            'scales' => self::pack_scales($scales),
        ];
    }

    /**
     * Scale results as one string: twenty bytes each instead of an array each.
     *
     * @param array $scales Subscale key "category:subscale" => [true deviation, estimated deviation].
     * @return string
     */
    public static function pack_scales(array $scales): string {
        $packed = '';
        foreach ($scales as $key => [$true, $est]) {
            [$category, $subscale] = array_map('intval', explode(':', (string) $key) + [0, 0]);
            $packed .= pack('Vee', $category * 100000 + $subscale, $true, $est);
        }

        return $packed;
    }

    /**
     * The scale results of a sitting again.
     *
     * @param string|array $packed From pack_scales(); an array is returned as it is.
     * @return array Subscale key => [true deviation, estimated deviation].
     */
    public static function unpack_scales($packed): array {
        if (is_array($packed)) {
            return $packed;
        }
        $scales = [];
        for ($offset = 0, $length = strlen($packed); $offset < $length; $offset += 20) {
            $part = unpack('Vkey/etrue/eest', $packed, $offset);
            $scales[intdiv($part['key'], 100000) . ':' . ($part['key'] % 100000)] = [$part['true'], $part['est']];
        }

        return $scales;
    }

    /**
     * Evaluate every targeted mode, and compare it with every baseline.
     *
     * @param iterable $sittings Results of sitting(); nulls are skipped.
     * @param float $threshold The threshold in logits.
     * @return array roles (role => sittings, valid, withitems, included), targets (target => own, baselines),
     *      threshold, unassigned.
     */
    public static function analyse(
        iterable $sittings,
        float $threshold = local_analysis::DEFAULT_DEFICIT_THRESHOLD
    ): array {
        $byrole = [];
        $counts = [];
        foreach ($sittings as $sitting) {
            if ($sitting === null) {
                continue;
            }
            $role = $sitting['role'];
            $counts[$role] = $counts[$role] ?? ['sittings' => 0, 'valid' => 0, 'withitems' => 0, 'included' => 0,
                'strategy' => $sitting['strategy']];
            $counts[$role]['sittings']++;
            $counts[$role]['withitems'] += $sitting['withitems'];
            if (!$sitting['valid']) {
                continue;
            }
            $counts[$role]['valid']++;
            $counts[$role]['included'] += (int) ($sitting['included'] ?? count(self::unpack_scales($sitting['scales'])));
            $byrole[$role][] = $sitting;
        }

        $targets = [];
        foreach (self::TARGETS as $target) {
            if (empty($counts[$target])) {
                continue;
            }
            $own = $byrole[$target] ?? [];
            $entry = [
                'target' => $target,
                'own' => self::evaluate($own, $target, $threshold) + ['counts' => $counts[$target]],
                'baselines' => [],
            ];
            foreach (self::BASELINES as $baseline) {
                if (empty($counts[$baseline])) {
                    // Not driven in this selection: said, not shown as an empty table.
                    $entry['baselines'][$baseline] = ['available' => false];
                    continue;
                }
                $entry['baselines'][$baseline] = ['available' => true, 'counts' => $counts[$baseline]]
                    + self::compare($own, $byrole[$baseline] ?? [], $target, $threshold);
            }
            $targets[$target] = $entry;
        }

        return [
            'roles' => $counts,
            'targets' => $targets,
            'threshold' => abs($threshold),
        ];
    }

    /**
     * A set of sittings against the ground truth, for one mode's targets.
     *
     * @param array $sittings Valid sittings.
     * @param string $target One of TARGETS: whose targets are looked for.
     * @param float $threshold The threshold in logits.
     * @param array|null $onlyscales Sitting index => the scale keys to use; all valid ones by default.
     * @return array sittings, scales, truetargets, targetscovered, tp, fp, fn, precision, recall, f1, bias, rmse,
     *      mae, correlation, targetrmse, targetn, spearman, ranked, topk (k => agreement, ndcg).
     */
    public static function evaluate(array $sittings, string $target, float $threshold, ?array $onlyscales = null): array {
        $defined = self::has_targets($target);
        $n = 0;
        $se = $sae = $serr = 0.0;
        $sx = $sy = $sxx = $syy = $sxy = 0.0;
        $tse = 0.0;
        $tn = 0;
        $tp = $fp = 0;
        $truetargets = 0;
        $covered = 0;
        $spearman = [];
        $topk = [];
        foreach ($sittings as $index => $sitting) {
            $truetargets += (int) $sitting['truth'][$target];
            $scales = self::unpack_scales($sitting['scales']);
            if ($onlyscales !== null) {
                $scales = array_intersect_key($scales, array_flip($onlyscales[$index] ?? []));
            }
            $true = [];
            $est = [];
            foreach ($scales as [$truedelta, $estdelta]) {
                $error = $estdelta - $truedelta;
                $n++;
                $serr += $error;
                $se += $error * $error;
                $sae += abs($error);
                $sx += $truedelta;
                $sy += $estdelta;
                $sxx += $truedelta * $truedelta;
                $syy += $estdelta * $estdelta;
                $sxy += $truedelta * $estdelta;
                $istarget = self::is_target($truedelta, $target, $threshold);
                $detected = self::is_target($estdelta, $target, $threshold);
                if ($istarget) {
                    $covered++;
                    $tn++;
                    $tse += $error * $error;
                }
                $tp += $istarget && $detected ? 1 : 0;
                $fp += !$istarget && $detected ? 1 : 0;
                $true[] = self::oriented($truedelta, $target);
                $est[] = self::oriented($estdelta, $target);
            }
            if ($defined && count($true) >= 2) {
                $rho = diagnostics::spearman($true, $est);
                if ($rho !== null) {
                    $spearman[] = (float) $rho;
                }
                foreach (self::TOPK as $k) {
                    if ($k > count($true)) {
                        continue;
                    }
                    $topk[$k]['agreement'][] = (float) diagnostics::topk_agreement($true, $est, $k)['fraction'];
                    $topk[$k]['ndcg'][] = (float) diagnostics::ndcg_at_k($true, $est, $k);
                }
            }
        }
        // A true target without a valid result was not found: it counts against the recall.
        $fn = max(0, $truetargets - $tp);
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : null;
        $recall = $truetargets > 0 ? $tp / $truetargets : null;
        $varx = $n > 1 ? $sxx - $sx * $sx / $n : 0.0;
        $vary = $n > 1 ? $syy - $sy * $sy / $n : 0.0;
        $mean = static fn(array $values): ?float => $values === [] ? null : round(array_sum($values) / count($values), 6);
        $ranking = [];
        foreach ($topk as $k => $measures) {
            $ranking[$k] = ['agreement' => $mean($measures['agreement'] ?? []), 'ndcg' => $mean($measures['ndcg'] ?? []),
                'n' => count($measures['agreement'] ?? [])];
        }
        ksort($ranking);

        return [
            'sittings' => count($sittings),
            'scales' => $n,
            // Null throughout where the mode's true targets are not defined: not zero.
            'truetargets' => $defined ? $truetargets : null,
            'targetscovered' => $defined ? $covered : null,
            'tp' => $defined ? $tp : null, 'fp' => $defined ? $fp : null, 'fn' => $defined ? $fn : null,
            'precision' => $precision === null || !$defined ? null : round($precision, 6),
            'recall' => $recall === null || !$defined ? null : round($recall, 6),
            'f1' => $defined && $precision !== null && $recall !== null && ($precision + $recall) > 0
                ? round(2 * $precision * $recall / ($precision + $recall), 6) : null,
            'bias' => $n > 0 ? round($serr / $n, 6) : null,
            'rmse' => $n > 0 ? round(sqrt($se / $n), 6) : null,
            'mae' => $n > 0 ? round($sae / $n, 6) : null,
            'correlation' => $varx > 1e-12 && $vary > 1e-12
                ? round(($sxy - $sx * $sy / $n) / sqrt($varx * $vary), 6) : null,
            'targetrmse' => $defined && $tn > 0 ? round(sqrt($tse / $tn), 6) : null,
            'targetn' => $defined ? $tn : 0,
            'spearman' => $mean($spearman),
            'ranked' => count($spearman),
            'topk' => $ranking,
        ];
    }

    /**
     * A targeted mode beside a baseline: paired over the same simulated people where there are any.
     *
     * @param array $own The mode's valid sittings.
     * @param array $baseline The baseline's valid sittings.
     * @param string $target One of TARGETS.
     * @param float $threshold The threshold in logits.
     * @return array paired (bool), pairs, pairable (the mode's sittings that could have a twin), ambiguous,
     *      commonscales, pairwithitems, mode and baseline (evaluate() of each side on the pairs), common (each side on the scales
     *      valid in both: mode, baseline), points (x baseline, y mode: mean absolute local error per person on
     *      the common scales), meandifference (mode − baseline of those), unpaired (the baseline's own figures,
     *      where nothing pairs).
     */
    public static function compare(array $own, array $baseline, string $target, float $threshold): array {
        // The baseline's sittings by person. A second sitting of the same
        // person in the same conditions is not a second twin: the first stands.
        $bykey = [];
        $ambiguous = 0;
        foreach ($baseline as $sitting) {
            if ($sitting['pairkey'] === '') {
                continue;
            }
            if (isset($bykey[$sitting['pairkey']])) {
                $ambiguous++;
                continue;
            }
            $bykey[$sitting['pairkey']] = $sitting;
        }

        $modeside = [];
        $baseside = [];
        $common = [];
        $points = [];
        $pairable = 0;
        $seen = [];
        foreach ($own as $sitting) {
            $key = $sitting['pairkey'];
            if ($key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                $ambiguous++;
                continue;
            }
            $seen[$key] = true;
            $pairable++;
            if (!isset($bykey[$key])) {
                // No twin in the baseline: not replaced by somebody else.
                continue;
            }
            $twin = $bykey[$key];
            $index = count($modeside);
            $modeside[] = $sitting;
            $baseside[] = $twin;
            $ownscales = self::unpack_scales($sitting['scales']);
            $twinscales = self::unpack_scales($twin['scales']);
            $shared = array_keys(array_intersect_key($ownscales, $twinscales));
            $common[$index] = $shared;
            if ($shared !== []) {
                $own1 = 0.0;
                $base1 = 0.0;
                foreach ($shared as $scale) {
                    $own1 += abs($ownscales[$scale][1] - $ownscales[$scale][0]);
                    $base1 += abs($twinscales[$scale][1] - $twinscales[$scale][0]);
                }
                $points[] = ['x' => $base1 / count($shared), 'y' => $own1 / count($shared), 'n' => count($shared),
                    'attemptid' => $sitting['attemptid'], 'twin' => $twin['attemptid']];
            }
        }

        $pairs = count($modeside);
        if ($pairs === 0) {
            return [
                'paired' => false, 'pairs' => 0, 'pairable' => $pairable, 'ambiguous' => $ambiguous, 'commonscales' => 0,
                'points' => [], 'meandifference' => null,
                // Nothing pairs: the baseline's own sittings, which are other people — and said to be.
                'unpaired' => self::evaluate($baseline, $target, $threshold),
            ];
        }
        $differences = array_map(static fn(array $point): float => $point['y'] - $point['x'], $points);

        return [
            'paired' => true,
            'pairs' => $pairs,
            'pairable' => $pairable,
            'ambiguous' => $ambiguous,
            'commonscales' => array_sum(array_map('count', $common)),
            // What the mode's paired sittings had: every scale result with an administered item.
            'pairwithitems' => array_sum(array_column($modeside, 'withitems')),
            'mode' => self::evaluate($modeside, $target, $threshold),
            'baseline' => self::evaluate($baseside, $target, $threshold),
            'common' => [
                'mode' => self::evaluate($modeside, $target, $threshold, $common),
                'baseline' => self::evaluate($baseside, $target, $threshold, $common),
            ],
            'points' => $points,
            'meandifference' => $differences === [] ? null : round(array_sum($differences) / count($differences), 6),
            'differences' => results_query::describe_values($differences),
        ];
    }

    /**
     * The sittings of a selection, read once: every validity, so that what went in can be said against all.
     *
     * @param results_query $query The selection.
     * @param float $threshold The threshold in logits.
     * @return \Generator<array|null>
     */
    public static function sittings(
        results_query $query,
        float $threshold = local_analysis::DEFAULT_DEFICIT_THRESHOLD
    ): \Generator {
        $filter = $query->get_filter();
        $rule = (string) ($filter['validityrule'] ?? engine_validity::RULE_UNIFORM);
        $all = new results_query(['validity' => result_validity::ALL] + $filter);
        $maps = $all->scale_maps();
        foreach ($all->each_observation() as $observation) {
            $map = $maps[$observation['runid']] ?? [];
            if ($map === [] || self::role((string) $observation['strategy']) === null) {
                continue;
            }
            yield self::sitting($observation + results_query::detail($observation), $map, $rule, $threshold);
        }
    }

    /**
     * The strategies of a selection that have no role here, so that their absence is said.
     *
     * @param results_query $query The selection.
     * @return string[] Strategy keys.
     */
    public static function unassigned(results_query $query): array {
        return array_values(array_filter(
            array_keys($query->available('strategy')),
            static fn(string $strategy): bool => self::role($strategy) === null
        ));
    }

    /**
     * Every scale result behind the figures, one row each — for the export.
     *
     * @param results_query $query The selection.
     * @param float $threshold The threshold in logits.
     * @return array{columns: string[], rows: \Generator}
     */
    public static function export(
        results_query $query,
        float $threshold = local_analysis::DEFAULT_DEFICIT_THRESHOLD
    ): array {
        $columns = [
            'mode', 'strategy', 'runid', 'attemptid', 'personid', 'twinid', 'pairkey',
            'attemptvalid', 'validityreason', 'category', 'subscale', 'items', 'itemssource',
            'scalevalid', 'scalereasons', 'enginescalevalid', 'included',
            'truedelta', 'estdelta', 'error', 'localse', 'threshold',
            'truedeficit', 'estdeficit', 'truestrength', 'eststrength',
            'baseline_allsubs', 'baseline_classic',
        ];
        $rows = (static function () use ($query, $threshold): \Generator {
            $filter = $query->get_filter();
            $rule = (string) ($filter['validityrule'] ?? engine_validity::RULE_UNIFORM);
            $all = new results_query(['validity' => result_validity::ALL] + $filter);
            $maps = $all->scale_maps();
            // First the twins each baseline has a valid sitting of.
            $twins = [self::ALLSUBS => [], self::CLASSIC => []];
            foreach ($all->each_observation() as $observation) {
                $role = self::role((string) $observation['strategy']);
                if (in_array($role, self::BASELINES, true) && $observation['valid']) {
                    $twins[$role][self::pair_key($observation)] = (int) $observation['attemptid'];
                }
            }
            foreach ($all->each_observation() as $observation) {
                $role = self::role((string) $observation['strategy']);
                $map = $maps[$observation['runid']] ?? [];
                if ($role === null || $map === []) {
                    continue;
                }
                $pairkey = self::pair_key($observation);
                $full = $observation + results_query::detail($observation);
                foreach (local_analysis::subscale_rows($full, $map) as $row) {
                    $true = (float) $row['truedelta'];
                    $est = (float) $row['estdelta'];
                    $included = $observation['valid'] && local_analysis::row_is_valid($row, $rule);
                    yield [
                        'mode' => $role,
                        'strategy' => $observation['strategy'],
                        'runid' => $observation['runid'],
                        'attemptid' => $observation['attemptid'],
                        'personid' => $observation['personid'],
                        'twinid' => $observation['twinid'],
                        'pairkey' => $pairkey,
                        'attemptvalid' => (int) $observation['valid'],
                        'validityreason' => (string) ($observation['validityreason'] ?? ''),
                        'category' => $row['category'],
                        'subscale' => $row['subscale'],
                        'items' => $row['items'],
                        'itemssource' => $row['itemssource'],
                        'scalevalid' => (int) $row['scalevalid'],
                        'scalereasons' => implode(',', (array) $row['scalereasons']),
                        'enginescalevalid' => $row['enginescalevalid'] === null ? null : (int) $row['enginescalevalid'],
                        'included' => (int) $included,
                        'truedelta' => $row['truedelta'],
                        'estdelta' => $row['estdelta'],
                        'error' => $row['error'],
                        'localse' => $row['localse'],
                        'threshold' => abs($threshold),
                        'truedeficit' => (int) self::is_target($true, self::DEFICIT, $threshold),
                        'estdeficit' => (int) self::is_target($est, self::DEFICIT, $threshold),
                        'truestrength' => (int) self::is_target($true, self::STRENGTH, $threshold),
                        'eststrength' => (int) self::is_target($est, self::STRENGTH, $threshold),
                        // The twin's sitting in each baseline, where it has a valid one.
                        'baseline_allsubs' => $pairkey !== '' ? ($twins[self::ALLSUBS][$pairkey] ?? null) : null,
                        'baseline_classic' => $pairkey !== '' ? ($twins[self::CLASSIC][$pairkey] ?? null) : null,
                    ];
                }
            }
        })();

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * The summary as rows: every mode against the truth, and each side of every comparison — for the export.
     *
     * @param array $analysis From analyse().
     * @return array{columns: string[], rows: array[]}
     */
    public static function summary_rows(array $analysis): array {
        $columns = ['mode', 'compared', 'baseline', 'pairing', 'sittings', 'sittingsall', 'pairs', 'pairable',
            'scales', 'scaleswithitems', 'truetargets', 'targetscovered', 'correlation', 'bias', 'rmse', 'mae',
            'targetrmse', 'recall', 'precision', 'f1', 'spearman', 'top1', 'top3', 'ndcg3', 'meandifference',
            'threshold'];
        $rows = [];
        $threshold = $analysis['threshold'];
        $row = static function (
            string $mode,
            string $compared,
            string $baseline,
            string $pairing,
            array $e,
            array $extra
        ) use ($threshold): array {
            return [
                'mode' => $mode, 'compared' => $compared, 'baseline' => $baseline, 'pairing' => $pairing,
                'sittings' => $e['sittings'] ?? null, 'sittingsall' => $extra['sittingsall'] ?? null,
                'pairs' => $extra['pairs'] ?? null, 'pairable' => $extra['pairable'] ?? null,
                'scales' => $e['scales'] ?? null, 'scaleswithitems' => $extra['withitems'] ?? null,
                'truetargets' => $e['truetargets'] ?? null, 'targetscovered' => $e['targetscovered'] ?? null,
                'correlation' => $e['correlation'] ?? null, 'bias' => $e['bias'] ?? null, 'rmse' => $e['rmse'] ?? null,
                'mae' => $e['mae'] ?? null, 'targetrmse' => $e['targetrmse'] ?? null, 'recall' => $e['recall'] ?? null,
                'precision' => $e['precision'] ?? null, 'f1' => $e['f1'] ?? null, 'spearman' => $e['spearman'] ?? null,
                'top1' => $e['topk'][1]['agreement'] ?? null, 'top3' => $e['topk'][3]['agreement'] ?? null,
                'ndcg3' => $e['topk'][3]['ndcg'] ?? null, 'meandifference' => $extra['meandifference'] ?? null,
                'threshold' => $threshold,
            ];
        };
        foreach ($analysis['targets'] as $target => $entry) {
            $rows[] = $row($target, $target, '', 'truth', $entry['own'], [
                'sittingsall' => $entry['own']['counts']['sittings'], 'withitems' => $entry['own']['counts']['withitems'],
            ]);
            foreach ($entry['baselines'] as $baseline => $comparison) {
                if (empty($comparison['available'])) {
                    $rows[] = $row($target, $baseline, $baseline, 'notavailable', [], []);
                    continue;
                }
                if (!$comparison['paired']) {
                    $rows[] = $row($target, $baseline, $baseline, 'unpaired', $comparison['unpaired'], [
                        'pairs' => 0, 'pairable' => $comparison['pairable'],
                        'sittingsall' => $comparison['counts']['sittings'],
                    ]);
                    continue;
                }
                $extra = ['pairs' => $comparison['pairs'], 'pairable' => $comparison['pairable']];
                $rows[] = $row($target, $target, $baseline, 'paired', $comparison['mode'], $extra);
                $rows[] = $row($target, $baseline, $baseline, 'paired', $comparison['baseline'], $extra);
                $rows[] = $row($target, $target, $baseline, 'pairedcommonscales', $comparison['common']['mode'], $extra
                    + ['meandifference' => $comparison['meandifference']]);
                $rows[] = $row($target, $baseline, $baseline, 'pairedcommonscales', $comparison['common']['baseline'], $extra);
            }
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
