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
 * Event: an experiment's name or description was changed (#152).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\event;

/**
 * An experiment's name or description was changed; the experimental design was not.
 *
 * The history of the presentation metadata: who changed what, when, from what to what. other holds oldname, newname,
 * olddescription, newdescription and designhash (the same before and after).
 */
class experiment_metadata_updated extends \core\event\base {
    /**
     * Initialise the event data.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_catquizlab_experiment';
    }

    /**
     * The localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:experiment_metadata_updated', 'local_catquizlab');
    }

    /**
     * A human-readable description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' changed the name or description of experiment '{$this->objectid}'; "
            . "its experimental design is unchanged.";
    }

    /**
     * The other fields this event must carry.
     *
     * @return void
     */
    protected function validate_data(): void {
        parent::validate_data();
        foreach (['oldname', 'newname', 'designhash'] as $key) {
            if (!array_key_exists($key, $this->other ?? [])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }
}
