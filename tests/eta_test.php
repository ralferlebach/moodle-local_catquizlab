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
use local_catquizlab\local\live_progress;
use local_catquizlab\local\question_progress;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_figures;
use local_catquizlab\local\run_lifecycle;
use local_catquizlab\local\worker_registry;

/**
 * A run's own remaining work and when it will be done, on a fake clock (#125, #142, #160).
 *
 * The world is real rows: runs, sittings, the execution history, engine attempts and workers, read the way the
 * progress page reads them. The fixture of #160 is the screenshot of 14.11.2026: runs #84–#88, #86 at 997 of
 * 1000 sittings and 24 910 of about 24 985 questions, shown with 2 h 35 min remaining.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\eta
 * @covers     \local_catquizlab\local\run_figures
 * @covers     \local_catquizlab\local\live_progress
 */
final class eta_test extends \advanced_testcase {
    /** @var int The fake clock. */
    protected const NOW = 1800000000;

    /** @var int Course-module instances handed out. */
    protected int $instance = 7000;

    /**
     * A clean database, the engine's table, no cached limits.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('Needs mod_adaptivequiz.');
        }
        question_progress::reset_caches();
    }

    /**
     * An experiment.
     *
     * @return int
     */
    protected function experiment(): int {
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');

        return (int) $generator->create_experiment()->id;
    }

    /**
     * A run with a given id, strategy and status, and its test.
     *
     * @param int $experimentid The experiment.
     * @param int $id The run id (the screenshot's numbers), or 0 for the next one.
     * @param string $strategy The strategy key.
     * @param int $status The run status.
     * @return array{runid: int, instance: int}
     */
    protected function make_run(int $experimentid, int $id, string $strategy, int $status = registry::STATUS_RUNNING): array {
        global $DB;

        $instance = $this->instance++;
        $course = $this->getDataGenerator()->create_course();
        $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
            'instance' => $instance, 'section' => 0, 'added' => self::NOW]);
        $record = ['experimentid' => $experimentid, 'cellkey' => $strategy . '-' . $id, 'seed' => 1, 'replication' => 1,
            'status' => $status, 'testcmid' => $cmid, 'usermodified' => 0, 'timecreated' => self::NOW,
            'timemodified' => self::NOW,
            'manifestjson' => json_encode(['config' => ['strategy' => ['key' => $strategy],
                'definition' => ['strategy' => $strategy, 'budgets' => ['global' => ['maxitems' => 625]]]]])];
        if ($id > 0) {
            $DB->import_record('local_catquizlab_run', (object) (['id' => $id] + $record));
            $DB->get_manager()->reset_sequence('local_catquizlab_run');
        } else {
            $id = (int) $DB->insert_record('local_catquizlab_run', (object) $record);
        }

        return ['runid' => $id, 'instance' => $instance];
    }

    /**
     * Queued sittings, created at a time and due at a time: the two things the claim order reads.
     *
     * @param array $run From run().
     * @param int $n How many.
     * @param int $created timecreated, seconds before NOW.
     * @param int $due nextruntime (0: due since ever).
     * @return void
     */
    protected function queued(array $run, int $n, int $created, int $due = 0): void {
        global $DB;

        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = (object) ['runid' => $run['runid'], 'personid' => 0, 'status' => attempt_scheduler::STATUS_QUEUED,
                'tries' => 0, 'nextruntime' => $due, 'timecreated' => self::NOW - $created, 'timemodified' => self::NOW];
        }
        $DB->insert_records('local_catquizlab_attempt', $rows);
    }

    /**
     * Collected sittings, $slots at a time, each $seconds long, the last ending $before seconds before NOW, each
     * with an engine attempt of $answered questions.
     *
     * @param array $run From run().
     * @param int $n How many.
     * @param int $seconds Duration of each.
     * @param int $slots Played in parallel.
     * @param int|array $answered Questions per sitting, or a list.
     * @param int $before The last one ended this long before NOW.
     * @return void
     */
    protected function collected(array $run, int $n, int $seconds, int $slots, $answered, int $before = 1): void {
        global $DB;

        $logs = [];
        for ($i = 0; $i < $n; $i++) {
            $q = is_array($answered) ? $answered[$i] : $answered;
            $engine = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => $run['instance'],
                'userid' => 0, 'uniqueid' => 0, 'attemptstate' => 'complete', 'attemptstopcriteria' => '',
                'questionsattempted' => $q, 'timecreated' => self::NOW, 'timemodified' => self::NOW]);
            $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run['runid'],
                'personid' => 0, 'engineattemptid' => $engine, 'status' => attempt_scheduler::STATUS_COLLECTED,
                'tries' => 1, 'runtimems' => $seconds * 1000, 'timecreated' => self::NOW - 100000,
                'timemodified' => self::NOW]);
            // Sitting i ends (n - 1 - i) / slots durations before the last one.
            $end = self::NOW - $before - (int) round(($n - 1 - $i) * $seconds / $slots);
            $logs[] = (object) ['attemptid' => $attemptid, 'runid' => $run['runid'], 'tryno' => 1,
                'outcome' => attempt_history::STARTED, 'runtimems' => null, 'timecreated' => $end - $seconds];
            $logs[] = (object) ['attemptid' => $attemptid, 'runid' => $run['runid'], 'tryno' => 1,
                'outcome' => attempt_history::COLLECTED, 'runtimems' => $seconds * 1000, 'timecreated' => $end];
        }
        $DB->insert_records('local_catquizlab_attemptlog', $logs);
    }

    /**
     * A sitting being played: started $since seconds ago, $answered questions in.
     *
     * @param array $run From run().
     * @param int $since Seconds before NOW it started.
     * @param int $answered Questions the engine has stored.
     * @return void
     */
    protected function flying(array $run, int $since, int $answered): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $personid = (int) $DB->insert_record('local_catquizlab_person', (object) ['runid' => $run['runid'],
            'twinid' => 't' . $user->id, 'twinindex' => 1, 'stratum' => 'conforming', 'abilityglobal' => 0,
            'profilejson' => '{}', 'moodleuserid' => $user->id, 'timecreated' => self::NOW, 'timemodified' => self::NOW]);
        $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => $run['instance'], 'userid' => $user->id,
            'uniqueid' => 0, 'attemptstate' => 'inprogress', 'attemptstopcriteria' => '', 'questionsattempted' => $answered,
            'timecreated' => self::NOW, 'timemodified' => self::NOW]);
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run['runid'],
            'personid' => $personid, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
            'leaseowner' => 'w', 'leaseexpires' => self::NOW + 3600, 'timecreated' => self::NOW - 100000,
            'timemodified' => self::NOW]);
        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid, 'runid' => $run['runid'],
            'tryno' => 1, 'outcome' => attempt_history::STARTED, 'timecreated' => self::NOW - $since]);
    }

    /**
     * Workers up and reporting.
     *
     * @param int $n How many.
     * @return void
     */
    protected function workers(int $n): void {
        global $DB;

        $DB->delete_records('local_catquizlab_worker');
        for ($i = 1; $i <= $n; $i++) {
            $DB->insert_record('local_catquizlab_worker', (object) ['workerid' => 'w' . $i, 'slot' => $i,
                'status' => worker_registry::STATUS_RUNNING, 'heartbeat' => time(), 'timecreated' => time(),
                'timemodified' => time()]);
        }
    }

    /**
     * The progress snapshot of an experiment, with its times on the fake clock.
     *
     * @param int $experimentid The experiment.
     * @param int $later Seconds after NOW.
     * @return array
     */
    protected function snapshot(int $experimentid, int $later = 0): array {
        question_progress::reset_caches();
        $snapshot = live_progress::snapshot($experimentid);
        // The bar's counts at the real clock are the same rows; the times are recomputed at the fake one.
        $snapshot['runs'] = array_map(static function (array $row): array {
            unset($row['time']);
            return $row;
        }, $snapshot['runs']);
        $snapshot['time'] = eta::estimate($experimentid, $snapshot, self::NOW + $later);

        return $snapshot;
    }

    /**
     * The times of one run.
     *
     * @param array $snapshot From snapshot().
     * @param int $runid The run.
     * @return array
     */
    protected function times(array $snapshot, int $runid): array {
        return array_column($snapshot['time']['runs'], null, 'runid')[$runid];
    }

    /**
     * The screenshot of #160: #84 allsubs and #85 classic not started, #86 fastest at 997/1000, #87 highestsub with
     * one sitting 26 questions in, #88 finished. #86's last three sittings were created first, so they are claimed
     * first; #84 and #85 were created later.
     *
     * @return array{experimentid: int, runs: array<int, array>}
     */
    protected function screenshot(): array {
        $experimentid = $this->experiment();
        $runs = [];
        foreach ([84 => 'allsubs', 85 => 'classic', 86 => 'fastest', 87 => 'highestsub', 88 => 'relsubs'] as $id => $s) {
            $runs[$id] = $this->make_run($experimentid, $id, $s, $id === 88 ? registry::STATUS_FINISHED : registry::STATUS_RUNNING);
        }
        $this->workers(13);
        // 24 910 questions in 997 sittings: 982 of 25 and 15 of 24.
        $answered = array_merge(array_fill(0, 982, 25), array_fill(0, 15, 24));
        $this->collected($runs[86], 997, 60, 13, $answered);
        $this->queued($runs[86], 3, 7200);
        $this->queued($runs[84], 1000, 3600);
        $this->queued($runs[85], 1000, 3600);
        $this->queued($runs[87], 999, 3600);
        $this->flying($runs[87], 120, 26);
        $this->collected($runs[88], 10, 60, 1, 25, 20000);

        return ['experimentid' => $experimentid, 'runs' => $runs];
    }

    /**
     * The old method (0.7.59) on the same figures: each run's "remaining" was the cumulative work of all runs up to
     * it in run-id order, over the capacity. Kept here to show what the screenshot showed.
     *
     * @param array $snapshot From snapshot().
     * @return array<int, int> Run id => seconds the old page showed.
     */
    protected function old_remaining(array $snapshot): array {
        $slots = (float) $snapshot['time']['capacity']['slots'];
        $out = [];
        $cumulative = 0.0;
        $rows = $snapshot['runs'];
        usort($rows, static fn(array $a, array $b): int => $a['runid'] <=> $b['runid']);
        $times = array_column($snapshot['time']['runs'], null, 'runid');
        foreach ($rows as $row) {
            $left = max(0, $row['total'] - $row['done'] - $row['failed']);
            if ($left === 0) {
                continue;
            }
            // The old fallback: an unobserved run took the experiment's mean.
            $cumulative += $left * (float) ($times[$row['runid']]['meanseconds'] ?? 60.0);
            $out[$row['runid']] = (int) ceil($cumulative / $slots);
        }

        return $out;
    }

    /**
     * DoD01, AK05: the screenshot. The old page gave #86 the work of #84 and #85; its own work now is its three
     * sittings and about 75 questions, and it is done in minutes because its sittings are first in the claim order.
     *
     * @return void
     */
    public function test_the_screenshot_86_no_longer_inherits_84_and_85(): void {
        $world = $this->screenshot();
        $snapshot = $this->snapshot($world['experimentid']);

        // What the old method made of it: #86 as late as #85, twice #84.
        $old = $this->old_remaining($snapshot);
        $this->assertGreaterThan(2 * HOURSECS, $old[86], 'the old page: over two hours for 3 sittings');
        $this->assertGreaterThanOrEqual($old[85], $old[86]);

        $t86 = $this->times($snapshot, 86);
        $own = $t86['own'];
        $this->assertSame(3, $own['sittings']);
        $this->assertSame(question_progress::ESTIMATE, $own['questionkind']);
        $this->assertEqualsWithDelta(75, $own['questions'], 1, '≈ 24 985 − 24 910');
        $this->assertSame('run', $own['basis']);
        $this->assertSame(180, $own['workseconds'], 'three sittings of 60 s');
        // Done by: in flight first (#87's one sitting), then #86's three, claimed first. Minutes, not hours.
        $this->assertLessThan(10 * MINSECS, $t86['remaining']);
        $this->assertSame(eta::ESTIMATE, $t86['reliability']);
        $this->assertStringContainsString('3 sittings', $t86['text']['own']);
        $this->assertStringContainsString('75 questions', $t86['text']['own']);

        // Runs 84 and 85 wait behind each other and take hours: that is their completion, not #86's.
        $t84 = $this->times($snapshot, 84);
        $t85 = $this->times($snapshot, 85);
        $this->assertGreaterThan(HOURSECS, $t84['remaining']);
        $this->assertGreaterThan($t84['remaining'], $t85['remaining']);
        $this->assertGreaterThan(0, $t85['ahead'], '#85 waits behind #84');
        // Their own work is their own 1000 sittings each, whatever is ahead of them.
        $this->assertSame($t84['own']['workseconds'], $t85['own']['workseconds']);
        // Run 88 is finished: nothing left, the recorded end.
        $this->assertSame(eta::FINAL, $this->times($snapshot, 88)['reliability']);
        // The experiment is done when its last run is.
        $this->assertSame(max(array_column(array_filter(
            $snapshot['time']['runs'],
            static fn(array $r): bool => $r['remaining'] !== null
        ), 'remaining')), $snapshot['time']['remaining']);
    }

    /**
     * AK03, DoD01: renumbering the runs and adding others before and after does not change a run's own work.
     *
     * @return void
     */
    public function test_own_work_is_the_same_whatever_else_is_there(): void {
        $world = $this->screenshot();
        $before = $this->times($this->snapshot($world['experimentid']), 86)['own'];

        // The same run as #286 in another experiment, with runs #280 and #290 around it, and created later.
        $experimentid = $this->experiment();
        $this->make_run($experimentid, 280, 'allsubs');
        $copy = $this->make_run($experimentid, 286, 'fastest');
        $after = $this->make_run($experimentid, 290, 'classic');
        $answered = array_merge(array_fill(0, 982, 25), array_fill(0, 15, 24));
        $this->collected($copy, 997, 60, 13, $answered);
        $this->queued($copy, 3, 60);
        $this->queued(['runid' => 280], 500, 7200);
        $this->queued($after, 500, 7200);

        $own = $this->times($this->snapshot($experimentid), 286)['own'];
        foreach (['sittings', 'questions', 'questionkind', 'workseconds', 'basis'] as $key) {
            $this->assertSame($before[$key], $own[$key], $key);
        }
        // And the original's own work did not move either, with all of that added.
        $again = $this->times($this->snapshot($world['experimentid']), 86)['own'];
        $this->assertSame($before['workseconds'], $again['workseconds']);
    }

    /**
     * AK04: completion follows the claim order — due time, then creation, then id — not the run id. A run with
     * the higher id whose sittings were created first is done first; a retry waiting for its backoff goes last.
     *
     * @return void
     */
    public function test_the_claim_order_not_the_run_id(): void {
        $experimentid = $this->experiment();
        $low = $this->make_run($experimentid, 501, 'fastest');
        $high = $this->make_run($experimentid, 502, 'fastest');
        $this->workers(2);
        $this->collected($low, 10, 30, 2, 20);
        $this->collected($high, 10, 30, 2, 20);
        $this->queued($high, 20, 7200);
        $this->queued($low, 20, 3600);
        $snapshot = $this->snapshot($experimentid);
        $this->assertLessThan(
            $this->times($snapshot, 501)['finish'],
            $this->times($snapshot, 502)['finish'],
            'created first, claimed first, though its id is higher'
        );

        // One retry of the high run, due in an hour: the high run is done only after it, whatever else is left.
        $this->queued($high, 1, 7300, self::NOW + HOURSECS);
        $snapshot = $this->snapshot($experimentid);
        $this->assertGreaterThan(self::NOW + HOURSECS, $this->times($snapshot, 502)['finish']);
        $this->assertLessThan(self::NOW + HOURSECS, $this->times($snapshot, 501)['finish']);
    }

    /**
     * AK04: a run of another experiment ahead in the claim order delays this one; this experiment's own work does
     * not change.
     *
     * @return void
     */
    public function test_a_competing_experiment_ahead_delays_completion_not_own_work(): void {
        $mine = $this->experiment();
        $run = $this->make_run($mine, 0, 'fastest');
        $this->workers(2);
        $this->collected($run, 10, 30, 2, 20);
        $this->queued($run, 10, 60);
        $alone = $this->snapshot($mine);

        $other = $this->experiment();
        $theirs = $this->make_run($other, 0, 'fastest');
        $this->collected($theirs, 10, 30, 2, 20);
        $this->queued($theirs, 100, 7200);
        $shared = $this->snapshot($mine);

        $runid = $run['runid'];
        $this->assertSame(
            $this->times($alone, $runid)['own']['workseconds'],
            $this->times($shared, $runid)['own']['workseconds']
        );
        $this->assertGreaterThan(
            $this->times($alone, $runid)['remaining'] + 1000,
            $this->times($shared, $runid)['remaining'],
            '100 sittings of another experiment ahead'
        );
        $this->assertGreaterThan(0, $this->times($shared, $runid)['ahead']);
    }

    /**
     * AK07, DoD03: the capacity counts only the part of a sitting inside the window, and never more slots than
     * there are workers. Two workers playing sittings of an hour: two slots, not twelve.
     *
     * @return void
     */
    public function test_long_sittings_do_not_make_slots_no_worker_has(): void {
        $experimentid = $this->experiment();
        $run = $this->make_run($experimentid, 0, 'classic');
        $this->workers(2);
        $this->collected($run, 6, HOURSECS, 2, 625);
        $this->queued($run, 4, 60);
        $capacity = $this->snapshot($experimentid)['time']['capacity'];
        // The window is three mean durations; at most two sittings overlap any moment of it.
        $this->assertSame(3 * HOURSECS, $capacity['window']);
        $this->assertLessThanOrEqual(2.0, $capacity['slots']);
        $this->assertGreaterThan(1.5, $capacity['slots']);

        // The old count: whole durations of what ended in the window, over the window.
        $this->assertSame(2, $capacity['physical']);
        $figures = run_figures::read([$run['runid']], self::NOW);
        $this->assertLessThanOrEqual(2 * 3 * HOURSECS, $figures['runs'][$run['runid']]['busy']);
    }

    /**
     * AK08: without a worker and with nothing in flight there is no forecast, and it does not count down by itself.
     *
     * @return void
     */
    public function test_no_capacity_no_countdown(): void {
        $experimentid = $this->experiment();
        $run = $this->make_run($experimentid, 0, 'fastest');
        $this->workers(0);
        $this->collected($run, 10, 30, 2, 20, 3 * HOURSECS);
        $this->queued($run, 10, 60);
        foreach ([0, 60, 600] as $later) {
            $time = $this->snapshot($experimentid, $later)['time'];
            $this->assertSame(eta::UNRELIABLE, $time['reliability'], "after $later s");
            $this->assertNull($time['remaining']);
            $this->assertSame('—', $time['text']['finish']);
        }
    }

    /**
     * Paused and held: no running forecast, the reason said, the own work still shown.
     *
     * @return void
     */
    public function test_paused_and_held(): void {
        $experimentid = $this->experiment();
        $run = $this->make_run($experimentid, 0, 'fastest');
        $this->workers(2);
        $this->collected($run, 10, 30, 2, 20);
        $this->queued($run, 10, 60);
        run_lifecycle::set_paused($run['runid'], true, 'by hand');
        $snapshot = $this->snapshot($experimentid);
        $this->assertSame(eta::UNRELIABLE, $snapshot['time']['reliability']);
        $this->assertSame(get_string('eta:whypaused', 'local_catquizlab'), $snapshot['time']['text']['reason']);
        $this->assertSame(300, $this->times($snapshot, $run['runid'])['own']['workseconds'], 'own work stays');

        run_lifecycle::set_paused($run['runid'], false, '');
        run_lifecycle::set_status($run['runid'], registry::STATUS_FAILED, 'test');
        $snapshot = $this->snapshot($experimentid);
        $this->assertSame(eta::UNRELIABLE, $snapshot['time']['reliability']);
        $this->assertSame(get_string('eta:whyblocked', 'local_catquizlab'), $snapshot['time']['text']['reason']);
    }

    /**
     * #142 DoD1: a staggered start — workers up, nothing in flight and nothing finished lately — has no throughput
     * yet: calculating, not a forecast from old work. More workers playing more sittings: a shorter forecast.
     *
     * @return void
     */
    public function test_staggered_start_and_scaling(): void {
        $experimentid = $this->experiment();
        $run = $this->make_run($experimentid, 0, 'fastest');
        $this->workers(4);
        $this->collected($run, 10, 30, 2, 20, 3 * HOURSECS);
        $this->queued($run, 40, 60);
        $time = $this->times($this->snapshot($experimentid), $run['runid']);
        $this->assertSame(eta::CALCULATING, $time['reliability']);
        $this->assertSame(get_string('eta:whynothroughput', 'local_catquizlab'), $time['text']['reason']);

        // Two sittings in flight on two of the four workers.
        $this->flying($run, 10, 0);
        $this->flying($run, 5, 0);
        $two = $this->times($this->snapshot($experimentid), $run['runid']);
        // Four in flight: the workers scaled up.
        $this->flying($run, 3, 0);
        $this->flying($run, 1, 0);
        $four = $this->times($this->snapshot($experimentid), $run['runid']);
        $this->assertNotNull($two['remaining']);
        $this->assertLessThan($two['remaining'], $four['remaining']);
    }

    /**
     * DoD05 calibration: few sittings and drifting durations widen the range; many steady ones keep only the site's
     * load in it; after a drift the latest durations are the guess.
     *
     * Reference values: Student's t, two-sided 95 %, from the usual table (4.303 at 2, 2.228 at 10 degrees of freedom).
     *
     * @return void
     */
    public function test_the_range_widens_for_few_sittings_and_for_drift(): void {
        $this->assertEqualsWithDelta(4.303, eta::t975(2), 1e-9);
        $this->assertEqualsWithDelta(2.228, eta::t975(10), 1e-9);
        $this->assertEqualsWithDelta(2.042, eta::t975(30), 0.01);
        $this->assertEqualsWithDelta(1.96, eta::t975(100000), 0.001);

        $experimentid = $this->experiment();
        $this->workers(2);
        // Three sittings, 30, 30 and 60 seconds: sd 17.32 from two degrees of freedom, widened by 4.303 / 1.96.
        $few = $this->make_run($experimentid, 0, 'fastest');
        $this->collected($few, 2, 30, 1, 20, 100);
        $this->collected($few, 1, 60, 1, 20, 1);
        // Fifteen steady sittings of 40 seconds.
        $steady = $this->make_run($experimentid, 0, 'classic');
        $this->collected($steady, 15, 40, 1, 20, 1);
        // Ten of 30 seconds, then the last five of 60: the site got slower.
        $drift = $this->make_run($experimentid, 0, 'allsubs');
        $this->collected($drift, 10, 30, 1, 20, 2000);
        $this->collected($drift, 5, 60, 1, 20, 1);
        foreach ([$few, $steady, $drift] as $run) {
            $this->queued($run, 5, 60);
        }
        $durations = eta::forecast([$few['runid'], $steady['runid'], $drift['runid']], self::NOW)['durations'];

        [$mean, $se, $basis, $sd] = $durations[$few['runid']];
        $this->assertSame('run', $basis);
        $this->assertEqualsWithDelta(40.0, $mean, 1e-9);
        $this->assertEqualsWithDelta(sqrt(300) * 4.303 / 1.96, $sd, 1e-6);
        $this->assertEqualsWithDelta(sqrt(300) * 4.303 / 1.96 / sqrt(3), $se, 1e-6);

        // Steady: no spread, no drift; what remains is the site's load, 30 % of a sitting over the root of 15.
        [$mean, $se, , $sd] = $durations[$steady['runid']];
        $this->assertEqualsWithDelta(40.0, $mean, 1e-9);
        $this->assertEqualsWithDelta(eta::LOAD_SE * 40 / sqrt(15), $se, 1e-9);

        // Mean 40, the last five 60: the latest five are the guess, the gap of 20 seconds is in the uncertainty.
        [$mean, $se] = $durations[$drift['runid']];
        $this->assertEqualsWithDelta(60.0, $mean, 1e-9);
        $this->assertGreaterThanOrEqual(20.0, $se);
        // The same sittings with the slow ones first: the latest five are 30 seconds, ten from the mean, not twenty.
        $earlier = $this->make_run($experimentid, 0, 'highestsub');
        $this->collected($earlier, 5, 60, 1, 20, 2000);
        $this->collected($earlier, 10, 30, 1, 20, 1);
        [$meanearlier, $seearlier] = eta::forecast([$earlier['runid']], self::NOW)['durations'][$earlier['runid']];
        $this->assertEqualsWithDelta(30.0, $meanearlier, 1e-9);
        $this->assertLessThan($se, $seearlier);
    }

    /**
     * DoD05 calibration: four sittings begun a while apart are done when the one begun last is, not when their
     * average is, while the site stays busy; when they are the site's last work, the middle of a processor-bound and
     * a slot-bound end, with a range holding both. Twenty questions of five seconds each; the sittings began 90, 60,
     * 30 and 5 seconds ago.
     *
     * @return void
     */
    public function test_a_run_ends_when_its_last_begun_sitting_does(): void {
        global $DB;

        $experimentid = $this->experiment();
        $run = $this->make_run($experimentid, 0, 'fastest');
        $this->workers(4);
        $this->collected($run, 10, 100, 4, 20, 1);
        foreach ([90 => 18, 60 => 12, 30 => 6, 5 => 1] as $since => $answered) {
            $this->flying($run, $since, $answered);
        }
        // Something else queued on the site: the slots stay busy, the last one begun takes its 95 seconds; the
        // average (43 of 80 questions left, 54 seconds) would be far too early.
        $other = $this->make_run($this->experiment(), 0, 'classic');
        $this->collected($other, 3, 100, 1, 20, 1);
        $this->queued($other, 5, 600);
        $time = $this->times($this->snapshot($experimentid), $run['runid']);
        $this->assertSame(eta::ESTIMATE, $time['reliability']);
        $this->assertGreaterThanOrEqual(90, $time['remaining']);
        $this->assertLessThanOrEqual(100, $time['remaining']);

        // Nothing else: the site empties as these end. Bound by the processors they would share the slots out
        // (54 s), bound by the slots the last takes 95 s: the middle, and a range that holds both.
        $DB->delete_records('local_catquizlab_attempt', ['runid' => $other['runid'],
            'status' => attempt_scheduler::STATUS_QUEUED]);
        $time = $this->times($this->snapshot($experimentid), $run['runid']);
        $this->assertEqualsWithDelta(75, $time['remaining'], 2);
        $this->assertLessThanOrEqual(54, $time['remaining'] * (1 - $time['margin']));
        $this->assertGreaterThanOrEqual(95, $time['remaining'] * (1 + $time['margin']));
    }

    /**
     * DoD05 calibration: a run with nothing finished of its own strategy takes the pace of its own questions, not
     * another strategy's durations; without a pace, other strategies' time per question times its own questions.
     * A classic run's sittings take 600 s; the fastest run plays 20 questions at five seconds each.
     *
     * @return void
     */
    public function test_its_own_pace_before_another_strategys_durations(): void {
        global $DB;

        $experimentid = $this->experiment();
        $this->workers(4);
        $classic = $this->make_run($experimentid, 0, 'classic');
        $this->collected($classic, 3, 600, 1, 120, 1);
        $fastest = $this->make_run($experimentid, 0, 'fastest');
        // Three of its own collected, 20 questions each, so a sitting is expected to have 20; their executions are
        // not in the history (restored from elsewhere, say), so it has no duration sample of its own.
        $this->collected($fastest, 3, 100, 1, 20, 1);
        $DB->delete_records('local_catquizlab_attemptlog', ['runid' => $fastest['runid']]);
        $this->flying($fastest, 50, 10);
        $this->queued($fastest, 2, 60);
        // Another fastest run with nothing collected: only the bound of 625 questions, so the classic duration
        // stays the guess (625 questions at five seconds would be longer).
        $bounded = $this->make_run($experimentid, 0, 'fastest');
        $this->flying($bounded, 50, 10);
        $durations = eta::forecast([$fastest['runid'], $bounded['runid']], self::NOW)['durations'];

        [$mean, $se, $basis] = $durations[$fastest['runid']];
        $this->assertSame('questions', $basis, 'not the classic run\'s 600 s');
        $this->assertEqualsWithDelta(100.0, $mean, 1e-6, '20 questions at 5 s');
        // Ten questions: 0.5 * sqrt(5 / 10) of a sitting.
        $this->assertEqualsWithDelta(100.0 * 0.5 * sqrt(0.5), $se, 1e-6);
        $time = $this->times($this->snapshot($experimentid), $fastest['runid']);
        $this->assertSame(eta::PROVISIONAL, $time['reliability']);

        [$mean, , $basis] = $durations[$bounded['runid']];
        $this->assertSame('experiment', $basis);
        $this->assertEqualsWithDelta(600.0, $mean, 1e-6);

        // A third fastest run, its sittings known to have 20 questions, none in flight: other strategies' time per
        // question (5 s) times its 20 questions, not their 600 s a sitting.
        $waiting = $this->make_run($experimentid, 0, 'allsubs');
        $this->collected($waiting, 3, 100, 1, 20, 1);
        $DB->delete_records('local_catquizlab_attemptlog', ['runid' => $waiting['runid']]);
        $this->queued($waiting, 4, 60);
        [$mean, $se, $basis] = eta::forecast([$waiting['runid']], self::NOW)['durations'][$waiting['runid']];
        $this->assertSame('experiment', $basis);
        $this->assertEqualsWithDelta(100.0, $mean, 1e-6);
        $this->assertGreaterThanOrEqual(eta::BORROWED_SE * 100.0 - 1e-9, $se);
    }

    /**
     * A run with nothing left but sittings in flight rests on its own duration only: what other runs have queued
     * does not make its forecast provisional.
     *
     * @return void
     */
    public function test_sittings_in_flight_only_do_not_borrow(): void {
        $experimentid = $this->experiment();
        $run = $this->make_run($experimentid, 0, 'fastest');
        $this->workers(4);
        $this->collected($run, 5, 60, 2, 20, 1);
        $this->flying($run, 20, 6);
        $this->flying($run, 10, 3);
        // Another experiment's run, nothing measured, ten sittings queued ahead of everything.
        $other = $this->make_run($this->experiment(), 0, 'classic');
        $this->queued($other, 10, 600);
        $time = $this->times($this->snapshot($experimentid), $run['runid']);
        $this->assertSame(eta::ESTIMATE, $time['reliability']);
        $this->assertNotNull($time['remaining']);
    }

    /**
     * #142 DoD1: a sitting failed for good is neither done nor left: not in the own work, not in the questions.
     *
     * @return void
     */
    public function test_a_failed_sitting_is_neither_done_nor_left(): void {
        global $DB;

        $experimentid = $this->experiment();
        $run = $this->make_run($experimentid, 0, 'fastest');
        $this->workers(2);
        $this->collected($run, 10, 30, 2, 20);
        $this->queued($run, 3, 60);
        $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run['runid'], 'personid' => 0,
            'status' => attempt_scheduler::STATUS_FAILED, 'tries' => 3, 'timecreated' => self::NOW,
            'timemodified' => self::NOW]);
        $snapshot = $this->snapshot($experimentid);
        $own = $this->times($snapshot, $run['runid'])['own'];
        $this->assertSame(3, $own['sittings'], 'the failed one is not left');
        $this->assertSame(90, $own['workseconds']);
        $this->assertSame(200, $snapshot['questions']['done'], 'and not done');
        $this->assertSame(1, $snapshot['now']['failed']);
    }

    /**
     * Before anything was measured: calculating, with the reason; nothing invented.
     *
     * @return void
     */
    public function test_calculating_before_a_basis(): void {
        $experimentid = $this->experiment();
        $run = $this->make_run($experimentid, 0, 'fastest');
        $this->workers(2);
        $this->queued($run, 10, 60);
        $time = $this->snapshot($experimentid)['time'];
        $this->assertSame(eta::CALCULATING, $time['reliability']);
        $this->assertSame(get_string('eta:whynotstarted', 'local_catquizlab'), $time['text']['reason']);

        // Two finished: still not a duration.
        $this->collected($run, 2, 30, 2, 20);
        $time = $this->snapshot($experimentid)['time'];
        $this->assertSame(eta::CALCULATING, $time['reliability']);
        $this->assertSame(get_string('eta:whysample', 'local_catquizlab'), $time['text']['reason']);
        $this->assertSame('Calculating…', $time['text']['remaining']);
    }

    /**
     * AK08: finished — remaining zero, the recorded end, the same an hour later.
     *
     * @return void
     */
    public function test_finished_shows_the_actual_end(): void {
        $experimentid = $this->experiment();
        $run = $this->make_run($experimentid, 0, 'fastest');
        $this->collected($run, 10, 30, 2, 20, 500);
        $first = $this->snapshot($experimentid)['time'];
        $later = $this->snapshot($experimentid, HOURSECS)['time'];
        $this->assertSame(eta::FINAL, $first['reliability']);
        $this->assertSame(0, $first['remaining']);
        $this->assertSame(self::NOW - 500, $first['finish']);
        $this->assertSame($first['finish'], $later['finish']);
        $this->assertSame($first['elapsed'], $later['elapsed']);
        $this->assertSame(0, $this->times(['time' => $first], $run['runid'])['remaining']);
    }

    /**
     * A sitting nobody is playing any more (its lease ran out) is not in flight: it waits to be queued again.
     *
     * @return void
     */
    public function test_an_expired_lease_is_not_in_flight(): void {
        global $DB;

        $experimentid = $this->experiment();
        $run = $this->make_run($experimentid, 0, 'fastest');
        $this->workers(1);
        $this->collected($run, 10, 30, 1, 20);
        $this->flying($run, 60, 10);
        $DB->set_field(
            'local_catquizlab_attempt',
            'leaseexpires',
            self::NOW - 1,
            ['runid' => $run['runid'], 'status' => attempt_scheduler::STATUS_RUNNING]
        );
        $figures = run_figures::read([$run['runid']], self::NOW)['runs'][$run['runid']];
        $this->assertSame(0, $figures['inflight']);
        $this->assertSame(1, $figures['stale']);
        $time = $this->times($this->snapshot($experimentid), $run['runid']);
        // Queued again after the backoff: it is done after that, not now.
        $this->assertGreaterThanOrEqual(attempt_scheduler::RETRY_BACKOFF, $time['remaining']);
    }

    /**
     * AK12, DoD: the number of queries for a snapshot does not grow with the runs (1, 10, 40 runs).
     *
     * @return void
     */
    public function test_the_query_count_does_not_grow_with_runs(): void {
        global $DB;

        $counts = [];
        foreach ([1, 10, 40] as $n) {
            $experimentid = $this->experiment();
            for ($i = 0; $i < $n; $i++) {
                $run = $this->make_run($experimentid, 0, $i % 2 ? 'fastest' : 'classic');
                $this->queued($run, 5, 60);
            }
            $this->workers(2);
            question_progress::reset_caches();
            $before = $DB->perf_get_reads();
            live_progress::snapshot($experimentid);
            $counts[$n] = $DB->perf_get_reads() - $before;
        }
        $this->assertSame($counts[1], $counts[10], json_encode($counts));
        $this->assertSame($counts[1], $counts[40], json_encode($counts));
    }

    /**
     * The snapshot carries the times, and the run rows carry their text: one reading for bar and forecast.
     *
     * @return void
     */
    public function test_the_snapshot_carries_the_times(): void {
        $world = $this->screenshot();
        $snapshot = live_progress::snapshot($world['experimentid']);
        $this->assertArrayHasKey('time', $snapshot);
        $this->assertArrayNotHasKey('figures', $snapshot, 'the reading is not sent to the page');
        foreach ($snapshot['runs'] as $row) {
            $this->assertNotEmpty($row['time']['own']);
            $this->assertArrayHasKey('owntime', $row['time']);
        }
        $this->assertNotSame('', $snapshot['time']['text']['own']);
    }
}
