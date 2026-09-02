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
 * The state of the engine defects this suite works around.
 *
 * These tests pin what the installed engine does today. Each one fails when the
 * engine is fixed, and that is the point: a workaround that outlives its cause
 * is not free — it hides the fixed behaviour and keeps a limitation in the
 * documentation that no longer exists. The failure message says what to remove.
 *
 * They skip when no engine is installed, so CI without one stays green.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\environment;

/**
 * Engine-defect pins.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class engine_defects_test extends \advanced_testcase {
    /**
     * Read a file from the installed engine.
     *
     * @param string $relative Path below the engine's directory.
     * @return string|null The contents, or null when the engine is absent.
     */
    protected function engine_source(string $relative): ?string {
        global $CFG;

        $path = $CFG->dirroot . '/local/catquiz/' . ltrim($relative, '/');

        return is_readable($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * catquiz#59: the ability range is asked for without checking the scales.
     *
     * @return void
     */
    public function test_ability_range_is_still_called_unchecked(): void {
        $this->resetAfterTest();

        $source = $this->engine_source('classes/teststrategy/feedbackgenerator.php');
        if ($source === null) {
            $this->markTestSkipped('No CAT engine installed.');
        }

        // See https://github.com/ralferlebach/moodle-local_catquiz/issues/59 —
        // array_key_first() on an empty array is null, and get_ability_range()
        // declares int. At the first question of an attempt the scales are
        // empty, so the call raises and the selection is aborted.
        $this->assertStringContainsString(
            'get_ability_range(array_key_first($catscales))',
            $source,
            'catquiz#59 appears to be fixed. Re-test whether the lab still needs its guards, '
                . 'and update docs/design/issue-catquiz-ability-range-null.md.'
        );
    }

    /**
     * The debug generator reads `lastquestion` without checking it exists.
     *
     * @return void
     */
    public function test_debuginfo_still_reads_lastquestion_unchecked(): void {
        $this->resetAfterTest();

        $source = $this->engine_source('classes/teststrategy/feedbackgenerator/debuginfo.php');
        if ($source === null) {
            $this->markTestSkipped('No CAT engine installed.');
        }

        // A PHP notice on a normal site, an exception at DEBUG_DEVELOPER — and
        // there it aborts every attempt while store_debug_info is on, which is
        // why the ability path cannot be collected in a development
        // environment.
        $this->assertStringContainsString(
            '(array) $newdata[\'lastquestion\']',
            $source,
            'The lastquestion access appears to be guarded now. Re-test store_debug_info at '
                . 'DEBUG_DEVELOPER and update docs/design/issue-catquiz-debuginfo-lastquestion.md.'
        );
    }

    /**
     * Progress retention is configurable, as the lab asked upstream.
     *
     * @return void
     */
    public function test_progress_retention_is_configurable(): void {
        $this->resetAfterTest();

        if (!environment::catquiz_available()) {
            $this->markTestSkipped('No CAT engine installed.');
        }

        // This one is the other way round: the setting the lab asked for now
        // exists, so the test holds it in place rather than pinning a defect.
        // If it disappears again, the trajectory archiving loses its ground.
        $retention = get_config('local_catquiz', 'progressretention');
        $this->assertNotFalse(
            $retention,
            'local_catquiz no longer offers progressretention. The lab archives progress rows '
                . 'itself and relied on the engine keeping them.'
        );
    }

    /**
     * The engine still leaves person parameters without a standard error.
     *
     * @return void
     */
    public function test_person_parameters_have_no_standard_error(): void {
        global $DB;
        $this->resetAfterTest();

        if (
            !environment::catquiz_available()
            || !$DB->get_manager()->table_exists('local_catquiz_personparams')
        ) {
            $this->markTestSkipped('No CAT engine installed.');
        }

        // The lab computes its own standard errors because the engine writes
        // rows full of nulls here. If it starts writing real values, the
        // computed ones should give way — they assume the generating model,
        // and the engine's own estimate is the better witness.
        $columns = $DB->get_columns('local_catquiz_personparams');
        $this->assertArrayHasKey(
            'standarderror',
            $columns,
            'The standarderror column is gone; precision collection needs revisiting.'
        );
    }
}
