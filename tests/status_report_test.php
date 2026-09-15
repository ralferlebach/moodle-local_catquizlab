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
 * Every stateful component says the same three things.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;
use local_catquizlab\local\status_report;
use local_catquizlab\local\worker_registry;

/**
 * Status report tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\status_report
 */
final class status_report_test extends \advanced_testcase {
    /**
     * A run in a given state, with attempts if asked for.
     *
     * @param int $status The run status.
     * @param int $queued How many queued attempts to add.
     * @return int The run id.
     */
    protected function make_run(int $status, int $queued = 0): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $DB->set_field('local_catquizlab_run', 'status', $status, ['id' => $runid]);

        for ($i = 0; $i < $queued; $i++) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => 0, 'status' => attempt_scheduler::STATUS_QUEUED,
                'tries' => 0, 'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        return $runid;
    }

    /**
     * Every card carries the same three things.
     *
     * @return void
     */
    public function test_every_card_follows_the_contract(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cards = [
            'queue'    => status_report::queue(),
            'pipeline' => status_report::pipeline(),
            'run'      => status_report::run($this->make_run(registry::STATUS_READY)),
        ];

        foreach ($cards as $name => $card) {
            // A contract that allows exceptions is a style guide.
            $this->assertNotSame('', $card['state'], $name . ' has no state.');
            $this->assertArrayHasKey('reason', $card, $name . ' has no reason field.');
            $this->assertArrayHasKey('action', $card, $name . ' has no action field.');

            $flags = (int) $card['good'] + (int) $card['watch'] + (int) $card['bad'];
            $this->assertSame(1, $flags, $name . ' is in ' . $flags . ' levels at once.');

            if ($card['action'] !== null) {
                $this->assertNotSame('', $card['action']['label']);
                $this->assertNotSame('', $card['action']['url']);
            }
        }
    }

    /**
     * A scheduled run says what it waits for, and offers a way on.
     *
     * @return void
     */
    public function test_a_scheduled_run_names_what_it_waits_for(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $card = status_report::run($this->make_run(registry::STATUS_SCHEDULED));

        // The bare word "scheduled" was the complaint: accurate, and it leaves the
        // reader to work out whether anything is happening.
        $this->assertNotSame('', $card['reason']);
        $this->assertNotNull($card['action']);
    }

    /**
     * Work with nobody to do it reads as a problem, not as progress.
     *
     * @return void
     */
    public function test_a_ready_run_without_workers_is_a_problem(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $DB->delete_records('local_catquizlab_worker');
        $card = status_report::run($this->make_run(registry::STATUS_READY, 3));

        $this->assertTrue($card['bad']);
        $this->assertStringContainsString('3', $card['reason']);
        $this->assertNotNull($card['action']);
    }

    /**
     * A working worker names the attempt it is playing.
     *
     * @return void
     */
    public function test_a_working_worker_names_its_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->make_run(registry::STATUS_RUNNING);
        $attemptid = $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $runid, 'personid' => 0, 'status' => attempt_scheduler::STATUS_RUNNING,
            'tries' => 1, 'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        worker_registry::acquire_slot(1, 'exec-1');
        worker_registry::report('exec-1', (int) $attemptid, 'working');

        $card = status_report::worker($DB->get_record('local_catquizlab_worker', ['workerid' => 'exec-1']));

        // The count "1 worker" was the whole message before this.
        $this->assertTrue($card['good']);
        $this->assertStringContainsString((string) $attemptid, $card['reason']);
        $this->assertStringContainsString((string) $runid, $card['reason']);
    }

    /**
     * An idle worker says why it is idle.
     *
     * @return void
     */
    public function test_an_idle_worker_says_why(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        worker_registry::acquire_slot(1, 'exec-2');
        worker_registry::report('exec-2', 0, 'idle');

        $card = status_report::worker($DB->get_record('local_catquizlab_worker', ['workerid' => 'exec-2']));

        // Idle is not a problem, and "idle" alone invites the question.
        $this->assertTrue($card['good']);
        $this->assertNotSame('', $card['reason']);
        $this->assertNull($card['action']);
    }

    /**
     * A queue of unclaimable attempts does not read as waiting.
     *
     * @return void
     */
    public function test_unclaimable_work_is_not_reported_as_waiting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->make_run(registry::STATUS_FAILED, 5);

        $card = status_report::queue();

        // Reported: "150 waiting" for attempts no worker would ever take.
        $this->assertTrue($card['bad']);
        $this->assertStringContainsString('5', $card['state']);
        $this->assertNotSame('', $card['reason']);
    }

    /**
     * A healthy component offers nothing to press.
     *
     * @return void
     */
    public function test_healthy_components_have_no_action(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $card = status_report::queue();

        // An action on a healthy state teaches people to press buttons that do
        // not need pressing.
        $this->assertTrue($card['good']);
        $this->assertNull($card['action']);
    }
}
