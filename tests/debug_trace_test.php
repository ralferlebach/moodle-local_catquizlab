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
 * What was clicked, what ran, and what came back — in one sequence.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\debug_trace;

/**
 * Debug trace tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\debug_trace
 */
final class debug_trace_test extends \advanced_testcase {
    /**
     * Nothing is recorded while the mode is off.
     *
     * @return void
     */
    public function test_it_records_nothing_when_off(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('debugmode', 0, 'local_catquizlab');
        debug_trace::record(debug_trace::UI, 'provision', ['runid' => 4]);

        // An installation that records every action all the time is one where
        // nobody reads the recording.
        $this->assertFalse(debug_trace::enabled());
        $this->assertSame([], debug_trace::entries());
    }

    /**
     * With the mode on, an action and its consequences read as one sequence.
     *
     * @return void
     */
    public function test_it_records_a_sequence(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('debugmode', 1, 'local_catquizlab');

        debug_trace::record(debug_trace::UI, 'provision', ['runid' => 4], 'ok', [], 4);
        debug_trace::record(debug_trace::LIFECYCLE, 'run_failed', [], 'ok', ['reason' => 'pool'], 4);

        $entries = debug_trace::entries();

        // Each log held a fragment and none held the order, which is what made
        // a defect impossible to read as what it was.
        $this->assertCount(2, $entries);
        $this->assertSame('run_failed', $entries[0]['action']);
        $this->assertSame('provision', $entries[1]['action']);
    }

    /**
     * Secrets are recorded as present, not as their value.
     *
     * @return void
     */
    public function test_secrets_are_redacted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('debugmode', 1, 'local_catquizlab');
        debug_trace::record(debug_trace::UI, 'setup', [
            'runid'   => 7,
            'sesskey' => 'abc123',
            'wstoken' => 'secretvalue',
        ]);

        $params = debug_trace::entries()[0]['params'];

        // Knowing a token was sent is diagnostic; knowing which one is a
        // liability.
        $this->assertStringContainsString('7', $params);
        $this->assertStringNotContainsString('abc123', $params);
        $this->assertStringNotContainsString('secretvalue', $params);
        $this->assertStringContainsString('hidden', $params);
    }

    /**
     * An exception is recorded with where it came from.
     *
     * @return void
     */
    public function test_an_exception_is_recorded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('debugmode', 1, 'local_catquizlab');

        try {
            throw new \coding_exception('something specific');
        } catch (\Throwable $e) {
            debug_trace::exception(debug_trace::TASK, 'orchestrate', $e, 4);
        }

        $entry = debug_trace::entries()[0];

        $this->assertTrue($entry['failed']);
        $this->assertStringContainsString('something specific', $entry['detail']);
        // One frame, not the whole trace: a hundred frames in a table cell is
        // not an answer.
        $this->assertStringContainsString('where', $entry['detail']);
    }

    /**
     * Recording never breaks the thing it records.
     *
     * @return void
     */
    public function test_recording_is_never_fatal(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('debugmode', 1, 'local_catquizlab');

        // An action name far longer than the column, and a non-scalar
        // parameter. A plugin that breaks while writing about itself is worse
        // than a defect nobody can reconstruct.
        debug_trace::record(debug_trace::UI, str_repeat('x', 500), ['thing' => new \stdClass()]);

        $this->assertCount(1, debug_trace::entries());
    }

    /**
     * Clearing forgets everything.
     *
     * @return void
     */
    public function test_clearing_empties_the_buffer(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('debugmode', 1, 'local_catquizlab');
        debug_trace::record(debug_trace::UI, 'one');
        debug_trace::record(debug_trace::UI, 'two');

        $this->assertSame(2, debug_trace::clear());
        $this->assertSame([], debug_trace::entries());
    }
}
