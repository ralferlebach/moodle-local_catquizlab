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
 * Robustness: how far a disturbed pool moves the outcomes.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Compares each pool variant against the ideal pool.
 *
 * A robustness figure is a difference, and a difference is only meaningful if
 * everything except the disturbance is held constant. So the reference is not
 * "all ideal-pool runs" but the ideal-pool runs that share the same tier,
 * strategy, model, stratum and severity as the cell being judged. Comparing a
 * shifted pool under one strategy against an ideal pool under another would
 * report the strategy difference as a robustness effect.
 *
 * Cells with no matching reference are reported as having none, rather than
 * being silently compared against whatever was nearest.
 */
class robustness_analysis {
    /** @var string The variant every other variant is measured against. */
    public const REFERENCE_VARIANT = 'ideal';

    /** @var string[] The coordinates that must match between a cell and its reference. */
    public const MATCH_ON = ['tier', 'strategy', 'model', 'stratum', 'severity'];

    /**
     * The global outcomes robustness is reported on.
     *
     * @return array<string, string> Metric key => language string key.
     */
    public static function global_metrics(): array {
        return [
            'rmse'          => 'metric:rmse',
            'bias'          => 'metric:bias',
            'correlation'   => 'metric:correlation',
            'se'            => 'metric:se',
            'nitems'        => 'metric:testlength',
            'stopsuccess'   => 'metric:stopsuccess',
            'runtimems'     => 'metric:runtime',
        ];
    }

    /**
     * The local outcomes robustness is reported on.
     *
     * @return array<string, string> Metric key => language string key.
     */
    public static function local_metrics(): array {
        return [
            'localrmse'   => 'metric:localrmse',
            'localbias'   => 'metric:localbias',
            'within1se'   => 'metric:within1se',
            'within2se'   => 'metric:within2se',
            'spearman'    => 'metric:spearman',
            'top3'        => 'metric:top3',
            'ndcg3'       => 'metric:ndcg3',
        ];
    }

    /**
     * Compute the outcomes of every cell, and the deltas against its reference.
     *
     * @param array $observations Rows from {@see results_query::observations()}.
     * @param array $scalemaps Run id => scale map, for the local outcomes.
     * @return array[] One row per cell, with 'outcomes', 'deltas' and 'reference'.
     */
    public static function cells(array $observations, array $scalemaps = []): array {
        $grouped = [];
        foreach ($observations as $observation) {
            $grouped[self::cell_key($observation)][] = $observation;
        }

        $cells = [];
        foreach ($grouped as $key => $members) {
            $first = $members[0];
            $cells[$key] = [
                'key'         => $key,
                'variant'     => $first['variant'],
                'strength'    => $first['strength'] ?? null,
                'tier'        => $first['tier'],
                'strategy'    => $first['strategy'],
                'model'       => $first['model'],
                'stratum'     => $first['stratum'],
                'severity'    => $first['severity'],
                'n'           => count($members),
                'outcomes'    => self::outcomes($members, $scalemaps),
                'deltas'      => [],
                'reference'   => null,
                'isreference' => $first['variant'] === self::REFERENCE_VARIANT,
            ];
        }

        // Index the references by everything except the variant and its strength.
        $references = [];
        foreach ($cells as $cell) {
            if ($cell['isreference']) {
                $references[self::reference_key($cell)] = $cell;
            }
        }

        foreach ($cells as $key => $cell) {
            if ($cell['isreference']) {
                continue;
            }
            $reference = $references[self::reference_key($cell)] ?? null;
            if ($reference === null) {
                // Without a matching ideal-pool cell there is nothing to
                // subtract; inventing a reference would fabricate the effect.
                continue;
            }
            $cells[$key]['reference'] = $reference['key'];
            $cells[$key]['deltas'] = self::deltas($cell['outcomes'], $reference['outcomes']);
        }

        return array_values($cells);
    }

    /**
     * The outcomes of one cell.
     *
     * @param array $members The cell's observations.
     * @param array $scalemaps Run id => scale map.
     * @return array<string, float|null> Metric key => value.
     */
    public static function outcomes(array $members, array $scalemaps = []): array {
        $errors = [];
        $stopped = 0;
        foreach ($members as $member) {
            $errors[] = (float) $member['error'];
            $stopped += $member['stopreached'] ? 1 : 0;
        }
        $n = max(1, count($members));

        $outcomes = [
            'rmse'        => $errors === [] ? null : round(
                sqrt(array_sum(array_map(static fn(float $e): float => $e * $e, $errors)) / count($errors)),
                6
            ),
            'bias'        => results_query::summarise($members, 'error')['mean'],
            'correlation' => metrics::ability_recovery($members)['correlation'],
            'se'          => results_query::summarise($members, 'se')['mean'],
            'nitems'      => results_query::summarise($members, 'nitems')['mean'],
            'stopsuccess' => round($stopped / $n, 6),
            'runtimems'   => results_query::summarise($members, 'runtimems')['mean'],
        ];

        return $outcomes + self::local_outcomes($members, $scalemaps);
    }

    /**
     * The local outcomes of one cell.
     *
     * @param array $members The cell's observations.
     * @param array $scalemaps Run id => scale map.
     * @return array<string, float|null>
     */
    protected static function local_outcomes(array $members, array $scalemaps): array {
        $empty = [
            'localrmse' => null, 'localbias' => null,
            'within1se' => null, 'within2se' => null,
            'spearman'  => null, 'top3' => null, 'ndcg3' => null,
        ];
        if ($scalemaps === []) {
            return $empty;
        }

        $subscalerows = [];
        $rankings = [];
        foreach ($members as $member) {
            $map = $scalemaps[$member['runid']] ?? [];
            if ($map === []) {
                continue;
            }
            $rows = local_analysis::subscale_rows($member, $map);
            foreach ($rows as $row) {
                $subscalerows[] = $row;
            }
            $ranking = local_analysis::ranking($rows, (string) $member['strategy']);
            if ($ranking !== null) {
                $rankings[] = $ranking;
            }
        }

        if ($subscalerows === []) {
            return $empty;
        }

        $summary = local_analysis::summarise($subscalerows);
        $aggregate = local_analysis::aggregate_ranking($rankings);

        return [
            'localrmse' => $summary['rmse'],
            'localbias' => $summary['bias'],
            'within1se' => $summary['within1se'],
            'within2se' => $summary['within2se'],
            'spearman'  => $aggregate['spearman']['mean'] ?? null,
            'top3'      => $aggregate['topk'][3]['agreement']['mean'] ?? null,
            'ndcg3'     => $aggregate['topk'][3]['ndcg']['mean'] ?? null,
        ];
    }

    /**
     * The differences between a cell's outcomes and its reference's.
     *
     * @param array $outcomes The cell's outcomes.
     * @param array $reference The reference's outcomes.
     * @return array<string, float|null>
     */
    public static function deltas(array $outcomes, array $reference): array {
        $deltas = [];
        foreach ($outcomes as $metric => $value) {
            $base = $reference[$metric] ?? null;
            $deltas[$metric] = ($value === null || $base === null) ? null : round($value - $base, 6);
        }

        return $deltas;
    }

    /**
     * Whether a larger value of a metric is a better outcome.
     *
     * The robustness view has to colour a delta as improvement or degradation,
     * and the direction is not the same for every metric: more error is worse,
     * more agreement is better, and a longer test is a cost rather than a
     * defect.
     *
     * @param string $metric The metric key.
     * @return int 1 when higher is better, -1 when lower is better, 0 when it is neutral.
     */
    public static function direction(string $metric): int {
        $higherisbetter = ['correlation', 'stopsuccess', 'within1se', 'within2se', 'spearman', 'top3', 'ndcg3'];
        $lowerisbetter = ['rmse', 'se', 'localrmse'];

        if (in_array($metric, $higherisbetter, true)) {
            return 1;
        }
        if (in_array($metric, $lowerisbetter, true)) {
            return -1;
        }

        // Bias, test length and runtime are costs or signed quantities: a
        // reader has to see the number, not a verdict.
        return 0;
    }

    /**
     * Cells of one variant, ordered by disturbance strength.
     *
     * @param array $cells Rows from {@see self::cells()}.
     * @param string $variant The variant.
     * @return array[] The cells, ascending by strength.
     */
    public static function by_strength(array $cells, string $variant): array {
        $selected = array_values(array_filter(
            $cells,
            static fn(array $cell): bool => $cell['variant'] === $variant && $cell['strength'] !== null
        ));
        usort($selected, static fn(array $a, array $b): int => $a['strength'] <=> $b['strength']);

        return $selected;
    }

    /**
     * The variants present, excluding the reference.
     *
     * @param array $cells Rows from {@see self::cells()}.
     * @return string[]
     */
    public static function variants(array $cells): array {
        $variants = [];
        foreach ($cells as $cell) {
            if (!$cell['isreference']) {
                $variants[$cell['variant']] = true;
            }
        }
        ksort($variants);

        return array_keys($variants);
    }

    /**
     * The identifying key of a cell.
     *
     * @param array $observation An observation.
     * @return string
     */
    protected static function cell_key(array $observation): string {
        $parts = [];
        foreach (self::MATCH_ON as $field) {
            $parts[] = (string) ($observation[$field] ?? '');
        }
        $parts[] = (string) ($observation['variant'] ?? '');
        $parts[] = (string) ($observation['strength'] ?? '');

        return implode('|', $parts);
    }

    /**
     * The key a cell looks its reference up by: everything but the disturbance.
     *
     * @param array $cell A cell.
     * @return string
     */
    protected static function reference_key(array $cell): string {
        $parts = [];
        foreach (self::MATCH_ON as $field) {
            $parts[] = (string) ($cell[$field] ?? '');
        }

        return implode('|', $parts);
    }
}
