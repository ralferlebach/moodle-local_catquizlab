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

/**
 * Exceptions with their root cause, and form submissions without secrets (#90).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\debug_trace
 */
final class debug_trace_cause_test extends \advanced_testcase {
    /**
     * An exception is recorded with the innermost cause, and Moodle's errorcode.
     *
     * @return void
     */
    public function test_an_exception_is_recorded_with_its_root_cause(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('debuglevel', debug_trace::LEVEL_TRACE, 'local_catquizlab');

        // A chain: the outer exception only says that a stage failed.
        $root = new \RuntimeException('Division by zero in model');
        debug_trace::exception(debug_trace::LIFECYCLE, 'stage_exception', new \Exception('Stage failed', 0, $root), 7);
        // Moodle's own: its errorcode and debug information.
        debug_trace::exception(
            debug_trace::SERVICE,
            'oracle_question_lookup',
            new \moodle_exception('generalexceptionmessage', 'error', '', 'Lookup failed', 'debug detail')
        );

        $entry = function (string $action) use ($DB): string {
            $row = $DB->get_record_select('local_catquizlab_debug', 'action = ?', [$action], '*', IGNORE_MULTIPLE);
            $this->assertNotFalse($row, $action);
            // The whole entry, whatever column the details are kept in.
            return stripslashes(json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        };
        $this->assertStringContainsString('RuntimeException: Division by zero in model', $entry('stage_exception'));
        $moodle = $entry('oracle_question_lookup');
        $this->assertStringContainsString('generalexceptionmessage', $moodle, 'the errorcode');
        $this->assertStringContainsString('debug detail', $moodle, 'the debug information');
    }

    /**
     * What a form submitted is logged without the session key and anything secret.
     *
     * @return void
     */
    public function test_submitted_data_is_logged_without_secrets(): void {
        $clean = debug_trace::submitted([
            'name' => 'Exp', 'sesskey' => 'abc', 'workerpassword' => 'p', 'apitoken' => 't',
            'nested' => ['secretkey' => 's', 'count' => 3],
        ]);
        $this->assertSame('Exp', $clean['name']);
        $this->assertSame(3, $clean['nested']['count']);
        foreach ([$clean['sesskey'], $clean['workerpassword'], $clean['apitoken'], $clean['nested']['secretkey']] as $value) {
            $this->assertSame('[redacted]', $value);
        }
    }
}
