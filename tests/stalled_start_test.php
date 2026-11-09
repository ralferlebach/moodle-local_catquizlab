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

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\experiment_runner;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\experiment_status;
use local_catquizlab\local\log_view;
use local_catquizlab\local\registry;
use local_catquizlab\local\setup_wizard;
use local_catquizlab\local\worker_launcher;

/**
 * The incident of 9 November 2026: workers did not start, and nothing said why.
 *
 * Six runs had been created and never started; the progress page called that "being prepared" and offered no
 * button. The execution queue had been held for 48 days by an experiment without runs, every tick answering
 * "busy" to nobody. And the one log line there was — "no-claimable-work" — was filed as an error, without a
 * word of what was missing.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_runner
 * @covers     \local_catquizlab\local\experiment_status
 * @covers     \local_catquizlab\local\execution_queue
 * @covers     \local_catquizlab\local\worker_launcher
 * @covers     \local_catquizlab\local\log_view
 * @covers     \local_catquizlab\local\attempt_scheduler
 */
final class stalled_start_test extends \advanced_testcase {
    /**
     * Forget the readiness a test declared.
     *
     * @return void
     */
    protected function tearDown(): void {
        setup_wizard::assume_ready_for_testing(null);
        experiment_status::reset();
        parent::tearDown();
    }

    /**
     * An experiment with runs in a status, and its id.
     *
     * @param int $runs How many runs.
     * @param int $status Their status.
     * @return array{experimentid: int, runids: int[]}
     */
    protected function experiment(int $runs, int $status): array {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experimentid = (int) $generator->create_experiment()->id;
        $runids = [];
        for ($i = 0; $i < $runs; $i++) {
            $runid = (int) $generator->create_run(['experimentid' => $experimentid])->id;
            $DB->set_field('local_catquizlab_run', 'status', $status, ['id' => $runid]);
            $runids[] = $runid;
        }

        return ['experimentid' => $experimentid, 'runids' => $runids];
    }

    /**
     * Runs created and never started are said as such, and the one way on is offered.
     *
     * @return void
     */
    public function test_created_runs_are_not_a_dead_end(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $e = $this->experiment(6, registry::STATUS_DRAFT);

        $state = experiment_runner::state($e['experimentid']);
        $this->assertSame(experiment_runner::STATE_CREATED, $state['state'], 'not "being prepared"');
        $this->assertSame(6, $state['runs']['draft']);
        $this->assertTrue($state['canstartruns'], 'there is a way on');
        $this->assertFalse($state['canstart']);

        $status = experiment_status::snapshot($e['experimentid'], true);
        $this->assertSame('created', $status['phase']);
        $this->assertSame(get_string('status:phasecreated', 'local_catquizlab'), $status['label']);
        $this->assertSame('startruns', $status['next']['kind']);
        $this->assertSame('startruns', $status['next']['action']);
        $this->assertTrue($status['next']['post']);

        // The library says the same.
        $this->assertSame('created', experiment_status::library([$e['experimentid']])[$e['experimentid']]['phase']);

        // Once their setup tasks are queued, they are being prepared — and the button is gone.
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_SCHEDULED, ['experimentid' => $e['experimentid']]);
        $preparing = experiment_runner::state($e['experimentid']);
        $this->assertSame(experiment_runner::STATE_PREPARING, $preparing['state']);
        $this->assertFalse($preparing['canstartruns']);
        $this->assertSame('preparing', experiment_status::snapshot($e['experimentid'], true)['phase']);
    }

    /**
     * The progress page shows the button.
     *
     * @return void
     */
    public function test_the_progress_page_offers_to_start_the_runs(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $e = $this->experiment(2, registry::STATUS_DRAFT);
        $PAGE->set_url('/local/catquizlab/runs.php');
        $PAGE->set_context(\context_system::instance());

        $context = \local_catquizlab\local\progress_view::context($e['experimentid']);
        $this->assertTrue($context['runner']['canstartruns']);
        $html = $PAGE->get_renderer('core')->render_from_template('local_catquizlab/progress', $context);
        $this->assertStringContainsString('name="action" value="startruns"', $html);
        $this->assertStringContainsString(get_string('runner:startruns', 'local_catquizlab'), $html);
    }

    /**
     * A running entry whose experiment has no runs is closed, and the next one starts.
     *
     * @return void
     */
    public function test_an_orphaned_running_entry_does_not_hold_the_queue(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);

        // The installation's entry: experiment 6, "running" for 48 days, without a single run.
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $empty = (int) $generator->create_experiment()->id;
        $DB->insert_record('local_catquizlab_execqueue', (object) ['experimentid' => $empty, 'state' => 'running',
            'position' => 1, 'userid' => 2, 'timecreated' => time() - 48 * DAYSECS, 'timestarted' => time() - 48 * DAYSECS]);
        // Behind it, one that is ready.
        $ready = $this->experiment(1, registry::STATUS_READY);
        $this->assertTrue(execution_queue::enqueue($ready['experimentid'])['ok']);

        $result = execution_queue::advance();
        $this->assertSame($ready['experimentid'], $result['started'], 'the line moves');
        $this->assertSame([['experimentid' => $empty, 'reason' => 'orphaned: experiment has no runs']], $result['closed']);
        $closed = $DB->get_record('local_catquizlab_execqueue', ['experimentid' => $empty]);
        $this->assertSame(execution_queue::CANCELLED, $closed->state);
        $this->assertGreaterThan(0, (int) $closed->timefinished);
    }

    /**
     * Running entries whose runs were never started, waiting entries without runs or experiment: out of the line.
     *
     * @return void
     */
    public function test_entries_that_can_never_run_are_taken_out(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);

        $created = $this->experiment(2, registry::STATUS_DRAFT);
        $DB->insert_record('local_catquizlab_execqueue', (object) ['experimentid' => $created['experimentid'],
            'state' => 'running', 'position' => 1, 'userid' => 2, 'timecreated' => time(), 'timestarted' => time()]);
        $DB->insert_record('local_catquizlab_execqueue', (object) ['experimentid' => 999999, 'state' => 'waiting',
            'position' => 2, 'userid' => 2, 'timecreated' => time()]);

        $result = execution_queue::advance();
        $this->assertSame('empty', $result['reason']);
        $this->assertSame(
            ['orphaned: runs were never started', 'orphaned: experiment deleted'],
            array_column($result['closed'], 'reason')
        );
        $this->assertSame(0, $DB->count_records_select('local_catquizlab_execqueue', "state IN ('running', 'waiting')"));
    }

    /**
     * A genuine running experiment still holds the line — and says who and since when.
     *
     * @return void
     */
    public function test_a_busy_queue_names_its_holder(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        setup_wizard::assume_ready_for_testing(true);
        $running = $this->experiment(1, registry::STATUS_READY);
        execution_queue::enqueue($running['experimentid']);
        execution_queue::advance();

        $busy = execution_queue::advance();
        $this->assertSame('busy', $busy['reason']);
        $this->assertSame($running['experimentid'], $busy['holder']);
        $this->assertGreaterThan(0, $busy['since']);
    }

    /**
     * Deleting an experiment takes it out of the line.
     *
     * @return void
     */
    public function test_deleting_an_experiment_forgets_its_entry(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $id = (int) $generator->create_experiment()->id;
        $DB->insert_record('local_catquizlab_execqueue', (object) ['experimentid' => $id, 'state' => 'waiting',
            'position' => 1, 'userid' => 2, 'timecreated' => time()]);

        experiment_service::delete($id);
        $this->assertSame('cancelled', $DB->get_field('local_catquizlab_execqueue', 'state', ['experimentid' => $id]));
    }

    /**
     * Why nothing is claimable is said: the runs never started, of which experiment, and the held queue.
     *
     * @return void
     */
    public function test_the_tick_says_why_nothing_is_claimable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $e = $this->experiment(6, registry::STATUS_DRAFT);

        $why = worker_launcher::idle_reasons(
            attempt_scheduler::queue_breakdown(),
            ['reason' => 'busy', 'holder' => 6, 'since' => time() - 48 * DAYSECS]
        );
        $this->assertSame(['runsnotstarted', 'queueheld'], array_column($why, 'code'));
        $this->assertSame(6, $why[0]['n']);
        $this->assertSame([$e['experimentid']], $why[0]['experiments']);

        $text = worker_launcher::idle_text($why);
        $this->assertStringContainsString(get_string('tick:why_runsnotstarted', 'local_catquizlab', (object) [
            'n' => 6, 'experiments' => $e['experimentid'], 'since' => '–']), $text);
        $this->assertStringContainsString('6', $text);

        // Nothing at all: said too, not left blank.
        global $DB;
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['experimentid' => $e['experimentid']]);
        $this->assertSame(['nothing'], array_column(worker_launcher::idle_reasons(['claimable' => 0]), 'code'));
    }

    /**
     * The tick's line: a state is info, what needs a person a warning, only a failed start an error.
     *
     * @return void
     */
    public function test_the_tick_line_has_the_right_severity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $line = static function (array $last): array {
            set_config(
                'lastdispatch',
                json_encode($last + ['time' => time(), 'launched' => 0, 'claimable' => 0]),
                'local_catquizlab'
            );
            $lines = array_values(array_filter(
                log_view::lines(['channel' => 'task']),
                static fn(array $l): bool => $l['seq'] === PHP_INT_MAX
            ));

            return $lines[0];
        };

        $idle = $line(['reason' => 'no-claimable-work', 'why' => [['code' => 'nothing', 'n' => 0]]]);
        $this->assertSame('info', $idle['severity'], 'nothing to do is not an error');
        $this->assertFalse($idle['failed']);
        $this->assertStringContainsString(get_string('tick:why_nothing', 'local_catquizlab'), $idle['text']);

        $todo = $line(['reason' => 'no-claimable-work', 'why' => [['code' => 'runsnotstarted', 'n' => 6,
            'experiments' => [16]]]]);
        $this->assertSame('warning', $todo['severity'], 'runs waiting for a person');
        $this->assertStringContainsString('16', $todo['text']);

        $broken = $line(['reason' => 'not-configured: worker_token', 'why' => []]);
        $this->assertSame('error', $broken['severity']);
        $this->assertTrue($broken['failed']);

        $busy = $line(['reason' => 'all-slots-busy', 'launched' => 0, 'why' => []]);
        $this->assertSame('info', $busy['severity']);
    }

    /**
     * The tick itself: closes the orphaned entry, says who holds nothing, and records why nothing started.
     *
     * @return void
     */
    public function test_the_tick_reports_the_incident_in_words(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 1, 'local_catquizlab');
        set_config('worker_exec_enabled', 1, 'local_catquizlab');
        set_config('worker_node_path', '/usr/bin/node', 'local_catquizlab');
        set_config('worker_base_url', 'https://example.invalid', 'local_catquizlab');
        set_config('worker_token', str_repeat('a', 32), 'local_catquizlab');

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $empty = (int) $generator->create_experiment()->id;
        $DB->insert_record('local_catquizlab_execqueue', (object) ['experimentid' => $empty, 'state' => 'running',
            'position' => 1, 'userid' => 2, 'timecreated' => time(), 'timestarted' => time()]);
        $e = $this->experiment(6, registry::STATUS_DRAFT);

        ob_start();
        (new \local_catquizlab\task\pipeline_tick())->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString(get_string('tick:queueclosed', 'local_catquizlab', (object) [
            'experimentid' => $empty, 'reason' => 'orphaned: experiment has no runs']), $output);
        $this->assertStringContainsString('no worker started: no-claimable-work — ', $output);
        $this->assertStringContainsString((string) $e['experimentid'], $output);

        $last = json_decode(get_config('local_catquizlab', 'lastdispatch'), true);
        $this->assertSame('no-claimable-work', $last['reason']);
        $this->assertSame('runsnotstarted', $last['why'][0]['code']);
        $this->assertSame([$e['experimentid']], $last['why'][0]['experiments']);
    }

    /**
     * Scheduling sittings costs the same number of queries for 20 people as for 200, and twice adds nothing.
     *
     * @return void
     */
    public function test_scheduling_is_batched_and_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');

        $cost = function (int $people) use ($generator, $DB): array {
            $runid = (int) $generator->create_run()->id;
            for ($i = 1; $i <= $people; $i++) {
                $generator->create_person(['runid' => $runid, 'twinid' => sprintf('t%05d', $i),
                    'moodleuserid' => 100000 + $i]);
            }
            $before = $DB->perf_get_queries();
            $created = attempt_scheduler::schedule($runid);

            return [$created, $DB->perf_get_queries() - $before, $runid];
        };

        [$small, $smallq] = $cost(20);
        [$large, $largeq, $runid] = $cost(200);
        $this->assertSame(20, $small);
        $this->assertSame(200, $large);
        $this->assertLessThan(50, $largeq, "200 people cost {$largeq} queries");
        $this->assertLessThanOrEqual($smallq + 5, $largeq, "20 cost {$smallq}, 200 cost {$largeq}");

        $this->assertSame(0, attempt_scheduler::schedule($runid), 'twice adds nothing');
        $this->assertSame(200, $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]));
    }
}
