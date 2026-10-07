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
 * What a figure is made of: the sittings and scale results behind it, of all that were planned.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The population of an analysis, from the plan down to what went in (#115, #112 section 4).
 *
 * A results page can show figures for an experiment of which half never ran:
 * a strategy that failed altogether, a baseline with no valid sitting, twins
 * of whom only some sat every mode. Each figure is a true statement about what
 * it was computed from — and misleading about the design unless that is said.
 *
 * So, for a selection: how many sittings the design planned, how many were
 * scheduled, started, failed technically, collected, and are valid — and why
 * the others are not; per run and per strategy; and where the design has lost
 * something, by name: a cell without a valid sitting, a run below the warning
 * threshold, twins without a valid sitting in every strategy.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analysis_population {
    /** @var string A run or strategy of the design without a single collected sitting. */
    public const LOSS_NOTHING_COLLECTED = 'nothingcollected';

    /** @var string A run or strategy with collected sittings of which none is valid. */
    public const LOSS_NOTHING_VALID = 'nothingvalid';

    /** @var string A baseline (all sub, classic) without a valid sitting. */
    public const LOSS_BASELINE = 'baselineempty';

    /** @var string A run whose valid sittings are below the warning threshold of what was planned. */
    public const LOSS_COVERAGE = 'coverage';

    /** @var string Twins without a valid sitting in every strategy of the selection. */
    public const LOSS_PAIRS = 'pairs';

    /** @var string Scale results in the figures below the warning threshold of those with an administered item. */
    public const LOSS_SCALES = 'scales';

    /** @var int The default warning threshold in percent. */
    public const DEFAULT_THRESHOLD = 80;

    /**
     * The warning threshold: below this share of what there should be, a loss is said.
     *
     * @return int Percent, 0 to 100.
     */
    public static function threshold(): int {
        $value = get_config('local_catquizlab', 'coverage_warning');

        return $value === false || $value === '' ? self::DEFAULT_THRESHOLD : max(0, min(100, (int) $value));
    }

    /**
     * The sittings of a selection, from the plan to the valid ones.
     *
     * @param results_query $query The selection; read once if it has not been.
     * @return array planned, scheduled, started, failed, collected, valid, invalid, invalidreasons (code => n),
     *      runs (run id => strategy, cellkey, planned, scheduled, started, failed, collected, valid),
     *      strategies (strategy => the same, summed), runids, filter, rule, pairs (complete, twins, strategies).
     */
    public static function funnel(results_query $query): array {
        global $DB;

        $runs = $query->runs();
        $counts = $query->validity_counts();
        $perrun = [];
        foreach ($runs as $runid => $run) {
            $record = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
            $definition = $record ? run_registry::definition_for($record) : [];
            $perrun[(int) $runid] = [
                'strategy' => (string) $run['strategy'],
                'cellkey' => (string) $run['cellkey'],
                // What the design says; where a run keeps no definition, what was scheduled.
                'planned' => $definition !== [] ? person_generator::planned_count($definition) : null,
                'scheduled' => 0, 'started' => 0, 'failed' => 0, 'collected' => 0,
                'valid' => (int) ($counts['byrun'][$runid]['valid'] ?? 0),
                'evaluated' => (int) ($counts['byrun'][$runid]['total'] ?? 0),
            ];
        }
        if ($perrun !== []) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($perrun), SQL_PARAMS_NAMED, 'run');
            $rows = $DB->get_recordset_sql(
                "SELECT MIN(id) AS id, runid, status,
                        CASE WHEN tries > 0 OR engineattemptid IS NOT NULL THEN 1 ELSE 0 END AS begun,
                        CASE WHEN tracejson IS NOT NULL THEN 1 ELSE 0 END AS traced,
                        COUNT(1) AS n
                   FROM {local_catquizlab_attempt}
                  WHERE runid {$insql}
               GROUP BY runid, status,
                        CASE WHEN tries > 0 OR engineattemptid IS NOT NULL THEN 1 ELSE 0 END,
                        CASE WHEN tracejson IS NOT NULL THEN 1 ELSE 0 END",
                $params
            );
            foreach ($rows as $row) {
                $runid = (int) $row->runid;
                $n = (int) $row->n;
                $status = (int) $row->status;
                $perrun[$runid]['scheduled'] += $n;
                if ($status !== attempt_scheduler::STATUS_QUEUED || (int) $row->begun === 1) {
                    $perrun[$runid]['started'] += $n;
                }
                if ($status === attempt_scheduler::STATUS_FAILED) {
                    $perrun[$runid]['failed'] += $n;
                }
                if (
                    in_array($status, [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_VALIDATED], true)
                    && (int) $row->traced === 1
                ) {
                    $perrun[$runid]['collected'] += $n;
                }
            }
            $rows->close();
        }

        $total = ['planned' => 0, 'scheduled' => 0, 'started' => 0, 'failed' => 0, 'collected' => 0, 'valid' => 0];
        $strategies = [];
        foreach ($perrun as $runid => $run) {
            $planned = $run['planned'] ?? $run['scheduled'];
            $perrun[$runid]['planned'] = $planned;
            $strategy = $run['strategy'];
            $strategies[$strategy] = $strategies[$strategy] ?? ['runs' => 0] + array_fill_keys(array_keys($total), 0);
            $strategies[$strategy]['runs']++;
            foreach (array_keys($total) as $key) {
                $value = $key === 'planned' ? $planned : $run[$key];
                $total[$key] += $value;
                $strategies[$strategy][$key] += $value;
            }
        }
        ksort($strategies);

        return $total + [
            'invalid' => (int) $counts['invalid'],
            'evaluated' => (int) $counts['total'],
            'invalidreasons' => (array) $counts['invalidreasons'],
            'runs' => $perrun,
            'strategies' => $strategies,
            'runids' => array_keys($perrun),
            'filter' => $query->get_filter(),
            'rule' => (string) ($query->get_filter()['validityrule'] ?? engine_validity::RULE_UNIFORM),
            'pairs' => $query->twin_completeness(),
        ];
    }

    /**
     * Where the design has lost something — each as a code and what it concerns.
     *
     * @param array $funnel From funnel().
     * @param array|null $scales A scale coverage (results_query::scale_coverage()), on views made of scale results.
     * @param int|null $threshold The warning threshold in percent; the setting by default.
     * @return array[] Each: code, a (the string's parameters).
     */
    public static function design_loss(array $funnel, ?array $scales = null, ?int $threshold = null): array {
        $threshold = $threshold ?? self::threshold();
        $losses = [];
        $percent = static fn(int $n, int $of): string => $of > 0 ? format_float(100 * $n / $of, 1) : '0';
        foreach ($funnel['strategies'] as $strategy => $s) {
            $label = strategy_catalog::has($strategy) ? strategy_catalog::label($strategy) : $strategy;
            $baseline = in_array(mode_detection::role($strategy), mode_detection::BASELINES, true);
            if ($s['collected'] === 0) {
                $losses[] = ['code' => self::LOSS_NOTHING_COLLECTED, 'a' => ['what' => $label, 'planned' => $s['planned'],
                    'failed' => $s['failed']]];
            } else if ($s['valid'] === 0) {
                $losses[] = ['code' => $baseline ? self::LOSS_BASELINE : self::LOSS_NOTHING_VALID,
                    'a' => ['what' => $label, 'collected' => $s['collected'], 'planned' => $s['planned']]];
            }
        }
        foreach ($funnel['runs'] as $runid => $run) {
            $strategy = $funnel['strategies'][$run['strategy']] ?? null;
            // A strategy lost altogether has been said; a run of it need not be said again.
            if ($strategy !== null && ($strategy['collected'] === 0 || $strategy['valid'] === 0)) {
                continue;
            }
            if ($run['planned'] > 0 && 100 * $run['valid'] < $threshold * $run['planned']) {
                $losses[] = ['code' => self::LOSS_COVERAGE, 'a' => ['run' => $runid, 'cell' => $run['cellkey'],
                    'valid' => $run['valid'], 'planned' => $run['planned'],
                    'percent' => $percent($run['valid'], $run['planned']), 'threshold' => $threshold]];
            }
        }
        $pairs = $funnel['pairs'] ?? null;
        if ($pairs !== null && $pairs['strategies'] > 1 && $pairs['twins'] > 0 && $pairs['complete'] < $pairs['twins']) {
            $losses[] = ['code' => self::LOSS_PAIRS, 'a' => ['complete' => $pairs['complete'], 'twins' => $pairs['twins'],
                'strategies' => $pairs['strategies'], 'percent' => $percent($pairs['complete'], $pairs['twins'])]];
        }
        if ($scales !== null && (int) $scales['measured'] > 0) {
            $valid = (int) ($scales['chain']['valid'] ?? $scales['included']);
            if (100 * $valid < $threshold * (int) $scales['measured']) {
                $losses[] = ['code' => self::LOSS_SCALES, 'a' => ['included' => $valid, 'measured' => $scales['measured'],
                    'percent' => $percent($valid, (int) $scales['measured']), 'threshold' => $threshold]];
            }
        }

        return $losses;
    }

    /**
     * A loss as a sentence.
     *
     * @param array $loss One of design_loss()'s.
     * @return string
     */
    public static function message(array $loss): string {
        return get_string('population:loss_' . $loss['code'], 'local_catquizlab', (object) $loss['a']);
    }

    /**
     * The population as an export's metadata carries it: counts, reasons, losses — machine-readable.
     *
     * @param results_query $query The selection.
     * @param array|null $scales A scale coverage, where the export is made of scale results.
     * @return array
     */
    public static function metadata(results_query $query, ?array $scales = null): array {
        $funnel = self::funnel($query);
        $out = [
            'attempts' => array_intersect_key($funnel, array_flip(['planned', 'scheduled', 'started', 'failed',
                'collected', 'evaluated', 'valid', 'invalid'])),
            'invalidreasons' => $funnel['invalidreasons'],
            'validityrule' => $funnel['rule'],
            'validityshown' => (string) ($funnel['filter']['validity'] ?? result_validity::VALID),
            'strategies' => $funnel['strategies'],
            'runs' => $funnel['runs'],
            'runids' => $funnel['runids'],
            'twins' => $funnel['pairs'],
            'warningthreshold' => self::threshold(),
            'designloss' => array_map(
                static fn(array $loss): array => ['code' => $loss['code']] + $loss['a'],
                self::design_loss($funnel, $scales)
            ),
        ];
        if ($scales !== null) {
            $out['scales'] = [
                'withadministereditem' => (int) $scales['measured'],
                'fromvalidattempts' => (int) ($scales['chain']['fromvalid'] ?? 0),
                'withtruthandestimate' => (int) ($scales['chain']['paired'] ?? 0),
                'valid' => (int) ($scales['chain']['valid'] ?? 0),
                'included' => (int) $scales['included'],
                'withstandarderror' => (int) ($scales['chain']['withse'] ?? 0),
                'unknownitemcount' => (int) $scales['unknownitems'],
                'estimatedwithoutitem' => (int) $scales['withoutitems'],
                'excluded' => ['frominvalidattempt' => (int) $scales['frominvalid'],
                    'unestimated' => (int) $scales['unestimated']] + (array) $scales['reasons'],
                'itemcountsources' => (array) $scales['sources'],
                'itemcountdiagnosis' => (array) $scales['diagnosis'],
            ];
        }

        return $out;
    }
}
