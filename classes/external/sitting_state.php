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

namespace local_catquizlab\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Where a sitting stands on the server, for a worker whose submission ended uncertainly (#134).
 *
 * A POST that ended in a 404, a 5xx, a timeout or a redirect nobody expected
 * says nothing certain about the answer it carried: it may have been stored
 * with only the response lost, or not stored at all. The browser cannot tell
 * which. The server can: the activity's attempt counts the questions answered,
 * its question usage the questions presented. The worker compares those with
 * what it has answered, and only then continues — answering again what was not
 * stored, never what was — or stops with the reason.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sitting_state extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'The catquizlab sitting.'),
        ]);
    }

    /**
     * The state of the sitting's activity attempt.
     *
     * @param int $attemptid The catquizlab sitting.
     * @return array
     */
    public static function execute(int $attemptid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        return self::state((int) $params['attemptid']);
    }

    /**
     * The state, without the web service around it.
     *
     * @param int $attemptid The catquizlab sitting.
     * @return array found, reason, engineattemptid, state, questionsattempted, slots, timemodified.
     */
    public static function state(int $attemptid): array {
        global $DB;

        $none = ['found' => false, 'reason' => '', 'engineattemptid' => 0, 'state' => '', 'questionsattempted' => 0,
            'slots' => 0, 'timemodified' => 0];
        $sitting = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid]);
        if (!$sitting) {
            return ['reason' => 'sitting-not-found'] + $none;
        }
        $person = $DB->get_record('local_catquizlab_person', ['id' => (int) $sitting->personid]);
        $run = $DB->get_record('local_catquizlab_run', ['id' => (int) $sitting->runid]);
        if (!$person || !$run || (int) $person->moodleuserid === 0 || (int) $run->testcmid === 0) {
            return ['reason' => 'sitting-incomplete'] + $none;
        }
        $instance = (int) $DB->get_field('course_modules', 'instance', ['id' => (int) $run->testcmid]);
        if ($instance === 0) {
            return ['reason' => 'no-test-instance'] + $none;
        }
        // The newest attempt of this person on this test: the one the browser was in.
        $attempts = $DB->get_records(
            'adaptivequiz_attempt',
            ['instance' => $instance, 'userid' => (int) $person->moodleuserid],
            'id DESC',
            'id, uniqueid, attemptstate, questionsattempted, timemodified',
            0,
            1
        );
        $attempt = reset($attempts);
        if (!$attempt) {
            return ['reason' => 'no-engine-attempt'] + $none;
        }
        $slots = (int) $attempt->uniqueid > 0
            ? $DB->count_records('question_attempts', ['questionusageid' => (int) $attempt->uniqueid])
            : 0;

        return [
            'found' => true,
            'reason' => '',
            'engineattemptid' => (int) $attempt->id,
            'state' => (string) $attempt->attemptstate,
            'questionsattempted' => (int) $attempt->questionsattempted,
            'slots' => $slots,
            'timemodified' => (int) $attempt->timemodified,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Whether the activity attempt was found.'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'Why not, otherwise.'),
            'engineattemptid' => new external_value(PARAM_INT, 'The adaptivequiz_attempt id.'),
            'state' => new external_value(PARAM_ALPHANUMEXT, 'Its attemptstate: inprogress or complete.'),
            'questionsattempted' => new external_value(PARAM_INT, 'Questions answered and stored.'),
            'slots' => new external_value(PARAM_INT, 'Questions presented: the slots of its question usage.'),
            'timemodified' => new external_value(PARAM_INT, 'When the attempt last changed.'),
        ]);
    }
}
