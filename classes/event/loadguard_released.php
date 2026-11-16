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
 * Event: a person released the load guard's throttle (#162).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\event;

/**
 * A person ended the load guard's cooldown before its time.
 *
 * The record of who did it, when, why, and what the guard had counted: other holds reason, limitbefore, limitafter,
 * configured, cap, until (the cooldown's end that was lifted), failures, executions, causes and probation (until when a
 * new trip counts as the same problem again).
 */
class loadguard_released extends \core\event\base {
    /**
     * Initialise the event data.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:loadguard_released', 'local_catquizlab');
    }

    /**
     * A human-readable description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        $other = (array) $this->other;

        return "The user with id '{$this->userid}' released the load guard's throttle: the worker limit went from "
            . (int) ($other['limitbefore'] ?? 0) . ' to ' . (int) ($other['limitafter'] ?? 0) . ' (configured '
            . (int) ($other['configured'] ?? 0) . ', safety cap ' . (int) ($other['cap'] ?? 0) . ').';
    }

    /**
     * The other fields this event must carry.
     *
     * @return void
     */
    protected function validate_data(): void {
        parent::validate_data();
        foreach (['reason', 'limitbefore', 'limitafter', 'failures', 'executions'] as $key) {
            if (!array_key_exists($key, $this->other ?? [])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }
}
