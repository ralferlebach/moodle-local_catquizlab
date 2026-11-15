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

use local_catquizlab\local\core_compat;
use local_catquizlab\local\deletion_log;
use local_catquizlab\local\engine_footprint;
use local_catquizlab\local\engine_inventory;
use local_catquizlab\local\purge_incomplete;
use local_catquizlab\local\purger;
use local_catquizlab\local\run_lifecycle;

/**
 * Deep deletion against the real engine and its host activity, with crossing ids (#161 DoD1, DoD3; #145 AK1/AK6).
 *
 * A lab run A and somebody else's activity B, each with a real adaptive quiz (catmodel catquiz), activity attempts with
 * question usages, CAT attempts, progress and abilities in the engine's own tables. The numbers are made to cross: B's
 * CAT attempt has the number of A's activity attempt, and B's activity attempt the number of A's CAT attempt — so a
 * deletion that takes one id space for the other hits B. Deleting A deeply must leave nothing of A and everything of
 * B; a callback that takes B's rows is caught and the deletion does not say "deleted"; a failing activity deletion
 * keeps the run with what leads to its engine rows; a retry is idempotent; a deletion that is not deep touches nothing
 * in the engine and says so.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\engine_footprint
 * @covers     \local_catquizlab\local\engine_inventory
 * @covers     \local_catquizlab\local\purger
 * @covers     \local_catquizlab\local\deletion_log
 */
final class engine_footprint_test extends \advanced_testcase {
    /** @var int The number A's activity attempt and B's CAT attempt share. */
    protected const CROSS1 = 900001;

    /** @var int The number A's CAT attempt and B's activity attempt share. */
    protected const CROSS2 = 900002;

    /**
     * Take the fault away again.
     *
     * @return void
     */
    protected function tearDown(): void {
        purger::simulate('activity', null);
        purger::simulate('afterengine', null);
        parent::tearDown();
    }

    /**
     * Give a row a chosen id.
     *
     * @param string $table The table.
     * @param int $from Its id.
     * @param int $to The id it gets.
     * @return void
     */
    protected function renumber(string $table, int $from, int $to): void {
        global $DB;
        $DB->execute("UPDATE {{$table}} SET id = ? WHERE id = ?", [$to, $from]);
    }

    /**
     * A real adaptive quiz with the catquiz model, an attempt of a user with a question usage, and the engine's rows
     * for it: CAT attempt, progress, ability.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user The user.
     * @param int $hostid The id the activity attempt gets.
     * @param int $catid The id the CAT attempt gets.
     * @param int $contextid The engine context of the ability.
     * @return array{cm: \stdClass, instance: int, host: int, cat: int, progress: int, ability: int, usage: int}
     */
    protected function activity_with_attempt(\stdClass $course, \stdClass $user, int $hostid, int $catid, int $contextid): array {
        global $DB;

        $quiz = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
        $DB->set_field('adaptivequiz', 'catmodel', 'catquiz', ['id' => $quiz->id]);
        $cm = get_coursemodule_from_instance('adaptivequiz', $quiz->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', \context_module::instance($cm->id));
        $quba->set_preferred_behaviour('deferredfeedback');
        // A sitting that got its first question: the usage holds a question attempt, as every played one does. (One
        // without is the case of test_an_attempt_without_a_first_question_leaves_no_empty_usage().)
        $questions = $this->getDataGenerator()->get_plugin_generator('core_question');
        $asked = $questions->create_question('truefalse', null, ['category' => $questions->create_question_category()->id]);
        $quba->add_question(\question_bank::load_question($asked->id));
        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);

        $host = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => $quiz->id, 'userid' => $user->id,
            'uniqueid' => $quba->get_id(), 'attemptstate' => 'complete', 'attemptstopcriteria' => '',
            'questionsattempted' => 3, 'timecreated' => time(), 'timemodified' => time()]);
        $this->renumber('adaptivequiz_attempt', $host, $hostid);
        $cat = (int) $DB->insert_record('local_catquiz_attempts', (object) ['userid' => $user->id, 'scaleid' => 0,
            'contextid' => $contextid, 'courseid' => $course->id, 'attemptid' => $hostid, 'component' => 'mod_adaptivequiz',
            'instanceid' => $quiz->id, 'teststrategy' => 1, 'status' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        $this->renumber('local_catquiz_attempts', $cat, $catid);
        $progress = (int) $DB->insert_record('local_catquiz_progress', (object) ['userid' => $user->id,
            'component' => 'mod_adaptivequiz', 'attemptid' => $catid, 'json' => '{}', 'quizsettings' => '{}',
            'timemodified' => time()]);
        $ability = (int) $DB->insert_record('local_catquiz_personparams', (object) ['userid' => $user->id,
            'catscaleid' => 0, 'contextid' => $contextid, 'attemptid' => $catid, 'ability' => 0.5, 'standarderror' => 0.3,
            'status' => 0, 'timecreated' => time(), 'timemodified' => time()]);

        return ['cm' => $cm, 'instance' => (int) $quiz->id, 'host' => $hostid, 'cat' => $catid, 'progress' => $progress,
            'ability' => $ability, 'usage' => $quba->get_id()];
    }

    /**
     * Run A of an experiment, with its activity, accounts, engine context, scale, item, item parameter and question;
     * and B, somebody else's activity in the same course, whose ids cross A's.
     *
     * @return array{experimentid: int, runid: int, a: array, b: array, bow: array}
     */
    protected function two_with_crossing_ids(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquiz_progress') || !$DB->get_manager()->table_exists('adaptivequiz')) {
            $this->markTestSkipped('Needs local_catquiz and mod_adaptivequiz.');
        }
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment();
        $run = $generator->create_run(['experimentid' => $experiment->id]);
        $course = $this->getDataGenerator()->create_course();

        // A: the run's engine context, scale, item and its parameter, as provisioning makes them.
        $ctx = (int) $DB->insert_record('local_catquiz_catcontext', (object) ['parentid' => 0, 'name' => 'lab A',
            'description' => '', 'descriptionformat' => 1, 'starttimestamp' => 0, 'endtimestamp' => 0, 'json' => '{}',
            'usermodified' => 0, 'timecreated' => time(), 'timemodified' => time(), 'timecalculated' => 0]);
        $scale = (int) $DB->insert_record('local_catquiz_catscales', (object) ['parentid' => 0, 'label' => 'A',
            'name' => 'A', 'description' => '', 'contextid' => $ctx, 'minscalevalue' => -5, 'maxscalevalue' => 5,
            'timecreated' => time(), 'timemodified' => time()]);
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category();
        $question = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question(
            'truefalse',
            null,
            ['category' => $category->id]
        );
        $item = (int) $DB->insert_record('local_catquiz_items', (object) ['componentname' => 'question',
            'componentid' => $question->id, 'catscaleid' => $scale, 'contextid' => $ctx, 'activeparamid' => 0,
            'lastupdated' => 0, 'status' => 0]);
        $DB->insert_record('local_catquiz_itemparams', (object) ['itemid' => $item, 'componentid' => $question->id,
            'componentname' => 'question', 'contextid' => $ctx, 'model' => 'raschbirnbaum', 'difficulty' => 0.1,
            'discrimination' => 1, 'guessing' => 0, 'json' => '{}', 'status' => 0, 'usable' => 1, 'timecreated' => time(),
            'timemodified' => time()]);
        $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $run->id, 'level' => 0, 'catscaleid' => $scale,
            'contextid' => $ctx, 'categoryindex' => 0, 'subscaleindex' => 0, 'nodekey' => 'root', 'timecreated' => time()]);
        $DB->insert_record('local_catquizlab_item', (object) ['runid' => $run->id, 'questionid' => $question->id,
            'timecreated' => time()]);

        // A's simulated account, named with the run as provisioning names them, and its sitting.
        $usera = $this->getDataGenerator()->create_user(['username' => 'catlab_r' . $run->id . '_p1']);
        $generator->create_person(['runid' => $run->id, 'moodleuserid' => $usera->id]);
        $a = $this->activity_with_attempt($course, $usera, self::CROSS1, self::CROSS2, $ctx);
        $DB->set_field('local_catquizlab_run', 'testcmid', $a['cm']->id, ['id' => $run->id]);

        // B: a real person's attempt in another activity, numbered across A's.
        $userb = $this->getDataGenerator()->create_user(['username' => 'realstudent']);
        $b = $this->activity_with_attempt($course, $userb, self::CROSS2, self::CROSS1, 0);

        return ['experimentid' => (int) $experiment->id, 'runid' => (int) $run->id, 'a' => $a, 'b' => $b,
            'bow' => $this->rows_of_b($b)];
    }

    /**
     * B's rows as they are.
     *
     * @param array $b From activity_with_attempt().
     * @return array
     */
    protected function rows_of_b(array $b): array {
        global $DB;
        return [
            'adaptivequiz' => $DB->get_record('adaptivequiz', ['id' => $b['instance']]),
            'host' => $DB->get_record('adaptivequiz_attempt', ['id' => $b['host']]),
            'usage' => $DB->get_record('question_usages', ['id' => $b['usage']]),
            'cat' => $DB->get_record('local_catquiz_attempts', ['id' => $b['cat']]),
            'progress' => $DB->get_record('local_catquiz_progress', ['id' => $b['progress']]),
            'ability' => $DB->get_record('local_catquiz_personparams', ['id' => $b['ability']]),
        ];
    }

    /**
     * The footprint finds A's rows by the lab's own record, and B's crossing rows as at risk, never as A's.
     *
     * @return void
     */
    public function test_the_footprint_is_the_runs_own_and_names_what_crosses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();

        $f = engine_footprint::of_run($x['runid']);
        $this->assertSame([self::CROSS1], $f['owned']['adaptivequiz_attempt']);
        $this->assertSame([self::CROSS2], $f['owned']['local_catquiz_attempts']);
        $this->assertSame([$x['a']['progress']], $f['owned']['local_catquiz_progress']);
        $this->assertSame([$x['a']['ability']], $f['owned']['local_catquiz_personparams']);
        $this->assertSame([$x['a']['usage']], $f['owned']['question_usages']);
        $this->assertCount(1, $f['owned']['local_catquiz_items']);
        $this->assertCount(1, $f['owned']['local_catquiz_itemparams']);
        $this->assertCount(1, $f['owned']['local_catquiz_catcontext']);
        $this->assertCount(1, $f['owned']['question']);
        // B's rows sharing A's numbers: at risk, not owned.
        $this->assertSame([$x['b']['progress']], $f['atrisk']['local_catquiz_progress']);
        $this->assertSame([$x['b']['ability']], $f['atrisk']['local_catquiz_personparams']);
        $this->assertSame([self::CROSS1], $f['atrisk']['local_catquiz_attempts']);
        $this->assertSame([self::CROSS2], $f['atrisk']['adaptivequiz_attempt']);
        foreach ($f['owned'] as $table => $ids) {
            foreach ($f['atrisk'][$table] ?? [] as $id) {
                $this->assertNotContains($id, $ids, "$table $id is B's");
            }
        }
    }

    /**
     * Deep deletion through the background task: nothing of A is left, all of B is as it was, no new orphan in the
     * engine, and the record says when it was queued, started and finished, with each run's check (#145 AK1/AK6).
     *
     * @return void
     */
    public function test_a_deep_deletion_leaves_nothing_of_the_run_and_all_of_the_others(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();
        $before = engine_footprint::of_run($x['runid']);
        $orphans = engine_inventory::orphans();

        \local_catquizlab\task\delete_experiment::queue($x['experimentid'], true);
        $this->expectOutputRegex('/experiment \d+ deleted in/');
        $this->runAdhocTasks('\\local_catquizlab\\task\\delete_experiment');

        $this->assertFalse($DB->record_exists('local_catquizlab_experiment', ['id' => $x['experimentid']]));
        $check = engine_footprint::check($before);
        $this->assertSame([], $check['left'], 'nothing of A is left');
        $this->assertSame([], $check['lost'], 'nothing of B is lost');
        $this->assertEquals($x['bow'], $this->rows_of_b($x['b']), 'B is as it was');
        foreach (engine_inventory::orphans() as $kind => $now) {
            $this->assertLessThanOrEqual($orphans[$kind]['count'], $now['count'], "no new orphan: $kind");
        }

        $events = array_column(deletion_log::events($x['experimentid']), 'event');
        $this->assertSame(['queued', 'started', 'engine-before', 'engine-after', 'finished'], $events);
        $last = deletion_log::events($x['experimentid'])[4];
        $this->assertSame([], $last['left']);
        $this->assertSame('deleted', deletion_log::state($x['experimentid'])['state']);
        $after = deletion_log::events($x['experimentid'])[3];
        $this->assertTrue($after['ok']);
    }

    /**
     * A host callback that takes B's rows by the wrong number (adaptivequizcatmodel_catquiz #11) is caught: the run and
     * its experiment stay, nothing more is deleted, and the failure names B's row; the task is retried.
     *
     * @return void
     */
    public function test_a_callback_taking_others_rows_stops_the_deletion(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();

        // As the faulty callback does: the progress of the activity attempt's number, which is B's CAT attempt's.
        purger::simulate('afterengine', static function () use ($DB): void {
            $DB->delete_records('local_catquiz_progress', ['attemptid' => self::CROSS1]);
        });
        try {
            purger::delete_experiment($x['experimentid'], true, true);
            $this->fail('the deletion must not complete');
        } catch (purge_incomplete $e) {
            $this->assertSame([$x['b']['progress']], $e->check['lost']['local_catquiz_progress']);
            $this->assertStringContainsString('local_catquiz_progress 1 (' . $x['b']['progress'] . ')', $e->getMessage());
        }
        $this->assertTrue($DB->record_exists('local_catquizlab_experiment', ['id' => $x['experimentid']]));
        $this->assertTrue($DB->record_exists('local_catquizlab_run', ['id' => $x['runid']]));
        $this->assertSame(
            1,
            $DB->count_records('local_catquizlab_person', ['runid' => $x['runid']]),
            'what leads to the run\'s rows stays'
        );
        $this->assertSame(1, $DB->count_records('local_catquizlab_scalemap', ['runid' => $x['runid']]));
    }

    /**
     * An activity that cannot be deleted stops the deletion before anything else goes; a retry without the fault
     * completes it, and a second retry finds nothing to do (#161 AK2, DoD3).
     *
     * @return void
     */
    public function test_a_failing_activity_keeps_the_run_and_a_retry_completes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();
        $before = engine_footprint::of_run($x['runid']);

        purger::simulate('activity', static function (): void {
            throw new \moodle_exception('error', 'moodle', '', null, 'simulated: the activity refuses to go');
        });
        \local_catquizlab\task\delete_experiment::queue($x['experimentid'], true);
        try {
            $this->runAdhocTasks('\\local_catquizlab\\task\\delete_experiment');
        } catch (\Throwable $e) {
            // The task runner rethrows in tests; Moodle would retry it.
            unset($e);
        }
        $this->assertTrue($DB->record_exists('local_catquizlab_run', ['id' => $x['runid']]));
        $this->assertSame($before['owned'], engine_footprint::of_run($x['runid'])['owned'], 'nothing of A deleted yet');
        $this->assertNotEmpty($DB->get_field('local_catquizlab_experiment', 'deleteerror', ['id' => $x['experimentid']]));
        $this->assertSame('failed', deletion_log::state($x['experimentid'])['state']);

        purger::simulate('activity', null);
        $result = purger::delete_experiment($x['experimentid'], true, true);
        $this->assertTrue($result['ok']);
        $this->assertSame([], engine_footprint::check($before)['left']);
        $this->assertEquals($x['bow'], $this->rows_of_b($x['b']));
        $this->assertFalse(purger::delete_experiment($x['experimentid'], true, true)['ok'], 'already gone: nothing to do');
    }

    /**
     * The task as cron runs it: it fails, Moodle keeps it with a fail delay, the list shows the error and the record
     * says failed; the retry by cron's own runner completes the deletion, and the task is gone (#145 DoD2, #161 DoD3).
     *
     * @return void
     */
    public function test_a_failed_task_is_retried_by_cron_and_completes(): void {
        global $DB;
        $this->resetAfterTest();
        // Cron's runner rolls back an open transaction when a task fails; the test's own must not be that one.
        $this->preventResetByRollback();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();
        $before = engine_footprint::of_run($x['runid']);
        $classname = '\\local_catquizlab\\task\\delete_experiment';

        purger::simulate('activity', static function (): void {
            // A PHP error, not an exception: what a fatal in the activity's own callback is, as far as PHP lets it be caught.
            throw new \Error('simulated: the activity refuses to go');
        });
        \local_catquizlab\task\delete_experiment::queue($x['experimentid'], true);
        $taskid = (int) $DB->get_field('task_adhoc', 'id', ['classname' => $classname], MUST_EXIST);
        $this->expectOutputRegex('/Adhoc task failed: .*delete_experiment.*Adhoc task complete: .*delete_experiment/s');
        \core\cron::run_adhoc_task($taskid);

        $task = $DB->get_record('task_adhoc', ['id' => $taskid]);
        $this->assertNotFalse($task, 'Moodle keeps the failed task for its retry');
        $this->assertGreaterThan(0, (int) $task->faildelay);
        $this->assertTrue($DB->record_exists('local_catquizlab_run', ['id' => $x['runid']]));
        $this->assertSame($before['owned'], engine_footprint::of_run($x['runid'])['owned'], 'nothing of A deleted yet');
        $this->assertStringContainsString(
            'simulated: the activity refuses to go',
            (string) $DB->get_field('local_catquizlab_experiment', 'deleteerror', ['id' => $x['experimentid']])
        );
        $states = \local_catquizlab\task\delete_experiment::states();
        $this->assertStringContainsString('the activity refuses', $states[$x['experimentid']]['error']);
        $this->assertSame('failed', deletion_log::state($x['experimentid'])['state']);

        purger::simulate('activity', null);
        \core\cron::run_adhoc_task($taskid);

        $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $taskid]), 'the retry completed the task');
        $this->assertFalse($DB->record_exists('local_catquizlab_experiment', ['id' => $x['experimentid']]));
        $check = engine_footprint::check($before);
        $this->assertSame([], $check['left']);
        $this->assertSame([], $check['lost']);
        $this->assertEquals($x['bow'], $this->rows_of_b($x['b']));
        $this->assertSame(['queued', 'started', 'engine-before', 'failed', 'started', 'engine-before', 'engine-after',
            'finished'], array_column(deletion_log::events($x['experimentid']), 'event'));
        $this->assertSame('deleted', deletion_log::state($x['experimentid'])['state']);
    }

    /**
     * Not deep: nothing of the engine or the activity goes, and the preview says what stays (#161 AK5).
     *
     * @return void
     */
    public function test_a_deletion_that_is_not_deep_keeps_the_engine_and_says_so(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();
        $before = engine_footprint::of_run($x['runid']);

        $preview = purger::preview_experiment($x['experimentid'], false);
        $this->assertSame(1, $preview['kept']['hostattempts']);
        $this->assertSame(1, $preview['kept']['progress']);
        $this->assertArrayNotHasKey('hostattempts', $preview['counts']);
        $deep = purger::preview_experiment($x['experimentid'], true);
        $this->assertSame(1, $deep['counts']['hostattempts']);
        $this->assertSame(4, $deep['atrisk'], 'B\'s attempt, CAT attempt, progress and ability share A\'s numbers');

        purger::delete_run($x['runid'], false, true);
        $check = engine_footprint::check($before);
        $this->assertSame([], $check['lost']);
        foreach ($before['owned'] as $table => $ids) {
            $this->assertSame($ids, $check['left'][$table] ?? [], "$table stays");
        }
    }

    /**
     * A reset ends where a deep deletion does, and stops the same way (#161 AK3).
     *
     * @return void
     */
    public function test_a_reset_checks_like_a_deletion(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();
        $before = engine_footprint::of_run($x['runid']);

        purger::simulate('afterengine', null);
        $DB->set_field('local_catquizlab_run', 'status', \local_catquizlab\local\registry::STATUS_FAILED, ['id' => $x['runid']]);
        $result = run_lifecycle::reset($x['runid']);
        $this->assertTrue($result['ok'], json_encode($result));
        $check = engine_footprint::check($before);
        $this->assertSame([], $check['lost']);
        // Gone with the activity and the scales; the accounts stay with a reset, their engine rows do not.
        foreach (
            ['adaptivequiz_attempt', 'local_catquiz_attempts', 'local_catquiz_progress', 'local_catquiz_personparams',
            'local_catquiz_catscales', 'local_catquiz_items', 'local_catquiz_itemparams', 'local_catquiz_catcontext'] as $table
        ) {
            $this->assertArrayNotHasKey($table, $check['left'], $table);
        }
        $this->assertEquals($x['bow'], $this->rows_of_b($x['b']));
    }

    /**
     * A reset and a run cleanup whose activity cannot be deleted keep everything that leads to the run's engine rows
     * and say so; without the fault, the cleanup ends as a deep deletion does (#161 AK3).
     *
     * @return void
     */
    public function test_a_failing_reset_or_cleanup_keeps_the_run_and_a_cleanup_ends_like_a_deletion(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();
        $before = engine_footprint::of_run($x['runid']);
        $DB->set_field('local_catquizlab_run', 'status', \local_catquizlab\local\registry::STATUS_FAILED, ['id' => $x['runid']]);

        purger::simulate('activity', static function (): void {
            throw new \moodle_exception('error', 'moodle', '', null, 'simulated: the activity refuses to go');
        });
        $result = run_lifecycle::reset($x['runid']);
        $this->assertFalse($result['ok']);
        $this->assertSame('engine-incomplete', $result['reason']);
        $this->assertStringContainsString('the activity refuses', $result['message']);
        try {
            \local_catquizlab\local\run_cleanup::cleanup($x['runid']);
            $this->fail('the cleanup must not complete');
        } catch (purge_incomplete $e) {
            $this->assertSame($x['runid'], $e->runid);
        }
        $this->assertSame($before['owned'], engine_footprint::of_run($x['runid'])['owned'], 'nothing of A deleted');
        $this->assertSame(1, $DB->count_records('local_catquizlab_person', ['runid' => $x['runid']]));
        $this->assertSame(1, $DB->count_records('local_catquizlab_scalemap', ['runid' => $x['runid']]));

        purger::simulate('activity', null);
        \local_catquizlab\local\run_cleanup::cleanup($x['runid']);
        $check = engine_footprint::check($before);
        $this->assertSame([], $check['left']);
        $this->assertSame([], $check['lost']);
        $this->assertEquals($x['bow'], $this->rows_of_b($x['b']));
        $this->assertSame(0, $DB->count_records('local_catquizlab_person', ['runid' => $x['runid']]));
        $again = \local_catquizlab\local\run_cleanup::cleanup($x['runid']);
        $this->assertSame([0, 0, 0], [$again['persons'], $again['users'], $again['items']], 'a second cleanup finds nothing');
    }

    /**
     * The activity deleted the regular way first, in the course: its own callbacks take its attempts, and the lab's
     * deep deletion afterwards finds the rest and ends in the same state (#161 AK3).
     *
     * @return void
     */
    public function test_an_activity_deleted_in_the_course_first_ends_the_same(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();
        $before = engine_footprint::of_run($x['runid']);

        core_compat::delete_module((int) $DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $x['runid']]));
        $this->assertSame([], engine_footprint::check($before)['lost'], 'the activity\'s own deletion took nothing of B');

        $result = purger::delete_experiment($x['experimentid'], true, true);
        $this->assertTrue($result['ok']);
        $check = engine_footprint::check($before);
        $this->assertSame([], $check['left']);
        $this->assertSame([], $check['lost']);
        $this->assertEquals($x['bow'], $this->rows_of_b($x['b']));
    }

    /**
     * An attempt that never got its first question has an empty usage. Moodle's question engine deletes such a usage
     * on PostgreSQL, but not on MySQL or MariaDB (an inner join over the question attempts): the deep deletion takes it
     * as the run's own, and nothing of B (#161, seen on the MariaDB mirror).
     *
     * @return void
     */
    public function test_an_attempt_without_a_first_question_leaves_no_empty_usage(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();
        $quba = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', \context_module::instance(
            (int) $x['a']['cm']->id
        ));
        $quba->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($quba);
        $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => $x['a']['instance'],
            'userid' => (int) $DB->get_field('local_catquizlab_person', 'moodleuserid', ['runid' => $x['runid']]),
            'uniqueid' => $quba->get_id(), 'attemptstate' => 'inprogress', 'attemptstopcriteria' => '',
            'questionsattempted' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        $before = engine_footprint::of_run($x['runid']);
        $this->assertContains((int) $quba->get_id(), $before['owned']['question_usages']);

        $this->assertTrue(purger::delete_experiment($x['experimentid'], true, true)['ok']);
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $quba->get_id()]));
        $check = engine_footprint::check($before);
        $this->assertSame([], $check['left']);
        $this->assertSame([], $check['lost']);
        $this->assertEquals($x['bow'], $this->rows_of_b($x['b']));
    }

    /**
     * The inventory counts what has no owner and changes nothing.
     *
     * @return void
     */
    public function test_the_inventory_counts_and_changes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->two_with_crossing_ids();
        $first = engine_inventory::orphans();
        // A progress row whose CAT attempt is gone.
        $DB->insert_record('local_catquiz_progress', (object) ['userid' => 2, 'component' => 'mod_adaptivequiz',
            'attemptid' => 999999, 'json' => '{}', 'quizsettings' => '{}', 'timemodified' => time()]);
        $tables = ['local_catquiz_progress', 'local_catquiz_attempts', 'local_catquiz_personparams', 'adaptivequiz_attempt'];
        $counts = array_map(static fn($t) => $DB->count_records($t), $tables);

        $second = engine_inventory::orphans();
        $this->assertSame(
            $first['progress without its CAT attempt']['count'] + 1,
            $second['progress without its CAT attempt']['count']
        );
        $this->assertSame($counts, array_map(static fn($t) => $DB->count_records($t), $tables), 'read only');
        $this->assertNotNull($second['progress without its CAT attempt']['newest']);
    }
}
