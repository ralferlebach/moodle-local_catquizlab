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

    if ($oldversion < 2026083100) {
        // Schema 2: the ground truth of an item is kept apart from what the
        // engine was told, so calibration and tagging errors become real
        // robustness conditions instead of annotations nobody reads.
        local_catquizlab_upgrade_add_item_table($dbman);
        local_catquizlab_upgrade_add_pool_lifecycle_columns($dbman);
        local_catquizlab_upgrade_add_person_twin_columns($dbman);
        local_catquizlab_upgrade_add_run_masterseed($dbman);
        upgrade_plugin_savepoint(true, 2026083100, 'local', 'catquizlab');
    }

    if ($oldversion < 2026083102) {
        // Reusable pool and person building blocks, so a new experiment can
        // cite an existing scale structure or person model instead of
        // restating it and hoping the numbers match.
        local_catquizlab_upgrade_add_preset_table($dbman);
        upgrade_plugin_savepoint(true, 2026083102, 'local', 'catquizlab');
    }

    if ($oldversion < 2026083109) {
        // Issue #8: experiments live in one shared course, one section each,
        // instead of a course per run.
        local_catquizlab_upgrade_add_experiment_container($dbman);
        upgrade_plugin_savepoint(true, 2026083109, 'local', 'catquizlab');
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

/**
 * Add the per-item ground-truth table.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_catquizlab_upgrade_add_item_table(database_manager $dbman): void {
    global $CFG;

    $table = new xmldb_table('local_catquizlab_item');
    if ($dbman->table_exists($table)) {
        return;
    }
    // Read the definition from the plugin's own install.xml, so an upgraded
    // install and a fresh one cannot drift apart.
    $dbman->install_one_table_from_xmldb_file(
        $CFG->dirroot . '/local/catquizlab/db/install.xml',
        'local_catquizlab_item'
    );
}

/**
 * Add the run binding and seed columns that make the pool table part of the run lifecycle.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_catquizlab_upgrade_add_pool_lifecycle_columns(database_manager $dbman): void {
    $table = new xmldb_table('local_catquizlab_pool');

    $fields = [
        new xmldb_field('runid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'experimentid'),
        new xmldb_field('poolseed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'runid'),
        new xmldb_field('mutationseed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'poolseed'),
        new xmldb_field('itemcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'mutationseed'),
    ];
    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }
}

/**
 * Add the digital-twin and severity columns to the person table.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_catquizlab_upgrade_add_person_twin_columns(database_manager $dbman): void {
    $table = new xmldb_table('local_catquizlab_person');

    $fields = [
        // Nullable rather than NOT NULL with an empty default: Moodle rejects
        // an empty-string default on a CHAR column, and a NOT NULL column
        // without one cannot be added to a table that already has rows.
        // Null is also the honest value for persons generated before the
        // paired design existed — they have no twin.
        new xmldb_field('twinid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'runid'),
        new xmldb_field('twinindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'twinid'),
        new xmldb_field('severity', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'none', 'twinindex'),
    ];
    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }
}

/**
 * Add the master-seed column to the run table.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_catquizlab_upgrade_add_run_masterseed(database_manager $dbman): void {
    $table = new xmldb_table('local_catquizlab_run');

    $field = new xmldb_field('masterseed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'cellkey');
    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }
}

/**
 * Add the reusable-preset table.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_catquizlab_upgrade_add_preset_table(database_manager $dbman): void {
    global $CFG;

    $table = new xmldb_table('local_catquizlab_preset');
    if ($dbman->table_exists($table)) {
        return;
    }
    $dbman->install_one_table_from_xmldb_file(
        $CFG->dirroot . '/local/catquizlab/db/install.xml',
        'local_catquizlab_preset'
    );
}

/**
 * Add the shared-course container columns to the experiment table.
 *
 * Nullable, because an experiment that has never been provisioned belongs to no
 * course, and because a NOT NULL column cannot be added to a populated table.
 * Existing runs keep their own courseid; the upgrade moves nothing.
 *
 * @param database_manager $dbman The database manager.
 * @return void
 */
function local_catquizlab_upgrade_add_experiment_container(database_manager $dbman): void {
    $table = new xmldb_table('local_catquizlab_experiment');

    $fields = [
        new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'tier'),
        new xmldb_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'courseid'),
    ];
    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }
}
