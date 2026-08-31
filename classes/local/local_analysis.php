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
 * Local diagnostic analysis: recovery of subscale-level deviations.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Turns attempts into local diagnostic observations and their summaries.
 *
 * The quantity of interest is the deviation, not the absolute subscale ability:
 *
 *     Δ_s^true = θ_s^true − θ_g^true      Δ̂_s = θ̂_s − θ̂_g
 *
 * That distinction matters. A test that estimates every subscale one logit too
 * high has recovered the local structure perfectly and the global level badly;
 * comparing absolute abilities would blame the local diagnostics for a global
 * offset. Subscales here are content groups within one unidimensional frame,
 * not independent latent dimensions, so the deviation is what the DPF signal is
 * about.
 *
 * Where the engine did not report a per-scale standard error, the agreement
 * measures are reported as unavailable rather than computed against a stand-in.
 */
class local_analysis {
    /** @var float A deviation of at least this size counts as a deficit. */
    public const DEFAULT_DEFICIT_THRESHOLD = 0.5;

    /** @var int[] The k values the top-k measures are reported at. */
    public const TOPK = [1, 3, 5, 10];

    /**
     * Per-subscale observations for one attempt.
     *
     * @param array $observation One row from {@see results_query::observations()}.
     * @param array $scalemapindex Engine scale id => "category:subscale".
     * @return array[] One row per aligned subscale, empty when nothing aligns.
     */
    public static function subscale_rows(array $observation, array $scalemapindex): array {
        $profile = (array) ($observation['profile'] ?? []);
        $trace = (array) ($observation['trace'] ?? []);

        $truth = subscale_evaluator::profile_subscales($profile);
        $estimates = subscale_evaluator::estimate_subscales(
            (array) ($trace['scaleabilities'] ?? []),
            $scalemapindex
        );
        $ses = self::map_by_subscale((array) ($trace['scalestandarderrors'] ?? []), $scalemapindex);
        $items = self::map_by_subscale((array) ($trace['questionsperscale'] ?? []), $scalemapindex);

        $trueglobal = (float) $truth['global'];
        $estglobal = (float) ($trace['finaltheta'] ?? $observation['esttheta'] ?? 0.0);

        $rows = [];
        foreach ($truth['subscales'] as $key => $truetheta) {
            if (!isset($estimates[$key])) {
                continue;
            }
            [$category, $subscale] = array_map('intval', explode(':', $key));
            $esttheta = (float) $estimates[$key];
            $truedelta = $truetheta - $trueglobal;
            $estdelta = $esttheta - $estglobal;
            $se = $ses[$key] ?? null;

            $rows[] = [
                'attemptid'   => $observation['attemptid'] ?? 0,
                'runid'       => $observation['runid'] ?? 0,
                'personid'    => $observation['personid'] ?? 0,
                'twinid'      => $observation['twinid'] ?? '',
                'strategy'    => $observation['strategy'] ?? '',
                'variant'     => $observation['variant'] ?? '',
                'stratum'     => $observation['stratum'] ?? '',
                'severity'    => $observation['severity'] ?? '',
                'tier'        => $observation['tier'] ?? '',
                'key'         => $key,
                'category'    => $category,
                'subscale'    => $subscale,
                'truetheta'   => round($truetheta, 6),
                'esttheta'    => round($esttheta, 6),
                'truedelta'   => round($truedelta, 6),
                'estdelta'    => round($estdelta, 6),
                'error'       => round($estdelta - $truedelta, 6),
                'localse'     => $se === null ? null : round($se, 6),
                'items'       => (int) ($items[$key] ?? 0),
                'within1se'   => $se === null ? null : abs($estdelta - $truedelta) <= $se,
                'within2se'   => $se === null ? null : abs($estdelta - $truedelta) <= 2 * $se,
            ];
        }

        return $rows;
    }

    /**
     * Per-subscale observations across a set of attempts.
     *
     * @param array $observations Rows from {@see results_query::observations()}.
     * @param array $scalemaps Run id => engine scale id => "category:subscale".
     * @return array[] All subscale observations.
     */
    public static function rows(array $observations, array $scalemaps): array {
        $rows = [];
        foreach ($observations as $observation) {
            $map = $scalemaps[$observation['runid']] ?? [];
            if ($map === []) {
                continue;
            }
            foreach (self::subscale_rows($observation, $map) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Summarise the local recovery of a set of subscale observations.
     *
     * @param array $rows Subscale observations.
     * @return array The summary: n, bias, rmse, correlation, agreement, mean local SE.
     */
    public static function summarise(array $rows): array {
        $errors = [];
        $truedeltas = [];
        $estdeltas = [];
        $ses = [];
        $within1 = 0;
        $within2 = 0;
        $withse = 0;

        foreach ($rows as $row) {
            $errors[] = (float) $row['error'];
            $truedeltas[] = (float) $row['truedelta'];
            $estdeltas[] = (float) $row['estdelta'];
            if ($row['localse'] !== null) {
                $ses[] = (float) $row['localse'];
                $withse++;
                $within1 += $row['within1se'] ? 1 : 0;
                $within2 += $row['within2se'] ? 1 : 0;
            }
        }

        $n = count($errors);
        if ($n === 0) {
            return [
                'n' => 0, 'bias' => null, 'rmse' => null, 'mae' => null,
                'correlation' => null, 'meanse' => null,
                'within1se' => null, 'within2se' => null, 'nwithse' => 0,
            ];
        }

        $bias = array_sum($errors) / $n;
        $rmse = sqrt(array_sum(array_map(static fn(float $e): float => $e * $e, $errors)) / $n);

        return [
            'n'           => $n,
            'bias'        => round($bias, 6),
            'rmse'        => round($rmse, 6),
            'mae'         => round(array_sum(array_map('abs', $errors)) / $n, 6),
            'correlation' => self::correlation($truedeltas, $estdeltas),
            'meanse'      => $ses === [] ? null : round(array_sum($ses) / count($ses), 6),
            // Reported as null rather than zero when no standard error was
            // recorded: "we could not measure this" and "it never agreed" are
            // different statements.
            'within1se'   => $withse > 0 ? round($within1 / $withse, 6) : null,
            'within2se'   => $withse > 0 ? round($within2 / $withse, 6) : null,
            'nwithse'     => $withse,
        ];
    }

    /**
     * Group subscale observations and summarise each group.
     *
     * @param array $rows Subscale observations.
     * @param string $groupby A field of the observations, e.g. 'key' or 'category'.
     * @return array[] One row per group.
     */
    public static function group(array $rows, string $groupby): array {
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) $row[$groupby]][] = $row;
        }
        ksort($groups, SORT_NATURAL);

        $out = [];
        foreach ($groups as $key => $members) {
            $out[] = array_merge(['group' => $key], self::summarise($members));
        }

        return $out;
    }

    /**
     * Ranking measures for one attempt: did the strategy find the right subscales?
     *
     * @param array $rows The attempt's subscale observations.
     * @param string $strategy The strategy key, which decides what "first" means.
     * @param float $threshold The deficit threshold in logits.
     * @return array|null The measures, or null with fewer than two subscales.
     */
    public static function ranking(array $rows, string $strategy, float $threshold = self::DEFAULT_DEFICIT_THRESHOLD): ?array {
        if (count($rows) < 2) {
            return null;
        }

        // For a strengths strategy the interesting end of the scale is the top,
        // so the sign is flipped and every measure below reads the same way.
        $sign = self::orientation($strategy);
        $truevalues = [];
        $estvalues = [];
        foreach ($rows as $row) {
            $truevalues[] = $sign * (float) $row['truedelta'];
            $estvalues[] = $sign * (float) $row['estdelta'];
        }

        // Rank 1 is the strongest deficit, so the target is the *lowest* value;
        // negating turns "lowest first" into the "highest first" convention the
        // ranking helpers use.
        $trueranked = array_map(static fn(float $v): float => -$v, $truevalues);
        $estranked = array_map(static fn(float $v): float => -$v, $estvalues);

        $truelabels = diagnostics::deficit_labels($truevalues, -abs($threshold));
        $estlabels = diagnostics::deficit_labels($estvalues, -abs($threshold));
        $confusion = diagnostics::confusion($truelabels, $estlabels);

        $topk = [];
        foreach (self::TOPK as $k) {
            if ($k > count($rows)) {
                continue;
            }
            $pr = diagnostics::precision_recall_at_k($truelabels, $estranked, $k);
            $topk[$k] = [
                'agreement' => diagnostics::topk_agreement($trueranked, $estranked, $k)['fraction'],
                'precision' => $pr['precision'],
                'recall'    => $pr['recall'],
                'ndcg'      => diagnostics::ndcg_at_k($trueranked, $estranked, $k),
            ];
        }

        return [
            'n'          => count($rows),
            'spearman'   => diagnostics::spearman($trueranked, $estranked),
            'topk'       => $topk,
            'confusion'  => $confusion,
            'rankerror'  => self::mean_rank_error($trueranked, $estranked),
        ];
    }

    /**
     * Aggregate ranking measures across attempts.
     *
     * @param array $rankings Results of {@see self::ranking()}, nulls filtered out.
     * @return array The aggregate: spearman, rank error, per-k measures and the pooled confusion matrix.
     */
    public static function aggregate_ranking(array $rankings): array {
        $rankings = array_values(array_filter($rankings));
        if ($rankings === []) {
            return ['n' => 0, 'spearman' => null, 'rankerror' => null, 'topk' => [], 'confusion' => null];
        }

        $spearman = [];
        $rankerror = [];
        $bykey = [];
        $confusion = ['tp' => 0, 'fp' => 0, 'fn' => 0, 'tn' => 0];

        foreach ($rankings as $ranking) {
            if ($ranking['spearman'] !== null) {
                $spearman[] = (float) $ranking['spearman'];
            }
            if ($ranking['rankerror'] !== null) {
                $rankerror[] = (float) $ranking['rankerror'];
            }
            foreach ($ranking['topk'] as $k => $measures) {
                foreach ($measures as $name => $value) {
                    if ($value !== null) {
                        $bykey[$k][$name][] = (float) $value;
                    }
                }
            }
            foreach ($confusion as $cell => $unused) {
                $confusion[$cell] += (int) ($ranking['confusion'][$cell] ?? 0);
            }
        }

        $topk = [];
        foreach ($bykey as $k => $measures) {
            foreach ($measures as $name => $values) {
                $topk[$k][$name] = results_query::describe_values($values);
            }
        }
        ksort($topk);

        return [
            'n'         => count($rankings),
            'spearman'  => results_query::describe_values($spearman),
            'rankerror' => results_query::describe_values($rankerror),
            'topk'      => $topk,
            'confusion' => $confusion + self::confusion_rates($confusion),
        ];
    }

    /**
     * Precision, recall and specificity of a pooled confusion matrix.
     *
     * @param array $confusion With tp, fp, fn and tn.
     * @return array{precision: float|null, recall: float|null, specificity: float|null, accuracy: float|null}
     */
    public static function confusion_rates(array $confusion): array {
        $tp = (int) ($confusion['tp'] ?? 0);
        $fp = (int) ($confusion['fp'] ?? 0);
        $fn = (int) ($confusion['fn'] ?? 0);
        $tn = (int) ($confusion['tn'] ?? 0);
        $total = $tp + $fp + $fn + $tn;

        return [
            'precision'   => ($tp + $fp) > 0 ? round($tp / ($tp + $fp), 6) : null,
            'recall'      => ($tp + $fn) > 0 ? round($tp / ($tp + $fn), 6) : null,
            'specificity' => ($tn + $fp) > 0 ? round($tn / ($tn + $fp), 6) : null,
            'accuracy'    => $total > 0 ? round(($tp + $tn) / $total, 6) : null,
        ];
    }

    /**
     * Which end of the deviation scale a strategy is aiming at.
     *
     * @param string $strategy The strategy key.
     * @return float 1.0 when low deviations are the target, -1.0 when high ones are.
     */
    public static function orientation(string $strategy): float {
        return $strategy === 'highestsub' ? -1.0 : 1.0;
    }

    /**
     * The tab title and target wording that fit a strategy.
     *
     * Calling the tab "deficit detection" for a strategy that hunts strengths
     * would misdescribe what is being measured, even though the arithmetic is
     * the same.
     *
     * @param string $strategy The strategy key.
     * @return array{title: string, goal: string}
     */
    public static function detection_labels(string $strategy): array {
        $component = 'local_catquizlab';
        $known = [
            'lowestsub'  => 'deficit',
            'highestsub' => 'strength',
            'relsubs'    => 'relevant',
            'allsubs'    => 'coverage',
            'fastest'    => 'byproduct',
            'balanced'   => 'balance',
            'classic'    => 'baseline',
        ];
        $key = $known[$strategy] ?? 'deficit';

        return [
            'title' => get_string('detection:title' . $key, $component),
            'goal'  => get_string('detection:goal' . $key, $component),
        ];
    }

    /**
     * Re-key a per-engine-scale map onto subscale keys.
     *
     * @param array $values Engine scale id => value.
     * @param array $scalemapindex Engine scale id => "category:subscale".
     * @return array<string, float>
     */
    protected static function map_by_subscale(array $values, array $scalemapindex): array {
        $out = [];
        foreach ($values as $scaleid => $value) {
            $key = $scalemapindex[(int) $scaleid] ?? null;
            if ($key !== null && is_numeric($value)) {
                $out[$key] = (float) $value;
            }
        }

        return $out;
    }

    /**
     * The mean absolute difference between true and estimated rank.
     *
     * @param array $truevalues True values, higher is first.
     * @param array $estvalues Estimated values, higher is first.
     * @return float|null
     */
    protected static function mean_rank_error(array $truevalues, array $estvalues): ?float {
        $n = count($truevalues);
        if ($n < 2) {
            return null;
        }
        $trueranks = self::ranks($truevalues);
        $estranks = self::ranks($estvalues);

        $sum = 0.0;
        foreach ($trueranks as $index => $rank) {
            $sum += abs($rank - $estranks[$index]);
        }

        return round($sum / $n, 6);
    }

    /**
     * Ranks of a list, 1 for the highest value.
     *
     * @param array $values The values.
     * @return array<int, int> Index => rank.
     */
    protected static function ranks(array $values): array {
        $order = array_keys($values);
        usort($order, static fn(int $a, int $b): int => $values[$b] <=> $values[$a]);

        $ranks = [];
        foreach ($order as $position => $index) {
            $ranks[$index] = $position + 1;
        }
        ksort($ranks);

        return $ranks;
    }

    /**
     * Pearson correlation of two equally long lists.
     *
     * @param array $a First list.
     * @param array $b Second list.
     * @return float|null Null when it is undefined.
     */
    protected static function correlation(array $a, array $b): ?float {
        $n = count($a);
        if ($n < 2 || $n !== count($b)) {
            return null;
        }
        $meana = array_sum($a) / $n;
        $meanb = array_sum($b) / $n;

        $cov = 0.0;
        $va = 0.0;
        $vb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $da = $a[$i] - $meana;
            $db = $b[$i] - $meanb;
            $cov += $da * $db;
            $va += $da * $da;
            $vb += $db * $db;
        }
        if ($va <= 0.0 || $vb <= 0.0) {
            return null;
        }

        return round($cov / sqrt($va * $vb), 6);
    }
}
