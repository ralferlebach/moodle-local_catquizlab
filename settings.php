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

// Management/registry page under Site administration > Reports. Added outside
// the $hassiteconfig gate so a manager with local/catquizlab:manage sees it in
// the Reports list even without full site-config rights; the capability on the
// page controls access. The same URL backs the navbar button (see lib.php).
$ADMIN->add('reports', new admin_externalpage(
    'local_catquizlab_manage',
    get_string('manage:pagetitle', 'local_catquizlab'),
    new moodle_url('/local/catquizlab/index.php'),
    'local/catquizlab:manage'
));

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

    // Local worker (exec variant): the dispatch task launches the Puppeteer
    // worker on this host to drain the attempt queue.
    $settings->add(new admin_setting_heading(
        $component . '/workerheading',
        get_string('setting:worker', $component),
        get_string('setting:worker_desc', $component)
    ));
    $settings->add(new admin_setting_configcheckbox(
        $component . '/worker_exec_enabled',
        get_string('setting:worker_exec_enabled', $component),
        get_string('setting:worker_exec_enabled_desc', $component),
        0
    ));
    $settings->add(new admin_setting_configexecutable(
        $component . '/worker_node_path',
        get_string('setting:worker_node_path', $component),
        get_string('setting:worker_node_path_desc', $component),
        '/usr/bin/node'
    ));
    $settings->add(new admin_setting_configtext(
        $component . '/worker_base_url',
        get_string('setting:worker_base_url', $component),
        get_string('setting:worker_base_url_desc', $component),
        $CFG->wwwroot,
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        $component . '/worker_token',
        get_string('setting:worker_token', $component),
        get_string('setting:worker_token_desc', $component),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        $component . '/worker_max_jobs',
        get_string('setting:worker_max_jobs', $component),
        get_string('setting:worker_max_jobs_desc', $component),
        0,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        $component . '/worker_concurrency',
        get_string('setting:worker_concurrency', $component),
        get_string('setting:worker_concurrency_desc', $component),
        1,
        PARAM_INT
    ));

    // Hub connection (node -> hub submission).
    $settings->add(new admin_setting_heading(
        $component . '/hubheading',
        get_string('setting:hub', $component),
        get_string('setting:hub_desc', $component)
    ));
    $settings->add(new admin_setting_configtext(
        $component . '/hub_url',
        get_string('setting:hub_url', $component),
        get_string('setting:hub_url_desc', $component),
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        $component . '/hub_token',
        get_string('setting:hub_token', $component),
        get_string('setting:hub_token_desc', $component),
        ''
    ));
}
