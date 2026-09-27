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
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

/**
 * The results pages at the size of a real experiment (#99).
 *
 * Memory and time are the assertions, not a side note: the evaluation failed
 * twice on a live installation with nine thousand sittings, each time as a
 * page that stopped after its tabs.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_query
 * @covers     \local_catquizlab\local\results_export
 */
final class results_scale_test extends \advanced_testcase {
    /**
     * The sizes, and what each may cost.
     *
     * Limits are for this test's work alone, measured from a reset peak, and
     * leave a Moodle page's own overhead room inside the default 128 MB.
     *
     * @return array[]
     */
    public static function sizes(): array {
        return [
            'nine thousand'  => [9000, 48, 20],
            'fifty thousand' => [50000, 64, 60],
        ];
    }

    /**
     * Overview, raw data, export count and CSV stay within bounds.
     *
     * @dataProvider sizes
     * @param int $n How many sittings.
     * @param int $megabytes The most any one of them may use.
     * @param int $seconds The most any one of them may take.
     * @return void
     */
    public function test_the_evaluation_is_bounded(int $n, int $megabytes, int $seconds): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        if (!function_exists('memory_reset_peak_usage')) {
            $this->markTestSkipped('Needs PHP 8.2 or later to measure peak memory per step.');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FINISHED, ['id' => $run->id]);

        $persons = [];
        for ($i = 0; $i < 50; $i++) {
            $persons[] = (int) $generator->create_person(['runid' => $run->id])->id;
        }

        // Sittings of thirty-five items and twelve subscales each: the item
        // and subscale levels multiply by those, which is where memory went.
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $items = range(1 + ($i % 50), 35 + ($i % 50));
            $rows[] = (object) [
                'runid' => $run->id, 'personid' => $persons[$i % 50],
                'status' => attempt_scheduler::STATUS_COLLECTED, 'tries' => 1,
                'runtimems' => 4000 + $i % 900, 'timecreated' => time(), 'timemodified' => time(),
                'tracejson' => json_encode([
                    'finaltheta' => sin($i), 'finalse' => 0.3 + ($i % 7) / 100, 'items' => $items,
                    'nitems' => 35, 'steps' => 35, 'stopreason' => 'se',
                    'scaleabilities' => array_fill(0, 12, 0.1),
                ]),
            ];
            if (count($rows) === 2500) {
                $DB->insert_records('local_catquizlab_attempt', $rows);
                $rows = [];
            }
        }
        if ($rows) {
            $DB->insert_records('local_catquizlab_attempt', $rows);
        }

        $PAGE->set_url('/local/catquizlab/results.php');
        $filter = ['experimentid' => (int) $run->experimentid];

        $steps = [
            'overview' => function () use ($filter): void {
                (new \local_catquizlab\output\results_page(new results_query($filter), 'overview', $filter))->render_tab();
            },
            'raw data' => function () use ($filter): void {
                (new \local_catquizlab\output\results_page(new results_query($filter), 'rawdata', $filter))->render_tab();
            },
            'export sizes' => function () use ($filter): void {
                results_export::row_count(new results_query($filter), results_export::LEVEL_ITEM);
            },
            'csv download' => function () use ($filter): void {
                // Discarded as it is written, as a browser would take it.
                ob_start(static fn(string $chunk): string => '', 8192);
                results_export::stream(new results_query($filter), results_export::LEVEL_ATTEMPT, 'csv');
                ob_end_clean();
            },
        ];

        foreach ($steps as $label => $step) {
            gc_collect_cycles();
            $before = memory_get_usage();
            memory_reset_peak_usage();
            $started = microtime(true);

            $step();

            $used = (memory_get_peak_usage() - $before) / 1048576;
            $took = microtime(true) - $started;

            $this->assertLessThan($megabytes, $used, sprintf('%s on %d sittings used %.1f MB', $label, $n, $used));
            $this->assertLessThan($seconds, $took, sprintf('%s on %d sittings took %.1f s', $label, $n, $took));
        }
    }
}
