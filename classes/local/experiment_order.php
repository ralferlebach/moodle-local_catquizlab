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

namespace local_catquizlab\local;

/**
 * The one order experiments are shown in: A–Z by their visible name (#153).
 *
 * The library sorted by last change, the selector on every tab by last change with the first 50 only, the overview
 * by name: an experiment moved while somebody worked with it and older ones fell out of the selector. Every list of
 * experiments now uses this order.
 *
 * The comparison is the user's language's collation (accents and umlauts where that language puts them, case
 * ignored, numbers by value: "Run 9" before "Run 10"); names that compare equal stay in id order, so the order is the
 * same on every page and every reload. Only the presentation: the order runs and sittings are executed in is
 * untouched.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class experiment_order {
    /** @var \Collator|null The collator of this request's language. */
    protected static ?\Collator $collator = null;

    /** @var string The language it was made for. */
    protected static string $language = '';

    /**
     * Compare two names, then two ids.
     *
     * @param string $a One name.
     * @param int $aid Its experiment id.
     * @param string $b The other name.
     * @param int $bid Its experiment id.
     * @return int
     */
    public static function compare(string $a, int $aid, string $b, int $bid): int {
        $collator = self::collator();
        $byname = $collator !== null ? (int) $collator->compare($a, $b) : strnatcasecmp($a, $b);

        return $byname !== 0 ? $byname : ($aid <=> $bid);
    }

    /**
     * Sort rows of experiments A–Z, keys kept.
     *
     * @param array $rows Arrays or objects, each with a name and an id.
     * @param string $name The name field.
     * @param string $id The id field.
     * @return array The rows, sorted.
     */
    public static function sort(array $rows, string $name = 'name', string $id = 'id'): array {
        $value = static fn($row, string $field) => is_array($row) ? $row[$field] : $row->$field;
        uasort($rows, static fn($a, $b): int => self::compare(
            (string) $value($a, $name),
            (int) $value($a, $id),
            (string) $value($b, $name),
            (int) $value($b, $id)
        ));

        return $rows;
    }

    /**
     * The collator of the current language: case ignored, numbers by value.
     *
     * @return \Collator|null Null without the intl extension (then natural, case-insensitive byte order).
     */
    protected static function collator(): ?\Collator {
        $language = current_language();
        if (self::$language !== $language) {
            self::$language = $language;
            self::$collator = null;
            if (class_exists('\Collator')) {
                // A C locale such as "de_DE.UTF-8": ICU wants "de_DE".
                $locale = explode('.', (string) get_string('locale', 'langconfig'))[0];
                $collator = new \Collator($locale !== '' ? $locale : 'en');
                // Secondary: accents count, case does not.
                $collator->setStrength(\Collator::SECONDARY);
                $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
                self::$collator = $collator;
            }
        }

        return self::$collator;
    }
}
