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
use local_catquizlab\external\worker_heartbeat;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\run_log;
use local_catquizlab\local\worker_capacity;
use local_catquizlab\local\worker_launcher;
use local_catquizlab\local\worker_registry;

/**
 * Worker limit enforced by draining, safety cap, load guard, process uptime (#124).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\worker_capacity
 * @covers     \local_catquizlab\local\worker_launcher
 * @covers     \local_catquizlab\local\worker_registry
 * @covers     \local_catquizlab\external\job_claim
 * @covers     \local_catquizlab\external\worker_heartbeat
 */
final class worker_capacity_test extends \advanced_testcase {
    /**
     * Live workers in slots 1..$n, each playing nothing.
     *
     * @param int $n How many.
     * @return void
     */
    protected function live_workers(int $n): void {
        for ($slot = 1; $slot <= $n; $slot++) {
            worker_registry::acquire_slot($slot, 'w-' . $slot);
            worker_registry::heartbeat('w-' . $slot);
        }
    }

    /**
     * Installation-level log events, in order.
     *
     * @return string[]
     */
    protected function installation_events(): array {
        global $DB;

        return array_values($DB->get_fieldset_select('local_catquizlab_runlog', 'event', 'runid = 0', [], 'id ASC'));
    }

    /**
     * 60 → 50: the ten above drain, get no new claim, are told to stop; nothing new starts until they are gone.
     *
     * @return void
     */
    public function test_lowering_the_limit_drains_without_aborting(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('worker_concurrency', 60, 'local_catquizlab');
        $this->live_workers(60);
        $this->assertSame(0, worker_capacity::note_change(), 'nothing above 60');

        set_config('worker_concurrency', 50, 'local_catquizlab');
        $this->assertSame(10, worker_capacity::note_change());
        $this->assertSame([run_log::CAPACITY_CHANGED, run_log::WORKERS_DRAINING], $this->installation_events());
        $this->assertSame(0, worker_capacity::note_change(), 'idempotent: asked once');
        $this->assertCount(2, $this->installation_events());

        // The ten above: no new claim, a stop at their next heartbeat — after their sitting.
        $this->assertFalse(worker_capacity::may_claim('w-55'));
        $this->assertTrue(worker_capacity::may_claim('w-10'));
        $this->assertTrue(worker_heartbeat::execute('w-55', 0, 'working')['stop']);
        $this->assertFalse(worker_heartbeat::execute('w-10', 0, 'working')['stop']);
        $claim = job_claim::execute('w-55');
        $this->assertFalse($claim['hasjob']);
        $this->assertSame(get_string('job:draining', 'local_catquizlab'), $claim['message']);

        // The snapshot says so: 50 in force, 60 live, 10 draining, no free slot.
        $snapshot = worker_capacity::snapshot();
        $this->assertSame([50, 60, 10, 10, 0], [$snapshot['limit'], $snapshot['live'], $snapshot['draining'],
            $snapshot['above'], $snapshot['free']]);
        $this->assertStringContainsString('10 draining', $snapshot['text']['line']);

        // While they drain, nothing starts: 60 live against 50.
        $this->assertSame([], worker_launcher::slots_to_start(50, 50));
        // The ten finish and stop: still 50 live, still nothing.
        for ($slot = 51; $slot <= 60; $slot++) {
            worker_registry::record_stop('w-' . $slot, 'stop-requested');
        }
        $this->assertSame([], worker_launcher::slots_to_start(50, 50));
        $this->assertSame(50, count(worker_registry::live()), 'at most 50 after draining');
        // Five below the limit stop: five slots to fill, the lowest.
        for ($slot = 1; $slot <= 5; $slot++) {
            worker_registry::record_stop('w-' . $slot, 'queue-empty');
        }
        $this->assertSame([1, 2, 3, 4, 5], worker_launcher::slots_to_start(50, 50));

        // A restarted identity is a new process: not draining any more, uptime from now.
        $DB->set_field('local_catquizlab_worker', 'timecreated', time() - 86400, ['workerid' => 'w-3']);
        worker_registry::acquire_slot(3, 'w-3');
        $row = $DB->get_record('local_catquizlab_worker', ['workerid' => 'w-3']);
        $this->assertGreaterThanOrEqual(time() - 1, (int) $row->timestarted);
        $this->assertSame(0, (int) $row->stoprequested);
    }

    /**
     * The safety cap wins over a larger configured value, and drains like a lowered limit.
     *
     * @return void
     */
    public function test_the_safety_cap(): void {
        $this->resetAfterTest();

        set_config('worker_concurrency', 50, 'local_catquizlab');
        $this->live_workers(30);
        worker_capacity::note_change();
        set_config('worker_safety_cap', 20, 'local_catquizlab');

        $effective = worker_capacity::effective();
        $this->assertSame([20, 'cap'], [$effective['limit'], $effective['by']]);
        $this->assertSame(10, worker_capacity::note_change());
        $snapshot = worker_capacity::snapshot();
        $this->assertStringContainsString('safety cap of 20', $snapshot['text']['why']);
        // The configured value stays as it was saved.
        $this->assertSame('50', get_config('local_catquizlab', 'worker_concurrency'));
        $this->assertSame(20, worker_launcher::config_from_settings()['concurrency']);
    }

    /**
     * Rising capacity failures halve the limit for a cooldown, logged once; the release is logged too.
     *
     * @return void
     */
    public function test_the_load_guard(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('worker_concurrency', 40, 'local_catquizlab');
        $now = time();
        $row = static function (string $outcome, string $code, int $at) use ($DB): void {
            $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => 1, 'runid' => 1, 'tryno' => 1,
                'outcome' => $outcome, 'reasoncode' => $code, 'timecreated' => $at]);
        };
        // Below the threshold: four 5xx among twenty executions.
        for ($i = 0; $i < 16; $i++) {
            $row(attempt_history::COLLECTED, '', $now - 10);
        }
        for ($i = 0; $i < 4; $i++) {
            $row(attempt_history::FAILED, 'submit_http_5xx', $now - 10);
        }
        $this->assertFalse(worker_capacity::load_guard($now)['throttled']);

        // Three more, two of them recovered by reconciliation: 7 of 23 — throttled.
        $row(attempt_history::FAILED, 'submit_connect_timeout', $now - 5);
        $row(attempt_history::RECOVERED, 'submit_http_5xx', $now - 5);
        $row(attempt_history::RECOVERED, 'submit_connect_failed', $now - 5);
        // Within the 30 s cache: the old verdict.
        $this->assertFalse(worker_capacity::load_guard($now + 10)['throttled']);
        $guard = worker_capacity::load_guard($now + 31);
        $this->assertTrue($guard['throttled']);
        $this->assertSame(20, $guard['limit']);
        $this->assertSame(7, $guard['failures']);
        $effective = worker_capacity::effective($now + 31);
        $this->assertSame([20, 'loadguard'], [$effective['limit'], $effective['by']]);
        $this->assertSame([run_log::LOAD_THROTTLED], $this->installation_events());

        // Deterministic causes do not count: it is the server's load that is guarded.
        $this->assertNotContains('submit_http_404', array_keys($guard['causes']));

        // The window empties, the cooldown runs out: released, said once.
        $later = $now + 31 + worker_capacity::GUARD_COOLDOWN + 1;
        $this->assertFalse(worker_capacity::load_guard($later)['throttled']);
        $this->assertSame(40, worker_capacity::effective($later)['limit']);
        $this->assertSame([run_log::LOAD_THROTTLED, run_log::LOAD_RELEASED], $this->installation_events());
        worker_capacity::load_guard($later + 60);
        $this->assertCount(2, $this->installation_events());
    }

    /**
     * The installation's events are readable in the logs, as warnings where they hold something back.
     *
     * @return void
     */
    public function test_the_events_are_in_the_log(): void {
        $this->resetAfterTest();

        set_config('worker_concurrency', 4, 'local_catquizlab');
        $this->live_workers(4);
        worker_capacity::note_change();
        set_config('worker_concurrency', 2, 'local_catquizlab');
        worker_capacity::note_change();

        $lines = \local_catquizlab\local\log_view::lines(['since' => 0, 'channel' => 'lifecycle'], 10);
        $texts = array_column($lines, 'text');
        $this->assertNotEmpty(preg_grep('/^installation capacity_changed .*"from":4,"to":2/', $texts));
        $draining = array_values(array_filter($lines, static fn(array $l): bool => str_contains($l['text'], 'workers_draining')));
        $this->assertSame(\local_catquizlab\local\log_view::WARNING, $draining[0]['severity']);
    }
}
