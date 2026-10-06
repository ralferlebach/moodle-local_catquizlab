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
use local_catquizlab\local\engine_dryrun;

/**
 * Tell a worker where the engine actually failed.
 *
 * The browser the worker drives sees an error page with no debug information,
 * because production sites do not show any. So when a sitting fails, the worker
 * asks here: the server replays the question selection for that sitting, inside
 * a transaction it rolls back, and returns the exception with its file, line and
 * trace. "Division by zero" becomes "DivisionByZeroError at
 * local/catquiz/classes/…/x.php:123", which is a thing somebody can fix.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_attempt extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'The catquizlab attempt that failed.'),
        ]);
    }

    /**
     * Replay the selection and report what it threw.
     *
     * @param int $attemptid The catquizlab attempt.
     * @return array
     */
    public static function execute(int $attemptid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/catquizlab:worker', $context);

        $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $params['attemptid']]);
        if (!$attempt) {
            return self::answer(false, 'attempt-not-found', '', 0, 0, []);
        }

        $person = $DB->get_record('local_catquizlab_person', ['id' => (int) $attempt->personid]);
        $run = $DB->get_record('local_catquizlab_run', ['id' => (int) $attempt->runid]);
        if (!$person || !$run || (int) $person->moodleuserid === 0 || (int) $run->testcmid === 0) {
            return self::answer(false, 'attempt-incomplete', '', 0, 0, []);
        }

        $instanceid = (int) $DB->get_field('course_modules', 'instance', ['id' => (int) $run->testcmid]);
        if ($instanceid === 0) {
            return self::answer(false, 'no-test-instance', '', 0, 0, []);
        }

        $verdict = engine_dryrun::replay((int) $person->moodleuserid, $instanceid);
        $exception = $verdict['exception'] ?? null;

        return self::answer(
            (bool) $verdict['ok'],
            (string) $verdict['reason'],
            $exception ? (string) $exception['class'] : '',
            0,
            (int) $verdict['questionid'],
            $exception ? (array) $exception['trace'] : [],
            $exception ? (string) $exception['file'] : '',
            $exception ? (int) $exception['line'] : 0
        );
    }

    /**
     * Assemble the answer.
     *
     * @param bool $ok Whether a question came back.
     * @param string $reason Why not, otherwise.
     * @param string $class The exception class.
     * @param int $unused Kept for signature stability.
     * @param int $questionid The question, when there was one.
     * @param array $trace The frames.
     * @param string $file Where it threw.
     * @param int $line Which line.
     * @return array
     */
    protected static function answer(
        bool $ok,
        string $reason,
        string $class,
        int $unused,
        int $questionid,
        array $trace,
        string $file = '',
        int $line = 0
    ): array {
        return [
            'ok'         => $ok,
            'reason'     => $reason,
            'class'      => $class,
            'file'       => $file,
            'line'       => $line,
            'questionid' => $questionid,
            'trace'      => implode("\n", $trace),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'         => new external_value(PARAM_BOOL, 'Whether the engine returned a question.'),
            'reason'     => new external_value(PARAM_RAW, 'What went wrong, with file and line.'),
            'class'      => new external_value(PARAM_RAW, 'The exception class, when one was thrown.'),
            'file'       => new external_value(PARAM_RAW, 'The file that threw, relative to Moodle.'),
            'line'       => new external_value(PARAM_INT, 'The line that threw.'),
            'questionid' => new external_value(PARAM_INT, 'The question, when there was one.'),
            'trace'      => new external_value(PARAM_RAW, 'The first frames of the trace, one per line.'),
        ]);
    }
}
