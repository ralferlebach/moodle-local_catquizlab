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
 * Result aggregator: turn a run's attempt traces into stored metric rows.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;


/**
 * Computes a run's evaluation results from its collected attempts and stores them.
 *
 * It reads the attempts that carry a trace, pairs each with its person's
 * ground-truth ability, computes the {@see metrics} summary and writes one
 * result row per scalar metric (plus an exposure detail row) at run scope. A
 * recompute is idempotent: existing run-scope results are replaced. This is the
 * bridge between evaluation (E4) and export (E6): it needs no engine — it parses
 * the trace JSON the collect step stores — so it is fully testable with synthetic
 * traces.
 *
 * The expected trace shape is a JSON object with 'finaltheta' (float), 'finalse'
 * (float) and 'items' (list of question identifiers); the per-subscale
 * diagnostics aggregation follows once traces carry subscale estimates.
 */
class result_aggregator {
    /**
     * Compute and persist the run-scope results of a run.
     *
     * @param int $runid The run to aggregate.
     * @param int|null $poolsize Total pool items, for exposure (optional).
     * @return int The number of result rows written.
     */
    public static function aggregate(int $runid, ?int $poolsize = null): int {
        $attempts = self::collect_attempts($runid);

        return self::persist_results($runid, $attempts, $poolsize);
    }

    /**
     * Read stored results of a run as flat rows ready for export.
     *
     * @param int $runid The run.
     * @return array List of rows (metric, scope, value, detail).
     */
    public static function results(int $runid): array {
        global $DB;

        $rows = $DB->get_records('local_catquizlab_result', ['runid' => $runid], 'metric ASC, scope ASC');
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'metric' => $row->metric,
                'scope'  => $row->scope,
                'value'  => $row->value,
                'detail' => $row->detailjson,
            ];
        }
        return $out;
    }

    /**
     * Queue the ad-hoc task that aggregates a run's results.
     *
     * @param int $runid The run to aggregate.
     * @param int|null $poolsize Total pool items, for exposure (optional).
     * @return void
     */
    public static function queue(int $runid, ?int $poolsize = null): void {
        $task = new \local_catquizlab\task\aggregate_results();
        $task->set_custom_data(['runid' => $runid, 'poolsize' => $poolsize]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Build the metrics-ready attempt list from a run's traces.
     *
     * @param int $runid The run.
     * @return array Attempts with truetheta, esttheta, se, nitems, items and stratum.
     */
    protected static function collect_attempts(int $runid): array {
        // From the one place every view reads (#118): the same end, the same
        // validity, from the engine's code and the run's design — not from a
        // list of English stop texts, which "You ran out of questions" and
        // "Keine weiteren Fragen" both passed by.
        $query = new results_query(['runid' => $runid, 'validity' => result_validity::ALL]);
        $attempts = [];
        foreach ($query->each_observation() as $row) {
            $attempts[] = [
                'truetheta'   => (float) $row['truetheta'],
                'esttheta'    => (float) $row['esttheta'],
                'se'          => $row['se'],
                'nitems'      => (int) $row['nitems'],
                'items'       => (array) ($row['items'] ?? []),
                // The person's stratum, as before: a run's people need not share one.
                'stratum'     => (string) ($row['personstratum'] !== '' ? $row['personstratum'] : $row['stratum']),
                'stopreason'  => (string) $row['endreasoncode'],
                'stopreached' => (bool) $row['stopreached'],
                'valid'       => (bool) $row['valid'],
                'runtimems'   => (int) ($row['runtimems'] ?? 0),
            ];
        }

        return $attempts;
    }

    /**
     * Replace a run's result rows with run-scope and per-stratum-scope metrics.
     *
     * @param int $runid The run.
     * @param array $attempts The run's attempts (each carrying a 'stratum').
     * @param int|null $poolsize Total pool items, for exposure.
     * @return int The number of rows written.
     */
    protected static function persist_results(int $runid, array $attempts, ?int $poolsize): int {
        global $DB;

        $now = time();
        $DB->delete_records('local_catquizlab_result', ['runid' => $runid]);

        // Figures from valid sittings only; the process — how many stopped as
        // planned, why the others ended, how many were valid — from all (#118).
        $valid = array_values(array_filter($attempts, static fn(array $a): bool => !empty($a['valid'])));
        $count = self::write_scope($runid, 'run', metrics::summarise($valid, $poolsize), $now);
        $count += self::write_process_metrics($runid, 'run', $attempts, $now);

        $bystratum = [];
        foreach ($attempts as $attempt) {
            $bystratum[$attempt['stratum'] ?? 'unknown'][] = $attempt;
        }
        foreach ($bystratum as $stratum => $group) {
            $validgroup = array_values(array_filter($group, static fn(array $a): bool => !empty($a['valid'])));
            $count += self::write_scope($runid, 'stratum:' . $stratum, metrics::summarise($validgroup, $poolsize), $now);
            $count += self::write_process_metrics($runid, 'stratum:' . $stratum, $group, $now);
        }

        return $count;
    }

    /**
     * Write the process outcomes of a scope: stop-rule success and runtime.
     *
     * Both are primary outcomes of the design rather than diagnostics. A stop
     * rule that never bites and one that always does are different procedures,
     * and the computational cost is what makes a strategy usable in practice.
     *
     * @param int $runid The run.
     * @param string $scope The aggregation scope.
     * @param array $attempts The attempts of that scope.
     * @param int $now Timestamp.
     * @return int The number of rows written.
     */
    protected static function write_process_metrics(int $runid, string $scope, array $attempts, int $now): int {
        $n = count($attempts);
        if ($n === 0) {
            return 0;
        }

        $stopped = 0;
        $runtimes = [];
        $reasons = [];
        foreach ($attempts as $attempt) {
            if (!empty($attempt['stopreached'])) {
                $stopped++;
            }
            if (($attempt['runtimems'] ?? 0) > 0) {
                $runtimes[] = (int) $attempt['runtimems'];
            }
            $reason = (string) ($attempt['stopreason'] ?? '');
            if ($reason !== '') {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }

        // The success rate, plus the reasons behind it: a rate of 0.6 says
        // nothing about whether the other 40% ran out of items or were cut
        // short by another criterion.
        self::write_result(
            $runid,
            'stopsuccess',
            $scope,
            round($stopped / $n, 6),
            json_encode($reasons, JSON_UNESCAPED_SLASHES),
            $now
        );

        $mean = $runtimes === [] ? null : round(array_sum($runtimes) / count($runtimes), 3);
        self::write_result($runid, 'runtimems', $scope, $mean, null, $now);

        // The share of valid sittings, and why the others are not (#118).
        $validcount = count(array_filter($attempts, static fn(array $a): bool => !empty($a['valid'])));
        $excluded = [];
        foreach ($attempts as $attempt) {
            if (empty($attempt['valid'])) {
                $excluded[$attempt['stopreason']] = ($excluded[$attempt['stopreason']] ?? 0) + 1;
            }
        }
        self::write_result(
            $runid,
            'validshare',
            $scope,
            round($validcount / $n, 6),
            json_encode(['valid' => $validcount, 'total' => $n, 'excluded' => $excluded], JSON_UNESCAPED_SLASHES),
            $now
        );

        return 3;
    }

    /**
     * Write the scalar metric rows (plus an exposure detail row) for one scope.
     *
     * @param int $runid The run.
     * @param string $scope The aggregation scope (run, stratum:name).
     * @param array $summary The {@see metrics::summarise()} output.
     * @param int $now Timestamp.
     * @return int The number of rows written.
     */
    protected static function write_scope(int $runid, string $scope, array $summary, int $now): int {
        $recovery = $summary['abilityrecovery'];
        $efficiency = $summary['efficiency'];
        $scalars = [
            'n'           => $recovery['n'],
            'bias'        => $recovery['bias'],
            'rmse'        => $recovery['rmse'],
            'mae'         => $recovery['mae'],
            'correlation' => $recovery['correlation'],
            'meanlength'  => $efficiency['meanlength'],
            'minlength'   => $efficiency['minlength'],
            'maxlength'   => $efficiency['maxlength'],
            'meanse'      => $efficiency['meanse'],
        ];

        $count = 0;
        foreach ($scalars as $metric => $value) {
            self::write_result($runid, $metric, $scope, $value, null, $now);
            $count++;
        }
        self::write_result(
            $runid,
            'exposure',
            $scope,
            $summary['exposure']['maxrate'],
            json_encode($summary['exposure'], JSON_UNESCAPED_SLASHES),
            $now
        );
        $count++;

        // Exposure concentration as a figure of its own: a mean rate cannot
        // tell an evenly used pool from one where a tenth of the items carry
        // every test, and it is the second that the design cares about.
        $concentration = $summary['exposure']['concentration'] ?? [];
        foreach (['gini' => 'concentration', 'hhi' => 'concentrationhhi'] as $key => $metric) {
            self::write_result($runid, $metric, $scope, $concentration[$key] ?? null, null, $now);
            $count++;
        }

        return $count;
    }

    /**
     * Insert one result row.
     *
     * @param int $runid The run.
     * @param string $metric The metric key.
     * @param string $scope The aggregation scope.
     * @param float|int|null $value The scalar value.
     * @param string|null $detailjson Optional structured detail.
     * @param int $now Timestamp.
     * @return void
     */
    protected static function write_result(
        int $runid,
        string $metric,
        string $scope,
        $value,
        ?string $detailjson,
        int $now
    ): void {
        global $DB;

        $DB->insert_record('local_catquizlab_result', (object) [
            'runid'       => $runid,
            'metric'      => $metric,
            'scope'       => $scope,
            'value'       => $value === null ? null : $value,
            'detailjson'  => $detailjson,
            'timecreated' => $now,
        ]);
    }
}
