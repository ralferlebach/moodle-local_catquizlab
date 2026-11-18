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

namespace local_catquizlab;

/**
 * The release gate of #167: the numeric version and the upgrade savepoints cannot drift apart again.
 *
 * 0.7.64 on GitHub had `$plugin->version = 2026111400` below its own savepoint 2026111500: Moodle then refuses
 * the code as a downgrade. This test reads version.php and db/upgrade.php as they are shipped and holds them to the
 * rules Moodle relies on, so that such a release fails here and not on an installation.
 *
 * @package    local_catquizlab
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class release_version_test extends \advanced_testcase {
    /**
     * The savepoints of db/upgrade.php, in the order they appear, each with the version its block is guarded by.
     *
     * @return array<int, array{guard: int, savepoint: int}>
     */
    private static function blocks(): array {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/catquizlab/db/upgrade.php');
        $blocks = [];
        // Every block is guarded by the version it saves: the guard compares the old version with N, the savepoint saves N.
        preg_match_all('/if\s*\(\s*\$oldversion\s*<\s*(\d{10})\s*\)/', $source, $guards, PREG_OFFSET_CAPTURE);
        preg_match_all(
            "/upgrade_plugin_savepoint\\(\\s*true\\s*,\\s*(\\d{10})\\s*,\\s*'local'\\s*,\\s*'catquizlab'\\s*\\)/",
            $source,
            $savepoints,
            PREG_OFFSET_CAPTURE
        );
        foreach ($savepoints[1] as [$savepoint, $offset]) {
            // The guard is the nearest "if ($oldversion < N)" before the savepoint.
            $guard = 0;
            foreach ($guards[1] as [$candidate, $at]) {
                if ($at < $offset) {
                    $guard = (int) $candidate;
                }
            }
            $blocks[] = ['guard' => $guard, 'savepoint' => (int) $savepoint];
        }
        return $blocks;
    }

    /**
     * The shipped version is at least the highest savepoint, every block saves the version it is guarded by, and the
     * savepoints only go up.
     *
     * @return void
     */
    public function test_the_version_is_not_below_any_savepoint(): void {
        global $CFG;
        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/catquizlab/version.php');
        $blocks = self::blocks();
        $this->assertNotEmpty($blocks);

        $highest = max(array_column($blocks, 'savepoint'));
        $this->assertGreaterThanOrEqual(
            $highest,
            $plugin->version,
            "version.php ({$plugin->version}) is below the highest savepoint ({$highest}): Moodle would refuse it"
        );

        $previous = 0;
        foreach ($blocks as $block) {
            $this->assertSame(
                $block['guard'],
                $block['savepoint'],
                "the block guarded by {$block['guard']} saves {$block['savepoint']}"
            );
            $this->assertGreaterThan($previous, $block['savepoint'], 'savepoints go up in the order they are written');
            $previous = $block['savepoint'];
        }
    }

    /**
     * The numbers are what the project counts with: a date and a counter (YYYYMMDDNN), a release 0.7.x; and an installed
     * site is never ahead of the code it runs (the test site is installed from this code).
     *
     * @return void
     */
    public function test_the_numbers_have_their_form_and_the_site_matches_the_code(): void {
        global $CFG;
        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/catquizlab/version.php');
        $this->assertMatchesRegularExpression('/^20\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{2}$/', (string) $plugin->version);
        $this->assertMatchesRegularExpression('/^0\.7\.\d{1,2}$/', $plugin->release);
        $this->assertSame('local_catquizlab', $plugin->component);
        $this->assertEquals($plugin->version, get_config('local_catquizlab', 'version'));
    }
}
