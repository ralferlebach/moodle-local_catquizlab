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
 * The lab's deletions against a running attempt of somebody else whose numbers cross the run's.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;
use adaptivequizcatmodel_catquiz\trace_scenario;
use local_catquizlab\local\purger;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * Deep purge, reset, reset and rerun, and the same deletion again (adaptivequizcatmodel_catquiz #11).
 *
 * The run's activity A has a real attempt in progress of one of the run's simulated people; activity B, which the
 * lab does not own, has a real attempt in progress of somebody else. Their CAT attempts are renumbered so that
 * the numbers cross: the CAT attempt of A has the number of B's activity attempt and the other way round. A
 * deletion that finds rows by the wrong number takes B with it.
 *
 * Every lab path must take the run's footprint in the engine and leave B value for value as it was, create no
 * new orphan, and B must go on to its next question afterwards.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\purger
 * @covers     \local_catquizlab\local\run_lifecycle
 */
final class engine_isolation_test extends \advanced_testcase {
    /** @var array name => [adaptivequiz, context, userid] for A (the run's) and B (somebody else's). */
    private array $act = [];

    /** @var int The run. */
    private int $runid;

    /**
     * A run whose activity A has a running attempt, an activity B outside the lab with one, and the ids crossed.
     */
    private function build(): void {
        global $DB;
        $this->resetAfterTest();
        // The deletions run their own transactions: no test transaction around them (PostgreSQL).
        $this->preventResetByRollback();
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK=true');
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        // A: the run's activity and one of its simulated people.
        $scenario = trace_scenario::create($generator);
        $course = get_course($scenario->adaptivequiz->course);
        $this->act['A'] = [$scenario->adaptivequiz, $scenario->context, $scenario->userid];

        /** @var \local_catquizlab_generator $lab */
        $lab = $generator->get_plugin_generator('local_catquizlab');
        $this->runid = (int) $lab->create_run(['status' => registry::STATUS_FAILED, 'courseid' => $course->id,
            'testcmid' => (int) $scenario->adaptivequiz->cm->id])->id;
        // The lab stamps the run into the accounts it creates; that is how it knows them as its own.
        $DB->set_field('user', 'username', 'lab_r' . $this->runid . '_1', ['id' => $scenario->userid]);
        $DB->insert_record('local_catquizlab_person', (object) [
            'runid' => $this->runid, 'twinindex' => 1, 'severity' => 'none', 'stratum' => 'conforming',
            'moodleuserid' => $scenario->userid, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        // B: an activity of the same course on the same scale, somebody the lab did not create.
        $userb = $generator->create_user();
        $generator->enrol_user($userb->id, $course->id, 'student');
        $instance = $generator->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'minimumquestions' => 1, 'maximumquestions' => 50,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $root = $DB->get_record('local_catquiz_catscales', ['parentid' => 0], '*', MUST_EXIST);
        $generator->get_plugin_generator('local_catquiz')->create_catquiz_testsettings([
            'courseid' => $course->id, 'adaptivecatquizid' => $instance->id, 'catscalesid' => $root->id,
            'cateststrategyid' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 'catmodel' => 'catquiz',
            'catquiz_maxquestions' => 4, 'catquiz_standarderror_min' => 0.4, 'catquiz_standarderror_max' => 0.6,
            'numberoffeedbackoptions' => 2,
        ]);
        $b = $DB->get_record('adaptivequiz', ['id' => $instance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('adaptivequiz', $b->id, $course->id, false, MUST_EXIST);
        $b->context = \context_module::instance($cm->id);
        $b->cm = $cm;
        $this->act['B'] = [$b, $b->context, (int) $userb->id];

        // Both attempts in progress: the first question answered, the second shown.
        $this->play('A', [null, true]);
        $this->play('B', [null, false]);
        $this->cross();
        \cache_helper::purge_all();
    }

    /**
     * Ends skipping the feedback.
     */
    protected function tearDown(): void {
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK');
        parent::tearDown();
    }

    /**
     * Requests of an attempt, as its participant.
     *
     * @param string $name A or B.
     * @param array $answers null for the first request, then true/false for the answer to the pending item.
     * @return array The result of the last request.
     */
    private function play(string $name, array $answers): array {
        [$adaptivequiz, $context, $userid] = $this->act[$name];
        $this->setUser($userid);
        $result = [];
        foreach ($answers as $right) {
            $result = request_simulator::request($adaptivequiz, $context, $userid, $right);
        }
        $this->setAdminUser();
        return $result;
    }

    /**
     * The id of the activity's attempt.
     *
     * @param string $name
     * @return int
     */
    private function host(string $name): int {
        global $DB;
        [$adaptivequiz, , $userid] = $this->act[$name];
        return (int) $DB->get_field('adaptivequiz_attempt', 'MAX(id)', ['instance' => $adaptivequiz->id, 'userid' => $userid]);
    }

    /**
     * The id of the CAT attempt of the activity's attempt.
     *
     * @param string $name
     * @return int
     */
    private function cat(string $name): int {
        global $DB;
        return (int) $DB->get_field_select(
            'local_catquiz_attempts',
            'MAX(id)',
            "attemptid = ? AND component IN ('mod_adaptivequiz', 'adaptivequiz')",
            [$this->host($name)]
        );
    }

    /**
     * Renumbers the CAT attempts of A and B: A's gets the number of B's activity attempt and B's that of A's.
     */
    private function cross(): void {
        global $DB;
        $hosta = $this->host('A');
        $hostb = $this->host('B');
        $cata = $this->cat('A');
        $catb = $this->cat('B');
        $this->assertNotSame($hosta, $hostb);
        $this->assertTrue($cata > 0 && $catb > 0);
        $free = (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_catquiz_attempts}') + 1000;
        $this->renumber($cata, $free);
        $this->renumber($catb, $free + 1);
        $this->renumber($free, $hostb);
        $this->renumber($free + 1, $hosta);
        $this->assertSame($hostb, $this->cat('A'), "A's CAT attempt has the number of B's activity attempt");
        $this->assertSame($hosta, $this->cat('B'), "B's CAT attempt has the number of A's activity attempt");
    }

    /**
     * Gives a CAT attempt another id, with everything that references it.
     *
     * @param int $from
     * @param int $to
     */
    private function renumber(int $from, int $to): void {
        global $DB;
        $DB->execute('UPDATE {local_catquiz_attempts} SET id = ? WHERE id = ?', [$to, $from]);
        $DB->execute('UPDATE {local_catquiz_progress} SET attemptid = ? WHERE attemptid = ?', [$to, $from]);
        $DB->execute('UPDATE {local_catquiz_personparams} SET attemptid = ? WHERE attemptid = ?', [$to, $from]);
        // The progress events (local_catquiz #177, 2026112700) reference the CAT attempt too. Left behind, B's head
        // would read no events at all and the engine would end or break B on its own.
        if ($DB->get_manager()->table_exists('local_catquiz_progress_steps')) {
            $DB->execute('UPDATE {local_catquiz_progress_steps} SET catattemptid = ? WHERE catattemptid = ?', [$to, $from]);
        }
    }

    /**
     * Everything of B, every field of every row.
     *
     * @return array
     */
    private function snapshot_b(): array {
        global $DB;
        [$adaptivequiz, , $userid] = $this->act['B'];
        $host = $DB->get_records('adaptivequiz_attempt', ['instance' => $adaptivequiz->id], 'id');
        $uniqueids = array_filter(array_map(fn($h) => (int) $h->uniqueid, $host));
        $cat = $DB->get_records('local_catquiz_attempts', ['userid' => $userid], 'id');
        $in = implode(',', array_keys($cat) ?: [0]);
        return [
            'activity' => $DB->get_record('adaptivequiz', ['id' => $adaptivequiz->id]),
            'host' => $host,
            'usages' => $uniqueids ? $DB->get_records_list('question_usages', 'id', $uniqueids, 'id') : [],
            'questionattempts' => $uniqueids ? $DB->get_records_select(
                'question_attempts',
                'questionusageid IN (' . implode(',', $uniqueids) . ')',
                null,
                'id'
            ) : [],
            'cat' => $cat,
            'progress' => $DB->get_records_select('local_catquiz_progress', "attemptid IN ($in)", null, 'id'),
            // The progress events (local_catquiz #177, 2026112700): an answer goes there, the head keeps a fixed size.
            'progresssteps' => $DB->get_manager()->table_exists('local_catquiz_progress_steps')
                ? $DB->get_records_select('local_catquiz_progress_steps', "catattemptid IN ($in)", null, 'id') : [],
            'personparams' => $DB->get_records('local_catquiz_personparams', ['userid' => $userid], 'id'),
            'trace' => $host ? $DB->get_records_select(
                'local_catquiz_trace',
                'adaptiveattemptid IN (' . implode(',', array_keys($host)) . ')',
                null,
                'id'
            ) : [],
            'tests' => $DB->get_records('local_catquiz_tests', ['componentid' => $adaptivequiz->id,
                'component' => 'mod_adaptivequiz']),
        ];
    }

    /**
     * Rows that lead nowhere, by table - their ids.
     *
     * @return array
     */
    private function orphans(): array {
        global $DB;
        return [
            'progress' => $DB->get_fieldset_sql('SELECT p.id FROM {local_catquiz_progress} p
                WHERE NOT EXISTS (SELECT 1 FROM {local_catquiz_attempts} a WHERE a.id = p.attemptid) ORDER BY p.id'),
            'personparams' => $DB->get_fieldset_sql('SELECT pp.id FROM {local_catquiz_personparams} pp
                WHERE pp.attemptid IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM {local_catquiz_attempts} a WHERE a.id = pp.attemptid) ORDER BY pp.id'),
            'cat' => $DB->get_fieldset_sql("SELECT c.id FROM {local_catquiz_attempts} c
                WHERE c.component IN ('mod_adaptivequiz', 'adaptivequiz')
                AND NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.id = c.attemptid) ORDER BY c.id"),
            'trace' => $DB->get_fieldset_sql('SELECT t.id FROM {local_catquiz_trace} t
                WHERE NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.id = t.adaptiveattemptid) ORDER BY t.id'),
            'usages' => $DB->get_fieldset_sql("SELECT u.id FROM {question_usages} u WHERE u.component = 'mod_adaptivequiz'
                AND NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.uniqueid = u.id) ORDER BY u.id"),
        ];
    }

    /**
     * The lab's deletion paths.
     *
     * @return array
     */
    public static function paths(): array {
        return [
            'deep purge of the run' => ['deep'],
            'reset of the run' => ['reset'],
            'reset and rerun' => ['rerun'],
            'the same deletion again' => ['repeat'],
        ];
    }

    /**
     * A lab deletion takes the run's footprint and leaves B as it was; B goes on.
     *
     * @dataProvider paths
     * @param string $path
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('paths')]
    public function test_the_lab_deletes_its_own_and_b_goes_on(string $path): void {
        global $DB;
        $this->build();
        $hosta = $this->host('A');
        $cata = $this->cat('A');
        [$a, , $usera] = $this->act['A'];
        $before = $this->snapshot_b();
        $orphansbefore = $this->orphans();
        $this->assertNotEmpty($before['progress'], 'B has a saved state');
        $this->assertNotEmpty($before['questionattempts'], 'B has answered');

        switch ($path) {
            case 'deep':
                $result = purger::delete_run($this->runid, true, true);
                $this->assertTrue($result['ok']);
                break;
            case 'reset':
                $result = run_lifecycle::reset($this->runid);
                $this->assertTrue($result['ok'], $result['message'] ?? $result['reason'] ?? '');
                break;
            case 'rerun':
                $result = run_lifecycle::reset_and_rerun($this->runid);
                // Whether the run may start again is the lab's business; the reset in front of it is what is tested.
                $this->assertArrayHasKey('restarted', $result);
                $this->assertNotSame('engine-incomplete', $result['reason']);
                break;
            case 'repeat':
                // The engine part twice, then the deep deletion of the run: nothing more goes the second time.
                purger::delete_engine_objects($this->runid);
                $this->assertSame([], array_intersect_key(
                    purger::delete_engine_objects($this->runid),
                    ['activity' => 1, 'abilities' => 1]
                ));
                $this->assertTrue(purger::delete_run($this->runid, true, true)['ok']);
                $this->assertSame('run-not-found', purger::delete_run($this->runid, true, true)['reason']);
                break;
        }
        \cache_helper::purge_all();

        // A, the run's footprint, is gone - by its own numbers.
        $this->assertFalse($DB->record_exists('adaptivequiz', ['id' => $a->id]));
        $this->assertFalse($DB->record_exists('adaptivequiz_attempt', ['id' => $hosta, 'instance' => $a->id]));
        $this->assertFalse($DB->record_exists('local_catquiz_attempts', ['id' => $cata, 'userid' => $usera]));
        $this->assertSame(0, $DB->count_records('local_catquiz_personparams', ['userid' => $usera]));
        $this->assertSame(0, $DB->count_records_select('local_catquiz_progress', 'userid = ?', [$usera]));

        // B, value for value, and no new orphans.
        $this->assertSame(
            json_encode($before, JSON_PRESERVE_ZERO_FRACTION),
            json_encode($this->snapshot_b(), JSON_PRESERVE_ZERO_FRACTION),
            'B is unchanged'
        );
        $this->assertSame([], array_filter(array_map(
            fn(array $now, array $then): array => array_values(array_diff($now, $then)),
            $this->orphans(),
            $orphansbefore
        )), 'no new orphans');

        // B goes on: the next request answers the pending item and shows the next question.
        $catb = $this->cat('B');
        $result = $this->play('B', [true]);
        $this->assertSame($this->host('B'), $result['attemptid'], 'B continues its own attempt');
        $this->assertSame($catb, $this->cat('B'), 'B continues its own CAT attempt');
        $after = $this->snapshot_b();
        // Two of B's four questions are played: its own engine has no reason to end it here.
        $this->assertFalse($result['stopped'], 'B was not ended');
        $this->assertCount(count($before['questionattempts']) + 1, $after['questionattempts'], 'B was shown its next question');
        // The answer is in B's progress: its head (engines before local_catquiz #177), or its events and the head's
        // sequence number (from 2026112700 on, the head keeps a fixed size).
        $state = static fn(array $snapshot): string => json_encode([
            reset($snapshot['progress'])->json,
            reset($snapshot['progress'])->lastseq ?? null,
            count($snapshot['progresssteps']),
        ]);
        $this->assertNotSame($state($before), $state($after), 'B\'s progress took the answer');
    }
}
