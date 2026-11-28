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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

use adaptivequizcatmodel_catquiz\trace_scenario;
use local_catquizlab\external\job_complete;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\completion_contract;
use local_catquizlab\local\engine_identity;
use local_catquizlab\local\engine_validity;
use local_catquizlab\local\experiment_resume;
use local_catquizlab\local\experiment_stop;
use local_catquizlab\local\registry;
use local_catquizlab\local\result_validity;
use local_catquizlab\local\run_log;
use local_catquizlab\local\setup_wizard;

/**
 * The engine's time limit on a real engine attempt (P0 "[ENGINE-LIFECYCLE]" DoD03, re-audit 27.11.2026).
 *
 * CLARA run 103: the classical attempts of 2500 items were closed by local_catquiz's clean-up task
 * (cancel_expired_attempts) 24 hours after they began — the lab's tests set no time limit of their own, so the engine's
 * default for tests without one applies, counted from the attempt's creation and not stopped by a pause. Here the same
 * task closes a real attempt of the engine, without waiting a day: the attempt's creation is set back in the test
 * database (a controlled test clock, nothing else of the engine changed). Then the lab reports, collects, resumes — and
 * must keep the attempt as a diagnosis, never as a result, never start a new attempt by itself.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\completion_contract
 * @covers     \local_catquizlab\local\attempt_collector
 * @covers     \local_catquizlab\external\job_complete
 * @covers     \local_catquizlab\local\experiment_resume
 */
final class host_timeout_test extends \advanced_testcase {
    /** @var trace_scenario The engine's activity, its pool and a participant. */
    private trace_scenario $scenario;

    /**
     * Admin, a site ready to run.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK=true');
        setup_wizard::assume_ready_for_testing(true);
    }

    /**
     * The site as it was.
     *
     * @return void
     */
    protected function tearDown(): void {
        setup_wizard::assume_ready_for_testing(null);
        parent::tearDown();
    }

    /**
     * A classical run on the engine's activity: its pool as the run's form, the participant as its person, one sitting
     * held by execution 1 of worker "w1", its engine attempt begun and bound, two questions answered.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} Sitting, engine attempt, run, experiment.
     */
    protected function classical_sitting(): array {
        global $DB;

        if (!class_exists('\local_catquiz\task\cancel_expired_attempts')) {
            $this->markTestSkipped('Needs local_catquiz with its clean-up task.');
        }
        $this->scenario = trace_scenario::create($this->getDataGenerator(), false, ['catquiz_maxquestions' => 40]);
        /** @var \local_catquizlab_generator $lab */
        $lab = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $lab->create_experiment()->id;
        $runid = (int) $lab->create_run(['experimentid' => $experimentid, 'status' => registry::STATUS_RUNNING,
            'testcmid' => (int) $this->scenario->adaptivequiz->cm->id,
            'manifestjson' => json_encode(['config' => ['definition' => ['strategy' => 'classic']]])])->id;
        run_log::record($runid, run_log::PROVISIONING_READY);
        // The run's form: every question of the engine's pool.
        foreach ($DB->get_fieldset_select('local_catquiz_items', 'componentid', "componentname = 'question'") as $q) {
            $DB->insert_record('local_catquizlab_item', (object) ['runid' => $runid, 'questionid' => (int) $q,
                'itemname' => 'q' . $q, 'timecreated' => time(), 'timemodified' => time()]);
        }
        $personid = (int) $DB->insert_record('local_catquizlab_person', (object) ['runid' => $runid, 'twinindex' => 1,
            'severity' => 'none', 'stratum' => 'conforming', 'moodleuserid' => $this->scenario->userid,
            'timecreated' => time(), 'timemodified' => time()]);
        $sitting = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid,
            'personid' => $personid, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1, 'executionid' => 1,
            'leaseowner' => 'w1', 'leaseexpires' => time() + 600, 'timecreated' => time(), 'timemodified' => time()]);

        // The first question shown, two answered: an attempt in progress, played as its participant.
        $this->setUser($this->scenario->userid);
        $first = $this->scenario->request(null);
        $this->assertNotNull($first['questionid']);
        $this->scenario->request(true);
        $this->scenario->request(false);
        $this->setAdminUser();
        $engine = (int) $DB->get_field('adaptivequiz_attempt', 'MAX(id)', ['userid' => $this->scenario->userid]);
        $usage = (int) $DB->get_field('adaptivequiz_attempt', 'uniqueid', ['id' => $engine]);
        $this->assertTrue(engine_identity::bind($sitting, $usage, 'w1', 1)['found']);

        return [$sitting, $engine, $runid, $experimentid];
    }

    /**
     * The engine's clean-up task, as cron runs it, once the attempt began more than the default limit ago.
     *
     * @param int $engine The engine attempt.
     * @return void
     */
    protected function expire(int $engine): void {
        global $DB;

        set_config('maximum_attempt_duration_hours', 24, 'local_catquiz');
        $DB->set_field('adaptivequiz_attempt', 'timecreated', time() - 25 * HOURSECS, ['id' => $engine]);
        ob_start();
        (new \local_catquiz\task\cancel_expired_attempts())->execute();
        $out = ob_get_clean();
        $this->assertStringContainsString('marked 1 as completed', $out);
        $this->assertSame('complete', $DB->get_field('adaptivequiz_attempt', 'attemptstate', ['id' => $engine]));
    }

    /**
     * Closed by the time limit while the worker plays it: its report collects the attempt as a technical end — the
     * trace kept, never a result, no new attempt.
     *
     * @return void
     */
    public function test_the_time_limit_ends_a_sitting_as_a_technical_end(): void {
        global $DB;
        [$sitting, $engine] = $this->classical_sitting();
        $this->expire($engine);
        $columns = $DB->get_columns('adaptivequiz_attempt');
        if (isset($columns['stopcode'])) {
            $this->assertSame('timelimit', $DB->get_field('adaptivequiz_attempt', 'stopcode', ['id' => $engine]));
        }

        // The worker finds it ended (its finish page or the server's word) and reports.
        $answer = job_complete::execute($sitting, 'finished', 1000, $engine, '', '', 'w1', '', 1);
        $this->assertTrue($answer['acknowledged']);
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $sitting]);
        $this->assertContains((int) $row->status, [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_TRACEPENDING]);
        $verdict = json_decode((string) $row->completionjson, true);
        $this->assertSame(completion_contract::TECHNICAL, $verdict['class'], $verdict['why']);
        $this->assertSame(completion_contract::HOST_TIMEOUT, $verdict['technical']);
        $this->assertSame(completion_contract::RECOVERY_KEEP, $verdict['recovery']);
        $this->assertSame('worker-report', $verdict['source']);
        $this->assertSame($engine, (int) $verdict['host']['engineattemptid']);
        $this->assertSame(2, (int) $verdict['host']['answered']);
        $this->assertGreaterThan(0, (int) $verdict['form']['missing'], 'the form was broken off');

        if ((int) $row->status === attempt_scheduler::STATUS_COLLECTED) {
            // The trace is kept as a diagnosis, with its verdict; the figures do not take it.
            $trace = json_decode((string) $row->tracejson, true);
            $this->assertSame(completion_contract::TECHNICAL, $trace['completion']['class']);
            $v = result_validity::evaluate_attempt($trace, ['strategy' => 'classic', 'enginestatus' => $trace['enginestatus'],
                'hoststopcode' => $trace['hoststopcode']], null, [], engine_validity::RULE_UNIFORM);
            $this->assertFalse($v['valid']);
            $this->assertContains(result_validity::COMPLETION_TECHNICAL, $v['reasons']);
        }
        $this->assertSame(
            1,
            $DB->count_records('adaptivequiz_attempt', ['userid' => $this->scenario->userid]),
            'no second attempt'
        );
    }

    /**
     * Stopped, the host's timer runs on; closed by it while stopped, the sitting is collected on resume as a technical
     * end — not queued, not played again, no new attempt (AK04, AK05).
     *
     * @return void
     */
    public function test_a_sitting_closed_by_the_time_limit_while_stopped_is_not_played_again(): void {
        global $DB;
        [$sitting, $engine, $runid, $experimentid] = $this->classical_sitting();
        experiment_stop::stop($experimentid);
        $this->assertSame(attempt_scheduler::STATUS_CANCELLED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $sitting]
        ));
        // The stop does not stop the engine's clock.
        $this->expire($engine);

        $done = experiment_resume::resume($experimentid);
        $this->assertTrue($done['ok'], $done['reason']);
        $this->assertSame(1, $done['collecting']);
        $this->assertSame(0, $done['requeued']);
        $this->assertSame(attempt_scheduler::STATUS_TRACEPENDING, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $sitting]
        ));

        // The pipeline's look collects it, and says how it ended.
        attempt_scheduler::retry_pending_traces(time() + 1);
        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $sitting]);
        $verdict = json_decode((string) $row->completionjson, true);
        $this->assertSame(
            [completion_contract::TECHNICAL, completion_contract::HOST_TIMEOUT, 'trace-pending'],
            [$verdict['class'], $verdict['technical'], $verdict['source']]
        );
        $this->assertNotSame(attempt_scheduler::STATUS_QUEUED, (int) $row->status);
        $this->assertSame(
            1,
            $DB->count_records('adaptivequiz_attempt', ['userid' => $this->scenario->userid]),
            'no second attempt'
        );
        $this->assertNotContains(attempt_history::STARTED, array_column(
            array_slice(attempt_history::of_attempt($sitting), 0),
            'outcome'
        ), 'nothing played');
    }
}
