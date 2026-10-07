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
use local_catquizlab\local\status_report;
use local_catquizlab\local\worker_launcher;
use local_catquizlab\local\worker_registry;

/**
 * Workers begin one after another, retries are spread — and the interface says so (#120).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\worker_launcher
 * @covers     \local_catquizlab\local\worker_registry
 * @covers     \local_catquizlab\local\attempt_scheduler
 * @covers     \local_catquizlab\local\status_report
 */
final class load_spreading_test extends \advanced_testcase {
    /**
     * The start times: the interval apart, continuing after a worker still waiting.
     *
     * @return void
     */
    public function test_workers_are_planned_one_after_another(): void {
        $this->resetAfterTest();
        $now = 1790000000;

        $this->assertSame([$now, $now + 20, $now + 40, $now + 60], worker_launcher::plan_starts(4, 20, $now));
        // A second start while one still waits: after it, not beside it.
        $this->assertSame([$now + 70, $now + 90], worker_launcher::plan_starts(2, 20, $now, $now + 50));
        // The last one began long ago: the first begins now.
        $this->assertSame([$now, $now + 20], worker_launcher::plan_starts(2, 20, $now, $now - 600));
        // Switched off: all at once.
        $this->assertSame([$now, $now, $now], worker_launcher::plan_starts(3, 0, $now, $now + 50));
        $this->assertSame([], worker_launcher::plan_starts(0, 20, $now));

        // The setting, and its default.
        $this->assertSame(20, worker_launcher::start_stagger());
        set_config('worker_start_stagger', 45, 'local_catquizlab');
        $this->assertSame(45, worker_launcher::start_stagger());
        set_config('worker_start_stagger', -3, 'local_catquizlab');
        $this->assertSame(0, worker_launcher::start_stagger());

        // The worker is told how long to wait, and not told where it need not.
        $argv = worker_launcher::build_command(['baseurl' => 'x', 'token' => 'y', 'startdelayms' => 40000]);
        $this->assertContains('--start-delay=40000', $argv);
        $argv = worker_launcher::build_command(['baseurl' => 'x', 'token' => 'y', 'startdelayms' => 0]);
        $this->assertSame([], array_filter($argv, static fn(string $arg): bool => str_starts_with($arg, '--start-delay')));
    }

    /**
     * A waiting worker is known as one: in the registry, on its card, and for the run it will play.
     *
     * @return void
     */
    public function test_a_waiting_worker_says_when_it_begins(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertSame(0, worker_registry::last_planned_start());
        $this->assertNotNull(worker_registry::acquire_slot(1, 'pool-1'));
        $this->assertNotNull(worker_registry::acquire_slot(2, 'pool-2'));
        $begins = time() + 40;
        worker_registry::plan_start('pool-1', time());
        worker_registry::plan_start('pool-2', $begins);
        // As the worker reports while it waits.
        worker_registry::report('pool-1', 0, 'idle');
        worker_registry::report('pool-2', 0, 'waiting');

        $this->assertSame($begins, worker_registry::last_planned_start());
        $rows = [];
        foreach (worker_registry::live() as $row) {
            $rows[$row->workerid] = $row;
        }
        $this->assertSame(0, worker_registry::waits_for($rows['pool-1']));
        $this->assertEqualsWithDelta(40, worker_registry::waits_for($rows['pool-2']), 2);
        $this->assertSame(0, worker_registry::waits_for($rows['pool-2'], $begins + 1), 'begun');
        $summary = worker_registry::summary();
        $this->assertSame([2, 1], [$summary['live'], $summary['waiting']]);

        $card = status_report::worker($rows['pool-2']);
        $this->assertSame(status_report::GOOD, $card['level']);
        $this->assertSame('Waiting for its staggered start', $card['state']);
        $this->assertStringContainsString(worker_launcher::clock($begins), $card['reason']);
        $this->assertStringContainsString('20 s apart', $card['reason']);
        $this->assertNotSame('Waiting for its staggered start', status_report::worker($rows['pool-1'])['state']);

        // A worker that plays is not waiting, whatever its start time says.
        $playing = clone $rows['pool-2'];
        $playing->currentattempt = 7;
        $this->assertSame(0, worker_registry::waits_for($playing));
        // Nor is one that stopped.
        worker_registry::report('pool-2', 0, 'stopping');
        $this->assertSame(0, worker_registry::summary()['waiting']);
        $this->assertSame(0, worker_registry::last_planned_start() > time() ? 1 : 0, 'a stopped worker plans nothing');
    }

    /**
     * Pressing "start workers" says when the workers begin, not only that they were started.
     *
     * @return void
     */
    public function test_the_start_message_says_when_they_begin(): void {
        $this->resetAfterTest();
        $now = time();
        $plan = [
            ['workerid' => 'pool-1', 'startsat' => $now],
            ['workerid' => 'pool-2', 'startsat' => $now + 20],
            ['workerid' => 'pool-3', 'startsat' => $now + 40],
        ];

        $said = worker_launcher::explain(['launched' => 3, 'reason' => '', 'plan' => $plan], $now + 300);
        $this->assertSame(\core\output\notification::NOTIFY_SUCCESS, $said['type']);
        $this->assertStringContainsString('Started 3 worker(s) now', $said['message']);
        $this->assertStringContainsString('one after another, 20 s apart', $said['message']);
        $this->assertStringContainsString('the last at ' . worker_launcher::clock($now + 40), $said['message']);

        // One worker beginning now: nothing to add.
        $this->assertSame('', worker_launcher::stagger_message([['workerid' => 'pool-1', 'startsat' => $now]]));
        // One worker queued behind another that still waits.
        $this->assertStringContainsString(
            'It begins at ' . worker_launcher::clock($now + 60),
            worker_launcher::stagger_message([['workerid' => 'pool-4', 'startsat' => $now + 60]])
        );
        // Switched off: nothing to add.
        set_config('worker_start_stagger', 0, 'local_catquizlab');
        $this->assertSame('', worker_launcher::stagger_message($plan));
        set_config('worker_start_stagger', 20, 'local_catquizlab');

        // Every slot taken by workers still waiting: "already running" alone would mislead.
        worker_registry::acquire_slot(1, 'pool-1');
        worker_registry::plan_start('pool-1', $now + 90);
        worker_registry::report('pool-1', 0, 'waiting');
        $said = worker_launcher::explain(['launched' => 0, 'reason' => 'all-slots-busy'], $now + 300);
        $this->assertStringContainsString('1 of them still wait for their staggered start', $said['message']);
        $this->assertStringContainsString(worker_launcher::clock($now + 90), $said['message']);
    }

    /**
     * A retry waits its backoff plus a random share of the spread, and the history says which.
     *
     * @return void
     */
    public function test_retries_are_spread(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $attempts = [];
        for ($i = 0; $i < 40; $i++) {
            $person = $generator->create_person(['runid' => $run->id]);
            $attempts[] = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id,
                'personid' => $person->id, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
                'nextruntime' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        }

        set_config('retry_spread', 120, 'local_catquizlab');
        $before = time();
        foreach ($attempts as $attemptid) {
            $this->assertSame(attempt_scheduler::STATUS_QUEUED, attempt_scheduler::retry_or_fail($attemptid));
        }
        $after = time();
        $due = array_map('intval', $DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'nextruntime',
            'runid = :runid',
            ['runid' => $run->id]
        ));
        $backoff = attempt_scheduler::RETRY_BACKOFF;
        $this->assertGreaterThanOrEqual($before + $backoff, min($due), 'never before the backoff');
        $this->assertLessThanOrEqual($after + $backoff + 120, max($due), 'never beyond the spread');
        $this->assertGreaterThan(10, count(array_unique($due)), 'forty sittings failed together are not due together');

        // The history says how the wait is made up.
        $detail = (string) $DB->get_field_select(
            'local_catquizlab_attemptlog',
            'detail',
            'attemptid = :attemptid AND ' . $DB->sql_like('detail', ':like'),
            ['attemptid' => $attempts[0], 'like' => '%spread%'],
            IGNORE_MULTIPLE
        );
        $this->assertMatchesRegularExpression('/retry after \d+ s \(backoff ' . $backoff . ' s \+ spread \d+ s\)/', $detail);

        // The window the interface shows: how many, from when to when.
        $window = attempt_scheduler::retry_window();
        $this->assertSame([40, min($due), max($due)], [$window['n'], $window['first'], $window['last']]);
        $said = worker_launcher::retry_window_message();
        $this->assertStringContainsString('40 sitting(s) wait to be retried', $said);
        $this->assertStringContainsString(worker_launcher::clock(max($due)), $said);

        // Switched off: the backoff, exactly.
        set_config('retry_spread', 0, 'local_catquizlab');
        $this->assertSame(0, attempt_scheduler::retry_spread());
        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_RUNNING, ['id' => $attempts[0]]);
        $now = time();
        attempt_scheduler::retry_or_fail($attempts[0]);
        $this->assertEqualsWithDelta(
            $now + $backoff,
            (int) $DB->get_field('local_catquizlab_attempt', 'nextruntime', ['id' => $attempts[0]]),
            1
        );
    }

    /**
     * The page says how starts and retries are spread — and when they are not.
     *
     * @return void
     */
    public function test_the_interface_says_how_the_load_is_spread(): void {
        $this->resetAfterTest();

        $note = worker_launcher::load_spreading();
        $this->assertFalse($note['off']);
        $this->assertStringContainsString('20 s apart', $note['text']);
        $this->assertStringContainsString('0–300 s', $note['text']);

        set_config('retry_spread', 0, 'local_catquizlab');
        $this->assertStringContainsString('spread of retries is switched off', worker_launcher::load_spreading()['text']);
        set_config('worker_start_stagger', 0, 'local_catquizlab');
        $note = worker_launcher::load_spreading();
        $this->assertTrue($note['off']);
        $this->assertStringContainsString('switched off', $note['text']);
        set_config('retry_spread', 60, 'local_catquizlab');
        $this->assertStringContainsString('staggered start is switched off', worker_launcher::load_spreading()['text']);

        // Nothing waiting for a retry: nothing said.
        $this->assertSame('', worker_launcher::retry_window_message());
    }
}
