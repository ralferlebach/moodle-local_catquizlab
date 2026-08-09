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
 * Admin settings for local_catquizlab.
 *
 * The stub exposes the two switches every later component keys on — whether
 * experiment runs are enabled at all, and whether this instance acts as a
 * data-collecting node or as the central recalculation hub — plus a read-only
 * status line showing whether the CAT engine (local_catquiz +
 * mod_adaptivequiz) is installed on this site.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_catquizlab\local\environment;

if ($hassiteconfig) {
    $component = 'local_catquizlab';

    $settings = new admin_settingpage(
        'local_catquizlab_settings',
        get_string('pluginname', $component)
    );
    $ADMIN->add('localplugins', $settings);

    // Read-only environment status: is the engine here?
    $envlines = [];
    $envlines[] = environment::catquiz_available()
        ? get_string('env:catquizfound', $component)
        : get_string('env:catquizmissing', $component);
    $envlines[] = environment::adaptivequiz_available()
        ? get_string('env:adaptivequizfound', $component)
        : get_string('env:adaptivequizmissing', $component);

    $settings->add(new admin_setting_heading(
        $component . '/environment',
        get_string('setting:environment', $component),
        implode('<br>', $envlines)
    ));

    // Master switch: no experiment component acts while this is off.
    $settings->add(new admin_setting_configcheckbox(
        $component . '/enabled',
        get_string('setting:enabled', $component),
        get_string('setting:enabled_desc', $component),
        0
    ));

    // Instance role: node (runs experiments, collects data) or hub (central
    // recalculation instance that receives run packages from nodes).
    $settings->add(new admin_setting_configselect(
        $component . '/instancerole',
        get_string('setting:instancerole', $component),
        get_string('setting:instancerole_desc', $component),
        'node',
        [
            'node' => get_string('instancerole:node', $component),
            'hub'  => get_string('instancerole:hub', $component),
        ]
    ));
}
