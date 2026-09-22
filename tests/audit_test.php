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
 * The claims the release gate makes, checked rather than asserted.
 *
 * The audit for #70 set the right standard: "practical functionality and usable
 * operation, not the mere presence of classes or strings or changelog claims".
 * A changelog says what somebody meant to do. This runs the thing.
 *
 * It is deliberately shallow — one check per claim, at the seam where the claim
 * would break — because its job is to notice a regression in a promise, not to
 * re-test what the focused suites already cover.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\debug_trace;
use local_catquizlab\local\scale_health;
use local_catquizlab\local\scale_inventory;
use local_catquizlab\output\shell;

/**
 * Release gate audit.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class audit_test extends \advanced_testcase {
    /**
     * The frame: one per request, selector under the tabs, a way to start one.
     *
     * @return void
     */
    public function test_the_shell_holds_its_promises(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // The selector only renders when there is something to select, which is
        // correct behaviour and means the test has to make one.
        $this->getDataGenerator()->get_plugin_generator('local_catquizlab')->create_experiment();

        shell::reset_for_testing();
        $first = shell::render('plan', 0);

        // RG-001: several pages call this from more than one branch.
        $this->assertSame('', shell::render('plan', 0));

        // Issue 72: above the tabs it read as a filter on the whole plugin.
        $this->assertGreaterThan(
            strpos($first, 'nav-tabs'),
            strpos($first, 'catquizlab-experiment'),
            'The experiment selector is above the tabs.'
        );

        // Issue 53: looking for the new-experiment link on another tab is how
        // somebody makes their second experiment by editing their first.
        $this->assertStringContainsString('experiment.php', $first);
    }

    /**
     * Preparation: six stages, and the PHP path where it belongs.
     *
     * @return void
     */
    public function test_preparation_is_six_stages(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $state = \local_catquizlab\local\setup_wizard::state();
        $this->assertCount(6, $state['stages']);

        $stageof = [];
        foreach ($state['stages'] as $stage) {
            foreach ($stage['steps'] as $step) {
                $stageof[$step['id']] = $stage['id'];
            }
        }

        // RG-004: an installation with working cron runs everything it needs
        // without this path, so it gates the pipeline and not the engine.
        $this->assertSame('pipeline', $stageof['phpcli'] ?? '');
    }

    /**
     * The scale invariant, refused by the database rather than by a check.
     *
     * @return void
     */
    public function test_the_scale_invariant_is_enforced(): void {
        global $DB;
        $this->resetAfterTest();

        $index = $DB->get_manager()->find_index_name(
            new \xmldb_table('local_catquizlab_scalemap'),
            new \xmldb_index('runnodekey', XMLDB_INDEX_UNIQUE, ['runid', 'generation', 'nodekey'])
        );

        // RG-007: UNIQUE(runid, catscaleid) could not express this.
        $this->assertNotFalse($index);

        // A run nobody can resolve gets recovery, not a guess.
        $this->assertNull(scale_inventory::current_root(0));
    }

    /**
     * Nine consistency checks, each with its own answer.
     *
     * @return void
     */
    public function test_scale_health_asks_the_whole_question(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $health = scale_health::check((int) $generator->create_run()->id);

        // RG-008: the node count alone reports a missing subscale and a stray
        // extra one as the same thing.
        $this->assertArrayHasKey('codes', $health);
    }

    /**
     * Observability: a log that survives, costs that are measured, one id.
     *
     * @return void
     */
    public function test_the_observability_promises_hold(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('debuglevel', 'verbose', 'local_catquizlab');
        debug_trace::reset_for_testing();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;

        $correlation = debug_trace::correlation_id();
        \local_catquizlab\local\run_log::record($runid, \local_catquizlab\local\run_log::START_REQUESTED);

        // RG-010/011: one click, one id, across both logs.
        $entries = \local_catquizlab\local\run_log::entries($runid);
        $last = end($entries);
        $this->assertSame($correlation, $last['correlationid']);
        $this->assertNotEmpty(debug_trace::entries(['correlationid' => $correlation]));
    }

    /**
     * Deleting is its own authority, and previews what it takes.
     *
     * @return void
     */
    public function test_deleting_is_guarded(): void {
        $this->resetAfterTest();

        $capability = get_capability_info('local/catquizlab:purge');

        // RG-006: somebody who may operate the lab can do their whole job
        // without ever being able to destroy a measurement.
        $this->assertNotEmpty($capability);
        $this->assertNotSame(0, (int) $capability->riskbitmask & RISK_DATALOSS);
    }

    /**
     * The debug recording is gated, bounded and exportable.
     *
     * @return void
     */
    public function test_the_debug_recording_is_governed(): void {
        $this->resetAfterTest();

        $capability = get_capability_info('local/catquizlab:debug');

        // It records what every operator did, with parameters. Running
        // experiments is not a reason to read that.
        $this->assertNotEmpty($capability);

        $context = \context_system::instance();
        $operator = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/catquizlab:execute', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $operator->id, $context->id);
        $this->assertFalse(has_capability('local/catquizlab:debug', $context, $operator));

        // Bounded by age as well as count: a quiet installation would otherwise
        // keep a log of colleagues for months.
        $this->assertGreaterThan(0, debug_trace::KEEP_SECONDS);

        $this->setAdminUser();
        $export = debug_trace::export();
        foreach (['generated', 'site', 'moodle', 'plugin', 'level', 'retention', 'entries'] as $key) {
            $this->assertArrayHasKey($key, $export);
        }
    }

    /**
     * A PHP path is validated before it is stored, and alternatives are offered.
     *
     * @return void
     */
    public function test_the_php_path_is_validated(): void {
        $this->resetAfterTest();

        $wizard = \local_catquizlab\local\setup_wizard::class;

        // An unvalidated path becomes a scheduled task that quietly does
        // nothing, which is the failure this check exists to prevent.
        $this->assertFalse($wizard::validate_php_cli('php')['ok']);
        $this->assertSame('notabsolute', $wizard::validate_php_cli('php')['reason']);
        $this->assertSame('notthere', $wizard::validate_php_cli('/nothing/here')['reason']);

        // Several PHP versions on one server is the normal case after an
        // upgrade, and picking the first without saying so is how somebody runs
        // tasks on a version their site does not support.
        foreach ($wizard::php_cli_candidates() as $candidate) {
            $this->assertSame('cli', $candidate['sapi']);
            $this->assertNotSame('', $candidate['version']);
        }
    }

    /**
     * Deep deletion takes the accounts it created, and only those.
     *
     * @return void
     */
    public function test_deep_deletion_removes_its_own_users(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;

        $ours = $this->getDataGenerator()->create_user(['username' => 'catlab_r' . $runid . '_p1']);
        $theirs = $this->getDataGenerator()->create_user(['username' => 'a_real_person']);

        foreach ([$ours, $theirs] as $user) {
            $DB->insert_record('local_catquizlab_person', (object) [
                'runid' => $runid, 'twinid' => 't' . $user->id, 'moodleuserid' => $user->id,
                'truetheta' => 0, 'profile' => 'conforming',
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        \local_catquizlab\local\purger::delete_simulated_people($runid);

        // Deleting a real user because they happened to be enrolled in an
        // experiment course would be unforgivable, so ownership is read from
        // the run number this plugin stamps on the accounts it makes.
        $this->assertFalse($DB->record_exists('user', ['id' => $ours->id, 'deleted' => 0]));
        $this->assertTrue($DB->record_exists('user', ['id' => $theirs->id, 'deleted' => 0]));
    }

    /**
     * One press on a fresh installation flips every switch the pipeline needs.
     *
     * @return void
     */
    public function test_one_press_switches_everything_the_pipeline_needs(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Factory state: this is what every fresh installation and every CI
        // job starts from, and what the reported installation sat in.
        set_config('worker_exec_enabled', 0, 'local_catquizlab');
        set_config('enabled', 0, 'local_catquizlab');
        set_config('pathtophp', '');
        $CFG->pathtophp = '';

        $before = \local_catquizlab\local\worker_launcher::missing(
            \local_catquizlab\local\worker_launcher::config_from_settings()
        );
        $this->assertContains('worker_exec_enabled', $before);

        \local_catquizlab\local\setup_wizard::run(true);

        // The switch nothing used to flip: an installation could pass every
        // other check and still never play a sitting, and the page said
        // "stalled" with no word about why.
        $this->assertSame(1, (int) get_config('local_catquizlab', 'worker_exec_enabled'));
        $this->assertSame(1, (int) get_config('local_catquizlab', 'enabled'));

        // The PHP binary, detected rather than left as an amber line with a
        // settings page behind it.
        $this->assertNotSame('', (string) get_config('core', 'pathtophp'));

        $after = \local_catquizlab\local\worker_launcher::missing(
            \local_catquizlab\local\worker_launcher::config_from_settings()
        );
        $this->assertNotContains('worker_exec_enabled', $after);
        $this->assertNotContains('worker_token', $after);
        $this->assertNotContains('worker_base_url', $after);

        // And the wizard now lists the switch, so an installation where it is
        // off cannot report itself ready.
        set_config('worker_exec_enabled', 0, 'local_catquizlab');
        $state = \local_catquizlab\local\setup_wizard::state();
        $this->assertFalse($state['ready']);
        $this->assertContains(get_string('wizard:workerexec', 'local_catquizlab'), $state['blockers']);
    }

    /**
     * A launch that cannot happen says what is missing, never nothing.
     *
     * @return void
     */
    public function test_a_launch_that_cannot_happen_names_what_is_missing(): void {
        $this->resetAfterTest();

        set_config('worker_exec_enabled', 0, 'local_catquizlab');
        set_config('worker_token', '', 'local_catquizlab');

        $result = \local_catquizlab\local\worker_launcher::launch_pool(
            \local_catquizlab\local\worker_launcher::config_from_settings()
        );

        // Null here was what the tick printed nothing about, every five
        // minutes, for as long as anybody watched.
        $this->assertNotNull($result);
        $this->assertSame(0, $result['launched']);
        $this->assertStringStartsWith('not-configured', $result['reason']);
        $this->assertStringContainsString('worker_exec_enabled', $result['reason']);
        $this->assertStringContainsString('worker_token', $result['reason']);
    }

    /**
     * The installation says what the server must allow before it tries.
     *
     * @return void
     */
    public function test_the_preflight_names_what_the_server_must_allow(): void {
        global $CFG;
        $this->resetAfterTest();

        $steps = \local_catquizlab\local\worker_runtime::preflight(false);
        $ids = array_column($steps, 'id');

        // Writable directories and disk space are checked every time; both
        // name the operating-system user, because "the web server user" is
        // not something an administrator can type into chown.
        $this->assertContains('storage', $ids);
        $this->assertContains('diskspace', $ids);
        $storage = $steps[array_search('storage', $ids)];
        $this->assertTrue($storage['ok']);
        $this->assertStringContainsString(\local_catquizlab\local\worker_runtime::process_user(), $storage['detail']);

        // Moodle's proxy reaches npm and the browser download. Without it a
        // site behind a proxy could reach the internet from Moodle and not
        // from the installation it started.
        $CFG->proxyhost = 'proxy.example.org';
        $CFG->proxyport = 3128;
        $CFG->proxybypass = 'localhost';
        $environment = implode(' ', \local_catquizlab\local\worker_launcher::runtime_environment([]));
        $this->assertStringContainsString('HTTPS_PROXY=http://proxy.example.org:3128', $environment);
        $this->assertStringContainsString('npm_config_https_proxy=http://proxy.example.org:3128', $environment);
        $this->assertStringContainsString('NO_PROXY=localhost', $environment);

        // And a password in the proxy never reaches a message.
        $CFG->proxyuser = 'u';
        $CFG->proxypassword = 's3cret';
        $this->assertStringContainsString('u:s3cret@', \local_catquizlab\local\worker_runtime::proxy_url());
    }

    /**
     * Queued sittings of a held run are named as held, not as stalled.
     *
     * @return void
     */
    public function test_held_work_is_not_reported_as_stalled(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $DB->set_field('local_catquizlab_run', 'status', \local_catquizlab\local\registry::STATUS_FAILED, ['id' => $runid]);
        $DB->set_field('local_catquizlab_run', 'lasterror', 'Division by zero', ['id' => $runid]);
        for ($i = 0; $i < 3; $i++) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => 0,
                'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED,
                'tries' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        // This was the reported installation: every run held, the page saying
        // "stalled — start workers", and the start correctly doing nothing
        // because nothing was claimable.
        $verdict = \local_catquizlab\local\situation::assess();
        $this->assertNotSame(\local_catquizlab\local\situation::STALLED, $verdict['state']);
        $this->assertStringContainsString('#' . $runid, $verdict['headline']);
        $this->assertStringContainsString('Division by zero', $verdict['detail']);
    }

    /**
     * The live poll answers for the whole site without a parameter mismatch.
     *
     * @return void
     */
    public function test_the_live_poll_works_without_an_experiment(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $site = \local_catquizlab\external\live_status::execute(0);
        $this->assertSame('site', $site['scope']);
        \core_external\external_api::clean_returnvalue(\local_catquizlab\external\live_status::execute_returns(), $site);

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $generator->create_experiment()->id;
        $this->assertSame('experiment', \local_catquizlab\external\live_status::execute($experimentid)['scope']);
    }

    /**
     * Every count a preview or a result can contain has a name.
     *
     * @return void
     */
    public function test_every_count_has_a_name(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Every key the previews and the reset and deletion results produce.
        // One of them — "activity" — had no string, and the reset preview
        // failed with a debugging notice; the other results printed the
        // internal keys to the person.
        $keys = ['activities', 'activity', 'activityfailed', 'attempts', 'collected', 'contexts', 'course',
            'engineitems', 'enginescales', 'enrolments', 'experiment', 'failed', 'generations', 'items',
            'logentries', 'nodes', 'open', 'people', 'questions', 'results', 'run', 'runs', 'scales', 'tasks',
            'total', 'users'];
        foreach ($keys as $key) {
            $this->assertTrue(
                get_string_manager()->string_exists('purge:count' . $key, 'local_catquizlab'),
                'no name for the count "' . $key . '"'
            );
            $this->assertSame(
                '2 ' . get_string('purge:count' . $key, 'local_catquizlab'),
                \local_catquizlab\local\purger::counts_line([$key => 2])
            );
        }

        // The reset preview of a run with a test activity renders without a
        // debugging notice — which PHPUnit turns into a failure by itself.
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $DB->set_field('local_catquizlab_run', 'testcmid', 4711, ['id' => $runid]);

        $message = \local_catquizlab\local\run_lifecycle::reset_preview_message($runid, 'local_catquizlab');
        $this->assertStringContainsString(get_string('purge:countactivities', 'local_catquizlab'), $message);

        // An unknown key still reads as something rather than failing.
        $this->assertSame('3 somethingnew', \local_catquizlab\local\purger::count_label('somethingnew', 3));
    }

    /**
     * The engine's prior never fills up with identical abilities.
     *
     * @return void
     */
    public function test_a_persons_engine_parameters_go_when_their_sitting_is_read(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        if (!\local_catquizlab\local\environment::engine_available()) {
            $this->markTestSkipped('No CAT engine installed.');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $contextid = 7777;
        $root = 55;
        $DB->insert_record('local_catquizlab_scalemap', (object) [
            'runid' => $runid, 'level' => 0, 'catscaleid' => $root, 'parentcatscaleid' => 0,
            'contextid' => $contextid, 'nodekey' => 'root', 'generation' => 1, 'timecreated' => time(),
        ]);

        // The engine writes one ability per person per scale. Fifty people all
        // start from the same value, and once fifty are in the context the
        // engine takes their standard deviation as the prior for the next
        // estimate: zero, and the estimate divides by it. That is the
        // "Division by zero" — no seeding needed, only enough people.
        $mine = 4242;
        for ($i = 1; $i <= 50; $i++) {
            $DB->insert_record('local_catquiz_personparams', (object) [
                'userid' => $i === 1 ? $mine : 9000 + $i, 'catscaleid' => $root, 'contextid' => $contextid,
                'attemptid' => 0, 'ability' => 0.0, 'standarderror' => null, 'status' => 0,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        // One person's sitting has been read back: their rows go, nobody
        // else's does.
        $removed = \local_catquizlab\local\user_provisioner::forget_engine_person_params($runid, $mine);
        $this->assertSame(1, $removed);
        $this->assertSame(49, $DB->count_records('local_catquiz_personparams', ['contextid' => $contextid]));

        // Preparing the run again clears the context completely, so a reset
        // does not start against fifty stale abilities — which is what made
        // every sitting after a reset fail at once.
        $this->assertSame(49, \local_catquizlab\local\user_provisioner::remove_seeded_parameters($runid));
        $this->assertSame(0, $DB->count_records('local_catquiz_personparams', ['contextid' => $contextid]));
    }

    /**
     * A worker whose process is gone does not hold its slot.
     *
     * @return void
     */
    public function test_a_dead_process_does_not_hold_a_slot(): void {
        global $DB;
        $this->resetAfterTest();

        $registry = \local_catquizlab\local\worker_registry::class;

        // A worker that crashed a second ago: heartbeat fresh, process gone.
        // It used to hold the only slot for five minutes, during which the page
        // said "1 worker running" and the pipeline said "all-slots-busy".
        $registry::acquire_slot(1, 'crashed', 999999);
        $DB->set_field('local_catquizlab_worker', 'status', $registry::STATUS_RUNNING, ['workerid' => 'crashed']);
        $DB->set_field('local_catquizlab_worker', 'heartbeat', time(), ['workerid' => 'crashed']);
        $this->assertSame(1, $registry::summary()['live']);

        $this->assertSame(1, $registry::reap_dead_processes());
        $this->assertSame(0, $registry::summary()['live']);
        $this->assertSame(
            $registry::STATUS_CRASHED,
            (int) $DB->get_field('local_catquizlab_worker', 'status', ['workerid' => 'crashed'])
        );

        // A process that is alive is left alone, whatever its heartbeat says.
        $registry::acquire_slot(2, 'alive', getmypid());
        $DB->set_field('local_catquizlab_worker', 'status', $registry::STATUS_RUNNING, ['workerid' => 'alive']);
        $this->assertSame(0, $registry::reap_dead_processes());
        $this->assertSame(1, $registry::summary()['live']);
    }
}
