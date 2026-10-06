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

namespace local_catquizlab\local;

/**
 * Ask the CAT engine for a run's first question, server-side, before any worker does.
 *
 * A browser that hits an error page sees "Division by zero" and nothing else:
 * production sites do not show debug information, so the worker can report the
 * words and not the place. That is how six runs on a live installation failed
 * identically for two days while every check this plugin had said they were
 * ready — the checks read configuration, and the failure was in what the engine
 * did with it.
 *
 * This does what the browser would do, in PHP, inside a transaction it rolls
 * back: it asks the engine for the first item exactly as mod_adaptivequiz asks
 * for it. If that throws, the exception is caught here with its file, its line
 * and its trace, and a run that would fail on its first sitting is blocked
 * before a single one is queued — with the cause written where the person who
 * has to fix it will read it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_dryrun {
    /**
     * Try to select the first question for a run's first simulated person.
     *
     * @param int $runid The run.
     * @return array{ok: bool, questionid: int, reason: string, exception: array|null}
     */
    public static function first_question(int $runid): array {
        global $DB, $USER, $CFG;

        if (!class_exists('\\local_catquiz\\catquiz_handler')) {
            return self::verdict(false, 0, 'engine-missing');
        }

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        if (!$run) {
            return self::verdict(false, 0, 'run-not-found');
        }

        // The activity is reached through its course module, which is what the
        // run keeps.
        $cmid = (int) $run->testcmid;
        $instanceid = $cmid > 0
            ? (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid])
            : 0;

        if ($instanceid === 0 || !$DB->record_exists('adaptivequiz', ['id' => $instanceid])) {
            return self::verdict(false, 0, 'no-test-instance');
        }

        $people = $DB->get_records_select(
            'local_catquizlab_person',
            'runid = :runid AND moodleuserid > 0',
            ['runid' => $runid],
            'id ASC',
            '*',
            0,
            1
        );
        $person = $people === [] ? null : reset($people);
        if (!$person) {
            return self::verdict(false, 0, 'no-simulated-person');
        }

        $user = $DB->get_record('user', ['id' => (int) $person->moodleuserid, 'deleted' => 0]);
        if (!$user) {
            return self::verdict(false, 0, 'person-account-missing');
        }

        $previoususer = $USER;
        $transaction = $DB->start_delegated_transaction();

        try {
            // A real attempt row, because the engine writes to it when it has
            // nothing to offer — closing the attempt with a status — and a
            // made-up id turned that into a missing-record error that hid the
            // real answer. It lives only inside this transaction.
            $attempt = (object) [
                'instance'            => $instanceid,
                'userid'              => (int) $user->id,
                'uniqueid'            => 0,
                'attemptstate'        => 'inprogress',
                'attemptstopcriteria' => '',
                'questionsattempted'  => 0,
                'difficultysum'       => 0,
                'standarderror'       => 999,
                'measure'             => 0,
                'resultvalid'         => 0,
                'timecreated'         => time(),
                'timemodified'        => time(),
            ];
            $attempt->id = $DB->insert_record('adaptivequiz_attempt', $attempt);

            // The engine reads the current user, as the attempt page runs as
            // the person sitting the test.
            $USER = $user;

            [$questionid, $message] = \local_catquiz\catquiz_handler::fetch_question_id(
                $instanceid,
                'mod_adaptivequiz',
                $attempt
            );

            $USER = $previoususer;

            // Nothing this did is kept: it was a question, not an attempt.
            $transaction->rollback(new \moodle_exception('dryrun', 'local_catquizlab'));
        } catch (\moodle_exception $rolledback) {
            // The rollback above throws the exception it was given; that one
            // is ours and means the dry run completed.
            $USER = $previoususer;

            if ($rolledback->errorcode !== 'dryrun') {
                return self::from_throwable($rolledback, $CFG->dirroot);
            }

            if ((int) ($questionid ?? 0) === 0) {
                return self::verdict(false, 0, 'engine-refused: ' . (string) ($message ?? ''));
            }

            return self::verdict(true, (int) $questionid, '');
        } catch (\Throwable $e) {
            $USER = $previoususer;
            try {
                $transaction->rollback($e);
            } catch (\Throwable $ignored) {
                // Nothing to do: the rollback rethrows what it was given.
                $ignored = null;
            }

            return self::from_throwable($e, $CFG->dirroot);
        }
    }

    /**
     * Replay the question selection for an attempt that already exists.
     *
     * When a sitting fails on its third question, the browser sees an error
     * page and the plugin sees "Division by zero". This asks the engine the
     * same question it was asked when the page failed — with that attempt's
     * real progress, inside a transaction that is rolled back — and catches
     * what it throws.
     *
     * @param int $userid The simulated person.
     * @param int $instanceid The adaptivequiz instance.
     * @return array{ok: bool, questionid: int, reason: string, exception: array|null, attemptid: int}
     */
    public static function replay(int $userid, int $instanceid): array {
        global $DB, $USER, $CFG;

        if (!class_exists('\\local_catquiz\\catquiz_handler')) {
            return self::verdict(false, 0, 'engine-missing') + ['attemptid' => 0];
        }

        $attempts = $DB->get_records(
            'adaptivequiz_attempt',
            ['userid' => $userid, 'instance' => $instanceid],
            'id DESC',
            '*',
            0,
            1
        );
        $attempt = $attempts === [] ? null : reset($attempts);
        if (!$attempt) {
            return self::verdict(false, 0, 'no-attempt-for-person') + ['attemptid' => 0];
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user) {
            return self::verdict(false, 0, 'person-account-missing') + ['attemptid' => (int) $attempt->id];
        }

        $previoususer = $USER;
        $transaction = $DB->start_delegated_transaction();

        try {
            $USER = $user;

            [$questionid, $message] = \local_catquiz\catquiz_handler::fetch_question_id(
                $instanceid,
                'mod_adaptivequiz',
                $attempt
            );

            $USER = $previoususer;
            $transaction->rollback(new \moodle_exception('dryrun', 'local_catquizlab'));
        } catch (\moodle_exception $rolledback) {
            $USER = $previoususer;

            if ($rolledback->errorcode !== 'dryrun') {
                return self::from_throwable($rolledback, $CFG->dirroot) + ['attemptid' => (int) $attempt->id];
            }

            if ((int) ($questionid ?? 0) === 0) {
                return self::verdict(false, 0, 'engine-refused: ' . (string) ($message ?? ''))
                    + ['attemptid' => (int) $attempt->id];
            }

            return self::verdict(true, (int) $questionid, '') + ['attemptid' => (int) $attempt->id];
        } catch (\Throwable $e) {
            $USER = $previoususer;
            try {
                $transaction->rollback($e);
            } catch (\Throwable $ignored) {
                $ignored = null;
            }

            return self::from_throwable($e, $CFG->dirroot) + ['attemptid' => (int) $attempt->id];
        }
    }

    /**
     * The verdict for an exception: the words the browser showed, and the
     * place the browser could not.
     *
     * @param \Throwable $e What was thrown.
     * @param string $dirroot Moodle's root, stripped from paths.
     * @return array
     */
    protected static function from_throwable(\Throwable $e, string $dirroot): array {
        $strip = static function (string $path) use ($dirroot): string {
            return str_starts_with($path, $dirroot . '/') ? substr($path, strlen($dirroot) + 1) : $path;
        };

        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 12) as $frame) {
            $frames[] = sprintf(
                '%s:%d %s%s%s()',
                $strip((string) ($frame['file'] ?? '?')),
                (int) ($frame['line'] ?? 0),
                (string) ($frame['class'] ?? ''),
                (string) ($frame['type'] ?? ''),
                (string) ($frame['function'] ?? '')
            );
        }

        return [
            'ok'         => false,
            'questionid' => 0,
            'reason'     => get_class($e) . ': ' . $e->getMessage()
                . ' at ' . $strip($e->getFile()) . ':' . $e->getLine(),
            'exception'  => [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $strip($e->getFile()),
                'line'    => $e->getLine(),
                'trace'   => $frames,
            ],
        ];
    }

    /**
     * Assemble a verdict.
     *
     * @param bool $ok Whether a question came back.
     * @param int $questionid Which one.
     * @param string $reason Why not, otherwise.
     * @return array
     */
    protected static function verdict(bool $ok, int $questionid, string $reason): array {
        return ['ok' => $ok, 'questionid' => $questionid, 'reason' => $reason, 'exception' => null];
    }
}
