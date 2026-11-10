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
use local_catquizlab\local\registry;
use local_catquizlab\local\results_query;
use local_catquizlab\local\trace_reader;

/**
 * Long sittings do not exhaust a request's memory (#147).
 *
 * On the production server (MariaDB, 512 MB) the results page ran out of memory before its analysis basis. Traces
 * are copies of the engine's record of a sitting — forty kilobytes for twenty questions, megabytes for several
 * hundred — and they were read five hundred at a time, whatever their size. On MySQL and MariaDB Moodle's driver
 * also holds a whole result in PHP's memory before the first row is read. The benchmark of fifty thousand short
 * sittings (results_scale_test) never saw it: its traces are a kilobyte each.
 *
 * Here: two hundred sittings of a megabyte each, read by every path that reads traces in bulk. Each may use a small
 * fraction of what the traces amount to. Run on PostgreSQL and on MariaDB.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\trace_reader
 * @covers     \local_catquizlab\local\results_query
 */
final class results_memory_test extends \advanced_testcase {
    /** @var int Sittings. */
    protected const SITTINGS = 200;

    /** @var int Bytes of one trace, about. */
    protected const TRACE = 1048576;

    /**
     * A run of long sittings: about a megabyte of trace each, mostly the engine's progress record.
     *
     * @return int[] The experiment and its run.
     */
    protected function long_sittings(): array {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment(['strategy' => 'classic',
            'definition' => ['budgets' => ['global' => ['minitems' => 1, 'maxitems' => 700]]]]);
        $run = $generator->create_run(['experimentid' => $experiment->id]);
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $run->id]);
        $persons = [];
        for ($i = 0; $i < 20; $i++) {
            $persons[] = (int) $generator->create_person(['runid' => $run->id])->id;
        }

        $items = range(1, 600);
        // What the engine keeps per question: the abilities of every scale after it.
        $step = array_fill_keys(array_map(static fn(int $s): string => 'scale' . $s, range(1, 70)), 0.123456789);
        $progress = array_fill(0, 600, ['ability' => $step, 'questionid' => 1, 'fraction' => 1.0]);
        $template = [
            'finaltheta' => 0.5, 'finalse' => 0.3, 'items' => $items,
            'responses' => array_combine($items, array_fill(0, 600, 1.0)),
            'nitems' => 600, 'steps' => 600, 'stopreason' => 'maxitems', 'hoststopcode' => 'maximumquestions',
            'scaleabilities' => [], 'progress' => $progress,
        ];
        $json = json_encode($template);
        $this->assertGreaterThan(0.7 * self::TRACE, strlen($json), 'the trace is as long as intended');

        $rows = [];
        for ($i = 0; $i < self::SITTINGS; $i++) {
            $rows[] = (object) [
                'runid' => $run->id, 'personid' => $persons[$i % 20], 'status' => attempt_scheduler::STATUS_COLLECTED,
                'tries' => 1, 'runtimems' => 60000, 'timecreated' => time(), 'timemodified' => time(),
                // Half with their size stored, half as rows from before 0.7.53 without it.
                'tracejson' => $json, 'tracebytes' => $i % 2 ? strlen($json) : null,
            ];
            if (count($rows) === 10) {
                $DB->insert_records('local_catquizlab_attempt', $rows);
                $rows = [];
            }
        }

        return [(int) $experiment->id, (int) $run->id];
    }

    /**
     * Every bulk reader of traces stays far below the traces' total.
     *
     * @return void
     */
    public function test_long_sittings_are_read_in_bounded_batches(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!function_exists('memory_reset_peak_usage')) {
            $this->markTestSkipped('Needs PHP 8.2 or later to measure peak memory per step.');
        }
        results_query::reset_caches();
        trace_reader::reset();

        [$experimentid, $runid] = $this->long_sittings();
        $PAGE->set_url('/local/catquizlab/results.php');
        $filter = ['experimentid' => $experimentid];
        $total = self::SITTINGS * self::TRACE / 1048576;

        $steps = [
            'size check' => function () use ($filter): void {
                $size = (new results_query($filter))->size_check();
                $this->assertSame(self::SITTINGS, $size['count']);
                $this->assertGreaterThan(0.7 * self::SITTINGS * self::TRACE, $size['bytes'], 'stored and measured sizes');
            },
            'analysis basis' => function () use ($filter, $experimentid): void {
                (new \local_catquizlab\output\results_page(new results_query($filter), 'overview', $filter))
                    ->render_provenance($experimentid);
            },
            'overview' => function () use ($filter): void {
                (new \local_catquizlab\output\results_page(new results_query($filter), 'overview', $filter))->render_tab();
            },
            'answer matrix' => function () use ($runid): void {
                $this->assertCount(self::SITTINGS, \local_catquizlab\local\answer_matrix::build($runid)['rows']);
            },
            'subscale evaluation' => function () use ($runid): void {
                \local_catquizlab\local\subscale_evaluator::evaluate_run($runid);
            },
            'run contract' => function () use ($runid): void {
                $this->assertSame(self::SITTINGS, \local_catquizlab\local\run_contract::chain($runid)['sittings']);
            },
        ];
        foreach ($steps as $label => $step) {
            results_query::reset_caches();
            gc_collect_cycles();
            $before = memory_get_usage();
            memory_reset_peak_usage();
            $step();
            $used = (memory_get_peak_usage() - $before) / 1048576;
            fwrite(STDERR, sprintf(
                "  %3d sittings of %.1f MB  %-20s %6.1f MB\n",
                self::SITTINGS,
                self::TRACE / 1048576,
                $label,
                $used
            ));
            // A batch is at most 16 MB of trace; decoded, one long trace is a few times its text.
            $this->assertLessThan(96, $used, sprintf('%s used %.1f MB of %.0f MB of traces', $label, $used, $total));
        }
        $this->assertLessThanOrEqual(trace_reader::BYTES, trace_reader::figures()['largest'], 'no batch above the budget');
    }

    /**
     * Batches respect both bounds, and a trace larger than the budget is read alone.
     *
     * @return void
     */
    public function test_chunks_respect_the_byte_budget(): void {
        $this->assertSame([[1, 2], [3], [4], [5, 6]], trace_reader::chunks([1 => 4, 2 => 6, 3 => 9, 4 => 30, 5 => 1, 6 => 2], 10));
        $this->assertSame([], trace_reader::chunks([], 10));
        $this->assertSame([[7]], trace_reader::chunks([7 => 0], 10));
    }

    /**
     * Every sitting is read once, in id order, whatever the budget (#147).
     *
     * @return void
     */
    public function test_every_sitting_is_read_once(): void {
        global $DB;
        $this->resetAfterTest();
        [, $runid] = $this->long_sittings();
        foreach ([trace_reader::BYTES, 3 * self::TRACE, 1] as $budget) {
            trace_reader::reset();
            trace_reader::$budget = $budget;
            $seen = [];
            foreach (trace_reader::batches('a.runid = :runid', ['runid' => $runid], 'a.id, a.status') as $batch) {
                $seen = array_merge($seen, array_keys($batch));
            }
            $expected = array_keys($DB->get_records('local_catquizlab_attempt', ['runid' => $runid], 'id ASC', 'id'));
            $this->assertSame($expected, $seen, 'budget ' . $budget);
        }
        trace_reader::reset();
    }
}
