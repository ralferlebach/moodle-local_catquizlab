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

namespace local_catquizlab;

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\experiment_runner;
use local_catquizlab\local\experiment_status;
use local_catquizlab\local\experiment_stop;
use local_catquizlab\local\purger;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\run_orchestrator;
use local_catquizlab\local\worker_registry;

/**
 * Stopping an experiment, completely and now (0.7.49).
 *
 * Reported 9 November 2026: deleting an experiment did not stop it. Deleting asked the workers playing it to
 * finish their sitting and refused until they had, while every tick started new workers for the sittings still
 * waiting — an experiment that could be neither stopped nor deleted.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_stop
 * @covers     \local_catquizlab\local\run_lifecycle
 * @covers     \local_catquizlab\local\run_orchestrator
 * @covers     \local_catquizlab\local\purger
 * @covers     \local_catquizlab\local\experiment_runner
 * @covers     \local_catquizlab\local\experiment_status
 * @covers     \local_catquizlab\external\worker_heartbeat
 * @covers     \local_catquizlab\external\job_complete
 * @covers     \local_catquizlab\task\delete_experiment
 */
final class experiment_stop_test extends \advanced_testcase {
    /**
     * Forget this request's snapshots.
     *
     * @return void
     */
    protected function tearDown(): void {
        experiment_status::reset();
        parent::tearDown();
    }

    /**
     * A run of an experiment in a status, with sittings.
     *
     * @param int $experimentid The experiment.
     * @param int $status The run's status.
     * @param int[] $sittings Statuses of its sittings.
     * @return int The run.
     */
    protected function run_with(int $experimentid, int $status, array $sittings = []): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run(['experimentid' => $experimentid])->id;
        $DB->set_field('local_catquizlab_run', 'status', $status, ['id' => $runid]);
        foreach ($sittings as $sitting) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => 0, 'status' => $sitting, 'tries' => 0,
                'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        return $runid;
    }

    /**
     * A new experiment.
     *
     * @return int
     */
    protected function experiment(): int {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');

        return (int) $generator->create_experiment()->id;
    }

    /**
     * A sitting of a run, played by a worker right now.
     *
     * @param int $runid The run.
     * @param string $workerid The worker.
     * @return int The sitting.
     */
    protected function playing(int $runid, string $workerid): int {
        global $DB;

        worker_registry::acquire_slot(1, $workerid);

        return (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $runid, 'personid' => 0, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
            'leaseowner' => $workerid, 'leaseexpires' => time() + 3600,
            'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * An entry of the execution queue, as the tick leaves it.
     *
     * @param int $experimentid The experiment.
     * @param string $state Its state.
     * @return void
     */
    protected function queue_entry(int $experimentid, string $state): void {
        global $DB;

        $DB->insert_record('local_catquizlab_execqueue', (object) [
            'experimentid' => $experimentid, 'state' => $state, 'position' => 1, 'userid' => 0,
            'timecreated' => time(), 'timestarted' => time(),
        ]);
    }

    /**
     * A stop takes the work away: runs, sittings, workers, tasks, queue — and keeps what was measured.
     *
     * @return void
     */
    public function test_a_stop_takes_the_work_away_and_keeps_what_was_measured(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $e = $this->experiment();
        $queued = attempt_scheduler::STATUS_QUEUED;
        $ready = $this->run_with($e, registry::STATUS_READY, [$queued, $queued, $queued]);
        $running = $this->run_with($e, registry::STATUS_RUNNING, [$queued, attempt_scheduler::STATUS_COLLECTED]);
        $sitting = $this->playing($running, 'w1');
        $scheduled = $this->run_with($e, registry::STATUS_SCHEDULED);
        \local_catquizlab\task\orchestrate_run::queue($scheduled);
        $draft = $this->run_with($e, registry::STATUS_DRAFT);
        $finished = $this->run_with($e, registry::STATUS_FINISHED, [attempt_scheduler::STATUS_COLLECTED]);
        $aggregating = $this->run_with($e, registry::STATUS_AGGREGATING, [attempt_scheduler::STATUS_COLLECTED]);
        \local_catquizlab\task\aggregate_results::queue($aggregating);
        $this->queue_entry($e, execution_queue::RUNNING);

        // A sitting of another experiment is none of this stop's business.
        $other = $this->run_with($this->experiment(), registry::STATUS_READY, [$queued]);

        $this->assertGreaterThan(0, attempt_scheduler::queue_breakdown($e)['claimable']);
        $this->assertTrue(experiment_runner::state($e)['canstop']);

        $preview = experiment_stop::preview($e);
        $this->assertSame(['runs' => 4, 'queued' => 4, 'running' => 1, 'workers' => 1, 'tasks' => 1, 'queue' => 1,
            'aggregating' => 1, 'any' => true], $preview);
        $this->assertSame(
            registry::STATUS_READY,
            (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $ready]),
            'the preview changes nothing'
        );

        $stopped = experiment_stop::stop($e);
        $this->assertSame(['runs' => 4, 'queued' => 4, 'running' => 1, 'workers' => 1, 'tasks' => 1, 'queue' => 1,
            'aggregating' => 1], $stopped);

        // Every run that could build or hand out work is cancelled; finished and aggregating ones are not.
        $status = fn(int $runid): int => (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]);
        foreach ([$ready, $running, $scheduled, $draft] as $runid) {
            $this->assertSame(registry::STATUS_CANCELLED, $status($runid), "run {$runid}");
        }
        $this->assertSame(registry::STATUS_FINISHED, $status($finished));
        $this->assertSame(registry::STATUS_AGGREGATING, $status($aggregating));

        // Nothing is claimable, nothing open; collected sittings stay collected.
        $breakdown = attempt_scheduler::queue_breakdown($e);
        $this->assertSame(0, $breakdown['claimable']);
        $this->assertSame(0, $breakdown['running']);
        $this->assertSame(3, $breakdown['collected']);
        $closed = $DB->get_records_select(
            'local_catquizlab_attempt',
            'runid IN (?, ?) AND status = ?',
            [$ready, $running, attempt_scheduler::STATUS_FAILED]
        );
        $this->assertCount(5, $closed);
        foreach ($closed as $attempt) {
            $this->assertSame(get_string('history:experimentstopped', 'local_catquizlab'), $attempt->lasterror);
            $this->assertNull($attempt->leaseowner);
        }

        // The worker that held a sitting is asked to exit, and its sitting's history says it was a stop.
        $this->assertTrue(worker_registry::stop_requested('w1'));
        $history = $DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $sitting]);
        $this->assertSame(attempt_history::ABANDONED, end($history)->outcome);
        $this->assertSame('w1', end($history)->workerid);

        // Setup tasks gone, aggregation kept; out of the execution queue.
        $tasks = $DB->get_fieldset_select('task_adhoc', 'classname', "component = 'local_catquizlab'");
        $this->assertSame(['\local_catquizlab\task\aggregate_results'], array_values($tasks));
        $this->assertNull(execution_queue::state_of($e));

        // The other experiment is untouched.
        $this->assertSame(registry::STATUS_READY, $status($other));
        $this->assertSame(1, attempt_scheduler::queue_breakdown()['claimable']);

        // Idempotent.
        $again = experiment_stop::stop($e);
        $this->assertSame(0, $again['runs'] + $again['queued'] + $again['running'] + $again['workers'] + $again['tasks']
            + $again['queue']);
        $this->assertFalse(experiment_stop::preview($e)['any']);
        $this->assertFalse(experiment_runner::state($e)['canstop']);
    }

    /**
     * A worker in the middle of a sitting learns at its next heartbeat that the sitting is gone, and is told to stop.
     *
     * @return void
     */
    public function test_a_worker_mid_sitting_loses_it_at_its_next_heartbeat(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $e = $this->experiment();
        $runid = $this->run_with($e, registry::STATUS_RUNNING);
        $sitting = $this->playing($runid, 'w2');
        $this->assertTrue(attempt_scheduler::renew_lease('w2', $sitting), 'held before the stop');

        experiment_stop::stop($e);

        // What the heartbeat service answers: the lease is lost (the answer loop stops before its next answer)
        // and it is asked to stop (it exits after reporting).
        $reply = \local_catquizlab\external\worker_heartbeat::execute('w2', $sitting, 'working');
        $this->assertTrue($reply['leaselost']);
        $this->assertSame('closed', $reply['leasefate'], 'its log says the sitting was closed, not requeued');
        $this->assertTrue($reply['stop']);

        // What it reports afterwards is recorded as late and not applied: the sitting stays closed.
        $done = \local_catquizlab\external\job_complete::execute($sitting, 'failed', 0, 0, 'lease lost', '', 'w2');
        $this->assertFalse($done['acknowledged']);
        $this->assertSame(
            attempt_scheduler::STATUS_FAILED,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $sitting])
        );
        $this->assertSame(0, attempt_scheduler::queue_breakdown($e)['claimable'], 'and not requeued');
    }

    /**
     * A setup that was running when the stop came does not put the run back into play.
     *
     * @return void
     */
    public function test_a_setup_does_not_undo_a_stop(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $e = $this->experiment();
        $runid = $this->run_with($e, registry::STATUS_SCHEDULED);
        experiment_stop::stop($e);

        // The setup task that was already running finishes: its last stage planned sittings, and it reports success.
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $runid, 'personid' => 0, 'status' => attempt_scheduler::STATUS_QUEUED, 'tries' => 0,
            'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        run_lifecycle::provisioned($runid, true);

        $this->assertSame(
            registry::STATUS_CANCELLED,
            (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]),
            'not ready again'
        );
        $this->assertSame(0, attempt_scheduler::queue_breakdown($e)['claimable']);
        $this->assertSame(0, $DB->count_records(
            'local_catquizlab_attempt',
            ['runid' => $runid, 'status' => attempt_scheduler::STATUS_QUEUED]
        ));

        // A setup that comes round after the stop builds nothing.
        $result = run_orchestrator::setup($runid);
        $this->assertFalse($result['ok']);
        $this->assertSame(run_orchestrator::REASON_STOPPED, $result['reason']);
        $this->assertTrue($result['concurrent'], 'the task leaves the run\'s state to the stop');
        $this->assertSame(registry::STATUS_CANCELLED, (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]));

        // Nor does a failed setup call the stop a failure.
        run_lifecycle::provisioned($runid, false, 'people stage failed');
        $this->assertSame(registry::STATUS_CANCELLED, (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]));
    }

    /**
     * A stopped experiment says "Stopped", not "Needs attention" or "Complete, with failures".
     *
     * @return void
     */
    public function test_a_stopped_experiment_is_said_to_be_stopped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $e = $this->experiment();
        $this->run_with($e, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED]);
        experiment_stop::stop($e);

        $state = experiment_runner::state($e);
        $this->assertSame(experiment_runner::STATE_STOPPED, $state['state']);
        $this->assertSame(get_string('runner:statestopped', 'local_catquizlab'), $state['label']);
        $snapshot = experiment_status::snapshot($e, true);
        $this->assertSame('stopped', $snapshot['phase']);
        $this->assertSame(get_string('status:phasestopped', 'local_catquizlab'), $snapshot['label']);
        $this->assertSame('progress', $snapshot['next']['kind'], 'nothing finished: no results to show');
        $this->assertSame('warn', $snapshot['tone']);
        $this->assertSame('stopped', experiment_status::library([$e])[$e]['phase']);

        // With a finished run, its results are the way on.
        $withresults = $this->experiment();
        $this->run_with($withresults, registry::STATUS_FINISHED, [attempt_scheduler::STATUS_COLLECTED]);
        $this->run_with($withresults, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED]);
        experiment_stop::stop($withresults);
        $this->assertSame('stopped', experiment_status::snapshot($withresults, true)['phase']);
        $this->assertSame('results', experiment_status::snapshot($withresults, true)['next']['kind']);
        $this->assertSame('stopped', experiment_status::library([$withresults])[$withresults]['phase']);

        // A run that failed is still a problem, stop or not.
        $failed = $this->experiment();
        $this->run_with($failed, registry::STATUS_FAILED);
        $this->run_with($failed, registry::STATUS_READY);
        experiment_stop::stop($failed);
        $this->assertSame(experiment_runner::STATE_BLOCKED, experiment_runner::state($failed)['state']);
    }

    /**
     * The progress page offers the stop while there is work, and only then.
     *
     * @return void
     */
    public function test_the_progress_page_offers_the_stop(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/catquizlab/runs.php');
        $PAGE->set_context(\context_system::instance());

        $e = $this->experiment();
        $this->run_with($e, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED]);
        $render = function (int $experimentid) use ($PAGE): string {
            experiment_status::reset();
            $context = \local_catquizlab\local\progress_view::context($experimentid);
            return $PAGE->get_renderer('core')->render_from_template('local_catquizlab/progress', $context);
        };

        $html = $render($e);
        $this->assertStringContainsString('name="action" value="stopexperiment"', $html);
        $this->assertStringContainsString(get_string('runner:stop', 'local_catquizlab'), $html);

        experiment_stop::stop($e);
        $this->assertStringNotContainsString('value="stopexperiment"', $render($e));

        // Runs created and never started: nothing runs, so nothing to stop.
        $created = $this->experiment();
        $this->run_with($created, registry::STATUS_DRAFT);
        $this->assertFalse(experiment_runner::state($created)['canstop']);
        $this->assertStringNotContainsString('value="stopexperiment"', $render($created));

        // Without the authority to execute, no button.
        $viewer = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/catquizlab:view', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $viewer->id, \context_system::instance()->id);
        $running = $this->experiment();
        $this->run_with($running, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED]);
        $this->setUser($viewer);
        $this->assertStringNotContainsString('value="stopexperiment"', $render($running));
    }

    /**
     * Deleting an experiment that is being played stops it and deletes it, in one go.
     *
     * @return void
     */
    public function test_deleting_a_played_experiment_stops_and_deletes_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $e = $this->experiment();
        $runid = $this->run_with($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED]);
        $this->playing($runid, 'w3');
        $this->queue_entry($e, execution_queue::RUNNING);

        $this->assertNotEmpty(purger::preview_experiment($e)['stopping'], 'said before the button is pressed');

        $result = purger::delete_experiment($e, false, false);

        $this->assertTrue($result['ok'], 'not refused while a worker plays');
        $this->assertSame(1, $result['stopped']['runs']);
        $this->assertSame(1, $result['stopped']['running']);
        $this->assertFalse($DB->record_exists('local_catquizlab_experiment', ['id' => $e]));
        $this->assertFalse($DB->record_exists('local_catquizlab_run', ['id' => $runid]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]));
        $this->assertTrue(worker_registry::stop_requested('w3'), 'its worker exits');
        $this->assertNull(execution_queue::state_of($e));
        $this->assertSame(0, attempt_scheduler::queue_breakdown()['claimable'], 'no tick starts a worker for it');
    }
    /**
     * Deleting runs in the background: the click stops, the task deletes, and running it twice does no harm.
     *
     * @return void
     */
    public function test_deleting_runs_in_the_background(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $e = $this->experiment();
        $runid = $this->run_with($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED]);

        // What experiment.php does on the confirmed POST.
        experiment_stop::stop($e);
        \local_catquizlab\task\delete_experiment::queue($e, true);

        $this->assertSame([$e], \local_catquizlab\task\delete_experiment::pending(), 'the library says "being deleted"');
        $this->assertTrue($DB->record_exists('local_catquizlab_experiment', ['id' => $e]), 'not yet');
        $this->assertSame(0, attempt_scheduler::queue_breakdown($e)['claimable'], 'but nothing runs any more');

        // A second click queues nothing more.
        \local_catquizlab\task\delete_experiment::queue($e, true);
        $this->assertSame(1, $DB->count_records('task_adhoc', ['classname' => '\\local_catquizlab\\task\\delete_experiment']));
        $this->expectOutputRegex('/experiment ' . $e . ' deleted in /');
        $this->runAdhocTasks(\local_catquizlab\task\delete_experiment::class);

        // Run again after it is gone (a retry, a delete from the console in between): nothing to do, no error.
        $again = new \local_catquizlab\task\delete_experiment();
        $again->set_custom_data(['experimentid' => $e, 'deep' => true]);
        $again->execute();

        $this->assertFalse($DB->record_exists('local_catquizlab_experiment', ['id' => $e]));
        $this->assertFalse($DB->record_exists('local_catquizlab_run', ['id' => $runid]));
        $this->assertSame([], \local_catquizlab\task\delete_experiment::pending());
    }

    /**
     * The experiment says it is being deleted, and a delete whose task failed or vanished says so (0.7.50).
     *
     * @return void
     */
    public function test_the_experiment_keeps_its_deleting_state(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $e = $this->experiment();
        $this->run_with($e, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED]);
        \local_catquizlab\task\delete_experiment::queue($e, false);

        $states = \local_catquizlab\task\delete_experiment::states();
        $this->assertTrue($states[$e]['pending']);
        $this->assertGreaterThan(0, $states[$e]['since']);
        $this->assertSame('', $states[$e]['error']);
        $this->assertSame('being-deleted', execution_queue::enqueue($e)['reason'], 'not started again meanwhile');

        // A failure is kept where the list shows it, and the task fails so Moodle retries it.
        $task = new class extends \local_catquizlab\task\delete_experiment {
            /**
             * Record a failure as execute() does.
             *
             * @param int $id The experiment.
             * @param string $message The error.
             * @return void
             */
            public function fail(int $id, string $message): void {
                self::record_error($id, $message);
            }
        };
        $task->fail($e, 'Lock wait timeout exceeded');
        $this->assertSame('Lock wait timeout exceeded', \local_catquizlab\task\delete_experiment::states()[$e]['error']);

        // The task gone (given up after its retries, or removed by hand): no longer "being deleted" but "failed",
        // and offered for deleting again.
        $DB->delete_records('task_adhoc', ['classname' => '\\local_catquizlab\\task\\delete_experiment']);
        $this->assertFalse(\local_catquizlab\task\delete_experiment::states()[$e]['pending']);

        // Queued again: the old error is cleared.
        \local_catquizlab\task\delete_experiment::queue($e, false);
        $this->assertSame('', \local_catquizlab\task\delete_experiment::states()[$e]['error']);
        $this->assertTrue(\local_catquizlab\task\delete_experiment::states()[$e]['pending']);
    }

    /**
     * Deleting leaves no row behind that points at the deleted run or experiment (0.7.50).
     *
     * @return void
     */
    public function test_deleting_leaves_nothing_pointing_at_the_experiment(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $e = $this->experiment();
        $runid = $this->run_with($e, registry::STATUS_FINISHED, [attempt_scheduler::STATUS_COLLECTED]);
        $attemptid = (int) $DB->get_field('local_catquizlab_attempt', 'id', ['runid' => $runid]);
        \local_catquizlab\local\attempt_history::record($attemptid, \local_catquizlab\local\attempt_history::STARTED);
        $DB->insert_record('local_catquizlab_twinclaim', (object) ['runid' => $runid, 'twinid' => 't1', 'personid' => 2,
            'timecreated' => time()]);
        $DB->insert_record('local_catquizlab_pool', (object) ['experimentid' => $e, 'runid' => $runid,
            'variant' => 'ideal', 'timecreated' => time()]);
        $DB->insert_record('local_catquizlab_pool', (object) ['experimentid' => $e, 'runid' => null,
            'variant' => 'ideal', 'timecreated' => time()]);
        $DB->insert_record('local_catquizlab_exportlog', (object) ['runid' => $runid, 'experimentid' => $e,
            'format' => 'csv', 'dataset' => 'answermatrix', 'timecreated' => time()]);
        $DB->insert_record('local_catquizlab_transfer', (object) ['runid' => $runid, 'direction' => 'submit',
            'remotehost' => 'https://hub.example', 'payloadhash' => str_repeat('a', 64), 'timecreated' => time()]);
        $this->queue_entry($e, execution_queue::FINISHED);
        $context = \context_system::instance();
        $fs = get_file_storage();
        foreach (['run-' . $runid . '-answermatrix.csv', 'run-' . ($runid + 1000) . '-answermatrix.csv'] as $name) {
            $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'local_catquizlab',
                'filearea' => 'export', 'itemid' => 0, 'filepath' => '/', 'filename' => $name], 'x');
        }

        $preview = purger::preview_experiment($e);
        $this->assertSame(1, $preview['counts']['twinclaims'] ?? 0, 'the preview names what goes');
        $this->assertGreaterThanOrEqual(1, $preview['counts']['history'] ?? 0);

        $result = purger::delete_experiment($e, false, true);

        $this->assertTrue($result['ok']);
        foreach (['attemptlog', 'twinclaim', 'pool', 'exportlog', 'transfer', 'result', 'scalemap'] as $table) {
            $this->assertSame(0, $DB->count_records('local_catquizlab_' . $table, ['runid' => $runid]), $table);
        }
        foreach (['pool', 'exportlog', 'execqueue'] as $table) {
            $this->assertSame(0, $DB->count_records('local_catquizlab_' . $table, ['experimentid' => $e]), $table);
        }
        $this->assertFalse($fs->file_exists(
            $context->id,
            'local_catquizlab',
            'export',
            0,
            '/',
            'run-' . $runid . '-answermatrix.csv'
        ), 'its export file goes');
        $this->assertTrue($fs->file_exists(
            $context->id,
            'local_catquizlab',
            'export',
            0,
            '/',
            'run-' . ($runid + 1000) . '-answermatrix.csv'
        ), 'another run\'s stays');
    }
}
