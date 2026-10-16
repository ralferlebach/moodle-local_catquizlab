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

use local_catquizlab\external\live_status;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\experiment_status;
use local_catquizlab\local\progress_view;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\output\shell;

/**
 * One status per experiment, the same wherever it is shown, and the one next step it allows (#128).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_status
 * @covers     \local_catquizlab\output\shell
 * @covers     \local_catquizlab\external\live_status
 */
final class experiment_status_test extends \advanced_testcase {
    /** @var int The experiment. */
    protected int $experimentid = 0;

    /** @var int[] Its runs. */
    protected array $runs = [];

    /**
     * An experiment of two runs, ten sittings each, all queued.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        experiment_status::reset();

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Status';
        $definition['sweep'] = ['factors' => ['strategy' => ['fastest', 'allsubs']]];
        $this->experimentid = (int) experiment_service::save($definition)['id'];
    }

    /**
     * Create the runs and their sittings, in a run status.
     *
     * @param int $status The run status.
     * @return void
     */
    protected function runs(int $status): void {
        global $DB;

        experiment_service::create_sweep($this->experimentid);
        $this->runs = array_map('intval', array_keys($DB->get_records(
            'local_catquizlab_run',
            ['experimentid' => $this->experimentid],
            'id ASC',
            'id'
        )));
        foreach ($this->runs as $runid) {
            $DB->set_field('local_catquizlab_run', 'status', $status, ['id' => $runid]);
            for ($i = 0; $i < 10; $i++) {
                $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid, 'personid' => $runid * 100 + $i,
                    'status' => attempt_scheduler::STATUS_QUEUED, 'tries' => 0, 'timecreated' => time(),
                    'timemodified' => time()]);
            }
        }
    }

    /**
     * The status, taken again.
     *
     * @return array
     */
    protected function current_status(): array {
        experiment_status::reset();

        return experiment_status::snapshot($this->experimentid, true);
    }

    /**
     * Set every sitting of a run to a status, with history.
     *
     * @param int $runid The run.
     * @param int $status The attempt status.
     * @param int $count How many.
     * @return void
     */
    protected function sittings(int $runid, int $status, int $count): void {
        global $DB;

        $ids = array_slice(array_keys($DB->get_records('local_catquizlab_attempt', ['runid' => $runid,
            'status' => attempt_scheduler::STATUS_QUEUED], 'id ASC', 'id')), 0, $count);
        foreach ($ids as $id) {
            $DB->update_record('local_catquizlab_attempt', (object) ['id' => $id, 'status' => $status, 'tries' => 1,
                'leaseowner' => $status === attempt_scheduler::STATUS_RUNNING ? 'w1' : null,
                'leaseexpires' => $status === attempt_scheduler::STATUS_RUNNING ? time() + 600 : 0]);
            attempt_history::record((int) $id, attempt_history::STARTED, ['tryno' => 1]);
            if ($status === attempt_scheduler::STATUS_COLLECTED) {
                attempt_history::record((int) $id, attempt_history::COLLECTED, ['tryno' => 1, 'runtimems' => 5000]);
            }
        }
    }

    /**
     * From plan to results: each phase with its one next step; no start once queued or running.
     *
     * @return void
     */
    public function test_the_phases_and_their_next_step(): void {
        $status = $this->current_status();
        $this->assertSame(['planned', 'create'], [$status['phase'], $status['next']['kind']]);
        $this->assertTrue($status['next']['post']);

        $this->runs(registry::STATUS_READY);
        $status = $this->current_status();
        $this->assertSame(['ready', 'start'], [$status['phase'], $status['next']['kind']]);
        $this->assertSame('startexperiment', $status['next']['action']);

        // Queued: no second start.
        $this->assertTrue(execution_queue::enqueue($this->experimentid)['ok']);
        $status = $this->current_status();
        $this->assertSame(['queued', 'progress'], [$status['phase'], $status['next']['kind']]);
        $this->assertFalse(progress_view::context($this->experimentid)['runner']['canstart']);
        $this->assertNotNull(progress_view::context($this->experimentid)['runner']['queued'], 'shown as queued');

        // Collecting.
        foreach ($this->runs as $runid) {
            run_lifecycle::set_status($runid, registry::STATUS_RUNNING, 'test');
        }
        $this->sittings($this->runs[0], attempt_scheduler::STATUS_COLLECTED, 4);
        $this->sittings($this->runs[0], attempt_scheduler::STATUS_RUNNING, 1);
        $status = $this->current_status();
        $this->assertSame(['collecting', 'progress'], [$status['phase'], $status['next']['kind']]);
        $this->assertSame('4 of 20 sittings (20 %)', $status['sittings']);
        $this->assertStringContainsString('Technical:', $status['technical']);
        $this->assertFalse(progress_view::context($this->experimentid)['runner']['canstart'], 'no start while running');

        // Blocked: a run held by the breaker.
        run_lifecycle::set_paused($this->runs[1], true, 'circuit_breaker:submit_http_5xx');
        $status = $this->current_status();
        $this->assertSame(['blocked', 'problems', 'problem'], [$status['phase'], $status['next']['kind'], $status['tone']]);
        $this->assertSame(1, $status['problems']);
        $this->assertStringContainsString('#catquizlab-recovery', $status['next']['url']);
        run_lifecycle::set_paused($this->runs[1], false);

        // Complete, with one sitting failed for good.
        global $DB;
        foreach ($this->runs as $runid) {
            $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_COLLECTED, ['runid' => $runid]);
            run_lifecycle::set_status($runid, registry::STATUS_FINISHED, 'test');
        }
        $status = $this->current_status();
        $this->assertSame(['complete', 'results'], [$status['phase'], $status['next']['kind']]);
        $DB->set_field_select(
            'local_catquizlab_attempt',
            'status',
            attempt_scheduler::STATUS_FAILED,
            'runid = ? AND id = (SELECT MIN(id) FROM {local_catquizlab_attempt} WHERE runid = ?)',
            [$this->runs[0], $this->runs[0]]
        );
        $status = $this->current_status();
        $this->assertSame(['completefailures', 'results'], [$status['phase'], $status['next']['kind']]);
        $this->assertSame(1, $status['problems']);
    }

    /**
     * Header, progress card, run summary and poll say the same at the same time.
     *
     * @return void
     */
    public function test_one_status_everywhere(): void {
        $this->runs(registry::STATUS_RUNNING);
        $this->sittings($this->runs[0], attempt_scheduler::STATUS_COLLECTED, 3);
        $this->sittings($this->runs[0], attempt_scheduler::STATUS_RUNNING, 2);
        experiment_status::reset();

        $header = shell::context(shell::STEP_PROGRESS, $this->experimentid)['status'];
        $page = progress_view::context($this->experimentid);
        $poll = live_status::execute($this->experimentid);

        $this->assertSame('Collecting sittings', $header['label']);
        $this->assertSame($header['label'], $page['progress']['label']);
        $this->assertSame($header['label'], $page['runner']['label']);
        $this->assertSame($header['label'], $poll['status']['label']);
        $this->assertSame($header['label'], $poll['progress']['label']);
        $this->assertSame($header['label'], $poll['experiment']['label']);
        $this->assertSame([3, 20], [$poll['experiment']['done'], $poll['experiment']['total']]);
        $this->assertSame([3, 20], [$page['progress']['done'], $page['progress']['total']]);
        // On the progress step, "view progress" is not offered as a button to itself.
        $this->assertFalse($header['shownext']);
        $this->assertTrue(shell::context(shell::STEP_RESULTS, $this->experimentid)['status']['shownext']);
        // No installation verdict beside the experiment's own.
        $this->assertNull(shell::context(shell::STEP_PROGRESS, $this->experimentid)['situation']);
    }

    /**
     * Without an experiment the installation's line reads in words, not the internal state.
     *
     * @return void
     */
    public function test_the_installation_line_reads_in_words(): void {
        $context = shell::context(shell::STEP_PROGRESS, 0);
        $this->assertNull($context['status']);
        $this->assertNotSame('', $context['situation']['headline']);
        shell::reset_for_testing();
        $html = shell::render(shell::STEP_PROGRESS, 0);
        $this->assertStringContainsString($context['situation']['headline'], $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*(working|failing|idle|stalled)\s*</', $html);
    }

    /**
     * The library's cheap summary says the same phase as the snapshot (#129).
     *
     * @return void
     */
    public function test_the_library_agrees_with_the_snapshot(): void {
        $this->assertSame('planned', experiment_status::library([$this->experimentid])[$this->experimentid]['phase']);

        $this->runs(registry::STATUS_READY);
        $this->assertSame('ready', experiment_status::library([$this->experimentid])[$this->experimentid]['phase']);

        foreach ($this->runs as $runid) {
            run_lifecycle::set_status($runid, registry::STATUS_RUNNING, 'test');
        }
        $this->sittings($this->runs[0], attempt_scheduler::STATUS_COLLECTED, 4);
        $this->sittings($this->runs[0], attempt_scheduler::STATUS_RUNNING, 1);
        $library = experiment_status::library([$this->experimentid])[$this->experimentid];
        $this->assertSame([$this->current_status()['phase'], 4, 20, 20], [$library['phase'], $library['done'], $library['total'],
            $library['percent']]);

        run_lifecycle::set_paused($this->runs[1], true, 'circuit_breaker:submit_http_5xx');
        $this->assertSame('blocked', experiment_status::library([$this->experimentid])[$this->experimentid]['phase']);
        $this->assertSame([], experiment_status::library([]));
    }
}
