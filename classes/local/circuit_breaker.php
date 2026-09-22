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
 * Stop a run that is failing the same way over and over.
 *
 * A run whose sittings all fail for one reason will keep failing for that
 * reason: retrying the eleventh after ten identical failures produces an
 * eleventh identical failure, some minutes of a browser's time, and one more
 * line in a log nobody is reading yet. Worse, the experiment goes on reporting
 * itself as running, so somebody watching it sees progress that is only the
 * failure counter moving.
 *
 * So: after enough consecutive failures, stop, say why, and hold the rest back
 * until a person has looked. The recommended action is to read the error, not
 * to press retry — which is why "reset and continue" appears only after the
 * details have been shown.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class circuit_breaker {
    /** @var int Consecutive failures before a run is held. */
    public const THRESHOLD = 10;

    /**
     * Whether this run has just tripped, and hold it if so.
     *
     * @param int $runid The run.
     * @return array{tripped: bool, failures: int, lasterror: string}
     */
    public static function check(int $runid): array {
        global $DB;

        $streak = self::failure_streak($runid);

        if ($streak['count'] < self::THRESHOLD) {
            return ['tripped' => false, 'failures' => $streak['count'], 'lasterror' => $streak['lasterror']];
        }

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run || (int) $run->status === registry::STATUS_FAILED) {
            return ['tripped' => false, 'failures' => $streak['count'], 'lasterror' => $streak['lasterror']];
        }

        // Everything still queued for this run stays queued, and out of reach.
        // Letting workers take them would spend the pool on sittings that are
        // going to fail the same way.
        $held = $DB->count_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status = :queued',
            ['runid' => $runid, 'queued' => attempt_scheduler::STATUS_QUEUED]
        );

        $DB->update_record('local_catquizlab_run', (object) [
            'id'           => $runid,
            'status'       => registry::STATUS_FAILED,
            'lasterror'    => $streak['lasterror'],
            'timemodified' => time(),
        ]);

        run_log::record($runid, run_log::RUN_AUTOPAUSED, [
            'reason'        => 'failure-streak',
            'failure_count' => $streak['count'],
            'last_error'    => $streak['lasterror'],
            'held'          => $held,
        ]);

        return [
            'tripped'   => true,
            'failures'  => $streak['count'],
            'lasterror' => $streak['lasterror'],
            'held'      => $held,
        ];
    }

    /**
     * How many of this run's most recent attempts failed in a row, and why.
     *
     * @param int $runid The run.
     * @return array{count: int, lasterror: string}
     */
    public static function failure_streak(int $runid): array {
        global $DB;

        $recent = $DB->get_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status IN (:failed, :collected)',
            [
                'runid'     => $runid,
                'failed'    => attempt_scheduler::STATUS_FAILED,
                'collected' => attempt_scheduler::STATUS_COLLECTED,
            ],
            'timemodified DESC, id DESC',
            'id, status, lasterror',
            0,
            self::THRESHOLD + 5
        );

        $count = 0;
        $lasterror = '';
        foreach ($recent as $attempt) {
            if ((int) $attempt->status !== attempt_scheduler::STATUS_FAILED) {
                break;
            }

            if ($lasterror === '') {
                $lasterror = (string) $attempt->lasterror;
            }

            $count++;
        }

        return ['count' => $count, 'lasterror' => $lasterror];
    }

    /**
     * The failures of a run, grouped by what actually went wrong.
     *
     * Ten reports of one fault are one fault. Listing them separately makes a
     * single cause look like ten problems, and buries the one line somebody
     * needs under nine copies of itself.
     *
     * @param int $runid The run.
     * @return array[] Each with reason and count, commonest first.
     */
    public static function causes(int $runid): array {
        global $DB;

        $rows = $DB->get_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND status = :failed',
            ['runid' => $runid, 'failed' => attempt_scheduler::STATUS_FAILED],
            '',
            'id, lasterror'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $key = self::normalise((string) $row->lasterror);
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['reason' => $key, 'count' => 0];
            }
            $grouped[$key]['count']++;
        }

        uasort($grouped, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'];
        });

        return array_values($grouped);
    }

    /**
     * Strip the parts of an error that differ between otherwise identical ones.
     *
     * Attempt ids, question ids, paths and timestamps are what makes ten
     * reports of one fault look like ten faults.
     *
     * @param string $error The message.
     * @return string
     */
    public static function normalise(string $error): string {
        $error = trim($error);
        if ($error === '') {
            return get_string('circuit:nomessage', 'local_catquizlab');
        }

        $error = preg_replace('/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\S*/', '<time>', $error);
        $error = preg_replace('/\battempt\s*#?\d+/i', 'attempt <n>', $error);
        $error = preg_replace('/\b(id|runid|questionid|userid|slot)\s*[=:]\s*\d+/i', '$1=<n>', $error);
        $error = preg_replace('#(/[\w./-]+){2,}#', '<path>', $error);
        $error = preg_replace('/\b[0-9a-f]{16,}\b/i', '<hash>', $error);
        $error = preg_replace('/\s+/', ' ', $error);

        return trim(\core_text::substr($error, 0, 300));
    }

    /**
     * Let a held run try again, once somebody has dealt with the cause.
     *
     * @param int $runid The run.
     * @return array{ok: bool, released: int}
     */
    public static function reset_and_continue(int $runid): array {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run) {
            return ['ok' => false, 'released' => 0];
        }

        // Starting abilities an earlier version seeded, if this run still has
        // them: they are the cause behind every "Division by zero" that tripped
        // this breaker on a run with fifty people, and retrying without
        // removing them repeats it exactly.
        user_provisioner::remove_seeded_parameters($runid);

        // The failed sittings go back in the queue with their counters cleared;
        // the ones that were held were never touched and need nothing.
        $released = $DB->count_records('local_catquizlab_attempt', [
            'runid'  => $runid,
            'status' => attempt_scheduler::STATUS_FAILED,
        ]);

        $DB->execute(
            'UPDATE {local_catquizlab_attempt}
                SET status = :queued, tries = 0, lasterror = NULL, nextruntime = 0,
                    leaseowner = NULL, leaseexpires = 0
              WHERE runid = :runid AND status = :failed',
            [
                'queued' => attempt_scheduler::STATUS_QUEUED,
                'runid'  => $runid,
                'failed' => attempt_scheduler::STATUS_FAILED,
            ]
        );

        $DB->update_record('local_catquizlab_run', (object) [
            'id'           => $runid,
            'status'       => registry::STATUS_READY,
            'lasterror'    => null,
            'timemodified' => time(),
        ]);

        run_log::record($runid, run_log::RUN_RESUMED, [
            'reason'   => 'operator-reset',
            'released' => $released,
        ]);

        return ['ok' => true, 'released' => $released];
    }
}
