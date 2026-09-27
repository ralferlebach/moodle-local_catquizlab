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
use local_catquizlab\external\job_complete;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;

/**
 * A sitting that fails, is diagnosed, retried and completed (#98).
 *
 * Driven through the same web services the worker calls, in the order it
 * calls them.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\attempt_history
 * @covers     \local_catquizlab\local\run_lifecycle
 */
final class attempt_recovery_test extends \advanced_testcase {
    /** @var string A failure as the worker reports it, taken from a live installation. */
    const FAILURE = 'Attempt did not reach the finish page after 1 answer(s). '
        . 'url=https://example.org/mod/adaptivequiz/attempt.php?cmid=12&sesskey=abc123 '
        . 'title="Fehler | Client01" error="Fehler: Division by zero" '
        . 'errorcode="Weitere Informationen über diesen Fehler" '
        . '| server replay: DivisionByZeroError at local/catquiz/classes/local/model/model_raschmodel.php:734';

    /**
     * A run of three sittings, one of which will fail.
     *
     * @return array [runid, attempt ids]
     */
    protected function run_of_three(): array {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_READY, ['id' => $run->id]);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $person = $generator->create_person(['runid' => $run->id]);
            $ids[] = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $person->id,
                'status' => attempt_scheduler::STATUS_QUEUED, 'tries' => 0,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        return [(int) $run->id, $ids];
    }

    /**
     * Failure, diagnosis, retry, success — with the history intact.
     *
     * @return void
     */
    public function test_a_failed_sitting_is_diagnosed_retried_and_completed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$runid, $ids] = $this->run_of_three();

        // Two sittings play through; the third fails on its last try.
        foreach ([0, 1] as $index) {
            $claim = job_claim::execute('worker-a');
            job_complete::execute((int) $claim['attemptid'], 'finished', 5000, 900 + $index);
        }
        $claim = job_claim::execute('worker-b');
        $failing = (int) $claim['attemptid'];
        $DB->set_field('local_catquizlab_attempt', 'tries', attempt_scheduler::MAX_TRIES, ['id' => $failing]);
        job_complete::execute($failing, 'failed', 0, 0, self::FAILURE);

        $status = (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $failing]);
        $this->assertSame(attempt_scheduler::STATUS_FAILED, $status);
        $this->assertFalse(run_lifecycle::is_complete($runid), 'a run with a failed sitting is complete');

        // Diagnosis: the failure in fields, without the session key.
        $failure = null;
        foreach (attempt_history::of_attempt($failing) as $row) {
            if ($row['outcome'] === attempt_history::FAILED) {
                $failure = $row;
            }
        }
        $this->assertNotNull($failure);
        $this->assertSame('answering', $failure['diagnosis']['phase']);
        $this->assertSame(2, $failure['diagnosis']['slot']);
        $this->assertSame('DivisionByZeroError', $failure['diagnosis']['exception']);
        $this->assertSame(734, $failure['diagnosis']['line']);
        $this->assertStringNotContainsString('sesskey', $failure['diagnosis']['url']);
        $this->assertArrayNotHasKey('errorcode', $failure['diagnosis'], 'link text taken for an error code');
        $this->assertSame('worker-b', $failure['workerid']);

        // Retry of every incomplete sitting: the failed one goes back, the two
        // collected ones are not touched.
        $collected = static function () use ($DB, $runid): array {
            return $DB->get_records_select(
                'local_catquizlab_attempt',
                'runid = ? AND status = ?',
                [$runid, attempt_scheduler::STATUS_COLLECTED],
                'id',
                'id, timemodified, engineattemptid'
            );
        };
        $collectedbefore = $collected();
        $done = run_lifecycle::requeue_incomplete($runid);
        $this->assertSame(1, $done['requeued']);
        $this->assertEquals($collectedbefore, $collected());

        // Success on the retry.
        $claim = job_claim::execute('worker-c');
        $this->assertSame($failing, (int) $claim['attemptid']);
        job_complete::execute($failing, 'finished', 5000, 999);

        $this->assertTrue(run_lifecycle::is_complete($runid), 'every planned sitting collected, yet not complete');

        // And the history still has the failure it had before the retry.
        $outcomes = array_column(attempt_history::of_attempt($failing), 'outcome');
        $this->assertContains(attempt_history::FAILED, $outcomes);
        $this->assertContains(attempt_history::REQUEUED, $outcomes);
        $this->assertSame(attempt_history::COLLECTED, end($outcomes));
    }

    /**
     * A sitting that fails the same way after every retry is not retried forever.
     *
     * @return void
     */
    public function test_a_deterministic_failure_stops_being_requeued(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$runid, $ids] = $this->run_of_three();
        $stubborn = $ids[0];

        for ($cycle = 1; $cycle <= run_lifecycle::MAX_FUTILE_RETRIES + 1; $cycle++) {
            $DB->update_record('local_catquizlab_attempt', (object) [
                'id' => $stubborn, 'status' => attempt_scheduler::STATUS_FAILED,
                'tries' => attempt_scheduler::MAX_TRIES, 'lasterror' => self::FAILURE,
            ]);
            attempt_history::record($stubborn, attempt_history::FAILED, ['detail' => self::FAILURE]);

            $done = run_lifecycle::requeue_incomplete($runid);

            if ($cycle <= run_lifecycle::MAX_FUTILE_RETRIES) {
                $this->assertSame(1, $done['requeued'], 'cycle ' . $cycle . ' was not retried');
            } else {
                // Three retries, three identical failures: a fourth changes
                // nothing, and a bulk action must not loop on it.
                $this->assertSame(0, $done['requeued']);
                $this->assertSame(1, $done['skipped']);
                $status = (int) $DB->get_field('local_catquizlab_attempt', 'status', ['id' => $stubborn]);
                $this->assertSame(attempt_scheduler::STATUS_FAILED, $status);
            }
        }
    }

    /**
     * Complete means collected, none failed, none open — nothing less.
     *
     * @return void
     */
    public function test_complete_is_one_rule(): void {
        global $DB;
        $this->resetAfterTest();

        [$runid, $ids] = $this->run_of_three();
        $this->assertFalse(run_lifecycle::is_complete($runid), 'nothing collected yet');

        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_COLLECTED, ['id' => $ids[0]]);
        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_COLLECTED, ['id' => $ids[1]]);
        $this->assertFalse(run_lifecycle::is_complete($runid), 'one still open');

        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_FAILED, ['id' => $ids[2]]);
        $this->assertFalse(run_lifecycle::is_complete($runid), 'one failed');

        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_COLLECTED, ['id' => $ids[2]]);
        $this->assertTrue(run_lifecycle::is_complete($runid));
    }
}
