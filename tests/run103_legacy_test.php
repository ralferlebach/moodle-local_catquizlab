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

use local_catquizlab\external\sitting_state;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\engine_identity;
use local_catquizlab\local\experiment_resume;
use local_catquizlab\local\experiment_stop;
use local_catquizlab\local\registry;
use local_catquizlab\local\resume_integrity;
use local_catquizlab\local\run_log;
use local_catquizlab\local\setup_wizard;
use local_catquizlab\local\sitting_recovery;

/**
 * A run like CLARA run 103, made up: its shape, none of its data (#173 DoD04/DoD05, #174 AK07–AK09/DoD05; re-audit
 * 27.11.2026).
 *
 * As the operator's SQL found it: a CLASSIC run of 2500 items, 50 sittings (25 failed, 25 queued), none bound. Each
 * person has two engine attempts on the activity: generation A, begun on 16.11. and closed by the engine's time limit
 * a day later with 1362 to 2459 answers; generation B, begun on 27.11. and open, 49 with 1000 answers and one with
 * 1264. The try counter of 24 sittings was reset to 1 by a requeue; their histories have no binding and no engine
 * attempt — the record of 0.7.83 and earlier.
 *
 * The audit must count 50 ambiguous sittings, 50 attempts closed by the time limit, 50 incomplete, 0 regular, and no
 * resume it cannot establish as harmless; reading must change nothing, marking only add history rows. Resuming must
 * hold the ambiguous sittings instead of playing them; a person's decision must bind, or start a new generation, and
 * be kept.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\resume_integrity
 * @covers     \local_catquizlab\local\experiment_resume
 * @covers     \local_catquizlab\local\sitting_recovery
 * @covers     \local_catquizlab\local\engine_identity
 */
final class run103_legacy_test extends \advanced_testcase {
    /** @var int The activity instance of the made-up run. */
    protected const INSTANCE = 9103;

    /** @var int 16.11.2026 08:00 UTC: generation A begins. */
    protected const GEN_A = 1794816000;

    /** @var int 27.11.2026 02:00 UTC: generation B begins. */
    protected const GEN_B = 1795744800;

    /**
     * Admin, a site ready to run.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
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
     * The made-up run 103.
     *
     * @param int $n How many sittings (50 as on CLARA).
     * @param int $runstatus The run's status.
     * @return array{0: int, 1: int[], 2: int} The run, its sittings (in order), its experiment.
     */
    protected function run103(int $n = 50, int $runstatus = registry::STATUS_RUNNING): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('Needs mod_adaptivequiz.');
        }
        /** @var \local_catquizlab_generator $lab */
        $lab = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $lab->create_experiment()->id;
        $course = $this->getDataGenerator()->create_course();
        $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
            'instance' => self::INSTANCE, 'section' => 0, 'added' => time()]);
        $runid = (int) $lab->create_run(['experimentid' => $experimentid, 'status' => $runstatus, 'testcmid' => $cmid,
            'manifestjson' => json_encode(['config' => ['definition' => ['strategy' => 'classic']]])])->id;
        run_log::record($runid, run_log::PROVISIONING_READY);
        $items = [];
        for ($q = 1; $q <= 2500; $q++) {
            $items[] = (object) ['runid' => $runid, 'questionid' => 700000 + $q, 'itemname' => 'i' . $q,
                'timecreated' => self::GEN_A, 'timemodified' => self::GEN_A];
        }
        $DB->insert_records('local_catquizlab_item', $items);
        $columns = $DB->get_columns('adaptivequiz_attempt');
        $timeout = get_string('attemptclosedbytimelimit', 'local_catquiz');
        $sittings = [];
        for ($i = 0; $i < $n; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $personid = (int) $DB->insert_record('local_catquizlab_person', (object) ['runid' => $runid, 'twinindex' => $i + 1,
                'severity' => 'none', 'stratum' => 'conforming', 'moodleuserid' => $user->id, 'timecreated' => self::GEN_A,
                'timemodified' => self::GEN_A]);
            // 26 with their try counter as the executions left it, 24 reset to 1 by a requeue.
            $tries = $i < 26 ? 2 + ($i % 2) : 1;
            $status = $i < 25 ? attempt_scheduler::STATUS_FAILED : attempt_scheduler::STATUS_QUEUED;
            $sitting = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid,
                'personid' => $personid, 'status' => $status, 'tries' => $tries, 'nextruntime' => 0,
                'lasterror' => 'Attempt did not reach the finish page after 1000 answer(s).',
                'timecreated' => self::GEN_A, 'timemodified' => self::GEN_B + 7200]);
            $sittings[] = $sitting;
            // Generation A: closed by the time limit a day after it began, 1362 … 2459 answered.
            $answereda = 1362 + intdiv(($i * (2459 - 1362)), max(1, $n - 1));
            $a = ['instance' => self::INSTANCE, 'userid' => $user->id, 'uniqueid' => 27326 + $i, 'attemptstate' => 'complete',
                'attemptstopcriteria' => $timeout, 'questionsattempted' => $answereda, 'timecreated' => self::GEN_A + $i,
                'timemodified' => self::GEN_A + DAYSECS + 300 + $i];
            // Generation B: open, 1000 answered (one 1264).
            $b = ['instance' => self::INSTANCE, 'userid' => $user->id, 'uniqueid' => 27526 + $i, 'attemptstate' => 'inprogress',
                'attemptstopcriteria' => '', 'questionsattempted' => $i === 43 ? 1264 : 1000, 'timecreated' => self::GEN_B + $i,
                'timemodified' => self::GEN_B + 5400 + $i];
            if (isset($columns['stopcode'])) {
                $a['stopcode'] = 'timelimit';
            }
            $DB->insert_record('adaptivequiz_attempt', (object) $a);
            $DB->insert_record('adaptivequiz_attempt', (object) $b);
            // The record as 0.7.83 left it: executions, failures, requeues; no binding, no engine attempt named.
            $rows = [[1, attempt_history::STARTED, self::GEN_A + 60], [1, attempt_history::FAILED, self::GEN_A + 3600],
                [2, attempt_history::STARTED, self::GEN_A + 3700], [2, attempt_history::FAILED, self::GEN_A + 7200]];
            if ($tries === 3) {
                $rows[] = [3, attempt_history::STARTED, self::GEN_A + 7300];
                $rows[] = [3, attempt_history::FAILED, self::GEN_A + 9000];
            }
            if ($tries === 1) {
                $rows[] = [0, attempt_history::REQUEUED, self::GEN_B - 600];
                $rows[] = [1, attempt_history::STARTED, self::GEN_B + $i];
                $rows[] = [1, attempt_history::FAILED, self::GEN_B + 5400];
            }
            foreach ($rows as [$tryno, $outcome, $when]) {
                $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $sitting, 'runid' => $runid,
                    'tryno' => $tryno, 'outcome' => $outcome, 'reasoncode' => $outcome === attempt_history::FAILED
                    ? 'navigation_error' : null, 'detail' => $outcome === attempt_history::FAILED
                    ? 'Attempt did not reach the finish page after 1000 answer(s).' : null, 'timecreated' => $when]);
            }
        }

        return [$runid, $sittings, $experimentid];
    }

    /**
     * The audit finds what the operator's SQL found, reads without changing anything, and marks once (#174 DoD05).
     *
     * @return void
     */
    public function test_the_audit_reproduces_the_manual_diagnosis(): void {
        global $DB;
        [$runid, $sittings] = $this->run103();
        $snapshot = static fn(): array => [
            $DB->get_records('local_catquizlab_attempt', ['runid' => $runid], 'id', 'id, status, tries, engineattemptid, '
                . 'tracejson, lasterror, executionid'),
            $DB->count_records('local_catquizlab_attemptlog', ['runid' => $runid]),
            $DB->get_records('adaptivequiz_attempt', ['instance' => self::INSTANCE], 'id'),
        ];
        $before = $snapshot();

        // The fix was installed at the time 0.7.84 reached CLARA: after both generations' failures.
        $audit = resume_integrity::audit($runid, self::GEN_B + 16 * HOURSECS);
        $this->assertEquals($before, $snapshot(), 'reading changes nothing');
        // Resumes: 13 sittings with two executions (1 each), 13 with three (2 each), 24 reset to 1 after two (2 each).
        $this->assertSame(['sittings' => 50, 'engineattempts' => 100, 'resumes' => 13 + 26 + 48], $audit['denominators']);
        $this->assertSame(2500, $audit['form']);
        $c = $audit['counts'];
        $this->assertSame(50, $c[resume_integrity::AMBIGUOUS]);
        $this->assertSame(50, $c[resume_integrity::TIMEOUT]);
        $this->assertSame(50, $c[resume_integrity::INCOMPLETE]);
        $this->assertSame(0, $c[resume_integrity::REGULAR]);
        $this->assertSame(0, $c[resume_integrity::NO_ENGINE], 'two attempts each: never "no engine attempt"');
        $this->assertSame(
            0,
            $c[resume_integrity::AT_RISK] + $c[resume_integrity::AFTER_FIX],
            'nothing claimed about a resume that is not on record'
        );
        $this->assertSame($audit['denominators']['resumes'], $c[resume_integrity::RESUME_UNKNOWN]);
        // Every sitting is in the report, whatever its try counter says, with every one of its findings.
        $this->assertSame($sittings, array_column($audit['sittings'], 'sitting'));
        foreach ($audit['sittings'] as $s) {
            $this->assertContains(resume_integrity::AMBIGUOUS, $s['findings']);
            $this->assertContains(resume_integrity::TIMEOUT, $s['findings']);
            $this->assertContains(resume_integrity::INCOMPLETE, $s['findings']);
            $this->assertContains(resume_integrity::RESUME_UNKNOWN, $s['findings']);
            $this->assertCount(2, $s['attempts']);
        }
        $one = array_values(array_filter($audit['sittings'], static fn($s) => $s['sitting'] === $sittings[43]))[0];
        $this->assertSame([1264, 'inprogress', resume_integrity::INCOMPLETE], [$one['attempts'][1]['answered'],
            $one['attempts'][1]['state'], $one['attempts'][1]['finding']]);
        // The server's own view of each: ambiguous, never "the newest" (#173).
        $this->assertSame(engine_identity::AMBIGUOUS, sitting_state::state($sittings[0])['reason']);

        // Marked once per sitting and finding; the sittings, their engine attempts and results stay as they were.
        $marked = resume_integrity::mark($audit);
        $this->assertSame(150, $marked, 'ambiguous, timeout and resume-unknown for each of 50');
        $this->assertSame(0, resume_integrity::mark($audit), 'idempotent');
        [$rows, , $engines] = $snapshot();
        $this->assertEquals($before[0], $rows);
        $this->assertEquals($before[2], $engines);
    }

    /**
     * Stopped and resumed: the ambiguous sittings are held, not played; nothing is bound by itself (#173 AK08).
     *
     * @return void
     */
    public function test_resuming_holds_the_ambiguous_sittings(): void {
        global $DB;
        [, $sittings, $experimentid] = $this->run103(3, registry::STATUS_RUNNING);
        $DB->set_field_select('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_QUEUED, 'id > 0');
        experiment_stop::stop($experimentid);
        $done = experiment_resume::resume($experimentid);
        $this->assertTrue($done['ok'], $done['reason']);
        $this->assertSame(3, $done['ambiguous']);
        $this->assertSame(0, $done['collecting']);
        foreach ($sittings as $sitting) {
            $row = $DB->get_record('local_catquizlab_attempt', ['id' => $sitting]);
            $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $row->status, 'held, not queued');
            $this->assertEmpty($row->engineattemptid, 'not bound by itself');
            $this->assertStringStartsWith('[identity:' . engine_identity::AMBIGUOUS . ']', (string) $row->lasterror);
            $held = $DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $sitting,
                'outcome' => attempt_history::HELD]);
            $this->assertSame('identity_mismatch', reset($held)->reasoncode);
        }
        $this->assertSame(6, $DB->count_records('adaptivequiz_attempt', ['instance' => self::INSTANCE]), 'nothing started');
    }

    /**
     * A person decides: bind generation B, or start a new generation, or keep the end — each kept, none twice.
     *
     * @return void
     */
    public function test_a_person_decides_and_the_decision_is_kept(): void {
        global $DB;
        [, [$first, $second, $third]] = $this->run103(3);
        $attempts = static fn(int $s): array => array_column(sitting_recovery::inspect($s)['candidates'], 'engineattemptid');

        // Inspecting reads only.
        $inspect = sitting_recovery::inspect($first);
        $this->assertCount(2, $inspect['candidates']);
        $this->assertSame(engine_identity::AMBIGUOUS, $inspect['resolved']['reason']);
        [$a, $b] = $attempts($first);

        // Bind B: the sitting's attempt is B from now on; asked twice, it changes nothing the second time.
        $this->assertSame(
            ['ok' => false, 'changed' => false, 'reason' => 'no-note', 'retired' => []],
            sitting_recovery::decide($first, sitting_recovery::BIND, ' ', $b)
        );
        $this->assertTrue(sitting_recovery::decide(
            $first,
            sitting_recovery::BIND,
            'operator: B is its own',
            $b,
            2
        )['changed']);
        $this->assertFalse(sitting_recovery::decide($first, sitting_recovery::BIND, 'again', $b, 2)['changed']);
        $this->assertSame('bound-to-another', sitting_recovery::decide($first, sitting_recovery::BIND, 'A now', $a, 2)['reason']);
        $state = sitting_state::state($first);
        $this->assertSame([true, $b, 'inprogress'], [$state['found'], $state['engineattemptid'], $state['state']]);

        // A new generation: both retired, kept in the engine and in the record; the next execution may start one.
        [$a2, $b2] = $attempts($second);
        $decided = sitting_recovery::decide($second, sitting_recovery::NEW_GENERATION, 'operator: play it again', 0, 2);
        $this->assertSame([true, true, [$a2, $b2]], [$decided['ok'], $decided['changed'], $decided['retired']]);
        $this->assertSame(engine_identity::NO_ENGINE_ATTEMPT, sitting_state::state($second)['reason']);
        $retired = $DB->get_records('local_catquizlab_retired', ['attemptid' => $second], 'engineattemptid');
        $this->assertSame([[$a2, 'complete'], [$b2, 'inprogress']], array_values(array_map(
            static fn($r) => [(int) $r->engineattemptid, (string) $r->state],
            $retired
        )));
        $this->assertSame(2, $DB->count_records('adaptivequiz_attempt', ['id' => $a2]) + $DB->count_records(
            'adaptivequiz_attempt',
            ['id' => $b2]
        ), 'the predecessors stay in the engine');
        $this->assertFalse(sitting_recovery::decide($second, sitting_recovery::NEW_GENERATION, 'again', 0, 2)['changed']);
        // Its next generation: a third attempt is the only one that counts; binding it is not ambiguous.
        $userid = (int) $DB->get_field('local_catquizlab_person', 'moodleuserid', ['id' => (int) $DB->get_field(
            'local_catquizlab_attempt',
            'personid',
            ['id' => $second]
        )]);
        $usage = 99000;
        $c = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => self::INSTANCE, 'userid' => $userid,
            'uniqueid' => $usage, 'attemptstate' => 'inprogress', 'attemptstopcriteria' => '', 'questionsattempted' => 0,
            'timecreated' => time(), 'timemodified' => time()]);
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $second, 'status' => attempt_scheduler::STATUS_RUNNING,
            'executionid' => 77, 'leaseowner' => 'w9', 'leaseexpires' => time() + 300]);
        $retiredusage = (int) $DB->get_field('adaptivequiz_attempt', 'uniqueid', ['id' => $b2]);
        $this->assertSame(
            engine_identity::RETIRED,
            engine_identity::bind($second, $retiredusage, 'w9', 77)['reason'],
            'a retired attempt is never bound again'
        );
        $bound = engine_identity::bind($second, $usage, 'w9', 77);
        $this->assertTrue($bound['found'], $bound['reason']);
        $this->assertSame($c, $bound['engineattemptid']);
        $this->assertSame('running', sitting_recovery::decide($second, sitting_recovery::KEEP, 'while it runs', 0, 2)['reason']);

        // Keep: recorded once, nothing changed.
        $before = $DB->get_record('local_catquizlab_attempt', ['id' => $third]);
        $this->assertTrue(sitting_recovery::decide($third, sitting_recovery::KEEP, 'the end stands', 0, 2)['changed']);
        $this->assertFalse(sitting_recovery::decide($third, sitting_recovery::KEEP, 'the end stands', 0, 2)['changed']);
        $this->assertEquals($before, $DB->get_record('local_catquizlab_attempt', ['id' => $third]));
        $decisions = $DB->get_records(
            'local_catquizlab_attemptlog',
            ['outcome' => attempt_history::DECIDED],
            'id',
            'id, attemptid, reasoncode, executionid'
        );
        $this->assertSame(
            ['recovery_bind', 'recovery_newgeneration', 'recovery_keep'],
            array_values(array_column($decisions, 'reasoncode'))
        );
        $this->assertSame(
            [null, null, null],
            array_values(array_column($decisions, 'executionid')),
            'a decision is no execution'
        );
    }
}
