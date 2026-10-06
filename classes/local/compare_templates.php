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

/**
 * Saved comparisons.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * A comparison saved by name, per user (#109).
 *
 * Metric, colouring, subscale, layout and axis ranges: the same view of other
 * twin families, runs or experiments, without setting it up again.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compare_templates {
    /** @var string The user preference holding them. */
    protected const PREFERENCE = 'local_catquizlab_comparetemplates';

    /** @var string[] What a template keeps. */
    public const FIELDS = ['metric', 'group', 'scale', 'layout', 'xmin', 'xmax', 'ymin', 'ymax'];

    /**
     * The user's templates.
     *
     * @return array<string, array>
     */
    public static function mine(): array {
        $stored = json_decode((string) get_user_preferences(self::PREFERENCE, '{}'), true);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Save a template.
     *
     * @param string $name Its name.
     * @param array $settings What to keep.
     * @return void
     */
    public static function save(string $name, array $settings): void {
        $name = trim(\core_text::substr(clean_param($name, PARAM_TEXT), 0, 60));
        if ($name === '') {
            return;
        }
        $clean = [];
        foreach (self::FIELDS as $field) {
            $value = $settings[$field] ?? null;
            $clean[$field] = in_array($field, ['metric', 'group', 'layout'], true)
                ? clean_param((string) $value, PARAM_ALPHA)
                : (is_numeric($value) ? (float) $value : null);
        }
        $clean['scale'] = (int) ($settings['scale'] ?? 0);
        $mine = self::mine();
        $mine[$name] = $clean;
        set_user_preference(self::PREFERENCE, json_encode($mine));
    }
}
