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

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\circuit_breaker;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\idle_diagnosis;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;

/**
 * Why waiting work is not played, run by run (#110, #133, #163: CLARA 17.11.2026).
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\idle_diagnosis
 */
final class idle_diagnosis_test extends \advanced_testcase {
    /**
     * A run with sittings in the given statuses; queued ones due unless a time is given.
     *
     * @param int $experimentid The experiment.
     * @param int $status The run's status.
     * @param array $sittings Status => how many.
     * @param int $nextruntime When the queued ones are due.
     * @return int The run.
     */
    protected function make_run(int $experimentid, int $status, array $sittings, int $nextruntime = 0): int {
        global $DB;

        $runid = (int) $this->getDataGenerator()->get_plugin_generator('local_catquizlab')->create_run(
            ['experimentid' => $experimentid, 'status' => $status]
        )->id;
        foreach ($sittings as $state => $n) {
            for ($i = 0; $i < $n; $i++) {
                $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid, 'personid' => 0,
                    'status' => $state, 'tries' => $state === attempt_scheduler::STATUS_FAILED ? 3 : 0,
                    'nextruntime' => $state === attempt_scheduler::STATUS_QUEUED ? $nextruntime : 0,
                    'timecreated' => time() - 600, 'timemodified' => time() - 600]);
            }
        }

        return $runid;
    }

    /**
     * The CLARA case: three running runs with failures and due sittings, none in the line, no worker. Each says that
     * the circuit breaker holds it, why, and what releases it; the line would not adopt them either.
     *
     * @return void
     */
    public function test_the_clara_case_is_held_by_the_breaker_and_says_so(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');

        $runs = [];
        foreach ([[25, 25], [10, 40], [28, 22]] as $i => [$failed, $queued]) {
            $experimentid = (int) $generator->create_experiment(['name' => 'Exp' . (22 + $i)])->id;
            $runid = $this->make_run($experimentid, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_FAILED => $failed,
                attempt_scheduler::STATUS_QUEUED => $queued]);
            run_lifecycle::set_paused($runid, true, circuit_breaker::PAUSE_REASON . ':submit_http_5xx');
            $runs[$runid] = [$failed, $queued];
        }

        $found = idle_diagnosis::runs();
        $this->assertCount(3, $found);
        foreach ($found as $run) {
            [$failed, $queued] = $runs[$run['runid']];
            $this->assertSame(idle_diagnosis::PAUSED_BREAKER, $run['verdict']);
            $this->assertSame([$queued, $queued, $failed, ''], [$run['queued'], $run['due'], $run['failed'], $run['line']]);
            $this->assertSame(get_string('reason:submit_http_5xx', 'local_catquizlab'), $run['cause']);
            $this->assertStringContainsString(get_string('circuit:resetcontinue', 'local_catquizlab'), $run['text']);
            $this->assertStringContainsString($failed . ' failed', $run['text']);
        }

        // Due is not claimable: the line does not take them in, and the next tick changes nothing.
        execution_queue::advance();
        $this->assertSame(0, execution_queue::holder());
        foreach (idle_diagnosis::runs() as $run) {
            $this->assertSame(idle_diagnosis::PAUSED_BREAKER, $run['verdict']);
        }
        $this->assertSame(0, attempt_scheduler::queue_breakdown()['claimable']);

        // Released by a person: the line takes the experiment in, and its sittings are claimable.
        $first = array_key_first($runs);
        circuit_breaker::reset_and_continue($first);
        $verdicts = array_column(idle_diagnosis::runs(), 'verdict', 'runid');
        $this->assertSame(idle_diagnosis::NOT_IN_LINE, $verdicts[$first]);
        execution_queue::advance();
        $verdicts = array_column(idle_diagnosis::runs(), 'verdict', 'runid');
        $this->assertSame(idle_diagnosis::CLAIMABLE, $verdicts[$first]);
    }

    /**
     * Every other reason, each where the claim would stop: deleting, not runnable, paused by a person, waiting out a
     * retry delay, waiting in the line behind another experiment.
     *
     * @return void
     */
    public function test_every_reason_in_the_order_the_claim_checks_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $queued = attempt_scheduler::STATUS_QUEUED;

        $holder = (int) $generator->create_experiment(['name' => 'Holder'])->id;
        $holding = $this->make_run($holder, registry::STATUS_RUNNING, [$queued => 4]);
        $behind = (int) $generator->create_experiment(['name' => 'Behind'])->id;
        $waiting = $this->make_run($behind, registry::STATUS_RUNNING, [$queued => 3]);
        $later = $this->make_run($behind, registry::STATUS_RUNNING, [$queued => 2], time() + 3600);
        $byhand = $this->make_run($behind, registry::STATUS_RUNNING, [$queued => 2]);
        run_lifecycle::set_paused($byhand, true, 'operator');
        $draft = $this->make_run($behind, registry::STATUS_SCHEDULED, [$queued => 1]);
        $gone = (int) $generator->create_experiment(['name' => 'Gone'])->id;
        $deleting = $this->make_run($gone, registry::STATUS_RUNNING, [$queued => 1]);
        $DB->set_field('local_catquizlab_experiment', 'timedeleting', time(), ['id' => $gone]);

        // The line: the first holds it, the second waits.
        execution_queue::advance();
        $this->assertSame($holder, execution_queue::holder());
        $this->assertSame(execution_queue::WAITING, execution_queue::state_of($behind));

        $runs = array_column(idle_diagnosis::runs(), null, 'runid');
        $this->assertSame([
            $holding => idle_diagnosis::CLAIMABLE,
            $waiting => idle_diagnosis::IN_LINE,
            $later => idle_diagnosis::NOT_DUE,
            $byhand => idle_diagnosis::PAUSED_PERSON,
            $draft => idle_diagnosis::NOT_RUNNABLE,
            $deleting => idle_diagnosis::DELETING,
        ], array_map(static fn(array $run): string => $run['verdict'], $runs));
        $this->assertStringContainsString('behind experiment ' . $holder, $runs[$waiting]['text']);
        $this->assertSame(0, $runs[$later]['due']);
        $this->assertGreaterThan(time(), $runs[$later]['nextdue']);

        // Waiting with nobody at the front says so, not "behind experiment 0".
        $free = $runs[$waiting];
        $free['holder'] = 0;
        $this->assertStringContainsString('nobody holds it now', idle_diagnosis::text($free));

        // One experiment only.
        $this->assertSame([$holding], array_column(idle_diagnosis::runs($holder), 'runid'));

        // Read only: the same answer twice, nothing moved.
        $this->assertSame($runs, array_column(idle_diagnosis::runs(), null, 'runid'));
    }
}
