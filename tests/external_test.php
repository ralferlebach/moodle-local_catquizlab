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
        // A second person: two sittings of the same person are deliberately
        // never handed out at once, which is what this test would otherwise
        // trip over while testing queue order.
        $secondperson = $generator->create_person(['runid' => $run->id]);
        $second = $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $secondperson->id,
            'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED,
            'timecreated' => $now + 1, 'timemodified' => $now + 1,
        ]);

        // Oldest first.
        // A run only hands out work while it is ready or running: a failed run
        // with claimable attempts is the defect this guards against.
        $DB->set_field('local_catquizlab_run', 'status', \local_catquizlab\local\registry::STATUS_READY, []);

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

    /**
     * Ten terminal failures reported through the worker's own path trip the breaker.
     *
     * @return void
     */
    public function test_ten_failures_through_job_complete_hold_the_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id]);
        $runid = (int) $run->id;
        $DB->set_field('local_catquizlab_run', 'status', \local_catquizlab\local\registry::STATUS_RUNNING, ['id' => $runid]);

        // Ten sittings that will fail for good, and five that will wait.
        $failing = [];
        for ($i = 0; $i < 10; $i++) {
            $failing[] = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => $person->id,
                'status' => \local_catquizlab\local\attempt_scheduler::STATUS_RUNNING,
                // Claimed for the last time: the claim counts the try, so this
                // is the third, and its failure is terminal.
                'tries' => \local_catquizlab\local\attempt_scheduler::MAX_TRIES,
                'leaseowner' => 'w', 'leaseexpires' => time() + 600,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => $person->id,
                'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED,
                'tries' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        // Reported exactly as the worker reports them: through job_complete,
        // which is the path that fires in practice. The breaker used to be
        // wired only to the scheduler's retry path, and never tripped here.
        foreach ($failing as $index => $attemptid) {
            job_complete::execute($attemptid, 'failed', 0, 0, 'Division by zero at attempt ' . $index);

            if ($index < 9) {
                $this->assertNotSame(
                    \local_catquizlab\local\registry::STATUS_FAILED,
                    (int) $DB->get_field('local_catquizlab_run', 'status', ['id' => $runid]),
                    'held after only ' . ($index + 1) . ' failures'
                );
            }
        }

        $held = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        $this->assertSame(\local_catquizlab\local\registry::STATUS_FAILED, (int) $held->status);
        $this->assertStringContainsString('Division by zero', (string) $held->lasterror);

        // The five waiting sittings stay queued and out of reach.
        $this->assertSame(5, $DB->count_records('local_catquizlab_attempt', [
            'runid' => $runid, 'status' => \local_catquizlab\local\attempt_scheduler::STATUS_QUEUED,
        ]));
        $this->assertFalse(job_claim::execute('worker-b')['hasjob']);

        // Ten reports of one fault read as one fault.
        $causes = \local_catquizlab\local\circuit_breaker::causes($runid);
        $this->assertCount(1, $causes);
        $this->assertSame(10, $causes[0]['count']);

        $experimentid = (int) $held->experimentid;
        $this->assertSame(
            \local_catquizlab\local\experiment_runner::STATE_BLOCKED,
            \local_catquizlab\local\experiment_runner::state($experimentid)['state']
        );
    }

    /**
     * The engine dry run returns a question for a sound run and a located
     * failure for a broken one.
     *
     * @return void
     */
    public function test_the_dry_run_locates_an_engine_failure(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        // No test instance, no people: the dry run says so rather than
        // throwing, because "cannot even try" is a different answer from
        // "tried and failed".
        $verdict = \local_catquizlab\local\engine_dryrun::first_question((int) $run->id);
        $this->assertFalse($verdict['ok']);
        $this->assertContains($verdict['reason'], ['no-test-instance', 'no-simulated-person', 'engine-missing']);
        $this->assertNull($verdict['exception']);
    }
}
