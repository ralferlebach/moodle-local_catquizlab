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

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\eta;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\live_progress;
use local_catquizlab\local\progress_overview;
use local_catquizlab\local\question_progress;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\worker_registry;

/**
 * All experiments at once: the population, each experiment, each run (#158).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\progress_overview
 * @covers     \local_catquizlab\local\live_progress
 */
final class progress_overview_test extends \advanced_testcase {
    /**
     * A clean database.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        question_progress::reset_caches();
        $this->setAdminUser();
    }

    /**
     * An experiment with runs, each with sittings in the given states.
     *
     * @param string $name Its name.
     * @param int $runs How many runs.
     * @param int $status The runs' status.
     * @param array $sittings Status => count, per run.
     * @return array{id: int, runs: int[]}
     */
    protected function experiment(string $name, int $runs, int $status, array $sittings): array {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $id = (int) $generator->create_experiment(['name' => $name])->id;
        $runids = [];
        for ($r = 0; $r < $runs; $r++) {
            $runid = (int) $generator->create_run(['experimentid' => $id, 'status' => $status,
                'manifestjson' => json_encode(['config' => ['strategy' => ['key' => $r % 2 ? 'fastest' : 'classic']]])])->id;
            $runids[] = $runid;
            foreach ($sittings as $state => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $runid,
                        'personid' => 0, 'status' => $state, 'tries' => 1,
                        'leaseexpires' => $state === attempt_scheduler::STATUS_RUNNING ? time() + 3600 : 0,
                        'runtimems' => 30000, 'timecreated' => time(), 'timemodified' => time()]);
                    if ($state !== attempt_scheduler::STATUS_QUEUED) {
                        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid,
                            'runid' => $runid, 'tryno' => 1, 'outcome' => attempt_history::STARTED,
                            'timecreated' => time() - 60 - $i]);
                    }
                    if (in_array($state, [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_FAILED], true)) {
                        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid,
                            'runid' => $runid, 'tryno' => 1, 'outcome' => $state === attempt_scheduler::STATUS_FAILED
                                ? attempt_history::FAILED : attempt_history::COLLECTED,
                            'runtimems' => 30000, 'timecreated' => time() - 30 - $i]);
                    }
                }
            }
        }

        return ['id' => $id, 'runs' => $runids];
    }

    /**
     * A worker up and reporting.
     *
     * @return void
     */
    protected function worker(): void {
        global $DB;

        $DB->insert_record('local_catquizlab_worker', (object) ['workerid' => 'w1', 'slot' => 1,
            'status' => worker_registry::STATUS_RUNNING, 'heartbeat' => time(), 'timecreated' => time(),
            'timemodified' => time()]);
    }

    /**
     * DoD1: three experiments (not started, running with a paused and a held run, finished), 32 runs. Global,
     * experiment and run figures add up exactly; the finished one is there with its end; nothing cut at 25 runs.
     *
     * @return void
     */
    public function test_the_whole_population_adds_up(): void {
        $this->worker();
        $planned = $this->experiment('Beta planned', 2, registry::STATUS_DRAFT, [attempt_scheduler::STATUS_QUEUED => 4]);
        $running = $this->experiment('alpha running', 20, registry::STATUS_RUNNING, [
            attempt_scheduler::STATUS_COLLECTED => 3, attempt_scheduler::STATUS_RUNNING => 1,
            attempt_scheduler::STATUS_QUEUED => 5, attempt_scheduler::STATUS_FAILED => 1]);
        $finished = $this->experiment(
            'Gamma finished',
            10,
            registry::STATUS_FINISHED,
            [attempt_scheduler::STATUS_COLLECTED => 4]
        );
        run_lifecycle::set_paused($running['runs'][3], true, 'by hand');
        run_lifecycle::set_status($running['runs'][4], registry::STATUS_FAILED, 'test');

        $overview = progress_overview::build(0, 50);
        $totals = $overview['totals'];
        $this->assertSame(3, $totals['experiments']);
        $this->assertSame(32, $totals['runs'], 'every run, not the last 25');
        $this->assertSame(20 * 3 + 10 * 4, $totals['done']);
        $this->assertSame(2 * 4 + 20 * 10 + 10 * 4, $totals['total']);
        $this->assertSame(1, $totals['notstarted']);
        // Exact denominators: done / total, not the mean of the experiments' percentages.
        $this->assertSame((int) floor(100 * 100 / 248), $totals['percent']);

        // A–Z, case folded: alpha, Beta, Gamma.
        $this->assertSame(
            ['alpha running', 'Beta planned', 'Gamma finished'],
            array_column($overview['experiments'], 'name')
        );
        // Each experiment as its own page shows it, every run of it listed.
        foreach ($overview['experiments'] as $e) {
            $own = live_progress::snapshot($e['experimentid']);
            $this->assertSame($own['done'], $e['done']);
            $this->assertSame($own['total'], $e['total']);
            $this->assertSame($own['state'], $e['state']);
            $this->assertSame(array_column($own['runs'], 'runid'), array_column($e['runs'], 'runid'));
        }
        $byname = array_column($overview['experiments'], null, 'name');
        $this->assertCount(20, $byname['alpha running']['runs']);
        $this->assertSame(live_progress::BLOCKED, $byname['alpha running']['state'], 'a held run blocks it');
        $this->assertSame(live_progress::FINISHED, $byname['Gamma finished']['state']);
        $this->assertSame(eta::FINAL, $byname['Gamma finished']['time']['reliability']);
        $this->assertSame(100, $byname['Gamma finished']['percent']);
        $this->assertNotNull($byname['Gamma finished']['time']['finish'], 'the actual end');

        // The states counted: one of each of these three.
        $states = array_column($totals['states'], 'count', 'state');
        $this->assertSame(1, $states[live_progress::BLOCKED]);
        $this->assertSame(1, $states[live_progress::FINISHED]);
        // Planned, not started: waiting, and counted as not started.
        $this->assertSame(1, $states[live_progress::WAITING]);
        $this->assertSame(0, $states[live_progress::DRAFT], 'draft means no runs at all');
        // Blocked: no time for everything open, the reason said.
        $this->assertSame(eta::UNRELIABLE, $overview['time']['reliability']);
        $this->assertNotSame('', $overview['time']['reason']);
    }

    /**
     * DoD2: an experiment running and one waiting in the execution queue. The time of everything open is the
     * running one's forecast, then the waiting one; without a duration for the waiting one, no time, a lower bound.
     *
     * @return void
     */
    public function test_queued_experiments_come_after_the_running_one(): void {
        global $DB;

        $this->worker();
        $running = $this->experiment('Running', 2, registry::STATUS_RUNNING, [
            attempt_scheduler::STATUS_COLLECTED => 5, attempt_scheduler::STATUS_RUNNING => 1,
            attempt_scheduler::STATUS_QUEUED => 4]);
        $alone = progress_overview::build(0, 50)['time'];
        $this->assertContains($alone['reliability'], [eta::ESTIMATE, eta::PROVISIONAL]);
        $this->assertGreaterThan(0, $alone['remaining']);

        // A second experiment waits its turn, never started: no duration to estimate it from.
        $waiting = $this->experiment('Waiting', 2, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED => 10]);
        // Ready runs of an experiment waiting in line are not claimed yet: held by the execution queue here.
        foreach ($waiting['runs'] as $runid) {
            run_lifecycle::set_status($runid, registry::STATUS_DRAFT, 'waiting in line');
        }
        $DB->insert_record('local_catquizlab_execqueue', (object) ['experimentid' => $waiting['id'],
            'state' => execution_queue::WAITING, 'position' => 1, 'userid' => 2, 'timecreated' => time()]);
        $time = progress_overview::build(0, 50)['time'];
        $this->assertSame(eta::CALCULATING, $time['reliability']);
        $this->assertNull($time['remaining'], 'no invented time');
        $this->assertNotNull($time['lowerbound'], 'at least until the running one is done');
        $this->assertGreaterThanOrEqual(time() + $alone['remaining'] - 5, $time['lowerbound']);
        $this->assertStringContainsString(get_string('eta:whyqueuedunknown', 'local_catquizlab'), $time['reason']);

        // Once the waiting one has durations of its own (sittings it played before), it is added after the running
        // one: later than the running one alone, by its work over the capacity.
        foreach ($waiting['runs'] as $runid) {
            for ($i = 0; $i < 3; $i++) {
                $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => 900000 + $runid * 10 + $i,
                    'runid' => $runid, 'tryno' => 1, 'outcome' => attempt_history::COLLECTED, 'runtimems' => 30000,
                    'timecreated' => time() - DAYSECS]);
            }
        }
        $time = progress_overview::build(0, 50)['time'];
        $this->assertContains($time['reliability'], [eta::ESTIMATE, eta::PROVISIONAL]);
        $this->assertGreaterThan($alone['remaining'], $time['remaining'], 'the queued experiment comes after');
    }

    /**
     * DoD2: the time of everything is the latest finish, not an average; a paused experiment or no worker means no
     * countdown, only what is known as a lower bound; a finished experiment adds nothing; never negative.
     *
     * @return void
     */
    public function test_the_overall_time_is_the_latest_and_says_what_stops_it(): void {
        global $DB;

        $this->worker();
        // A long and a short experiment running side by side (eight and two sittings left), and a finished one.
        $long = $this->experiment('Long', 1, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_COLLECTED => 3,
            attempt_scheduler::STATUS_RUNNING => 1, attempt_scheduler::STATUS_QUEUED => 8]);
        $short = $this->experiment('Short', 1, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_COLLECTED => 3,
            attempt_scheduler::STATUS_RUNNING => 1, attempt_scheduler::STATUS_QUEUED => 1]);
        $this->experiment('Done', 1, registry::STATUS_FINISHED, [attempt_scheduler::STATUS_COLLECTED => 3]);
        $overview = progress_overview::build(0, 50);
        $finishes = [];
        foreach ($overview['experiments'] as $section) {
            if (in_array($section['experimentid'], [$long['id'], $short['id']], true)) {
                $finishes[] = (int) $section['time']['finish'];
            }
        }
        $this->assertCount(2, $finishes);
        $time = $overview['time'];
        $this->assertContains($time['reliability'], [eta::ESTIMATE, eta::PROVISIONAL]);
        $this->assertSame(max($finishes), $time['finish'], 'the latest finish');
        $this->assertNotSame((int) round(array_sum($finishes) / 2), $time['finish'], 'not an average');
        $this->assertGreaterThan(0, $time['remaining']);

        // The long one paused: no countdown for everything; at least until the short one is done.
        foreach ($long['runs'] as $runid) {
            run_lifecycle::set_paused($runid, true, 'test');
        }
        $build = progress_overview::build(0, 50);
        $paused = $build['time'];
        $this->assertSame(eta::UNRELIABLE, $paused['reliability']);
        $this->assertNull($paused['remaining']);
        $this->assertSame(get_string('eta:whypaused', 'local_catquizlab'), $paused['reason']);
        // The short one now has the workers to itself: its own forecast is what is known.
        $sections = array_column($build['experiments'], null, 'experimentid');
        $shortnow = $sections[$short['id']]['time']['finish'];
        $this->assertSame((int) $shortnow, (int) $paused['lowerbound']);
        foreach ($long['runs'] as $runid) {
            run_lifecycle::set_paused($runid, false, 'test');
        }

        // No worker and nothing in flight any more: no capacity, no countdown.
        $DB->delete_records('local_catquizlab_worker');
        $running = ['status' => attempt_scheduler::STATUS_RUNNING];
        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_QUEUED, $running);
        $stalled = progress_overview::build(0, 50)['time'];
        $this->assertNull($stalled['remaining']);
        $this->assertNotSame(eta::ESTIMATE, $stalled['reliability']);
        $this->assertNotSame('', $stalled['reason']);
    }

    /**
     * DoD4: the overview and the whole poll cost a fixed number of queries, nothing per run and nothing per
     * experiment.
     *
     * @return void
     */
    public function test_the_query_count_does_not_grow_with_runs(): void {
        global $DB;

        $this->worker();
        for ($i = 0; $i < 5; $i++) {
            $this->experiment('Small ' . $i, 2, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED => 2]);
        }
        // Once to fill Moodle's own caches (table metadata, plugin config), then counted.
        progress_overview::build(0, 50);
        \local_catquizlab\external\live_status::execute(0, 0);
        question_progress::reset_caches();
        $before = $DB->perf_get_reads();
        progress_overview::build(0, 50);
        $small = $DB->perf_get_reads() - $before;
        // The whole poll of "All experiments" too: it asked twice per run for the queue before (#160 AK12).
        question_progress::reset_caches();
        $before = $DB->perf_get_reads();
        \local_catquizlab\external\live_status::execute(0, 0);
        $smallpoll = $DB->perf_get_reads() - $before;

        // Five more experiments of twenty runs each: 110 runs instead of 10, the same queries.
        for ($i = 0; $i < 5; $i++) {
            $this->experiment('Large ' . $i, 20, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED => 2]);
        }
        question_progress::reset_caches();
        $before = $DB->perf_get_reads();
        progress_overview::build(0, 50);
        $large = $DB->perf_get_reads() - $before;

        $this->assertSame($small, $large, '10 or 110 runs, 5 or 10 experiments: the same queries');
        question_progress::reset_caches();
        $before = $DB->perf_get_reads();
        \local_catquizlab\external\live_status::execute(0, 0);
        $this->assertSame($smallpoll, $DB->perf_get_reads() - $before, 'the poll: the same queries');

        // The poll of one experiment: two runs or twenty, the same queries (the run cards read the site's queue
        // once, not once per run).
        $two = $this->experiment('Two', 2, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED => 2]);
        $twenty = $this->experiment('Twenty', 20, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED => 2]);
        \local_catquizlab\external\live_status::execute($two['id'], 0);
        $counted = [];
        foreach ([$two['id'], $twenty['id']] as $id) {
            question_progress::reset_caches();
            $before = $DB->perf_get_reads();
            \local_catquizlab\external\live_status::execute($id, 0);
            $counted[] = $DB->perf_get_reads() - $before;
        }
        $this->assertSame($counted[0], $counted[1], 'one experiment\'s poll: 2 or 20 runs, the same queries');
    }

    /**
     * Paging: the sections are paged, the totals are not.
     *
     * @return void
     */
    public function test_sections_are_paged_totals_are_not(): void {
        for ($i = 0; $i < 5; $i++) {
            $this->experiment('E' . $i, 1, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED => 1]);
        }
        $first = progress_overview::build(0, 2);
        $last = progress_overview::build(2, 2);
        $this->assertCount(2, $first['experiments']);
        $this->assertCount(1, $last['experiments']);
        $this->assertSame(3, $first['pages']);
        $this->assertSame(5, $first['totals']['experiments']);
        $this->assertSame($first['totals'], $last['totals']);
        $this->assertCount(3, $first['pager']['links']);
    }

    /**
     * Twelve processes busy elsewhere are not this experiment's (#160 AK-CLARA-15, DoD-CLARA-09; #163 AK-CLARA-12;
     * #158 AK-CLARA-03).
     *
     * The CLARA situation: an experiment stopped by its operator — six runs, 1018-like: some collected, the rest
     * cancelled, no claim — while twelve worker processes play two other experiments. Its progress says twelve processes
     * on the installation and none of its own sittings in progress, says so in words that keep the two apart, is not
     * running, gives no countdown and no 100 %; the two others count exactly the twelve sittings the processes hold.
     *
     * @return void
     */
    public function test_twelve_processes_elsewhere_are_not_this_experiments(): void {
        global $CFG, $DB;

        $stopped = $this->experiment('Stopped', 6, registry::STATUS_CANCELLED, [
            attempt_scheduler::STATUS_COLLECTED => 2, attempt_scheduler::STATUS_CANCELLED => 3]);
        $first = $this->experiment('First', 2, registry::STATUS_RUNNING, [
            attempt_scheduler::STATUS_RUNNING => 3, attempt_scheduler::STATUS_QUEUED => 5]);
        $second = $this->experiment('Second', 1, registry::STATUS_RUNNING, [
            attempt_scheduler::STATUS_RUNNING => 6, attempt_scheduler::STATUS_QUEUED => 4]);
        // Twelve processes, each holding one sitting of the two running experiments.
        $held = $DB->get_fieldset_select('local_catquizlab_attempt', 'id', 'status = ?', [attempt_scheduler::STATUS_RUNNING]);
        $this->assertCount(12, $held);
        foreach (array_values($held) as $i => $attemptid) {
            $DB->insert_record('local_catquizlab_worker', (object) ['workerid' => 'w' . ($i + 1), 'slot' => $i + 1,
                'status' => worker_registry::STATUS_RUNNING, 'heartbeat' => time(), 'currentattempt' => (int) $attemptid,
                'timecreated' => time(), 'timemodified' => time()]);
        }

        $own = live_progress::snapshot($stopped['id']);
        $this->assertSame(12, $own['now']['workers'], 'the installation\'s processes');
        $this->assertSame(0, $own['now']['inflight'], 'none of them on this experiment');
        $this->assertSame([12, 18, 0], [$own['now']['collected'], $own['now']['cancelled'], $own['now']['queued']]);
        $this->assertNotSame(live_progress::RUNNING, $own['state'], 'a stopped experiment is not running');
        // Stopped, not finished: the label "All experiments" shows (CLARA: it said "Finished" for 1018 of 6000).
        $this->assertSame(live_progress::STOPPED, $own['state']);
        $this->assertSame(get_string('progress:statestopped', 'local_catquizlab'), $own['label']);
        $this->assertLessThan(100, $own['percent'], 'cancelled is not done: no 100 %');
        foreach ($own['time']['runs'] as $run) {
            $this->assertNotSame(eta::ESTIMATE, $run['reliability'], 'no countdown for run ' . $run['runid']);
        }

        // The two running ones count exactly the sittings the twelve processes hold.
        $inflight = 0;
        foreach ([$first['id'], $second['id']] as $experimentid) {
            $snapshot = live_progress::snapshot($experimentid);
            $this->assertSame(12, $snapshot['now']['workers']);
            $inflight += $snapshot['now']['inflight'];
        }
        $this->assertSame(12, $inflight, 'the twelve are the two others\' sittings');

        // The words keep them apart: the processes are the installation's, the sittings this experiment's — in both
        // languages, read from the plugin's own files (a test site has no German language pack installed).
        $words = [
            'en' => ['on this installation, for every experiment', 'of this experiment in progress'],
            'de' => ['auf dieser Installation aktiv, für alle Experimente', 'dieses Experiments in Bearbeitung'],
        ];
        foreach ($words as $lang => [$workers, $sittings]) {
            $string = [];
            include($CFG->dirroot . '/local/catquizlab/lang/' . $lang . '/local_catquizlab.php');
            $this->assertStringContainsString($workers, $string['progress:nowworkers'], $lang);
            $this->assertStringContainsString($sittings, $string['progress:nowinflight'], $lang);
        }
    }
}
