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
 * The execution history of a test sitting.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * What was tried on a sitting, by whom, and how it ended.
 *
 * A sitting's own row holds only its latest state: tries, last error, lease.
 * A retry clears those, which is how the diagnosis of the try before it was
 * lost — exactly when it is needed, namely after the retry did not help
 * either. This is append-only: every execution leaves a row, and the row of a
 * failure survives the retry that follows it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_history {
    /** @var string A worker took it and began playing. */
    public const STARTED = 'started';

    /** @var string It finished and was read back. */
    public const COLLECTED = 'collected';

    /** @var string It ended without a result. */
    public const FAILED = 'failed';

    /** @var string Somebody put it back in the queue. */
    public const REQUEUED = 'requeued';

    /**
     * Record one execution.
     *
     * @param int $attemptid The sitting.
     * @param string $outcome One of the constants above.
     * @param array $detail workerid, engineattemptid, runtimems, tryno, detail.
     * @return int The new row's id, or 0 when nothing was written.
     */
    public static function record(int $attemptid, string $outcome, array $detail = []): int {
        global $DB;

        if ($attemptid <= 0 || !$DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
            return 0;
        }

        $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid], 'id, runid, tries');
        if (!$attempt) {
            return 0;
        }

        return (int) $DB->insert_record('local_catquizlab_attemptlog', (object) [
            'attemptid'       => $attemptid,
            'runid'           => (int) $attempt->runid,
            'tryno'           => (int) ($detail['tryno'] ?? $attempt->tries),
            'outcome'         => $outcome,
            'workerid'        => $detail['workerid'] ?? null,
            'engineattemptid' => isset($detail['engineattemptid']) ? (int) $detail['engineattemptid'] : null,
            'runtimems'       => isset($detail['runtimems']) ? (int) $detail['runtimems'] : null,
            // Kept whole: this is the thing somebody reads when a retry did
            // not help, and a truncated stack trace is a stack trace nobody
            // can follow.
            'detail'          => isset($detail['detail']) ? (string) $detail['detail'] : null,
            'timecreated'     => time(),
        ]);
    }

    /**
     * Everything that happened to one sitting, oldest first.
     *
     * @param int $attemptid The sitting.
     * @return array[] Rows with tryno, outcome, workerid, detail, when.
     */
    public static function of_attempt(int $attemptid): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
            return [];
        }

        $rows = [];
        foreach ($DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $attemptid], 'id ASC') as $row) {
            $rows[] = [
                'tryno'           => (int) $row->tryno,
                'outcome'         => (string) $row->outcome,
                'outcomelabel'    => get_string('attemptlog:' . $row->outcome, 'local_catquizlab'),
                'failed'          => $row->outcome === self::FAILED,
                'workerid'        => (string) ($row->workerid ?? ''),
                'engineattemptid' => (int) ($row->engineattemptid ?? 0),
                'runtimems'       => (int) ($row->runtimems ?? 0),
                'detail'          => (string) ($row->detail ?? ''),
                'when'            => userdate((int) $row->timecreated),
                'time'            => (int) $row->timecreated,
            ];
        }

        return $rows;
    }

    /**
     * How often each outcome occurred in a run.
     *
     * @param int $runid The run.
     * @return array<string, int>
     */
    public static function outcomes_of_run(int $runid): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
            return [];
        }

        $counts = [];
        $rows = $DB->get_records_sql(
            'SELECT outcome, COUNT(1) AS n
               FROM {local_catquizlab_attemptlog}
              WHERE runid = :runid
           GROUP BY outcome',
            ['runid' => $runid]
        );
        foreach ($rows as $row) {
            $counts[$row->outcome] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * The last failure recorded for a sitting, whatever has happened since.
     *
     * @param int $attemptid The sitting.
     * @return string Empty when it has never failed.
     */
    public static function last_failure(int $attemptid): string {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
            return '';
        }

        $rows = $DB->get_records(
            'local_catquizlab_attemptlog',
            ['attemptid' => $attemptid, 'outcome' => self::FAILED],
            'id DESC',
            'id, detail',
            0,
            1
        );

        $row = reset($rows);

        return $row ? (string) ($row->detail ?? '') : '';
    }
}
