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
 * Attempt scheduler: materialises a run's attempt queue.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Creates the queued attempt rows a run needs, one per provisioned person (E3.1).
 *
 * Scheduling is pure lab-store bookkeeping: it inserts an attempt row at status
 * "queued" for every person of the run that has a Moodle user and no attempt
 * yet, and marks the run scheduled. It performs no engine work and starts no
 * worker — the timed ad-hoc task {@see \local_catquizlab\task\schedule_attempts}
 * calls it, and the collect/execute steps act on the rows it produces.
 */
class attempt_scheduler {
    /** @var int Attempt status: queued, not yet played. */
    public const STATUS_QUEUED = 0;

    /** @var int Attempt status: being played by a worker. */
    public const STATUS_RUNNING = 10;

    /** @var int Attempt status: trace collected from the engine. */
    public const STATUS_COLLECTED = 20;

    /** @var int Attempt status: collected trace validated. */
    public const STATUS_VALIDATED = 30;

    /** @var int Attempt status: failed. */
    public const STATUS_FAILED = 40;

    /**
     * Create the queued attempts for a run.
     *
     * @param int $runid The run to schedule.
     * @return int The number of attempts newly created.
     */
    public static function schedule(int $runid): int {
        global $DB;

        $now = time();
        $persons = $DB->get_records_select(
            'local_catquizlab_person',
            'runid = :runid AND moodleuserid IS NOT NULL',
            ['runid' => $runid],
            'id ASC'
        );

        $created = 0;
        foreach ($persons as $person) {
            if ($DB->record_exists('local_catquizlab_attempt', ['runid' => $runid, 'personid' => $person->id])) {
                continue;
            }
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid'           => $runid,
                'personid'        => $person->id,
                'engineattemptid' => null,
                'status'          => self::STATUS_QUEUED,
                'tracejson'       => null,
                'runtimems'       => null,
                'timecreated'     => $now,
                'timemodified'    => $now,
            ]);
            $created++;
        }

        if ($created > 0) {
            $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_SCHEDULED, ['id' => $runid]);
        }

        return $created;
    }

    /**
     * Queue the ad-hoc task that schedules a run's attempts.
     *
     * @param int $runid The run to schedule.
     * @return void
     */
    public static function queue(int $runid): void {
        $task = new \local_catquizlab\task\schedule_attempts();
        $task->set_custom_data(['runid' => $runid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
