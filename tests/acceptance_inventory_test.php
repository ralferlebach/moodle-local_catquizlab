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

use local_catquizlab\local\acceptance_inventory;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\circuit_breaker;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\worker_registry;

/**
 * The read-only inventory (re-audit 18.11.2026, section 3): it writes nothing, a clean installation has no
 * contradiction, and each contradiction it looks for is found when it is there.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\acceptance_inventory
 */
final class acceptance_inventory_test extends \advanced_testcase {
    /**
     * The checks by name.
     *
     * @return array<string, array>
     */
    protected function checks(): array {
        return array_column((new acceptance_inventory())->run()['checks'], null, 'name');
    }

    /**
     * A sitting of a run.
     *
     * @param int $runid The run.
     * @param int $status Its status.
     * @param int $personid Its person.
     * @param array $extra More fields.
     * @return int
     */
    protected function sitting(int $runid, int $status, int $personid, array $extra = []): int {
        global $DB;

        return (int) $DB->insert_record('local_catquizlab_attempt', (object) ($extra + ['runid' => $runid,
            'personid' => $personid, 'status' => $status, 'tries' => 0, 'nextruntime' => 0, 'timecreated' => time(),
            'timemodified' => time()]));
    }

    /**
     * A normal installation: experiments in the line, a paused run, sittings in every status. Nothing contradicts,
     * the database saw no write, and the counts are the ones made.
     *
     * @return void
     */
    public function test_a_clean_installation_writes_nothing_and_contradicts_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');

        $first = (int) $generator->create_experiment(['name' => 'First'])->id;
        $second = (int) $generator->create_experiment(['name' => 'Second'])->id;
        $run = (int) $generator->create_run(['experimentid' => $first, 'status' => registry::STATUS_RUNNING])->id;
        $paused = (int) $generator->create_run(['experimentid' => $second, 'status' => registry::STATUS_RUNNING])->id;
        foreach (
            [attempt_scheduler::STATUS_QUEUED, attempt_scheduler::STATUS_QUEUED, attempt_scheduler::STATUS_COLLECTED,
                attempt_scheduler::STATUS_FAILED, attempt_scheduler::STATUS_CANCELLED] as $i => $status
        ) {
            $this->sitting($run, $status, $i + 1);
            $this->sitting($paused, $status, $i + 1);
        }
        run_lifecycle::set_paused($paused, true, circuit_breaker::PAUSE_REASON . ':submit_http_5xx');
        execution_queue::advance();

        $reads = $DB->perf_get_reads();
        $report = (new acceptance_inventory())->run();
        $this->assertGreaterThan($reads, $DB->perf_get_reads());
        $this->assertSame(0, $report['writes']);
        $checks = array_column($report['checks'], null, 'name');
        $contradictions = array_filter(
            $report['checks'],
            static fn(array $check): bool => $check['verdict'] === acceptance_inventory::CONTRADICTION
        );
        $this->assertSame([], array_values($contradictions));

        $this->assertSame(4, $checks['sittings queued']['value']);
        $this->assertSame(4, $checks['sittings queued and due (nextruntime <= now)']['value']);
        $this->assertSame(2, $checks['sittings due and claimable now']['value']);
        $this->assertSame(
            [['runid' => $paused, 'experimentid' => $second, 'by' => 'breaker',
            'cause' => get_string('reason:submit_http_5xx', 'local_catquizlab')]],
            $checks['paused runs, with who paused them and why (run manifest)']['value']
        );
        $this->assertSame(
            ['collected' => 2, 'cancelled' => 2, 'failed' => 2, 'queued' => 4, 'inplay' => 0, 'total' => 10],
            $checks['sittings: collected, cancelled, failed, queued, in play, total']['value']
        );
        $this->assertSame(
            [['experimentid' => $first, 'since' => $checks['holders (state running)']['value'][0]['since']]],
            $checks['holders (state running)']['value']
        );
        $this->assertSame([], $checks['tables missing']['value']);
        $this->assertSame([], $checks['differences to db/install.xml']['value']);
    }

    /**
     * Each contradiction the inventory looks for, made and found.
     *
     * @return void
     */
    public function test_each_contradiction_is_found(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = (int) $generator->create_experiment(['name' => 'One'])->id;
        $other = (int) $generator->create_experiment(['name' => 'Two'])->id;
        $run = (int) $generator->create_run(['experimentid' => $experiment, 'status' => registry::STATUS_FINISHED])->id;

        // A finished run with a sitting still queued, a person with two sittings, an engine attempt twice.
        $this->sitting($run, attempt_scheduler::STATUS_QUEUED, 7);
        $this->sitting($run, attempt_scheduler::STATUS_COLLECTED, 7, ['engineattemptid' => 99]);
        $this->sitting($run, attempt_scheduler::STATUS_COLLECTED, 8, ['engineattemptid' => 99]);
        // A sitting in play nobody owns.
        $this->sitting($run, attempt_scheduler::STATUS_RUNNING, 9, ['leaseexpires' => time() + 600]);
        // Orphans: a sitting and a claim of a run that does not exist, history of a sitting that does not exist.
        $this->sitting(999999, attempt_scheduler::STATUS_QUEUED, 1);
        $DB->insert_record('local_catquizlab_twinclaim', (object) ['runid' => 999999, 'twinid' => 'x', 'personid' => 1,
            'timecreated' => time()]);
        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => 999999, 'runid' => $run, 'tryno' => 1,
            'outcome' => 'x', 'workerid' => 'w', 'engineattemptid' => 0, 'runtimems' => 0, 'detail' => '',
            'timecreated' => time()]);
        // Two holders of the line; an entry of an experiment that is gone.
        foreach ([$experiment, $other] as $i => $id) {
            $DB->insert_record('local_catquizlab_execqueue', (object) ['experimentid' => $id,
                'state' => execution_queue::RUNNING, 'position' => $i + 1, 'reason' => '', 'userid' => 2,
                'timecreated' => time(), 'timestarted' => time(), 'timefinished' => 0]);
        }
        $DB->insert_record('local_catquizlab_execqueue', (object) ['experimentid' => 888888,
            'state' => execution_queue::WAITING, 'position' => 3, 'reason' => '', 'userid' => 2, 'timecreated' => time(),
            'timestarted' => 0, 'timefinished' => 0]);
        // A crashed worker still naming a sitting.
        $DB->insert_record('local_catquizlab_worker', (object) ['workerid' => 'w-dead', 'slot' => 1,
            'status' => worker_registry::STATUS_CRASHED, 'pid' => 0, 'hostname' => 'h', 'jobsdone' => 0,
            'currentattempt' => 5, 'heartbeat' => time() - 3600, 'timecreated' => time(), 'timemodified' => time()]);

        $checks = $this->checks();
        foreach (
            [
            'finished or cancelled runs with sittings still queued or in play',
            'sittings of the same person in the same run',
            'engine attempts linked to two sittings',
            'sittings in play without a lease owner',
            'sittings without their run',
            'twin claims without their run',
            'attempt history without its sitting',
            'holders (state running)',
            'open line entries of experiments that no longer exist',
            'stopped or crashed workers still naming a sitting',
            ] as $name
        ) {
            $this->assertSame(acceptance_inventory::CONTRADICTION, $checks[$name]['verdict'], $name);
        }
        $this->assertSame(0, $checks['database writes while the inventory ran']['value']);
    }
}
