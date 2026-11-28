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

namespace local_catquizlab\tests;

/**
 * What a worker report is checked against, built for the tests that report (#173, re-audit 27.11.2026).
 *
 * Collected is only a sitting whose owner, activity and engine attempt the server can establish and whose attempt is
 * complete (engine_identity::for_collection()); a report is applied only for the execution holding the lease
 * (lease_fence). Until 0.7.84 a test could report a sitting of a person without an account and a made-up engine attempt
 * id, and have it collected. These helpers build the minimum the server checks: an activity for the run, an account
 * for the person, an activity attempt with its usage and presented slots.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait engine_world {
    /**
     * Give a run an activity: a course module standing in for the run's adaptive quiz.
     *
     * @param int $runid The run.
     * @param int $instance The activity instance.
     * @return int The instance.
     */
    protected function world_activity(int $runid, int $instance = 4713): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            $this->markTestSkipped('Needs mod_adaptivequiz.');
        }
        $course = $this->getDataGenerator()->create_course();
        $cmid = (int) $DB->insert_record('course_modules', (object) ['course' => $course->id, 'module' => 1,
            'instance' => $instance, 'section' => 0, 'added' => time()]);
        $DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $runid]);

        return $instance;
    }

    /**
     * Give a sitting's person an account, when it has none.
     *
     * @param int $attemptid The sitting.
     * @return int The account.
     */
    protected function world_account(int $attemptid): int {
        global $DB;

        $personid = (int) $DB->get_field('local_catquizlab_attempt', 'personid', ['id' => $attemptid]);
        $userid = (int) $DB->get_field('local_catquizlab_person', 'moodleuserid', ['id' => $personid]);
        if ($userid <= 0) {
            $userid = (int) $this->getDataGenerator()->create_user()->id;
            $DB->set_field('local_catquizlab_person', 'moodleuserid', $userid, ['id' => $personid]);
        }

        return $userid;
    }

    /**
     * The sitting's activity attempt: its usage and presented slots, as the engine left it.
     *
     * @param int $attemptid The sitting (its run needs an activity: world_activity()).
     * @param int $answered questionsattempted.
     * @param string $state attemptstate: complete or inprogress.
     * @param int|null $presented Slots of its usage; the count by default.
     * @return array{0: int, 1: int} The activity attempt and its usage.
     */
    protected function world_engine(int $attemptid, int $answered = 3, string $state = 'complete', ?int $presented = null): array {
        global $DB;

        $userid = $this->world_account($attemptid);
        $runid = (int) $DB->get_field('local_catquizlab_attempt', 'runid', ['id' => $attemptid]);
        $cmid = (int) $DB->get_field('local_catquizlab_run', 'testcmid', ['id' => $runid]);
        $instance = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $usage = (int) $DB->insert_record('question_usages', (object) ['contextid' => 1, 'component' => 'mod_adaptivequiz',
            'preferredbehaviour' => 'deferredfeedback']);
        $engine = (int) $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => $instance, 'userid' => $userid,
            'uniqueid' => $usage, 'attemptstate' => $state, 'attemptstopcriteria' => '', 'questionsattempted' => $answered,
            'timecreated' => time(), 'timemodified' => time()]);
        for ($slot = 1; $slot <= ($presented ?? $answered); $slot++) {
            $DB->insert_record('question_attempts', (object) ['questionusageid' => $usage, 'slot' => $slot,
                'behaviour' => 'deferredfeedback', 'questionid' => $slot, 'variant' => 1, 'maxmark' => 1, 'minfraction' => 0,
                'maxfraction' => 1, 'flagged' => 0, 'timemodified' => time()]);
        }

        return [$engine, $usage];
    }
}
