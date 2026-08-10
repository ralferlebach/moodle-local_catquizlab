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
        $summary = metrics::summarise($attempts, $poolsize);

        return self::persist_results($runid, $summary);
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
     * Build the metrics-ready attempt list from a run's traces.
     *
     * @param int $runid The run.
     * @return array Attempts with truetheta, esttheta, se, nitems and items.
     */
    protected static function collect_attempts(int $runid): array {
        global $DB;

        $sql = "SELECT a.id, a.tracejson, p.profilejson
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
                'truetheta' => (float) (is_array($profile) ? ($profile['global'] ?? 0.0) : 0.0),
                'esttheta'  => (float) ($trace['finaltheta'] ?? 0.0),
                'se'        => isset($trace['finalse']) ? (float) $trace['finalse'] : null,
                'nitems'    => count($items),
                'items'     => $items,
            ];
        }
        return $attempts;
    }

    /**
     * Replace the run-scope result rows with the given metric summary.
     *
     * @param int $runid The run.
     * @param array $summary The {@see metrics::summarise()} output.
     * @return int The number of rows written.
     */
    protected static function persist_results(int $runid, array $summary): int {
        global $DB;

        $now = time();
        $DB->delete_records('local_catquizlab_result', ['runid' => $runid, 'scope' => 'run']);

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
            self::write_result($runid, $metric, $value, null, $now);
            $count++;
        }

        // Exposure is a structure; keep the max rate as the scalar value.
        self::write_result(
            $runid,
            'exposure',
            $summary['exposure']['maxrate'],
            json_encode($summary['exposure'], JSON_UNESCAPED_SLASHES),
            $now
        );
        $count++;

        return $count;
    }

    /**
     * Insert one result row.
     *
     * @param int $runid The run.
     * @param string $metric The metric key.
     * @param float|int|null $value The scalar value.
     * @param string|null $detailjson Optional structured detail.
     * @param int $now Timestamp.
     * @return void
     */
    protected static function write_result(int $runid, string $metric, $value, ?string $detailjson, int $now): void {
        global $DB;

        $DB->insert_record('local_catquizlab_result', (object) [
            'runid'      => $runid,
            'metric'     => $metric,
            'scope'      => 'run',
            'value'      => $value === null ? null : $value,
            'detailjson' => $detailjson,
            'timecreated' => $now,
        ]);
    }
}
