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

namespace local_catquizlab\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * The settings that matter, on the page where the work happens.
 *
 * These live in the Moodle settings tree as well, and that is the right place
 * for a site administrator configuring a plugin they will never open again. It
 * is the wrong place for somebody running an experiment, who then has to leave
 * the plugin, find a settings section, come back, and remember which of the two
 * pages showed the state and which one changed it.
 *
 * Only the settings an operator actually turns are here. The rest — timeouts,
 * retry counts, log verbosity — stay in the settings tree, where they belong.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_form extends \moodleform {
    /**
     * Build the form.
     *
     * @return void
     */
    protected function definition(): void {
        global $DB;

        $mform = $this->_form;
        $component = 'local_catquizlab';

        $mform->addElement('header', 'general', get_string('settingsform:heading', $component));
        $mform->setExpanded('general', true);

        // The course, as a chooser rather than an id to look up elsewhere.
        $courses = ['0' => get_string('settingsform:nocourse', $component)];
        foreach (
            $DB->get_records_select(
                'course',
                'id <> :site',
                ['site' => SITEID],
                'fullname ASC',
                'id, fullname, shortname',
                0,
                200
            ) as $course
        ) {
            $courses[$course->id] = format_string($course->fullname) . ' (' . $course->shortname . ')';
        }
        $mform->addElement('select', 'experimentcourseid', get_string('health:course', $component), $courses);
        $mform->addHelpButton('experimentcourseid', 'settingsform:course', $component);

        $mform->addElement('advcheckbox', 'enabled', get_string('wizard:masterswitch', $component));
        $mform->addElement('text', 'worker_base_url', get_string('runtime:baseurl', $component), ['size' => 50]);
        $mform->setType('worker_base_url', PARAM_URL);

        $mform->addElement('text', 'worker_node_path', get_string('health:node', $component), ['size' => 50]);
        $mform->setType('worker_node_path', PARAM_RAW_TRIMMED);

        $mform->addElement(
            'text',
            'worker_concurrency',
            get_string('settingsform:concurrency', $component),
            ['size' => 5]
        );
        $mform->setType('worker_concurrency', PARAM_INT);
        $mform->addHelpButton('worker_concurrency', 'settingsform:concurrency', $component);

        $mform->addElement('text', 'worker_max_jobs', get_string('settingsform:maxjobs', $component), ['size' => 5]);
        $mform->setType('worker_max_jobs', PARAM_INT);

        // Deliberately shown, not hidden: an operator who can see it is empty
        // understands why nothing is running. It is read-only because the
        // plugin creates it — typing one in by hand is the thing this page
        // exists to make unnecessary.
        $token = (string) get_config($component, 'worker_token');
        $mform->addElement(
            'static',
            'tokenstate',
            get_string('setting:worker_token', $component),
            $token === ''
                ? get_string('settingsform:notoken', $component)
            : get_string('settingsform:tokenpresent', $component, \core_text::substr($token, 0, 6))
        );

        $mform->addElement('select', 'debuglevel', get_string('debug:level', $component), [
            'off'     => get_string('debug:leveloff', $component),
            'action'  => get_string('debug:levelaction', $component),
            'verbose' => get_string('debug:levelverbose', $component),
            'trace'   => get_string('debug:leveltrace', $component),
        ]);
        $mform->addHelpButton('debuglevel', 'debug:level', $component);

        $this->add_action_buttons(false, get_string('settingsform:save', $component));
    }

    /**
     * Check the values before they are stored.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $component = 'local_catquizlab';

        if ((int) $data['worker_concurrency'] < 1) {
            $errors['worker_concurrency'] = get_string('settingsform:concurrencytoolow', $component);
        }

        if ((int) $data['worker_max_jobs'] < 0) {
            $errors['worker_max_jobs'] = get_string('settingsform:maxjobstoolow', $component);
        }

        $node = trim((string) $data['worker_node_path']);
        if ($node !== '' && !is_executable($node)) {
            // Checked here rather than discovered later by a worker that cannot
            // start: the error is the same either way, but here it arrives
            // while somebody is looking at the field.
            $errors['worker_node_path'] = get_string('settingsform:nodenotexecutable', $component);
        }

        return $errors;
    }
}
