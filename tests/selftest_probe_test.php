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

use local_catquizlab\external\sitting_state;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;
use local_catquizlab\local\selftest;

/**
 * The self-test asks the web service with the worker's token and takes no work (0.7.66).
 *
 * Until 0.7.65 the check "Web service answers the stored token" was a real job_claim as worker "selftest". Where a
 * sitting was waiting it took it, held it until its lease lapsed and queued it for a retry; since the one rule for the
 * execution line (#163) its experiment held the line meanwhile. In the Interface E2E (2026-11-16) the setup's
 * self-test took the one sitting of a prepared experiment, and the experiment created through the interface waited
 * sixteen minutes for its turn.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\selftest
 * @covers     \local_catquizlab\external\sitting_state
 */
final class selftest_probe_test extends \advanced_testcase {
    /**
     * The function the check calls is a read, needs the worker's capability and is in the worker's service: what a
     * claim needs, without the claim.
     *
     * @return void
     */
    public function test_the_check_calls_a_read_in_the_worker_service(): void {
        global $CFG;
        $functions = [];
        $services = [];
        require($CFG->dirroot . '/local/catquizlab/db/services.php');

        $request = selftest::probe_request('t0ken');
        $this->assertSame(selftest::PROBE_FUNCTION, $request['wsfunction']);
        $this->assertSame('t0ken', $request['wstoken']);
        $this->assertNotSame('local_catquizlab_job_claim', $request['wsfunction']);
        $this->assertArrayNotHasKey('workerid', $request, 'no worker identity: nothing to claim, nothing registered');

        $function = $functions[selftest::PROBE_FUNCTION];
        $this->assertSame('read', $function['type']);
        $this->assertSame('local/catquizlab:worker', $function['capabilities']);
        $this->assertSame(
            $functions['local_catquizlab_job_claim']['capabilities'],
            $function['capabilities'],
            'the same capability as the claim the worker makes'
        );
        $worker = array_values(array_filter(
            $services,
            static fn(array $s): bool => ($s['shortname'] ?? '') === 'local_catquizlab_worker'
        ));
        $this->assertCount(1, $worker);
        $this->assertContains(selftest::PROBE_FUNCTION, $worker[0]['functions']);
        $this->assertContains('local_catquizlab_job_claim', $worker[0]['functions']);
    }

    /**
     * Answered with the check's own request, while an experiment waits with a sitting a worker could take: the answer
     * has the field the check looks for, and nothing moved: the sitting still waits, no lease, no history, nobody in the
     * execution line.
     *
     * @return void
     */
    public function test_the_answer_takes_nothing_and_moves_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment();
        $run = $generator->create_run(['experimentid' => $experiment->id, 'status' => registry::STATUS_READY]);
        $sitting = $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => 900001, 'status' => attempt_scheduler::STATUS_QUEUED, 'tries' => 0,
            'nextruntime' => 0, 'leaseowner' => null, 'leaseexpires' => 0, 'lasterror' => null,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $before = $DB->get_record('local_catquizlab_attempt', ['id' => $sitting]);

        $request = selftest::probe_request('t0ken');
        $answer = sitting_state::execute((int) $request['attemptid']);

        $this->assertArrayHasKey('found', $answer, 'the field the check takes as "this is the worker\'s service"');
        $this->assertFalse($answer['found']);
        $this->assertSame('sitting-not-found', $answer['reason']);
        $this->assertEquals($before, $DB->get_record('local_catquizlab_attempt', ['id' => $sitting]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_attemptlog', ['attemptid' => $sitting]));
        $this->assertSame(0, $DB->count_records('local_catquizlab_execqueue'));
    }
}
