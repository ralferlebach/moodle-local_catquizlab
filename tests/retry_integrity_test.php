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

use local_catquizlab\external\job_claim;
use local_catquizlab\external\job_complete;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\circuit_breaker;
use local_catquizlab\local\reason_catalog;
use local_catquizlab\local\registry;
use local_catquizlab\local\resume_integrity;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\sitting_budget;

/**
 * A failed execution keeps what its retry needs, a broken invariant is held instead of retried, and past runs can
 * be audited for the sittings that were not (#174, #175, #172).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\external\job_complete
 * @covers     \local_catquizlab\external\job_claim
 * @covers     \local_catquizlab\local\attempt_scheduler
 * @covers     \local_catquizlab\local\circuit_breaker
 * @covers     \local_catquizlab\local\reason_catalog
 * @covers     \local_catquizlab\local\resume_integrity
 * @covers     \local_catquizlab\local\sitting_budget
 */
final class retry_integrity_test extends \advanced_testcase {
    /** @var int The context the fixture's run maps its scales to. */
    protected const CONTEXT = 987;

    /**
     * A run with one scale in a context of its own, and $n running sittings held by "w1".
     *
     * @param int $n How many sittings.
     * @return array{0: int, 1: int[], 2: int[]} The run, its sittings, their accounts.
     */
    protected function run_with_sittings(int $n): array {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run(['status' => registry::STATUS_RUNNING]);
        $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $run->id, 'catscaleid' => 51,
            'parentcatscaleid' => 0, 'contextid' => self::CONTEXT, 'level' => 0, 'nodekey' => 'root', 'generation' => 1,
            'name' => 'root', 'timecreated' => time(), 'timemodified' => time()]);
        $sittings = [];
        $users = [];
        for ($i = 0; $i < $n; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $person = $generator->create_person(['runid' => $run->id, 'moodleuserid' => $user->id]);
            $sittings[] = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id,
                'personid' => $person->id, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1, 'executionid' => 1,
                'leaseowner' => 'w1', 'leaseexpires' => time() + 600, 'timecreated' => time() - $n + $i,
                'timemodified' => time() - $n + $i]);
            $users[] = (int) $user->id;
        }

        return [(int) $run->id, $sittings, $users];
    }

    /**
     * The engine's intermediate estimate of one person in the fixture's context, as an attempt in progress keeps it.
     *
     * @param int $userid The account.
     * @param int $catattemptid The engine's CAT attempt.
     * @return int The row.
     */
    protected function provisional(int $userid, int $catattemptid): int {
        global $DB;

        $row = ['userid' => $userid, 'catscaleid' => 51, 'contextid' => self::CONTEXT, 'attemptid' => $catattemptid,
            'ability' => 0.73, 'standarderror' => 0.41, 'isprimary' => 0, 'isvalid' => 0, 'rangebackfilled' => 0,
            'timecreated' => time(), 'timemodified' => time()];
        $columns = $DB->get_columns('local_catquiz_personparams');
        if (isset($columns['validationstatus'])) {
            $row['validationstatus'] = 'provisional';
        }

        return (int) $DB->insert_record('local_catquiz_personparams', (object) $row);
    }

    /**
     * A failed execution leaves the engine's intermediate estimates where its retry continues from them (#174 AK01).
     *
     * Until 0.7.83 every failed report deleted them; the retry's next estimate then started from the default ability.
     *
     * @return void
     */
    public function test_a_failed_execution_keeps_the_engine_estimates_for_its_retry(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!$DB->get_manager()->table_exists('local_catquiz_personparams')) {
            $this->markTestSkipped('Needs the CAT engine.');
        }
        [, [$sitting], [$userid]] = $this->run_with_sittings(1);
        $row = $this->provisional($userid, 5001);

        job_complete::execute(
            $sitting,
            'failed',
            1000,
            0,
            '[state:unavailable] sitting_state gave no answer',
            '',
            'w1',
            'state_unavailable',
            1
        );

        $this->assertSame(
            attempt_scheduler::STATUS_QUEUED,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $sitting]),
            'retried'
        );
        $this->assertTrue($DB->record_exists('local_catquiz_personparams', ['id' => $row]), 'kept for the retry');

        // A second failure, the tries used up: failed for good, and still kept — a person may put it back.
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $sitting, 'status' => attempt_scheduler::STATUS_RUNNING,
            'tries' => attempt_scheduler::MAX_TRIES, 'executionid' => attempt_scheduler::MAX_TRIES, 'leaseowner' => 'w1',
            'leaseexpires' => time() + 300]);
        job_complete::execute(
            $sitting,
            'failed',
            1000,
            0,
            'Attempt did not reach the finish page',
            '',
            'w1',
            '',
            attempt_scheduler::MAX_TRIES
        );
        $this->assertSame(
            attempt_scheduler::STATUS_FAILED,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $sitting])
        );
        $this->assertTrue($DB->record_exists('local_catquiz_personparams', ['id' => $row]));

        // The intermediate estimate is nobody else's prior (engine issue #164): not among the admissible abilities.
        if (class_exists('\\local_catquiz\\local\\result\\personparam_repository')) {
            $population = \local_catquiz\catquiz::get_person_abilities(self::CONTEXT, [51]);
            $this->assertSame([], array_values(array_filter($population, static fn($p) => (int) $p->userid === $userid)));
        }
    }

    /**
     * A broken invariant is held at once: failed with its code, not retried, the estimates kept (#175 AK02).
     *
     * @return void
     */
    public function test_a_broken_invariant_is_held_not_retried(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$runid, [$sitting]] = $this->run_with_sittings(1);

        job_complete::execute($sitting, 'failed', 1000, 0, '[guard:budget] 2500 answer(s) confirmed and usage 7 presents slot '
            . '2501', '{"progress":{"answered":2500,"budget":2500}}', 'w1', 'budget_violation', 1);

        $row = $DB->get_record('local_catquizlab_attempt', ['id' => $sitting]);
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $row->status, 'held');
        $this->assertSame(1, (int) $row->tries, 'no try spent on a retry');
        $history = attempt_history::of_attempt($sitting);
        $this->assertSame([attempt_history::FAILED, attempt_history::HELD], array_column($history, 'outcome'));
        $this->assertSame(['budget_violation', 'budget_violation'], array_column($history, 'reasoncode'));
        $this->assertSame(2500, $history[0]['diagnosis']['progress']['answered']);

        // Put back by a person: queued again, counters cleared, the history kept.
        $this->assertSame(1, run_lifecycle::requeue_failed($runid));
        $this->assertSame(
            attempt_scheduler::STATUS_QUEUED,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $sitting])
        );
    }

    /**
     * The code decides, recorded before the decision: the worker's own, or read from the message (#175 AK01).
     *
     * @return void
     */
    public function test_the_code_is_the_workers_or_read_from_its_message(): void {
        $this->assertSame('no_progress', reason_catalog::reported('no_progress', 'Attempt did not reach the finish page'));
        $this->assertSame('budget_violation', reason_catalog::reported('', '[guard:budget] 1000 answer(s) confirmed'));
        $this->assertSame('identity_mismatch', reason_catalog::reported('', '[submit:http_404] x [identity:quba-mismatch] y'));
        $this->assertSame('state_unavailable', reason_catalog::reported('', '[state:unavailable] sitting_state ...'));
        // The host's own word that the attempt is gone (mod_adaptivequiz #27): an identity in doubt, held.
        $this->assertSame('identity_mismatch', reason_catalog::reported('', 'Moodle error page on attempt.php: '
            . 'errorcode="attemptlost" "This attempt does not exist any more"'));
        $this->assertSame('identity_mismatch', reason_catalog::failure('error page', ['errorcode' => 'attemptlost']));
        // What a worker may not claim is read from the message instead.
        $this->assertSame('navigation_error', reason_catalog::reported(
            'engine_exception',
            'Attempt did not reach the finish page after 1000 answer(s).'
        ));
        $this->assertSame('navigation_error', reason_catalog::reported(
            'banana',
            'Attempt did not reach the finish page after 1000 answer(s).'
        ));
        foreach (reason_catalog::WORKER_REASONS as $code) {
            $this->assertContains($code, reason_catalog::FAILURES);
            $this->assertNotSame($code, reason_catalog::label($code), "$code is labelled");
        }
        foreach (circuit_breaker::HOLD_CAUSES as $code) {
            $this->assertSame('deterministic', circuit_breaker::category($code));
            $this->assertTrue(circuit_breaker::holds($code));
        }
        $this->assertSame('capacity', circuit_breaker::category('state_unavailable'));
        $this->assertFalse(circuit_breaker::holds('state_unavailable'));
    }

    /**
     * Three sittings held for the same invariant hold the run; a retry storm does not happen (#175 AK02).
     *
     * @return void
     */
    public function test_three_held_sittings_of_one_cause_hold_the_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$runid, $sittings] = $this->run_with_sittings(4);

        foreach (array_slice($sittings, 0, 2) as $sitting) {
            job_complete::execute($sitting, 'failed', 1000, 0, '[guard:budget] x', '', 'w1', 'budget_violation', 1);
        }
        $this->assertFalse(run_lifecycle::is_paused($runid), 'two are two sittings');
        job_complete::execute($sittings[2], 'failed', 1000, 0, '[guard:budget] x', '', 'w1', 'budget_violation', 1);
        $this->assertTrue(run_lifecycle::is_paused($runid), 'three are the run');
        $this->assertStringContainsString('budget_violation', run_lifecycle::pause_reason($runid));
        $this->assertSame(3, $DB->count_records('local_catquizlab_attemptlog', ['outcome' => attempt_history::FAILED]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_attemptlog', ['outcome' => attempt_history::REQUEUED]));
    }

    /**
     * A lost lease, an operator's stop: neither counts towards the run failing (#175 AK04).
     *
     * @return void
     */
    public function test_lost_leases_are_not_the_run_failing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$runid, $sittings] = $this->run_with_sittings(circuit_breaker::THRESHOLD);
        foreach ($sittings as $sitting) {
            $DB->update_record('local_catquizlab_attempt', (object) ['id' => $sitting,
                'status' => attempt_scheduler::STATUS_FAILED, 'lasterror' => '[lease:lost] gone']);
            attempt_history::record($sitting, attempt_history::FAILED, ['detail' => '[lease:lost] gone']);
        }
        $streak = circuit_breaker::failure_streak($runid);
        $this->assertSame(0, $streak['count']);
        $this->assertFalse(circuit_breaker::check($runid)['tripped']);
    }

    /**
     * The claim: a worker without the contract gets nothing; a sitting comes with its run's pool as the bound (#172).
     *
     * @return void
     */
    public function test_the_claim_speaks_the_contract_and_carries_the_budget(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run(['status' => registry::STATUS_READY]);
        $person = $generator->create_person(['runid' => $run->id]);
        $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id, 'personid' => $person->id,
            'status' => attempt_scheduler::STATUS_QUEUED, 'tries' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        for ($i = 1; $i <= 25; $i++) {
            $DB->insert_record('local_catquizlab_item', (object) ['runid' => $run->id, 'questionid' => $i,
                'itemname' => 'i' . $i, 'model' => 'raschbirnbaum', 'truedifficulty' => 0, 'storeddifficulty' => 0,
                'discrimination' => 1, 'guessing' => 0, 'truecategory' => 0, 'truesubscale' => 0, 'miscalibrated' => 0,
                'mistagged' => 0, 'timecreated' => time()]);
        }

        $old = job_claim::execute('w-old', 0);
        $this->assertFalse($old['hasjob']);
        $this->assertSame(get_string('job:outdatedworker', 'local_catquizlab', job_claim::WORKER_CONTRACT), $old['message']);

        $job = job_claim::execute('w-new');
        $this->assertTrue($job['hasjob']);
        $this->assertSame(25, $job['budget']);
        $this->assertSame(sitting_budget::KIND_POOL, $job['budgetkind']);
        $this->assertSame(job_claim::WORKER_CONTRACT, $job['contract']);
        $this->assertSame(
            ['limit' => 0, 'kind' => sitting_budget::KIND_UNKNOWN, 'expected' => 0],
            sitting_budget::for_run(987654),
            'no pool, no bound invented'
        );
    }

    /**
     * A past run: which sittings went on after a failure that deleted the estimates, marked once (#174 AK05).
     *
     * @return void
     */
    public function test_a_past_run_is_audited_and_marked_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('Needs mod_adaptivequiz.');
        }
        [$runid, [$risky, $later, $noengine, $untouched], $users] = $this->run_with_sittings(4);
        $t = time() - 7200;
        // The run's activity, and an engine attempt for the first two, begun before their first failure.
        $course = $this->getDataGenerator()->create_course();
        $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
            'instance' => 4714, 'section' => 0, 'added' => time()]);
        $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $runid]);
        foreach ([0 => $t, 1 => $t + 5000] as $index => $failedat) {
            $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => 4714, 'userid' => $users[$index],
                'uniqueid' => 0, 'attemptstate' => 'complete', 'attemptstopcriteria' => '', 'questionsattempted' => 1300,
                'timecreated' => $failedat - 900, 'timemodified' => time()]);
        }
        foreach ([$risky => $t, $later => $t + 5000, $noengine => $t] as $sitting => $failedat) {
            $DB->set_field('local_catquizlab_attempt', 'tries', 2, ['id' => $sitting]);
            $rows = [
                [1, attempt_history::STARTED, null, null, $failedat - 600],
                [1, attempt_history::FAILED, 'navigation_error', 'Attempt did not reach the finish page after 1000 answer(s).',
                    $failedat],
                [2, attempt_history::STARTED, null, null, $failedat + 60],
            ];
            foreach ($rows as [$tryno, $outcome, $code, $detail, $when]) {
                $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $sitting, 'runid' => $runid,
                    'tryno' => $tryno, 'outcome' => $outcome, 'reasoncode' => $code, 'detail' => $detail,
                    'timecreated' => $when]);
            }
        }
        // The fix arrived between the two failures.
        $audit = resume_integrity::audit($runid, $t + 3000);
        $by = array_column($audit['sittings'], null, 'sitting');
        $resumes = static fn(int $s): array => array_column($by[$s]['resumes'], 'finding');
        // One engine attempt, created before the failure: the resume can only have continued it (said as inferred).
        $this->assertSame([resume_integrity::AT_RISK], $resumes($risky));
        $this->assertSame('single-attempt-created-before-the-failure', $by[$risky]['resumes'][0]['evidence']);
        $this->assertSame([resume_integrity::AFTER_FIX], $resumes($later));
        // No engine attempt today: which went on is not on record — not "nothing was continued" (re-audit 27.11.2026).
        $this->assertSame([resume_integrity::RESUME_UNKNOWN], $resumes($noengine));
        $this->assertContains(resume_integrity::NO_ENGINE, $by[$noengine]['findings']);
        // Every sitting is reported, the one never resumed too; nothing is counted as harmless.
        $this->assertSame([resume_integrity::NO_ENGINE], $by[$untouched]['findings']);
        $this->assertSame([], $by[$untouched]['resumes']);
        $this->assertSame([1, 1, 1, 2], [$audit['counts'][resume_integrity::AT_RISK],
            $audit['counts'][resume_integrity::AFTER_FIX], $audit['counts'][resume_integrity::RESUME_UNKNOWN],
            $audit['counts'][resume_integrity::NO_ENGINE]]);
        $this->assertSame(['sittings' => 4, 'engineattempts' => 2, 'resumes' => 3], $audit['denominators']);
        $this->assertSame(1000, $by[$risky]['resumes'][0]['answeredbefore']);

        // Marked once per sitting and finding, results untouched.
        $before = $DB->get_record('local_catquizlab_attempt', ['id' => $risky]);
        $this->assertSame(2, resume_integrity::mark($audit));
        $this->assertSame(0, resume_integrity::mark($audit), 'once');
        $this->assertEquals($before, $DB->get_record('local_catquizlab_attempt', ['id' => $risky]));
        $marks = $DB->get_records_menu(
            'local_catquizlab_attemptlog',
            ['outcome' => attempt_history::AUDITED],
            'attemptid',
            'attemptid, reasoncode'
        );
        $expected = [$risky => resume_integrity::REASON, $noengine => 'audit_resume_unknown'];
        ksort($expected);
        $this->assertSame($expected, array_map('strval', $marks));

        // Before any upgrade to the fix, every such resume is at risk.
        $this->assertSame(2, resume_integrity::audit($runid, null)['counts'][resume_integrity::AT_RISK]);
    }
}
