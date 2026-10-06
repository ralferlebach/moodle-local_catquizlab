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

        $probe = __DIR__ . '/fixtures/resource_guard_probe.php';
        $command = escapeshellarg(PHP_BINARY) . ' -n -d memory_limit=16M -d display_errors=0 '
            . escapeshellarg($probe);

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // It started, it died of memory, and it said so after dying.
        $this->assertStringContainsString('PROBE-START', $output);
        $this->assertStringContainsString('PROBE-OUT-OF-MEMORY', $output);
        $this->assertStringContainsString('limit=16M', $output);
        $this->assertStringNotContainsString('PROBE-NO-MESSAGE', $output);
    }

    /**
     * The page's own texts are complete and carry the limit.
     *
     * @return void
     */
    public function test_the_page_texts_name_the_limit(): void {
        foreach (['results:outofmemory', 'results:outoftime'] as $key) {
            $text = get_string($key, 'local_catquizlab', '{limit}');
            $this->assertStringContainsString('{limit}', $text, $key . ' does not name the limit');
        }
    }
}
