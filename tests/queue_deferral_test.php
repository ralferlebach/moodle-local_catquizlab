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

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\queue_deferral;

/**
 * A test run sets other queued work aside and gives each sitting its own time back (#120, audit 11.11.2026).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\queue_deferral
 */
final class queue_deferral_test extends \advanced_testcase {
    /**
     * A queued sitting of a run.
     *
     * @param int $runid The run.
     * @param int $nextruntime Its own next run time.
     * @param int $status Its status.
     * @return int Its id.
     */
    protected function sitting(int $runid, int $nextruntime, int $status = attempt_scheduler::STATUS_QUEUED): int {
        global $DB;
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $person = $generator->create_person(['runid' => $runid]);

        return (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $runid, 'personid' => $person->id, 'status' => $status, 'tries' => 1,
            'nextruntime' => $nextruntime, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Backoffs survive, the test's own experiment stays claimable, a sitting the scheduler touched keeps its new time.
     *
     * @return void
     */
    public function test_each_sitting_gets_its_own_time_back(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $own = $generator->create_run();
        $other = $generator->create_run();
        $experiment = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $own->id]);
        if ((int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $other->id]) === $experiment) {
            $DB->set_field('local_catquizlab_run', 'experimentid', $experiment + 1000, ['id' => $other->id]);
        }

        $backoff = time() + 600;
        $mine = $this->sitting((int) $own->id, 0);
        $ready = $this->sitting((int) $other->id, 0);
        $retry = $this->sitting((int) $other->id, $backoff);
        $touched = $this->sitting((int) $other->id, 0);
        $running = $this->sitting((int) $other->id, 0, attempt_scheduler::STATUS_RUNNING);

        $this->assertSame(3, queue_deferral::defer_others($experiment, HOURSECS));
        $later = (int) $DB->get_field('local_catquizlab_attempt', 'nextruntime', ['id' => $ready]);
        $this->assertGreaterThan(time() + 3000, $later);
        $this->assertSame($later, (int) $DB->get_field('local_catquizlab_attempt', 'nextruntime', ['id' => $retry]));
        $this->assertSame(0, (int) $DB->get_field('local_catquizlab_attempt', 'nextruntime', ['id' => $mine]));
        $this->assertSame(0, (int) $DB->get_field('local_catquizlab_attempt', 'nextruntime', ['id' => $running]));

        // Meanwhile the scheduler gives one of them a time of its own.
        $DB->set_field('local_catquizlab_attempt', 'nextruntime', $backoff + 77, ['id' => $touched]);

        $this->assertSame(2, queue_deferral::restore());
        $this->assertSame(0, (int) $DB->get_field('local_catquizlab_attempt', 'nextruntime', ['id' => $ready]));
        $this->assertSame(
            $backoff,
            (int) $DB->get_field('local_catquizlab_attempt', 'nextruntime', ['id' => $retry]),
            'a retry keeps its backoff'
        );
        $this->assertSame(
            $backoff + 77,
            (int) $DB->get_field('local_catquizlab_attempt', 'nextruntime', ['id' => $touched]),
            'what the scheduler set since stays'
        );
        $this->assertFalse(get_config('local_catquizlab', queue_deferral::CONFIG));
        // Nothing left to give back.
        $this->assertSame(0, queue_deferral::restore());
    }

    /**
     * A run that was killed leaves its record; the next run gives the time back before it defers anew.
     *
     * @return void
     */
    public function test_a_killed_run_is_put_right_by_the_next(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $own = $generator->create_run();
        $other = $generator->create_run();
        $experiment = (int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $own->id]);
        if ((int) $DB->get_field('local_catquizlab_run', 'experimentid', ['id' => $other->id]) === $experiment) {
            $DB->set_field('local_catquizlab_run', 'experimentid', $experiment + 1000, ['id' => $other->id]);
        }
        $backoff = time() + 900;
        $retry = $this->sitting((int) $other->id, $backoff);

        queue_deferral::defer_others($experiment, HOURSECS);
        // No restore: the process died. The next run starts.
        queue_deferral::defer_others($experiment, HOURSECS);
        queue_deferral::restore();
        $this->assertSame($backoff, (int) $DB->get_field('local_catquizlab_attempt', 'nextruntime', ['id' => $retry]));
    }
}
