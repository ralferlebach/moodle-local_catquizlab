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
 * Deleting things, and not deleting the things next to them.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\purger;
use local_catquizlab\local\registry;
use local_catquizlab\local\worker_registry;

/**
 * Purger tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\purger
 */
final class purger_test extends \advanced_testcase {
    /**
     * A run with attempts.
     *
     * @param int $status The run status.
     * @param int $attempts How many attempts.
     * @param int $attemptstatus Their status.
     * @return int The run id.
     */
    protected function make_run(
        int $status = registry::STATUS_READY,
        int $attempts = 2,
        int $attemptstatus = attempt_scheduler::STATUS_QUEUED
    ): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $DB->set_field('local_catquizlab_run', 'status', $status, ['id' => $runid]);

        for ($i = 0; $i < $attempts; $i++) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => 0, 'status' => $attemptstatus, 'tries' => 0,
                'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        return $runid;
    }

    /**
     * Deleting a run takes its rows and leaves its neighbours.
     *
     * @return void
     */
    public function test_deleting_a_run_leaves_the_others(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $doomed = $this->make_run();
        $keep = $this->make_run();

        $result = purger::delete_run($doomed);

        $this->assertTrue($result['ok']);
        $this->assertFalse($DB->record_exists('local_catquizlab_run', ['id' => $doomed]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', ['runid' => $doomed]));

        // The point of a narrow delete: the run beside it is untouched.
        $this->assertTrue($DB->record_exists('local_catquizlab_run', ['id' => $keep]));
        $this->assertSame(2, $DB->count_records('local_catquizlab_attempt', ['runid' => $keep]));
    }

    /**
     * A run being played is refused, unless forced.
     *
     * @return void
     */
    public function test_a_played_run_is_refused_then_forced(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run(registry::STATUS_RUNNING, 1, attempt_scheduler::STATUS_RUNNING);

        // Deleting under a worker strands the claim it holds.
        $refused = purger::delete_run($runid);
        $this->assertFalse($refused['ok']);
        $this->assertSame('run-is-being-played', $refused['reason']);
        $this->assertTrue($DB->record_exists('local_catquizlab_run', ['id' => $runid]));

        // Forcing exists because a worker can be gone without having said so,
        // and then this is the only way out.
        $forced = purger::delete_run($runid, false, true);
        $this->assertTrue($forced['ok']);
        $this->assertFalse($DB->record_exists('local_catquizlab_run', ['id' => $runid]));
    }

    /**
     * Killing the pipeline empties it and keeps what was measured.
     *
     * @return void
     */
    public function test_killing_the_pipeline_keeps_runs_and_results(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        \local_catquizlab\task\orchestrate_run::queue($runid);
        worker_registry::acquire_slot(1, 'zombie');

        $result = purger::kill_pipeline();

        $this->assertGreaterThan(0, $result['tasks']);
        $this->assertSame(1, $result['workers']);
        $this->assertSame(2, $result['attempts']);

        // The blunt instrument, and it stops at the data: this empties the
        // pipeline, it does not discard what has been measured.
        $this->assertTrue($DB->record_exists('local_catquizlab_run', ['id' => $runid]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', [
            'runid' => $runid, 'status' => attempt_scheduler::STATUS_QUEUED,
        ]));
    }

    /**
     * Only this plugin's tasks are dropped.
     *
     * @return void
     */
    public function test_only_our_tasks_are_dropped(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        \local_catquizlab\task\orchestrate_run::queue($runid);

        $stranger = new \core\task\asynchronous_backup_task();
        $stranger->set_custom_data(['backupid' => 'abc']);
        \core\task\manager::queue_adhoc_task($stranger);

        $before = $DB->count_records('task_adhoc');
        $dropped = purger::kill_tasks();

        // Dropping somebody else's task from a plugin page would be a way to
        // break a site from a page about experiments.
        $this->assertGreaterThan(0, $dropped);
        $this->assertSame($before - $dropped, $DB->count_records('task_adhoc'));
        $this->assertTrue($DB->record_exists('task_adhoc', [
            'classname' => '\core\task\asynchronous_backup_task',
        ]));
    }

    /**
     * Clearing the registry hands back what the workers held.
     *
     * @return void
     */
    public function test_clearing_workers_releases_their_claims(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run(registry::STATUS_RUNNING, 1, attempt_scheduler::STATUS_RUNNING);
        $DB->set_field('local_catquizlab_attempt', 'leaseowner', 'gone', ['runid' => $runid]);
        worker_registry::acquire_slot(1, 'gone');

        $result = purger::kill_workers();

        $this->assertSame(1, $result['workers']);
        // A slot held by a process that is not there stays busy until the
        // heartbeat lapses, and with a long attempt that is a long wait.
        $this->assertSame(1, $result['attempts']);
        $this->assertSame(
            attempt_scheduler::STATUS_QUEUED,
            (int) $DB->get_field_sql('SELECT status FROM {local_catquizlab_attempt} WHERE runid = ?', [$runid])
        );
    }

    /**
     * Deleting results keeps the run and its traces.
     *
     * @return void
     */
    public function test_deleting_results_keeps_the_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        $DB->insert_record('local_catquizlab_result', (object) [
            'runid' => $runid, 'metric' => 'bias', 'value' => 0.5,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->assertSame(1, purger::delete_results($runid));
        $this->assertTrue($DB->record_exists('local_catquizlab_run', ['id' => $runid]));
        $this->assertSame(2, $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]));
    }

    /**
     * The preview says what would go, before anything goes.
     *
     * @return void
     */
    public function test_the_preview_counts_what_would_be_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run(registry::STATUS_READY, 3);
        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);

        $preview = purger::preview_experiment($experimentid);

        // Counting the things that go is something a person can weigh;
        // "this cannot be undone" is not.
        $this->assertTrue($preview['ok']);
        $this->assertSame(1, $preview['counts']['runs']);
        $this->assertSame(3, $preview['counts']['attempts']);
        $this->assertNotSame('', $preview['name']);
    }

    /**
     * A run being played is named before the button, not after.
     *
     * @return void
     */
    public function test_the_preview_names_what_blocks_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run(registry::STATUS_RUNNING, 1, attempt_scheduler::STATUS_RUNNING);
        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);

        $preview = purger::preview_experiment($experimentid);

        $this->assertFalse($preview['ok']);
        $this->assertNotEmpty($preview['blockers']);
        $this->assertStringContainsString((string) $runid, $preview['blockers'][0]);
    }

    /**
     * Deleting a run takes its log with it.
     *
     * @return void
     */
    public function test_deleting_a_run_removes_its_log(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run();
        \local_catquizlab\local\run_log::record($runid, \local_catquizlab\local\run_log::START_REQUESTED);

        purger::delete_run($runid);

        // The log describes a run that no longer exists; keeping it would be a
        // history of something nobody can look at. A reset is the case where it
        // must survive — and does.
        $this->assertSame(0, $DB->count_records('local_catquizlab_runlog', ['runid' => $runid]));
    }

    /**
     * Resetting takes the engine objects with it.
     *
     * @return void
     */
    public function test_reset_leaves_no_engine_objects(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run(registry::STATUS_FAILED, 2);

        // Stand in for what provisioning creates on the engine side.
        $DB->insert_record('local_catquizlab_scalemap', (object) [
            'runid' => $runid, 'level' => \local_catquizlab\local\scale_provisioner::LEVEL_ROOT,
            'catscaleid' => 4242, 'contextid' => 99,
            'categoryindex' => 0, 'subscaleindex' => 0, 'nodekey' => 'n1',
            'timecreated' => time(),
        ]);

        \local_catquizlab\local\run_lifecycle::reset($runid);

        // Clearing the scale map while leaving the scales behind loses the
        // record of which scales belonged to this run — and an engine object
        // nobody owns is not a leftover, it is a scale a later selection can
        // still find.
        $this->assertSame(0, $DB->count_records('local_catquizlab_scalemap', ['runid' => $runid]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]));
        $this->assertSame(0, (int) $DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $runid]));
    }

    /**
     * The reset preview names what would go, and what would stay.
     *
     * @return void
     */
    public function test_the_reset_preview_names_both_sides(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run(registry::STATUS_FAILED, 3);
        $preview = \local_catquizlab\local\run_lifecycle::preview_reset($runid);

        $this->assertTrue($preview['ok']);
        $this->assertSame(3, $preview['counts']['attempts']);
        // The log survives on purpose: what went wrong last time is what
        // somebody needs while looking at the next attempt.
        $this->assertContains('log', $preview['keeps']);
        $this->assertContains('seed', $preview['keeps']);
    }

    /**
     * Reset and rerun is one action, not two that can half-happen.
     *
     * @return void
     */
    public function test_reset_and_rerun_restarts_the_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run(registry::STATUS_FAILED, 1);

        $result = \local_catquizlab\local\run_lifecycle::reset_and_rerun($runid);

        // A reset that succeeded and a start that was forgotten looked exactly
        // like a run nobody had touched.
        $this->assertArrayHasKey('restarted', $result);
        $this->assertNotSame(
            registry::STATUS_FAILED,
            (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid])
        );
    }

    /**
     * Deleting needs its own authority.
     *
     * @return void
     */
    public function test_purging_is_its_own_capability(): void {
        $this->resetAfterTest();

        $capability = get_capability_info('local/catquizlab:purge');

        // Someone who may start runs and stop workers can do their whole job
        // without ever being able to destroy a measurement.
        $this->assertNotEmpty($capability);
        $this->assertNotSame(0, (int) $capability->riskbitmask & RISK_DATALOSS);

        $context = \context_system::instance();
        $operator = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/catquizlab:execute', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $operator->id, $context->id);

        $this->assertTrue(has_capability('local/catquizlab:execute', $context, $operator));
        $this->assertFalse(has_capability('local/catquizlab:purge', $context, $operator));
    }

    /**
     * A worker mid-attempt is asked to stop, not overruled.
     *
     * @return void
     */
    public function test_a_playing_worker_is_asked_to_stop(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run(registry::STATUS_RUNNING, 0);
        $experimentid = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $runid]);

        worker_registry::acquire_slot(1, 'playing');
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $runid, 'personid' => 0, 'status' => attempt_scheduler::STATUS_RUNNING,
            'tries' => 1, 'leaseowner' => 'playing', 'leaseexpires' => time() + 3600,
            'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $result = purger::delete_experiment($experimentid, true, false);

        // Forcing past it strands the claim and leaves a browser playing a quiz
        // whose questions are being deleted underneath it.
        $this->assertFalse($result['ok']);
        $this->assertSame('workers-stopping', $result['reason']);
        $this->assertTrue(worker_registry::stop_requested('playing'));
        $this->assertTrue($DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid]));
    }
}
