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

namespace local_catquizlab;

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\experiment_resume;
use local_catquizlab\local\experiment_runner;
use local_catquizlab\local\experiment_status;
use local_catquizlab\local\experiment_stop;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_log;
use local_catquizlab\local\setup_wizard;

/**
 * A stopped experiment goes on, keeping everything it collected (#165).
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_resume
 * @covers     \local_catquizlab\local\experiment_runner
 * @covers     \local_catquizlab\local\experiment_status
 * @covers     \local_catquizlab\output\resume_view
 */
final class experiment_resume_test extends \advanced_testcase {
    /** @var \local_catquizlab_generator */
    protected $generator;

    /**
     * Admin, generator, a site ready to run.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
    }

    /**
     * A provisioned run with its activity, people and sittings in the given statuses.
     *
     * @param int $experimentid The experiment.
     * @param int $status The run's status.
     * @param array $sittings Sitting status => how many.
     * @param bool $bytask Prepared by the provisioning task ("provisioning_ready"), or in one go (e2e_prepare, the setup
     *      assistant), which only logs its status changing to ready.
     * @return int The run.
     */
    protected function provisioned(int $experimentid, int $status, array $sittings, bool $bytask = true): int {
        global $DB;
        $runid = (int) $this->generator->create_run(['experimentid' => $experimentid, 'status' => $status])->id;
        $course = $this->getDataGenerator()->create_course();
        $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
            'instance' => 5000 + $runid, 'section' => 0, 'added' => time()]);
        $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $runid]);
        if ($bytask) {
            run_log::record($runid, run_log::PROVISIONING_READY);
        } else {
            run_log::record($runid, run_log::STATUS_CHANGED, ['from' => 'scheduled', 'to' => 'ready', 'why' => 'e2e_prepared']);
        }
        $rows = [];
        $n = 0;
        foreach ($sittings as $sittingstatus => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = (object) ['runid' => $runid, 'personid' => 800000 + $runid * 10000 + $n++,
                    'status' => $sittingstatus, 'tries' => $sittingstatus === attempt_scheduler::STATUS_QUEUED ? 0 : 1,
                    'nextruntime' => 0, 'timecreated' => time() - 3600 + $n, 'timemodified' => time(),
                    'tracejson' => $sittingstatus === attempt_scheduler::STATUS_COLLECTED ? '{"theta":' . $n . '}' : null];
            }
        }
        $DB->insert_records('local_catquizlab_attempt', $rows);

        return $runid;
    }

    /**
     * The live case (AK9): stopped with 1018 of 6000 collected; resumed, the 1018 stay exactly as they were, the 4982
     * the stop closed are played again in their order, the runs are ready, the experiment holds a place in the line;
     * the numbers only grow. A second resume finds nothing.
     *
     * @return void
     */
    public function test_resume_keeps_the_1018_and_plays_the_4982_the_stop_closed(): void {
        global $DB;
        $e = (int) $this->generator->create_experiment()->id;
        $runs = [
            86 => $this->provisioned($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_COLLECTED => 997,
                attempt_scheduler::STATUS_QUEUED => 3]),
            89 => $this->provisioned($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_COLLECTED => 21,
                attempt_scheduler::STATUS_QUEUED => 979]),
        ];
        foreach ([84, 85, 87, 88] as $label) {
            $runs[$label] = $this->provisioned($e, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED => 1000]);
        }
        experiment_stop::stop($e);
        $this->assertSame('stopped', experiment_status::snapshot($e, true)['phase']);
        $this->assertTrue(experiment_runner::state($e)['canresume']);
        $next = experiment_status::snapshot($e, true)['next'];
        $this->assertSame(['resume', 'resumeexperiment', true], [$next['kind'], $next['action'], $next['post']]);
        $collected = $DB->get_records_select_menu(
            'local_catquizlab_attempt',
            'status = ?',
            [attempt_scheduler::STATUS_COLLECTED],
            'id ASC',
            'id, tracejson'
        );
        $this->assertCount(1018, $collected);

        // Shown first, run by run (AK2).
        $preview = experiment_resume::preview($e);
        $this->assertTrue($preview['resumable']);
        $this->assertSame([6, 6000, 1018, 0, 4982, 4982, 0], [$preview['totals']['runs'], $preview['totals']['planned'],
            $preview['totals']['collected'], $preview['totals']['failed'], $preview['totals']['cancelled'],
            $preview['totals']['requeue'], $preview['totals']['blocked']]);
        $byrun = array_column($preview['runs'], null, 'runid');
        $this->assertSame([997, 3, experiment_resume::RESUME], [$byrun[$runs[86]]['collected'],
            $byrun[$runs[86]]['requeue'], $byrun[$runs[86]]['action']]);
        $html = \local_catquizlab\output\resume_view::preview_table($preview);
        $this->assertStringContainsString('data-region="catquizlab-resume-preview"', $html);
        $this->assertStringContainsString('<caption', $html);
        $this->assertStringContainsString('scope="row"', $html);

        $result = experiment_resume::resume($e);
        $this->assertTrue($result['ok']);
        $this->assertSame([6, 4982, 0, 0], [$result['runs'], $result['requeued'], $result['collecting'],
            $result['blocked']]);

        // The 1018: the same rows, the same traces (AK3).
        $this->assertSame($collected, $DB->get_records_select_menu(
            'local_catquizlab_attempt',
            'status = ?',
            [attempt_scheduler::STATUS_COLLECTED],
            'id ASC',
            'id, tracejson'
        ));
        // The 4982: queued again, nothing left cancelled, nothing failed; each says why in its history.
        $this->assertSame(4982, $DB->count_records(
            'local_catquizlab_attempt',
            ['status' => attempt_scheduler::STATUS_QUEUED]
        ));
        $this->assertSame(0, $DB->count_records_select(
            'local_catquizlab_attempt',
            'status IN (?, ?)',
            [attempt_scheduler::STATUS_CANCELLED, attempt_scheduler::STATUS_FAILED]
        ));
        $this->assertSame([4982, 4982], [
            $DB->count_records('local_catquizlab_attemptlog', ['outcome' => attempt_history::CANCELLED,
                'reasoncode' => experiment_stop::REASON]),
            $DB->count_records('local_catquizlab_attemptlog', ['outcome' => attempt_history::RESUMED,
                'reasoncode' => experiment_resume::REASON]),
        ], 'each closed sitting: cancelled by the stop, then resumed, each with its code');
        foreach ($runs as $runid) {
            $this->assertSame(registry::STATUS_READY, (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]));
        }
        // In the line like any started experiment (#163), and played from there.
        $this->assertTrue($result['queue']['ok']);
        $this->assertNotNull(execution_queue::state_of($e));
        $this->assertNotSame('stopped', experiment_status::snapshot($e, true)['phase']);
        execution_queue::advance();
        $this->assertSame($e, execution_queue::holder());

        // Idempotent: nothing left to resume, nothing changed.
        $again = experiment_resume::resume($e);
        $this->assertSame([false, 'nothing'], [$again['ok'], $again['reason']]);
        $this->assertFalse(experiment_runner::state($e)['canresume']);
    }

    /**
     * What cannot go on is said, not guessed at (AK5): a run stopped while it was being prepared, one whose activity is
     * gone; a run nobody had started is a draft again. The others go on.
     *
     * @return void
     */
    public function test_what_cannot_go_on_is_named_and_a_never_started_run_is_prepared(): void {
        global $DB;
        $e = (int) $this->generator->create_experiment()->id;
        $ok = $this->provisioned($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED => 2], false);
        $gone = $this->provisioned($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED => 2]);
        $DB->delete_records('course_modules', ['id' => $DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $gone])]);
        $partial = (int) $this->generator->create_run(['experimentid' => $e, 'status' => registry::STATUS_SCHEDULED])->id;
        $this->generator->create_person(['runid' => $partial]);
        $draft = (int) $this->generator->create_run(['experimentid' => $e, 'status' => registry::STATUS_DRAFT])->id;
        experiment_stop::stop($e);

        $byrun = array_column(experiment_resume::preview($e)['runs'], null, 'runid');
        $this->assertSame(
            [[experiment_resume::RESUME, ''], [experiment_resume::BLOCKED, 'noactivity'],
                [experiment_resume::BLOCKED, 'partial'], [experiment_resume::PREPARE, '']],
            array_map(static fn(int $r): array => [$byrun[$r]['action'], $byrun[$r]['blocker']], [$ok, $gone, $partial,
                $draft])
        );
        $result = experiment_resume::resume($e);
        $this->assertSame([2, 1, 2], [$result['runs'], $result['prepared'], $result['blocked']]);
        $status = static fn(int $r): int => (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $r]);
        $this->assertSame([registry::STATUS_READY, registry::STATUS_CANCELLED, registry::STATUS_CANCELLED,
            registry::STATUS_DRAFT], [$status($ok), $status($gone), $status($partial), $status($draft)]);
        $this->assertSame(2, $DB->count_records('local_catquizlab_attempt', ['runid' => $gone,
            'status' => attempt_scheduler::STATUS_CANCELLED]), 'a blocked run keeps its sittings as they were');
        // The destructive way, apart: what it would delete, counted (AK6).
        $inventory = experiment_resume::restart_inventory($e);
        $this->assertSame([2, 2, 1], [$inventory['totals']['runs'], $inventory['totals']['sittings'],
            $inventory['totals']['persons']]);
        $this->assertStringContainsString(
            'data-region="catquizlab-restart-inventory"',
            \local_catquizlab\output\resume_view::restart_table($inventory)
        );
    }

    /**
     * A sitting finished in the engine after the stop, by a worker that had not heard of it, is collected, not played
     * again; one whose engine attempt is in progress is queued and its attempt continued by the next worker (AK4).
     *
     * @return void
     */
    public function test_a_sitting_finished_after_the_stop_is_collected_not_played_again(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('Needs mod_adaptivequiz.');
        }
        $e = (int) $this->generator->create_experiment()->id;
        $runid = $this->provisioned($e, registry::STATUS_RUNNING, []);
        $instance = (int) $DB->get_field_sql('SELECT cm.instance FROM {course_modules} cm JOIN {local_catquizlab_run} r
            ON r.testcmid = cm.id WHERE r.id = ?', [$runid]);
        $sittings = [];
        foreach (['complete', 'inprogress'] as $state) {
            $user = $this->getDataGenerator()->create_user();
            $person = $this->generator->create_person(['runid' => $runid, 'moodleuserid' => $user->id]);
            $sittings[$state] = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid,
                'personid' => $person->id, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
                'leaseowner' => 'w-' . $state, 'leaseexpires' => time() + 600, 'timecreated' => time() - 900,
                'timemodified' => time() - 900]);
            $engine[$state] = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => $instance,
                'userid' => $user->id, 'uniqueid' => 0, 'attemptstate' => 'inprogress', 'attemptstopcriteria' => '',
                'questionsattempted' => 7, 'timecreated' => time() - 900, 'timemodified' => time() - 60]);
        }
        experiment_stop::stop($e);
        // The worker of the first finishes its engine attempt after the stop; its report is not applied (#133).
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $engine['complete']]);

        $preview = experiment_resume::preview($e);
        $this->assertSame([1, 1, 1], [$preview['totals']['finishedafter'], $preview['totals']['inprogress'],
            $preview['totals']['requeue']]);
        $result = experiment_resume::resume($e);
        $this->assertSame([1, 1], [$result['collecting'], $result['requeued']]);
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $sittings['complete']]);
        $this->assertSame(
            [attempt_scheduler::STATUS_TRACEPENDING, $engine['complete'], 1],
            [(int) $row->status, (int) $row->engineattemptid, (int) $row->tries],
            'collected from the engine, no try spent'
        );
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $sittings['inprogress']]
        ), 'played on: the worker continues its engine attempt (#136)');
        // A late report of the worker that held it before the stop does not touch it now.
        $late = \local_catquizlab\external\job_complete::execute(
            $sittings['inprogress'],
            'failed',
            0,
            0,
            'lease lost',
            '',
            'w-inprogress'
        );
        $this->assertFalse($late['acknowledged']);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $sittings['inprogress']]
        ));
    }

    /**
     * A run cancelled on its own, in an experiment that goes on, goes on on its own (AK1): the other runs are not
     * touched, the experiment keeps its place in the line.
     *
     * @return void
     */
    public function test_a_run_cancelled_on_its_own_goes_on_on_its_own(): void {
        global $DB;
        $e = (int) $this->generator->create_experiment()->id;
        $playing = $this->provisioned($e, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED => 5]);
        $cancelled = $this->provisioned($e, registry::STATUS_READY, [attempt_scheduler::STATUS_COLLECTED => 2,
            attempt_scheduler::STATUS_QUEUED => 3]);
        $this->assertTrue(execution_queue::enqueue($e)['ok']);
        $position = (int) $DB->get_field('local_catquizlab_execqueue', 'position', ['experimentid' => $e]);
        experiment_stop::close_sittings([$cancelled]);
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_CANCELLED, ['id' => $cancelled]);

        $preview = experiment_resume::preview($e, $cancelled);
        $this->assertSame([1, $cancelled, 3], [$preview['totals']['runs'], $preview['runs'][0]['runid'],
            $preview['totals']['requeue']]);
        $result = experiment_resume::resume($e, $cancelled);
        $this->assertSame([true, 1, 3, 'already-queued'], [$result['ok'], $result['runs'], $result['requeued'],
            $result['queue']['reason']]);
        $this->assertSame(registry::STATUS_READY, (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $cancelled]));
        $this->assertSame(2, $DB->count_records('local_catquizlab_attempt', ['runid' => $cancelled,
            'status' => attempt_scheduler::STATUS_COLLECTED]));
        $this->assertSame(5, $DB->count_records('local_catquizlab_attempt', ['runid' => $playing,
            'status' => attempt_scheduler::STATUS_QUEUED]), 'the other run is not touched');
        $this->assertSame([1, $position], [$DB->count_records('local_catquizlab_execqueue', ['experimentid' => $e]),
            (int) $DB->get_field('local_catquizlab_execqueue', 'position', ['experimentid' => $e])]);
    }

    /**
     * A hundred stopped runs go on in one action, not a hundred clicks (AK7), in one transaction.
     *
     * @return void
     */
    public function test_a_hundred_runs_go_on_in_one_action(): void {
        global $DB;
        $e = (int) $this->generator->create_experiment()->id;
        for ($i = 0; $i < 100; $i++) {
            $this->provisioned($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_COLLECTED => 1,
                attempt_scheduler::STATUS_QUEUED => 2]);
        }
        experiment_stop::stop($e);
        $queries = $DB->perf_get_queries();
        $result = experiment_resume::resume($e);
        $this->assertSame([true, 100, 200], [$result['ok'], $result['runs'], $result['requeued']]);
        $this->assertSame(100, $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_COLLECTED]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_run', ['experimentid' => $e,
            'status' => registry::STATUS_CANCELLED]));
        $this->assertLessThan(3000, $DB->perf_get_queries() - $queries, 'bounded per run, not per sitting');
    }
}
