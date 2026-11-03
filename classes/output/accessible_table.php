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

namespace local_catquizlab\output;

/**
 * A table that can be scrolled from the keyboard (WCAG 2.1.1; axe scrollable-region-focusable).
 *
 * Moodle wraps a responsive table in a scrolling box that a keyboard cannot reach: a wide table — the raw data,
 * the step export — could only be scrolled with a mouse. The box here is a named region in the tab order.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class accessible_table {
    /**
     * The table, in a scrolling box that takes the focus and says what it holds.
     *
     * @param \html_table $table The table.
     * @param string $label What the table holds; the table's caption, or a general name where it has none.
     * @return string
     */
    public static function render(\html_table $table, string $label = ''): string {
        $label = trim($label !== '' ? $label : strip_tags((string) ($table->caption ?? '')));
        $responsive = $table->responsive;
        $table->responsive = false;
        $html = \html_writer::table($table);
        $table->responsive = $responsive;
        if (!$responsive) {
            return $html;
        }

        return \html_writer::div($html, 'table-responsive', [
            'tabindex' => '0',
            'role' => 'region',
            'aria-label' => $label !== '' ? $label : get_string('table:scrollable', 'local_catquizlab'),
        ]);
    }
}
