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
use local_catquizlab\local\log_view;
use local_catquizlab\local\request_trace;

/**
 * A request log of more than 100 MB beside more than 5000 lifecycle events (#176 DoD02, re-audit 27.11.2026).
 *
 * The file is real, its size measured on disk; the memory is the process' peak while reading (memory_reset_peak_usage),
 * not what is left at the end. The lifecycle export pages by cursor without a line twice or lost; it is bounded by the
 * lines of a page, whatever the requests beside them; the request view reads within its byte budget; no session key of
 * a request path reaches a line. A line written while an export runs is after its snapshot boundary ("until"), and a
 * continuation by cursor takes it once.
 *
 * About 20 to 40 seconds: the file is written line by line, as the server writes it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\log_view
 * @covers     \local_catquizlab\local\request_trace
 */
final class large_log_test extends \advanced_testcase {
    /** @var int The size the request log has to reach. */
    protected const BYTES = 100 * 1048576 + 1;

    /** @var int Lifecycle events beside it. */
    protected const EVENTS = 5200;

    /**
     * The process' peak memory while running $read, in MB.
     *
     * @param callable $read What to measure.
     * @return float
     */
    protected function peak(callable $read): float {
        gc_collect_cycles();
        $base = memory_get_usage();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $read();

        return round((memory_get_peak_usage() - $base) / 1048576, 1);
    }

    /**
     * The whole of it.
     *
     * @return void
     */
    public function test_a_large_log_is_read_bounded_paged_and_redacted(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        if (!function_exists('memory_reset_peak_usage')) {
            $this->markTestSkipped('Needs PHP 8.2: the peak is measured from a reset, not since the process began.');
        }
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $runid = (int) $generator->create_run()->id;
        $start = time() - 3600;

        // The lifecycle: 5200 history rows of 200 sittings, several in the same second (the cursor has to hold there).
        $rows = [];
        for ($i = 0; $i < self::EVENTS; $i++) {
            $rows[] = (object) ['attemptid' => 900000 + ($i % 200), 'runid' => $runid, 'tryno' => 1 + intdiv($i, 2600),
                'executionid' => 500000 + ($i % 200), 'outcome' => $i % 2 ? attempt_history::RECOVERED : attempt_history::BOUND,
                'detail' => 'event ' . $i, 'timecreated' => $start + intdiv($i, 4)];
        }
        $DB->insert_records('local_catquizlab_attemptlog', $rows);

        // The requests: one line as the server writes it, with a session key in its path, then repeated with its time,
        // sitting and execution moving on until the day file is past 100 MB.
        $dir = $CFG->dataroot . '/' . request_trace::DIRECTORY;
        @mkdir($dir, 0777, true);
        $template = request_trace::record(['correlationid' => 'c0ffee', 'attemptid' => 1, 'execution' => 1,
            'executionid' => 1, 'method' => 'POST',
            'path' => request_trace::redact_uri('/mod/adaptivequiz/attempt.php?cmid=5&sesskey=Ab12Cd34Ef'),
            'started' => (float) $start], 200, $start + 0.2, 0, 0, null, null);
        $this->assertStringNotContainsString('Ab12Cd34Ef', json_encode($template), 'redacted where it is written');
        $template['path'] .= str_repeat('&x=1', 40);
        $file = $dir . '/' . gmdate('Y-m-d', $start) . '.jsonl';
        $handle = fopen($file, 'ab');
        $n = 0;
        while (filesize($file) < self::BYTES) {
            $chunk = '';
            for ($k = 0; $k < 2000; $k++, $n++) {
                $ms = ($start * 1000) + $n;
                $line = ['time' => gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000),
                    'attemptid' => 900000 + ($n % 200), 'execution' => 1, 'executionid' => 500000 + ($n % 200)] + $template;
                $chunk .= json_encode($line, JSON_UNESCAPED_SLASHES) . "\n";
            }
            fwrite($handle, $chunk);
            fflush($handle);
            clearstatcache(true, $file);
        }
        fclose($handle);
        clearstatcache(true, $file);
        $bytes = filesize($file);
        $this->assertGreaterThan(100 * 1048576, $bytes, 'the file on disk is past 100 MB');

        // The lifecycle export, paged by cursor: every event, none twice, none lost; its memory the page's, not the file's.
        $paged = [];
        $after = '';
        $pages = 0;
        $peak = $this->peak(static function () use (&$paged, &$after, &$pages, $runid, $start): void {
            do {
                $page = log_view::stream_lifecycle(
                    ['since' => $start - 1, 'runid' => $runid, 'after' => $after],
                    static function (array $line) use (&$paged): void {
                        $paged[] = log_view::cursor_of($line);
                    },
                    1000
                );
                $after = (string) $page['nextcursor'];
                $pages++;
            } while ($page['nextcursor'] !== null && $pages < 20);
        });
        $this->assertCount(self::EVENTS, $paged);
        $this->assertCount(self::EVENTS, array_unique($paged), 'no line twice');
        $this->assertSame(6, $pages);
        $this->assertLessThan(64, $peak, "peak while exporting: {$peak} MB beside a log of " . round($bytes / 1048576) . ' MB');

        // The request view: within its byte budget, bounded by the page, no session key.
        $window = null;
        $requestpeak = $this->peak(static function () use (&$window, $start): void {
            $window = log_view::window(['since' => $start - 1, 'until' => $start + 3 * 3600, 'channel' => 'request'], 500);
        });
        $this->assertLessThanOrEqual(500, count($window['lines']));
        $this->assertNotEmpty($window['lines']);
        $this->assertLessThan(128, $requestpeak, "peak while reading the requests: {$requestpeak} MB");
        foreach ($window['lines'] as $line) {
            $this->assertStringNotContainsString('Ab12Cd34Ef', $line['text']);
        }
        // The whole file read (within the budget of request_trace::SCAN_BYTES), every line of it seen.
        $this->assertSame($bytes, (int) $window['scan']['requestbytes']);
        $this->assertSame($n, (int) $window['scan']['requestlines']);
        $this->assertFalse($window['scan']['partial']);

        // The snapshot boundary: what arrives while an export runs lies after its "until", and goes on by cursor once.
        $boundary = $start + intdiv(self::EVENTS, 4);
        $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => 900001, 'runid' => $runid, 'tryno' => 9,
            'executionid' => 777, 'outcome' => attempt_history::STARTED, 'detail' => 'arrived meanwhile',
            'timecreated' => $boundary + 5]);
        $snapshot = [];
        log_view::stream_lifecycle(
            ['since' => $start - 1, 'until' => $boundary, 'runid' => $runid],
            static function (array $line) use (&$snapshot): void {
                $snapshot[] = log_view::cursor_of($line);
            }
        );
        $this->assertSame($paged, $snapshot, 'the snapshot to its boundary is the export');
        $more = [];
        $end = log_view::stream_lifecycle(
            ['since' => $start - 1, 'runid' => $runid, 'after' => end($paged)],
            static function (array $line) use (&$more): void {
                $more[] = $line;
            }
        );
        $this->assertCount(1, $more);
        $this->assertSame([777, 'arrived meanwhile'], [$more[0]['executionid'], substr($more[0]['text'], -17)]);
        $this->assertNull($end['nextcursor']);
        @unlink($file);
        // The figures, for the record of the run (nachweise).
        fwrite(STDERR, sprintf("\n  large log: %.1f MB on disk, %d request lines, %d lifecycle events in %d pages; peak %.1f MB "
            . "exporting, %.1f MB reading requests\n", $bytes / 1048576, $n, self::EVENTS, $pages, $peak, $requestpeak));
    }
}
