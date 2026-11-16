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

use local_catquizlab\external\job_claim;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\execution_queue;
use local_catquizlab\local\experiment_status;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_lifecycle;

/**
 * Claims follow the execution line (#163).
 *
 * The live installation of 15.11.2026: experiment 21 held the line since the evening before, its six runs ready with
 * fifty sittings each and none of them ever handed out, while all eleven workers played run 88 of experiment 19. The
 * claim read every ready run of the installation, oldest due time first — and a sitting never started has due time 0,
 * so experiment 19's thousand-odd sittings, queued earlier, came first for as long as there were any. The first fifty
 * candidates were 40 of run 88 and 10 of run 84; none of experiment 21.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\external\job_claim
 * @covers     \local_catquizlab\local\execution_queue
 */
final class claim_order_test extends \advanced_testcase {
    /** @var \local_catquizlab_generator The plugin's generator. */
    protected $generator;

    /**
     * Each test as the administrator, on a fresh database.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
    }

    /**
     * A run of an experiment, in a status.
     *
     * @param int $experimentid The experiment.
     * @param int $status The run status.
     * @param int $created When it was created.
     * @return int The run.
     */
    protected function run_of(int $experimentid, int $status, int $created): int {
        return (int) $this->generator->create_run(['experimentid' => $experimentid, 'status' => $status,
            'timecreated' => $created])->id;
    }

    /**
     * Sittings of a run, each with a person of its own.
     *
     * @param int $runid The run.
     * @param int $n How many.
     * @param int $created When they were queued.
     * @param array $fields Other fields: status, nextruntime, leaseowner, leaseexpires, tries.
     * @return int[] The sittings, in the order they were queued.
     */
    protected function sittings(int $runid, int $n, int $created, array $fields = []): array {
        global $DB;
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $person = $this->generator->create_person(['runid' => $runid]);
            $ids[] = (int) $DB->insert_record('local_catquizlab_attempt', (object) ($fields + [
                'runid' => $runid, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_QUEUED, 'tries' => 0,
                'nextruntime' => 0, 'timecreated' => $created, 'timemodified' => $created,
            ]));
        }

        return $ids;
    }

    /**
     * An entry of the execution line.
     *
     * @param int $experimentid The experiment.
     * @param string $state waiting or running.
     * @param int $position Its position.
     * @param int $since When it was queued and, running, started.
     * @return int The entry.
     */
    protected function line_entry(int $experimentid, string $state, int $position, int $since): int {
        global $DB;

        return (int) $DB->insert_record('local_catquizlab_execqueue', (object) ['experimentid' => $experimentid,
            'state' => $state, 'position' => $position, 'userid' => 2, 'timecreated' => $since,
            'timestarted' => $state === execution_queue::RUNNING ? $since : 0]);
    }

    /**
     * The installation of 15.11.2026, in small: experiment 19 started outside the line, earlier, with sittings in play,
     * and experiment 21 holding the line with six ready runs nobody had touched.
     *
     * @return array{e19: int, e21: int, run84: int, run86: int, run87: int, run88: int, runs21: int[], s21: int[],
     *      inplay: int[]}
     */
    protected function clara(): array {
        $yesterday = time() - DAYSECS;
        $e19 = (int) $this->generator->create_experiment(['name' => 'Experiment 19'])->id;
        $e21 = (int) $this->generator->create_experiment(['name' => 'Experiment 21'])->id;
        $run84 = $this->run_of($e19, registry::STATUS_RUNNING, $yesterday - 600);
        $run86 = $this->run_of($e19, registry::STATUS_RUNNING, $yesterday - 500);
        $run87 = $this->run_of($e19, registry::STATUS_RUNNING, $yesterday - 400);
        $run88 = $this->run_of($e19, registry::STATUS_RUNNING, $yesterday - 300);
        // Queued before anything of experiment 21, due time 0: what the old claim took first.
        $this->sittings($run84, 10, $yesterday - 600);
        $this->sittings($run86, 3, $yesterday - 500);
        $this->sittings($run87, 120, $yesterday - 400);
        $this->sittings($run88, 40, $yesterday - 300);
        // Eleven in play on run 88, each with a worker's lease.
        $inplay = $this->sittings($run88, 11, $yesterday - 300, ['status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
            'leaseowner' => 'catquizlab-exec-1', 'leaseexpires' => time() + 600]);

        $this->line_entry($e21, execution_queue::RUNNING, 1, $yesterday + 3600);
        $runs21 = [];
        $s21 = [];
        foreach (range(1, 6) as $i) {
            $runid = $this->run_of($e21, registry::STATUS_READY, $yesterday + 3600 + $i);
            $runs21[] = $runid;
            $s21 = array_merge($s21, $this->sittings($runid, 5, $yesterday + 3600 + $i));
        }

        return ['e19' => $e19, 'e21' => $e21, 'run84' => $run84, 'run86' => $run86, 'run87' => $run87, 'run88' => $run88,
            'runs21' => $runs21, 's21' => $s21, 'inplay' => $inplay];
    }

    /**
     * The installation of 15.11.2026: every claim goes to the holder, the experiment started outside the line gets a
     * place behind it, and what it has in play is left alone; then the line moves on by itself.
     *
     * @return void
     */
    public function test_claims_go_to_the_holder_of_the_line_and_never_to_older_runs_outside_it(): void {
        global $DB;
        $c = $this->clara();

        // What the claim before 0.7.65 read first: nextruntime, timecreated, id over every ready run.
        $old = array_map('intval', array_column($DB->get_records_sql(
            'SELECT a.id, a.runid FROM {local_catquizlab_attempt} a JOIN {local_catquizlab_run} r ON r.id = a.runid
              WHERE a.status = :queued AND r.status IN (:ready, :running)
           ORDER BY a.nextruntime ASC, a.timecreated ASC, a.id ASC',
            ['queued' => attempt_scheduler::STATUS_QUEUED, 'ready' => registry::STATUS_READY,
                'running' => registry::STATUS_RUNNING],
            0,
            50
        ), 'runid'));
        $this->assertCount(50, $old);
        $this->assertCount(0, array_intersect($old, $c['runs21']), 'the old order reached none of 21');

        // Thirty claims: all thirty sittings of experiment 21, in the order they were queued.
        $handed = [];
        for ($i = 0; $i < 30; $i++) {
            $claim = job_claim::execute('worker-' . ($i % 11));
            $this->assertTrue($claim['hasjob'], 'claim ' . $i);
            $this->assertSame($c['e21'], (int) $claim['experimentid']);
            $handed[] = (int) $claim['attemptid'];
        }
        $this->assertSame($c['s21'], $handed);

        // Nothing of 21 queued, its thirty in play: nothing is handed out, and the worker is asked to wait for them.
        $wait = job_claim::execute('worker-x');
        $this->assertFalse($wait['hasjob']);
        $this->assertSame(job_claim::IN_PLAY_WAIT, (int) $wait['nextdue']);
        $this->assertSame($c['e21'], execution_queue::holder());

        // Experiment 19 has a place behind it now, says why, and keeps what it has in play.
        $entry19 = $DB->get_record('local_catquizlab_execqueue', ['experimentid' => $c['e19']]);
        $this->assertSame(execution_queue::WAITING, $entry19->state);
        $this->assertSame(execution_queue::ADOPTED, $entry19->reason);
        foreach ($c['inplay'] as $id) {
            $sitting = $DB->get_record('local_catquizlab_attempt', ['id' => $id]);
            $this->assertSame(attempt_scheduler::STATUS_RUNNING, (int) $sitting->status);
            $this->assertSame('catquizlab-exec-1', $sitting->leaseowner);
        }

        // Collected: the next claim finds the holder through, the line moves on to 19, and 19's oldest sitting is next.
        [$insql, $params] = $DB->get_in_or_equal($c['s21']);
        $DB->set_field_select('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_COLLECTED, "id {$insql}", $params);
        $next = job_claim::execute('worker-x');
        $this->assertTrue($next['hasjob']);
        $this->assertSame($c['e19'], (int) $next['experimentid']);
        $this->assertSame($c['run84'], (int) $next['runid']);
        $entry21 = $DB->get_record('local_catquizlab_execqueue', ['experimentid' => $c['e21']]);
        $this->assertSame(execution_queue::FINISHED, $entry21->state);
        $this->assertSame(execution_queue::SITTINGS_DONE, $entry21->reason);
        $this->assertSame($c['e19'], execution_queue::holder());
        $this->assertSame(execution_queue::ADOPTED, $DB->get_field(
            'local_catquizlab_execqueue',
            'reason',
            ['experimentid' => $c['e19'], 'state' => execution_queue::RUNNING]
        ));
    }

    /**
     * The counts, the experiment's phase and the run cards say the same as the claim does.
     *
     * @return void
     */
    public function test_counts_phase_and_run_cards_say_what_the_claim_does(): void {
        $c = $this->clara();
        job_claim::execute('worker-a');
        // The line's move gives experiment 19 its place (the tick does this every minute).
        execution_queue::advance();

        $site = attempt_scheduler::queue_breakdown();
        $this->assertSame($c['e21'], $site['holder']);
        $this->assertSame(29, $site['claimable']);
        $this->assertSame(10 + 3 + 120 + 40, $site['waitingturn']);
        $this->assertSame(0, attempt_scheduler::queue_breakdown($c['e19'])['claimable']);

        experiment_status::reset();
        $status = experiment_status::snapshot($c['e19'], true);
        $this->assertSame('queued', $status['phase']);
        $this->assertStringContainsString('2', $status['reason']);
        $this->assertStringContainsString((string) $c['e21'], $status['reason']);
        $this->assertSame('queued', experiment_status::library([$c['e19']])[$c['e19']]['phase']);
        $this->assertNotSame('queued', experiment_status::snapshot($c['e21'], true)['phase']);

        $card = \local_catquizlab\local\status_report::run($c['run88']);
        $this->assertSame(get_string('report:runwindingdown', 'local_catquizlab'), $card['state']);
        $card = \local_catquizlab\local\status_report::run($c['run87']);
        $this->assertSame(get_string('report:runwaitingturn', 'local_catquizlab'), $card['state']);
    }

    /**
     * A retry keeps its place: it is not taken before its backoff is over, and once it is, before anything queued
     * after it. Before 0.7.65 a retry went behind every sitting never started (due time 0 sorted first).
     *
     * @return void
     */
    public function test_a_retry_keeps_its_place_and_waits_for_its_backoff(): void {
        global $DB;
        $experimentid = (int) $this->generator->create_experiment()->id;
        $runid = $this->run_of($experimentid, registry::STATUS_READY, time() - 600);
        [$retry] = $this->sittings($runid, 1, time() - 600, ['tries' => 1, 'nextruntime' => time() + 300]);
        [$second, $third] = $this->sittings($runid, 2, time() - 500);

        $this->assertSame($second, (int) job_claim::execute('worker-a')['attemptid'], 'the retry is not due yet');
        $DB->set_field('local_catquizlab_attempt', 'nextruntime', time() - 1, ['id' => $retry]);
        $this->assertSame($retry, (int) job_claim::execute('worker-a')['attemptid'], 'due now, and queued first');
        $this->assertSame($third, (int) job_claim::execute('worker-a')['attemptid']);
    }

    /**
     * Fifty sittings of a paused run first in the order do not hide the holder's other runs; the search goes on
     * without the paused run instead of answering "empty".
     *
     * @return void
     */
    public function test_sittings_of_a_paused_run_do_not_hide_the_others(): void {
        $experimentid = (int) $this->generator->create_experiment()->id;
        $paused = $this->run_of($experimentid, registry::STATUS_READY, time() - 900);
        $this->sittings($paused, 60, time() - 900);
        run_lifecycle::set_paused($paused, true);
        $open = $this->run_of($experimentid, registry::STATUS_READY, time() - 100);
        [$sitting] = $this->sittings($open, 1, time() - 100);

        $claim = job_claim::execute('worker-a');
        $this->assertTrue($claim['hasjob']);
        $this->assertSame($sitting, (int) $claim['attemptid']);

        // Only the paused run's sittings left: nothing to wait for until somebody resumes it.
        $none = job_claim::execute('worker-b');
        $this->assertFalse($none['hasjob']);
        $this->assertSame(job_claim::IN_PLAY_WAIT, (int) $none['nextdue'], 'one sitting is in play');
    }

    /**
     * An experiment that needs a person — its run held by the circuit breaker — leaves the line and is not taken back
     * while it does, whatever it still has queued; the next one goes on meanwhile. Released, it gets a place again.
     *
     * @return void
     */
    public function test_a_blocked_holder_leaves_the_line_and_comes_back_once_put_right(): void {
        global $DB;
        $first = (int) $this->generator->create_experiment(['name' => 'First'])->id;
        $second = (int) $this->generator->create_experiment(['name' => 'Second'])->id;
        $run1 = $this->run_of($first, registry::STATUS_READY, time() - 900);
        $this->sittings($run1, 3, time() - 900);
        $run2 = $this->run_of($second, registry::STATUS_READY, time() - 100);
        [$s2] = $this->sittings($run2, 1, time() - 100);
        $this->line_entry($first, execution_queue::RUNNING, 1, time() - 900);
        $this->line_entry($second, execution_queue::WAITING, 2, time() - 100);

        run_lifecycle::set_paused($run1, true, \local_catquizlab\local\circuit_breaker::PAUSE_REASON . ': test');
        $claim = job_claim::execute('worker-a');
        $this->assertSame($s2, (int) $claim['attemptid']);
        $this->assertSame('blocked', $DB->get_field(
            'local_catquizlab_execqueue',
            'reason',
            ['experimentid' => $first, 'state' => execution_queue::FINISHED]
        ));
        // Blocked: not taken back into the line, with three sittings queued.
        execution_queue::advance();
        $this->assertNull(execution_queue::state_of($first));
        $this->assertSame(3, attempt_scheduler::queue_breakdown($first)['paused']);

        // Released: the line gives it a place at its end.
        run_lifecycle::set_paused($run1, false);
        execution_queue::advance();
        $this->assertSame(execution_queue::WAITING, execution_queue::state_of($first));
        $this->assertSame([$second, $first], array_column(execution_queue::entries(), 'experimentid'));
    }

    /**
     * A tool takes the front openly and for a limited time; what it displaced waits at the front and goes on next;
     * a front whose time is up is given back by the line itself.
     *
     * @return void
     */
    public function test_a_tool_takes_the_front_openly_and_gives_it_back(): void {
        global $DB;
        $held = (int) $this->generator->create_experiment(['name' => 'Held'])->id;
        $behind = (int) $this->generator->create_experiment(['name' => 'Behind'])->id;
        $test = (int) $this->generator->create_experiment(['name' => 'Smoke'])->id;
        foreach ([$held => time() - 900, $behind => time() - 800, $test => time() - 10] as $experimentid => $created) {
            $this->sittings($this->run_of($experimentid, registry::STATUS_READY, $created), 2, $created);
        }
        $this->line_entry($held, execution_queue::RUNNING, 1, time() - 900);
        $this->line_entry($behind, execution_queue::WAITING, 2, time() - 800);

        $front = execution_queue::take_front($test, 'cli/smoke.php', 600);
        $this->assertTrue($front['ok']);
        $this->assertSame([$held], $front['preempted']);
        $this->assertSame($test, execution_queue::holder());
        $this->assertSame($test, (int) job_claim::execute('worker-a')['experimentid']);
        $entries = execution_queue::entries();
        $this->assertSame([$test, $held, $behind], array_column($entries, 'experimentid'));
        $this->assertStringContainsString('cli/smoke.php', $entries[0]['note']);
        $this->assertStringContainsString('cli/smoke.php', $entries[1]['note']);

        // Given back: the displaced one holds the line again before the one that was behind it.
        $this->assertTrue(execution_queue::release_front($test, 'test done'));
        $this->assertSame($held, (int) job_claim::execute('worker-a')['experimentid']);
        $this->assertSame('', (string) $DB->get_field(
            'local_catquizlab_execqueue',
            'reason',
            ['experimentid' => $held, 'state' => execution_queue::RUNNING]
        ));

        // A front whose time ran out: the line closes it, and the experiment, with sittings left, waits at the end.
        execution_queue::take_front($test, 'cli/smoke.php', 600);
        $this->assertSame($test, execution_queue::holder());
        $DB->set_field(
            'local_catquizlab_execqueue',
            'reason',
            execution_queue::FRONT . ':' . (time() - 1) . ':cli/smoke.php',
            ['experimentid' => $test, 'state' => execution_queue::RUNNING]
        );
        $this->assertSame($held, (int) job_claim::execute('worker-b')['experimentid']);
        $this->assertSame('front expired', $DB->get_field(
            'local_catquizlab_execqueue',
            'reason',
            ['experimentid' => $test, 'state' => execution_queue::CANCELLED]
        ));
        execution_queue::advance();
        $this->assertSame($held, execution_queue::holder());
        $this->assertSame(execution_queue::WAITING, execution_queue::state_of($test));
        $this->assertSame([$held, $behind, $test], array_column(execution_queue::entries(), 'experimentid'));
    }

    /**
     * A tool's front displaced by a second tool stays a front: it comes back with its own time limit when the second
     * gives the front back, its own tool can give it back while it waits, and its time runs out while it waits too.
     *
     * @return void
     */
    public function test_a_front_displaced_by_another_tool_keeps_its_time_limit(): void {
        global $DB;
        $held = (int) $this->generator->create_experiment(['name' => 'Held'])->id;
        $smoke = (int) $this->generator->create_experiment(['name' => 'Smoke'])->id;
        $race = (int) $this->generator->create_experiment(['name' => 'Race'])->id;
        // Enough sittings for every claim below: the held one is claimed from three times.
        foreach ([$held => time() - 900, $smoke => time() - 20, $race => time() - 10] as $experimentid => $created) {
            $this->sittings($this->run_of($experimentid, registry::STATUS_READY, $created), 5, $created);
        }
        $this->line_entry($held, execution_queue::RUNNING, 1, time() - 900);
        $reason = fn(int $e): string => (string) $DB->get_field_select(
            'local_catquizlab_execqueue',
            'reason',
            "experimentid = ? AND state IN ('running', 'waiting')",
            [$e]
        );

        execution_queue::take_front($smoke, 'cli/smoke.php', 600);
        $smokefront = $reason($smoke);
        $this->assertSame([$smoke], execution_queue::take_front($race, 'cli/claim_race.php', 600)['preempted']);
        $this->assertSame($race, execution_queue::holder());
        $this->assertStringStartsWith(execution_queue::PREEMPTED . ': cli/claim_race.php', $reason($smoke));
        $this->assertStringEndsWith($smokefront, $reason($smoke), 'the displaced front is kept');
        $entries = array_column(execution_queue::entries(), null, 'experimentid');
        $this->assertStringContainsString('cli/claim_race.php', $entries[$smoke]['note']);
        $this->assertStringNotContainsString('front:', $entries[$smoke]['note']);

        // The second tool done: the first is back at the front with its own time limit, the held one still behind.
        execution_queue::release_front($race, 'race done');
        $this->assertSame($smoke, (int) job_claim::execute('worker-a')['experimentid']);
        $this->assertSame($smokefront, $reason($smoke));
        // The second tool's experiment, with sittings left, waits at the end like any started outside the line.
        $this->assertSame([$smoke, $held, $race], array_column(execution_queue::entries(), 'experimentid'));
        // Its own tool gives it back: the held one goes on.
        $this->assertTrue(execution_queue::release_front($smoke, 'smoke done'));
        $this->assertSame($held, (int) job_claim::execute('worker-a')['experimentid']);

        // Given back while displaced: closed where it waits.
        execution_queue::take_front($smoke, 'cli/smoke.php', 600);
        execution_queue::take_front($race, 'cli/claim_race.php', 600);
        $this->assertTrue(execution_queue::release_front($smoke, 'smoke killed'));
        $this->assertNull(execution_queue::state_of($smoke));
        execution_queue::release_front($race, 'race done');
        $this->assertSame($held, (int) job_claim::execute('worker-b')['experimentid']);

        // Its time ran out while displaced: it holds nothing when the second tool is done.
        execution_queue::take_front($smoke, 'cli/smoke.php', 600);
        execution_queue::take_front($race, 'cli/claim_race.php', 600);
        $DB->set_field_select(
            'local_catquizlab_execqueue',
            'reason',
            execution_queue::PREEMPTED . ': cli/claim_race.php | '
            . execution_queue::FRONT . ':' . (time() - 1) . ':cli/smoke.php',
            "experimentid = ? AND state = 'waiting'",
            [$smoke]
        );
        execution_queue::release_front($race, 'race done');
        $this->assertSame($held, (int) job_claim::execute('worker-c')['experimentid']);
        $this->assertSame('front expired', $DB->get_field(
            'local_catquizlab_execqueue',
            'reason',
            ['experimentid' => $smoke, 'state' => execution_queue::CANCELLED]
        ));
    }

    /**
     * Nobody holds the line: the first claim gives it to the experiment at its front, or, with an empty line, to the
     * one started outside it whose sittings were queued first — never to one being prepared, which is the tick's.
     *
     * @return void
     */
    public function test_with_nobody_holding_the_line_a_claim_moves_it_and_nothing_else(): void {
        global $DB;
        $later = (int) $this->generator->create_experiment(['name' => 'Later'])->id;
        $earlier = (int) $this->generator->create_experiment(['name' => 'Earlier'])->id;
        $this->sittings($this->run_of($later, registry::STATUS_READY, time() - 100), 1, time() - 100);
        [$first] = $this->sittings($this->run_of($earlier, registry::STATUS_READY, time() - 900), 1, time() - 900);

        $this->assertSame($earlier, execution_queue::next_holder());
        $this->assertSame(1, attempt_scheduler::queue_breakdown()['claimable']);
        $claim = job_claim::execute('worker-a');
        $this->assertSame($first, (int) $claim['attemptid']);
        $this->assertSame([$earlier, $later], array_column(execution_queue::entries(), 'experimentid'));

        // A front that must be prepared first: a claim does not prepare it, and does not pass it either.
        $draft = (int) $this->generator->create_experiment(['name' => 'Draft'])->id;
        $DB->delete_records('local_catquizlab_execqueue');
        $this->line_entry($draft, execution_queue::WAITING, 1, time());
        $DB->set_field('local_catquizlab_attempt', 'status', attempt_scheduler::STATUS_COLLECTED, ['id' => $first]);
        $none = job_claim::execute('worker-a');
        $this->assertFalse($none['hasjob']);
        $this->assertSame(0, execution_queue::holder());
        $this->assertSame(execution_queue::WAITING, execution_queue::state_of($draft));
        $this->assertSame(0, $DB->count_records('task_adhoc', ['classname' => '\\local_catquizlab\\task\\prepare_experiment']));
    }

    /**
     * A test run on a busy installation leaves the other experiments' sittings exactly as they were — before, while
     * it plays, after it ends, and after it was killed — retries and their backoff included (#120 AK-20261111-SMOKE).
     *
     * Until 0.7.64 the smoke test deferred them for its duration and gave each its time back afterwards; a killed test
     * left them deferred until the next one. Now it takes the front of the line instead and touches none of them.
     *
     * @return void
     */
    public function test_a_test_run_leaves_the_others_sittings_as_they_were(): void {
        global $DB;
        $other = (int) $this->generator->create_experiment(['name' => 'Busy'])->id;
        $otherrun = $this->run_of($other, registry::STATUS_RUNNING, time() - 900);
        $this->sittings($otherrun, 3, time() - 900, ['tries' => 1, 'nextruntime' => time() + 240]);
        $this->sittings($otherrun, 2, time() - 900);
        $this->line_entry($other, execution_queue::RUNNING, 1, time() - 900);
        $snapshot = static fn(): array => array_values(array_map(
            static fn($r): array => (array) $r,
            $DB->get_records(
                'local_catquizlab_attempt',
                ['runid' => $otherrun],
                'id ASC',
                'id, status, nextruntime, tries, leaseowner, leaseexpires, timemodified'
            )
        ));
        $before = $snapshot();

        $test = (int) $this->generator->create_experiment(['name' => 'Smoke'])->id;
        $this->sittings($this->run_of($test, registry::STATUS_READY, time()), 2, time());
        $this->assertTrue(execution_queue::take_front($test, 'cli/smoke.php', 600)['ok']);
        $this->assertSame($test, (int) job_claim::execute('worker-a')['experimentid']);
        $this->assertSame($test, (int) job_claim::execute('worker-b')['experimentid']);
        $this->assertSame($before, $snapshot(), 'while it plays');
        $this->assertTrue(execution_queue::release_front($test, 'cli/smoke.php done'));
        $this->assertSame($before, $snapshot(), 'after it ended');

        // Killed instead: the front is never given back; its time runs out, and the line goes on by itself.
        $killed = (int) $this->generator->create_experiment(['name' => 'Killed smoke'])->id;
        $this->sittings($this->run_of($killed, registry::STATUS_READY, time()), 2, time());
        execution_queue::take_front($killed, 'cli/smoke.php', 600);
        job_claim::execute('worker-c');
        $DB->set_field(
            'local_catquizlab_execqueue',
            'reason',
            execution_queue::FRONT . ':' . (time() - 1) . ':cli/smoke.php',
            ['experimentid' => $killed, 'state' => execution_queue::RUNNING]
        );
        $next = job_claim::execute('worker-d');
        $this->assertSame($other, (int) $next['experimentid'], 'the line goes on with the one it displaced');
        $after = $snapshot();
        $claimed = (int) $next['attemptid'];
        $this->assertSame(
            array_values(array_filter($before, static fn(array $r): bool => (int) $r['id'] !== $claimed)),
            array_values(array_filter($after, static fn(array $r): bool => (int) $r['id'] !== $claimed)),
            'after it was killed: every sitting not handed out since is as it was, the retries\' backoff included'
        );
        $this->assertContains($claimed, array_map(static fn(array $r): int => (int) $r['id'], $before));
        $this->assertSame(
            0,
            (int) $before[array_search($claimed, array_map('intval', array_column($before, 'id')))]['tries'],
            'what was handed out is a sitting that was due, not a retry before its time'
        );
    }
}
