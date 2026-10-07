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

use local_catquizlab\local\debug_trace;
use local_catquizlab\local\log_view;
use local_catquizlab\local\request_trace;

/**
 * A line per worker request: what the access log knows, and what Moodle saw (#110).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\request_trace
 * @covers     \local_catquizlab\local\log_view
 */
final class request_trace_test extends \advanced_testcase {
    /** @var array A request as the worker makes it. */
    protected const REQUEST = [
        'correlationid' => 'c0ffee', 'attemptid' => 19084, 'execution' => 2,
        'method' => 'POST', 'path' => '/mod/adaptivequiz/attempt.php', 'started' => 1790000000.25,
    ];

    /**
     * Tidy up what begin() changed.
     *
     * @return void
     */
    protected function tearDown(): void {
        request_trace::reset();
        parent::tearDown();
    }

    /**
     * A failed request's line: status, duration, connection, the exception behind the page.
     *
     * @return void
     */
    public function test_a_line_says_what_the_access_log_cannot(): void {
        $exception = request_trace::describe(new \dml_write_exception('Deadlock found when trying to get lock', 'INSERT', []));
        $line = request_trace::record(self::REQUEST, 500, 1790000012.75, CONNECTION_ABORTED, 47 * 1048576, $exception, null);

        $this->assertSame('2026-09-21T14:13:20.250Z', $line['time']);
        $this->assertSame([500, 12500, 'aborted', 47.0], [$line['status'], $line['durationms'], $line['connection'],
            $line['peakmb']]);
        $this->assertSame(['c0ffee', 19084, 2], [$line['correlationid'], $line['attemptid'], $line['execution']]);
        $this->assertSame('dml_write_exception', $line['exception']['class']);
        $this->assertSame('dmlwriteexception', $line['exception']['errorcode']);
        $this->assertStringContainsString('Deadlock', $line['exception']['debuginfo']);
        $this->assertStringStartsWith('local/catquizlab/tests/', $line['exception']['file'], 'relative to the root');
        $this->assertNull($line['fatal']);

        // A PHP fatal error is one; a warning left as the last error is not.
        $fatal = request_trace::record(
            self::REQUEST,
            500,
            1790000001.0,
            0,
            0,
            null,
            ['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => '/x.php', 'line' => 3]
        );
        $this->assertSame('Allowed memory size exhausted', $fatal['fatal']['message']);
        $warning = request_trace::record(
            self::REQUEST,
            200,
            1790000001.0,
            0,
            0,
            null,
            ['type' => E_WARNING, 'message' => 'x', 'file' => '/x.php', 'line' => 3]
        );
        $this->assertNull($warning['fatal']);
        $this->assertSame('normal', $warning['connection']);
        $this->assertSame('timeout', request_trace::record(
            self::REQUEST,
            200,
            1790000001.0,
            CONNECTION_TIMEOUT,
            0,
            null,
            null
        )['connection']);
    }

    /**
     * Session keys and tokens do not reach the file.
     *
     * @return void
     */
    public function test_secrets_are_redacted_from_the_path(): void {
        $this->assertSame(
            '/mod/adaptivequiz/attempt.php?cmid=47&sesskey=[redacted]',
            request_trace::redact_uri('/mod/adaptivequiz/attempt.php?cmid=47&sesskey=zuNsFiLN')
        );
        $this->assertSame(
            '/webservice/rest/server.php?wstoken=[redacted]&wsfunction=x',
            request_trace::redact_uri('/webservice/rest/server.php?wstoken=abc123&wsfunction=x')
        );
    }

    /**
     * Only the worker's requests are traced, and they take its correlation id.
     *
     * @return void
     */
    public function test_only_requests_with_the_header_are_traced(): void {
        $this->assertFalse(request_trace::begin(['REQUEST_URI' => '/my/']), 'a real user\'s request');
        $this->assertFalse(request_trace::begin(['HTTP_X_CATQUIZLAB_CORRELATION' => 'no spaces; or quotes"']));

        $traced = request_trace::begin([
            'HTTP_X_CATQUIZLAB_CORRELATION' => 'abc123def', 'HTTP_X_CATQUIZLAB_ATTEMPT' => '19084.2',
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/mod/adaptivequiz/view.php?id=47',
        ]);
        restore_exception_handler();
        $this->assertTrue($traced);
        $this->assertSame('abc123def', debug_trace::correlation_id(), 'every entry of the request carries it');
    }

    /**
     * Lines are written per day, read back by time, and removed after the retention.
     *
     * @return void
     */
    public function test_lines_are_written_read_and_purged(): void {
        global $CFG;
        $this->resetAfterTest();

        $old = request_trace::record(['started' => time() - 40 * DAYSECS] + self::REQUEST, 200, time(), 0, 0, null, null);
        $new = request_trace::record(['started' => time() - 10] + self::REQUEST, 500, time(), 0, 0, null, null);
        request_trace::write($old);
        request_trace::write($new);

        $read = request_trace::read(time() - DAYSECS, time());
        $this->assertCount(1, $read);
        $this->assertSame(500, $read[0]['status']);

        $this->assertSame(1, request_trace::purge_older_than(30));
        $this->assertCount(1, glob($CFG->dataroot . '/' . request_trace::DIRECTORY . '/*.jsonl'));
    }

    /**
     * The logs page shows a failed request as an error, with its exception, and by sitting.
     *
     * @return void
     */
    public function test_the_logs_page_shows_the_requests(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id]);
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id,
            'personid' => $person->id, 'status' => 40, 'tries' => 2, 'timecreated' => time(), 'timemodified' => time()]);

        $exception = request_trace::describe(new \dml_write_exception('Lock wait timeout exceeded', 'UPDATE', []));
        request_trace::write(request_trace::record(
            ['started' => time() - 5, 'attemptid' => $attemptid] + self::REQUEST,
            500,
            time(),
            0,
            41943040,
            $exception,
            null
        ));
        request_trace::write(request_trace::record(
            ['started' => time() - 4, 'attemptid' => 999999] + self::REQUEST,
            200,
            time() - 3,
            0,
            0,
            null,
            null
        ));

        $lines = array_values(array_filter(
            log_view::lines(['attemptid' => $attemptid]),
            static fn(array $l): bool => $l['source'] === 'request'
        ));
        $this->assertCount(1, $lines, 'only the sitting asked for');
        $this->assertSame(log_view::ERROR, $lines[0]['severity']);
        $this->assertSame((int) $run->id, $lines[0]['runid']);
        $this->assertSame('c0ffee', $lines[0]['correlationid']);
        $this->assertStringContainsString('POST /mod/adaptivequiz/attempt.php → 500', $lines[0]['text']);
        $this->assertStringContainsString('dml_write_exception [dmlwriteexception]', $lines[0]['text']);
        $this->assertStringContainsString('Lock wait timeout exceeded', $lines[0]['text']);
    }

    /**
     * The plugin's hook is registered for every request's configuration.
     *
     * @return void
     */
    public function test_the_hook_is_registered(): void {
        $callbacks = \core\di::get(\core\hook\manager::class)->get_callbacks_for_hook(\core\hook\after_config::class);
        $ours = array_filter($callbacks, static fn(array $c): bool => ($c['component'] ?? '') === 'local_catquizlab');
        $this->assertNotEmpty($ours);
    }
}
