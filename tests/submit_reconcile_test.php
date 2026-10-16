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

use local_catquizlab\external\sitting_state;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\reason_catalog;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

/**
 * Answer submissions told apart, reconciled with the server, and kept (#134).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\reason_catalog
 * @covers     \local_catquizlab\local\attempt_history
 * @covers     \local_catquizlab\external\sitting_state
 * @covers     \local_catquizlab\local\results_export
 */
final class submit_reconcile_test extends \advanced_testcase {
    /**
     * Each class the worker reports is its own reason code; the status wins over the error page.
     *
     * @return void
     */
    public function test_every_submission_class_is_its_own_code(): void {
        foreach (reason_catalog::SUBMIT_CLASSES as $class) {
            $code = reason_catalog::failure("[submit:{$class}] submit answer 5: usage=6 slot=5 answered=4 chain=[POST 404]");
            $this->assertSame('submit_' . $class, $code);
            $this->assertContains($code, reason_catalog::FAILURES);
            $this->assertNotSame($code, reason_catalog::label($code), 'labelled');
        }
        // A 404 answered with Moodle's error page is a 404, not an engine exception.
        $this->assertSame('submit_http_404', reason_catalog::failure(
            '[submit:http_404] submit answer 3: chain=[POST 404] | Moodle showed an error page Exception'
        ));
        // Where the server's replay located an exception behind a plain error page, that is the cause.
        $this->assertSame('engine_exception', reason_catalog::failure(
            '[submit:moodle_error_page] Moodle showed an error page | server replay: DivisionByZeroError at x.php:7'
        ));
        $this->assertSame('submit_state_mismatch', reason_catalog::failure(
            '[submit:http_5xx] submit answer 4 [reconcile:mismatch] server: inprogress, 7 answered'
        ));
        // An unknown class is not invented into a code.
        $this->assertNotSame('submit_banana', reason_catalog::failure('[submit:banana] something'));
    }

    /**
     * A reconciled submission keeps a row of its own, with its cause, in the history, the logs and the export.
     *
     * @return void
     */
    public function test_reconciled_submissions_are_kept(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $person = $generator->create_person(['runid' => $run->id]);
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id,
            'personid' => $person->id, 'status' => 40, 'tries' => 1, 'timecreated' => time(), 'timemodified' => time()]);

        $written = attempt_history::record_reconciliations($attemptid, [
            ['class' => 'connect_failed', 'verdict' => 'stored', 'answered' => 4, 'usage' => 6, 'slot' => 5,
                'server' => ['state' => 'inprogress', 'questionsattempted' => 5, 'slots' => 5]],
            ['class' => 'http_5xx', 'verdict' => 'notstored', 'answered' => 8, 'usage' => 6, 'slot' => 9,
                'server' => ['state' => 'inprogress', 'questionsattempted' => 8, 'slots' => 9]],
            // The one that ended the execution belongs to its failure row, not here.
            ['class' => 'http_404', 'verdict' => 'mismatch', 'answered' => 9],
        ], ['workerid' => 'w1', 'tryno' => 1]);
        $this->assertSame(2, $written);

        $rows = array_values($DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $attemptid], 'id ASC'));
        $this->assertSame([attempt_history::RECOVERED, attempt_history::RECOVERED], array_column($rows, 'outcome'));
        $this->assertSame(['submit_connect_failed', 'submit_http_5xx'], array_column($rows, 'reasoncode'));
        $this->assertStringContainsString('reconciled: stored — usage 6 slot 5, 4 answered before', $rows[0]->detail);
        $this->assertStringContainsString('"reconciliation"', (string) $rows[0]->diagnosis);

        // In the logs: a warning, with its code.
        $lines = \local_catquizlab\local\log_view::lines(['since' => 0, 'attemptid' => $attemptid, 'channel' => 'attempt'], 10);
        $this->assertCount(2, $lines);
        $this->assertSame(\local_catquizlab\local\log_view::WARNING, $lines[0]['severity']);
        $this->assertStringContainsString('reason=submit_connect_failed', $lines[0]['text']);

        // In the export: one row per execution entry, with code and label.
        $export = results_export::iterate(new results_query(['runid' => (int) $run->id]), results_export::LEVEL_EXECUTION);
        $exported = iterator_to_array($export['rows'], false);
        $this->assertSame(results_export::EXECUTION_COLUMNS, $export['columns']);
        $this->assertSame(['submit_connect_failed', 'submit_http_5xx'], array_column($exported, 'reasoncode'));
        $this->assertSame('Answer submission: connection failed', $exported[0]['reasonlabel']);
        $this->assertSame(2, results_export::row_count(
            new results_query(['runid' => (int) $run->id]),
            results_export::LEVEL_EXECUTION
        ));
    }

    /**
     * The server's word on a sitting: answered, presented, finished.
     *
     * @return void
     */
    public function test_the_sitting_state_counts_answered_and_presented(): void {
        global $DB;
        $this->resetAfterTest();
        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('Needs mod_adaptivequiz, which this job does not install.');
        }

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
            'instance' => 4711, 'section' => 0, 'added' => time()]);
        $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $run->id]);
        $person = $generator->create_person(['runid' => $run->id, 'moodleuserid' => $user->id]);
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) ['runid' => $run->id,
            'personid' => $person->id, 'status' => 10, 'tries' => 1, 'timecreated' => time(), 'timemodified' => time()]);

        $this->assertSame('no-engine-attempt', sitting_state::state($attemptid)['reason']);

        $usage = (int) $DB->insert_record('question_usages', (object) ['contextid' => 1, 'component' => 'mod_adaptivequiz',
            'preferredbehaviour' => 'deferredfeedback']);
        $engine = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => 4711, 'userid' => $user->id,
            'uniqueid' => $usage, 'attemptstate' => 'inprogress', 'attemptstopcriteria' => '', 'questionsattempted' => 4,
            'timecreated' => time(), 'timemodified' => time()]);
        for ($slot = 1; $slot <= 5; $slot++) {
            $DB->insert_record('question_attempts', (object) ['questionusageid' => $usage, 'slot' => $slot,
                'behaviour' => 'deferredfeedback', 'questionid' => $slot, 'variant' => 1, 'maxmark' => 1, 'minfraction' => 0,
                'maxfraction' => 1, 'flagged' => 0, 'timemodified' => time()]);
        }

        $state = sitting_state::state($attemptid);
        $this->assertTrue($state['found']);
        $this->assertSame([$engine, 'inprogress', 4, 5], [$state['engineattemptid'], $state['state'],
            $state['questionsattempted'], $state['slots']]);
        $this->assertSame('sitting-not-found', sitting_state::state(987654)['reason']);
    }
}
