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
 * Run registry: persistence and read helpers for experiments and their runs.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Writes a sweep expansion into the lab store and reads it back for display.
 *
 * This is the bridge between the pure sweep expander ({@see sweep}) and the
 * management UI (E1.3): it records one experiment row plus one run row per
 * replication, all at status "draft", so the registry has something concrete to
 * show and the later provisioning/scheduling steps have rows to act on. It only
 * writes to the plugin's own lab-store tables — no Moodle users, courses or
 * questions are created here (that is provisioning, E2).
 */
class registry {
    /** @var int Run/experiment status: defined but not yet scheduled. */
    public const STATUS_DRAFT = 0;

    /** @var int Status: queued for execution. */
    public const STATUS_SCHEDULED = 10;

    /** @var int Status: currently running. */
    public const STATUS_RUNNING = 20;

    /** @var int Status: finished successfully. */
    public const STATUS_FINISHED = 30;

    /** @var int Status: failed. */
    public const STATUS_FAILED = 40;

    /** @var int Status: provisioned and ready, not yet queued for a worker. */
    public const STATUS_READY = 15;

    /** @var int Status: attempts are done, results are being aggregated. */
    public const STATUS_AGGREGATING = 25;

    /**
     * Status: stopped by a person.
     *
     * Kept apart from failed on purpose. A cancelled run tells you a decision
     * was made; a failed one tells you something is wrong. Reading a list where
     * both look the same means investigating cancellations and overlooking
     * defects.
     *
     * @var int
     */
    public const STATUS_CANCELLED = 50;

    /**
     * The statuses a run can be in, in lifecycle order.
     *
     * @return int[]
     */
    public static function run_statuses(): array {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SCHEDULED,
            self::STATUS_READY,
            self::STATUS_RUNNING,
            self::STATUS_AGGREGATING,
            self::STATUS_FINISHED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * Whether a run in this status has finished moving.
     *
     * @param int $status The status.
     * @return bool
     */
    public static function is_terminal(int $status): bool {
        return in_array($status, [self::STATUS_FINISHED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    /**
     * The actions a run in a given status allows.
     *
     * Offering an action that cannot work is worse than not offering it: a
     * button that does nothing looks like a defect in the suite rather than a
     * property of the run.
     *
     * @param int $status The status.
     * @return array{cancel: bool, reproduce: bool, results: bool}
     */
    public static function allowed_actions(int $status): array {
        return [
            'cancel'    => in_array(
                $status,
                [self::STATUS_SCHEDULED, self::STATUS_READY, self::STATUS_RUNNING, self::STATUS_AGGREGATING],
                true
            ),
            'reproduce' => self::is_terminal($status),
            'results'   => in_array($status, [self::STATUS_FINISHED, self::STATUS_AGGREGATING], true),
        ];
    }

    /**
     * Persist a sweep expansion as one experiment and its runs.
     *
     * @param string $name Experiment name.
     * @param string $tier Experiment tier.
     * @param array $expansion The result of {@see sweep::expand()}.
     * @param array $sweepspec The sweep spec the expansion came from (stored for reproducibility).
     * @return int The new experiment id.
     */
    public static function persist_expansion(string $name, string $tier, array $expansion, array $sweepspec): int {
        global $DB, $USER;

        $now = time();
        $transaction = $DB->start_delegated_transaction();

        $experimentid = $DB->insert_record('local_catquizlab_experiment', (object) [
            'name'         => $name,
            'tier'         => $tier,
            'configjson'   => json_encode($sweepspec, JSON_UNESCAPED_SLASHES),
            'status'       => self::STATUS_DRAFT,
            'usermodified' => $USER->id ?? 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        foreach ($expansion['runs'] as $run) {
            $DB->insert_record('local_catquizlab_run', (object) [
                'experimentid' => $experimentid,
                'cellkey'      => (string) $run['cellkey'],
                'seed'         => (int) $run['seed'],
                'replication'  => (int) $run['replication'],
                'status'       => self::STATUS_DRAFT,
                'manifestjson' => null,
                'usermodified' => $USER->id ?? 0,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }

        $transaction->allow_commit();

        return $experimentid;
    }

    /**
     * Count runs per status across all experiments.
     *
     * @return array Map of status code to run count (only non-zero statuses present).
     */
    public static function global_status_summary(): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT status, COUNT(*) AS cnt
               FROM {local_catquizlab_run}
           GROUP BY status'
        );
        $summary = [];
        foreach ($rows as $row) {
            $summary[(int) $row->status] = (int) $row->cnt;
        }
        return $summary;
    }

    /**
     * Count the runs of one experiment.
     *
     * @param int $experimentid Experiment id.
     * @return int
     */
    public static function count_runs(int $experimentid): int {
        global $DB;
        return $DB->count_records('local_catquizlab_run', ['experimentid' => $experimentid]);
    }

    /**
     * Fetch the most recent runs, joined with their experiment, for display.
     *
     * @param int $limit Maximum number of runs to return.
     * @return array List of run rows with experimentname and tier attached.
     */
    public static function recent_runs(int $limit = 100): array {
        global $DB;

        $sql = 'SELECT r.id, r.cellkey, r.replication, r.seed, r.status,
                       e.name AS experimentname, e.tier
                  FROM {local_catquizlab_run} r
                  JOIN {local_catquizlab_experiment} e ON e.id = r.experimentid
              ORDER BY r.timecreated DESC, r.id DESC';

        return $DB->get_records_sql($sql, [], 0, $limit);
    }
}
