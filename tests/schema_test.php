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
 * Tests that the installed schema matches what the code expects.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

/**
 * Schema tests.
 *
 * A fresh install reads db/install.xml; an existing site reads db/upgrade.php.
 * When a column is added to only one of them the two drift apart, and the
 * symptom appears far away: the twin columns were added by the upgrade but not
 * to install.xml, so every freshly installed site silently lost the digital-twin
 * identity, and only a results test noticed months later.
 *
 * These tests run against the schema PHPUnit builds from install.xml, so they
 * fail on exactly that mistake.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class schema_test extends \advanced_testcase {
    /**
     * The columns the plugin's code reads and writes, per table.
     *
     * @return array<string, string[]>
     */
    public static function expected_columns(): array {
        return [
            'local_catquizlab_experiment' => [
                'id', 'name', 'tier', 'configjson', 'status', 'usermodified', 'timecreated', 'timemodified',
            ],
            'local_catquizlab_run' => [
                'id', 'experimentid', 'cellkey', 'masterseed', 'seed', 'replication', 'status',
                'manifestjson', 'courseid', 'testcmid', 'timecreated', 'timemodified',
            ],
            'local_catquizlab_person' => [
                'id', 'runid', 'twinid', 'twinindex', 'severity', 'stratum',
                'abilityglobal', 'profilejson', 'moodleuserid', 'timecreated', 'timemodified',
            ],
            'local_catquizlab_pool' => [
                'id', 'experimentid', 'runid', 'poolseed', 'mutationseed', 'itemcount',
                'variant', 'recipejson', 'scaleid', 'questioncategoryid',
            ],
            'local_catquizlab_item' => [
                'id', 'runid', 'poolid', 'questionid', 'itemname', 'model',
                'truedifficulty', 'storeddifficulty', 'discrimination', 'guessing', 'stepsjson',
                'truecatscaleid', 'assignedcatscaleid', 'truecategory', 'truesubscale',
                'miscalibrated', 'mistagged',
            ],
            'local_catquizlab_preset' => [
                'id', 'kind', 'name', 'description', 'payloadjson', 'fingerprint',
                'usecount', 'locked', 'timecreated', 'timemodified',
            ],
            'local_catquizlab_attempt' => [
                'id', 'runid', 'personid', 'engineattemptid', 'status', 'tracejson', 'runtimems', 'tries',
            ],
            'local_catquizlab_result' => [
                'id', 'runid', 'metric', 'scope', 'value', 'detailjson',
            ],
        ];
    }

    /**
     * Every table the code uses exists with every column the code touches.
     *
     * @return void
     */
    public function test_installed_schema_has_every_expected_column(): void {
        global $DB;
        $this->resetAfterTest();

        $manager = $DB->get_manager();

        foreach (self::expected_columns() as $table => $columns) {
            $this->assertTrue(
                $manager->table_exists(new \xmldb_table($table)),
                'Table ' . $table . ' is missing from the installed schema.'
            );

            $installed = array_keys($DB->get_columns($table));
            $missing = array_diff($columns, $installed);

            $this->assertSame(
                [],
                array_values($missing),
                'Table ' . $table . ' is missing: ' . implode(', ', $missing)
                . '. Add them to db/install.xml as well as db/upgrade.php.'
            );
        }
    }

    /**
     * No column declares a default Moodle will reject and rewrite.
     *
     * A CHAR or TEXT column that is NOT NULL with an empty-string default makes
     * Moodle print a debugging message and silently change the default to NULL.
     * That message is fatal in CI — moodle-plugin-ci treats any debugging output
     * during installation as a failure — so the whole matrix went red over three
     * DEFAULT="" attributes that had no effect in the first place.
     *
     * @return void
     */
    public function test_no_column_declares_a_rejected_default(): void {
        global $CFG;
        $this->resetAfterTest();

        $xml = file_get_contents($CFG->dirroot . '/local/catquizlab/db/install.xml');
        preg_match_all('/<FIELD NAME="([^"]+)" TYPE="([^"]+)"([^\/]*)\/>/', $xml, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'No fields were found in install.xml.');

        foreach ($matches as $match) {
            [, $name, $type, $rest] = $match;
            if (!in_array($type, ['char', 'text'], true)) {
                continue;
            }
            $hasdefault = preg_match('/DEFAULT="([^"]*)"/', $rest, $default) === 1;

            if ($hasdefault) {
                $this->assertNotSame(
                    '',
                    $default[1],
                    'Column ' . $name . ' declares an empty-string default; drop the DEFAULT attribute.'
                );
            }
            if ($type === 'text') {
                $this->assertFalse(
                    $hasdefault,
                    'Column ' . $name . ' is a text column with a default, which Moodle does not allow.'
                );
            }
        }
    }

    /**
     * No upgrade step adds a NOT NULL column without a default.
     *
     * Such a column cannot be added to a table that already holds rows, so the
     * step works on a fresh site and fails on every real one.
     *
     * @return void
     */
    public function test_no_upgrade_field_is_not_null_without_a_default(): void {
        global $CFG;
        $this->resetAfterTest();

        $upgrade = file_get_contents($CFG->dirroot . '/local/catquizlab/db/upgrade.php');
        preg_match_all(
            "/new xmldb_field\\(\s*'([^']+)',\s*(XMLDB_TYPE_\w+),\s*[^,]*,\s*[^,]*,\s*([^,]*),\s*[^,]*,\s*([^,]*),/",
            $upgrade,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            [, $name, $type, $notnull, $default] = $match;
            if (trim($notnull) !== 'XMLDB_NOTNULL') {
                continue;
            }
            $default = trim($default);

            $this->assertNotSame(
                'null',
                $default,
                'Upgrade field ' . $name . ' is NOT NULL without a default and cannot be added to a populated table.'
            );
            $this->assertNotSame(
                "''",
                $default,
                'Upgrade field ' . $name . ' declares an empty-string default, which Moodle rewrites to NULL.'
            );
        }
    }

    /**
     * Every capability has a language string describing it.
     *
     * A capability without one shows up in the roles UI as its raw identifier,
     * and moodle-plugin-ci validate refuses the plugin outright. Three new
     * capabilities reached the code without their strings and nothing noticed
     * until CI did.
     *
     * @return void
     */
    public function test_every_capability_is_named(): void {
        global $CFG;
        $this->resetAfterTest();

        $capabilities = [];
        require($CFG->dirroot . '/local/catquizlab/db/access.php');

        $this->assertNotEmpty($capabilities, 'The plugin declares no capabilities.');

        foreach (array_keys($capabilities) as $capability) {
            $key = str_replace('local/', '', $capability);
            $this->assertTrue(
                get_string_manager()->string_exists($key, 'local_catquizlab'),
                'Capability ' . $capability . ' has no language string "' . $key . '".'
            );
        }
    }

    /**
     * The English and German language packs describe the same set of strings.
     *
     * A key present in one pack and missing from the other surfaces as the raw
     * identifier for half the users and is invisible to the other half.
     *
     * @return void
     */
    public function test_language_packs_match(): void {
        global $CFG;
        $this->resetAfterTest();

        $read = static function (string $path): array {
            $string = [];
            require($path);
            return array_keys($string);
        };

        $base = $CFG->dirroot . '/local/catquizlab/lang/';
        $en = $read($base . 'en/local_catquizlab.php');
        $de = $read($base . 'de/local_catquizlab.php');

        $this->assertSame([], array_values(array_diff($en, $de)), 'Strings missing from the German pack.');
        $this->assertSame([], array_values(array_diff($de, $en)), 'Strings missing from the English pack.');

        // Moodle's own lang checker wants them sorted, and a sorted file is
        // also the only way two people editing it do not collide constantly.
        $sorted = $en;
        sort($sorted);
        $this->assertSame($sorted, $en, 'The English language file is not sorted by key.');
    }

    /**
     * Every savepoint in the upgrade path is at most the plugin version.
     *
     * A savepoint above the version can never be reached, so the upgrade step
     * silently never runs.
     *
     * @return void
     */
    public function test_savepoints_do_not_exceed_the_plugin_version(): void {
        global $CFG;
        $this->resetAfterTest();

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/catquizlab/version.php');
        $version = (int) $plugin->version;

        $upgrade = file_get_contents($CFG->dirroot . '/local/catquizlab/db/upgrade.php');
        preg_match_all('/upgrade_plugin_savepoint\(true,\s*(\d+)/', $upgrade, $matches);

        $this->assertNotEmpty($matches[1], 'The upgrade path has no savepoints.');
        foreach ($matches[1] as $savepoint) {
            $this->assertLessThanOrEqual(
                $version,
                (int) $savepoint,
                'Savepoint ' . $savepoint . ' is above the plugin version ' . $version . '.'
            );
        }
    }
}
