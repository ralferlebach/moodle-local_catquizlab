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
 * Tests for the export dataset selection layer.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\export_dataset;

/**
 * Export dataset tests.
 *
 * @covers \local_catquizlab\local\export_dataset
 */
final class export_dataset_test extends \advanced_testcase {
    /**
     * Scope resolves to the right runs for run, experiment and tier.
     *
     * @return void
     */
    public function test_runids_for(): void {
        $this->resetAfterTest();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment(['tier' => 'baseline']);
        $run1 = $generator->create_run(['experimentid' => $experiment->id]);
        $run2 = $generator->create_run(['experimentid' => $experiment->id]);

        $this->assertSame([$run1->id], export_dataset::runids_for('run', $run1->id));
        $this->assertEqualsCanonicalizing(
            [$run1->id, $run2->id],
            export_dataset::runids_for('experiment', $experiment->id)
        );
        $this->assertEqualsCanonicalizing(
            [$run1->id, $run2->id],
            export_dataset::runids_for('tier', 'baseline')
        );
    }

    /**
     * Ground truth is emitted as global, category and subscale long rows.
     *
     * @return void
     */
    public function test_ground_truth(): void {
        $this->resetAfterTest();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $profile = [
            'label' => 'Alice', 'global' => 0.2,
            'categories' => [
                ['index' => 1, 'theta' => 0.5, 'subscales' => [
                    ['index' => 1, 'theta' => -1.0], ['index' => 2, 'theta' => 0.3]]],
            ],
        ];
        $generator->create_person(['runid' => $run->id, 'profilejson' => json_encode($profile)]);

        $table = export_dataset::ground_truth([$run->id]);
        $this->assertSame(export_dataset::GROUNDTRUTH_COLUMNS, $table['columns']);
        // 1 global + 1 category + 2 subscales.
        $this->assertCount(4, $table['rows']);

        $levels = array_column($table['rows'], 'level');
        $this->assertSame(['global', 'category', 'subscale', 'subscale'], $levels);
        $this->assertEqualsWithDelta(0.2, $table['rows'][0]['theta'], 1e-9);
        $this->assertEqualsWithDelta(-1.0, $table['rows'][2]['theta'], 1e-9);
    }

    /**
     * Metrics are emitted as long rows across the given runs.
     *
     * @return void
     */
    public function test_metrics(): void {
        global $DB;
        $this->resetAfterTest();
        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();
        $DB->insert_record('local_catquizlab_result', (object) [
            'runid' => $run->id, 'metric' => 'rmse', 'scope' => 'run', 'value' => 0.42, 'timecreated' => time(),
        ]);

        $table = export_dataset::metrics([$run->id]);
        $this->assertSame(export_dataset::METRIC_COLUMNS, $table['columns']);
        $this->assertCount(1, $table['rows']);
        $this->assertSame('rmse', $table['rows'][0]['metric']);
        $this->assertEqualsWithDelta(0.42, $table['rows'][0]['value'], 1e-9);

        $this->assertSame([], export_dataset::metrics([])['rows']);
    }
}
