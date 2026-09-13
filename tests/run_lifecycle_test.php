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
 * The run lifecycle, from a created experiment to an evaluated result.
 *
 * The guiding invariant these tests exist for: an experiment may only appear as
 * executed when its runs have been through the real execution path. An expanded
 * sweep is not an execution, a scheduled run is not an attempt, and an attempt
 * without a trace is not a result.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\preflight;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;

/**
 * Lifecycle tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\run_lifecycle
 * @covers     \local_catquizlab\local\preflight
 */
final class run_lifecycle_test extends \advanced_testcase {
    /**
     * Create an experiment with a two-cell sweep.
     *
     * @return int The experiment id.
     */
    protected function experiment_with_runs(): int {
        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Lifecycle';
        $definition['sweep'] = ['factors' => ['strategy' => ['classic', 'fastest']]];

        $experimentid = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($experimentid);

        return $experimentid;
    }

    /**
     * Give the site everything preflight insists on.
     *
     * @return int The course id.
     */
    protected function satisfy_preflight(): int {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        set_config('experimentcourseid', $course->id, 'local_catquizlab');

        // A worker that could play the attempts. Without one preflight warns
        // but does not block, so this only keeps the warning out of the way.
        set_config('workernodepath', '/usr/bin/node', 'local_catquizlab');

        return (int) $course->id;
    }

    /**
     * The run ids of an experiment.
     *
     * @param int $experimentid The experiment.
     * @return int[]
     */
    protected function run_ids(int $experimentid): array {
        global $DB;

        return array_map('intval', array_keys(
            $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC', 'id')
        ));
    }

    /**
     * Add an attempt in a given state.
     *
     * @param int $runid The run.
     * @param int $status One of attempt_scheduler's status constants.
     * @return int The attempt id.
     */
    protected function add_attempt(int $runid, int $status = attempt_scheduler::STATUS_QUEUED): int {
        global $DB;

        return (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid'        => $runid,
            'personid'     => 0,
            'status'       => $status,
            'tries'        => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A run's status.
     *
     * @param int $runid The run.
     * @return int
     */
    protected function run_status(int $runid): int {
        global $DB;

        return (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]);
    }

    /**
     * Creating a sweep does not make an experiment executed.
     *
     * @return void
     */
    public function test_create_sweep_does_not_claim_execution(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $experimentid = $this->experiment_with_runs();
        $runs = $this->run_ids($experimentid);

        $this->assertNotEmpty($runs);
        foreach ($runs as $runid) {
            $this->assertSame(registry::STATUS_DRAFT, $this->run_status($runid));
        }

        // The observed defect: an experiment reading "Executed" while every one
        // of its runs was a draft at 0%. Creating runs is not running them.
        $status = (int) $DB->get_field('local_catquizlab_experiment', 'status', ['id' => $experimentid]);
        $this->assertSame(experiment_service::STATUS_EXPANDED, $status);
        $this->assertNotSame(experiment_service::STATUS_EXECUTED, $status);
    }

    /**
     * The experiment status follows its runs through the whole path.
     *
     * @return void
     */
    public function test_experiment_status_is_derived_from_its_runs(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $experimentid = $this->experiment_with_runs();
        $runs = $this->run_ids($experimentid);

        $this->assertSame(experiment_service::STATUS_EXPANDED, run_lifecycle::experiment_status($experimentid));

        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_RUNNING, ['id' => $runs[0]]);
        $this->assertSame(experiment_service::STATUS_RUNNING, run_lifecycle::experiment_status($experimentid));

        foreach ($runs as $runid) {
            $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $runid]);
        }
        $this->assertSame(experiment_service::STATUS_EXECUTED, run_lifecycle::experiment_status($experimentid));

        // Every run terminal but none finished is not an execution either.
        foreach ($runs as $runid) {
            $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FAILED, ['id' => $runid]);
        }
        $this->assertSame(experiment_service::STATUS_FAILED, run_lifecycle::experiment_status($experimentid));
    }

    /**
     * Starting a draft run schedules it and queues the orchestrator.
     *
     * @return void
     */
    public function test_start_schedules_a_draft_run(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $runid = $this->run_ids($this->experiment_with_runs())[0];

        $before = count(\core\task\manager::get_adhoc_tasks(\local_catquizlab\task\orchestrate_run::class));
        $result = run_lifecycle::start($runid);
        $after = count(\core\task\manager::get_adhoc_tasks(\local_catquizlab\task\orchestrate_run::class));

        $this->assertTrue($result['started']);
        $this->assertSame(registry::STATUS_SCHEDULED, $this->run_status($runid));
        $this->assertSame($before + 1, $after, 'Starting a run must go through the shared orchestrator task.');
    }

    /**
     * A run that is not a draft is not started again.
     *
     * @return void
     */
    public function test_only_draft_runs_can_be_started(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $runid = $this->run_ids($this->experiment_with_runs())[0];
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_RUNNING, ['id' => $runid]);

        // Restarting a running run would give it a second attempt queue.
        $result = run_lifecycle::start($runid);

        $this->assertFalse($result['started']);
        $this->assertSame('run-not-in-draft', $result['reason']);
        $this->assertSame(registry::STATUS_RUNNING, $this->run_status($runid));
    }

    /**
     * Without an experiment course a run is not started at all.
     *
     * @return void
     */
    public function test_start_is_blocked_without_an_experiment_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('experimentcourseid', 0, 'local_catquizlab');
        $runid = $this->run_ids($this->experiment_with_runs())[0];

        // Queueing it anyway would leave a run that looks started and never
        // moves, which is worse than a refusal with a reason.
        $result = run_lifecycle::start($runid);

        $this->assertFalse($result['started']);
        $this->assertNotSame('', $result['reason']);
        $this->assertSame(registry::STATUS_DRAFT, $this->run_status($runid));
    }

    /**
     * A configured but deleted course counts as not configured.
     *
     * @return void
     */
    public function test_deleted_experiment_course_blocks_the_start(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $courseid = $this->satisfy_preflight();
        delete_course($courseid, false);

        $check = preflight::check();

        // The setting still holds an id, which is exactly why this has to be
        // checked: it looks configured and fails deep inside a stage.
        $this->assertFalse($check['ok']);
        $this->assertSame(0, $check['course']);
    }

    /**
     * Starting every draft run of an experiment.
     *
     * @return void
     */
    public function test_start_drafts_starts_all_of_them(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $experimentid = $this->experiment_with_runs();
        $result = run_lifecycle::start_drafts($experimentid);

        $this->assertGreaterThan(0, $result['started']);
        $this->assertSame(0, $result['blocked']);
        foreach ($this->run_ids($experimentid) as $runid) {
            $this->assertSame(registry::STATUS_SCHEDULED, $this->run_status($runid));
        }
    }

    /**
     * Provisioning moves a scheduled run to ready, or fails it with a reason.
     *
     * @return void
     */
    public function test_provisioning_outcome_reaches_the_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $runs = $this->run_ids($this->experiment_with_runs());

        run_lifecycle::start($runs[0]);
        run_lifecycle::provisioned($runs[0], true);
        $this->assertSame(registry::STATUS_READY, $this->run_status($runs[0]));

        run_lifecycle::start($runs[1]);
        run_lifecycle::provisioned($runs[1], false, 'stage:materialise (pool-too-small)');
        $this->assertSame(registry::STATUS_FAILED, $this->run_status($runs[1]));

        // The reason travels with the run: a status without one forces the next
        // reader to reconstruct it from logs that may no longer exist.
        $manifest = json_decode(
            (string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runs[1]]),
            true
        );
        $this->assertStringContainsString('materialise', $manifest['lifecycle']['failedreason']);
    }

    /**
     * The first claimed attempt makes the run running.
     *
     * @return void
     */
    public function test_first_claim_moves_the_run_to_running(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $runid = $this->run_ids($this->experiment_with_runs())[0];
        run_lifecycle::start($runid);
        run_lifecycle::provisioned($runid, true);

        $this->assertTrue(run_lifecycle::attempt_claimed($runid));
        $this->assertSame(registry::STATUS_RUNNING, $this->run_status($runid));

        // Later claims of the same run change nothing.
        $this->assertFalse(run_lifecycle::attempt_claimed($runid));
        $this->assertSame(registry::STATUS_RUNNING, $this->run_status($runid));
    }

    /**
     * While attempts are open the run does not move on.
     *
     * @return void
     */
    public function test_run_waits_for_its_open_attempts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $runid = $this->run_ids($this->experiment_with_runs())[0];
        run_lifecycle::start($runid);
        run_lifecycle::provisioned($runid, true);
        run_lifecycle::attempt_claimed($runid);

        $this->add_attempt($runid, attempt_scheduler::STATUS_COLLECTED);
        $this->add_attempt($runid, attempt_scheduler::STATUS_QUEUED);

        $this->assertNull(run_lifecycle::attempt_finished($runid));
        $this->assertSame(registry::STATUS_RUNNING, $this->run_status($runid));
    }

    /**
     * The last collected attempt hands the run to aggregation, once.
     *
     * @return void
     */
    public function test_last_attempt_queues_aggregation_exactly_once(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $runid = $this->run_ids($this->experiment_with_runs())[0];
        run_lifecycle::start($runid);
        run_lifecycle::provisioned($runid, true);
        run_lifecycle::attempt_claimed($runid);
        $this->add_attempt($runid, attempt_scheduler::STATUS_COLLECTED);

        $before = count(\core\task\manager::get_adhoc_tasks(\local_catquizlab\task\aggregate_results::class));
        $this->assertSame('aggregating', run_lifecycle::attempt_finished($runid));
        $this->assertSame(registry::STATUS_AGGREGATING, $this->run_status($runid));

        // Completion callbacks arrive more than once — a retried worker, a
        // re-collected attempt — and the aggregation must not be queued twice.
        $this->assertNull(run_lifecycle::attempt_finished($runid));
        $this->assertNull(run_lifecycle::attempt_finished($runid));

        $after = count(\core\task\manager::get_adhoc_tasks(\local_catquizlab\task\aggregate_results::class));
        $this->assertSame($before + 1, $after);
    }

    /**
     * A run whose attempts all failed does not go to aggregation.
     *
     * @return void
     */
    public function test_run_without_a_single_trace_fails(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $runid = $this->run_ids($this->experiment_with_runs())[0];
        run_lifecycle::start($runid);
        run_lifecycle::provisioned($runid, true);
        run_lifecycle::attempt_claimed($runid);
        $this->add_attempt($runid, attempt_scheduler::STATUS_FAILED);

        // Aggregating nothing would produce an empty result set and a run
        // marked finished, which is the failure mode this whole issue is about.
        $this->assertSame('failed', run_lifecycle::attempt_finished($runid));
        $this->assertSame(registry::STATUS_FAILED, $this->run_status($runid));
    }

    /**
     * Successful aggregation finishes the run; a failed one fails it.
     *
     * @return void
     */
    public function test_aggregation_finalises_the_run(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $runs = $this->run_ids($this->experiment_with_runs());

        run_lifecycle::aggregated($runs[0], true);
        $this->assertSame(registry::STATUS_FINISHED, $this->run_status($runs[0]));

        run_lifecycle::aggregated($runs[1], false, 'aggregation-produced-no-results');
        $this->assertSame(registry::STATUS_FAILED, $this->run_status($runs[1]));
    }

    /**
     * There is no state "all attempts done, run stuck on running".
     *
     * @return void
     */
    public function test_a_completed_run_never_stays_running(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $runid = $this->run_ids($this->experiment_with_runs())[0];
        run_lifecycle::start($runid);
        run_lifecycle::provisioned($runid, true);
        run_lifecycle::attempt_claimed($runid);
        $this->add_attempt($runid, attempt_scheduler::STATUS_COLLECTED);
        run_lifecycle::attempt_finished($runid);
        run_lifecycle::aggregated($runid, true);

        $this->assertFalse(run_lifecycle::has_open_attempts($runid));
        $this->assertSame(registry::STATUS_FINISHED, $this->run_status($runid));
        $this->assertTrue(registry::is_terminal($this->run_status($runid)));
    }

    /**
     * The whole path, in order, with the experiment following along.
     *
     * @return void
     */
    public function test_the_full_path_from_draft_to_finished(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        $experimentid = $this->experiment_with_runs();
        $runs = $this->run_ids($experimentid);

        $seen = [];
        $seen[] = $this->run_status($runs[0]);

        run_lifecycle::start($runs[0]);
        $seen[] = $this->run_status($runs[0]);

        run_lifecycle::provisioned($runs[0], true);
        $seen[] = $this->run_status($runs[0]);

        run_lifecycle::attempt_claimed($runs[0]);
        $seen[] = $this->run_status($runs[0]);

        $this->add_attempt($runs[0], attempt_scheduler::STATUS_COLLECTED);
        run_lifecycle::attempt_finished($runs[0]);
        $seen[] = $this->run_status($runs[0]);

        run_lifecycle::aggregated($runs[0], true);
        $seen[] = $this->run_status($runs[0]);

        $this->assertSame([
            registry::STATUS_DRAFT,
            registry::STATUS_SCHEDULED,
            registry::STATUS_READY,
            registry::STATUS_RUNNING,
            registry::STATUS_AGGREGATING,
            registry::STATUS_FINISHED,
        ], $seen);

        // One run finished, one still a draft: the experiment is running, not
        // executed. It becomes executed only when no run is left to run.
        $this->assertSame(
            experiment_service::STATUS_RUNNING,
            (int) $DB->get_field('local_catquizlab_experiment', 'status', ['id' => $experimentid])
        );
    }

    /**
     * The settings link points at the section settings.php registers.
     *
     * @return void
     */
    public function test_settings_url_matches_the_registered_section(): void {
        global $CFG;
        $this->resetAfterTest();

        // The landing page linked to 'local_catquizlab' while settings.php
        // registered 'local_catquizlab_settings', so the one link a fresh
        // installation needs — to choose an experiment course — led to Moodle's
        // sectionerror. Two literals that had to agree, in two files.
        $settings = file_get_contents($CFG->dirroot . '/local/catquizlab/settings.php');
        $this->assertStringContainsString('registry::SETTINGS_SECTION', $settings);
        $this->assertStringNotContainsString("'local_catquizlab_settings',", $settings);

        $index = file_get_contents($CFG->dirroot . '/local/catquizlab/index.php');
        $this->assertStringNotContainsString("'section' => 'local_catquizlab'", $index);

        $url = registry::settings_url();
        $this->assertSame(registry::SETTINGS_SECTION, $url->param('section'));
        $this->assertStringContainsString('/admin/settings.php', $url->out(false));
    }

    /**
     * The state reported in the bug report cannot survive a start.
     *
     * @return void
     */
    public function test_the_reported_state_is_not_reachable_after_a_start(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->satisfy_preflight();

        // Reported: experiment "Executed", 16 runs all Draft at 0%, no results.
        // Each half of that is now impossible on its own.
        $experimentid = $this->experiment_with_runs();

        $status = (int) $DB->get_field('local_catquizlab_experiment', 'status', ['id' => $experimentid]);
        $this->assertNotSame(
            experiment_service::STATUS_EXECUTED,
            $status,
            'An experiment whose runs are all drafts must not read as executed.'
        );

        run_lifecycle::start_drafts($experimentid);

        $drafts = $DB->count_records('local_catquizlab_run', [
            'experimentid' => $experimentid,
            'status'       => registry::STATUS_DRAFT,
        ]);
        $this->assertSame(0, $drafts, 'After a start no run may still be a draft.');
    }

    /**
     * Preflight names what is missing rather than failing silently.
     *
     * @return void
     */
    public function test_preflight_reports_every_blocker(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('experimentcourseid', 0, 'local_catquizlab');
        set_config('workernodepath', '', 'local_catquizlab');

        $check = preflight::check();

        $this->assertFalse($check['ok']);
        $this->assertNotEmpty($check['blockers']);
        // A missing worker does not stop a start: the run provisions and its
        // attempts wait for a worker that may be started later.
        $this->assertNotEmpty($check['warnings']);
        $this->assertNotSame('', preflight::summary($check));
    }
}
