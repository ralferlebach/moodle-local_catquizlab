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
        global $DB;

        $sql = "SELECT a.id, a.tracejson, a.runtimems, p.profilejson, p.stratum
                  FROM {local_catquizlab_attempt} a
                  JOIN {local_catquizlab_person} p ON p.id = a.personid
                 WHERE a.runid = :runid AND a.tracejson IS NOT NULL";
        $rows = $DB->get_records_sql($sql, ['runid' => $runid]);

        $attempts = [];
        foreach ($rows as $row) {
            $trace = json_decode((string) $row->tracejson, true);
            if (!is_array($trace)) {
                continue;
            }
            $profile = json_decode((string) $row->profilejson, true);
            $items = (isset($trace['items']) && is_array($trace['items'])) ? $trace['items'] : [];

            $attempts[] = [
                'truetheta'   => (float) (is_array($profile) ? ($profile['global'] ?? 0.0) : 0.0),
                'esttheta'    => (float) ($trace['finaltheta'] ?? 0.0),
                'se'          => isset($trace['finalse']) ? (float) $trace['finalse'] : null,
                'nitems'      => count($items),
                'items'       => $items,
                'stratum'     => (string) $row->stratum,
                // The three outcomes the article requires that were computed
                // for the screen but never persisted, so nothing downstream
                // could aggregate them.
                'stopreason'  => (string) ($trace['stopreason'] ?? ''),
                'stopreached' => results_query::stop_reached((string) ($trace['stopreason'] ?? '')),
                'runtimems'   => (int) ($row->runtimems ?? 0),
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

        $count = self::write_scope($runid, 'run', metrics::summarise($attempts, $poolsize), $now);
        $count += self::write_process_metrics($runid, 'run', $attempts, $now);

        $bystratum = [];
        foreach ($attempts as $attempt) {
            $bystratum[$attempt['stratum'] ?? 'unknown'][] = $attempt;
        }
        foreach ($bystratum as $stratum => $group) {
            $count += self::write_scope($runid, 'stratum:' . $stratum, metrics::summarise($group, $poolsize), $now);
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

        return 2;
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
