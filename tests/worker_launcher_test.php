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
        ]);

        $this->assertSame('/usr/bin/node', $argv[0]);
        $this->assertSame('/path/run_attempt.js', $argv[1]);
        $this->assertContains('--base-url=https://moodle.example', $argv);
        $this->assertContains('--token=abc123', $argv);
        $this->assertContains('--worker-id=w1', $argv);
        $this->assertContains('--max-jobs=5', $argv);
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
}
