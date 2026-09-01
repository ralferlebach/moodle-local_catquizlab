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
 * Tests for the web-service external functions.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\external\oracle_answer;
use local_catquizlab\external\job_claim;
use local_catquizlab\external\job_complete;
use local_catquizlab\external\hub_submit_run;
use local_catquizlab\external\hub_fetch_results;

/**
 * External function tests: each endpoint authenticates, validates and returns
 * a well-formed stub response.
 *
 * @covers \local_catquizlab\external\oracle_answer
 * @covers \local_catquizlab\external\job_claim
 * @covers \local_catquizlab\external\job_complete
 * @covers \local_catquizlab\external\hub_submit_run
 * @covers \local_catquizlab\external\hub_fetch_results
 */
final class external_test extends \advanced_testcase {
    /**
     * The oracle returns a well-formed not-ready response when the engine or the
     * bound test is unavailable (as in CI); the assertion holds regardless of the
     * engine because run 1 does not exist here.
     *
     * @return void
     */
    public function test_oracle_answer_stub(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = oracle_answer::execute(1, 2);

        $this->assertFalse($result['ready']);
        $this->assertSame(-1, $result['choice']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * job_claim hands out queued attempts oldest-first and marks them running;
     * job_complete records the outcome on the attempt.
     *
     * @return void
     */
    public function test_job_queue(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id]);
        $now = time();
        $first = $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id,
            'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $second = $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id,
            'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED,
            'timecreated' => $now + 1, 'timemodified' => $now + 1,
        ]);

        // Oldest first.
        $claim = job_claim::execute('worker-a');
        $this->assertTrue($claim['hasjob']);
        $this->assertSame((int) $first, $claim['attemptid']);
        $this->assertSame(
            \local_catquizlab\local\attempt_scheduler::STATUS_RUNNING,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $first])
        );

        // Second claim gets the next; a third finds nothing.
        $this->assertSame((int) $second, job_claim::execute('worker-a')['attemptid']);
        $this->assertFalse(job_claim::execute('worker-a')['hasjob']);

        // Completing records the outcome. The engine attempt id is part of the
        // report: a finished attempt has an engine attempt behind it, and
        // without one there is nothing to collect a trace from.
        $complete = job_complete::execute($first, 'finished', 1234, 4711);
        $this->assertTrue($complete['acknowledged']);
        $this->assertSame(
            \local_catquizlab\local\attempt_scheduler::STATUS_COLLECTED,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $first])
        );
        $this->assertSame(1234, (int) $DB->get_field('local_catquizlab_attempt', 'runtimems', ['id' => $first]));

        // A failure requeues the attempt while retries remain (it was claimed once);
        // an unknown id is rejected.
        job_complete::execute($second, 'failed', 0, 0);
        $this->assertSame(
            \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED,
            (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $second])
        );
        $this->assertFalse(job_complete::execute(0, 'finished', 0, 0)['acknowledged']);
    }

    /**
     * The hub submit endpoint verifies the payload hash and reports not-stored.
     *
     * @return void
     */
    public function test_hub_submit_verifies_hash(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // A well-formed (if empty) run package; the hub verifies and ingests it.
        $payload = json_encode([
            'version'  => 1,
            'run'      => [
                'cellkey' => 'cell-hub', 'seed' => 1, 'replication' => 1,
                'status' => 20, 'manifestjson' => null,
            ],
            'persons'  => [],
            'attempts' => [],
            'results'  => [],
        ]);

        $good = hub_submit_run::execute($payload, hash('sha256', $payload));
        $this->assertTrue($good['verified']);
        $this->assertTrue($good['accepted']);

        $bad = hub_submit_run::execute($payload, hash('sha256', 'tampered'));
        $this->assertFalse($bad['verified']);
        $this->assertFalse($bad['accepted']);
    }

    /**
     * The hub fetch endpoint reports no results yet.
     *
     * @return void
     */
    public function test_hub_fetch_stub(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = hub_fetch_results::execute('run-ref-1');
        $this->assertFalse($result['available']);
        $this->assertSame('', $result['resultsjson']);
    }

    /**
     * Without the worker capability the oracle is refused.
     *
     * @return void
     */
    public function test_oracle_requires_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        oracle_answer::execute(1, 2);
    }
}
