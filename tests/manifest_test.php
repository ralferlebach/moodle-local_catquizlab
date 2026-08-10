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
 * Tests for the run manifest builder.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\manifest;

/**
 * Manifest builder tests.
 *
 * @covers \local_catquizlab\local\manifest
 */
final class manifest_test extends \advanced_testcase {
    /**
     * The manifest carries the expected top-level structure and this plugin's version.
     *
     * @return void
     */
    public function test_build_structure(): void {
        $config = ['seeds' => ['master' => 42], 'strategy' => 'fastest'];
        $manifest = manifest::build($config);

        foreach (['schema', 'generated', 'plugins', 'engine', 'environment', 'config'] as $key) {
            $this->assertArrayHasKey($key, $manifest);
        }

        // This plugin is always installed while its own tests run.
        $this->assertArrayHasKey('local_catquizlab', $manifest['plugins']);
        $this->assertNotNull($manifest['plugins']['local_catquizlab']);

        // The environment is fully populated.
        $this->assertArrayHasKey('phpversion', $manifest['environment']);
        $this->assertArrayHasKey('dbfamily', $manifest['environment']);
        $this->assertNotEmpty($manifest['environment']['dbfamily']);

        // The passed-in configuration round-trips verbatim.
        $this->assertSame($config, $manifest['config']);

        // The engine.available flag is a bool and githash is null or a 40-hex string.
        $this->assertIsBool($manifest['engine']['available']);
        $githash = $manifest['engine']['githash'];
        $this->assertTrue($githash === null || preg_match('/^[0-9a-f]{40}$/', $githash) === 1);
    }

    /**
     * The JSON form decodes back to the array form.
     *
     * @return void
     */
    public function test_build_json_roundtrips(): void {
        $json = manifest::build_json(['seeds' => ['master' => 1]]);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['schema']);
        $this->assertSame(1, $decoded['config']['seeds']['master']);
    }
}
