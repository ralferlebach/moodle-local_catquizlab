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
 * Callbacks for local_catquizlab.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Render the CAT experiment suite button into the navbar.
 *
 * Moodle concatenates the output of every plugin's *_render_navbar_output
 * callback into the same navbar region, so implementing this callback places
 * our button directly alongside the engine's CATQUIZ button. It is shown only
 * to users who may manage the suite; everyone else sees nothing.
 *
 * @param \renderer_base $renderer The page renderer (unused, part of the callback signature).
 * @return string HTML for the navbar, or an empty string when the button must not appear.
 */
function local_catquizlab_render_navbar_output(\renderer_base $renderer): string {
    unset($renderer);

    if (!isloggedin() || isguestuser()) {
        return '';
    }
    if (!has_capability('local/catquizlab:manage', \context_system::instance())) {
        return '';
    }

    $url = new moodle_url('/local/catquizlab/index.php');
    $label = get_string('navbarbutton', 'local_catquizlab');

    // Icon-only button: a cat glyph. The label is kept as the accessible name
    // (title + aria-label) so the control stays usable without visible text.
    $icon = \html_writer::tag('i', '', [
        'class'       => 'icon fa fa-cat fa-fw',
        'aria-hidden' => 'true',
    ]);

    return \html_writer::div(
        \html_writer::link($url, $icon, [
            'class'      => 'nav-link',
            'role'       => 'button',
            'id'         => 'local-catquizlab-navbarbutton',
            'title'      => $label,
            'aria-label' => $label,
        ]),
        'popover-region icon-no-margin'
    );
}
