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

/**
 * Progress and ETA in questions, on a fake clock (#141, #142).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\question_progress
 * @covers     \local_catquizlab\local\eta
 * @covers     \local_catquizlab\local\live_progress
 */
final class question_progress_test extends \advanced_testcase {
    /** @var int The fake clock's zero. */
    protected const T0 = 1800000000;

    /** @var int The experiment. */
    protected int $experimentid = 0;

    /** @var int Course-module instances handed out. */
    protected int $instance = 9000;

    /**
     * An experiment, and the engine's table.
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
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $this->experimentid = (int) $generator->create_experiment()->id;
    }

    /**
     * A run with a strategy, a pool and a test.
     *
     * @param string $strategy The strategy key.
     * @param int $pool Items in its pool.
     * @param int|null $maxitems The global maximum; null for the default, -1 for unlimited.
     * @return array{runid: int, instance: int}
     */
    protected function make_run(string $strategy, int $pool, ?int $maxitems = null): array {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $definition = ['strategy' => $strategy, 'budgets' => ['global' => $maxitems === null ? []
            : ['maxitems' => $maxitems === -1 ? 'unlimited' : $maxitems]]];
        $run = $generator->create_run(['experimentid' => $this->experimentid, 'manifestjson' => json_encode(['config' => [
            'strategy' => ['key' => $strategy], 'definition' => $definition]])]);
        $instance = $this->instance++;
        $course = $this->getDataGenerator()->create_course();
        $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
            'instance' => $instance, 'section' => 0, 'added' => time()]);
        $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $run->id]);
        $items = [];
        for ($i = 0; $i < $pool; $i++) {
            $items[] = (object) ['runid' => $run->id, 'poolid' => 0, 'questionid' => 0, 'itemname' => 'i' . $i,
                'model' => 'raschbirnbaum', 'truedifficulty' => 0, 'storeddifficulty' => 0, 'discrimination' => 1,
                'guessing' => 0, 'timecreated' => time()];
        }
        $DB->insert_records('local_catquizlab_item', $items);

        return ['runid' => (int) $run->id, 'instance' => $instance];
    }

    /**
     * A sitting: queued, being played with some questions answered, or collected.
     *
     * @param array $run From make_run().
     * @param int $status The sitting status.
     * @param int $answered Questions the engine has stored as answered.
     * @param int $runtime Seconds it took, for a collected one.
     * @param int $started Seconds after T0 it started.
     * @return array{attemptid: int, engine: int, userid: int}
     */
    protected function sitting(array $run, int $status, int $answered = 0, int $runtime = 0, int $started = 0): array {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $user = $this->getDataGenerator()->create_user();
        $person = $generator->create_person(['runid' => $run['runid'], 'moodleuserid' => $user->id]);
        $engine = 0;
        if ($status !== attempt_scheduler::STATUS_QUEUED) {
            $engine = $this->engine_attempt(
                $run,
                (int) $user->id,
                $answered,
                $status === attempt_scheduler::STATUS_RUNNING ? 'inprogress' : 'complete'
            );
        }
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run['runid'],
            'personid' => $person->id, 'status' => $status, 'tries' => 1,
            'engineattemptid' => $engine ?: null,
            'runtimems' => $runtime > 0 ? $runtime * 1000 : null,
            'leaseowner' => $status === attempt_scheduler::STATUS_RUNNING ? 'w-1' : null,
            'leaseexpires' => $status === attempt_scheduler::STATUS_RUNNING ? time() + 3600 : 0,
            'timecreated' => time(), 'timemodified' => time()]);
        if ($status !== attempt_scheduler::STATUS_QUEUED) {
            $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid,
                'runid' => $run['runid'], 'tryno' => 1, 'outcome' => attempt_history::STARTED,
                'timecreated' => self::T0 + $started]);
        }
        if ($status === attempt_scheduler::STATUS_COLLECTED) {
            $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $attemptid,
                'runid' => $run['runid'], 'tryno' => 1, 'outcome' => attempt_history::COLLECTED,
                'runtimems' => $runtime * 1000, 'engineattemptid' => $engine,
                'timecreated' => self::T0 + $started + $runtime]);
        }

        return ['attemptid' => $attemptid, 'engine' => $engine, 'userid' => (int) $user->id];
    }

    /**
     * An engine attempt.
     *
     * @param array $run From make_run().
     * @param int $userid The simulated user.
     * @param int $answered questionsattempted.
     * @param string $state attemptstate.
     * @return int Its id.
     */
    protected function engine_attempt(array $run, int $userid, int $answered, string $state): int {
        global $DB;

        return (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => $run['instance'],
            'userid' => $userid, 'uniqueid' => 0, 'attemptstate' => $state, 'attemptstopcriteria' => '',
            'questionsattempted' => $answered, 'timecreated' => time(), 'timemodified' => time()]);
    }

    /**
     * The snapshot, with its times recomputed on the fake clock.
     *
     * @param int $now Seconds after T0.
     * @return array
     */
    protected function snapshot(int $now): array {
        question_progress::reset_caches();
        $snapshot = live_progress::snapshot($this->experimentid);
        $snapshot['time'] = eta::estimate($this->experimentid, $snapshot, self::T0 + $now);

        return $snapshot;
    }

    /**
     * One CLASSIC sitting of 625 questions, six seconds a question: the bar and the forecast move with every
     * question, and the forecast is the actual remaining time (0, 1, 100, 624, 625).
     *
     * @return void
     */
    public function test_a_625_question_sitting_moves_question_by_question(): void {
        global $DB;

        $run = $this->make_run('classic', 625);
        $sitting = $this->sitting($run, attempt_scheduler::STATUS_RUNNING, 0);

        $expected = [0 => 0, 1 => 0, 100 => 16, 624 => 99];
        foreach ($expected as $answered => $percent) {
            $DB->set_field('adaptivequiz_attempt', 'questionsattempted', $answered, ['id' => $sitting['engine']]);
            $snapshot = $this->snapshot($answered * 6);
            $q = $snapshot['questions'];
            $this->assertSame($answered, $q['done'], "{$answered} answered");
            $this->assertSame(625, $q['expected'], 'a fixed form: the whole pool');
            $this->assertSame(question_progress::EXACT, $q['kind']);
            $this->assertSame($percent, $q['percent'], "{$answered} of 625");
            // The sitting bar does not move until the sitting is collected; that is why there is a second one.
            $this->assertSame(0, $snapshot['done']);

            $time = $snapshot['time'];
            if ($answered < eta::MIN_QUESTIONS) {
                $this->assertSame(eta::CALCULATING, $time['reliability'], "{$answered}: too few questions for a pace");
                continue;
            }
            // Forecast against what actually remains at six seconds a question.
            $this->assertSame(eta::PROVISIONAL, $time['reliability']);
            $this->assertEqualsWithDelta(6.0, $time['pace']['seconds'], 0.001);
            $this->assertSame((625 - $answered) * 6, $time['remaining'], "{$answered}: remaining seconds");
            $this->assertSame(self::T0 + 625 * 6, $time['finish'], "{$answered}: the finish does not move");
        }

        // 625: collected; the sitting and the questions are complete, and the times are the recorded ones.
        $DB->set_field('adaptivequiz_attempt', 'questionsattempted', 625, ['id' => $sitting['engine']]);
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $sitting['engine']]);
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $sitting['attemptid'],
            'status' => attempt_scheduler::STATUS_COLLECTED, 'engineattemptid' => $sitting['engine'],
            'runtimems' => 3750000, 'leaseowner' => null, 'leaseexpires' => 0]);
        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $sitting['attemptid'],
            'runid' => $run['runid'], 'tryno' => 1, 'outcome' => attempt_history::COLLECTED, 'runtimems' => 3750000,
            'timecreated' => self::T0 + 3750]);
        $snapshot = $this->snapshot(3750);
        $this->assertSame(625, $snapshot['questions']['done']);
        $this->assertSame(100, $snapshot['questions']['percent']);
        $this->assertSame(1, $snapshot['done']);
        $this->assertSame(eta::FINAL, $snapshot['time']['reliability']);
        $this->assertSame(0, $snapshot['time']['remaining']);
    }

    /**
     * A retry starts a new engine attempt: only the newest one of the person counts, never both.
     *
     * @return void
     */
    public function test_a_retry_is_not_counted_twice(): void {
        $run = $this->make_run('classic', 625);
        $sitting = $this->sitting($run, attempt_scheduler::STATUS_RUNNING, 300);
        // The first try was abandoned at 300; the second has 40 so far.
        $this->engine_attempt($run, $sitting['userid'], 40, 'inprogress');

        $snapshot = $this->snapshot(0);
        $this->assertSame(40, $snapshot['questions']['done']);
        $this->assertSame(625, $snapshot['questions']['expected']);
    }

    /**
     * Twenty adaptive questions and 625 fixed ones are not weighed alike: the experiment's figure is in questions.
     *
     * @return void
     */
    public function test_a_20_and_a_625_question_run_are_weighed_by_questions(): void {
        $classic = $this->make_run('classic', 625);
        $adaptive = $this->make_run('fastest', 100, 30);
        $this->sitting($classic, attempt_scheduler::STATUS_RUNNING, 100);
        for ($i = 0; $i < 3; $i++) {
            $this->sitting($adaptive, attempt_scheduler::STATUS_COLLECTED, 20, 60, $i * 60);
        }
        $this->sitting($adaptive, attempt_scheduler::STATUS_QUEUED);
        $this->sitting($adaptive, attempt_scheduler::STATUS_QUEUED);

        $snapshot = $this->snapshot(600);
        $runs = array_column($snapshot['runs'], 'questions', 'runid');
        $this->assertSame(question_progress::EXACT, $runs[$classic['runid']]['kind']);
        $this->assertSame(625, $runs[$classic['runid']]['expected']);
        $this->assertSame(question_progress::ESTIMATE, $runs[$adaptive['runid']]['kind']);
        $this->assertEquals(20.0, $runs[$adaptive['runid']]['persitting']);
        $this->assertSame(100, $runs[$adaptive['runid']]['expected'], '60 answered plus two sittings of about 20');

        // Sittings say 3 of 6 (50 %); questions say 160 of 725 (22 %). The sums agree with the rows.
        $this->assertSame(3, $snapshot['done']);
        $this->assertSame(160, $snapshot['questions']['done']);
        $this->assertSame(725, $snapshot['questions']['expected']);
        $this->assertSame(22, $snapshot['questions']['percent']);
        $this->assertSame(question_progress::ESTIMATE, $snapshot['questions']['kind'], 'the least certain run');
        $this->assertSame(
            array_sum(array_column(array_column($snapshot['runs'], 'questions'), 'done')),
            $snapshot['questions']['done']
        );
    }

    /**
     * An early CAT stop: the maximum is the bound until enough sittings show how many a sitting really has.
     *
     * @return void
     */
    public function test_an_early_stop_turns_the_bound_into_an_estimate(): void {
        $run = $this->make_run('fastest', 100, 30);
        $this->sitting($run, attempt_scheduler::STATUS_COLLECTED, 12, 30);
        for ($i = 0; $i < 3; $i++) {
            $this->sitting($run, attempt_scheduler::STATUS_QUEUED);
        }

        $q = $this->snapshot(60)['questions'];
        $this->assertSame(question_progress::BOUND, $q['kind']);
        $this->assertSame(12 + 3 * 30, $q['expected'], 'at most the maximum per sitting');
        $this->assertStringContainsString(get_string(
            'questions:donebound',
            'local_catquizlab',
            (object) ['done' => 12, 'expected' => 102, 'percent' => 11]
        ), $q['text']);

        $this->sitting($run, attempt_scheduler::STATUS_COLLECTED, 12, 30);
        $this->sitting($run, attempt_scheduler::STATUS_COLLECTED, 12, 30);
        $q = $this->snapshot(60)['questions'];
        $this->assertSame(question_progress::ESTIMATE, $q['kind']);
        $this->assertSame(36 + 3 * 12, $q['expected']);
    }

    /**
     * No maximum and no pool: the denominator is said to be unknown, never shown as a number.
     *
     * @return void
     */
    public function test_an_undeterminable_total_is_marked(): void {
        $run = $this->make_run('fastest', 0, -1);
        $this->sitting($run, attempt_scheduler::STATUS_RUNNING, 7);

        $q = $this->snapshot(0)['questions'];
        $this->assertSame(question_progress::UNKNOWN, $q['kind']);
        $this->assertNull($q['expected']);
        $this->assertFalse($q['hasexpected']);
        $this->assertSame(get_string('questions:doneunknown', 'local_catquizlab', (object) ['done' => 7,
            'expected' => '–', 'percent' => '–']), $q['text']);
    }

    /**
     * The question figures cost the same number of queries for two runs as for twelve.
     *
     * @return void
     */
    public function test_the_query_count_does_not_grow_with_runs(): void {
        global $DB;

        $cost = function (int $n) use ($DB): int {
            $runs = [];
            for ($i = 0; $i < $n; $i++) {
                $run = $this->make_run('fastest', 10, 20);
                $this->sitting($run, attempt_scheduler::STATUS_RUNNING, 3);
                $this->sitting($run, attempt_scheduler::STATUS_COLLECTED, 20, 30);
                $runs[$run['runid']] = ['inflight' => 1, 'queued' => 0];
            }
            question_progress::reset_caches();
            $before = $DB->perf_get_queries();
            question_progress::for_runs($runs);

            return $DB->perf_get_queries() - $before;
        };

        $two = $cost(2);
        $twelve = $cost(12);
        $this->assertSame($two, $twelve, "2 runs cost {$two} queries, 12 cost {$twelve}");
        $this->assertLessThanOrEqual(6, $twelve);
    }
}
