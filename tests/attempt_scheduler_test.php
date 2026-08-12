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
 * Tests for the attempt scheduler and its ad-hoc task.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\user_provisioner;
use local_catquizlab\task\schedule_attempts;

/**
 * Attempt scheduler tests.
 *
 * @covers \local_catquizlab\local\attempt_scheduler
 * @covers \local_catquizlab\task\schedule_attempts
 */
final class attempt_scheduler_test extends \advanced_testcase {
    /**
     * A run with four persons that have Moodle users.
     *
     * @return int The run id.
     */
    protected function run_with_users(): int {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        $definition = [
            'persons' => [
                'count'   => 4,
                'stratum' => 'conforming',
                'naming'  => ['pattern' => 'P-{stratum}-{index:03d}'],
            ],
            'pool'    => ['scales' => ['categories' => 1, 'subcategories' => 1]],
        ];
        person_generator::generate_and_persist($run->id, $definition, 42);
        user_provisioner::provision($run->id);

        return $run->id;
    }

    /**
     * Scheduling creates one queued attempt per user and marks the run scheduled.
     *
     * @return void
     */
    public function test_schedule_creates_queued_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with_users();
        $created = attempt_scheduler::schedule($runid);

        $this->assertSame(4, $created);
        $this->assertSame(4, $DB->count_records('local_catquizlab_attempt', [
            'runid'  => $runid,
            'status' => attempt_scheduler::STATUS_QUEUED,
        ]));
        $this->assertSame(
            (string) registry::STATUS_SCHEDULED,
            (string) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid])
        );
    }

    /**
     * Persons without a Moodle user are not scheduled.
     *
     * @return void
     */
    public function test_persons_without_user_are_skipped(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        // Two persons, neither provisioned to a user.
        $generator->create_person(['runid' => $run->id]);
        $generator->create_person(['runid' => $run->id]);

        $this->assertSame(0, attempt_scheduler::schedule($run->id));
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', ['runid' => $run->id]));
    }

    /**
     * Scheduling is idempotent.
     *
     * @return void
     */
    public function test_schedule_is_idempotent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with_users();
        $this->assertSame(4, attempt_scheduler::schedule($runid));
        $this->assertSame(0, attempt_scheduler::schedule($runid));
    }

    /**
     * The ad-hoc task schedules when queued and run; it no-ops while disabled.
     *
     * @return void
     */
    public function test_task_respects_master_switch(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with_users();

        // Master switch off: the task does nothing.
        set_config('enabled', 0, 'local_catquizlab');
        $task = new schedule_attempts();
        $task->set_custom_data(['runid' => $runid]);
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]));

        // Master switch on: the task schedules.
        set_config('enabled', 1, 'local_catquizlab');
        $task = new schedule_attempts();
        $task->set_custom_data(['runid' => $runid]);
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertSame(4, $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]));
    }

    /**
     * Queuing enqueues exactly one ad-hoc task carrying the run id.
     *
     * @return void
     */
    public function test_queue_enqueues_task(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with_users();
        attempt_scheduler::queue($runid);

        $tasks = \core\task\manager::get_adhoc_tasks(schedule_attempts::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame($runid, (int) $task->get_custom_data()->runid);
    }

    /**
     * The retry decision requeues while tries remain, then fails.
     *
     * @return void
     */
    public function test_retry_status(): void {
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, attempt_scheduler::retry_status(0));
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, attempt_scheduler::retry_status(attempt_scheduler::MAX_TRIES - 1));
        $this->assertSame(attempt_scheduler::STATUS_FAILED, attempt_scheduler::retry_status(attempt_scheduler::MAX_TRIES));
    }

    /**
     * Stale running attempts are requeued (with tries left) or failed.
     *
     * @return void
     */
    public function test_reclaim_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id]);
        $old = time() - 100000;

        $retryable = $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_RUNNING,
            'tries' => 1, 'nextruntime' => 0, 'timecreated' => $old, 'timemodified' => $old,
        ]);
        $exhausted = $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_RUNNING,
            'tries' => attempt_scheduler::MAX_TRIES, 'nextruntime' => 0, 'timecreated' => $old, 'timemodified' => $old,
        ]);

        $this->assertSame(2, attempt_scheduler::reclaim_stale($run->id, 60));
        $this->assertSame(
            attempt_scheduler::STATUS_QUEUED,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $retryable])
        );
        $this->assertSame(
            attempt_scheduler::STATUS_FAILED,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $exhausted])
        );
    }

    /**
     * Aborting a run fails its pending attempts and fires the event.
     *
     * @return void
     */
    public function test_abort(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id]);
        $statuses = [
            attempt_scheduler::STATUS_QUEUED,
            attempt_scheduler::STATUS_RUNNING,
            attempt_scheduler::STATUS_COLLECTED,
        ];
        foreach ($statuses as $status) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $person->id, 'status' => $status,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        $sink = $this->redirectEvents();
        $this->assertSame(2, attempt_scheduler::abort($run->id));
        // The collected attempt is untouched.
        $this->assertSame(1, $DB->count_records(
            'local_catquizlab_attempt',
            ['runid' => $run->id, 'status' => attempt_scheduler::STATUS_COLLECTED]
        ));
        $events = $sink->get_events();
        $this->assertInstanceOf(\local_catquizlab\event\run_aborted::class, end($events));
    }
}
