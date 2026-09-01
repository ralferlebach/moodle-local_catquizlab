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
 * The experiment import form.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\form;

use local_catquizlab\local\experiment_io;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Uploads a JSON experiment definition.
 *
 * The upload is bounded and restricted to .json, and the file is never read as
 * anything but data. Confirmation is a separate checkbox because the first
 * submit only produces a preview: an import that stored immediately would give
 * the author no chance to see the migrations or the name conflict first.
 */
class import_form extends \moodleform {
    /**
     * Build the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_catquizlab';

        $mform->addElement('filepicker', 'definitionfile', get_string('import:file', $component), null, [
            'maxbytes'   => experiment_io::MAX_BYTES,
            'accepted_types' => ['.json'],
        ]);
        $mform->addRule('definitionfile', null, 'required', null, 'client');
        $mform->addHelpButton('definitionfile', 'import:file', $component);

        $mform->addElement('select', 'conflictmode', get_string('import:conflictmode', $component), [
            experiment_io::CONFLICT_NEW     => get_string('import:asnew', $component),
            experiment_io::CONFLICT_VERSION => get_string('import:asversion', $component),
            experiment_io::CONFLICT_REPLACE => get_string('import:replacedraft', $component),
        ]);
        $mform->setDefault('conflictmode', experiment_io::CONFLICT_NEW);
        $mform->addHelpButton('conflictmode', 'import:conflictmode', $component);

        $mform->addElement('advcheckbox', 'confirmimport', get_string('import:confirm', $component));
        $mform->addHelpButton('confirmimport', 'import:confirm', $component);

        $this->add_action_buttons(true, get_string('import:submit', $component));
    }
}
