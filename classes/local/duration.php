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

namespace local_catquizlab\local;

/**
 * Durations, in units a reader can hold.
 *
 * `30720 s` is a correct answer to a question nobody asked. Somebody looking at
 * a retry delay wants to know whether it is worth waiting for, and eight and a
 * half hours says that where thirty thousand seconds does not.
 *
 * Moodle's `format_time()` does this well for whole seconds, and this wraps it
 * for the two cases it handles badly: a measured step that took a fraction of a
 * second, where "0 secs" loses the measurement, and a zero, where "now" is
 * clearer than "0 secs".
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class duration {
    /**
     * A span of time, as a person would say it.
     *
     * @param float $seconds How long.
     * @param bool $zeroisnow Whether zero should read as "now" rather than "0 secs".
     * @return string
     */
    public static function human(float $seconds, bool $zeroisnow = false): string {
        $component = 'local_catquizlab';

        if ($seconds <= 0) {
            return $zeroisnow
                ? get_string('duration:now', $component)
                : get_string('duration:none', $component);
        }

        // Below a second, format_time() rounds to "0 secs" and the measurement
        // is gone. A stage that took 0.7 s is worth saying so.
        if ($seconds < 1) {
            return get_string('duration:subsecond', $component, number_format($seconds, 2));
        }

        if ($seconds < 60) {
            return get_string('duration:seconds', $component, (int) round($seconds));
        }

        return format_time((int) round($seconds));
    }

    /**
     * How long ago something was.
     *
     * @param int $timestamp When it happened, or 0 for never.
     * @return string
     */
    public static function ago(int $timestamp): string {
        $component = 'local_catquizlab';

        if ($timestamp <= 0) {
            return get_string('duration:never', $component);
        }

        return get_string('duration:ago', $component, self::human(time() - $timestamp));
    }

    /**
     * How long until something is due.
     *
     * @param int $timestamp When it is due.
     * @return string
     */
    public static function until(int $timestamp): string {
        $component = 'local_catquizlab';

        $left = $timestamp - time();
        if ($left <= 0) {
            return get_string('duration:due', $component);
        }

        return get_string('duration:in', $component, self::human($left));
    }
}
