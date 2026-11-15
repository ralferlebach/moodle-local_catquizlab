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

namespace local_catquizlab\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_catquizlab\local\experiment_metadata;

/**
 * Name and description of an experiment whose design is locked by its runs (#152).
 *
 * Posted with the session key (moodleform), with the version the editor saw, so that a change made meanwhile by
 * somebody else is refused rather than overwritten.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class metadata_form extends \moodleform {
    /**
     * The two fields, the experiment and the version seen.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $component = 'local_catquizlab';

        $mform->addElement('header', 'metadata', get_string('metadata:heading', $component));
        $mform->setExpanded('metadata', true);
        $mform->addElement('static', 'metadatahelp', '', get_string('metadata:help', $component));
        // Own field names: the locked experiment form on the same page has its own "name" and "description", and two
        // inputs with the id "id_name" broke the labels for screen readers (found by the browser test of #152).
        $mform->addElement(
            'text',
            'metadataname',
            get_string('form:name', $component),
            ['maxlength' => experiment_metadata::NAME_MAX, 'size' => 60]
        );
        $mform->setType('metadataname', PARAM_TEXT);
        $mform->addRule('metadataname', null, 'required', null, 'client');
        $mform->addRule(
            'metadataname',
            get_string('metadata:errorlongname', $component),
            'maxlength',
            experiment_metadata::NAME_MAX,
            'client'
        );
        $mform->addElement(
            'textarea',
            'metadatadescription',
            get_string('form:description', $component),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('metadatadescription', PARAM_TEXT);
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'seen');
        $mform->setType('seen', PARAM_INT);
        $mform->addElement('hidden', 'action', 'metadata');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('submit', 'savemetadata', get_string('metadata:save', $component));
    }

    /**
     * Server-side checks of the two fields.
     *
     * @param array $data The submitted data.
     * @param array $files Files.
     * @return array Field => error.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $component = 'local_catquizlab';
        $name = trim((string) ($data['metadataname'] ?? ''));
        if ($name === '') {
            $errors['metadataname'] = get_string('metadata:errorempty', $component);
        } else if (\core_text::strlen($name) > experiment_metadata::NAME_MAX) {
            $errors['metadataname'] = get_string('metadata:errorlongname', $component);
        }
        if (\core_text::strlen((string) ($data['metadatadescription'] ?? '')) > experiment_metadata::DESCRIPTION_MAX) {
            $errors['metadatadescription'] = get_string('metadata:errorlongdescription', $component);
        }

        return $errors;
    }
}
