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
 * The Moodle container an experiment is provisioned into.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Resolves the shared course and the experiment's own section within it.
 *
 * The suite used to create a hidden course per run. A sweep of a hundred
 * replications therefore produced a hundred courses for one experimental
 * condition, which is unusable both administratively and for looking at what
 * actually happened. The structure is now:
 *
 *     one configured course
 *       └── one section per experiment
 *             └── one adaptivequiz per run
 *
 * The target course is a deployment setting, not an experimental factor: it
 * says where this installation puts its activities, and moving a study to
 * another Moodle should not change its scientific definition. It therefore
 * lives in the plugin configuration rather than in the portable experiment
 * JSON, and it is never created silently — an unconfigured course is an error
 * a person has to resolve, not a reason to invent one.
 */
class experiment_container {
    /** @var string The plugin setting holding the shared course id. */
    public const SETTING_COURSE = 'experimentcourseid';

    /** @var string No target course has been configured. */
    public const REASON_NO_COURSE = 'no-experiment-course-configured';

    /** @var string The configured course no longer exists. */
    public const REASON_COURSE_MISSING = 'experiment-course-missing';

    /** @var string The section could not be created. */
    public const REASON_NO_SECTION = 'experiment-section-failed';

    /**
     * The configured shared course id, or 0 when none is set.
     *
     * @return int
     */
    public static function configured_course(): int {
        return (int) (get_config('local_catquizlab', self::SETTING_COURSE) ?: 0);
    }

    /**
     * The configured course record, or null when it is unset or gone.
     *
     * @return \stdClass|null
     */
    public static function course(): ?\stdClass {
        global $DB;

        $courseid = self::configured_course();
        if ($courseid <= 0) {
            return null;
        }
        $course = $DB->get_record('course', ['id' => $courseid]);

        return $course ?: null;
    }

    /**
     * Resolve the container of an experiment, creating its section if needed.
     *
     * Idempotent: an experiment that already has a section keeps it, so
     * provisioning the same sweep twice does not leave two sections behind.
     *
     * @param int $experimentid The experiment.
     * @return array{ok: bool, courseid: int, sectionid: int, sectionnum: int, reason: ?string}
     */
    public static function provision(int $experimentid): array {
        global $DB, $CFG;

        $failure = static function (string $reason): array {
            return ['ok' => false, 'courseid' => 0, 'sectionid' => 0, 'sectionnum' => 0, 'reason' => $reason];
        };

        $courseid = self::configured_course();
        if ($courseid <= 0) {
            return $failure(self::REASON_NO_COURSE);
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return $failure(self::REASON_COURSE_MISSING);
        }

        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid], '*', MUST_EXIST);

        // An existing section is reused, as long as it is still there and still
        // belongs to the configured course.
        if (!empty($experiment->sectionid)) {
            $section = $DB->get_record('course_sections', [
                'id'     => $experiment->sectionid,
                'course' => $courseid,
            ]);
            if ($section) {
                return [
                    'ok'         => true,
                    'courseid'   => $courseid,
                    'sectionid'  => (int) $section->id,
                    'sectionnum' => (int) $section->section,
                    'reason'     => null,
                ];
            }
        }

        require_once($CFG->dirroot . '/course/lib.php');

        $section = self::create_section($course, self::section_name($experiment));
        if ($section === null) {
            return $failure(self::REASON_NO_SECTION);
        }

        $DB->update_record('local_catquizlab_experiment', (object) [
            'id'           => $experimentid,
            'courseid'     => $courseid,
            'sectionid'    => (int) $section->id,
            'timemodified' => time(),
        ]);

        return [
            'ok'         => true,
            'courseid'   => $courseid,
            'sectionid'  => (int) $section->id,
            'sectionnum' => (int) $section->section,
            'reason'     => null,
        ];
    }

    /**
     * The container of an experiment as already provisioned, without creating one.
     *
     * @param int $experimentid The experiment.
     * @return array{courseid: int, sectionid: int, sectionnum: int}
     */
    public static function existing(int $experimentid): array {
        global $DB;

        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid]);
        if (!$experiment || empty($experiment->sectionid)) {
            return ['courseid' => 0, 'sectionid' => 0, 'sectionnum' => 0];
        }
        $section = $DB->get_record('course_sections', ['id' => $experiment->sectionid]);

        return [
            'courseid'   => (int) ($experiment->courseid ?? 0),
            'sectionid'  => $section ? (int) $section->id : 0,
            'sectionnum' => $section ? (int) $section->section : 0,
        ];
    }

    /**
     * The question category an experiment's items live in.
     *
     * One category per experiment, for the same reason there is one section per
     * experiment: a shared category becomes unreadable after a few sweeps, and
     * an item cannot be traced to the study it belongs to without consulting
     * the lab's own tables. It also lets the item name stand on its own, since
     * uniqueness only has to hold within the category.
     *
     * Created under the course context, so it is removed with the course rather
     * than outliving it in the system context.
     *
     * @param int $experimentid The experiment.
     * @return int The category id, or 0 when there is no container to put it in.
     */
    public static function question_category(int $experimentid): int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid]);
        if (!$experiment) {
            return 0;
        }

        // The experiment's course, or the configured one when it has not been
        // provisioned yet. Materialisation runs before the container stage, so
        // requiring the course to be recorded already left every run without a
        // category and the whole stage returned nothing.
        $courseid = (int) ($experiment->courseid ?: self::configured_course());
        if ($courseid <= 0) {
            return 0;
        }

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return 0;
        }

        $name = get_string('container:questioncategory', 'local_catquizlab', (object) [
            'id'   => (int) $experiment->id,
            'name' => format_string($experiment->name),
        ]);

        $existing = $DB->get_record('question_categories', [
            'contextid' => $context->id,
            'name'      => $name,
        ]);
        if ($existing) {
            return (int) $existing->id;
        }

        $parent = $DB->get_record('question_categories', ['contextid' => $context->id, 'parent' => 0]);

        return (int) $DB->insert_record('question_categories', (object) [
            'name'         => $name,
            'contextid'    => $context->id,
            'info'         => get_string('container:questioncategoryinfo', 'local_catquizlab'),
            'infoformat'   => FORMAT_HTML,
            'stamp'        => make_unique_id_code(),
            'parent'       => $parent ? (int) $parent->id : 0,
            'sortorder'    => 999,
            'idnumber'     => null,
        ]);
    }

    /**
     * The section name of an experiment.
     *
     * The creation time rather than the current time, so the name of a section
     * does not depend on when it happened to be provisioned.
     *
     * @param \stdClass $experiment The experiment record.
     * @return string
     */
    public static function section_name(\stdClass $experiment): string {
        return get_string('container:sectionname', 'local_catquizlab', (object) [
            'id'   => (int) $experiment->id,
            'when' => userdate((int) $experiment->timecreated, get_string('strftimedatetimeshort')),
        ]);
    }

    /**
     * The activity name of a run.
     *
     * The run id stays in front of the cell key, which can be long and is
     * truncated in most course listings: the identifier has to survive.
     *
     * @param \stdClass $run The run record.
     * @return string
     */
    public static function activity_name(\stdClass $run): string {
        $cellkey = trim((string) ($run->cellkey ?? ''));

        // An experiment with no swept factors has an empty cell key, and the
        // full pattern then reads "Run #9 –  – Rep 1" with a gap where the
        // condition should be. A run without conditions simply has none.
        $key = $cellkey === '' ? 'container:activitynamenocell' : 'container:activityname';

        return get_string($key, 'local_catquizlab', (object) [
            'runid'       => (int) $run->id,
            'cellkey'     => $cellkey,
            'replication' => (int) ($run->replication ?? 1),
        ]);
    }

    /**
     * Append a section to a course and give it a name.
     *
     * @param \stdClass $course The course.
     * @param string $name The section name.
     * @return \stdClass|null The section record, or null when it could not be created.
     */
    protected static function create_section(\stdClass $course, string $name): ?\stdClass {
        global $DB;

        $last = (int) $DB->get_field_sql(
            'SELECT MAX(section) FROM {course_sections} WHERE course = :course',
            ['course' => $course->id]
        );
        $number = $last + 1;

        // The helper course_create_section() exists in every supported release;
        // the fallback keeps this working if a future one renames it rather than
        // failing the whole provisioning over a helper.
        if (function_exists('course_create_section')) {
            $section = course_create_section($course, $number);
        } else {
            $sectionid = $DB->insert_record('course_sections', (object) [
                'course'        => $course->id,
                'section'       => $number,
                'name'          => null,
                'summary'       => '',
                'summaryformat' => FORMAT_HTML,
                'sequence'      => '',
                'visible'       => 1,
                'timemodified'  => time(),
            ]);
            $section = $DB->get_record('course_sections', ['id' => $sectionid]);
        }

        if (!$section) {
            return null;
        }

        $DB->set_field('course_sections', 'name', $name, ['id' => $section->id]);
        rebuild_course_cache((int) $course->id, true);

        return $DB->get_record('course_sections', ['id' => $section->id]);
    }

    /**
     * A readable explanation of a container failure.
     *
     * @param string $reason One of the REASON_* constants.
     * @return string
     */
    public static function reason_label(string $reason): string {
        $keys = [
            self::REASON_NO_COURSE      => 'container:nocourse',
            self::REASON_COURSE_MISSING => 'container:coursemissing',
            self::REASON_NO_SECTION     => 'container:nosection',
        ];

        return isset($keys[$reason]) ? get_string($keys[$reason], 'local_catquizlab') : $reason;
    }
}
