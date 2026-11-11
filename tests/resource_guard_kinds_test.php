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

/**
 * Every kind of resource death is said as what it is, and the page stays whole (#147, AK1, AK2, AK8, D2).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\resource_guard
 */
final class resource_guard_kinds_test extends \advanced_testcase {
    /**
     * Each test starts from a request without phases.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        resource_guard::reset();
        log_redactor::reset();
    }

    /**
     * And leaves none.
     *
     * @return void
     */
    protected function tearDown(): void {
        resource_guard::reset();
        log_redactor::reset();
        parent::tearDown();
    }

    /**
     * PHP's limit, the system's memory and the time limit are three things, said as three (AK2); E_CORE_ERROR as well
     * as E_ERROR; a warning is not a death.
     *
     * @return void
     */
    public function test_three_kinds_and_their_messages(): void {
        $texts = [resource_guard::MEMORY => 'M {limit}', resource_guard::SYSTEM => 'S {limit}',
            resource_guard::TIME => 'T {limit}'];
        foreach ([E_ERROR, E_CORE_ERROR] as $type) {
            $this->assertSame(resource_guard::MEMORY, resource_guard::classify(['type' => $type,
                'message' => 'Allowed memory size of 268435456 bytes exhausted (tried to allocate 65536 bytes)']));
        }
        $this->assertNull(resource_guard::classify(['type' => E_WARNING, 'message' => 'Out of memory']));

        // A finite limit: the limit of the error itself, never what the setting says by then.
        $previous = ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        try {
            $html = resource_guard::message_for(['type' => E_ERROR,
                'message' => 'Allowed memory size of 268435456 bytes exhausted'], $texts);
            $this->assertStringContainsString('M 256 MB', $html);
            // Unlimited: the system's memory ran out, not PHP's limit — and it says unlimited, not "-1" as a limit.
            $html = resource_guard::message_for(['type' => E_ERROR,
                'message' => 'Out of memory (allocated 6291456) (tried to allocate 1048576 bytes)'], $texts);
            $this->assertStringContainsString('data-kind="system"', $html);
            $this->assertStringContainsString('S unlimited (-1)', $html);
            $this->assertStringContainsString('role="alert"', $html);
        } finally {
            ini_set('memory_limit', $previous);
        }

        // The log says which, with the original figures (AK1).
        $line = resource_guard::log_line(
            ['type' => E_ERROR, 'file' => '/x/local/catquizlab/classes/local/results_query.php',
            'line' => 612, 'message' => 'Allowed memory size of 268435456 bytes exhausted (tried to allocate 65536 bytes)'],
            ['REQUEST_URI' => '/local/catquizlab/results.php', 'REQUEST_TIME_FLOAT' => microtime(true) - 2]
        );
        foreach (
            [' kind=memory ', ' limit=256MB ', ' tried=0MB ', ' line=612 ', ' php=' . PHP_VERSION . ' ',
                ' sapi=' . PHP_SAPI . ' '] as $part
        ) {
            $this->assertStringContainsString($part, $line . ' ');
        }
        $this->assertMatchesRegularExpression('/ runtime=\d{4}ms /', $line);
        $this->assertMatchesRegularExpression('/ limitini=(\d+MB|-1) /', $line);
        $this->assertMatchesRegularExpression('/ file=\S*results_query\.php /', $line);
        $this->assertStringStartsWith('local_catquizlab memory: ', $line);
        $this->assertStringStartsWith('local_catquizlab memory: ', resource_guard::log_line(['type' => E_ERROR,
            'message' => 'Out of memory (allocated 6291456)']));
        $this->assertStringContainsString(' kind=system ', resource_guard::log_line(['type' => E_ERROR,
            'message' => 'Out of memory (allocated 6291456)']));
    }

    /**
     * Each phase and step is traceable with its time, its queries and its peak (AK3), and the step a page stopped in
     * is in the line.
     *
     * @return void
     */
    public function test_phases_and_steps_are_traced(): void {
        global $DB;
        $this->resetAfterTest();
        resource_guard::context(null);
        $reflection = new \ReflectionProperty(resource_guard::class, 'since');
        $reflection->setValue(null, [microtime(true), (int) $DB->perf_get_queries()]);
        resource_guard::phase('filter');
        $DB->get_records('config', [], '', 'id', 0, 1);
        resource_guard::phase('basis');
        resource_guard::step('provenance');
        $DB->get_records('config', [], '', 'id', 0, 1);
        $DB->get_records('config', [], '', 'id', 0, 1);
        resource_guard::step('funnel');
        $marks = resource_guard::marks();
        $this->assertArrayHasKey('start', $marks);
        $this->assertArrayHasKey('filter', $marks);
        $this->assertArrayHasKey('basis:provenance', $marks);
        $this->assertSame(1, $marks['filter']['queries']);
        $this->assertSame(2, $marks['basis:provenance']['queries']);
        $line = resource_guard::log_line(['type' => E_ERROR, 'message' => 'Allowed memory size of 1048576 bytes exhausted']);
        $this->assertStringContainsString(' phase=basis step=funnel ', $line);
        $this->assertMatchesRegularExpression('/ steps=\S*basis:provenance=\d+MB\/\d+ms\/2q/', $line);
    }

    /**
     * A real exhaustion with half a table written: the half table goes, the message stands, the log line has the
     * phase and step (AK8, D2: shutdown handler).
     *
     * @return void
     */
    public function test_a_real_exhaustion_leaves_a_whole_page(): void {
        $output = $this->probe('memory', 'memory_limit=24M');
        if ($output === null) {
            return;
        }
        $this->assertStringContainsString('PROBE-HEADER', $output);
        $this->assertStringNotContainsString('PROBE-PARTIAL-TABLE', $output, 'the half-written table was discarded');
        $this->assertStringContainsString('PROBE-MEMORY limit=24 MB phase=tab', $output);
        $this->assertStringContainsString('data-step="overviewpass"', $output);
        $this->assertMatchesRegularExpression('/PROBE-LOG local_catquizlab memory: .* kind=memory phase=tab '
            . 'step=overviewpass .* limit=24MB /', $output);
    }

    /**
     * A real timeout: said as a timeout, logged as one, the half table discarded (AK2, D2: deadline).
     *
     * @return void
     */
    public function test_a_real_timeout_is_said_as_time(): void {
        $output = $this->probe('time', 'memory_limit=64M');
        if ($output === null) {
            return;
        }
        $this->assertStringNotContainsString('PROBE-PARTIAL-TABLE', $output);
        $this->assertStringContainsString('PROBE-TIME limit=1', $output);
        $this->assertStringContainsString('data-kind="time"', $output);
        $this->assertMatchesRegularExpression('/PROBE-LOG local_catquizlab time: .* kind=time phase=tab /', $output);
    }

    /**
     * Run the probe in its own PHP process; null, with the test skipped, where this PHP runs no shutdown function
     * after a fatal error.
     *
     * @param string $mode memory or time.
     * @param string $setting An ini setting for the process.
     * @return string|null
     */
    protected function probe(string $mode, string $setting): ?string {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('Needs proc_open to start a child process.');
        }
        $run = function (string $script, array $args) use ($setting): string {
            $command = escapeshellarg(PHP_BINARY) . ' -n -d ' . escapeshellarg($setting) . ' -d display_errors=0 '
                . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args));
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return $out;
        };
        if (strpos($run(__DIR__ . '/fixtures/runtime_oom_probe.php', []), 'RUNTIME-SHUTDOWN-RAN') === false) {
            $this->markTestSkipped('PHP ' . PHP_VERSION . ' runs no shutdown function after memory exhaustion.');
        }

        return $run(__DIR__ . '/fixtures/fatal_kinds_probe.php', [$mode]);
    }
}
