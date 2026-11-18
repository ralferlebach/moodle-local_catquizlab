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

namespace local_catquizlab\local;

/**
 * Why work that is waiting is not played, run by run — read only (#110, #133, #163).
 *
 * CLARA, 17.11.2026: three runs "running", 87 sittings queued and due, no worker, no entry in the execution line, a
 * tick eight seconds before. A snapshot of the tables could say the failures were "compatible with" the circuit
 * breaker, not that it held the runs: the pause sits in the run's manifest, the line skips paused runs, and "due" is
 * not "claimable". This answers it per run with the same conditions the line and the claim use, in the order they
 * apply, and says what releases it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class idle_diagnosis {
    /** @var string The experiment is being deleted: nothing of it is played again. */
    public const DELETING = 'deleting';

    /** @var string The run is not ready or running (draft, provisioning, finished, failed …). */
    public const NOT_RUNNABLE = 'not_runnable';

    /** @var string The circuit breaker paused the run after failures in a row; a person releases it. */
    public const PAUSED_BREAKER = 'paused_breaker';

    /** @var string A person paused the run. */
    public const PAUSED_PERSON = 'paused_person';

    /** @var string Its sittings wait out a retry delay. */
    public const NOT_DUE = 'not_due';

    /** @var string Not in the execution line yet: the next tick adds its experiment (#163). */
    public const NOT_IN_LINE = 'not_in_line';

    /** @var string Its experiment waits in the line behind the one holding it. */
    public const IN_LINE = 'in_line';

    /** @var string Its experiment holds the line: workers may claim its sittings now. */
    public const CLAIMABLE = 'claimable';

    /**
     * Every run with sittings queued or in play, with why it is or is not played.
     *
     * @param int $experimentid One experiment, or 0 for all.
     * @param int|null $now The clock.
     * @return array<int, array{runid: int, experimentid: int, status: int, statuslabel: string, queued: int, due: int,
     *      nextdue: int, running: int, failed: int, paused: bool, pausedby: string, cause: string, line: string,
     *      holder: int, verdict: string, text: string}>
     */
    public static function runs(int $experimentid = 0, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $params = ['queued' => attempt_scheduler::STATUS_QUEUED, 'running' => attempt_scheduler::STATUS_RUNNING,
            'failed' => attempt_scheduler::STATUS_FAILED, 'now' => $now, 'q2' => attempt_scheduler::STATUS_QUEUED,
            'q3' => attempt_scheduler::STATUS_QUEUED, 'r2' => attempt_scheduler::STATUS_RUNNING];
        $where = '';
        if ($experimentid > 0) {
            $where = ' AND r.experimentid = :experimentid';
            $params['experimentid'] = $experimentid;
        }
        // One query for every count: a page or a CLI call on 6000 runs must not ask 6000 times.
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.experimentid, r.status, r.manifestjson, e.timedeleting,
                    SUM(CASE WHEN a.status = :queued THEN 1 ELSE 0 END) AS queued,
                    SUM(CASE WHEN a.status = :q2 AND a.nextruntime <= :now THEN 1 ELSE 0 END) AS due,
                    MIN(CASE WHEN a.status = :q3 THEN a.nextruntime END) AS nextdue,
                    SUM(CASE WHEN a.status = :running THEN 1 ELSE 0 END) AS inplay,
                    SUM(CASE WHEN a.status = :failed THEN 1 ELSE 0 END) AS failed
               FROM {local_catquizlab_run} r
               JOIN {local_catquizlab_experiment} e ON e.id = r.experimentid
               JOIN {local_catquizlab_attempt} a ON a.runid = r.id
              WHERE EXISTS (SELECT 1 FROM {local_catquizlab_attempt} w
                             WHERE w.runid = r.id AND w.status IN (:q4, :r2)){$where}
           GROUP BY r.id, r.experimentid, r.status, r.manifestjson, e.timedeleting
           ORDER BY r.experimentid, r.id",
            $params + ['q4' => attempt_scheduler::STATUS_QUEUED]
        );

        $holder = execution_queue::holder();
        $lines = [];
        $out = [];
        foreach ($rows as $row) {
            $experiment = (int) $row->experimentid;
            if (!array_key_exists($experiment, $lines)) {
                $lines[$experiment] = (string) execution_queue::state_of($experiment);
            }
            $manifest = json_decode((string) ($row->manifestjson ?? ''), true) ?: [];
            $paused = !empty($manifest['lifecycle']['paused']);
            $reason = (string) ($manifest['lifecycle']['pausedreason'] ?? '');
            $bybreaker = $paused && strpos($reason, circuit_breaker::PAUSE_REASON) === 0;
            $cause = $bybreaker ? substr($reason, strlen(circuit_breaker::PAUSE_REASON) + 1) : '';

            $run = [
                'runid' => (int) $row->id,
                'experimentid' => $experiment,
                'status' => (int) $row->status,
                'statuslabel' => run_registry::status_label((int) $row->status),
                'queued' => (int) $row->queued,
                'due' => (int) $row->due,
                'nextdue' => (int) $row->nextdue,
                'running' => (int) $row->inplay,
                'failed' => (int) $row->failed,
                'paused' => $paused,
                'pausedby' => $paused ? ($bybreaker ? 'breaker' : 'person') : '',
                'cause' => $cause !== '' ? reason_catalog::label($cause) : '',
                'line' => $lines[$experiment],
                'holder' => $holder,
            ];
            $run['verdict'] = self::verdict($run, (int) $row->timedeleting > 0);
            $run['text'] = self::text($run);
            $out[] = $run;
        }

        return $out;
    }

    /**
     * The first condition that keeps the run's sittings from being claimed, in the order the claim checks them.
     *
     * @param array $run The run as runs() builds it, before its verdict.
     * @param bool $deleting Whether its experiment is being deleted.
     * @return string One of the constants.
     */
    protected static function verdict(array $run, bool $deleting): string {
        if ($deleting) {
            return self::DELETING;
        }
        if (!in_array($run['status'], [registry::STATUS_READY, registry::STATUS_RUNNING], true)) {
            return self::NOT_RUNNABLE;
        }
        if ($run['paused']) {
            return $run['pausedby'] === 'breaker' ? self::PAUSED_BREAKER : self::PAUSED_PERSON;
        }
        if ($run['queued'] > 0 && $run['due'] === 0) {
            return self::NOT_DUE;
        }
        if ($run['line'] === '') {
            return self::NOT_IN_LINE;
        }

        return $run['line'] === execution_queue::RUNNING && $run['holder'] === $run['experimentid']
            ? self::CLAIMABLE : self::IN_LINE;
    }

    /**
     * The verdict in words, with what releases it.
     *
     * @param array $run The run with its verdict.
     * @return string
     */
    public static function text(array $run): string {
        $a = (object) [
            'runid' => $run['runid'],
            'experimentid' => $run['experimentid'],
            'status' => $run['statuslabel'],
            'cause' => $run['cause'] !== '' ? $run['cause'] : get_string('circuit:nomessage', 'local_catquizlab'),
            'failed' => $run['failed'],
            'due' => $run['due'],
            'nextdue' => $run['nextdue'] > 0 ? userdate($run['nextdue']) : '-',
            'holder' => $run['holder'],
        ];

        // Waiting with nobody at the front: the next tick (or the next claim) moves the line.
        $key = $run['verdict'] === self::IN_LINE && $run['holder'] === 0 ? 'in_line_free' : $run['verdict'];

        return get_string('diagnose:verdict_' . $key, 'local_catquizlab', $a);
    }
}
