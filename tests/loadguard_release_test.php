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

namespace local_catquizlab;

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\run_log;
use local_catquizlab\local\worker_capacity;
use local_catquizlab\local\worker_registry;

/**
 * The load guard released by a person, on purpose, recorded, and not for good (#162).
 *
 * The live installation of 15.11.2026: 25 workers configured, a safety cap of 25, 12 in force. The guard had counted
 * 37 capacity failures (submit_cdp_timeout, answers that took over 60 s and were reconciled afterwards) against 10
 * finished executions — 37 / 47 = 78.7 % over a threshold of 20 % — and halved the limit. Waiting did not end it:
 * every evaluation that still found the failures renewed the cooldown, and setting the minimum to 0 switched off future
 * trips but left the cooldown in force. There was no way to end it but SQL.
 *
 * All with a set clock: the guard is evaluated at most every 30 seconds, so each step moves the clock past that.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\worker_capacity
 */
final class loadguard_release_test extends \advanced_testcase {
    /** @var int The clock the test starts at. */
    protected int $now;

    /**
     * The installation as it was: 25 configured, a cap of 25.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('worker_concurrency', 25, 'local_catquizlab');
        set_config('worker_safety_cap', 25, 'local_catquizlab');
        set_config('loadguard', '', 'local_catquizlab');
        $this->now = time();
    }

    /**
     * Execution history rows at a time.
     *
     * @param int $n How many.
     * @param string $outcome attempt_history outcome.
     * @param string $code The reason code.
     * @param int $at When.
     * @return void
     */
    protected function rows(int $n, string $outcome, string $code, int $at): void {
        global $DB;
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['attemptid' => 1, 'runid' => 1, 'tryno' => 1, 'outcome' => $outcome, 'reasoncode' => $code,
                'timecreated' => $at];
        }
        $DB->insert_records('local_catquizlab_attemptlog', $rows);
    }

    /**
     * The installation-level lifecycle events, in order.
     *
     * @return array[] Each [event, detail].
     */
    protected function events(): array {
        global $DB;

        return array_values(array_map(
            static fn($r): array => [$r->event, json_decode((string) $r->detail, true) ?: []],
            $DB->get_records_select('local_catquizlab_runlog', 'runid = 0', [], 'id ASC', 'id, event, detail')
        ));
    }

    /**
     * The snapshot of 15.11.2026: 37 against 10, 25 → 12. Released by a person with a reason: 25 again, recorded in
     * the site log and the lifecycle log, the failures already counted not counted again, nothing else touched.
     *
     * @return void
     */
    public function test_the_snapshot_of_the_live_installation_and_its_release(): void {
        global $DB, $USER;
        $this->rows(37, attempt_history::RECOVERED, 'submit_cdp_timeout', $this->now - 60);
        $this->rows(10, attempt_history::COLLECTED, '', $this->now - 60);
        $inplay = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => 1, 'personid' => 1,
            'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1, 'leaseowner' => 'w-3',
            'leaseexpires' => $this->now + 600, 'nextruntime' => 0, 'timecreated' => $this->now, 'timemodified' => $this->now]);

        $before = worker_capacity::effective($this->now);
        $this->assertSame([12, 'loadguard', 25, 25], [$before['limit'], $before['by'], $before['configured'], $before['cap']]);
        $this->assertSame(37, $before['guard']['failures']);
        $this->assertSame(10, $before['guard']['executions']);
        $this->assertEqualsWithDelta(0.787, $before['guard']['share'], 0.001);
        $this->assertSame('', worker_capacity::release_refusal($before['guard'], $this->now));

        // Without a reason: nothing.
        $this->assertSame('no-reason', worker_capacity::release_guard((int) $USER->id, '  ', $this->now + 5)['reason']);
        $this->assertTrue(worker_capacity::effective($this->now + 5)['guard']['throttled']);

        $sink = $this->redirectEvents();
        $release = worker_capacity::release_guard((int) $USER->id, 'Selector fixed, Apache at 30 %', $this->now + 10);
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($e): bool => $e instanceof \local_catquizlab\event\loadguard_released
        ));
        $sink->close();
        $this->assertTrue($release['ok']);
        $this->assertSame([12, 25], [$release['before']['limit'], $release['after']['limit']]);
        $this->assertCount(1, $events);
        $this->assertSame((int) $USER->id, (int) $events[0]->userid);
        $this->assertSame(37, $events[0]->other['failures']);
        $this->assertSame('Selector fixed, Apache at 30 %', $events[0]->other['reason']);
        [$event, $detail] = array_slice($this->events(), -1)[0];
        $this->assertSame(run_log::LOAD_RELEASED, $event);
        $this->assertTrue($detail['manual']);
        $this->assertArrayNotHasKey('by', $detail, 'no user id in the plugin\'s own log');
        $this->assertSame(1, $DB->count_records('task_adhoc', ['classname' => '\\local_catquizlab\\task\\kick_pipeline']));

        // Counted from the release: the 37 do not trip it again a minute later; the lease in play is untouched.
        $after = worker_capacity::effective($this->now + 60);
        $this->assertSame([25, 'configured'], [$after['limit'], $after['by']]);
        $this->assertSame(0, $after['guard']['failures']);
        $sitting = $DB->get_record('local_catquizlab_attempt', ['id' => $inplay]);
        $this->assertSame(['w-3', $this->now + 600], [$sitting->leaseowner, (int) $sitting->leaseexpires]);

        // A second person a moment later: nothing to release, nothing logged twice.
        $this->assertSame('not-throttled', worker_capacity::release_guard((int) $USER->id, 'again', $this->now + 70)['reason']);
        $this->assertCount(1, array_filter($this->events(), static fn($e): bool => $e[0] === run_log::LOAD_RELEASED));
    }

    /**
     * The same failures again soon after a release: throttled again for twice the cooldown, and no second release
     * before it ends — not a release and a trip every few minutes. After the probation a trip is an ordinary one.
     *
     * @return void
     */
    public function test_a_trip_soon_after_a_release_holds_twice_and_cannot_be_released(): void {
        global $USER;
        $this->rows(37, attempt_history::RECOVERED, 'submit_cdp_timeout', $this->now - 60);
        $this->rows(10, attempt_history::COLLECTED, '', $this->now - 60);
        worker_capacity::effective($this->now);
        $released = $this->now + 10;
        $this->assertTrue(worker_capacity::release_guard((int) $USER->id, 'try again', $released)['ok']);

        $this->rows(6, attempt_history::RECOVERED, 'submit_cdp_timeout', $released + 10);
        $this->rows(1, attempt_history::COLLECTED, '', $released + 10);
        $at = $released + 41;
        $guard = worker_capacity::effective($at)['guard'];
        $this->assertTrue($guard['throttled']);
        $this->assertSame($at + 2 * worker_capacity::GUARD_COOLDOWN, $guard['until']);
        $this->assertSame('retripped', worker_capacity::release_refusal($guard, $at + 60));
        $this->assertSame('retripped', worker_capacity::release_guard((int) $USER->id, 'again', $at + 60)['reason']);
        $this->assertSame(12, worker_capacity::effective($at + 60)['limit']);
        $events = $this->events();
        $this->assertTrue(end($events)[1]['afterrelease']);

        // Over: released by time once the failures have left the window, and a release is possible again.
        $over = max(worker_capacity::load_guard($at + 60)['until'], $released + 10 + worker_capacity::GUARD_WINDOW) + 31;
        $this->assertFalse(worker_capacity::effective($over)['guard']['throttled']);
        $this->assertSame(0, worker_capacity::effective($over)['guard']['noreleaseuntil']);
        // Past the probation, a new trip has the ordinary cooldown.
        $late = $released + worker_capacity::RELEASE_PROBATION + 3 * worker_capacity::GUARD_COOLDOWN;
        $this->rows(8, attempt_history::FAILED, 'submit_cdp_timeout', $late - 10);
        $guard = worker_capacity::effective($late)['guard'];
        $this->assertTrue($guard['throttled']);
        $this->assertSame($late + worker_capacity::GUARD_COOLDOWN, $guard['until']);
        $this->assertSame('', worker_capacity::release_refusal($guard, $late));
    }

    /**
     * The safety cap always wins, a release goes nowhere above it; workers above a limit drain, none is killed; the
     * guard switched off (minimum failures 0) ends a cooldown at once, which before 0.7.65 it did not.
     *
     * @return void
     */
    public function test_the_cap_wins_workers_drain_and_switching_off_ends_the_cooldown(): void {
        global $DB, $USER;
        set_config('worker_safety_cap', 10, 'local_catquizlab');
        foreach (range(1, 12) as $slot) {
            worker_registry::acquire_slot($slot, 'w-' . $slot);
            worker_registry::heartbeat('w-' . $slot);
        }
        $this->rows(30, attempt_history::FAILED, 'submit_cdp_timeout', $this->now - 60);
        $this->assertSame(5, worker_capacity::effective($this->now)['limit'], 'the cap of 10, halved');
        $this->assertSame(7, worker_capacity::drain($this->now), 'slots 6 to 12 asked to finish and stop');
        $this->assertSame(12, (int) $DB->count_records('local_catquizlab_worker'), 'none removed');

        $this->assertTrue(worker_capacity::release_guard((int) $USER->id, 'measured', $this->now + 10)['ok']);
        $this->assertSame(10, worker_capacity::effective($this->now + 60)['limit'], 'the cap, not the 25 configured');
        $this->assertFalse(worker_capacity::may_claim('w-11'), 'above the cap and asked to stop');

        // Throttled again, then the guard switched off: the cooldown ends now, said in the log.
        set_config('loadguard', '', 'local_catquizlab');
        $this->rows(30, attempt_history::FAILED, 'submit_cdp_timeout', $this->now + 100);
        $this->assertTrue(worker_capacity::effective($this->now + 130)['guard']['throttled']);
        set_config('loadguard_minfailures', 0, 'local_catquizlab');
        $off = worker_capacity::effective($this->now + 170);
        $this->assertFalse($off['guard']['throttled']);
        $this->assertSame(10, $off['limit']);
        $events = $this->events();
        $this->assertSame('guard switched off', end($events)[1]['by']);
    }

    /**
     * What a person deciding about it sees: one time for all figures, the counts and what the share is a share of, the
     * thresholds; the release offered to an administrator only.
     *
     * @return void
     */
    public function test_the_view_says_what_was_counted_and_offers_the_release_to_administrators_only(): void {
        $this->rows(37, attempt_history::RECOVERED, 'submit_cdp_timeout', time() - 60);
        $this->rows(10, attempt_history::COLLECTED, '', time() - 60);
        $snapshot = worker_capacity::snapshot();
        $view = $snapshot['guardview'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC$/', $snapshot['asof']);
        $this->assertTrue($view['throttled']);
        $this->assertStringContainsString('37', $view['counted']);
        $this->assertStringContainsString('10', $view['counted']);
        $this->assertStringContainsString('79 %', $view['counted']);
        $this->assertSame([['cause' => 'submit_cdp_timeout', 'n' => 37]], $view['causes']);
        $this->assertStringContainsString('12', $view['limits']);
        $this->assertStringContainsString('not the share of sittings', $view['denominator']);
        $this->assertTrue($view['canrelease']);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(worker_capacity::snapshot()['guardview']['canrelease']);
    }
}
