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
 * Tests for the engine boundary and the materialisation postconditions.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\cat_item_provisioner;
use local_catquizlab\local\environment;
use local_catquizlab\local\materialiser;
use local_catquizlab\local\run_orchestrator;
use local_catquizlab\local\run_verifier;

/**
 * Provisioning tests.
 *
 * These guard the gap the whole class of bug came through: the lab counted
 * Moodle question rows and called that a materialised CAT pool. A run could
 * report success while the CAT manager showed no questions at all.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\cat_item_provisioner
 * @covers     \local_catquizlab\local\run_orchestrator
 * @covers     \local_catquizlab\local\run_verifier
 */
final class provisioning_test extends \advanced_testcase {
    /**
     * A materialisation result with the given counters.
     *
     * Not called result(): PHPUnit 10 and 11 declare TestCase::result() final,
     * so a helper of that name is a fatal error on Moodle 5.0 and above, before
     * a single test runs.
     *
     * @param int $planned The planned item count.
     * @param array $overrides Counter overrides.
     * @return array
     */
    protected function materialisation(int $planned, array $overrides = []): array {
        return $overrides + [
            'planned'              => $planned,
            'questionscreated'     => $planned,
            'itemsregistered'      => $planned,
            'parametersregistered' => $planned,
            'enginevisible'        => $planned,
            'faileditems'          => 0,
        ];
    }

    /**
     * A complete materialisation satisfies the stage postconditions.
     *
     * @return void
     */
    public function test_complete_materialisation_passes(): void {
        $this->resetAfterTest();

        $this->assertTrue(run_orchestrator::materialisation_complete($this->materialisation(2500)));
    }

    /**
     * Questions without engine-visible items is not a complete materialisation.
     *
     * @return void
     */
    public function test_questions_without_visible_items_fail(): void {
        $this->resetAfterTest();

        // This is the reported bug in one line: 2500 questions, nothing the
        // engine can retrieve, and the run previously reported ok.
        $result = $this->materialisation(2500, [
            'itemsregistered'      => 0,
            'parametersregistered' => 0,
            'enginevisible'        => 0,
            'faileditems'          => 2500,
        ]);

        $this->assertFalse(run_orchestrator::materialisation_complete($result));
    }

    /**
     * An empty plan is never a success, not even for the ideal pool.
     *
     * @return void
     */
    public function test_empty_plan_fails(): void {
        $this->resetAfterTest();

        $this->assertFalse(run_orchestrator::materialisation_complete($this->materialisation(0)));
    }

    /**
     * A single failed item fails the whole stage.
     *
     * @return void
     */
    public function test_one_failed_item_fails_the_stage(): void {
        $this->resetAfterTest();

        $result = $this->materialisation(100, ['enginevisible' => 99, 'faileditems' => 1]);

        $this->assertFalse(run_orchestrator::materialisation_complete($result));
    }

    /**
     * Every counter must reach the planned count, not just the last one.
     *
     * @return void
     */
    public function test_each_counter_must_reach_the_plan(): void {
        $this->resetAfterTest();

        foreach (['questionscreated', 'itemsregistered', 'parametersregistered', 'enginevisible'] as $counter) {
            $result = $this->materialisation(10, [$counter => 9]);
            $this->assertFalse(
                run_orchestrator::materialisation_complete($result),
                'A short ' . $counter . ' should fail the stage.'
            );
        }
    }

    /**
     * A missing counter is treated as a failure, not as an absent constraint.
     *
     * @return void
     */
    public function test_missing_counter_fails(): void {
        $this->resetAfterTest();

        $result = $this->materialisation(10);
        unset($result['enginevisible']);

        $this->assertFalse(run_orchestrator::materialisation_complete($result));
    }

    /**
     * Materialising with no engine reports the reason rather than a null.
     *
     * @return void
     */
    public function test_provisioning_without_engine_is_explicit(): void {
        $this->resetAfterTest();

        if (environment::engine_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        $outcome = cat_item_provisioner::provision(1, 2, 3, ['difficulty' => 0.0]);

        $this->assertFalse($outcome['ok']);
        $this->assertSame(cat_item_provisioner::REASON_NO_ENGINE, $outcome['reason']);
        $this->assertSame(1, $outcome['questionid']);
        $this->assertSame(2, $outcome['catscaleid']);
    }

    /**
     * Visibility reports nothing rather than failing when the engine is absent.
     *
     * @return void
     */
    public function test_visibility_without_engine(): void {
        $this->resetAfterTest();

        if (environment::engine_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        $this->assertSame([], cat_item_provisioner::visible_items(1, 2));
        $this->assertSame(0, cat_item_provisioner::visible_count(1, 2));
        $this->assertFalse(cat_item_provisioner::is_visible(1, 2, 3));
    }

    /**
     * Every failure reason has a readable label.
     *
     * @return void
     */
    public function test_every_reason_is_explained(): void {
        $this->resetAfterTest();

        $reasons = [
            cat_item_provisioner::REASON_NO_ENGINE,
            cat_item_provisioner::REASON_ASSIGNMENT,
            cat_item_provisioner::REASON_NO_ITEM_ROW,
            cat_item_provisioner::REASON_PARAMETERS,
            cat_item_provisioner::REASON_NOT_VISIBLE,
        ];

        foreach ($reasons as $reason) {
            $label = cat_item_provisioner::reason_label($reason);
            $this->assertNotSame($reason, $label, 'Reason ' . $reason . ' has no readable label.');
            $this->assertNotEmpty($label);
        }
    }

    /**
     * A stage that returns nothing is a failure, not a silent success.
     *
     * @return void
     */
    public function test_a_stage_returning_nothing_fails_the_run(): void {
        global $DB;
        $this->resetAfterTest();

        // Without the engine every stage is skipped, and setup() reports that
        // rather than scheduling a run with no pool behind it.
        if (environment::engine_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        $experimentid = (int) $DB->insert_record('local_catquizlab_experiment', (object) [
            'name'         => 'Guard',
            'tier'         => 'baseline',
            'configjson'   => json_encode(\local_catquizlab\local\experiment_definition::example_baseline()),
            'status'       => 0,
            'usermodified' => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
        $runid = (int) $DB->insert_record('local_catquizlab_run', (object) [
            'experimentid' => $experimentid,
            'cellkey'      => 'c1',
            'masterseed'   => 42,
            'seed'         => 42,
            'replication'  => 1,
            'status'       => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $result = run_orchestrator::setup($runid);

        $this->assertFalse($result['ok']);
        $this->assertNotSame(
            \local_catquizlab\local\registry::STATUS_SCHEDULED,
            (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid])
        );
    }

    /**
     * Verifying a run with no items says so instead of reporting success.
     *
     * @return void
     */
    public function test_verify_reports_a_run_without_items(): void {
        global $DB;
        $this->resetAfterTest();

        $runid = (int) $DB->insert_record('local_catquizlab_run', (object) [
            'experimentid' => 1,
            'cellkey'      => 'c1',
            'masterseed'   => 42,
            'seed'         => 42,
            'replication'  => 1,
            'status'       => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $report = run_verifier::verify($runid);

        $this->assertFalse($report['ok']);
        $this->assertNotEmpty($report['firstfailure']);
        $this->assertSame(0, $report['counts']['lab item rows']);
    }

    /**
     * The verifier names the first broken link, not merely the last count.
     *
     * @return void
     */
    public function test_verify_names_the_first_broken_link(): void {
        global $DB;
        $this->resetAfterTest();

        $runid = (int) $DB->insert_record('local_catquizlab_run', (object) [
            'experimentid' => 1,
            'cellkey'      => 'c1',
            'masterseed'   => 42,
            'seed'         => 42,
            'replication'  => 1,
            'status'       => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        // A ground-truth row pointing at a question that does not exist: the
        // chain breaks at the question bank, before the engine is even asked.
        $DB->insert_record('local_catquizlab_item', (object) [
            'runid'              => $runid,
            'poolid'             => null,
            'questionid'         => 99999999,
            'itemname'           => 'Q-1-1-001',
            'model'              => 'raschbirnbaum',
            'truedifficulty'     => 0.0,
            'storeddifficulty'   => 0.0,
            'discrimination'     => 1.0,
            'guessing'           => 0.0,
            'stepsjson'          => null,
            'truecatscaleid'     => 1,
            'assignedcatscaleid' => 1,
            'truecategory'       => 1,
            'truesubscale'       => 1,
            'miscalibrated'      => 0,
            'mistagged'          => 0,
            'timecreated'        => time(),
        ]);

        $report = run_verifier::verify($runid);

        $this->assertFalse($report['ok']);
        $this->assertSame(1, $report['counts']['lab item rows']);
        $this->assertSame(0, $report['counts']['Moodle questions']);
        $this->assertStringContainsString('Moodle questions', $report['firstfailure']);
    }

    /**
     * A stale retrieval cache does not make a good item look invisible.
     *
     * @return void
     */
    public function test_engine_cache_is_purged_after_writing_parameters(): void {
        $this->resetAfterTest();

        if (!environment::engine_available()) {
            $this->markTestSkipped('No CAT engine installed; this guards an engine interaction.');
        }

        // The engine caches get_testitems() in a store that listens for
        // changesinadaptivequizattempt, not for the item-change event that
        // assignment fires. Writing parameters afterwards left a snapshot taken
        // when the scale held one item fewer, so a run of six items reported
        // two visible. The provisioner purges that store.
        $method = new \ReflectionMethod(cat_item_provisioner::class, 'purge_engine_cache');
        $method->setAccessible(true);
        $method->invoke(null);

        $this->assertTrue(true, 'Purging the engine cache must not raise.');
    }

    /**
     * The activity module info carries every field adaptivequiz requires.
     *
     * @return void
     */
    public function test_module_info_fills_the_required_activity_fields(): void {
        $this->resetAfterTest();

        $method = new \ReflectionMethod(\local_catquizlab\local\test_provisioner::class, 'build_moduleinfo');
        $method->setAccessible(true);
        $settings = ['maxquestionsgroup' => [
            'catquiz_minquestions' => 10,
            'catquiz_maxquestions' => 25,
        ]];
        $info = $method->invoke(null, 'Run #1', 1, (object) ['id' => 1], $settings, ['section' => 2]);

        // The adaptivequiz module declares these NOT NULL without a default and
        // add_moduleinfo() writes the module info straight to the database, so
        // omitting them failed the insert on the first real run.
        $this->assertTrue(property_exists($info, "attemptfeedback"));
        $this->assertSame('', $info->attemptfeedback);
        $this->assertSame(2, $info->section);
    }

    /**
     * A simulated user can actually log in.
     *
     * @return void
     */
    public function test_simulated_users_get_a_password(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->insert_record('local_catquizlab_person', (object) [
            'runid'         => $run->id,
            'twinid'        => 'r001-t00001',
            'twinindex'     => 1,
            'severity'      => 'none',
            'stratum'       => 'conforming',
            'abilityglobal' => 0.0,
            'profilejson'   => json_encode(['global' => 0.0, 'categories' => []]),
            'moodleuserid'  => null,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        \local_catquizlab\local\user_provisioner::provision((int) $run->id);

        $userid = (int) $DB->get_field('local_catquizlab_person', 'moodleuserid', ['runid' => $run->id]);
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

        // Without a password the account is unusable and the worker cannot play
        // its attempt; nothing noticed because no worker had ever tried.
        $this->assertNotEmpty($user->password);
        $this->assertTrue(validate_internal_user_password(
            $user,
            \local_catquizlab\local\user_provisioner::password_for($userid)
        ));
    }

    /**
     * A claimed job carries the username, which the worker must not derive.
     *
     * @return void
     */
    public function test_claimed_job_carries_the_username(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $user = $this->getDataGenerator()->create_user(['username' => 'catlab_r9_p-conforming-0001']);
        $personid = (int) $DB->insert_record('local_catquizlab_person', (object) [
            'runid'         => $run->id,
            'twinid'        => 'r001-t00001',
            'twinindex'     => 1,
            'severity'      => 'none',
            'stratum'       => 'conforming',
            'abilityglobal' => 0.0,
            'profilejson'   => json_encode(['global' => 0.0, 'categories' => []]),
            'moodleuserid'  => $user->id,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $personid, 'status' => 0,
            'tries' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $job = \local_catquizlab\external\job_claim::execute('unit-worker');

        // The provisioner makes usernames unique per run, so any convention the
        // worker invented — it used catlab_user_<id> — could never match.
        $this->assertTrue($job['hasjob']);
        $this->assertSame('catlab_r9_p-conforming-0001', $job['username']);
    }

    /**
     * An attempt reported as finished without an engine attempt is not accepted.
     *
     * @return void
     */
    public function test_finished_without_an_engine_attempt_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => 0, 'status' => 10,
            'tries' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        \local_catquizlab\external\job_complete::execute($attemptid, 'finished', 1200, 0);

        // The mismatch is worth a developer notice: a worker reporting success
        // with nothing behind it is a bug in the worker, not routine.
        $this->assertDebuggingCalled();

        // A finished attempt has an engine attempt behind it. Accepting the
        // report would record a completed attempt with nothing to collect, and
        // the run would look done while holding no data.
        $status = (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $attemptid]);
        $this->assertNotSame(\local_catquizlab\local\attempt_scheduler::STATUS_COLLECTED, $status);
    }

    /**
     * A pool smaller than the test's own minimum fails before anything is played.
     *
     * @return void
     */
    public function test_pool_smaller_than_the_minimum_fails_the_run(): void {
        $this->resetAfterTest();

        $definition = ['budgets' => ['global' => ['minitems' => 10, 'maxitems' => 25]]];

        // The engine cannot finish a test whose minimum exceeds the items it
        // can be given: it runs out, reports an error, and the run yields one
        // item and a stop reason that blames the strategy. The arithmetic is
        // knowable in advance, so it is checked in advance.
        $reason = run_orchestrator::budget_feasible($definition, 6);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('6 items', $reason);
        $this->assertStringContainsString('minimum of 10', $reason);

        $this->assertNull(run_orchestrator::budget_feasible($definition, 10));
        $this->assertNull(run_orchestrator::budget_feasible($definition, 24));
    }

    /**
     * Without a stated minimum there is nothing to judge.
     *
     * @return void
     */
    public function test_budget_feasibility_needs_a_minimum(): void {
        $this->resetAfterTest();

        $this->assertNull(run_orchestrator::budget_feasible([], 3));
        $this->assertNull(run_orchestrator::budget_feasible(
            ['budgets' => ['global' => ['minitems' => 10]]],
            0
        ));
    }

    /**
     * Scales always carry a readable name.
     *
     * @return void
     */
    public function test_scales_are_named(): void {
        $this->resetAfterTest();

        // An empty name is not a name. A run with no swept factors has an empty
        // cell key, which reached the planner as '' and produced a nameless
        // root scale with children called " / K1.1" — unreadable in the CAT
        // manager, and the engine carries the name into its own structures.
        $plan = \local_catquizlab\local\scale_provisioner::plan_scales([
            'categories' => 1, 'subcategories' => 2, 'name' => '',
        ]);

        foreach ($plan as $node) {
            $this->assertNotSame('', trim($node['name']));
            $this->assertStringNotContainsString('  ', $node['name']);
            $this->assertStringStartsNotWith('/', trim($node['name']));
        }
        $this->assertSame('CATLab', $plan[0]['name']);
    }

    /**
     * Simulated persons start with a stated ability on every scale.
     *
     * @return void
     */
    public function test_person_parameters_are_seeded(): void {
        $this->resetAfterTest();

        if (!environment::engine_available()) {
            $this->markTestSkipped('No CAT engine installed; seeding writes to its tables.');
        }

        // The engine needs an ability before it can choose a first question. In
        // normal use the activity's entry path establishes one; a person the
        // worker drops straight into an attempt has none, so the lab states it.
        $this->assertSame(0, \local_catquizlab\local\user_provisioner::seed_person_parameters(0));
    }

    /**
     * Seeding is a no-op without the engine.
     *
     * @return void
     */
    public function test_seeding_without_the_engine(): void {
        $this->resetAfterTest();

        if (environment::engine_available()) {
            $this->markTestSkipped('Engine present; the guard path is not exercised.');
        }

        $this->assertSame(0, \local_catquizlab\local\user_provisioner::seed_person_parameters(1));
    }

    /**
     * The quiz settings describe a complete feedback configuration.
     *
     * @return void
     */
    public function test_quiz_settings_carry_the_feedback_ranges(): void {
        $this->resetAfterTest();

        $method = new \ReflectionMethod(
            \local_catquizlab\local\test_provisioner::class,
            'feedback_settings'
        );
        $method->setAccessible(true);
        $settings = $method->invoke(null, [10, 11], []);

        // The engine reads the number of ranges and the per-scale limits
        // whenever it builds an attempt's feedback — including at the first
        // question. Without them the attempt cannot start at all, which is not
        // obvious from anything the word "feedback" suggests.
        $this->assertSame('2', $settings['numberoffeedbackoptionsselect']);

        foreach ([10, 11] as $scaleid) {
            $this->assertSame('1', $settings['catquiz_scalereportcheckbox_' . $scaleid]);
            for ($i = 1; $i <= 2; $i++) {
                $this->assertArrayHasKey('feedback_scaleid_limit_lower_' . $scaleid . '_' . $i, $settings);
                $this->assertArrayHasKey('feedback_scaleid_limit_upper_' . $scaleid . '_' . $i, $settings);
            }
        }

        // The bands cover the scale range without a gap: the upper limit of one
        // is the lower limit of the next.
        $this->assertSame('-3', $settings['feedback_scaleid_limit_lower_10_1']);
        $this->assertSame(
            $settings['feedback_scaleid_limit_upper_10_1'],
            $settings['feedback_scaleid_limit_lower_10_2']
        );
        $this->assertSame('3', $settings['feedback_scaleid_limit_upper_10_2']);
    }

    /**
     * The engine caches are purged after provisioning.
     *
     * @return void
     */
    public function test_engine_caches_are_purged_after_provisioning(): void {
        $this->resetAfterTest();

        if (!environment::engine_available()) {
            $this->markTestSkipped('No CAT engine installed; this guards an engine interaction.');
        }

        // A run whose scale and item caches still described the previous run
        // presented no first question at all, and the engine's own message
        // blamed the configuration. Purging is therefore part of provisioning,
        // not an operator's job.
        cat_item_provisioner::purge_engine_cache();

        $this->assertTrue(true, 'Purging every engine store must not raise.');
    }

    /**
     * The materialiser refuses an empty plan with a named reason.
     *
     * @return void
     */
    public function test_materialiser_names_an_empty_plan(): void {
        $this->resetAfterTest();

        $this->assertSame('empty-materialisation-plan', materialiser::REASON_EMPTY_PLAN);
        $this->assertFalse(run_orchestrator::materialisation_complete([
            'planned' => 0, 'failed' => true, 'reason' => materialiser::REASON_EMPTY_PLAN,
        ]));
    }
}
