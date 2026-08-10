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
 * Tests for the result aggregator.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\result_aggregator;
use local_catquizlab\local\person_generator;
use local_catquizlab\local\attempt_scheduler;

/**
 * Result aggregator tests.
 *
 * @covers \local_catquizlab\local\result_aggregator
 */
final class result_aggregator_test extends \advanced_testcase {
    /**
     * Create a run whose four persons each have a collected attempt whose final
     * estimate is exactly their true ability plus a fixed offset.
     *
     * @param float $offset The estimation offset to bake into each trace.
     * @return int The run id.
     */
    protected function run_with_traces(float $offset): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        $definition = [
            'persons' => [
                'count'   => 4,
                'stratum' => 'conforming',
                'naming'  => ['pattern' => 'P-{stratum}-{index:03d}'],
            ],
            'pool'    => ['scales' => ['categories' => 1, 'subcategories' => 1]],
        ];
        person_generator::generate_and_persist($run->id, $definition, 42);

        $now = time();
        foreach ($DB->get_records('local_catquizlab_person', ['runid' => $run->id]) as $person) {
            $profile = json_decode($person->profilejson, true);
            $true = (float) $profile['global'];
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid'        => $run->id,
                'personid'     => $person->id,
                'status'       => attempt_scheduler::STATUS_COLLECTED,
                'tracejson'    => json_encode([
                    'finaltheta' => $true + $offset,
                    'finalse'    => 0.30,
                    'items'      => ['q1', 'q2', 'q3'],
                ]),
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }

        return $run->id;
    }

    /**
     * Aggregation stores the metric rows, and a constant offset gives that bias.
     *
     * @return void
     */
    public function test_aggregate_persists_metrics(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with_traces(0.5);
        $written = result_aggregator::aggregate($runid, 100);

        $this->assertGreaterThan(0, $written);

        $bias = $DB->get_field(
            'local_catquizlab_result',
            'value',
            ['runid' => $runid, 'metric' => 'bias', 'scope' => 'run']
        );
        $this->assertEqualsWithDelta(0.5, (float) $bias, 1e-6);

        $meanlength = $DB->get_field(
            'local_catquizlab_result',
            'value',
            ['runid' => $runid, 'metric' => 'meanlength', 'scope' => 'run']
        );
        $this->assertEqualsWithDelta(3.0, (float) $meanlength, 1e-6);

        $n = $DB->get_field(
            'local_catquizlab_result',
            'value',
            ['runid' => $runid, 'metric' => 'n', 'scope' => 'run']
        );
        $this->assertSame(4, (int) $n);

        // Exposure detail is stored as JSON.
        $exposure = $DB->get_field(
            'local_catquizlab_result',
            'detailjson',
            ['runid' => $runid, 'metric' => 'exposure', 'scope' => 'run']
        );
        $this->assertNotEmpty(json_decode((string) $exposure, true));
    }

    /**
     * Recomputing replaces the run-scope rows rather than duplicating them.
     *
     * @return void
     */
    public function test_aggregate_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with_traces(0.5);
        $first = result_aggregator::aggregate($runid);
        $after1 = $DB->count_records('local_catquizlab_result', ['runid' => $runid]);
        $second = result_aggregator::aggregate($runid);
        $after2 = $DB->count_records('local_catquizlab_result', ['runid' => $runid]);

        $this->assertSame($first, $second);
        $this->assertSame($after1, $after2);
    }

    /**
     * The stored results read back as flat, export-ready rows.
     *
     * @return void
     */
    public function test_results_reader(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $runid = $this->run_with_traces(0.0);
        result_aggregator::aggregate($runid);

        $rows = result_aggregator::results($runid);
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('metric', $rows[0]);
        $this->assertArrayHasKey('value', $rows[0]);
    }
}
