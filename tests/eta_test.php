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
use local_catquizlab\local\eta;
use local_catquizlab\local\live_progress;

/**
 * Started, elapsed, finish and remaining, on a fake clock (#125).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\eta
 * @covers     \local_catquizlab\local\live_progress
 */
final class eta_test extends \advanced_testcase {
    /** @var int The fake clock's zero. */
    protected const T0 = 1800000000;

    /** @var int The experiment. */
    protected int $experimentid = 0;

    /** @var int[] Strategy => run. */
    protected array $runs = [];

    /** @var int A running attempt id for history rows. */
    protected int $attemptno = 1;

    /**
     * Two runs: a fast strategy and a slow one, fifty sittings each.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $this->experimentid = (int) $generator->create_experiment()->id;
        foreach (['fastest', 'classic'] as $strategy) {
            $this->runs[$strategy] = (int) $generator->create_run(['experimentid' => $this->experimentid,
                'manifestjson' => json_encode(['config' => ['strategy' => ['key' => $strategy]]])])->id;
        }
    }

    /**
     * An execution: started at $start, finished $seconds later.
     *
     * @param string $strategy Which run.
     * @param int $start Seconds after T0.
     * @param int $seconds How long it took.
     * @param bool $finished Whether it has finished.
     * @return void
     */
    protected function execution(string $strategy, int $start, int $seconds, bool $finished = true): void {
        global $DB;

        $attemptid = $this->attemptno++;
        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid, 'runid' => $this->runs[$strategy],
            'tryno' => 1, 'outcome' => attempt_history::STARTED, 'timecreated' => self::T0 + $start]);
        if ($finished) {
            $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid,
                'runid' => $this->runs[$strategy], 'tryno' => 1, 'outcome' => attempt_history::COLLECTED,
                'runtimems' => $seconds * 1000, 'timecreated' => self::T0 + $start + $seconds]);
        }
    }

    /**
     * A progress snapshot as live_progress builds it.
     *
     * @param string $state The state.
     * @param array $done Strategy => [collected, failed, inflight].
     * @return array
     */
    protected function progress(string $state, array $done): array {
        $rows = [];
        $inflight = 0;
        foreach ($this->runs as $strategy => $runid) {
            [$collected, $failed, $flying] = $done[$strategy] ?? [0, 0, 0];
            $rows[] = ['runid' => $runid, 'done' => $collected, 'total' => 50, 'failed' => $failed, 'inflight' => $flying];
            $inflight += $flying;
        }

        return ['state' => $state, 'runs' => $rows, 'now' => ['inflight' => $inflight]];
    }

    /**
     * Before the first sitting: no start, no forecast, said why.
     *
     * @return void
     */
    public function test_before_the_first_sitting(): void {
        $time = eta::estimate($this->experimentid, $this->progress(live_progress::WAITING, []), self::T0);

        $this->assertSame(eta::CALCULATING, $time['reliability']);
        $this->assertNull($time['started']);
        $this->assertNull($time['finish']);
        $this->assertSame(get_string('eta:whynotstarted', 'local_catquizlab'), $time['text']['reason']);
    }

    /**
     * During calibration: started and elapsed, but "calculating" until three sittings finished.
     *
     * @return void
     */
    public function test_calibration_says_calculating(): void {
        $this->execution('fastest', 0, 10);
        $this->execution('fastest', 2, 10);
        $this->execution('fastest', 4, 10, false);

        $time = eta::estimate(
            $this->experimentid,
            $this->progress(live_progress::RUNNING, ['fastest' => [2, 0, 1]]),
            self::T0 + 20
        );
        $this->assertSame(eta::CALCULATING, $time['reliability']);
        $this->assertSame(self::T0, $time['started']);
        $this->assertSame(20, $time['elapsed']);
        $this->assertNull($time['remaining']);
        $this->assertSame(get_string('eta:calculating', 'local_catquizlab'), $time['text']['remaining']);
    }

    /**
     * Runs with different durations: each run's own mean, in queue order, over the observed capacity.
     *
     * @return void
     */
    public function test_runs_with_different_durations(): void {
        // Two slots for 600 s: fastest sittings of 10 s, then classic sittings of 60 s.
        for ($slot = 0; $slot < 2; $slot++) {
            for ($t = 0; $t < 300; $t += 10) {
                $this->execution('fastest', $t, 10);
            }
            for ($t = 300; $t < 600; $t += 60) {
                $this->execution('classic', $t, 60);
            }
        }
        $now = self::T0 + 600;
        // 60 fastest collected? 50 planned: all collected; 10 classic collected, none in flight.
        $time = eta::estimate(
            $this->experimentid,
            $this->progress(live_progress::RUNNING, ['fastest' => [50, 0, 0], 'classic' => [10, 0, 0]]),
            $now
        );

        // Capacity: (60 × 10 + 10 × 60) s busy in 600 s = 2 slots.
        $this->assertEqualsWithDelta(2.0, $time['capacity']['slots'], 0.01);
        // Classic: 40 left × 60 s / 2 slots = 1200 s.
        $this->assertSame(1200, $time['remaining']);
        $this->assertSame($now + 1200, $time['finish']);
        $this->assertSame(eta::ESTIMATE, $time['reliability']);
        $byrun = array_column($time['runs'], null, 'runid');
        $this->assertSame(eta::FINAL, $byrun[$this->runs['fastest']]['reliability']);
        $this->assertSame(1200, $byrun[$this->runs['classic']]['remaining']);
        $this->assertSame(60.0, $byrun[$this->runs['classic']]['meanseconds']);
        // The range is stated, and at least ± 10 %.
        $this->assertLessThanOrEqual(1080, $time['range']['low']);
        $this->assertGreaterThanOrEqual(1320, $time['range']['high']);
        $this->assertStringContainsString('≈', $time['text']['remaining']);
    }

    /**
     * The order matters: a slow run queued before a fast one delays the fast one.
     *
     * @return void
     */
    public function test_queue_order_and_a_strategy_not_yet_observed(): void {
        // Only fastest observed so far: classic rests on the experiment's mean — provisional.
        for ($t = 0; $t < 100; $t += 10) {
            $this->execution('fastest', $t, 10);
        }
        $time = eta::estimate(
            $this->experimentid,
            $this->progress(live_progress::RUNNING, ['fastest' => [10, 0, 0]]),
            self::T0 + 100
        );
        $this->assertSame(eta::PROVISIONAL, $time['reliability']);
        $this->assertSame(get_string('eta:provisional', 'local_catquizlab'), $time['text']['status']);
        $byrun = array_column($time['runs'], null, 'runid');
        $this->assertSame('run', $byrun[$this->runs['fastest']]['basis']);
        $this->assertSame('experiment', $byrun[$this->runs['classic']]['basis']);
        // Fastest (first in the queue) finishes before classic.
        $this->assertLessThan($byrun[$this->runs['classic']]['finish'], $byrun[$this->runs['fastest']]['finish']);
    }

    /**
     * A changing throughput moves the forecast; the start never moves.
     *
     * @return void
     */
    public function test_changing_throughput_moves_the_forecast_not_the_start(): void {
        // One slot for the first 600 s.
        for ($t = 0; $t < 600; $t += 20) {
            $this->execution('fastest', $t, 20);
        }
        $slow = eta::estimate(
            $this->experimentid,
            $this->progress(live_progress::RUNNING, ['fastest' => [30, 0, 0]]),
            self::T0 + 600
        );
        // Then four slots for the next 600 s.
        for ($slot = 0; $slot < 4; $slot++) {
            for ($t = 600; $t < 1200; $t += 20) {
                $this->execution('fastest', $t, 20);
            }
        }
        $fast = eta::estimate(
            $this->experimentid,
            $this->progress(live_progress::RUNNING, ['fastest' => [50, 0, 0], 'classic' => [0, 0, 0]]),
            self::T0 + 1200
        );

        $this->assertEqualsWithDelta(1.0, $slow['capacity']['slots'], 0.01);
        $this->assertEqualsWithDelta(4.0, $fast['capacity']['slots'], 0.01);
        $this->assertSame(self::T0, $slow['started']);
        $this->assertSame(self::T0, $fast['started'], 'the start does not jump');
        // Classic not observed: the experiment's mean, 20 s; 50 × 20 / 4 = 250 s.
        $this->assertSame(250, $fast['remaining']);
    }

    /**
     * Blocked, paused, or without capacity: no running forecast, and why.
     *
     * @return void
     */
    public function test_no_running_forecast_when_nothing_moves(): void {
        for ($t = 0; $t < 100; $t += 10) {
            $this->execution('fastest', $t, 10);
        }
        $progress = ['fastest' => [10, 0, 0]];
        foreach ([live_progress::BLOCKED => 'eta:whyblocked', live_progress::PAUSED => 'eta:whypaused'] as $state => $why) {
            $time = eta::estimate($this->experimentid, $this->progress($state, $progress), self::T0 + 100);
            $this->assertSame(eta::UNRELIABLE, $time['reliability']);
            $this->assertNull($time['finish']);
            $this->assertSame(get_string($why, 'local_catquizlab'), $time['text']['reason']);
            $this->assertSame(get_string('eta:na', 'local_catquizlab'), $time['text']['finish']);
        }

        // Waiting, an hour after the last sitting finished, nothing in flight.
        $time = eta::estimate($this->experimentid, $this->progress(live_progress::WAITING, $progress), self::T0 + 3700);
        $this->assertSame(eta::UNRELIABLE, $time['reliability']);
        $this->assertSame(get_string('eta:whynocapacity', 'local_catquizlab'), $time['text']['reason']);
        $this->assertSame(3700, $time['elapsed']);
    }

    /**
     * Finished: the recorded end, however late the page is looked at.
     *
     * @return void
     */
    public function test_finished_shows_the_actual_end(): void {
        for ($t = 0; $t < 500; $t += 10) {
            $this->execution('fastest', $t, 10);
        }
        for ($t = 0; $t < 3000; $t += 60) {
            $this->execution('classic', $t, 60);
        }
        $progress = $this->progress(live_progress::FINISHED, ['fastest' => [50, 0, 0], 'classic' => [49, 1, 0]]);

        foreach ([self::T0 + 3100, self::T0 + 86400] as $now) {
            $time = eta::estimate($this->experimentid, $progress, $now);
            $this->assertSame(eta::FINAL, $time['reliability']);
            $this->assertSame(self::T0 + 3000, $time['finish']);
            $this->assertSame(3000, $time['elapsed']);
            $this->assertSame(0, $time['remaining']);
            $this->assertStringContainsString(get_string('eta:finishedat', 'local_catquizlab', ''), $time['text']['finish'] . ' ');
        }
    }

    /**
     * The snapshot carries the times, per run as well, and the run list folds when long.
     *
     * @return void
     */
    public function test_the_snapshot_carries_the_times(): void {
        global $DB;

        $this->assertSame(eta::NONE, live_progress::snapshot($this->experimentid)['time']['reliability'], 'nothing planned');
        foreach ([1, 2] as $i) {
            $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $this->runs['classic'], 'personid' => $i,
                'status' => 0, 'tries' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        }
        $snapshot = live_progress::snapshot($this->experimentid);

        $this->assertArrayHasKey('time', $snapshot);
        $this->assertSame(eta::CALCULATING, $snapshot['time']['reliability']);
        $this->assertFalse($snapshot['manyruns']);
        foreach ($snapshot['runs'] as $run) {
            $this->assertArrayHasKey('remaining', $run['time']);
        }
        // Spans are rounded to what an estimate can claim.
        $this->assertSame(format_time(3900), eta::span(3912));
        $this->assertSame(format_time(9000), eta::span(8890));
    }
}
