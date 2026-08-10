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
 * Upgrade steps for local_catquizlab.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute the local_catquizlab upgrade from the given old version.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool Always true on success.
 */
function xmldb_local_catquizlab_upgrade($oldversion): bool {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081000) {
        // Round E0: add the seven tables that turn the single-table stub into
        // the full lab store. The definitions are read straight from the
        // plugin's own install.xml, so a fresh install (which ran install.xml)
        // and an upgraded install converge on the identical schema with no
        // duplicated table definitions to drift apart.
        $newtables = [
            'local_catquizlab_run',
            'local_catquizlab_pool',
            'local_catquizlab_person',
            'local_catquizlab_attempt',
            'local_catquizlab_result',
            'local_catquizlab_exportlog',
            'local_catquizlab_transfer',
        ];

        $xmldbfile = new xmldb_file($CFG->dirroot . '/local/catquizlab/db/install.xml');
        $xmldbfile->loadXMLStructure();
        $structure = $xmldbfile->getStructure();

        foreach ($newtables as $tablename) {
            if (!$dbman->table_exists($tablename)) {
                $dbman->create_table($structure->getTable($tablename));
            }
        }

        upgrade_plugin_savepoint(true, 2026081000, 'local', 'catquizlab');
    }

    return true;
}
