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

use local_catquizlab\local\log_view;
use local_catquizlab\local\request_trace;

/**
 * The logs page reads request traces streamed, bounded and paged (#132).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\request_trace
 * @covers     \local_catquizlab\local\log_view
 */
final class log_view_bounds_test extends \advanced_testcase {
    /**
     * Write so many request lines, one per 10 ms, ending a minute ago.
     *
     * Straight to the day file, as a busy installation leaves it: write()
     * per line would make the test as slow as the traffic it imitates.
     *
     * @param int $n How many.
     * @param callable|null $status The status of line $i; 200 when not given.
     * @param int|null $now The moment the lines end a minute before; time() when not given.
     * @return void
     */
    protected function write_lines(int $n, ?callable $status = null, ?int $now = null): void {
        global $CFG;

        $dir = $CFG->dataroot . '/' . request_trace::DIRECTORY;
        @mkdir($dir, 0777, true);
        $start = ($now ?? time()) - 60 - $n / 100;
        $handles = [];
        for ($i = 0; $i < $n; $i++) {
            $line = request_trace::record(
                ['correlationid' => 'bulk', 'attemptid' => 0, 'execution' => 1, 'method' => 'POST',
                    'path' => '/mod/adaptivequiz/attempt.php?n=' . $i, 'started' => $start + $i / 100],
                $status ? $status($i) : 200,
                $start + $i / 100 + 0.05,
                0,
                0,
                null,
                null
            );
            $file = $dir . '/' . substr($line['time'], 0, 10) . '.jsonl';
            $handles[$file] ??= fopen($file, 'ab');
            fwrite($handles[$file], json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
        }
        array_map('fclose', $handles);
    }

    /**
     * The request lines of a page.
     *
     * @param array $window A window().
     * @return array[]
     */
    protected function requests(array $window): array {
        return array_values(array_filter($window['lines'], static fn(array $l): bool => $l['source'] === 'request'));
    }

    /**
     * read() hands back the newest lines only, however many the day holds.
     *
     * @return void
     */
    public function test_read_is_bounded_to_the_newest(): void {
        $this->resetAfterTest();
        $this->write_lines(1200);

        $read = request_trace::read(time() - HOURSECS, time(), 100);
        $this->assertCount(100, $read);
        $this->assertStringEndsWith('?n=1199', $read[99]['path'], 'the newest is the last');
        $this->assertStringEndsWith('?n=1100', $read[0]['path']);
        $this->assertCount(1200, iterator_to_array(request_trace::each(time() - HOURSECS, time()), false));
    }

    /**
     * Lines outside the window are passed over; a damaged line is skipped, not fatal.
     *
     * @return void
     */
    public function test_each_keeps_to_the_window(): void {
        global $CFG;
        $this->resetAfterTest();
        // One "now" for writing and reading: the lines are 100 per second, and
        // a second passing between the two moved the window by 100 lines (a
        // red run on a busy machine, 19.10.2026).
        $now = time();
        $this->write_lines(300, null, $now);
        $file = $CFG->dataroot . '/' . request_trace::DIRECTORY . '/' . gmdate('Y-m-d', $now - 60) . '.jsonl';
        file_put_contents($file, "not json\n{\"time\":\"broken\n", FILE_APPEND);

        // The first 100 lines are older than 2 s before the 101st.
        $lines = iterator_to_array(request_trace::each($now - 60 - 2, $now), false);
        $this->assertLessThanOrEqual(201, count($lines));
        $this->assertGreaterThanOrEqual(199, count($lines));
        foreach ($lines as $line) {
            $this->assertGreaterThanOrEqual(($now - 62) * 1000, $line['unixms']);
        }
    }

    /**
     * The newest page, the one before it, and the way between them.
     *
     * @return void
     */
    public function test_the_log_is_paged(): void {
        $this->resetAfterTest();
        $this->write_lines(250);
        $filter = ['since' => time() - HOURSECS, 'channel' => 'request'];

        $first = log_view::window($filter, 100, 0);
        $this->assertCount(100, $this->requests($first));
        $this->assertTrue($first['hasolder']);
        $this->assertFalse($first['hasnewer']);
        $this->assertStringEndsWith('?n=249 → 200 in 0.1 s, 0.0 MB', $this->requests($first)[99]['text']);

        $second = log_view::window($filter, 100, 1);
        $this->assertTrue($second['hasnewer']);
        $this->assertTrue($second['hasolder']);
        $this->assertStringContainsString('?n=149 ', $this->requests($second)[99]['text'], 'the lines just before page 1');

        $third = log_view::window($filter, 100, 2);
        $this->assertCount(50, $this->requests($third));
        $this->assertFalse($third['hasolder']);
        $this->assertStringContainsString('?n=0 ', $this->requests($third)[0]['text']);

        // Every line shown has its time stamp; nothing else is formatted.
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{3}$/', $this->requests($first)[0]['stamp']);
    }

    /**
     * A filter is applied before the lines are cut: an old match is found behind many newer misses.
     *
     * @return void
     */
    public function test_a_filter_reaches_behind_the_newest(): void {
        $this->resetAfterTest();
        // One failure, followed by 3000 ordinary requests.
        $this->write_lines(3001, static fn(int $i): int => $i === 0 ? 500 : 200);

        $errors = $this->requests(log_view::window(['since' => time() - HOURSECS, 'severity' => log_view::ERROR], 50));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('?n=0 → 500', $errors[0]['text']);

        $search = $this->requests(log_view::window(['since' => time() - HOURSECS, 'search' => '?n=5 →'], 50));
        $this->assertCount(1, $search);
    }

    /**
     * A day of a load test does not take the page's memory with it.
     *
     * 60 000 lines held as arrays took well over 100 MB; streamed, the
     * page's lines are all that stay.
     *
     * @return void
     */
    public function test_memory_stays_bounded(): void {
        $this->resetAfterTest();
        $this->write_lines(60000);
        if (!function_exists('memory_reset_peak_usage')) {
            $this->markTestSkipped('memory_reset_peak_usage() needs PHP 8.2.');
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();

        $window = log_view::window(['since' => time() - DAYSECS], log_view::PAGE);

        $this->assertCount(log_view::PAGE, $window['lines']);
        $this->assertLessThan(24 * 1048576, memory_get_peak_usage() - $before, 'bounded, not proportional to the file');
    }

    /**
     * A database source shows its newest rows, not its oldest 2000.
     *
     * @return void
     */
    public function test_a_busy_table_shows_its_newest_rows(): void {
        global $DB;
        $this->resetAfterTest();

        $rows = [];
        for ($i = 0; $i < log_view::SOURCE_ROWS + 300; $i++) {
            $rows[] = (object) ['channel' => 'service', 'action' => 'call' . $i, 'outcome' => 'ok',
                'timecreated' => time() - 3000 + $i, 'runid' => 0];
        }
        $DB->insert_records('local_catquizlab_debug', $rows);

        $lines = log_view::lines(['since' => time() - HOURSECS, 'channel' => 'service'], 10);
        $this->assertCount(10, $lines);
        $this->assertSame('call' . (log_view::SOURCE_ROWS + 299), end($lines)['text']);
    }

    /**
     * A byte budget reads the newest part and says how much was left out.
     *
     * @return void
     */
    public function test_a_scan_budget_keeps_the_newest_and_says_so(): void {
        $this->resetAfterTest();
        $this->write_lines(2000);

        $plan = request_trace::scan_plan(time() - HOURSECS, time(), 50000);
        $this->assertSame(50000, $plan['bytes']);
        $this->assertGreaterThan(0, $plan['skippedbytes']);
        $lines = iterator_to_array(request_trace::each(time() - HOURSECS, time(), $plan), false);
        $this->assertNotEmpty($lines);
        $this->assertLessThan(2000, count($lines));
        $this->assertStringEndsWith('?n=1999', end($lines)['path'], 'the newest are the ones read');
        // Every line read is whole: the cut through the first one is skipped, not parsed.
        foreach ($lines as $line) {
            $this->assertArrayHasKey('status', $line);
        }

        $whole = request_trace::scan_plan(time() - HOURSECS, time());
        $this->assertSame(0, $whole['skippedbytes']);
    }

    /**
     * A download names its filter, window, limit and whether it is cut.
     *
     * @return void
     */
    public function test_a_download_says_what_it_holds(): void {
        $this->resetAfterTest();
        $this->write_lines(30);
        $filter = ['since' => time() - HOURSECS, 'channel' => 'request', 'severity' => log_view::INFO];

        $json = json_decode(log_view::export($filter, 'json', 20), true);
        $this->assertSame(20, $json['limit']);
        $this->assertSame(20, $json['count']);
        $this->assertTrue($json['truncated'], 'thirty lines, twenty in the file');
        $this->assertFalse($json['partial']);
        $this->assertSame('request', $json['filter']['channel']);
        $this->assertNotNull($json['from']);
        $this->assertCount(20, $json['lines']);
        $this->assertArrayHasKey('seconds', $json['scan']);

        $text = log_view::export($filter, 'txt', 50);
        $this->assertStringContainsString("# truncated: false\n", $text);
        $this->assertStringContainsString("# limit: 50\n", $text);
        $this->assertSame(30, substr_count($text, ' request '));
    }

    /**
     * Filtering a large trace by run reads the database a fixed number of times, not once per line.
     *
     * @return void
     */
    public function test_filtering_by_run_does_not_query_per_line(): void {
        global $DB, $CFG;
        $this->resetAfterTest();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $ids = [];
        for ($i = 0; $i < 40; $i++) {
            $person = $generator->create_person(['runid' => $run->id]);
            $ids[] = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id,
                'personid' => $person->id, 'status' => 40, 'tries' => 1, 'timecreated' => time(), 'timemodified' => time()]);
        }
        $dir = $CFG->dataroot . '/' . request_trace::DIRECTORY;
        @mkdir($dir, 0777, true);
        $handle = fopen($dir . '/' . gmdate('Y-m-d') . '.jsonl', 'ab');
        for ($i = 0; $i < 4000; $i++) {
            fwrite($handle, json_encode(request_trace::record(
                ['correlationid' => 'q', 'attemptid' => $ids[$i % 40], 'execution' => 1, 'method' => 'GET',
                    'path' => '/x', 'started' => time() - 30 + $i / 1000],
                200,
                time() - 29,
                0,
                0,
                null,
                null
            )) . "\n");
        }
        fclose($handle);

        $before = $DB->perf_get_queries();
        $lines = log_view::lines(['since' => time() - HOURSECS, 'channel' => 'request', 'runid' => $run->id], 100);
        $queries = $DB->perf_get_queries() - $before;

        $this->assertCount(100, $lines);
        $this->assertSame((int) $run->id, $lines[0]['runid']);
        $this->assertLessThan(10, $queries, 'a fixed number of queries, not one per sitting or line');
    }
}
