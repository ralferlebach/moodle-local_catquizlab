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

use local_catquizlab\local\resource_guard;

/**
 * A page that runs out of memory says so (#99).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\resource_guard
 */
final class resource_guard_test extends \advanced_testcase {
    /**
     * Resource errors are recognised, and nothing else is.
     *
     * @return void
     */
    public function test_it_recognises_memory_and_time_only(): void {
        $memory = ['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted'];
        $time = ['type' => E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded'];
        $other = ['type' => E_ERROR, 'message' => 'Call to undefined function foo()'];
        $warning = ['type' => E_WARNING, 'message' => 'Allowed memory size of 1 byte exhausted'];

        $this->assertSame(resource_guard::MEMORY, resource_guard::classify($memory));
        $this->assertSame(resource_guard::TIME, resource_guard::classify($time));
        $this->assertNull(resource_guard::classify($other));
        $this->assertNull(resource_guard::classify($warning));
        $this->assertNull(resource_guard::classify(null));
        // The other two wordings PHP uses for the same thing (#147).
        $this->assertSame(resource_guard::MEMORY, resource_guard::classify(['type' => E_ERROR,
            'message' => 'Hard memory limit of 134217728 bytes exhausted (tried to allocate 2097152 bytes)']));
        // The system's own exhaustion is not PHP's limit, and is not reported as one (#147, AK2).
        $this->assertSame(resource_guard::SYSTEM, resource_guard::classify(['type' => E_ERROR,
            'message' => 'Out of memory (allocated 6291456) (tried to allocate 1048576 bytes)']));
    }

    /**
     * The limit is read from the error, not from the setting Moodle has raised meanwhile (#147).
     *
     * The page on the production server said "limit -1": Moodle's shutdown handler raises the limit to unlimited
     * before any other shutdown function runs.
     *
     * @return void
     */
    public function test_the_limit_comes_from_the_error(): void {
        $this->assertSame(536870912, resource_guard::limit_from(
            'Allowed memory size of 536870912 bytes exhausted (tried to allocate 20480 bytes)'
        ));
        $this->assertSame(134217728, resource_guard::limit_from('Hard memory limit of 134217728 bytes exhausted'));
        $this->assertNull(resource_guard::limit_from('Out of memory (allocated 6291456)'));
        $this->assertSame('512 MB', resource_guard::megabytes(536870912));

        $previous = ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        try {
            $html = resource_guard::message_for(
                ['type' => E_ERROR, 'message' => 'Allowed memory size of 536870912 bytes exhausted'],
                [resource_guard::MEMORY => 'limit={limit} peak={peak} phase={phase}', resource_guard::TIME => 't'] +
                    ['phases' => ['start' => 'preparing']]
            );
        } finally {
            ini_set('memory_limit', $previous);
        }
        $this->assertStringContainsString('limit=512 MB', $html);
        $this->assertStringNotContainsString('-1', $html);
        $this->assertMatchesRegularExpression('/peak=\d+ MB/', $html);
    }

    /**
     * A memory exhaustion leaves one line in the error log that says where and with what (#147).
     *
     * @return void
     */
    public function test_the_log_line_says_where_and_with_what(): void {
        resource_guard::phase('filter');
        resource_guard::phase('basis');
        $line = resource_guard::log_line(['type' => E_ERROR,
            'message' => 'Allowed memory size of 536870912 bytes exhausted (tried to allocate 20480 bytes)']);
        $this->assertStringStartsWith('local_catquizlab memory:', $line);
        $this->assertStringContainsString(' phase=basis ', $line);
        $this->assertStringContainsString(' limit=512MB ', $line);
        $this->assertMatchesRegularExpression('/ phases=start=\d+MB,filter=\d+MB /', $line);
        // A timeout is logged as one, not as memory (#147, AK1, AK2); anything else is not logged here.
        $this->assertStringStartsWith('local_catquizlab time:', resource_guard::log_line(['type' => E_ERROR,
            'message' => 'Maximum execution time of 30 s']));
        $this->assertNull(resource_guard::log_line(['type' => E_ERROR, 'message' => 'Call to undefined function x()']));
        resource_guard::phase('start');
    }

    /**
     * In a process that really runs out of memory, the message is still written.
     *
     * A fatal error cannot be caught, so this cannot be tested inside PHPUnit's
     * own process without ending it. A child process with a small limit grows
     * until PHP stops it, exactly as the results page did, and the test reads
     * what it wrote.
     *
     * @return void
     */
    public function test_the_message_survives_a_real_exhaustion(): void {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('Needs proc_open to start a child process.');
        }

        // First the runtime itself: PHP 8.1.34 (the build of 21.10.2026 on the CI
        // runners) ends a process that runs out of memory without running any
        // shutdown function, and prints the fatal error whatever display_errors
        // says. There no guard can speak, and the test says so instead of
        // failing on PHP's behalf. PHP 8.3 and 8.4 run them.
        $control = $this->run_probe(__DIR__ . '/fixtures/runtime_oom_probe.php');
        $this->assertStringContainsString('RUNTIME-START', $control);
        if (strpos($control, 'RUNTIME-SHUTDOWN-RAN') === false) {
            $this->markTestSkipped('PHP ' . PHP_VERSION . ' runs no shutdown function after memory exhaustion; '
                . 'the guard cannot write its message on this runtime.');
        }

        $output = $this->run_probe(__DIR__ . '/fixtures/resource_guard_probe.php');

        // It started, it died of memory, and it said so after dying.
        $this->assertStringContainsString('PROBE-START', $output);
        $this->assertStringContainsString('PROBE-OUT-OF-MEMORY', $output);
        // The limit from the error itself, although the limit was raised to unlimited before the guard ran (#147).
        $this->assertStringContainsString('limit=16 MB phase=basis', $output);
        $this->assertStringNotContainsString('PROBE-NO-MESSAGE', $output);
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

    /**
     * The page's own texts are complete and carry the limit.
     *
     * @return void
     */
    public function test_the_page_texts_name_the_limit(): void {
        $text = get_string('results:outoftime', 'local_catquizlab', '{limit}');
        $this->assertStringContainsString('{limit}', $text, 'results:outoftime does not name the limit');
        $text = get_string('results:outofmemory', 'local_catquizlab', (object) [
            'limit' => '{limit}', 'peak' => '{peak}', 'phase' => '{phase}',
        ]);
        foreach (['{limit}', '{peak}', '{phase}'] as $part) {
            $this->assertStringContainsString($part, $text, 'results:outofmemory does not say ' . $part);
        }
        // Not the old advice: a 512 MB server is not below what Moodle recommends.
        $this->assertStringNotContainsString('below what Moodle recommends', $text);
        foreach (resource_guard::PHASES as $phase) {
            $this->assertNotEmpty(get_string('results:phase' . $phase, 'local_catquizlab'));
        }
    }
}
