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
 * Executions correlated across requests and history, and a lifecycle export that is complete (#176).
 *
 * The export of CLARA run 103 held 4962 request lines of 5000; its request lines said attempt 0 and no worker, and
 * which execution a line belonged to was not in it. These tests hold the log to the execution each line belongs to
 * and the lifecycle export to every line, in a stable order, whatever the requests beside it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\log_view
 * @covers     \local_catquizlab\local\request_trace
 */
final class lifecycle_export_test extends \advanced_testcase {
    /**
     * Request lines written straight to the day file, as the trace leaves them.
     *
     * @param array[] $requests attemptid, execution, started (float seconds), path.
     * @return void
     */
    protected function write_requests(array $requests): void {
        global $CFG;

        $dir = $CFG->dataroot . '/' . request_trace::DIRECTORY;
        @mkdir($dir, 0777, true);
        foreach ($requests as $r) {
            $line = request_trace::record(['correlationid' => 'c', 'attemptid' => $r['attemptid'], 'execution' => $r['execution'],
                'method' => 'POST', 'path' => $r['path'], 'started' => $r['started']], 200, $r['started'] + 0.02, 0, 0, null, null);
            file_put_contents(
                $dir . '/' . substr($line['time'], 0, 10) . '.jsonl',
                json_encode($line, JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND
            );
        }
    }

    /**
     * The header the worker sends is read as sitting and execution; the old form without an execution is execution 0.
     *
     * @return void
     */
    public function test_the_header_names_sitting_and_execution(): void {
        $this->resetAfterTest();
        $server = ['HTTP_X_CATQUIZLAB_CORRELATION' => 'abc', 'HTTP_X_CATQUIZLAB_ATTEMPT' => '46794.2',
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x', 'REQUEST_TIME_FLOAT' => 1795749022.565];
        request_trace::reset();
        $this->assertTrue(request_trace::begin($server));
        // The handler begin() puts in front of Moodle's belongs to a request, not to the test.
        restore_exception_handler();
        $line = request_trace::record(
            (new \ReflectionProperty(request_trace::class, 'request'))->getValue(),
            200,
            1795749022.6,
            0,
            0,
            null,
            null
        );
        $this->assertSame([46794, 2], [$line['attemptid'], $line['execution']]);
        // UTC, with its milliseconds.
        $this->assertSame('2026-11-27T03:10:22.565Z', $line['time']);
        request_trace::reset();
        $this->assertTrue(request_trace::begin(['HTTP_X_CATQUIZLAB_ATTEMPT' => '46794'] + $server));
        restore_exception_handler();
        $old = (new \ReflectionProperty(request_trace::class, 'request'))->getValue();
        $this->assertSame([46794, 0], [$old['attemptid'], $old['execution']]);
        request_trace::reset();
    }

    /**
     * A request line names its execution and the worker that started it; first and second execution are told apart.
     *
     * @return void
     */
    public function test_requests_carry_their_execution_and_its_worker(): void {
        global $DB;
        $this->resetAfterTest();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id]);
        $sitting = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id,
            'personid' => $person->id, 'status' => 10, 'tries' => 2, 'timecreated' => time(), 'timemodified' => time()]);
        $now = time() - 120;
        foreach ([1 => 'w-first', 2 => 'w-second'] as $tryno => $worker) {
            $DB->insert_record('local_catquizlab_attemptlog', (object) ['attemptid' => $sitting, 'runid' => $run->id,
                'tryno' => $tryno, 'outcome' => attempt_history::STARTED, 'workerid' => $worker, 'timecreated' => $now]);
        }
        $this->write_requests([
            ['attemptid' => $sitting, 'execution' => 1, 'started' => $now + 1.001, 'path' => '/one'],
            ['attemptid' => $sitting, 'execution' => 2, 'started' => $now + 1.001, 'path' => '/two'],
        ]);

        $lines = log_view::lines(['since' => $now - 10, 'channel' => 'request'], 10);
        $byexecution = array_column($lines, 'workerid', 'execution');
        $this->assertSame(['1' => 'w-first', '2' => 'w-second'], array_map('strval', $byexecution));
        $this->assertSame((int) $run->id, $lines[0]['runid']);

        // Filtered by execution, and by worker: the worker of the execution, not a guess.
        $second = log_view::lines(['since' => $now - 10, 'channel' => 'request', 'execution' => 2], 10);
        $this->assertCount(1, $second);
        $this->assertStringContainsString('/two', $second[0]['text']);
        $first = log_view::lines(['since' => $now - 10, 'channel' => 'request', 'workerid' => 'w-first'], 10);
        $this->assertCount(1, $first);
        $this->assertStringContainsString('/one', $first[0]['text']);
    }

    /**
     * An execution's history row says where it started, what it confirmed, its budget and the server's last word.
     *
     * @return void
     */
    public function test_the_history_line_carries_the_progress(): void {
        $text = log_view::progress_text(json_encode(['progress' => ['baseline' => 264, 'baselinesource' => 'confirmed',
            'answered' => 1264, 'actions' => 1001, 'reconciliations' => 0, 'lastslot' => 1265, 'qubaid' => 4711,
            'budget' => 2500, 'budgetkind' => 'pool', 'serverend' => ['state' => 'inprogress', 'questionsattempted' => 1264,
                'slots' => 1265]]]));
        $this->assertSame(' baseline=264(confirmed) answered=1264 actions=1001 reconciliations=0 lastslot=1265 usage=4711'
            . ' budget=2500(pool) server=inprogress 1264/1265', $text);
        $this->assertSame('', log_view::progress_text(''));
        $this->assertSame('', log_view::progress_text('{"browser":{}}'));
    }

    /**
     * 6000 lifecycle lines beside 6000 requests: all of them, in order, paged by cursor without a line twice or lost,
     * many of them in the same second.
     *
     * @return void
     */
    public function test_the_lifecycle_export_is_complete_and_paged_by_cursor(): void {
        global $DB;
        $this->resetAfterTest();
        $start = time() - 3600;
        $rows = [];
        for ($i = 0; $i < 6000; $i++) {
            // Ten rows per second: the order within a second is the id's.
            $rows[] = (object) ['attemptid' => 1 + $i % 40, 'runid' => 7, 'tryno' => 1 + $i % 3,
                'outcome' => $i % 2 ? attempt_history::STARTED : attempt_history::FAILED, 'reasoncode' => 'navigation_error',
                'detail' => 'line ' . $i, 'timecreated' => $start + intdiv($i, 10)];
        }
        $DB->insert_records('local_catquizlab_attemptlog', $rows);
        for ($i = 0; $i < 300; $i++) {
            $DB->insert_record('local_catquizlab_runlog', (object) ['runid' => 7, 'attemptno' => 1, 'event' => 'stage',
                'timecreated' => $start + $i, 'timecreatedms' => ($start + $i) * 1000 + 500]);
        }
        $requests = [];
        for ($i = 0; $i < 6000; $i++) {
            $requests[] = ['attemptid' => 1, 'execution' => 1, 'started' => $start + $i / 10, 'path' => '/r' . $i];
        }
        $this->write_requests($requests);

        // Everything at once: 6300 lines, oldest first, none twice.
        $all = [];
        $before = memory_get_usage();
        $end = log_view::stream_lifecycle(['since' => $start - 1, 'runid' => 7], static function (array $line) use (&$all): void {
            $all[] = log_view::cursor_of($line);
        });
        $this->assertSame(6300, $end['count']);
        $this->assertNull($end['nextcursor']);
        $this->assertSame(count($all), count(array_unique($all)));
        $sorted = $all;
        usort($sorted, static function (string $a, string $b): int {
            [$ams, $aseq, $asrc] = explode(':', $a);
            [$bms, $bseq, $bsrc] = explode(':', $b);
            return (int) $ams <=> (int) $bms ?: (int) $aseq <=> (int) $bseq ?: strcmp($asrc, $bsrc);
        });
        $this->assertSame($sorted, $all, 'in the merged order');
        $this->assertLessThan(16 * 1048576, memory_get_usage() - $before, 'streamed, not held');

        // The same in pages of 1000 by cursor: the same lines, none twice, none lost.
        $paged = [];
        $after = '';
        $pages = 0;
        do {
            $page = log_view::stream_lifecycle(
                ['since' => $start - 1, 'runid' => 7, 'after' => $after],
                static function (array $line) use (&$paged): void {
                    $paged[] = log_view::cursor_of($line);
                },
                1000
            );
            $after = (string) $page['nextcursor'];
            $pages++;
        } while ($page['nextcursor'] !== null && $pages < 20);
        $this->assertSame(7, $pages);
        $this->assertSame($all, $paged);

        // The windowed view without requests: its lines are lifecycle lines only.
        $window = log_view::window(['since' => $start - 1, 'runid' => 7, 'lifecycleonly' => 1], 5000);
        $this->assertSame([], array_values(array_filter($window['lines'], static fn($l) => $l['source'] === 'request')));
        $meta = log_view::export_metadata(['since' => $start - 1, 'runid' => 7, 'lifecycleonly' => 1], $window);
        $this->assertTrue($meta['truncated'], '6300 lines do not fit in 5000');
        $this->assertFalse($meta['complete']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $start - 1), $meta['requested']['from']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', (string) $meta['covered']['first']);
    }
}
