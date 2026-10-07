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

use local_catquizlab\local\worker_launcher;

/**
 * "Start workers" says what it did, in a sentence, and when it goes on by itself.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\worker_launcher
 */
final class worker_start_feedback_test extends \advanced_testcase {
    /**
     * Every outcome of a launch is a sentence, none a code.
     *
     * @return void
     */
    public function test_every_outcome_is_said(): void {
        $this->resetAfterTest();
        // Ahead of now, whatever the time of day: a tick in the past is shown as now.
        $tick = time() + 300;

        $said = worker_launcher::explain(['launched' => 2, 'reason' => ''], $tick);
        $this->assertSame(\core\output\notification::NOTIFY_SUCCESS, $said['type']);
        $this->assertStringContainsString('Started 2 worker(s) now', $said['message']);

        $said = worker_launcher::explain(['launched' => 0, 'reason' => 'not-configured: worker_node_path, worker_token'], $tick);
        $this->assertSame(\core\output\notification::NOTIFY_ERROR, $said['type']);
        $this->assertStringContainsString('incomplete', $said['message']);
        $this->assertStringNotContainsString('worker_node_path', $said['message'], 'the setting\'s label, not its name');

        $said = worker_launcher::explain(['launched' => 0, 'reason' => 'all-slots-busy'], $tick);
        $this->assertStringContainsString('already running', $said['message']);

        $said = worker_launcher::explain(['launched' => 0, 'reason' => 'no-claimable-work'], $tick);
        $this->assertStringContainsString('no sitting left to play', $said['message']);

        $said = worker_launcher::explain(['launched' => 0, 'reason' => 'no-handshake',
            'failures' => [['reason' => 'timeout', 'output' => 'Error: Cannot find module playwright']]], $tick);
        $this->assertStringContainsString('did not report back', $said['message']);
        $this->assertStringContainsString('Cannot find module playwright', $said['message']);
        $this->assertStringContainsString(userdate($tick, get_string('strftimetime')), $said['message'], 'when it goes on');

        foreach (['no-claimable-work', 'all-slots-busy', 'no-handshake'] as $code) {
            $this->assertStringNotContainsString($code, worker_launcher::explain(
                ['launched' => 0, 'reason' => $code],
                $tick
            )['message']);
        }
    }

    /**
     * Nothing to play now, but sittings waiting for a retry: when, and when the scheduler looks again.
     *
     * @return void
     */
    public function test_waiting_work_says_when(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id]);
        $due = time() + 600;
        $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id, 'personid' => $person->id,
            'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED, 'tries' => 1,
            'nextruntime' => $due, 'timecreated' => time(), 'timemodified' => time()]);
        $tick = time() + 120;

        $message = worker_launcher::explain(['launched' => 0, 'reason' => 'no-claimable-work'], $tick)['message'];
        $this->assertStringContainsString('no sitting can be played right now', $message);
        $this->assertStringContainsString(userdate($tick, get_string('strftimetime')), $message);
    }

    /**
     * The button on the run card and in the situation is the action, and comes back.
     *
     * @return void
     */
    public function test_the_buttons_start_and_come_back(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/local/catquizlab/index.php', ['tab' => 'progress', 'experimentid' => 12]));

        $url = worker_launcher::start_url();
        $this->assertStringEndsWith('/local/catquizlab/operations.php', $url->out_omit_querystring());
        $this->assertSame('startworkers', $url->get_param('action'));
        $this->assertSame(sesskey(), $url->get_param('sesskey'));
        $this->assertStringContainsString('tab=progress', $url->get_param('returnurl'));
    }
}
