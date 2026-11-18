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
use local_catquizlab\local\experiment_status;
use local_catquizlab\local\experiment_stop;
use local_catquizlab\local\live_progress;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_figures;
use local_catquizlab\local\run_lifecycle;

/**
 * A stop is a decision, not a defect: the sittings it closes are cancelled, never failed (#166).
 *
 * On the live installation a stopped experiment read "1018 collected, 4982 failed for good" of 6000: every sitting
 * the stop closed was stored as failed. Now they are cancelled by an operator, counted apart in every figure, and the
 * technical failures from before the stop stay what they were.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\experiment_stop
 * @covers     \local_catquizlab\local\run_figures
 * @covers     \local_catquizlab\local\live_progress
 * @covers     \local_catquizlab\local\experiment_status
 * @covers     \local_catquizlab\local\run_lifecycle
 * @covers     \local_catquizlab\local\population_plan
 * @covers     \local_catquizlab\local\reproducibility
 * @covers     \local_catquizlab\local\run_registry
 */
final class operator_cancel_test extends \advanced_testcase {
    /** @var \local_catquizlab_generator */
    protected $generator;

    /**
     * Admin, generator, a site ready to run.
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
     * The setup wizard's assumption is the test's own: the next test class finds the site as it is.
     *
     * @return void
     */
    protected function tearDown(): void {
        \local_catquizlab\local\setup_wizard::assume_ready_for_testing(null);
        parent::tearDown();
    }

    /**
     * A run of an experiment with sittings in the given statuses, in one insert per status.
     *
     * @param int $experimentid The experiment.
     * @param int $status The run's status.
     * @param array $sittings Sitting status => how many.
     * @param array $fields Extra fields of the sittings of a status: status => fields.
     * @return int The run.
     */
    protected function run_with(int $experimentid, int $status, array $sittings, array $fields = []): int {
        global $DB;
        $runid = (int) $this->generator->create_run(['experimentid' => $experimentid, 'status' => $status])->id;
        $rows = [];
        $n = 0;
        foreach ($sittings as $sittingstatus => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = (object) array_merge([
                    'runid' => $runid, 'personid' => 900000 + $n++, 'status' => $sittingstatus,
                    'tries' => $sittingstatus === attempt_scheduler::STATUS_QUEUED ? 0 : 1, 'nextruntime' => 0,
                    'leaseowner' => $sittingstatus === attempt_scheduler::STATUS_RUNNING ? 'w-' . $runid : null,
                    'leaseexpires' => $sittingstatus === attempt_scheduler::STATUS_RUNNING ? time() + 600 : 0,
                    'lasterror' => null, 'timecreated' => time(), 'timemodified' => time(),
                ], (array) ($fields[$sittingstatus] ?? []));
            }
        }
        $DB->insert_records('local_catquizlab_attempt', $rows);

        return $runid;
    }

    /**
     * Collected, failed for good (a real failure before the stop), queued, in play: the stop closes the open ones as
     * cancelled, with its reason; the failure stays a failure with its own; every figure counts them apart, and the
     * classes add up to the plan.
     *
     * @return void
     */
    public function test_a_stop_cancels_what_was_open_and_keeps_real_failures(): void {
        global $DB;
        $e = (int) $this->generator->create_experiment()->id;
        $run = $this->run_with($e, registry::STATUS_RUNNING, [
            attempt_scheduler::STATUS_COLLECTED => 3,
            attempt_scheduler::STATUS_FAILED => 2,
            attempt_scheduler::STATUS_QUEUED => 4,
            attempt_scheduler::STATUS_RUNNING => 1,
        ], [attempt_scheduler::STATUS_FAILED => ['lasterror' => 'Navigation timeout of 30000 ms exceeded', 'tries' => 3]]);
        $playing = (int) $DB->get_field('local_catquizlab_attempt', 'id', ['runid' => $run,
            'status' => attempt_scheduler::STATUS_RUNNING]);

        $stopped = experiment_stop::stop($e);
        $this->assertSame([4, 1], [$stopped['queued'], $stopped['running']]);

        $count = static fn(int $status): int => $DB->count_records('local_catquizlab_attempt', ['runid' => $run,
            'status' => $status]);
        $this->assertSame([3, 2, 5, 0, 0], [$count(attempt_scheduler::STATUS_COLLECTED),
            $count(attempt_scheduler::STATUS_FAILED), $count(attempt_scheduler::STATUS_CANCELLED),
            $count(attempt_scheduler::STATUS_QUEUED), $count(attempt_scheduler::STATUS_RUNNING)]);
        $this->assertSame(['Navigation timeout of 30000 ms exceeded'], array_values(array_unique($DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'lasterror',
            'runid = ? AND status = ?',
            [$run, attempt_scheduler::STATUS_FAILED]
        ))), 'the real failures keep their reason');
        $this->assertSame([experiment_stop::REASON], array_values(array_unique($DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'lasterror',
            'runid = ? AND status = ?',
            [$run, attempt_scheduler::STATUS_CANCELLED]
        ))));
        $history = attempt_history::of_attempt($playing);
        $this->assertSame(attempt_history::CANCELLED, end($history)['outcome'], 'the sitting in play: cancelled, not given up');
        // Each one it closed has its line, with the reason as a code (AK1: typed, auditable, the same in every language).
        $this->assertSame(5, $DB->count_records('local_catquizlab_attemptlog', ['runid' => $run,
            'outcome' => attempt_history::CANCELLED, 'reasoncode' => experiment_stop::REASON]));

        // Every figure: disjoint classes that add up to the plan (AK3), the same in each.
        $figures = run_figures::read([$run], time())['runs'][$run];
        $this->assertSame([10, 3, 2, 5, 0], [$figures['total'], $figures['collected'], $figures['failed'],
            $figures['cancelled'], $figures['open']]);
        $this->assertSame($figures['total'], $figures['collected'] + $figures['failed'] + $figures['cancelled']
            + $figures['open']);
        $counts = run_lifecycle::attempt_counts($run);
        $this->assertSame([10, 3, 2, 5, 0], [$counts['total'], $counts['collected'], $counts['failed'],
            $counts['cancelled'], $counts['open']]);
        $snapshot = live_progress::snapshot($e);
        $this->assertSame([3, 2, 5], [$snapshot['now']['collected'], $snapshot['now']['failed'],
            $snapshot['now']['cancelled']]);
        $this->assertSame(5, $snapshot['runs'][0]['cancelled']);
        $status = experiment_status::snapshot($e, true);
        $this->assertSame('stopped', $status['phase']);
        $this->assertSame(2, $status['problems'], 'the two real failures are problems; the stop is not');
        $library = experiment_status::library([$e])[$e];
        $this->assertSame(['stopped', 2, 5], [$library['phase'], $library['failed'], $library['cancelled']]);
        $queue = attempt_scheduler::queue_breakdown($e);
        $this->assertSame([2, 5], [$queue['failed'], $queue['cancelled']]);
        $plan = \local_catquizlab\local\population_plan::actual($e);
        $this->assertSame([5, 3, 2], [$plan['cancelled'], $plan['collected'], $plan['failed']]);
        $this->assertSame(6, $plan['started'], 'started: collected, failed and the one in play; not the four queued');
        $repro = \local_catquizlab\local\reproducibility::completeness($e);
        $this->assertSame([2, 5], [$repro['attempts']['failed'], $repro['attempts']['cancelled']]);
        $this->assertSame([0, 1], [$repro['runs']['failed'], $repro['runs']['cancelled']], 'a stopped run is not failed');
        $registry = \local_catquizlab\local\run_registry::describe_many([$DB->get_record(
            'local_catquizlab_run',
            ['id' => $run]
        )])[$run];
        $this->assertSame(3, $registry['attemptsdone'], 'done: the collected ones (#141), not failed and cancelled');

        // A late report of the worker that held the sitting in play is kept, not applied: it stays cancelled.
        $late = \local_catquizlab\external\job_complete::execute($playing, 'collected', 0, 0, '', '', 'w-' . $run);
        $this->assertFalse($late['acknowledged']);
        $this->assertSame(attempt_scheduler::STATUS_CANCELLED, (int) $DB->get_field(
            'local_catquizlab_attempt',
            'status',
            ['id' => $playing]
        ));
    }

    /**
     * The live installation's case as a test (AK5): six runs, 6000 sittings; #86 997 collected and 3 open, #89 21
     * and 979, four runs untouched. Stopped: 1018 collected, 4982 cancelled, none failed.
     *
     * @return void
     */
    public function test_the_live_case_6000_sittings_1018_collected_4982_cancelled(): void {
        $e = (int) $this->generator->create_experiment()->id;
        $runs = [
            86 => $this->run_with($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_COLLECTED => 997,
                attempt_scheduler::STATUS_QUEUED => 3]),
            89 => $this->run_with($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_COLLECTED => 21,
                attempt_scheduler::STATUS_QUEUED => 977, attempt_scheduler::STATUS_RUNNING => 2]),
        ];
        foreach ([84, 85, 87, 88] as $label) {
            $runs[$label] = $this->run_with($e, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED => 1000]);
        }

        experiment_stop::stop($e);

        $figures = run_figures::read(array_values($runs), time())['runs'];
        $by = static fn(int $label): array => [$figures[$runs[$label]]['collected'], $figures[$runs[$label]]['cancelled'],
            $figures[$runs[$label]]['failed']];
        $this->assertSame([997, 3, 0], $by(86));
        $this->assertSame([21, 979, 0], $by(89));
        foreach ([84, 85, 87, 88] as $label) {
            $this->assertSame([0, 1000, 0], $by($label));
        }
        // The run registry counts the sittings by their own status (#141): 997 of 1000 done, not 1000; 0, not 1000.
        global $DB;
        $registry = \local_catquizlab\local\run_registry::describe_many(array_values($DB->get_records_list(
            'local_catquizlab_run',
            'id',
            [$runs[86], $runs[84]]
        )));
        $this->assertSame([997, 99, 0, 0], [$registry[$runs[86]]['attemptsdone'], $registry[$runs[86]]['progress'],
            $registry[$runs[84]]['attemptsdone'], $registry[$runs[84]]['progress']]);
        $snapshot = live_progress::snapshot($e);
        $this->assertSame([6000, 1018, 4982, 0], [$snapshot['total'], $snapshot['now']['collected'],
            $snapshot['now']['cancelled'], $snapshot['now']['failed']]);
        $this->assertSame('stopped', experiment_status::snapshot($e, true)['phase']);
        $this->assertSame(0, experiment_status::snapshot($e, true)['problems'], 'nothing "failed for good"');
        // Not "Finished, nothing left": stopped, with what the stop closed, and no end forecast.
        $time = $snapshot['time'];
        $this->assertSame([\local_catquizlab\local\eta::STOPPED, null], [$time['reliability'], $time['finish']]);
        $this->assertSame(get_string('eta:stopped', 'local_catquizlab'), $time['text']['status']);
        $this->assertSame(get_string('eta:stoppedleft', 'local_catquizlab', 4982), $time['text']['remaining']);
        $this->assertSame([\local_catquizlab\local\eta::STOPPED], array_unique(array_column($time['runs'], 'reliability')));
    }

    /**
     * One table of a research package, as rows by column.
     *
     * @param string $path The package.
     * @param string $table The table.
     * @return array{manifest: array, rows: array[]}
     */
    protected function package_table(string $path, string $table): array {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $handle = fopen('php://memory', 'w+b');
        fwrite($handle, (string) $zip->getFromName($manifest['tables'][$table]['file']));
        rewind($handle);
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];
        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $line);
        }
        fclose($handle);
        $zip->close();

        return ['manifest' => $manifest, 'rows' => $rows];
    }

    /**
     * #166 DoD-03, the export goldens: the live case stopped and then resumed, written as research package each time.
     * The runs table and the manifest carry the same disjoint sums as every page: stopped, 1018 collected, 0 failed,
     * 4982 cancelled of 6000, run by run as on the live installation; resumed, 0 cancelled and the 6000 still all there.
     * No sitting the stop closed appears as a result (validity, analysis population).
     *
     * @return void
     */
    public function test_the_live_case_exported_stopped_and_resumed(): void {
        global $DB;
        \local_catquizlab\local\setup_wizard::assume_ready_for_testing(true);
        $e = (int) $this->generator->create_experiment()->id;
        $runs = [
            86 => $this->run_with($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_COLLECTED => 997,
                attempt_scheduler::STATUS_QUEUED => 3]),
            89 => $this->run_with($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_COLLECTED => 21,
                attempt_scheduler::STATUS_QUEUED => 977, attempt_scheduler::STATUS_RUNNING => 2]),
        ];
        foreach ([84, 85, 87, 88] as $label) {
            $runs[$label] = $this->run_with($e, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED => 1000]);
        }
        $course = $this->getDataGenerator()->create_course();
        foreach ($runs as $runid) {
            $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
                'instance' => 6000 + $runid, 'section' => 0, 'added' => time()]);
            $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $runid]);
            \local_catquizlab\local\run_log::record($runid, \local_catquizlab\local\run_log::PROVISIONING_READY);
        }
        experiment_stop::stop($e);

        $path = make_request_directory() . '/stopped.zip';
        \local_catquizlab\local\research_package::write_file(['experimentid' => $e], $path);
        $stopped = $this->package_table($path, 'runs');
        $byrun = array_column($stopped['rows'], null, 'runid');
        $golden = [86 => [1000, 0, 3], 89 => [1000, 0, 979], 84 => [1000, 0, 1000], 85 => [1000, 0, 1000],
            87 => [1000, 0, 1000], 88 => [1000, 0, 1000]];
        foreach ($golden as $label => $expected) {
            $row = $byrun[$runs[$label]];
            $this->assertSame($expected, [(int) $row['sittings'], (int) $row['sittingsfailed'],
                (int) $row['sittingscancelled']], "run {$label}");
            $this->assertSame('cancelled', $row['status'], "run {$label}");
        }
        $sum = static fn(array $rows, string $column): int => array_sum(array_map('intval', array_column($rows, $column)));
        $this->assertSame([6000, 0, 4982], [$sum($stopped['rows'], 'sittings'), $sum($stopped['rows'], 'sittingsfailed'),
            $sum($stopped['rows'], 'sittingscancelled')]);
        $this->assertSame(4982, array_sum(array_column($stopped['manifest']['runs'], 'sittingscancelled')));
        $this->assertSame(0, array_sum(array_column($stopped['manifest']['runs'], 'sittingsfailed')));
        // Nothing the stop closed is a result.
        $cancelled = array_map('strval', $DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'id',
            'status = ?',
            [attempt_scheduler::STATUS_CANCELLED]
        ));
        foreach (['validity', 'analysis_population'] as $table) {
            $ids = array_column($this->package_table($path, $table)['rows'], 'attemptid');
            $this->assertSame([], array_values(array_intersect($ids, $cancelled)), $table);
        }

        $this->assertTrue(\local_catquizlab\local\experiment_resume::resume($e)['ok']);
        $path = make_request_directory() . '/resumed.zip';
        \local_catquizlab\local\research_package::write_file(['experimentid' => $e], $path);
        $resumed = $this->package_table($path, 'runs');
        $this->assertSame([6000, 0, 0], [$sum($resumed['rows'], 'sittings'), $sum($resumed['rows'], 'sittingsfailed'),
            $sum($resumed['rows'], 'sittingscancelled')]);
        $this->assertSame(['ready'], array_values(array_unique(array_column($resumed['rows'], 'status'))));
    }

    /**
     * #133 AK-CLARA-11/12, DoD-CLARA-04: the live case with what can go wrong before a stop — ten technical failures
     * with their retries spent, the circuit breaker holding that run, one sitting waiting for its retry — then the stop
     * and the resume. Every kind keeps its own name in status, history and figures: 10 technical failures, 4972 closed
     * by the stop, never "4982 failed"; the resume plays what the stop closed, leaves what had failed for good, and does
     * not release the breaker's pause behind anyone's back: the preview says the run stays paused, the result counts it.
     *
     * @return void
     */
    public function test_failures_and_a_breaker_pause_before_the_stop_keep_their_names_through_stop_and_resume(): void {
        global $DB;
        $e = (int) $this->generator->create_experiment()->id;
        $http5xx = '[submit:http_5xx] submit answer 3';
        $runs = [
            86 => $this->run_with($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_COLLECTED => 997,
                attempt_scheduler::STATUS_QUEUED => 3]),
            // 21 collected, 10 failed for good (retries spent), 967 queued (1 of them waiting for its retry), 2 in play.
            89 => $this->run_with(
                $e,
                registry::STATUS_RUNNING,
                [attempt_scheduler::STATUS_COLLECTED => 21,
                attempt_scheduler::STATUS_FAILED => \local_catquizlab\local\circuit_breaker::THRESHOLD,
                attempt_scheduler::STATUS_QUEUED => 967, attempt_scheduler::STATUS_RUNNING => 2],
                [attempt_scheduler::STATUS_FAILED => ['lasterror' => $http5xx, 'tries' => attempt_scheduler::MAX_TRIES]]
            ),
        ];
        foreach ([84, 85, 87, 88] as $label) {
            $runs[$label] = $this->run_with($e, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED => 1000]);
        }
        // Each run as the live ones: prepared to the end, with its activity (what resuming needs to let it go on).
        \local_catquizlab\local\setup_wizard::assume_ready_for_testing(true);
        $course = $this->getDataGenerator()->create_course();
        foreach ($runs as $runid) {
            $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
                'instance' => 5000 + $runid, 'section' => 0, 'added' => time()]);
            $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $runid]);
            \local_catquizlab\local\run_log::record($runid, \local_catquizlab\local\run_log::PROVISIONING_READY);
        }
        $failedids = $DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'id',
            'runid = ? AND status = ?',
            [$runs[89], attempt_scheduler::STATUS_FAILED]
        );
        foreach ($failedids as $id) {
            attempt_history::record((int) $id, attempt_history::FAILED, ['detail' => $http5xx,
                'tryno' => attempt_scheduler::MAX_TRIES]);
        }
        $retry = (int) $DB->get_field_select(
            'local_catquizlab_attempt',
            'MIN(id)',
            'runid = ? AND status = ?',
            [$runs[89], attempt_scheduler::STATUS_QUEUED]
        );
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $retry, 'tries' => 1,
            'nextruntime' => time() + 600, 'lasterror' => '[submit:connect_timeout] submit answer 2']);
        // The breaker trips on the ten and holds run 89: paused, not failed (#133).
        $this->assertTrue(\local_catquizlab\local\circuit_breaker::check($runs[89])['tripped']);
        $this->assertTrue(run_lifecycle::is_paused($runs[89]));
        $this->assertSame(registry::STATUS_RUNNING, (int) $DB->get_field(
            'local_catquizlab_run',
            'status',
            ['id' => $runs[89]]
        ));

        experiment_stop::stop($e);

        // Status: 1018 collected, 10 failed for good with their own reason, 4972 cancelled by the stop.
        $count = static fn(int $status): int => $DB->count_records_select(
            'local_catquizlab_attempt',
            'status = ? AND runid IN (' . implode(',', $runs) . ')',
            [$status]
        );
        $this->assertSame([1018, 10, 4972, 0, 0], [$count(attempt_scheduler::STATUS_COLLECTED),
            $count(attempt_scheduler::STATUS_FAILED), $count(attempt_scheduler::STATUS_CANCELLED),
            $count(attempt_scheduler::STATUS_QUEUED), $count(attempt_scheduler::STATUS_RUNNING)]);
        $this->assertSame([$http5xx], array_values(array_unique($DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'lasterror',
            'runid = ? AND status = ?',
            [$runs[89], attempt_scheduler::STATUS_FAILED]
        ))));
        // History: the stop's code on every sitting it closed, the failures' rows untouched and not doubled.
        $this->assertSame(4972, $DB->count_records_select(
            'local_catquizlab_attemptlog',
            'outcome = ? AND reasoncode = ? AND runid IN (' . implode(',', $runs) . ')',
            [attempt_history::CANCELLED, experiment_stop::REASON]
        ));
        $this->assertSame(10, $DB->count_records('local_catquizlab_attemptlog', ['runid' => $runs[89],
            'outcome' => attempt_history::FAILED]));
        // Figures and the operations view: 10 technical failures, not 4982; the stop is no problem.
        $snapshot = live_progress::snapshot($e);
        $this->assertSame([6000, 1018, 10, 4972], [$snapshot['total'], $snapshot['now']['collected'],
            $snapshot['now']['failed'], $snapshot['now']['cancelled']]);
        $status = experiment_status::snapshot($e, true);
        // Problems: the 10 failed sittings and the 1 run the breaker holds; the 4972 closed by the stop are none.
        $this->assertSame(['stopped', 11], [$status['phase'], $status['problems']]);
        $queue = attempt_scheduler::queue_breakdown($e);
        $this->assertSame([10, 4972], [$queue['failed'], $queue['cancelled']]);
        $figures = run_figures::read([$runs[89]], time())['runs'][$runs[89]];
        $this->assertSame([1000, 21, 10, 969], [$figures['total'], $figures['collected'], $figures['failed'],
            $figures['cancelled']]);

        // The preview: run 89 goes on and stays paused by the breaker, said in its row; the others simply go on.
        $preview = \local_catquizlab\local\experiment_resume::preview($e);
        $byrun = array_column($preview['runs'], null, 'runid');
        $this->assertSame(
            [\local_catquizlab\local\experiment_resume::RESUME, 969, 10],
            [$byrun[$runs[89]]['action'], $byrun[$runs[89]]['requeue'], $byrun[$runs[89]]['failed']]
        );
        $this->assertStringContainsString('HTTP 5xx', $byrun[$runs[89]]['held']);
        $this->assertSame('', $byrun[$runs[84]]['held']);
        $this->assertSame([1, 4972, 10], [$preview['totals']['held'], $preview['totals']['requeue'],
            $preview['totals']['failed']]);
        $html = \local_catquizlab\output\resume_view::preview_table($preview);
        $this->assertStringContainsString('data-held="1"', $html);
        $this->assertStringContainsString(s(get_string('resume:held', 'local_catquizlab', $byrun[$runs[89]]['held'])), $html);

        $resumed = \local_catquizlab\local\experiment_resume::resume($e);
        $this->assertTrue($resumed['ok']);
        $this->assertSame([6, 4972, 1], [$resumed['runs'], $resumed['requeued'], $resumed['held']]);
        // What had failed for good is not played again behind anyone's back (AK-CLARA-12).
        $this->assertSame(10, $count(attempt_scheduler::STATUS_FAILED));
        $this->assertSame([$http5xx], array_values(array_unique($DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'lasterror',
            'runid = ? AND status = ?',
            [$runs[89], attempt_scheduler::STATUS_FAILED]
        ))));
        $this->assertSame([4972, 0], [$count(attempt_scheduler::STATUS_QUEUED), $count(attempt_scheduler::STATUS_CANCELLED)]);
        // The sitting that had used a try keeps it: its tries go on counting.
        $this->assertSame(1, (int) $DB->get_field('local_catquizlab_attempt', 'tries', ['id' => $retry]));
        // The breaker's pause stays until a person releases it; its sittings are held, the other runs hand out work.
        $this->assertTrue(run_lifecycle::is_paused($runs[89]));
        $this->assertStringStartsWith(
            \local_catquizlab\local\circuit_breaker::PAUSE_REASON,
            run_lifecycle::pause_reason($runs[89])
        );
        $after = attempt_scheduler::queue_breakdown($e);
        $this->assertSame([969, 10], [$after['paused'], $after['failed']]);
        $this->assertTrue(\local_catquizlab\local\execution_queue::has_work($e));
    }

    /**
     * The upgrade to 2026111501 (AK5, DoD1): sittings a stop closed as failed before are cancelled now, recognised by
     * the stop's own text in a cancelled run, or by the pipeline stop's; a failure for any other reason, or with the
     * stop's text in a run that was not stopped, stays failed. Nothing else of the row changes.
     *
     * @return void
     */
    public function test_the_upgrade_reclassifies_only_what_a_stop_closed(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/catquizlab/db/upgrade.php');
        $e = (int) $this->generator->create_experiment()->id;
        [$en, $de] = experiment_stop::LEGACY_TEXTS;
        $stopped = $this->run_with($e, registry::STATUS_CANCELLED, [attempt_scheduler::STATUS_COLLECTED => 2]);
        $other = $this->run_with($e, registry::STATUS_FINISHED, [attempt_scheduler::STATUS_COLLECTED => 1]);
        $failed = static fn(int $runid, string $error): int => (int) $DB->insert_record(
            'local_catquizlab_attempt',
            (object) ['runid' => $runid, 'personid' => 1, 'status' => attempt_scheduler::STATUS_FAILED, 'tries' => 1,
            'nextruntime' => 0,
            'lasterror' => $error,
            'timecreated' => 1000,
            'timemodified' => 2000]
        );
        $a = $failed($stopped, $en);
        $b = $failed($stopped, $de);
        $c = $failed($stopped, 'Navigation timeout of 30000 ms exceeded');
        $d = $failed($other, $en);
        $p = $failed($other, \local_catquizlab\local\purger::LEGACY_PIPELINE_TEXTS[1]);
        $before = $DB->get_records('local_catquizlab_attempt', null, 'id ASC');

        $this->assertSame(3, local_catquizlab_upgrade_reclassify_cancelled());

        $after = $DB->get_records('local_catquizlab_attempt', null, 'id ASC');
        foreach ($before as $id => $row) {
            $expected = in_array($id, [$a, $b, $p], true) ? attempt_scheduler::STATUS_CANCELLED : (int) $row->status;
            $this->assertSame($expected, (int) $after[$id]->status, 'sitting ' . $id);
            $row->status = $after[$id]->status;
            $this->assertEquals($row, $after[$id], 'nothing else of sitting ' . $id . ' changed');
        }
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $after[$c]->status, 'a real failure stays one');
        $this->assertSame(attempt_scheduler::STATUS_FAILED, (int) $after[$d]->status, 'the text in a run not stopped');
        $this->assertSame(0, local_catquizlab_upgrade_reclassify_cancelled(), 'once');
    }

    /**
     * What a stop does not touch (DoD-02): a sitting waiting for its trace is collected as it would have been, a
     * validated one stays validated; only what was waiting or in play is closed.
     *
     * @return void
     */
    public function test_a_stop_leaves_trace_pending_and_validated_sittings_alone(): void {
        global $DB;
        $e = (int) $this->generator->create_experiment()->id;
        $run = $this->run_with($e, registry::STATUS_RUNNING, [
            attempt_scheduler::STATUS_TRACEPENDING => 1,
            attempt_scheduler::STATUS_VALIDATED => 1,
            attempt_scheduler::STATUS_QUEUED => 1,
        ]);
        experiment_stop::stop($e);
        $statuses = array_map('intval', $DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'status',
            'runid = ? ORDER BY status',
            [$run]
        ));
        $expected = [attempt_scheduler::STATUS_TRACEPENDING, attempt_scheduler::STATUS_VALIDATED,
            attempt_scheduler::STATUS_CANCELLED];
        sort($expected);
        $this->assertSame($expected, $statuses);
        $this->assertSame(1, $DB->count_records('local_catquizlab_attemptlog', ['runid' => $run,
            'outcome' => attempt_history::CANCELLED]));
    }

    /**
     * A run cancelled on its own (a tool's abort, the runs page): its open sittings are cancelled too, not left queued
     * in a run that hands out nothing, and not failed.
     *
     * @return void
     */
    public function test_a_single_run_cancelled_closes_its_sittings_as_cancelled(): void {
        global $DB;
        $e = (int) $this->generator->create_experiment()->id;
        $run = $this->run_with($e, registry::STATUS_READY, [attempt_scheduler::STATUS_QUEUED => 3,
            attempt_scheduler::STATUS_COLLECTED => 1]);
        $this->assertSame(['queued' => 3, 'running' => 0], experiment_stop::close_sittings([$run]));
        $this->assertSame(3, $DB->count_records('local_catquizlab_attempt', ['runid' => $run,
            'status' => attempt_scheduler::STATUS_CANCELLED]));
        $abort = $this->run_with($e, registry::STATUS_RUNNING, [attempt_scheduler::STATUS_QUEUED => 2]);
        $this->assertSame(2, attempt_scheduler::abort($abort));
        $this->assertSame(2, $DB->count_records('local_catquizlab_attempt', ['runid' => $abort,
            'status' => attempt_scheduler::STATUS_CANCELLED]));
        $this->assertSame([3, 2], [
            $DB->count_records('local_catquizlab_attemptlog', ['runid' => $run, 'outcome' => attempt_history::CANCELLED,
                'reasoncode' => experiment_stop::REASON]),
            $DB->count_records('local_catquizlab_attemptlog', ['runid' => $abort, 'outcome' => attempt_history::CANCELLED,
                'reasoncode' => 'aborted_by_operator']),
        ]);
        $this->assertSame(0, $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_FAILED]));
        $this->assertSame(
            get_string('attemptstatus:cancelled', 'local_catquizlab'),
            attempt_scheduler::status_label(attempt_scheduler::STATUS_CANCELLED)
        );
    }
}
