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
 * Worker slots, leases and the recovery of orphaned claims.
 *
 * The reported failure these tests exist for: a site configured for one job at
 * a time ended up with two attempts claimed simultaneously, and nothing in the
 * installation could say that a worker had gone. Concurrency that only holds
 * while nobody interrupts anything is not a limit.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;
use local_catquizlab\local\worker_registry;

/**
 * Worker registry tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\worker_registry
 * @covers     \local_catquizlab\local\attempt_scheduler
 */
final class worker_registry_test extends \advanced_testcase {
    /**
     * Add an attempt in a given state.
     *
     * @param int $status One of attempt_scheduler's status constants.
     * @param string|null $owner The lease owner, when it is claimed.
     * @param int $leaseexpires When the lease lapses.
     * @return int The attempt id.
     */
    /**
     * Give a run a pool and budgets its readiness check can pass.
     *
     * A run that exists only as a row cannot pass readiness — correctly, since
     * such a run would queue jobs that fail before the first question.
     *
     * @param int $runid The run.
     * @return void
     */
    protected function make_run_ready(int $runid): void {
        global $DB;

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        $manifest = json_decode((string) $run->manifestjson, true) ?: [];
        $manifest['config']['definition']['budgets'] = [
            'global'   => ['minitems' => 2, 'maxitems' => 20],
            'subscale' => ['minitems' => 1, 'maxitems' => 10],
        ];
        $manifest['config']['definition']['strategy'] = 'fastest';
        $DB->set_field('local_catquizlab_run', 'manifestjson', json_encode($manifest), ['id' => $runid]);

        $scaleid = 900000 + $runid;
        $DB->insert_record('local_catquizlab_scalemap', (object) [
            'runid' => $runid, 'level' => \local_catquizlab\local\scale_provisioner::LEVEL_SUBSCALE,
            'catscaleid' => $scaleid, 'categoryindex' => 1, 'subscaleindex' => 1, 'timecreated' => time(),
        ]);

        for ($i = 0; $i < 12; $i++) {
            $paramid = $DB->insert_record('local_catquiz_itemparams', (object) [
                'componentid' => 0, 'componentname' => 'question', 'contextid' => 1,
                'model' => 'raschbirnbaum', 'difficulty' => 0, 'discrimination' => 1, 'guessing' => 0,
                'status' => \local_catquizlab\local\cat_readiness::STATUS_KNOWN,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
            $DB->insert_record('local_catquiz_items', (object) [
                'componentid' => 0, 'componentname' => 'question', 'catscaleid' => $scaleid,
                'contextid' => 1, 'activeparamid' => $paramid, 'status' => 0,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }
    }

    /**
     * Add an attempt to a run that already exists.
     *
     * @param int $runid The run.
     * @param int $status One of attempt_scheduler's status constants.
     * @param string|null $error The recorded failure reason, when there is one.
     * @return int The attempt id.
     */
    protected function add_attempt_to(int $runid, int $status, ?string $error = null): int {
        global $DB;

        return (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid'        => $runid,
            'personid'     => 0,
            'status'       => $status,
            'tries'        => 0,
            'lasterror'    => $error,
            'nextruntime'  => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Add an attempt on a run created for the purpose.
     *
     * @param int $status One of attempt_scheduler's status constants.
     * @param string|null $owner The lease owner, when it is claimed.
     * @param int $leaseexpires When the lease lapses.
     * @return int The attempt id.
     */
    protected function add_attempt(
        int $status = attempt_scheduler::STATUS_QUEUED,
        ?string $owner = null,
        int $leaseexpires = 0
    ): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        return (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid'        => $run->id,
            'personid'     => 0,
            'status'       => $status,
            'tries'        => 0,
            'leaseowner'   => $owner,
            'leaseexpires' => $leaseexpires,
            'nextruntime'  => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * An attempt's current status.
     *
     * @param int $attemptid The attempt.
     * @return int
     */
    protected function attempt_status(int $attemptid): int {
        global $DB;

        return (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $attemptid]);
    }

    /**
     * A slot holds one worker, and the second is turned away.
     *
     * @return void
     */
    public function test_a_slot_holds_one_worker(): void {
        $this->resetAfterTest();

        $first = worker_registry::acquire_slot(1, 'catquizlab-exec-1');
        $second = worker_registry::acquire_slot(1, 'catquizlab-exec-1-duplicate');

        // This is the whole point: worker_concurrency = 1 has to mean one job
        // at a time installation-wide, not one job per process that happens to
        // be started.
        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertTrue(worker_registry::slot_is_busy(1));
        $this->assertFalse(worker_registry::slot_is_busy(2));
    }

    /**
     * A restarting worker reuses its own slot.
     *
     * @return void
     */
    public function test_the_same_worker_may_restart_into_its_slot(): void {
        $this->resetAfterTest();

        $first = worker_registry::acquire_slot(1, 'catquizlab-exec-1');
        $again = worker_registry::acquire_slot(1, 'catquizlab-exec-1');

        // Otherwise a restarted worker would need a new name every time, and
        // the registry would fill with rows nobody can interpret.
        $this->assertSame($first, $again);
    }

    /**
     * Free slots are counted, not assumed.
     *
     * @return void
     */
    public function test_free_slots_reflect_what_is_held(): void {
        $this->resetAfterTest();

        $this->assertSame([1, 2, 3], worker_registry::free_slots(3));

        worker_registry::acquire_slot(1, 'w1');
        worker_registry::acquire_slot(3, 'w3');

        $this->assertSame([2], worker_registry::free_slots(3));
    }

    /**
     * A worker that stops reporting is treated as gone.
     *
     * @return void
     */
    public function test_a_silent_worker_is_reaped(): void {
        global $DB;
        $this->resetAfterTest();

        $id = worker_registry::acquire_slot(1, 'ghost');
        $this->assertTrue(worker_registry::slot_is_busy(1));

        // Push its heartbeat past the timeout: the process is gone, which from
        // outside is indistinguishable from silence.
        $DB->set_field(
            'local_catquizlab_worker',
            'heartbeat',
            time() - worker_registry::HEARTBEAT_TIMEOUT - 60,
            ['id' => $id]
        );

        $reaped = worker_registry::reap();

        $this->assertSame(1, $reaped['workers']);
        $this->assertFalse(worker_registry::slot_is_busy(1));
        $this->assertSame(
            worker_registry::STATUS_CRASHED,
            (int) $DB->get_field('local_catquizlab_worker', 'status', ['id' => $id])
        );
    }

    /**
     * A busy worker that keeps reporting is left alone.
     *
     * @return void
     */
    public function test_a_reporting_worker_is_not_reaped(): void {
        $this->resetAfterTest();

        worker_registry::acquire_slot(1, 'busy');
        worker_registry::heartbeat('busy', 1);

        $reaped = worker_registry::reap();

        // Declaring a live worker dead is the expensive mistake: its attempt
        // would be handed to a second worker and played twice.
        $this->assertSame(0, $reaped['workers']);
        $this->assertTrue(worker_registry::slot_is_busy(1));
    }

    /**
     * What a dead worker was holding goes back into the queue.
     *
     * @return void
     */
    public function test_reaping_releases_the_attempts_a_worker_held(): void {
        global $DB;
        $this->resetAfterTest();

        $id = worker_registry::acquire_slot(1, 'ghost');
        $attemptid = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'ghost', time() + 300);

        $DB->set_field(
            'local_catquizlab_worker',
            'heartbeat',
            time() - worker_registry::HEARTBEAT_TIMEOUT - 60,
            ['id' => $id]
        );

        $reaped = worker_registry::reap();

        // A claim whose holder is gone is not in progress. Leaving it as
        // running is how a queue stops draining with nobody able to say why.
        $this->assertSame(1, $reaped['attempts']);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, $this->attempt_status($attemptid));
        $this->assertNull($DB->get_field('local_catquizlab_attempt', 'leaseowner', ['id' => $attemptid]));
    }

    /**
     * Releasing by owner touches only that owner's claims.
     *
     * @return void
     */
    public function test_release_lease_is_scoped_to_its_owner(): void {
        $this->resetAfterTest();

        $mine = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'worker-a', time() + 300);
        $theirs = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'worker-b', time() + 300);

        $released = attempt_scheduler::release_lease('worker-a');

        $this->assertSame(1, $released);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, $this->attempt_status($mine));
        $this->assertSame(attempt_scheduler::STATUS_RUNNING, $this->attempt_status($theirs));
    }

    /**
     * An expired lease is recovered; a live one is not.
     *
     * @return void
     */
    public function test_recovery_follows_the_lease_not_the_clock(): void {
        $this->resetAfterTest();

        $expired = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'worker-a', time() - 60);
        $live = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'worker-b', time() + 600);

        // The previous rule keyed on timemodified, which a worker refreshes
        // while it works — so a genuinely stuck attempt and a slow one looked
        // the same. Both of these were modified just now.
        $reclaimed = attempt_scheduler::reclaim_stale(null, 3600);

        $this->assertSame(1, $reclaimed);
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, $this->attempt_status($expired));
        $this->assertSame(attempt_scheduler::STATUS_RUNNING, $this->attempt_status($live));
    }

    /**
     * Attempts without a lease still fall back to the timeout.
     *
     * @return void
     */
    public function test_leaseless_attempts_keep_the_timeout_fallback(): void {
        global $DB;
        $this->resetAfterTest();

        // An attempt claimed before leases existed. Dropping the fallback would
        // strand it for good.
        $attemptid = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, null, 0);
        $DB->set_field('local_catquizlab_attempt', 'timemodified', time() - 7200, ['id' => $attemptid]);

        $this->assertSame(1, attempt_scheduler::reclaim_stale(null, 3600));
        $this->assertSame(attempt_scheduler::STATUS_QUEUED, $this->attempt_status($attemptid));
    }

    /**
     * The worker's reason for a failure is kept.
     *
     * @return void
     */
    public function test_the_failure_reason_is_recorded(): void {
        global $DB;
        $this->resetAfterTest();

        $attemptid = $this->add_attempt(attempt_scheduler::STATUS_RUNNING, 'worker-a', time() + 300);
        attempt_scheduler::record_error($attemptid, "Login as catlab_r1 failed: no username field\nstack…");

        // Without this a retried attempt gives no clue why, and the person
        // looking has only a rising try count.
        $stored = (string) $DB->get_field('local_catquizlab_attempt', 'lasterror', ['id' => $attemptid]);
        $this->assertStringContainsString('Login as catlab_r1 failed', $stored);
        $this->assertLessThanOrEqual(1000, \core_text::strlen($stored));

        // An empty reason is not a reason and must not overwrite a real one.
        attempt_scheduler::record_error($attemptid, '   ');
        $this->assertSame(
            $stored,
            (string) $DB->get_field('local_catquizlab_attempt', 'lasterror', ['id' => $attemptid])
        );
    }

    /**
     * The fleet summary distinguishes live, stopped and crashed.
     *
     * @return void
     */
    public function test_summary_separates_live_from_gone(): void {
        global $DB;
        $this->resetAfterTest();

        worker_registry::acquire_slot(1, 'alive');
        worker_registry::heartbeat('alive', 3);

        worker_registry::acquire_slot(2, 'finished');
        worker_registry::release('finished');

        $id = worker_registry::acquire_slot(3, 'gone');
        $DB->set_field(
            'local_catquizlab_worker',
            'heartbeat',
            time() - worker_registry::HEARTBEAT_TIMEOUT - 60,
            ['id' => $id]
        );
        worker_registry::reap();

        $summary = worker_registry::summary();

        $this->assertSame(1, $summary['live']);
        $this->assertSame(1, $summary['stopped']);
        $this->assertSame(1, $summary['crashed']);
        $this->assertSame(3, $summary['jobsdone']);
    }

    /**
     * Releasing a slot frees it for the next worker.
     *
     * @return void
     */
    public function test_release_frees_the_slot(): void {
        $this->resetAfterTest();

        worker_registry::acquire_slot(1, 'first');
        worker_registry::release('first');

        $this->assertFalse(worker_registry::slot_is_busy(1));
        $this->assertNotNull(worker_registry::acquire_slot(1, 'second'));
    }

    /**
     * A crash is recorded with its reason rather than as a clean stop.
     *
     * @return void
     */
    public function test_a_crash_is_distinguishable_from_a_clean_stop(): void {
        global $DB;
        $this->resetAfterTest();

        worker_registry::acquire_slot(1, 'crashing');
        worker_registry::release('crashing', 'node exited with code 1');

        $row = $DB->get_record('local_catquizlab_worker', ['workerid' => 'crashing']);

        // One records a decision, the other a defect — and a list where both
        // look alike hides the defects among the decisions.
        $this->assertSame(worker_registry::STATUS_CRASHED, (int) $row->status);
        $this->assertStringContainsString('exited with code 1', (string) $row->lasterror);
    }

    /**
     * A paused run hands out nothing.
     *
     * @return void
     */
    public function test_a_paused_run_hands_out_no_work(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $attemptid = $this->add_attempt(attempt_scheduler::STATUS_QUEUED);
        $runid = (int) $DB->get_field('local_catquizlab_attempt', 'runid', ['id' => $attemptid]);
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_READY, ['id' => $runid]);

        $this->assertTrue(\local_catquizlab\external\job_claim::execute('w')['hasjob']);

        \local_catquizlab\local\run_lifecycle::set_paused($runid, true);
        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_QUEUED, ['id' => $attemptid]);
        $DB->set_field('local_catquizlab_attempt', 'nextruntime', 0, ['id' => $attemptid]);

        // A pause that still hands work out is not a pause.
        $this->assertFalse(\local_catquizlab\external\job_claim::execute('w')['hasjob']);

        \local_catquizlab\local\run_lifecycle::set_paused($runid, false);
        $this->assertTrue(\local_catquizlab\external\job_claim::execute('w')['hasjob']);
    }

    /**
     * A run failing over and over pauses itself.
     *
     * @return void
     */
    public function test_a_failing_run_pauses_itself(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $runid = (int) $run->id;

        // One short of the limit: a run that is merely unlucky keeps going.
        for ($i = 1; $i < \local_catquizlab\local\run_lifecycle::FAILURE_STREAK_LIMIT; $i++) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => 0, 'status' => attempt_scheduler::STATUS_FAILED,
                'tries' => 3, 'timecreated' => time(), 'timemodified' => time() + $i,
            ]);
        }
        $this->assertFalse(\local_catquizlab\local\run_lifecycle::check_failure_streak($runid));
        $this->assertFalse(\local_catquizlab\local\run_lifecycle::is_paused($runid));

        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $runid, 'personid' => 0, 'status' => attempt_scheduler::STATUS_FAILED,
            'tries' => 3, 'timecreated' => time(), 'timemodified' => time() + 100,
        ]);

        // Retrying 1600 attempts that all fail the same way exhausts the queue
        // and leaves nothing to diagnose.
        $this->assertTrue(\local_catquizlab\local\run_lifecycle::check_failure_streak($runid));
        $this->assertTrue(\local_catquizlab\local\run_lifecycle::is_paused($runid));
        $this->assertStringContainsString(
            (string) \local_catquizlab\local\run_lifecycle::FAILURE_STREAK_LIMIT,
            \local_catquizlab\local\run_lifecycle::pause_reason($runid)
        );
    }

    /**
     * A recent success breaks the streak.
     *
     * @return void
     */
    public function test_a_success_breaks_the_failure_streak(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;

        for ($i = 1; $i <= \local_catquizlab\local\run_lifecycle::FAILURE_STREAK_LIMIT; $i++) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => 0, 'status' => attempt_scheduler::STATUS_FAILED,
                'tries' => 3, 'timecreated' => time(), 'timemodified' => time() + $i,
            ]);
        }
        // The most recent one succeeded: the run is producing results, so the
        // streak is over and the history does not count against it.
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $runid, 'personid' => 0, 'status' => attempt_scheduler::STATUS_COLLECTED,
            'tries' => 1, 'timecreated' => time(), 'timemodified' => time() + 999,
        ]);

        $this->assertFalse(\local_catquizlab\local\run_lifecycle::check_failure_streak($runid));
        $this->assertFalse(\local_catquizlab\local\run_lifecycle::is_paused($runid));
    }

    /**
     * The health view answers what used to need a shell.
     *
     * @return void
     */
    public function test_health_reports_every_operational_question(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $health = \local_catquizlab\local\system_health::health();
        $ids = array_column($health['checks'], 'id');

        // Is the worker ready? Is one running? Is the pipeline blocked or just
        // slow? Each of these had to be answered over SSH.
        foreach (['engine', 'activity', 'course', 'workertoken', 'node', 'workermodules', 'workerfleet', 'pipeline'] as $id) {
            $this->assertContains($id, $ids, 'The health view says nothing about: ' . $id);
        }

        foreach ($health['checks'] as $check) {
            $this->assertNotSame('', $check['detail'], $check['id'] . ' reports a status without a finding.');
        }
    }

    /**
     * Waiting work with no worker is named as a blocker, not left to inference.
     *
     * @return void
     */
    public function test_a_stalled_pipeline_is_named(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->add_attempt(attempt_scheduler::STATUS_QUEUED);

        $checks = array_column(\local_catquizlab\local\system_health::health()['checks'], null, 'id');

        // Attempts waiting with nobody to play them is the one combination that
        // never resolves itself — the state the reported installation sat in.
        $this->assertSame(\local_catquizlab\local\system_health::FAIL, $checks['pipeline']['status']);
    }

    /**
     * The worker token can be created from the plugin.
     *
     * @return void
     */
    public function test_the_worker_token_can_be_created_in_the_plugin(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertNull(\local_catquizlab\local\system_health::worker_token());

        // This was the one step that forced an operator out of the workflow.
        $this->assertTrue(\local_catquizlab\local\worker_setup::ensure_token());
        $this->assertNotNull(\local_catquizlab\local\system_health::worker_token());

        // Creating it twice must not mint a second one.
        $this->assertFalse(\local_catquizlab\local\worker_setup::ensure_token());
    }

    /**
     * The worker runtime is pinned rather than inherited.
     *
     * @return void
     */
    public function test_the_browser_runtime_is_explicit(): void {
        global $CFG;
        $this->resetAfterTest();

        $env = \local_catquizlab\local\worker_launcher::runtime_environment([]);
        $keys = array_map(static fn(string $pair): string => explode('=', $pair, 2)[0], $env);

        // Puppeteer resolves its cache from the runtime of whoever runs it, so
        // a worker started by hand and the same worker started from cron look
        // in different places. Pinning these makes the two contexts one.
        foreach (['HOME', 'PUPPETEER_CACHE_DIR', 'XDG_CACHE_HOME', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME'] as $name) {
            $this->assertContains($name, $keys, $name . ' is left to the environment.');
        }

        $home = null;
        foreach ($env as $pair) {
            [$name, $value] = explode('=', $pair, 2);
            if ($name === 'HOME') {
                $home = $value;
            }
        }

        // Under dataroot on purpose: it belongs to the web server user by
        // construction, which is the user cron will run the worker as.
        $this->assertNotNull($home);
        $this->assertStringStartsWith($CFG->dataroot, $home);
        $this->assertDirectoryExists($home, 'The runtime directory is assumed rather than created.');
    }

    /**
     * A configured worker command carries that runtime with it.
     *
     * @return void
     */
    public function test_the_launch_command_carries_the_runtime(): void {
        $this->resetAfterTest();

        $method = new \ReflectionMethod(\local_catquizlab\local\worker_launcher::class, 'command_with_environment');
        $method->setAccessible(true);

        $command = $method->invoke(null, [], ['/usr/bin/node', '/tmp/run.js', '--self-test']);

        // Without the prefix the self-test and the worker run in different
        // environments, which is how a green self-test coexisted with a worker
        // that could not find Chrome.
        $this->assertStringStartsWith('env ', $command);
        $this->assertStringContainsString('PUPPETEER_CACHE_DIR', $command);
        $this->assertStringContainsString('run.js', $command);
    }

    /**
     * Engine attempts that never got a first question are cleared up.
     *
     * @return void
     */
    public function test_empty_engine_attempts_are_removed(): void {
        global $DB;
        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('No mod_adaptivequiz installed.');
        }

        $user = $this->getDataGenerator()->create_user();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $DB->insert_record('local_catquizlab_person', (object) [
            'runid' => $runid, 'twinid' => 'r001-t00001', 'twinindex' => 0,
            'moodleuserid' => $user->id, 'truetheta' => 0, 'timecreated' => time(),
        ]);

        // The row mod_adaptivequiz leaves behind when the selection fails
        // before item one: neither running nor finished.
        $emptyid = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 1, 'userid' => $user->id, 'uniqueid' => 0,
            'attemptstate' => 'inprogress', 'attemptstopcriteria' => '',
            'questionsattempted' => 0, 'standarderror' => 999, 'measure' => 0,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        // One with a question usage has answers attached and is somebody's
        // data, whatever state it is in.
        $realid = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 1, 'userid' => $user->id, 'uniqueid' => 4242,
            'attemptstate' => 'inprogress', 'attemptstopcriteria' => '',
            'questionsattempted' => 3, 'standarderror' => 0.5, 'measure' => 0.2,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->assertSame(1, \local_catquizlab\local\engine_hygiene::count_empty_attempts());
        $this->assertSame(1, \local_catquizlab\local\engine_hygiene::purge_empty_attempts());

        $this->assertFalse($DB->record_exists('adaptivequiz_attempt', ['id' => $emptyid]));
        $this->assertTrue($DB->record_exists('adaptivequiz_attempt', ['id' => $realid]));
    }

    /**
     * Attempts of people who are not this lab's are left alone.
     *
     * @return void
     */
    public function test_only_lab_attempts_are_touched(): void {
        global $DB;
        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('No mod_adaptivequiz installed.');
        }

        $stranger = $this->getDataGenerator()->create_user();
        $id = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 1, 'userid' => $stranger->id, 'uniqueid' => 0,
            'attemptstate' => 'inprogress', 'attemptstopcriteria' => '',
            'questionsattempted' => 0, 'standarderror' => 999, 'measure' => 0,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        // Same shape, not our person: a plugin that deletes rows it did not
        // create is worse than the defect it is cleaning up after.
        $this->assertSame(0, \local_catquizlab\local\engine_hygiene::purge_empty_attempts());
        $this->assertTrue($DB->record_exists('adaptivequiz_attempt', ['id' => $id]));
    }

    /**
     * The web service token never reaches the command line.
     *
     * @return void
     */
    public function test_the_token_stays_out_of_the_command_line(): void {
        $this->resetAfterTest();

        $secret = 'SECRET-TOKEN-0123456789';
        set_config('worker_token', $secret, 'local_catquizlab');
        set_config('worker_node_path', '/usr/bin/node', 'local_catquizlab');

        $config = \local_catquizlab\local\worker_launcher::config_from_settings();

        // Arguments are visible in process listings, monitoring output and
        // crash reports, and this token opens every web service function the
        // worker is allowed to call.
        $argv = \local_catquizlab\local\worker_launcher::build_command($config);
        $this->assertStringNotContainsString($secret, implode(' ', $argv));

        $method = new \ReflectionMethod(\local_catquizlab\local\worker_launcher::class, 'command_with_environment');
        $method->setAccessible(true);
        $command = $method->invoke(null, $config, $argv);

        // Not in the env prefix either: `env NAME=value cmd` only moves the
        // secret from the worker's argv into env's own, which is just as
        // visible. It is exported into this process, and the child inherits it.
        $this->assertStringNotContainsString($secret, $command);
        $this->assertSame($secret, getenv('CATQUIZLAB_WORKER_TOKEN'));
    }

    /**
     * Runtime directories are not world-writable.
     *
     * @return void
     */
    public function test_runtime_directories_are_not_world_writable(): void {
        $this->resetAfterTest();

        $env = \local_catquizlab\local\worker_launcher::runtime_environment([]);
        $home = null;
        foreach ($env as $pair) {
            [$name, $value] = explode('=', $pair, 2);
            if ($name === 'HOME') {
                $home = $value;
            }
        }

        $this->assertDirectoryExists($home);

        // These hold a browser profile and its cache. 0777 asks for more than
        // is needed, and whether the umask happens to trim it is not a
        // security argument.
        $mode = fileperms($home) & 0777;
        $this->assertSame(0, $mode & 0002, sprintf('The runtime directory is world-writable (%o).', $mode));
    }

    /**
     * An unusable runtime directory is reported where it happens.
     *
     * @return void
     */
    public function test_an_unwritable_runtime_directory_is_reported(): void {
        $this->resetAfterTest();

        // A directory that exists and cannot be written: the case that used to
        // surface later as an EACCES from inside Puppeteer, where the reader
        // then debugs the browser instead of the file system.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // Root ignores the permission bits, so the case cannot be staged
            // here. Skipping says so instead of passing on a false premise.
            $this->markTestSkipped('Running as root; directory permissions are not enforced.');
        }

        $blocked = make_temp_directory('catquizlab_blocked_' . random_int(1000, 9999));
        chmod($blocked, 0500);
        set_config('worker_home', $blocked . '/home', 'local_catquizlab');

        try {
            \local_catquizlab\local\worker_launcher::runtime_environment([]);
            $this->fail('An unusable runtime directory passed silently.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString($blocked, $e->getMessage() . ($e->debuginfo ?? ''));
        } finally {
            chmod($blocked, 0700);
        }
    }

    /**
     * The whole worker access is created in one operation.
     *
     * @return void
     */
    public function test_worker_access_is_set_up_completely(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $access = \local_catquizlab\local\worker_access::class;

        $before = $access::verify();
        $this->assertFalse($before['ok']);

        $result = $access::ensure();

        // Ten steps across four areas of the administration, any one of which
        // missing looks the same from outside: a worker that claims nothing.
        $this->assertTrue($result['ok'], 'Still missing: ' . implode(', ', $access::verify()['missing']));
        foreach ($result['steps'] as $step) {
            $this->assertTrue($step['ok'], $step['label'] . ' was not set up.');
        }

        $user = $DB->get_record('user', ['username' => $access::USERNAME]);
        $this->assertNotFalse($user);
        // The type 'nologin' looks equivalent and is not: web service calls under
        // it are refused with wsaccessusernologin, which reads as a permission
        // problem and is an account-type problem.
        $this->assertSame('webservice', $user->auth);
    }

    /**
     * Setting it up twice changes nothing the second time.
     *
     * @return void
     */
    public function test_worker_access_setup_is_idempotent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $access = \local_catquizlab\local\worker_access::class;

        $access::ensure();
        $second = $access::ensure();

        // Running it after a partial manual setup should complete that setup,
        // not duplicate it — so every step checks before it acts.
        $this->assertTrue($second['ok']);
        $this->assertSame([], $second['changed']);
    }

    /**
     * The token belongs to the technical account, not to whoever pressed the button.
     *
     * @return void
     */
    public function test_the_token_belongs_to_the_worker_account(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $access = \local_catquizlab\local\worker_access::class;
        $access::ensure();

        $service = $DB->get_record('external_services', ['shortname' => $access::SERVICE]);
        $stored = (string) get_config('local_catquizlab', 'worker_token');
        $token = $DB->get_record('external_tokens', ['token' => $stored]);

        $worker = $DB->get_record('user', ['username' => $access::USERNAME]);
        $this->assertSame((int) $worker->id, (int) $token->userid);
        $this->assertSame((int) $service->id, (int) $token->externalserviceid);

        // If it leaks it is worth exactly the three functions the worker calls.
        // An administrator's token is worth the administrator.
        $this->assertNotSame((int) $USER->id, (int) $token->userid);
    }

    /**
     * A partial setup is completed rather than duplicated.
     *
     * @return void
     */
    public function test_a_partial_setup_is_completed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $access = \local_catquizlab\local\worker_access::class;
        $access::ensure();

        // The step most often forgotten by hand: the token exists and was never
        // pasted back, so everything looks right and nothing works.
        set_config('worker_token', '', 'local_catquizlab');
        $this->assertFalse($access::verify()['ok']);

        $repaired = $access::ensure();

        $this->assertTrue($repaired['ok']);
        $this->assertContains('storedtoken', $repaired['changed']);
        // No second account and no second role for one missing setting.
        $this->assertSame(1, $DB->count_records('user', ['username' => $access::USERNAME, 'deleted' => 0]));
        $this->assertSame(1, $DB->count_records('role', ['shortname' => $access::ROLE]));
    }

    /**
     * The worker role carries both capabilities and only in the system context.
     *
     * @return void
     */
    public function test_the_worker_role_is_narrow(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $access = \local_catquizlab\local\worker_access::class;
        $access::ensure();

        $role = $DB->get_record('role', ['shortname' => $access::ROLE]);
        $context = \context_system::instance();

        // Both: the endpoints check local/catquizlab:worker, and Moodle refuses
        // the REST call itself without webservice/rest:use. Missing either
        // produces the same silence.
        foreach (['local/catquizlab:worker', 'webservice/rest:use'] as $capability) {
            $this->assertTrue($DB->record_exists('role_capabilities', [
                'roleid' => $role->id, 'capability' => $capability,
                'permission' => CAP_ALLOW, 'contextid' => $context->id,
            ]), $capability . ' is not granted.');
        }

        // System context only: a role assignable in courses would invite the
        // worker's capability being handed to people.
        $levels = array_map('intval', array_values(get_role_contextlevels($role->id)));
        $this->assertSame([CONTEXT_SYSTEM], $levels);
    }

    /**
     * The wizard reports the four stages in dependency order.
     *
     * @return void
     */
    public function test_the_wizard_reports_four_stages_in_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $state = \local_catquizlab\local\setup_wizard::state();
        $ids = array_column($state['stages'], 'id');

        // The order is the dependency order: a worker cannot be set up against
        // an engine that is not there, and a pipeline over a broken setup
        // produces failing jobs rather than results.
        $this->assertSame(['engine', 'environment', 'worker', 'pipeline'], $ids);
    }

    /**
     * Without the engine the wizard stops rather than pressing on.
     *
     * @return void
     */
    public function test_the_wizard_stops_when_the_engine_is_missing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        if (\local_catquizlab\local\environment::catquiz_available()) {
            $this->markTestSkipped('The engine is installed; this path cannot be staged.');
        }

        $result = \local_catquizlab\local\setup_wizard::run(true);

        // Setting up a worker against a missing engine produces a second
        // failure that hides the first.
        $this->assertFalse($result['ready']);
        $this->assertSame([], $result['changed']);
        $this->assertNotEmpty($result['log']);
    }

    /**
     * The pipeline is not switched on over an incomplete setup.
     *
     * @return void
     */
    public function test_the_pipeline_is_not_started_over_a_broken_setup(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $DB;

        // Without the external service the worker cannot authenticate, and that
        // is not something the wizard can repair: it is declared in
        // db/services.php, so its absence means a broken installation.
        $DB->delete_records('external_services', ['shortname' => \local_catquizlab\local\worker_access::SERVICE]);
        set_config('enabled', 0, 'local_catquizlab');

        \local_catquizlab\local\setup_wizard::run(true);

        // A pipeline switched on over a broken setup does not produce results,
        // it produces failing jobs.
        $this->assertFalse(\local_catquizlab\local\setup_wizard::pipeline_enabled());
    }

    /**
     * The experiment course is created, and only once.
     *
     * @return void
     */
    public function test_the_experiment_course_is_created_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('experimentcourseid', 0, 'local_catquizlab');

        $first = \local_catquizlab\local\experiment_container::ensure_course();
        $second = \local_catquizlab\local\experiment_container::ensure_course();

        $this->assertGreaterThan(0, $first);
        // An installation that already has the course must not end up with two:
        // the second would silently hold half the experiments.
        $this->assertSame($first, $second);
        $this->assertSame(1, $DB->count_records('course', ['shortname' => 'catquizlab']));

        $course = $DB->get_record('course', ['id' => $first]);
        // Sections are addressed by number, one per experiment, so a format
        // without them would break provisioning.
        $this->assertSame('topics', $course->format);
        // Visible on purpose: a hidden course tells its enrolled students that
        // it is unavailable, and the simulated persons are enrolled students.
        // Hiding it looked tidy and made every attempt unplayable.
        $this->assertSame(1, (int) $course->visible);
    }

    /**
     * An existing course is adopted rather than duplicated.
     *
     * @return void
     */
    public function test_an_existing_experiment_course_is_adopted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $existing = $this->getDataGenerator()->create_course(['shortname' => 'catquizlab']);
        set_config('experimentcourseid', 0, 'local_catquizlab');

        $resolved = \local_catquizlab\local\experiment_container::ensure_course();

        $this->assertSame((int) $existing->id, $resolved);
        $this->assertSame(1, $DB->count_records('course', ['shortname' => 'catquizlab']));
    }

    /**
     * A misconfigured Node path repairs itself rather than blocking setup.
     *
     * @return void
     */
    public function test_a_wrong_node_path_is_repaired(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        if (\local_catquizlab\local\worker_runtime::find_node() === null) {
            $this->markTestSkipped('No Node on this machine.');
        }

        // Pointing at nothing is the state a fresh installation is in, and it
        // is one the plugin can fix by looking rather than by asking.
        set_config('worker_node_path', '/nonexistent/node', 'local_catquizlab');
        $this->assertFalse(\local_catquizlab\local\worker_runtime::verify()['ok']);

        $result = \local_catquizlab\local\worker_runtime::ensure();

        $this->assertContains('node', $result['changed']);
        $this->assertNotSame('/nonexistent/node', get_config('local_catquizlab', 'worker_node_path'));
    }

    /**
     * A usable Node binary is found without being configured by hand.
     *
     * @return void
     */
    public function test_node_is_discovered(): void {
        $this->resetAfterTest();

        $found = \local_catquizlab\local\worker_runtime::find_node();
        if ($found === null) {
            $this->markTestSkipped('No Node on this machine; discovery cannot be exercised.');
        }

        $this->assertTrue(is_executable($found));
    }

    /**
     * A half-downloaded browser does not count as installed.
     *
     * @return void
     */
    public function test_an_empty_browser_directory_is_not_a_browser(): void {
        $this->resetAfterTest();

        $cache = null;
        foreach (\local_catquizlab\local\worker_launcher::runtime_environment([]) as $pair) {
            [$name, $value] = explode('=', $pair, 2);
            if ($name === 'PUPPETEER_CACHE_DIR') {
                $cache = $value;
            }
        }

        $stale = $cache . '/chrome/linux-0.0.0.0';
        make_writable_directory($stale, false);

        // An interrupted download leaves the directory behind, and the worker
        // then fails as if nothing were installed — so the check looks for an
        // executable, not for a path.
        $this->assertFalse(is_executable($stale . '/chrome-linux64/chrome'));
    }

    /**
     * A worker's output is kept, not thrown away.
     *
     * @return void
     */
    public function test_worker_output_is_kept(): void {
        $this->resetAfterTest();

        $launcher = \local_catquizlab\local\worker_launcher::class;
        $path = $launcher::log_path('catquizlab-exec-1');

        // A worker that dies on startup writes its reason to stderr. Sent to
        // /dev/null, the registry then showed a slot held by a process that no
        // longer existed, with nothing to say why.
        $this->assertNotSame('/dev/null', $path);
        file_put_contents($path, "first\nsecond\nthird\n");

        $this->assertSame("second\nthird", $launcher::log_tail('catquizlab-exec-1', 2));

        // One file per worker: a restarted worker appends to its own history
        // rather than into a shared file nobody can untangle.
        $this->assertStringContainsString('catquizlab-exec-1', $path);
        $this->assertSame('', $launcher::log_tail('a-worker-that-never-ran'));
    }

    /**
     * A failed run can be re-checked and resumed once its cause is fixed.
     *
     * @return void
     */
    public function test_a_failed_run_can_be_rechecked(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        if (!\local_catquizlab\local\environment::catquiz_available()) {
            $this->markTestSkipped('No CAT engine installed; readiness stands down.');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $this->make_run_ready($runid);
        $DB->set_field(
            'local_catquizlab_run',
            'status',
            \local_catquizlab\local\registry::STATUS_READY,
            ['id' => $runid]
        );
        $this->add_attempt_to($runid, attempt_scheduler::STATUS_QUEUED);

        \local_catquizlab\local\run_lifecycle::fail($runid, 'cat-not-ready: pool too small');
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', [
            'runid' => $runid, 'status' => attempt_scheduler::STATUS_QUEUED,
        ]));

        $result = \local_catquizlab\local\run_lifecycle::recheck($runid);

        // A run that failed because a pool was too small is not broken for
        // ever: the pool can be enlarged. Reproducing it instead would lose the
        // identity of the run that failed.
        $this->assertTrue($result['ok'], $result['reason']);
        $this->assertSame(1, $result['requeued']);
        $this->assertSame(
            \local_catquizlab\local\registry::STATUS_READY,
            (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid])
        );
        $this->assertSame('', \local_catquizlab\local\run_lifecycle::failure_details($runid)['reason']);
    }

    /**
     * Attempts that failed while being played keep their history.
     *
     * @return void
     */
    public function test_recheck_only_reopens_what_the_run_closed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        if (!\local_catquizlab\local\environment::catquiz_available()) {
            $this->markTestSkipped('No CAT engine installed; readiness stands down.');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $this->make_run_ready($runid);

        // One that genuinely failed while a worker played it, one closed when
        // the run failed. Reopening the first would discard a real result.
        $played = $this->add_attempt_to(
            $runid,
            attempt_scheduler::STATUS_FAILED,
            'Login failed: no username field'
        );
        $this->add_attempt_to($runid, attempt_scheduler::STATUS_QUEUED);

        $DB->set_field(
            'local_catquizlab_run',
            'status',
            \local_catquizlab\local\registry::STATUS_READY,
            ['id' => $runid]
        );
        \local_catquizlab\local\run_lifecycle::fail($runid, 'cat-not-ready');
        $result = \local_catquizlab\local\run_lifecycle::recheck($runid);

        $this->assertSame(1, $result['requeued']);
        $this->assertSame(
            attempt_scheduler::STATUS_FAILED,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $played])
        );
        $this->assertStringContainsString(
            'Login failed',
            (string) $DB->get_field('local_catquizlab_attempt', 'lasterror', ['id' => $played])
        );
    }

    /**
     * The recorded failure reason can be read back.
     *
     * @return void
     */
    public function test_the_failure_reason_is_readable(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;

        \local_catquizlab\local\run_lifecycle::fail($runid, 'stage:materialise (pool-too-small)');
        $details = \local_catquizlab\local\run_lifecycle::failure_details($runid);

        // It was recorded from the start and never read: a run said FAILED and
        // the reason sat in its manifest, where only database access found it.
        $this->assertStringContainsString('materialise', $details['reason']);
        $this->assertGreaterThan(0, $details['time']);
    }

    /**
     * An empty log file is normal, not an error.
     *
     * @return void
     */
    public function test_an_empty_worker_log_does_not_break_the_page(): void {
        $this->resetAfterTest();

        $launcher = \local_catquizlab\local\worker_launcher::class;
        $path = $launcher::log_path('catquizlab-exec-9');
        file_put_contents($path, '');

        // A worker that has just started has a log file and nothing in it — the
        // normal state for the first seconds of every run. Reading zero bytes
        // threw, and the exception took the operations page with it: the one
        // page somebody opens when a worker is not behaving.
        $this->assertSame('', $launcher::log_tail('catquizlab-exec-9'));
    }

    /**
     * A log written by another process is read at its current size.
     *
     * @return void
     */
    public function test_worker_log_reflects_later_writes(): void {
        $this->resetAfterTest();

        $launcher = \local_catquizlab\local\worker_launcher::class;
        $path = $launcher::log_path('catquizlab-exec-8');

        file_put_contents($path, '');
        $this->assertSame('', $launcher::log_tail('catquizlab-exec-8'));

        // The worker writes from its own process while this one reads, so PHP's
        // cached stat data can be older than the file.
        file_put_contents($path, "first\nsecond\n");
        $this->assertSame("first\nsecond", $launcher::log_tail('catquizlab-exec-8'));

        file_put_contents($path, "third\n", FILE_APPEND);
        $this->assertSame("second\nthird", $launcher::log_tail('catquizlab-exec-8', 2));
    }

    /**
     * A worker reports what it is playing, not just that it lives.
     *
     * @return void
     */
    public function test_a_worker_reports_its_current_job(): void {
        global $DB;
        $this->resetAfterTest();

        worker_registry::acquire_slot(1, 'reporter');

        $this->assertTrue(worker_registry::report('reporter', 77, 'working'));

        // "Busy for four minutes on attempt 77" and "gone four minutes ago"
        // were the same row before this.
        $row = $DB->get_record('local_catquizlab_worker', ['workerid' => 'reporter']);
        $this->assertSame(77, (int) $row->currentattempt);
        $this->assertSame('working', $row->workerstate);
        $this->assertSame(worker_registry::STATUS_RUNNING, (int) $row->status);
    }

    /**
     * A reporting worker is never reaped, however long its attempt takes.
     *
     * @return void
     */
    public function test_reporting_keeps_a_worker_alive(): void {
        global $DB;
        $this->resetAfterTest();

        $id = worker_registry::acquire_slot(1, 'slow');
        $DB->set_field('local_catquizlab_worker', 'heartbeat',
            time() - worker_registry::HEARTBEAT_TIMEOUT - 60, ['id' => $id]);

        // An attempt takes minutes, and the claim-to-completion gap used to
        // cover the whole timeout.
        worker_registry::report('slow', 5, 'working');

        $this->assertSame(0, worker_registry::reap()['workers']);
        $this->assertTrue(worker_registry::slot_is_busy(1));
    }

    /**
     * Stopping a worker is a request, not a kill.
     *
     * @return void
     */
    public function test_stopping_a_worker_is_asked_for(): void {
        $this->resetAfterTest();

        worker_registry::acquire_slot(1, 'stoppable');
        $this->assertFalse(worker_registry::stop_requested('stoppable'));

        $this->assertTrue(worker_registry::request_stop('stoppable'));
        $this->assertTrue(worker_registry::stop_requested('stoppable'));

        // The worker is mid-attempt in a browser. Ending the process there
        // leaves a claim with nobody to finish it, which is the state the lease
        // mechanism exists to prevent — so it reads this and finishes first.
        $this->assertFalse(worker_registry::request_stop('never-registered'));
    }

    /**
     * A worker the registry has forgotten is told to stop.
     *
     * @return void
     */
    public function test_an_unknown_worker_is_told_to_stop(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $reply = \local_catquizlab\external\worker_heartbeat::execute('ghost-worker', 0, 'working');

        // Its slot was reaped and may already have been given away. Two workers
        // believing they hold one slot is worse than one stopping early.
        $this->assertFalse($reply['known']);
        $this->assertTrue($reply['stop']);
    }
}
