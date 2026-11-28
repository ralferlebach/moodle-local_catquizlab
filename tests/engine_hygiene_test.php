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

use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;
use adaptivequizcatmodel_catquiz\trace_scenario;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\engine_hygiene;
use local_catquizlab\local\engine_identity;
use local_catquizlab\local\registry;

/**
 * No tick deletes a start in progress (#178, re-audit 27.11.2026).
 *
 * CLARA, run 120, sitting 46856: the host wrote its attempt (uniqueid 0), the CAT model its attempt for it, the usage was
 * saved a second later — and between the two the pipeline tick deleted every lab attempt without a usage. Here, with
 * the real engine: the host's attempt is created and stopped right there (host insert and the CAT model's callback,
 * no usage yet), the scheduled tick and the ad-hoc tick both run, and the start goes on. Until 0.7.84 the host's attempt
 * was gone after the first tick (RED: run with classes/local/engine_hygiene.php and classes/task/pipeline_tick.php of
 * 0.7.84); now it is kept, the start finishes in it, and the sitting binds it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\engine_hygiene
 * @covers     \local_catquizlab\task\pipeline_tick
 * @covers     \local_catquizlab\task\kick_pipeline
 */
final class engine_hygiene_test extends \advanced_testcase {
    /**
     * Run a task as cron would, its output kept out of the test's.
     *
     * @param \core\task\task_base $task The task.
     * @return string What it said.
     */
    protected function run_task(\core\task\task_base $task): string {
        ob_start();
        $task->execute();

        return (string) ob_get_clean();
    }

    /**
     * A start of the real engine, stopped between the host's attempt and its question usage; both ticks; the start goes
     * on in the same attempt.
     *
     * @return void
     */
    public function test_no_tick_deletes_a_start_in_progress(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 1, 'local_catquizlab');
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK=true');
        $scenario = trace_scenario::create($this->getDataGenerator());
        /** @var \local_catquizlab_generator $lab */
        $lab = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $lab->create_run(['status' => registry::STATUS_RUNNING,
            'testcmid' => (int) $scenario->adaptivequiz->cm->id])->id;
        $person = $lab->create_person(['runid' => $runid, 'moodleuserid' => $scenario->userid]);
        $sitting = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid,
            'personid' => $person->id, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1, 'executionid' => 31,
            'leaseowner' => 'w1', 'leaseexpires' => time() + 300, 'timecreated' => time(), 'timemodified' => time()]);

        // The host writes its attempt and the CAT model its own for it; the question usage comes with the first item.
        $this->setUser($scenario->userid);
        $host = (new \mod_adaptivequiz\local\attempt($scenario->adaptivequiz, $scenario->userid))->get_attempt();
        $this->setAdminUser();
        $this->assertSame(0, (int) $host->uniqueid, 'between the host\'s insert and the usage');
        $inventory = engine_hygiene::inventory();
        $this->assertSame([1, 1, engine_hygiene::INITIALISING, engine_hygiene::KEPT, [31]], [$inventory['count'],
            $inventory['initialising'], $inventory['rows'][0]['state'], $inventory['rows'][0]['decision'],
            $inventory['rows'][0]['executions']]);

        // The scheduled tick and the ad-hoc one, at this very moment.
        $said = $this->run_task(new \local_catquizlab\task\pipeline_tick());
        $this->assertTrue($DB->record_exists('adaptivequiz_attempt', ['id' => $host->id]), 'kept by the scheduled tick');
        $this->assertStringContainsString('without a question usage, kept', $said);
        $this->assertStringNotContainsString('removed', $said);
        $this->run_task(new \local_catquizlab\task\kick_pipeline());
        $this->assertTrue($DB->record_exists('adaptivequiz_attempt', ['id' => $host->id]), 'kept by the ad-hoc tick');
        // The decision in the sitting's history, once for both ticks, with the attempt and why (#176 AK06).
        $kept = $DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $sitting,
            'outcome' => \local_catquizlab\local\attempt_history::HYGIENE_KEPT]);
        $this->assertCount(1, $kept);
        $kept = reset($kept);
        $this->assertSame([(int) $host->id, 31, 'hygiene_kept_initialising'], [(int) $kept->engineattemptid,
            (int) $kept->executionid, $kept->reasoncode]);
        $this->assertSame(engine_hygiene::KEPT, json_decode($kept->diagnosis, true)['hygiene']['decision']);

        // The start goes on — in the same attempt — and the sitting binds it.
        $this->setUser($scenario->userid);
        $first = request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
        $this->setAdminUser();
        $this->assertNotNull($first['questionid']);
        $this->assertSame((int) $host->id, (int) $first['attemptid'], 'the same attempt, no second one');
        $this->assertSame(1, $DB->count_records('adaptivequiz_attempt', ['userid' => $scenario->userid]));
        $usage = (int) $DB->get_field('adaptivequiz_attempt', 'uniqueid', ['id' => $host->id]);
        $this->assertGreaterThan(0, $usage);
        $bound = engine_identity::bind($sitting, $usage, 'w1', 31);
        $this->assertTrue($bound['found'], $bound['reason']);
        $this->assertSame((int) $host->id, $bound['engineattemptid']);
        $this->assertSame(0, engine_hygiene::inventory()['count']);
    }

    /**
     * A start that failed (no sitting being played) is counted and kept too; another account's attempt is not counted.
     *
     * @return void
     */
    public function test_a_failed_start_is_counted_and_kept(): void {
        global $DB;
        $this->resetAfterTest();
        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('Needs mod_adaptivequiz.');
        }
        /** @var \local_catquizlab_generator $lab */
        $lab = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        set_config('enabled', 1, 'local_catquizlab');
        $runid = (int) $lab->create_run()->id;
        $user = $this->getDataGenerator()->create_user();
        $lab->create_person(['runid' => $runid, 'moodleuserid' => $user->id]);
        $dead = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => 77, 'userid' => $user->id,
            'uniqueid' => 0, 'attemptstate' => 'inprogress', 'attemptstopcriteria' => '', 'questionsattempted' => 0,
            'timecreated' => time() - 7200, 'timemodified' => time() - 7200]);
        $stranger = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => 77,
            'userid' => $this->getDataGenerator()->create_user()->id, 'uniqueid' => 0, 'attemptstate' => 'inprogress',
            'attemptstopcriteria' => '', 'questionsattempted' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        $inventory = engine_hygiene::inventory(50, time());
        $this->assertSame([1, 0, 1], [$inventory['count'], $inventory['initialising'], $inventory['notplayed']]);
        $this->assertSame([$dead, engine_hygiene::NOT_PLAYED, engine_hygiene::KEPT], [$inventory['rows'][0]['attemptid'],
            $inventory['rows'][0]['state'], $inventory['rows'][0]['decision']]);
        $this->assertGreaterThanOrEqual(7200, $inventory['rows'][0]['ageseconds']);
        $this->run_task(new \local_catquizlab\task\pipeline_tick());
        $this->assertTrue($DB->record_exists('adaptivequiz_attempt', ['id' => $dead]), 'not by age either');
        $this->assertTrue($DB->record_exists('adaptivequiz_attempt', ['id' => $stranger]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_attemptlog'), 'no sitting being played: no history row');
    }
}
