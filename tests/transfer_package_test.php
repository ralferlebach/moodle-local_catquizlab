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
 * Tests for the transfer package (hub submit/ingest).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\transfer_package;
use local_catquizlab\local\attempt_scheduler;

/**
 * Transfer package tests.
 *
 * @covers \local_catquizlab\local\transfer_package
 */
final class transfer_package_test extends \advanced_testcase {
    /**
     * Build a run with a person, an attempt and a result.
     *
     * @return \stdClass The run.
     */
    private function seed_run(): \stdClass {
        global $DB;
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run(['cellkey' => 'cell-x']);
        $now = time();
        $person = $generator->create_person([
            'runid' => $run->id, 'stratum' => 'conforming',
            'profilejson' => json_encode(['label' => 'Alice', 'global' => 0.1, 'categories' => []]),
        ]);
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $person->id, 'status' => attempt_scheduler::STATUS_COLLECTED,
            'tracejson' => json_encode(['finaltheta' => 0.2, 'responses' => [10 => 1.0]]),
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_catquizlab_result', (object) [
            'runid' => $run->id, 'metric' => 'rmse', 'scope' => 'run', 'value' => 0.33, 'timecreated' => $now,
        ]);
        return $run;
    }

    /**
     * A package verifies against its own hash and fails when tampered.
     *
     * @return void
     */
    public function test_build_and_verify(): void {
        $this->resetAfterTest();
        $run = $this->seed_run();

        $package = transfer_package::build($run->id);
        $this->assertTrue(transfer_package::verify($package['payload'], $package['hash']));
        $this->assertFalse(transfer_package::verify($package['payload'] . 'x', $package['hash']));
    }

    /**
     * Ingesting a package recreates the run, person, attempt and result locally.
     *
     * @return void
     */
    public function test_ingest_roundtrip(): void {
        global $DB;
        $this->resetAfterTest();
        $run = $this->seed_run();

        $package = transfer_package::build($run->id);
        $newrunid = transfer_package::ingest($package['payload']);

        $this->assertNotNull($newrunid);
        $this->assertNotEquals($run->id, $newrunid);
        $this->assertSame(1, $DB->count_records('local_catquizlab_person', ['runid' => $newrunid]));
        $this->assertSame(1, $DB->count_records('local_catquizlab_attempt', ['runid' => $newrunid]));

        $value = $DB->get_field(
            'local_catquizlab_result',
            'value',
            ['runid' => $newrunid, 'metric' => 'rmse', 'scope' => 'run']
        );
        $this->assertEqualsWithDelta(0.33, (float) $value, 1e-9);

        // The ingested person keeps its profile.
        $person = $DB->get_record('local_catquizlab_person', ['runid' => $newrunid]);
        $this->assertStringContainsString('Alice', $person->profilejson);
    }

    /**
     * Submitting without hub configuration is a safe no-op.
     *
     * @return void
     */
    public function test_submit_without_config(): void {
        $this->resetAfterTest();
        $run = $this->seed_run();

        $result = transfer_package::submit_to_hub($run->id);
        $this->assertFalse($result['submitted']);
    }
}
