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

use local_catquizlab\local\log_redactor;
use local_catquizlab\local\resource_guard;
use local_catquizlab\output\results_page;

/**
 * The fatal-error log line carries no query string, no secret and nothing that can start a line of its own (#149).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\resource_guard
 * @covers     \local_catquizlab\local\log_redactor
 */
final class fatal_log_redaction_test extends \advanced_testcase {
    /** @var array A memory exhaustion as error_get_last() reports it. */
    protected const EXHAUSTION = ['type' => E_ERROR,
        'message' => 'Allowed memory size of 536870912 bytes exhausted (tried to allocate 20480 bytes)'];

    /** @var string A results request with every kind of secret the audit names, encoded, repeated and in arrays. */
    protected const URI = '/local/catquizlab/results.php?experimentid=42&tab=rawdata'
        . '&sesskey=TESTSECRET&token=TESTSECRET&ses%73key=TESTSECRET&SESSKEY=TESTSECRET2&sesskey=TESTSECRET3'
        . '&wstoken[]=TESTSECRET&key=TESTSECRET&password=TESTSECRET&secret=TESTSECRET&authorization=TESTSECRET'
        . '&email=probe%40example.org&ses%2573key=TESTSECRET&filtername=Name%0D%0Alocal_catquizlab+memory:+forged';

    /**
     * Each test starts from a request without an id, context or phase.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        log_redactor::reset();
        resource_guard::reset();
    }

    /**
     * And leaves none behind.
     *
     * @return void
     */
    protected function tearDown(): void {
        log_redactor::reset();
        resource_guard::reset();
        parent::tearDown();
    }

    /**
     * DoD1: a simulated exhaustion with secrets in the URI — none of them in the line, and everything the
     * diagnosis of #147 needs still there.
     *
     * @return void
     */
    public function test_the_line_keeps_the_diagnosis_and_drops_every_secret(): void {
        resource_guard::context(static fn(): array => [
            'experimentid' => 42, 'tab' => 'rawdata', 'sittings' => 9000, 'traces' => '37 MB',
            'largestbatch' => '16 MB', 'read' => '120 MB',
        ]);
        resource_guard::phase('filter');
        resource_guard::phase('basis');

        $line = resource_guard::log_line(self::EXHAUSTION, ['REQUEST_URI' => self::URI]);

        foreach (['TESTSECRET', 'probe', 'example.org', '%40', '@', 'forged', 'filtername', '?', "\r", "\n"] as $absent) {
            $this->assertStringNotContainsString($absent, $line, 'Found "' . addcslashes($absent, "\r\n") . '"');
        }
        foreach (
            [' path=/local/catquizlab/results.php ', ' experimentid=42 ', ' tab=rawdata ', ' phase=basis ',
                ' limit=512MB ', ' sittings=9000 ', ' traces=37MB ', ' largestbatch=16MB ', ' read=120MB'] as $present
        ) {
            $this->assertStringContainsString($present, $line . ' ');
        }
        $this->assertMatchesRegularExpression('/ peak=\d+MB /', $line);
        $this->assertMatchesRegularExpression('/ phases=start=\d+MB,filter=\d+MB /', $line);
        $this->assertMatchesRegularExpression('/^local_catquizlab memory: rid=[0-9a-f]{16} ridsource=local /', $line);
    }

    /**
     * DoD2: what the page itself adds is typed — a forged tab, a number with letters, a filter value with a line
     * break, an address: dropped and counted, never written.
     *
     * @return void
     */
    public function test_only_allowed_and_typed_context_reaches_the_line(): void {
        resource_guard::context(static fn(): array => [
            'experimentid' => '42abc',
            'tab' => "rawdata\r\nlocal_catquizlab memory: forged",
            'traces' => "37 MB\n",
            'sittings' => ['9000'],
            'filtername' => 'Name',
            'email' => 'probe@example.org',
            'runid' => '17',
        ]);

        $line = resource_guard::log_line(self::EXHAUSTION, ['REQUEST_URI' => '/local/catquizlab/results.php']);

        $this->assertStringNotContainsString('experimentid=', $line);
        $this->assertStringNotContainsString('tab=', $line);
        $this->assertStringNotContainsString('traces=', $line);
        $this->assertStringNotContainsString('forged', $line);
        $this->assertStringNotContainsString('probe', $line);
        $this->assertStringContainsString(' runid=17 ', $line . ' ');
        $this->assertStringContainsString(' dropped=6', $line);
        $this->assertSame(1, substr_count($line, 'local_catquizlab memory:'));
    }

    /**
     * DoD2: negative cases of the URI itself — a line break, a terminal escape, a slash argument, a very long path,
     * and a phase nobody declared.
     *
     * @return void
     */
    public function test_the_uri_cannot_break_or_flood_the_line(): void {
        $cases = [
            "/local/catquizlab/results.php\r\nlocal_catquizlab memory: forged?x=1" => '/local/catquizlab/results.php',
            "/local/catquizlab/results.php/sesskey/TESTSECRET?tab=x" => '/local/catquizlab/results.php',
            "/local/catquizlab/\x1b[31mresults.php?x=1" => '/local/catquizlab/?[31mresults.php',
        ];
        foreach ($cases as $uri => $path) {
            $line = resource_guard::log_line(self::EXHAUSTION, ['REQUEST_URI' => $uri]);
            $this->assertStringContainsString(' path=' . $path . ' ', $line . ' ');
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $line);
            $this->assertStringNotContainsString('TESTSECRET', $line);
            $this->assertSame(1, substr_count($line, 'local_catquizlab memory:'));
        }

        // A path of 100 000 bytes: cut, and the line says so.
        $long = '/local/catquizlab/' . str_repeat('a', 100000) . '.php?sesskey=TESTSECRET';
        $line = resource_guard::log_line(self::EXHAUSTION, ['REQUEST_URI' => $long]);
        $this->assertLessThan(1000, strlen($line));
        $this->assertMatchesRegularExpression('/ path=\/local\/catquizlab\/a+~cut\d+ /', $line);

        // A phase that is not one of the page's phases is not written as given.
        resource_guard::phase("basis\nforged");
        $line = resource_guard::log_line(self::EXHAUSTION, ['REQUEST_URI' => '/x.php']);
        $this->assertStringContainsString(' phase=other ', $line);
    }

    /**
     * AK3: the request id is the web server's when there is one, and otherwise random — never taken from a header
     * that does not look like an id.
     *
     * @return void
     */
    public function test_the_request_id_correlates_without_carrying_anything(): void {
        $line = resource_guard::log_line(self::EXHAUSTION, ['REQUEST_URI' => '/x.php', 'UNIQUE_ID' => 'ZxQ3fH8AAQEAAB@kAbcAAAAD']);
        $this->assertStringContainsString(' rid=ZxQ3fH8AAQEAAB@kAbcAAAAD ridsource=apache ', $line);

        log_redactor::reset();
        $line = resource_guard::log_line(self::EXHAUSTION, ['REQUEST_URI' => '/x.php',
            'UNIQUE_ID' => "bad\r\nid", 'HTTP_X_REQUEST_ID' => 'sesskey=TESTSECRET']);
        $this->assertMatchesRegularExpression('/ rid=[0-9a-f]{16} ridsource=local /', $line);
        $this->assertStringNotContainsString('TESTSECRET', $line);

        log_redactor::reset();
        $line = resource_guard::log_line(self::EXHAUSTION, ['REQUEST_URI' => '/x.php',
            'HTTP_X_CATQUIZLAB_CORRELATION' => 'w3-a17-e1']);
        $this->assertStringContainsString(' rid=w3-a17-e1 ridsource=worker ', $line);

        // The page's message carries the same id, so a screenshot finds the line.
        $html = resource_guard::message_for(self::EXHAUSTION, [resource_guard::MEMORY => 'out {limit}',
            'requestid' => 'id {rid}']);
        $this->assertStringContainsString('data-requestid="w3-a17-e1"', $html);
        $this->assertStringContainsString('id w3-a17-e1', $html);
    }

    /**
     * The tabs the log line takes are the results page's tabs, so a new tab is not silently dropped.
     *
     * @return void
     */
    public function test_the_tab_allowlist_is_the_pages(): void {
        $this->assertSame(array_keys(results_page::tabs()), resource_guard::TABS);
    }

    /**
     * DoD1 in a process that really runs out of memory: the line the server's log receives, from the shutdown
     * path itself.
     *
     * @return void
     */
    public function test_a_real_exhaustion_writes_no_secret(): void {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('Needs proc_open to start a child process.');
        }
        $control = $this->run_probe(__DIR__ . '/fixtures/runtime_oom_probe.php');
        if (strpos($control, 'RUNTIME-SHUTDOWN-RAN') === false) {
            $this->markTestSkipped('PHP ' . PHP_VERSION . ' runs no shutdown function after memory exhaustion; '
                . 'there is no log line to check on this runtime.');
        }

        $output = $this->run_probe(__DIR__ . '/fixtures/fatal_log_probe.php');

        $this->assertStringContainsString('PROBE-START', $output);
        $this->assertMatchesRegularExpression('/^PROBE-LOG local_catquizlab memory: rid=[0-9a-f]{16} /m', $output);
        $line = substr($output, strpos($output, 'PROBE-LOG'));
        $this->assertSame(1, substr_count($line, "\n"), 'The log line is one line.');
        // The probe's own file name is in the line (file=…fatal_log_probe.php); its address is not.
        foreach (['TESTSECRET', 'probe@', 'probe%40', 'example.org', 'forged', 'filtername'] as $absent) {
            $this->assertStringNotContainsString($absent, $line);
        }
        foreach (
            [' path=/local/catquizlab/results.php ', ' phase=basis ', ' limit=16MB ', ' experimentid=42 ',
                ' tab=rawdata ', ' sittings=9000 ', ' traces=37MB ', ' dropped=2'] as $present
        ) {
            $this->assertStringContainsString($present, $line);
        }
    }

    /**
     * Run a probe in its own PHP process with a 16 MB limit and return what it wrote.
     *
     * @param string $probe Path of the probe script.
     * @return string Its standard output.
     */
    protected function run_probe(string $probe): string {
        $command = escapeshellarg(PHP_BINARY) . ' -n -d memory_limit=16M -d display_errors=0 '
            . escapeshellarg($probe);

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }
}
