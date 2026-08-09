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
 * Runtime environment checks for local_catquizlab.
 *
 * The experiment suite drives the CAT engine (local_catquiz) and its host
 * activity (mod_adaptivequiz) strictly as a black box. Neither is declared as
 * a hard dependency in version.php so that the stub installs stand-alone
 * (notably in CI). This class is the single place that answers "is the engine
 * actually here?" — the settings page displays the result, and later
 * components (provisioning, attempt runner) will refuse to start without it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Detects whether the CAT engine plugins are installed on this site.
 */
class environment {
    /**
     * Is the CAT engine (local_catquiz) present on disk?
     *
     * @return bool
     */
    public static function catquiz_available(): bool {
        return \core_component::get_component_directory('local_catquiz') !== null;
    }

    /**
     * Is the host activity (mod_adaptivequiz) present on disk?
     *
     * @return bool
     */
    public static function adaptivequiz_available(): bool {
        return \core_component::get_component_directory('mod_adaptivequiz') !== null;
    }

    /**
     * Are all engine plugins available so that experiments could run?
     *
     * @return bool
     */
    public static function engine_available(): bool {
        return self::catquiz_available() && self::adaptivequiz_available();
    }
}
