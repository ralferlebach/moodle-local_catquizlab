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
 * The overview says one thing, not four correct things that disagree.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\registry;
use local_catquizlab\local\situation;
use local_catquizlab\local\worker_registry;

/**
 * Situation tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\situation
 */
final class situation_test extends \advanced_testcase {
    /**
     * A set of facts with everything healthy, for the ranking tests.
     *
     * @param array $overrides What this case is actually about.
     * @return array
     */
    protected function facts(array $overrides = []): array {
        return $overrides + [
            'workers'    => ['live' => 1, 'crashed' => 0, 'stopped' => 0, 'jobsdone' => 0, 'lasterror' => null],
            'queued'     => 0,
            'running'    => 0,
            'failedruns' => 0,
            'wizard'     => ['ready' => true, 'stages' => [], 'blockers' => []],
        ];
    }

    /**
     * Queue up attempts on a run.
     *
     * @param int $count How many.
     * @param int $status Their status.
     * @return int The run id.
     */
    protected function queue_attempts(int $count, int $status = attempt_scheduler::STATUS_QUEUED): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;

        for ($i = 0; $i < $count; $i++) {
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $runid, 'personid' => 0, 'status' => $status, 'tries' => 0,
                'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        return $runid;
    }

    /**
     * The reported combination resolves to one actionable sentence.
     *
     * @return void
     */
    public function test_waiting_work_without_a_worker_is_the_headline(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Reported: 150 queued, 0 live workers, 1 crashed, experiment
        // "running", run "scheduled", progress 0%. Every figure correct, and
        // together no picture at all.
        $verdict = situation::rank($this->facts([
            'queued'  => 3,
            'workers' => ['live' => 0, 'crashed' => 1, 'stopped' => 0, 'jobsdone' => 0, 'lasterror' => null],
        ]));

        $this->assertSame(situation::STALLED, $verdict['state']);
        $this->assertTrue($verdict['problem']);
        // And what to do about it, not only that something is wrong.
        $this->assertNotNull($verdict['action']);
        $this->assertStringContainsString('3', $verdict['headline']);
        $this->assertStringContainsString('1', $verdict['detail']);
    }

    /**
     * Work in progress reads as work in progress.
     *
     * @return void
     */
    public function test_work_with_a_worker_is_not_a_problem(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $verdict = situation::rank($this->facts(['queued' => 2, 'running' => 1]));

        $this->assertSame(situation::WORKING, $verdict['state']);
        $this->assertTrue($verdict['ok']);
        // Nothing to press: an action button on a healthy state trains people
        // to press buttons that do not need pressing.
        $this->assertNull($verdict['action']);
    }

    /**
     * A stalled queue outranks a failed run.
     *
     * @return void
     */
    public function test_stalled_work_outranks_failed_runs(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Both are true. Work nobody is doing is the one somebody is waiting
        // on, so an overview that reports both makes the reader rank them.
        $verdict = situation::rank($this->facts([
            'queued'     => 5,
            'failedruns' => 1,
            'workers'    => ['live' => 0, 'crashed' => 0, 'stopped' => 0, 'jobsdone' => 0, 'lasterror' => null],
        ]));

        $this->assertSame(situation::STALLED, $verdict['state']);
    }

    /**
     * With an empty queue, failed runs become the headline.
     *
     * @return void
     */
    public function test_failed_runs_surface_once_the_queue_is_empty(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $verdict = situation::rank($this->facts(['failedruns' => 1]));

        $this->assertSame(situation::FAILING, $verdict['state']);
        $this->assertTrue($verdict['warn']);
        $this->assertNotNull($verdict['action']);
    }

    /**
     * Every verdict says something; none is an empty box.
     *
     * @return void
     */
    public function test_every_verdict_has_a_headline(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Through assess(), which reads the real installation: the ranking is
        // covered above, and this checks that what it is handed adds up.
        $verdict = situation::assess();

        $this->assertNotSame('', $verdict['headline']);
        $this->assertContains($verdict['state'], [
            situation::WORKING, situation::IDLE, situation::STALLED,
            situation::NOTREADY, situation::FAILING,
        ]);
        // Exactly one of the three flags, or the colour is undecided.
        $flags = (int) $verdict['ok'] + (int) $verdict['warn'] + (int) $verdict['problem'];
        $this->assertSame(1, $flags);
    }

    /**
     * An installation that cannot run anything says that first.
     *
     * @return void
     */
    public function test_not_ready_outranks_a_failed_run(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // A failed run on an installation that is not set up is very likely a
        // consequence of it, and reporting the consequence sends the reader
        // after the wrong thing.
        $verdict = situation::rank($this->facts([
            'failedruns' => 2,
            'wizard'     => ['ready' => false, 'stages' => [], 'blockers' => ['No worker token']],
        ]));

        $this->assertSame(situation::NOTREADY, $verdict['state']);
        $this->assertStringContainsString('token', $verdict['detail']);
    }

    /**
     * Waiting work outranks an unfinished setup.
     *
     * @return void
     */
    public function test_waiting_work_outranks_an_unfinished_setup(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Attempts in the queue mean the installation ran at some point, so the
        // setup warning is stale and the stalled queue is the live problem.
        $verdict = situation::rank($this->facts([
            'queued'  => 4,
            'workers' => ['live' => 0, 'crashed' => 0, 'stopped' => 0, 'jobsdone' => 0, 'lasterror' => null],
            'wizard'  => ['ready' => false, 'stages' => [], 'blockers' => ['Cron has run recently']],
        ]));

        $this->assertSame(situation::STALLED, $verdict['state']);
    }

    /**
     * The tabs are named and ordered by the work they belong to.
     *
     * @return void
     */
    public function test_the_tabs_follow_the_process(): void {
        global $CFG;
        $this->resetAfterTest();

        $source = file_get_contents($CFG->dirroot . '/local/catquizlab/index.php');

        // Set up, define, watch, evaluate — a first-time user should be able to
        // follow the numbers rather than know which page holds which object.
        $this->assertMatchesRegularExpression(
            "/\['setup', 'experiments', 'results', 'settings'\]/",
            $source,
            'The tab order no longer follows the process.'
        );

        foreach (['tab:setup', 'tab:experiments', 'tab:results', 'tab:settings'] as $key) {
            $this->assertNotEmpty(get_string($key, 'local_catquizlab'));
        }
    }
}
