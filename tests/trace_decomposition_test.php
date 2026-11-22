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

use local_catquizlab\local\request_trace;
use local_catquizlab\local\trace_decomposition;

/**
 * The request time taken apart from measured counters only (#164 AK02, DoD02): each part measured whole, or said not
 * to be — never estimated, never a pass on missing data.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\trace_decomposition
 * @covers     \local_catquizlab\local\request_trace
 */
final class trace_decomposition_test extends \advanced_testcase {
    /**
     * The trace INSERTs against the traces: every trace timed, some, or none.
     */
    public function test_persistence_is_measured_whole_or_not_at_all(): void {
        $inserts = [['requestid' => 'a', 'ms' => 1.5], ['requestid' => 'b', 'ms' => 2.5], ['requestid' => null, 'ms' => 9.0]];
        $whole = trace_decomposition::persistence(['a', 'b'], $inserts);
        $this->assertSame(trace_decomposition::MEASURED, $whole['status']);
        $this->assertSame(2, $whole['matched']);
        $this->assertSame(2.5, $whole['stats']['max']);
        $this->assertSame(2.0, $whole['stats']['mean']);

        $some = trace_decomposition::persistence(['a', 'b', 'c'], $inserts);
        $this->assertSame(trace_decomposition::INCOMPLETE, $some['status']);
        $this->assertSame(['c'], $some['unmatched']);

        $none = trace_decomposition::persistence(['a', 'b'], []);
        $this->assertSame(trace_decomposition::NOT_MEASURED, $none['status']);
        $this->assertStringContainsString('query log was off', $none['why']);
        $this->assertNull($none['stats']['p50']);

        $this->assertSame(trace_decomposition::NOT_MEASURED, trace_decomposition::persistence([], $inserts)['status']);
    }

    /**
     * The request id is read from the parameters the query log keeps of an engine trace INSERT.
     */
    public function test_the_request_id_of_a_logged_insert(): void {
        $spans = json_encode([['name' => 'request', 'depth' => 0, 'offsetms' => 0.0, 'ms' => null,
            'meta' => ['requestid' => 'r-7f3a', 'pid' => 12]]]);
        $logged = var_export(['adaptiveattemptid' => 5, 'spans' => $spans, 'timecreated' => 1], true);
        $this->assertSame('r-7f3a', trace_decomposition::requestid_of($logged));
        $this->assertNull(trace_decomposition::requestid_of(var_export(['spans' => '[]'], true)));
    }

    /**
     * A real INSERT of an engine trace, timed by Moodle's query log and found by its request id — and none without
     * the log.
     */
    public function test_a_logged_insert_is_found_and_timed(): void {
        global $DB;
        $this->resetAfterTest();
        if (!$DB->get_manager()->table_exists('local_catquiz_trace')) {
            $this->markTestSkipped('The engine is not installed.');
        }
        $now = time();
        $spans = json_encode([['name' => 'request', 'depth' => 0, 'offsetms' => 0.0, 'ms' => null,
            'meta' => ['requestid' => 'test-164']]]);
        $record = ['adaptiveattemptid' => 1, 'userid' => 2, 'kind' => 'next_item', 'questionnumber' => 2,
            'requestms' => 12.5, 'dbqueries' => 3, 'spans' => $spans, 'timecreated' => $now];
        $columns = $DB->get_columns('local_catquiz_trace');
        $record = array_intersect_key($record, $columns);

        // Without the query log: the trace is there, its INSERT is not timed.
        $DB->insert_record('local_catquiz_trace', (object) $record);
        $this->assertSame(['test-164'], trace_decomposition::trace_requestids($now - 1, $now + 1));
        $this->assertSame([], trace_decomposition::insert_times($now - 1, $now + 5));

        // With it (dboptions logall, as config.php sets it while the flag file exists): the INSERT timed by the database
        // layer, a row of log_queries of its own.
        $DB->delete_records('local_catquiz_trace');
        $property = new \ReflectionProperty($DB, 'dboptions');
        $options = $property->getValue($DB);
        $property->setValue($DB, ['logall' => true] + $options);
        try {
            $DB->insert_record('local_catquiz_trace', (object) $record);
        } finally {
            $property->setValue($DB, $options);
        }
        $times = trace_decomposition::insert_times($now - 1, time() + 5);
        $this->assertCount(1, $times);
        $this->assertSame('test-164', $times[0]['requestid']);
        $this->assertGreaterThan(0.0, $times[0]['ms']);
        $measured = trace_decomposition::persistence(trace_decomposition::trace_requestids($now - 1, $now + 1), $times);
        $this->assertSame(trace_decomposition::MEASURED, $measured['status']);
    }

    /**
     * The server's counters on every line, or the part is incomplete.
     */
    public function test_the_server_part(): void {
        $line = ['durationms' => 100, 'dbms' => 30.0, 'lockwaitms' => 20.0, 'lockheldms' => 90.0];
        $server = trace_decomposition::server([$line, $line]);
        $this->assertSame(trace_decomposition::MEASURED, $server['status']);
        $this->assertSame(50.0, $server['otherms']['p50']);
        $this->assertSame(30.0, $server['dbms']['p99']);

        $old = ['durationms' => 100];
        $this->assertSame(trace_decomposition::INCOMPLETE, trace_decomposition::server([$line, $old])['status']);
        $this->assertSame(trace_decomposition::NOT_MEASURED, trace_decomposition::server([])['status']);
    }

    /**
     * The client's time per sitting: its runtime less the union of its requests on the server — overlapping requests
     * counted once, the worker's web service calls not at all; a sitting with less runtime than server time is a
     * problem, not a negative wait.
     */
    public function test_the_client_part(): void {
        $this->assertSame(150.0, trace_decomposition::union([[0, 100], [50, 120], [200, 230]]));
        $this->assertSame(0.0, trace_decomposition::union([]));
        $lines = [
            ['attemptid' => 7, 'path' => '/mod/adaptivequiz/attempt.php', 'unixms' => 1000, 'durationms' => 100],
            ['attemptid' => 7, 'path' => '/theme/styles.php', 'unixms' => 1050, 'durationms' => 70],
            ['attemptid' => 7, 'path' => '/webservice/rest/server.php', 'unixms' => 5000, 'durationms' => 900],
            ['attemptid' => 8, 'path' => '/mod/adaptivequiz/attempt.php', 'unixms' => 1000, 'durationms' => 500],
        ];
        $client = trace_decomposition::client($lines, [7 => 1000]);
        $this->assertSame(trace_decomposition::MEASURED, $client['status']);
        $this->assertSame(1, $client['measured']);
        $this->assertSame(1, $client['withoutruntime']);
        $this->assertSame(880.0, $client['stats']['p50']);

        $short = trace_decomposition::client($lines, [7 => 1000, 8 => 300]);
        $this->assertSame(trace_decomposition::INCOMPLETE, $short['status']);
        $this->assertSame([8], $short['negative']);
        $this->assertSame(trace_decomposition::NOT_MEASURED, trace_decomposition::client($lines, [])['status']);
    }

    /**
     * Measured only when every part that applies was measured whole.
     */
    public function test_the_verdict(): void {
        $m = ['status' => trace_decomposition::MEASURED];
        $this->assertSame(
            trace_decomposition::MEASURED,
            trace_decomposition::verdict(['persistence' => $m, 'server' => $m, 'client' => $m])['verdict']
        );
        $this->assertSame(trace_decomposition::MEASURED, trace_decomposition::verdict(['persistence' =>
            ['status' => trace_decomposition::NOT_APPLICABLE], 'server' => $m, 'client' => $m])['verdict']);
        $off = trace_decomposition::verdict(['persistence' => ['status' => trace_decomposition::NOT_MEASURED,
            'why' => 'log off'], 'server' => $m, 'client' => $m]);
        $this->assertSame(trace_decomposition::NOT_MEASURED, $off['verdict']);
        $this->assertSame(['persistence: not_measured — log off'], $off['missing']);
        $this->assertSame(trace_decomposition::NOT_MEASURED, trace_decomposition::verdict([])['verdict']);
    }

    /**
     * The request line carries the edge counters: Moodle's own, read at the request's end.
     */
    public function test_the_request_line_carries_the_edge_counters(): void {
        global $DB, $PERF;
        $this->resetAfterTest();
        $DB->get_records('config', null, '', 'id', 0, 1);
        $PERF->sessionlock = ['wait' => 0.0125, 'held' => 0.25];
        $edge = request_trace::edge_figures();
        $this->assertSame(12.5, $edge['lockwaitms']);
        $this->assertSame(250.0, $edge['lockheldms']);
        $this->assertGreaterThan(0, $edge['dbreads']);
        $this->assertGreaterThanOrEqual(0.0, $edge['dbms']);
        $line = request_trace::record(['correlationid' => 'c', 'attemptid' => 1, 'execution' => 1, 'method' => 'GET',
            'path' => '/', 'started' => 1.0], 200, 1.5, 0, 1048576, null, null, $edge + ['other' => 1]);
        foreach (['dbms', 'dbreads', 'dbwrites', 'lockwaitms', 'lockheldms'] as $key) {
            $this->assertSame($edge[$key], $line[$key]);
        }
        $this->assertArrayNotHasKey('other', $line);
        // Nothing to read: nothing written, rather than a zero.
        $PERF->sessionlock = [];
        $this->assertArrayNotHasKey('lockwaitms', request_trace::edge_figures());
    }
}
