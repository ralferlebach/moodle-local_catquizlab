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

namespace local_catquizlab;

use local_catquizlab\local\execution_queue;
use local_catquizlab\local\experiment_runner;
use local_catquizlab\local\experiment_status;
use local_catquizlab\local\registry;
use local_catquizlab\local\setup_wizard;

/**
 * One start, everything after it by itself (#151).
 *
 * "Start experiment", once, on a saved definition: the request accepts the order and puts it in the line, nothing
 * more. The line prepares the experiment at its turn, in the background, one experiment at a time; a lost or failed
 * preparation is asked for again; an installation that cannot run yet holds the order and goes on once it can; a
 * second click, a second browser, a second tick add nothing.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_runner
 * @covers     \local_catquizlab\local\execution_queue
 * @covers     \local_catquizlab\task\prepare_experiment
 */
final class one_start_test extends \advanced_testcase {
    /** @var string The preparation task. */
    protected const TASK = '\\local_catquizlab\\task\\prepare_experiment';

    /**
     * Forget the readiness a test declared.
     *
     * @return void
     */
    protected function tearDown(): void {
        setup_wizard::assume_ready_for_testing(null);
        parent::tearDown();
    }

    /**
     * A saved experiment, valid, nothing built from it.
     *
     * @param array $record Generator overrides.
     * @return int Its id.
     */
    protected function saved(array $record = []): int {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        return (int) $generator->create_experiment($record)->id;
    }

    /**
     * The preparation tasks waiting, by experiment.
     *
     * @return int[] Experiment ids, one per task.
     */
    protected function preparations(): array {
        global $DB;
        return array_map(
            static fn($row): int => (int) json_decode($row->customdata)->experimentid,
            array_values($DB->get_records('task_adhoc', ['classname' => self::TASK]))
        );
    }

    /**
     * The start accepts the order and does nothing else: no runs, no task, no worker — the line has it.
     *
     * @return void
     */
    public function test_the_start_only_accepts_the_order(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = $this->saved();

        $definition = $DB->get_field('local_catquizlab_experiment', 'configjson', ['id' => $id]);
        // Entries long finished do not count: it is the first in the line, whatever came before.
        $DB->insert_record('local_catquizlab_execqueue', (object) ['experimentid' => 999999, 'state' => 'finished',
            'position' => 9, 'userid' => 2, 'timecreated' => time() - 3600]);
        $started = experiment_runner::start($id);
        $this->assertTrue($started['ok'], json_encode($started));
        $this->assertSame(1, $started['position']);
        // The start changes nothing of the definition (#151 AK11).
        $this->assertSame($definition, $DB->get_field('local_catquizlab_experiment', 'configjson', ['id' => $id]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_run', ['experimentid' => $id]), 'nothing built in the request');
        $this->assertSame([], $this->preparations(), 'nothing queued but the order');
        $this->assertSame(execution_queue::WAITING, execution_queue::state_of($id));

        // Said as "queued", not "planned"; and no second start offered.
        $status = experiment_status::snapshot($id, true);
        $this->assertSame(['queued', 'progress'], [$status['phase'], $status['next']['kind']]);
        $this->assertSame('queued', experiment_status::library([$id])[$id]['phase']);
    }

    /**
     * At its turn the line prepares it in the background, as the person who started it, and holds the line meanwhile.
     *
     * @return void
     */
    public function test_the_line_prepares_it_at_its_turn(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);
        $id = $this->saved();
        experiment_runner::start($id);

        $advanced = execution_queue::advance();
        $this->assertSame([$id, 'preparing'], [$advanced['started'], $advanced['reason']]);
        $entry = $DB->get_record('local_catquizlab_execqueue', ['experimentid' => $id]);
        $this->assertSame(execution_queue::RUNNING, $entry->state);
        $this->assertTrue(execution_queue::preparing($entry));
        $this->assertSame([$id], $this->preparations());
        $this->assertSame((int) $USER->id, (int) $DB->get_field('task_adhoc', 'userid', ['classname' => self::TASK]));
        $this->assertSame('preparing', experiment_status::snapshot($id, true)['phase']);
        $this->assertSame('preparing', experiment_status::library([$id])[$id]['phase']);

        $this->expectOutputRegex('/experiment \d+ prepared in the background: \d+ run\(s\) created/');
        $this->runAdhocTasks(self::TASK);
        $runs = $DB->count_records('local_catquizlab_run', ['experimentid' => $id]);
        $this->assertGreaterThan(0, $runs, 'the sweep is created by the task');

        // Without an experiment course the runs cannot start: not silently stuck — the line's entry says why, holds
        // the line, and the preparation is asked for again on the next tick (#151 AK5).
        $this->assertSame($runs, $DB->count_records('local_catquizlab_run', ['experimentid' => $id,
            'status' => registry::STATUS_DRAFT]));
        $entry = $DB->get_record('local_catquizlab_execqueue', ['experimentid' => $id]);
        $this->assertSame(
            execution_queue::PREPARING . ': ' . get_string('preflight:nocourse', 'local_catquizlab'),
            $entry->reason
        );
        $this->assertSame($entry->reason, execution_queue::entries()[0]['reason'], 'shown in the line');
        $this->assertTrue(execution_queue::advance()['preparing']);
        $this->assertSame([$id], $this->preparations());

        // Fixed: it goes on by itself. Every run started, each with its own setup task; nothing created twice.
        set_config('experimentcourseid', (int) $this->getDataGenerator()->create_course()->id, 'local_catquizlab');
        $this->runAdhocTasks(self::TASK);
        $this->assertSame($runs, $DB->count_records('local_catquizlab_run', ['experimentid' => $id]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_run', ['experimentid' => $id,
            'status' => registry::STATUS_DRAFT]));
        $this->assertSame($runs, $DB->count_records('task_adhoc', ['classname' => '\\local_catquizlab\\task\\orchestrate_run']));
        $this->assertSame(execution_queue::PREPARING, $DB->get_field('local_catquizlab_execqueue', 'reason', ['id' => $entry->id]));
        $this->assertSame('preparing', experiment_status::snapshot($id, true)['phase']);

        // Prepared (the setup tasks are not run here; their end is): the line says the workers are starting, and
        // the entry no longer says "preparing".
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_READY, ['experimentid' => $id]);
        $this->assertSame('busy', execution_queue::advance()['reason']);
        $this->assertSame('', (string) $DB->get_field('local_catquizlab_execqueue', 'reason', ['id' => $entry->id]));
        $this->assertSame('starting', experiment_status::snapshot($id, true)['phase']);
    }

    /**
     * A large sweep: the start request builds nothing, whatever the size; the line's task creates every run once and
     * gives each its own setup task (#151 AK6, DoD3).
     *
     * @return void
     */
    public function test_a_large_sweep_is_built_in_the_background(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);
        set_config('experimentcourseid', (int) $this->getDataGenerator()->create_course()->id, 'local_catquizlab');
        $id = $this->saved(['replications' => 120]);
        $definition = $DB->get_field('local_catquizlab_experiment', 'configjson', ['id' => $id]);

        $this->assertTrue(experiment_runner::start($id)['ok']);
        $this->assertSame(0, $DB->count_records('local_catquizlab_run', ['experimentid' => $id]));
        execution_queue::advance();
        $this->expectOutputRegex('/120 run\(s\) created, 120 started, 0 not started/');
        $this->runAdhocTasks(self::TASK);
        $this->assertSame(120, $DB->count_records('local_catquizlab_run', ['experimentid' => $id]));
        $this->assertSame(120, $DB->count_records('local_catquizlab_run', ['experimentid' => $id,
            'status' => registry::STATUS_SCHEDULED]));
        $this->assertSame(120, $DB->count_records('task_adhoc', ['classname' => '\\local_catquizlab\\task\\orchestrate_run']));
        // Nothing of the definition changed by preparing it either (#151 AK11).
        $this->assertSame($definition, $DB->get_field('local_catquizlab_experiment', 'configjson', ['id' => $id]));
    }

    /**
     * Stopped while it waits to be prepared: the preparation does nothing (#151 AK7, stop intact).
     *
     * @return void
     */
    public function test_a_stopped_experiment_is_not_prepared_after_all(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);
        $id = $this->saved();
        experiment_runner::start($id);
        execution_queue::advance();
        $this->assertSame([$id], $this->preparations());

        \local_catquizlab\local\experiment_stop::stop($id);
        $this->expectOutputRegex('/no longer in the line; not prepared/');
        $this->runAdhocTasks(self::TASK);
        $this->assertSame(0, $DB->count_records('local_catquizlab_run', ['experimentid' => $id]));
        $this->assertNull(execution_queue::state_of($id));
    }

    /**
     * The start asks for a tick now, and a run that becomes ready too: neither waits for the five-minute mark.
     *
     * @return void
     */
    public function test_the_start_and_a_ready_run_ask_for_a_tick_now(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $kick = '\\local_catquizlab\\task\\kick_pipeline';
        $id = $this->saved();
        experiment_runner::start($id);
        experiment_runner::start($id);
        $this->assertSame(1, $DB->count_records('task_adhoc', ['classname' => $kick]), 'asked for once');

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_SCHEDULED, ['id' => $run->id]);
        \local_catquizlab\local\run_lifecycle::provisioned((int) $run->id, true);
        $this->assertSame(2, $DB->count_records('task_adhoc', ['classname' => $kick]), 'and once for ready runs');

        // And when the experiment's last run is aggregated: the next one in the line begins at once (#151 AK12).
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_AGGREGATING, ['id' => $run->id]);
        \local_catquizlab\local\run_lifecycle::aggregated((int) $run->id, true);
        $this->assertSame(experiment_runner::STATE_FINISHED, experiment_runner::state((int) $run->experimentid)['state']);
        $this->assertSame(3, $DB->count_records('task_adhoc', ['classname' => $kick]), 'and once for a finished experiment');
    }

    /**
     * Two clicks, two browsers, two ticks, two task runs: one entry, one sweep, one task at a time (#151 AK7).
     *
     * @return void
     */
    public function test_twice_is_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);
        $id = $this->saved();

        $first = experiment_runner::start($id);
        $second = experiment_runner::start($id);
        $this->assertSame([true, true], [$first['ok'], $second['ok']]);
        $this->assertSame('already-queued', $second['reason']);
        $this->assertSame(1, $DB->count_records('local_catquizlab_execqueue', ['experimentid' => $id]));

        execution_queue::advance();
        execution_queue::advance();
        $this->assertSame([$id], $this->preparations(), 'one preparation waiting, not two');

        $this->expectOutputRegex('/prepared in the background/');
        $this->runAdhocTasks(self::TASK);
        $runs = $DB->count_records('local_catquizlab_run', ['experimentid' => $id]);
        // A second preparation after the first: nothing more.
        \local_catquizlab\task\prepare_experiment::queue($id);
        $this->runAdhocTasks(self::TASK);
        $this->assertSame($runs, $DB->count_records('local_catquizlab_run', ['experimentid' => $id]));
        $this->assertSame(1, $DB->count_records('local_catquizlab_execqueue', ['experimentid' => $id]));
    }

    /**
     * Two started experiments: the second is not touched while the first is prepared and run (#151 AK7).
     *
     * @return void
     */
    public function test_two_started_experiments_go_one_after_the_other(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);
        $a = $this->saved(['name' => 'A']);
        $b = $this->saved(['name' => 'B']);
        experiment_runner::start($a);
        $this->assertSame(2, experiment_runner::start($b)['position']);

        $this->assertSame($a, execution_queue::advance()['started']);
        $busy = execution_queue::advance();
        $this->assertSame(['busy', $a], [$busy['reason'], $busy['holder']]);
        $this->assertSame([$a], $this->preparations(), 'B is not prepared while A holds the line');
        $this->assertSame(execution_queue::WAITING, execution_queue::state_of($b));
        $this->assertSame(0, $DB->count_records('local_catquizlab_run', ['experimentid' => $b]));
        $this->assertSame('queued', experiment_status::snapshot($b, true)['phase']);
    }

    /**
     * A preparation lost (the task gone, failed for good, cron restarted) is asked for again on the next tick — the
     * entry is not taken for an orphan and cancelled (#151 AK4).
     *
     * @return void
     */
    public function test_a_lost_preparation_is_asked_for_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);
        $id = $this->saved();
        experiment_runner::start($id);
        execution_queue::advance();
        $DB->delete_records('task_adhoc', ['classname' => self::TASK]);

        $again = execution_queue::advance();
        $this->assertSame(['busy', $id], [$again['reason'], $again['holder']]);
        $this->assertTrue($again['preparing']);
        $this->assertSame([], $again['closed'], 'not cancelled as an orphan');
        $this->assertSame([$id], $this->preparations());
        $this->assertSame(execution_queue::RUNNING, execution_queue::state_of($id));
    }

    /**
     * An installation that cannot run yet keeps the order and says why; it goes on once it can (#151 AK5).
     *
     * @return void
     */
    public function test_an_installation_not_ready_keeps_the_order_and_goes_on(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(false);
        $id = $this->saved();

        $started = experiment_runner::start($id);
        $this->assertTrue($started['ok']);
        $this->assertStringStartsWith('setup-incomplete', $started['warning']);
        $waiting = execution_queue::advance();
        $this->assertSame(['setup-incomplete', $id], [$waiting['reason'], $waiting['holder']]);
        $this->assertSame(execution_queue::WAITING, execution_queue::state_of($id));
        $this->assertSame([], $this->preparations());

        setup_wizard::assume_ready_for_testing(true);
        $this->assertSame([$id, 'preparing'], [execution_queue::advance()['started'], 'preparing']);
        $this->assertSame([$id], $this->preparations());
    }

    /**
     * What a person has to change blocks the start at once, with the reason, and puts nothing in the line.
     *
     * @return void
     */
    public function test_what_only_a_person_can_fix_blocks_at_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $broken = $this->saved();
        $DB->set_field('local_catquizlab_experiment', 'configjson', json_encode(['strategy' => 'nonsense']), ['id' => $broken]);
        $result = experiment_runner::start($broken);
        $this->assertSame([false, 'invalid-definition'], [$result['ok'], $result['reason']]);
        $this->assertNotSame('', $result['detail']);
        $this->assertNull(execution_queue::state_of($broken));

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FAILED, ['id' => $run->id]);
        $result = experiment_runner::start((int) $run->experimentid);
        $this->assertSame([false, 'not-startable-blocked'], [$result['ok'], $result['reason']]);

        $DB->set_field('local_catquizlab_experiment', 'timedeleting', time(), ['id' => $broken]);
        $this->assertSame('being-deleted', experiment_runner::start($broken)['reason']);
    }

    /**
     * Prepared and its turn: the line runs it, and until a sitting is played it says the workers are starting.
     *
     * @return void
     */
    public function test_its_turn_prepared_is_starting_not_running(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_READY, ['id' => $run->id]);
        $id = (int) $run->experimentid;

        $this->assertTrue(experiment_runner::start($id)['ok']);
        $this->assertSame([$id, ''], array_values(array_intersect_key(execution_queue::advance(), ['started' => 1,
            'reason' => 1])));
        $this->assertSame([], $this->preparations(), 'prepared already: nothing to prepare');
        $this->assertSame('starting', experiment_status::snapshot($id, true)['phase']);
        $this->assertSame('starting', experiment_status::library([$id])[$id]['phase']);
    }
}
