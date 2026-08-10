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
     * The job queue endpoints acknowledge without handing out work yet.
     *
     * @return void
     */
    public function test_job_queue_stub(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $claim = job_claim::execute('worker-a');
        $this->assertFalse($claim['hasjob']);

        $complete = job_complete::execute(1, 'finished', 1234);
        $this->assertTrue($complete['acknowledged']);
    }

    /**
     * The hub submit endpoint verifies the payload hash and reports not-stored.
     *
     * @return void
     */
    public function test_hub_submit_verifies_hash(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $payload = json_encode(['run' => 1, 'traces' => []]);

        $good = hub_submit_run::execute($payload, hash('sha256', $payload));
        $this->assertTrue($good['verified']);
        $this->assertFalse($good['accepted']);

        $bad = hub_submit_run::execute($payload, hash('sha256', 'tampered'));
        $this->assertFalse($bad['verified']);
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
