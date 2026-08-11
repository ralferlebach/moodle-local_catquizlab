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
 * Tests for the report builder.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\report_builder;

/**
 * Report builder tests.
 *
 * @covers \local_catquizlab\local\report_builder
 */
final class report_builder_test extends \advanced_testcase {
    /**
     * A run report groups results by scope, and run_scalars returns run-scope values.
     *
     * @return void
     */
    public function test_run_report(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        $rows = [
            ['scope' => 'run', 'metric' => 'bias', 'value' => 0.5],
            ['scope' => 'run', 'metric' => 'rmse', 'value' => 0.7],
            ['scope' => 'stratum:conforming', 'metric' => 'bias', 'value' => 0.4],
        ];
        foreach ($rows as $row) {
            $DB->insert_record('local_catquizlab_result', (object) [
                'runid' => $run->id, 'metric' => $row['metric'], 'scope' => $row['scope'],
                'value' => $row['value'], 'timecreated' => time(),
            ]);
        }

        $report = report_builder::run_report($run->id);
        $this->assertArrayHasKey('run', $report['scopes']);
        $this->assertArrayHasKey('stratum:conforming', $report['scopes']);
        $this->assertEqualsWithDelta(0.5, $report['scopes']['run']['bias'], 1e-6);

        $scalars = report_builder::run_scalars($run->id);
        $this->assertEqualsWithDelta(0.5, $scalars['bias'], 1e-6);
        $this->assertEqualsWithDelta(0.7, $scalars['rmse'], 1e-6);
        $this->assertArrayNotHasKey('stratumbias', $scalars);
    }

    /**
     * An experiment report returns a series and stability per metric.
     *
     * @return void
     */
    public function test_experiment_report(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment();

        foreach ([0.30, 0.34, 0.38] as $index => $rmse) {
            $run = $generator->create_run(['experimentid' => $experiment->id, 'replication' => $index + 1]);
            $DB->insert_record('local_catquizlab_result', (object) [
                'runid' => $run->id, 'metric' => 'rmse', 'scope' => 'run',
                'value' => $rmse, 'timecreated' => time(),
            ]);
        }

        $report = report_builder::experiment_report($experiment->id, ['rmse']);
        $this->assertCount(3, $report['rmse']['series']);
        $this->assertEqualsWithDelta(0.34, $report['rmse']['stability']['mean'], 1e-6);
    }
}
