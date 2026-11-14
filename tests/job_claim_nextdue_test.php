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

use local_catquizlab\external\job_claim;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;

/**
 * An empty claim says when the next queued sitting is due (audit 11.11.2026).
 *
 * In the load matrix a sitting waited out a retry's backoff after every worker had left the queue as empty, and sat
 * there until the test's time limit. The claim now tells the worker how long that is, and the worker waits.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\external\job_claim
 */
final class job_claim_nextdue_test extends \advanced_testcase {
    /**
     * Nothing queued: 0. A sitting in its backoff: the seconds until it is due. Due again: it is handed out.
     *
     * @return void
     */
    public function test_an_empty_claim_says_when_the_next_sitting_is_due(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $none = job_claim::execute('worker-a');
        $this->assertFalse($none['hasjob']);
        $this->assertSame(0, $none['nextdue']);

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_READY, ['id' => $run->id]);
        $person = $generator->create_person(['runid' => $run->id]);
        $id = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_QUEUED, 'tries' => 1,
            'nextruntime' => time() + 120, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $wait = job_claim::execute('worker-a');
        $this->assertFalse($wait['hasjob']);
        $this->assertGreaterThan(100, $wait['nextdue']);
        $this->assertLessThanOrEqual(120, $wait['nextdue']);

        // A sitting of a run that hands out no work is not waited for.
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FAILED, ['id' => $run->id]);
        $this->assertSame(0, job_claim::execute('worker-a')['nextdue']);
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_READY, ['id' => $run->id]);

        $DB->set_field('local_catquizlab_attempt', 'nextruntime', time() - 1, ['id' => $id]);
        $claim = job_claim::execute('worker-a');
        $this->assertTrue($claim['hasjob']);
        $this->assertSame($id, (int) $claim['attemptid']);
    }
}
