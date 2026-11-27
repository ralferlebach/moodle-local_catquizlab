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

/**
 * Tests for the worker launcher.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\worker_launcher;

/**
 * Worker launcher tests.
 *
 * @covers \local_catquizlab\local\worker_launcher
 */
final class worker_launcher_test extends \advanced_testcase {
    /**
     * The command carries the base url, token and optional worker id / max jobs.
     *
     * @return void
     */
    public function test_build_command(): void {
        $argv = worker_launcher::build_command([
            'node'     => '/usr/bin/node',
            'script'   => '/path/run_attempt.js',
            'baseurl'  => 'https://moodle.example',
            'token'    => 'abc123',
            'workerid' => 'w1',
            'maxjobs'  => 5,
            'loginmode' => 'urltemplate',
            'loginurltemplate' => 'https://moodle.example/key.php?u={userid}',
            'loginsuffix' => '!pw',
        ]);

        $this->assertSame('/usr/bin/node', $argv[0]);
        $this->assertSame('/path/run_attempt.js', $argv[1]);
        $this->assertContains('--base-url=https://moodle.example', $argv);
        // The token is deliberately absent: command-line arguments are visible
        // in process listings, and this one opens every web service function
        // the worker may call. It travels in the environment instead.
        $this->assertNotContains('--token=abc123', $argv);
        $this->assertStringNotContainsString('abc123', implode(' ', $argv));
        $this->assertContains('--worker-id=w1', $argv);
        $this->assertContains('--max-jobs=5', $argv);
        $this->assertContains('--login-mode=urltemplate', $argv);
        $this->assertContains('--login-url-template=https://moodle.example/key.php?u={userid}', $argv);
        $this->assertContains('--login-suffix=!pw', $argv);
    }

    /**
     * Login options are omitted when not configured.
     *
     * @return void
     */
    public function test_build_command_no_login_options(): void {
        $argv = worker_launcher::build_command(['baseurl' => 'x', 'token' => 'y']);
        foreach ($argv as $part) {
            $this->assertStringNotContainsString('--login-mode', $part);
            $this->assertStringNotContainsString('--login-url-template', $part);
            $this->assertStringNotContainsString('--login-suffix', $part);
        }
    }

    /**
     * max-jobs is omitted when zero.
     *
     * @return void
     */
    public function test_build_command_no_maxjobs(): void {
        $argv = worker_launcher::build_command(['baseurl' => 'x', 'token' => 'y', 'maxjobs' => 0]);
        foreach ($argv as $part) {
            $this->assertStringNotContainsString('--max-jobs', $part);
        }
    }

    /**
     * Launching is refused when disabled or not configured (no exec in CI).
     *
     * @return void
     */
    public function test_launch_refuses_without_config(): void {
        $this->resetAfterTest();

        $this->assertNull(worker_launcher::launch(['enabled' => false]));
        $this->assertNull(worker_launcher::launch([
            'enabled' => true, 'node' => 'node', 'script' => '/no/such/file.js',
            'baseurl' => 'x', 'token' => 'y',
        ]));
    }

    /**
     * worker_ids returns one id for a single worker and distinct ids for a pool.
     *
     * @return void
     */
    public function test_worker_ids(): void {
        $this->assertSame(['base'], worker_launcher::worker_ids('base', 1));
        $this->assertSame(['base'], worker_launcher::worker_ids('base', 0));
        $this->assertSame(['w-1', 'w-2', 'w-3'], worker_launcher::worker_ids('w', 3));
    }

    /**
     * launch_pool is a no-op when disabled or not configured.
     *
     * @return void
     */
    public function test_launch_pool_guarded(): void {
        // Never null: null was what the tick printed nothing about, every five
        // minutes, while the page said "stalled". A launch that cannot happen
        // names what it is missing.
        $off = worker_launcher::launch_pool(['enabled' => false]);
        $this->assertSame(0, $off['launched']);
        $this->assertStringContainsString('worker_exec_enabled', $off['reason']);

        $bare = worker_launcher::launch_pool(['enabled' => true, 'concurrency' => 2]);
        $this->assertSame(0, $bare['launched']);
        $this->assertStringContainsString('worker_token', $bare['reason']);
        $this->assertStringContainsString('worker_node_path', $bare['reason']);
    }

    /**
     * resends counts the workers' resend lines of a time span, and only those (CI 26.11.2026, c6ec43c2).
     *
     * @return void
     */
    public function test_resends(): void {
        global $CFG;
        $this->resetAfterTest();

        // The mark is the worker's own: one string in two languages.
        $js = file_get_contents($CFG->dirroot . '/local/catquizlab/worker/run_attempt.js');
        $this->assertStringContainsString("const RESEND_MARK = '" . worker_launcher::RESEND_MARK . "';", $js);

        $dir = $CFG->dataroot . '/local_catquizlab/worker-logs';
        @mkdir($dir, 0700, true);
        $since = 1795707000;
        $line = static fn(int $at, string $what): string => worker_launcher::RESEND_MARK . ' '
            . gmdate('Y-m-d\TH:i:s', $at) . '.123Z local_catquizlab_oracle_answer: the server closed the connection '
            . 'before any response (UND_ERR_SOCKET: other side closed); ' . $what;
        file_put_contents($dir . '/w-1.log', implode("\n", [
            'Worker w-1 claimed sitting 66.',
            $line($since - 60, 'before the step'),
            $line($since + 981, 'in the step'),
            '  ' . $line($since + 982, 'not at the start of a line'),
            worker_launcher::RESEND_MARK . ' not-a-time local_catquizlab_oracle_answer',
        ]) . "\n");
        file_put_contents($dir . '/w-2.log', $line($since + 990, 'second worker') . "\n");
        // A log not written since: not read at all.
        file_put_contents($dir . '/w-3.log', $line($since + 995, 'old file') . "\n");
        touch($dir . '/w-3.log', $since - 3600);
        touch($dir . '/w-1.log', $since + 1000);
        touch($dir . '/w-2.log', $since + 1000);

        $found = worker_launcher::resends($since);
        $this->assertSame(2, $found['n']);
        $this->assertCount(2, $found['lines']);
        $this->assertStringContainsString('in the step', implode("\n", $found['lines']));
        $this->assertStringContainsString('second worker', implode("\n", $found['lines']));

        $this->assertSame(['n' => 2, 'lines' => []], worker_launcher::resends($since, 0));
        $this->assertSame(0, worker_launcher::resends($since + 2000)['n']);
    }
}
