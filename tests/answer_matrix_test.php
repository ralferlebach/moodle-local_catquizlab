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
 * Tests for the answer matrix.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\answer_matrix;
use local_catquizlab\local\exporter;
use local_catquizlab\local\attempt_scheduler;

/**
 * Answer matrix tests.
 *
 * @covers \local_catquizlab\local\answer_matrix
 */
final class answer_matrix_test extends \advanced_testcase {
    /**
     * Build a small run with two people answering overlapping item sets.
     *
     * @return \stdClass The run.
     */
    private function make_run(): \stdClass {
        global $DB;
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $now = time();

        $traces = [
            ['label' => 'Alice', 'stratum' => 'conforming', 'responses' => [10 => 1.0, 11 => 0.0]],
            ['label' => 'Bob', 'stratum' => 'deviant', 'responses' => [11 => 1.0, 12 => 1.0]],
        ];
        foreach ($traces as $t) {
            $person = $generator->create_person([
                'runid' => $run->id,
                'profilejson' => json_encode(['label' => $t['label'], 'global' => 0.0, 'categories' => []]),
                'stratum' => $t['stratum'],
            ]);
            $DB->insert_record('local_catquizlab_attempt', (object) [
                'runid' => $run->id, 'personid' => $person->id,
                'status' => attempt_scheduler::STATUS_COLLECTED,
                'tracejson' => json_encode(['responses' => $t['responses']]),
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        return $run;
    }

    /**
     * The matrix columns are the union of presented items, ordered.
     *
     * @return void
     */
    public function test_build(): void {
        $this->resetAfterTest();
        $run = $this->make_run();

        $matrix = answer_matrix::build($run->id);
        $this->assertSame([10, 11, 12], $matrix['questionids']);
        $this->assertCount(2, $matrix['rows']);
        $this->assertSame('Alice', $matrix['rows'][0]['person']);
    }

    /**
     * to_rows fills cells and leaves non-presented items empty; CSV round-trips.
     *
     * @return void
     */
    public function test_to_rows_and_csv(): void {
        $this->resetAfterTest();
        $run = $this->make_run();

        $table = answer_matrix::to_rows(answer_matrix::build($run->id));
        $this->assertSame(['person', 'stratum', '10', '11', '12'], $table['columns']);

        // Alice answered 10 and 11 but not 12.
        $alice = $table['rows'][0];
        $this->assertEqualsWithDelta(1.0, $alice['10'], 1e-9);
        $this->assertSame('', $alice['12']);

        $csv = exporter::to_csv($table['rows'], $table['columns']);
        $this->assertStringContainsString('person,stratum,10,11,12', $csv);
        $this->assertStringContainsString('Alice', $csv);
    }
}
