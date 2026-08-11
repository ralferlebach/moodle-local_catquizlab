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
 * Subscale evaluator: per-subscale DPF diagnostics against the ground truth.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Evaluates how well the CAT engine recovers a person's subscale profile (DPF).
 *
 * The collect step stores the engine's per-scale ability estimates on the trace
 * (scaleabilities); the scale map links each engine scale to a profile subscale;
 * and the person's ground-truth profile holds the true subscale abilities. This
 * class aligns estimate and truth per subscale and runs the {@see diagnostics}
 * measures — a deficit being a subscale below the person's global level (the DPF
 * definition). {@see self::evaluate_person()} is pure and testable;
 * {@see self::evaluate_run()} aggregates across a run and stores dpf_* result rows.
 */
class subscale_evaluator {
    /**
     * Flatten a person's profile into a subscale map plus the global ability.
     *
     * @param array $profile The decoded profilejson.
     * @return array{global: float, subscales: array<string, float>} keyed "category:subscale".
     */
    public static function profile_subscales(array $profile): array {
        $subscales = [];
        foreach ($profile['categories'] ?? [] as $category) {
            $c = (int) ($category['index'] ?? 0);
            foreach ($category['subscales'] ?? [] as $subscale) {
                $s = (int) ($subscale['index'] ?? 0);
                $subscales[$c . ':' . $s] = (float) ($subscale['theta'] ?? 0.0);
            }
        }
        return ['global' => (float) ($profile['global'] ?? 0.0), 'subscales' => $subscales];
    }

    /**
     * Map engine ability estimates to profile subscale keys via the scale map.
     *
     * @param array $scaleabilities Map of engine catscale id to estimated ability.
     * @param array $scalemapindex Map of engine catscale id to "category:subscale".
     * @return array<string, float> Estimated ability keyed "category:subscale".
     */
    public static function estimate_subscales(array $scaleabilities, array $scalemapindex): array {
        $estimates = [];
        foreach ($scaleabilities as $catscaleid => $ability) {
            $key = $scalemapindex[(int) $catscaleid] ?? null;
            if ($key !== null) {
                $estimates[$key] = (float) $ability;
            }
        }
        return $estimates;
    }

    /**
     * Evaluate one person's subscale recovery.
     *
     * @param array $profile The person's decoded profile.
     * @param array $scaleabilities The trace's per-scale ability estimates.
     * @param array $scalemapindex The engine-scale to subscale-key map.
     * @param array $options 'threshold' (deficit reference; defaults to global) and 'topk'.
     * @return array|null The person's diagnostics, or null with fewer than two aligned subscales.
     */
    public static function evaluate_person(
        array $profile,
        array $scaleabilities,
        array $scalemapindex,
        array $options = []
    ): ?array {
        $truth = self::profile_subscales($profile);
        $estmap = self::estimate_subscales($scaleabilities, $scalemapindex);

        $keys = array_values(array_intersect(array_keys($truth['subscales']), array_keys($estmap)));
        sort($keys);
        if (count($keys) < 2) {
            return null;
        }

        $true = [];
        $est = [];
        foreach ($keys as $key) {
            $true[] = $truth['subscales'][$key];
            $est[] = $estmap[$key];
        }

        $reference = (float) ($options['threshold'] ?? $truth['global']);
        $k = (int) ($options['topk'] ?? 3);
        $truelabels = diagnostics::deficit_labels($true, $reference);
        $estlabels = diagnostics::deficit_labels($est, $reference);
        $confusion = diagnostics::confusion($truelabels, $estlabels);
        $pr = diagnostics::precision_recall_at_k($truelabels, $est, $k);

        return [
            'spearman'  => diagnostics::spearman($true, $est),
            'topk'      => diagnostics::topk_agreement($true, $est, $k)['fraction'],
            'ndcg'      => diagnostics::ndcg_at_k($true, $est, $k),
            'confusion' => [$confusion['tp'], $confusion['fp'], $confusion['fn'], $confusion['tn']],
            'precision' => $pr['precision'],
            'recall'    => $pr['recall'],
        ];
    }

    /**
     * Evaluate a run's subscale recovery and store dpf_* result rows.
     *
     * @param int $runid The run.
     * @param array $options See evaluate_person.
     * @return array The aggregated DPF summary.
     */
    public static function evaluate_run(int $runid, array $options = []): array {
        global $DB;

        $scalemapindex = self::scalemap_index($runid);

        $sql = "SELECT a.id, a.tracejson, p.profilejson
                  FROM {local_catquizlab_attempt} a
                  JOIN {local_catquizlab_person} p ON p.id = a.personid
                 WHERE a.runid = :runid AND a.tracejson IS NOT NULL";
        $rows = $DB->get_records_sql($sql, ['runid' => $runid]);

        $people = [];
        foreach ($rows as $row) {
            $trace = json_decode((string) $row->tracejson, true);
            $profile = json_decode((string) $row->profilejson, true);
            $scaleabilities = (is_array($trace) && isset($trace['scaleabilities'])) ? $trace['scaleabilities'] : [];
            $result = self::evaluate_person((array) $profile, (array) $scaleabilities, $scalemapindex, $options);
            if ($result !== null) {
                $people[] = $result;
            }
        }

        $summary = self::aggregate($people);
        self::persist($runid, $summary);

        return $summary;
    }

    /**
     * Build the engine-scale to subscale-key map for a run.
     *
     * @param int $runid The run.
     * @return array<int, string>
     */
    protected static function scalemap_index(int $runid): array {
        global $DB;

        $rows = $DB->get_records(
            'local_catquizlab_scalemap',
            ['runid' => $runid, 'level' => scale_provisioner::LEVEL_SUBSCALE],
            '',
            'id, catscaleid, categoryindex, subscaleindex'
        );

        $index = [];
        foreach ($rows as $row) {
            if ($row->categoryindex !== null && $row->subscaleindex !== null) {
                $index[(int) $row->catscaleid] = (int) $row->categoryindex . ':' . (int) $row->subscaleindex;
            }
        }
        return $index;
    }

    /**
     * Aggregate per-person diagnostics into a run summary.
     *
     * @param array $people The per-person diagnostics.
     * @return array
     */
    protected static function aggregate(array $people): array {
        $confusion = self::pool_confusion($people);
        $precision = self::rate($confusion['tp'], $confusion['fp']);
        $recall = self::rate($confusion['tp'], $confusion['fn']);
        $f1 = self::f1($precision, $recall);

        return [
            'n'         => count($people),
            'spearman'  => self::mean(array_column($people, 'spearman')),
            'topk'      => self::mean(array_column($people, 'topk')),
            'ndcg'      => self::mean(array_column($people, 'ndcg')),
            'precision' => $precision === null ? null : round($precision, 6),
            'recall'    => $recall === null ? null : round($recall, 6),
            'f1'        => $f1 === null ? null : round($f1, 6),
            'confusion' => $confusion,
        ];
    }

    /**
     * Pool the per-person confusion counts.
     *
     * @param array $people The per-person diagnostics.
     * @return array{tp: int, fp: int, fn: int, tn: int}
     */
    protected static function pool_confusion(array $people): array {
        $totals = ['tp' => 0, 'fp' => 0, 'fn' => 0, 'tn' => 0];
        foreach ($people as $person) {
            [$tp, $fp, $fn, $tn] = $person['confusion'];
            $totals['tp'] += $tp;
            $totals['fp'] += $fp;
            $totals['fn'] += $fn;
            $totals['tn'] += $tn;
        }
        return $totals;
    }

    /**
     * A hit rate tp / (tp + other), or null when the denominator is zero.
     *
     * @param int $tp True positives.
     * @param int $other The complementary count (fp for precision, fn for recall).
     * @return float|null
     */
    protected static function rate(int $tp, int $other): ?float {
        return ($tp + $other) > 0 ? $tp / ($tp + $other) : null;
    }

    /**
     * The harmonic mean of precision and recall, or null when undefined.
     *
     * @param float|null $precision Precision.
     * @param float|null $recall Recall.
     * @return float|null
     */
    protected static function f1(?float $precision, ?float $recall): ?float {
        if ($precision === null || $recall === null || ($precision + $recall) <= 0.0) {
            return null;
        }
        return 2.0 * $precision * $recall / ($precision + $recall);
    }

    /**
     * Mean of the non-null numeric values.
     *
     * @param array $values The values.
     * @return float|null
     */
    protected static function mean(array $values): ?float {
        $values = array_values(array_filter($values, static fn($v) => $v !== null));
        if ($values === []) {
            return null;
        }
        return round(array_sum($values) / count($values), 6);
    }

    /**
     * Persist the DPF summary as dpf_* result rows at run scope.
     *
     * @param int $runid The run.
     * @param array $summary The aggregated summary.
     * @return void
     */
    protected static function persist(int $runid, array $summary): void {
        global $DB;

        $now = time();
        $DB->delete_records('local_catquizlab_result', ['runid' => $runid, 'scope' => 'dpf']);

        $scalars = [
            'dpf_n'         => $summary['n'],
            'dpf_spearman'  => $summary['spearman'],
            'dpf_topk'      => $summary['topk'],
            'dpf_ndcg'      => $summary['ndcg'],
            'dpf_precision' => $summary['precision'],
            'dpf_recall'    => $summary['recall'],
            'dpf_f1'        => $summary['f1'],
        ];
        foreach ($scalars as $metric => $value) {
            $DB->insert_record('local_catquizlab_result', (object) [
                'runid'       => $runid,
                'metric'      => $metric,
                'scope'       => 'dpf',
                'value'       => $value,
                'detailjson'  => null,
                'timecreated' => $now,
            ]);
        }
        $DB->insert_record('local_catquizlab_result', (object) [
            'runid'       => $runid,
            'metric'      => 'dpf_confusion',
            'scope'       => 'dpf',
            'value'       => null,
            'detailjson'  => json_encode($summary['confusion'], JSON_UNESCAPED_SLASHES),
            'timecreated' => $now,
        ]);
    }
}
