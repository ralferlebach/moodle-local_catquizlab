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
 * Test binder: bind a run to an adaptivequiz CAT test.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Binds a run to the adaptivequiz activity that carries its CAT test (E2.4).
 *
 * A run's simulated persons sit one CAT test, realised as an adaptivequiz
 * activity whose catquiz settings live in local_catquiz_tests (component
 * mod_adaptivequiz). This class resolves an existing such activity by its
 * course-module id, reads its CAT configuration (scale, engine context and
 * quiz settings — the same rows the Wunderbyte scripts read), and records it on
 * the run (run.testcmid). Creating a new adaptivequiz+catquiz test from a
 * definition is a separate step that needs the activity's form fields.
 *
 * Resolving the config needs the engine and the host activity, so the public
 * methods return null when either is absent (CI and stand-alone stay green).
 */
class test_binder {
    /** @var string The component under which adaptivequiz CAT tests are registered. */
    protected const TEST_COMPONENT = 'mod_adaptivequiz';

    /**
     * Bind a run to an existing adaptivequiz CAT test.
     *
     * @param int $runid The run to bind.
     * @param int $testcmid The course-module id of the adaptivequiz activity.
     * @return array|null The test configuration, or null when unavailable / not a CAT test.
     */
    public static function bind_existing(int $runid, int $testcmid): ?array {
        global $DB;

        if (!environment::engine_available() || !environment::adaptivequiz_available()) {
            return null;
        }

        $config = self::read_test_config($testcmid);
        if ($config === null) {
            return null;
        }

        $DB->set_field('local_catquizlab_run', 'testcmid', $testcmid, ['id' => $runid]);

        return $config;
    }

    /**
     * Read the CAT test configuration for an adaptivequiz course-module.
     *
     * @param int $testcmid The course-module id of the adaptivequiz activity.
     * @return array|null The configuration, or null when the module is not a catquiz test.
     */
    public static function read_test_config(int $testcmid): ?array {
        global $DB;

        $sql = "SELECT aq.id AS adaptivequizid, aq.name AS testname, cm.course AS courseid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'adaptivequiz'
                  JOIN {adaptivequiz} aq ON aq.id = cm.instance
                 WHERE cm.id = :cmid";
        $activity = $DB->get_record_sql($sql, ['cmid' => $testcmid]);
        if (!$activity) {
            return null;
        }

        // The local_catquiz_tests row has no contextid column; the CAT context is
        // resolved from the scale by the engine (walking up the scale tree to the
        // default context). We mirror that via \local_catquiz\catscale.
        $test = $DB->get_record('local_catquiz_tests', [
            'componentid' => $activity->adaptivequizid,
            'component'   => self::TEST_COMPONENT,
        ]);
        if (!$test) {
            return null;
        }

        $catscaleid = (int) $test->catscaleid;

        return [
            'testcmid'       => $testcmid,
            'adaptivequizid' => (int) $activity->adaptivequizid,
            'testname'       => (string) $activity->testname,
            'courseid'       => (int) $activity->courseid,
            'catscaleid'     => $catscaleid,
            'contextid'      => self::context_for_scale($catscaleid),
            'quizsettings'   => self::decode_settings($test),
        ];
    }

    /**
     * Resolve the engine context id for a scale, mirroring the engine.
     *
     * @param int $catscaleid The catquiz scale id.
     * @return int The context id, or 0 when the engine cannot resolve it.
     */
    protected static function context_for_scale(int $catscaleid): int {
        if ($catscaleid <= 0 || !class_exists('\local_catquiz\catscale')) {
            return 0;
        }
        return (int) \local_catquiz\catscale::get_context_id($catscaleid);
    }

    /**
     * Decode the stored quiz settings JSON of a CAT test row.
     *
     * @param \stdClass $test The local_catquiz_tests row.
     * @return array|null The decoded settings, or null when absent/invalid.
     */
    protected static function decode_settings(\stdClass $test): ?array {
        if (empty($test->json)) {
            return null;
        }
        $decoded = json_decode((string) $test->json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
