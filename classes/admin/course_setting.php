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
 * The experiment-course setting.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\admin;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/adminlib.php');

/**
 * A select of the site's courses, loaded only when the setting is displayed.
 *
 * The obvious version — building the option list in settings.php — is a trap.
 * settings.php runs whenever Moodle assembles the admin tree, including from
 * inside the navigation, and formatting a course name there sets up the filter
 * subsystem, which asks for the admin tree again. The result is a recursive
 * build that surfaces as "Duplicate admin page name: adminnotifications" on
 * every admin page of the site, not just on this one.
 *
 * Overriding load_choices() is what the setting API provides for exactly this:
 * the query runs when the setting is rendered or validated, and never while the
 * tree is being built.
 */
class course_setting extends \admin_setting_configselect {
    /** @var int How many courses the picker offers before it gives up on being a picker. */
    protected const MAX_COURSES = 500;

    /**
     * Load the course list.
     *
     * @return bool True once the choices are available.
     */
    public function load_choices(): bool {
        global $DB;

        if (is_array($this->choices)) {
            return true;
        }

        $this->choices = [0 => get_string('setting:nocourse', 'local_catquizlab')];

        // The site course is not a place to put experiment activities.
        $courses = $DB->get_records_select(
            'course',
            'id <> :siteid',
            ['siteid' => SITEID],
            'shortname ASC',
            'id, shortname, fullname',
            0,
            self::MAX_COURSES
        );

        foreach ($courses as $course) {
            // The short name leads, because it is what tells two courses called
            // "CAT experiments" apart. No format_string() here: this can run
            // during a settings page build, and filters must stay out of it.
            $this->choices[(int) $course->id] = s($course->shortname) . ' — ' . s($course->fullname);
        }

        return true;
    }
}
