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

    if ($oldversion < 2026081001) {
        // Correction (architektur.md 2.6.A): different item parameterisations are
        // realised as physically different questions grouped by item scales, not
        // as CAT contexts (which model calibration scopes of the same items and
        // would blur ground truth, tagging and depletion). The pool table drops
        // contextid in favour of scaleid + questioncategoryid.
        $table = new xmldb_table('local_catquizlab_pool');

        $contextid = new xmldb_field('contextid');
        if ($dbman->field_exists($table, $contextid)) {
            $dbman->drop_field($table, $contextid);
        }

        $scaleid = new xmldb_field('scaleid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'recipejson');
        if (!$dbman->field_exists($table, $scaleid)) {
            $dbman->add_field($table, $scaleid);
        }

        $questioncategoryid = new xmldb_field('questioncategoryid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'scaleid');
        if (!$dbman->field_exists($table, $questioncategoryid)) {
            $dbman->add_field($table, $questioncategoryid);
        }

        upgrade_plugin_savepoint(true, 2026081001, 'local', 'catquizlab');
    }

    if ($oldversion < 2026081011) {
        // E2.4: a run records the course its simulated users are enrolled in and
        // the adaptivequiz CAT test's course-module id.
        local_catquizlab_upgrade_add_run_course_columns($dbman);
        upgrade_plugin_savepoint(true, 2026081011, 'local', 'catquizlab');
    }

    if ($oldversion < 2026081033) {
        // E2.1: mapping of a run's materialised engine scales to the profile.
        local_catquizlab_upgrade_add_scalemap_table($dbman);
        upgrade_plugin_savepoint(true, 2026081033, 'local', 'catquizlab');
    }

    if ($oldversion < 2026081048) {
        // E3.1: attempt retry limiting and staggering/backoff.
        local_catquizlab_upgrade_add_attempt_retry_columns($dbman);
        upgrade_plugin_savepoint(true, 2026081048, 'local', 'catquizlab');
    }

    return true;
}

/**
 * Create the local_catquizlab_scalemap table from the install definition.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_catquizlab_upgrade_add_scalemap_table(database_manager $dbman): void {
    $table = new xmldb_table('local_catquizlab_scalemap');
    if (!$dbman->table_exists($table)) {
        $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_catquizlab_scalemap');
    }
}

/**
 * Add the courseid and testcmid columns (and the course foreign key) to the run table.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_catquizlab_upgrade_add_run_course_columns(database_manager $dbman): void {
    $table = new xmldb_table('local_catquizlab_run');

    $courseid = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'manifestjson');
    if (!$dbman->field_exists($table, $courseid)) {
        $dbman->add_field($table, $courseid);
    }

    $testcmid = new xmldb_field('testcmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'courseid');
    if (!$dbman->field_exists($table, $testcmid)) {
        $dbman->add_field($table, $testcmid);
    }

    $key = new xmldb_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
    $dbman->add_key($table, $key);
}

/**
 * Add the retry-count and next-run-time columns to the attempt table.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_catquizlab_upgrade_add_attempt_retry_columns(database_manager $dbman): void {
    $table = new xmldb_table('local_catquizlab_attempt');

    $tries = new xmldb_field('tries', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'runtimems');
    if (!$dbman->field_exists($table, $tries)) {
        $dbman->add_field($table, $tries);
    }

    $nextruntime = new xmldb_field('nextruntime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'tries');
    if (!$dbman->field_exists($table, $nextruntime)) {
        $dbman->add_field($table, $nextruntime);
    }
}
