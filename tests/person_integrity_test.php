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

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\person_integrity;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_log;
use local_catquizlab\local\run_orchestrator;

/**
 * Each digital twin once per run, however often or concurrently it is provisioned (#116).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\person_generator
 * @covers     \local_catquizlab\local\person_integrity
 */
final class person_integrity_test extends \advanced_testcase {
    /**
     * A run, and the five twins of its definition.
     *
     * @return array [run, persons]
     */
    protected function run_and_twins(): array {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $definition = experiment_definition::example_baseline();
        $definition['persons']['count'] = 5;

        return [$run, person_generator::generate($definition, 4242, ['replication' => 1]), $definition];
    }

    /**
     * Storing the same twins twice stores them once.
     *
     * @return void
     */
    public function test_storing_twice_stores_once(): void {
        global $DB;
        $this->resetAfterTest();
        [$run, $persons, $definition] = $this->run_and_twins();

        person_generator::persist((int) $run->id, $persons);
        person_generator::persist((int) $run->id, $persons);

        $check = person_integrity::check_run((int) $run->id, person_generator::planned_count($definition));
        $this->assertTrue($check['ok']);
        $this->assertSame([5, 5], [$check['rows'], $check['distinct']]);
        $this->assertSame(5, $DB->count_records('local_catquizlab_twinclaim', ['runid' => $run->id]));
    }

    /**
     * The same twin with another ground truth is refused, not overwritten.
     *
     * @return void
     */
    public function test_a_different_ground_truth_is_refused(): void {
        $this->resetAfterTest();
        [$run, $persons] = $this->run_and_twins();
        person_generator::persist((int) $run->id, $persons);

        $persons[0]['abilityglobal'] += 0.5;
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/different ground truth/');
        person_generator::persist((int) $run->id, $persons);
    }

    /**
     * The database itself refuses a second claim of a twin in a run.
     *
     * @return void
     */
    public function test_the_database_refuses_a_second_claim(): void {
        global $DB;
        $this->resetAfterTest();
        [$run] = $this->run_and_twins();
        $DB->insert_record('local_catquizlab_twinclaim', (object) ['runid' => $run->id, 'twinid' => 'r001-t00001',
            'personid' => 1, 'timecreated' => time()]);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_catquizlab_twinclaim', (object) ['runid' => $run->id, 'twinid' => 'r001-t00001',
            'personid' => 2, 'timecreated' => time()]);
    }

    /**
     * A second setup of a run while one runs does nothing — and does not fail the run.
     *
     * @return void
     */
    public function test_a_concurrent_setup_does_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$run] = $this->run_and_twins();
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_SCHEDULED, ['id' => $run->id]);

        // The other setup holds the run's lock. File locks, because they behave
        // within one process as two processes do: PostgreSQL's advisory locks
        // are re-entrant within a session, so a test there would get the lock
        // twice — which two real processes, with two sessions, do not.
        global $CFG;
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $this->assertInstanceOf(\core\lock\file_lock_factory::class, \core\lock\lock_config::get_lock_factory('x'));
        $lock = \core\lock\lock_config::get_lock_factory('local_catquizlab_setup')->get_lock('run_' . $run->id, 0);
        $this->assertNotFalse($lock);
        try {
            $result = run_orchestrator::setup((int) $run->id);
        } finally {
            $lock->release();
        }

        $this->assertTrue($result['concurrent']);
        $this->assertSame(run_orchestrator::REASON_SETUP_IN_PROGRESS, $result['reason']);
        $this->assertSame(registry::STATUS_SCHEDULED, (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $run->id]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_person', ['runid' => $run->id]));
        $this->assertTrue($DB->record_exists('local_catquizlab_runlog', ['runid' => $run->id, 'event' => run_log::SETUP_SKIPPED]));
    }

    /**
     * A run with a twin twice gets no sittings, and shows up in the diagnosis.
     *
     * @return void
     */
    public function test_a_damaged_run_is_blocked_and_diagnosed(): void {
        global $DB;
        $this->resetAfterTest();
        [$run, $persons] = $this->run_and_twins();
        person_generator::persist((int) $run->id, $persons);
        // A row from before the claims: the same twin once more, as in the damaged runs.
        $copy = $DB->get_record('local_catquizlab_person', ['runid' => $run->id, 'twinid' => 'r001-t00002']);
        unset($copy->id);
        $DB->insert_record('local_catquizlab_person', $copy);
        $DB->set_field('local_catquizlab_person', 'moodleuserid', 2, ['runid' => $run->id]);

        $check = person_integrity::check_run((int) $run->id, 5);
        $this->assertFalse($check['ok']);
        $this->assertSame([['twinid' => 'r001-t00002', 'rows' => 2]], $check['duplicates']);

        $diagnosis = person_integrity::runs_with_duplicates();
        $this->assertCount(1, $diagnosis);
        $this->assertSame([(int) $run->id, 1, 1], [$diagnosis[0]['runid'], $diagnosis[0]['groups'], $diagnosis[0]['extrarows']]);

        try {
            attempt_scheduler::schedule((int) $run->id);
            $this->fail('A run with a twin twice was scheduled.');
        } catch (\moodle_exception $e) {
            $this->assertSame('duplicatetwins', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', ['runid' => $run->id]));
    }

    /**
     * Fewer people than planned is a mismatch, as well as more.
     *
     * @return void
     */
    public function test_the_postcondition_compares_with_the_plan(): void {
        global $DB;
        $this->resetAfterTest();
        [$run, $persons] = $this->run_and_twins();
        person_generator::persist((int) $run->id, array_slice($persons, 0, 4));

        $check = person_integrity::check_run((int) $run->id, 5);
        $this->assertFalse($check['ok']);
        $this->assertSame([4, 4], [$check['rows'], $check['distinct']]);
    }

    /**
     * A people stage interrupted after some twins is continued, not started over (#116, criterion 3).
     *
     * @return void
     */
    public function test_an_interrupted_people_stage_is_continued(): void {
        global $DB;
        $this->resetAfterTest();
        [$run, $persons] = $this->run_and_twins();

        // Interrupted after three of five.
        person_generator::persist((int) $run->id, array_slice($persons, 0, 3));
        $before = $DB->get_records_menu('local_catquizlab_person', ['runid' => $run->id], 'id', 'twinid, id');

        // Run again, whole.
        person_generator::persist((int) $run->id, $persons);

        $check = person_integrity::check_run((int) $run->id, 5);
        $this->assertTrue($check['ok']);
        $after = $DB->get_records_menu('local_catquizlab_person', ['runid' => $run->id], 'id', 'twinid, id');
        // The three already there are the same rows; the two missing ones were added.
        foreach ($before as $twinid => $id) {
            $this->assertSame($id, $after[$twinid]);
        }
        $this->assertCount(5, $after);
    }
}
