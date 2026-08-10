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
 * Naming engine: expands name patterns into concrete, systematic names.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Turns a name pattern plus values into a concrete name (requirement 2.6.D).
 *
 * Patterns contain placeholders in curly braces: {key} inserts a value, and
 * {key:0Nd} inserts an integer zero-padded to N digits (for example
 * {index:04d} yields 0007). The expansion is deterministic and reproducible, so
 * the same values always produce the same name. It is a pure utility with no
 * side effects; the provisioning step (E2) uses it to name simulated persons
 * and generated items and questions.
 */
class naming {
    /**
     * Expand a single pattern against a set of values.
     *
     * @param string $pattern The name pattern, e.g. 'P-{stratum}-{index:04d}'.
     * @param array $values Map of placeholder key to value.
     * @return string The expanded name.
     * @throws \invalid_parameter_exception If a referenced placeholder has no value.
     */
    public static function expand(string $pattern, array $values): string {
        return (string) preg_replace_callback(
            '/\{(\w+)(?::(\d*)d)?\}/',
            static function (array $match) use ($values): string {
                $key = $match[1];
                if (!array_key_exists($key, $values)) {
                    throw new \invalid_parameter_exception(
                        get_string('naming:unknownplaceholder', 'local_catquizlab', $key)
                    );
                }
                $value = $values[$key];

                // A width given as {key:0Nd} zero-pads an integer value.
                if (isset($match[2]) && $match[2] !== '') {
                    return str_pad((string) (int) $value, (int) $match[2], '0', STR_PAD_LEFT);
                }
                return (string) $value;
            },
            $pattern
        );
    }

    /**
     * Expand a pattern once per index to produce a sequence of names.
     *
     * @param string $pattern The name pattern.
     * @param array $values Base values shared by every name.
     * @param int $count How many names to generate.
     * @param string $indexkey Placeholder key that receives the running index.
     * @param int $start First index value.
     * @return string[] The generated names, in index order.
     */
    public static function sequence(
        string $pattern,
        array $values,
        int $count,
        string $indexkey = 'index',
        int $start = 1
    ): array {
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $names[] = self::expand($pattern, [$indexkey => $start + $i] + $values);
        }
        return $names;
    }
}
