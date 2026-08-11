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
 * Tests for trend and stability analyses.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\trend_analysis;

/**
 * Trend analysis tests.
 *
 * @covers \local_catquizlab\local\trend_analysis
 */
final class trend_analysis_test extends \advanced_testcase {
    /**
     * Stability reports dispersion and copes with the empty case.
     *
     * @return void
     */
    public function test_stability(): void {
        $s = trend_analysis::stability([0.5, 0.52, 0.48, 0.51, 0.49]);
        $this->assertSame(5, $s['n']);
        $this->assertEqualsWithDelta(0.5, $s['mean'], 1e-6);
        $this->assertEqualsWithDelta(0.04, $s['range'], 1e-6);
        $this->assertGreaterThan(0.0, $s['sd']);

        $this->assertSame(0, trend_analysis::stability([])['n']);
    }

    /**
     * A perfect line recovers its slope, intercept and unit correlation.
     *
     * @return void
     */
    public function test_linear_trend(): void {
        $t = trend_analysis::linear_trend([1, 2, 3, 4], [3, 5, 7, 9]);
        $this->assertEqualsWithDelta(2.0, $t['slope'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $t['intercept'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $t['correlation'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $t['r2'], 1e-9);

        // A rising error with degradation has a positive slope and strong fit.
        $rise = trend_analysis::linear_trend([0, 1, 2, 3], [0.30, 0.42, 0.55, 0.71]);
        $this->assertGreaterThan(0.0, $rise['slope']);
        $this->assertGreaterThan(0.98, $rise['r2']);

        $this->assertNull(trend_analysis::linear_trend([1], [2])['slope']);
    }

    /**
     * Convergence flags when the running mean settles within tolerance.
     *
     * @return void
     */
    public function test_convergence(): void {
        // Values settle at 0.5, so the running mean stops moving.
        $c = trend_analysis::convergence([0.5, 0.5, 0.5, 0.5], 0.01);
        $this->assertTrue($c['converged']);
        $this->assertSame(1, $c['convergedat']);

        $notyet = trend_analysis::convergence([0.1, 0.4, 0.7, 1.0], 0.01);
        $this->assertFalse($notyet['converged']);
    }

    /**
     * The reader gathers a stored metric across an experiment's runs, in order.
     *
     * @return void
     */
    public function test_metric_series(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $experiment = $generator->create_experiment();

        $values = [0.30, 0.35, 0.40];
        foreach ($values as $index => $rmse) {
            $run = $generator->create_run(['experimentid' => $experiment->id, 'replication' => $index + 1]);
            $DB->insert_record('local_catquizlab_result', (object) [
                'runid' => $run->id, 'metric' => 'rmse', 'scope' => 'run',
                'value' => $rmse, 'timecreated' => time(),
            ]);
        }

        $series = trend_analysis::metric_series($experiment->id, 'rmse', 'run');
        $this->assertCount(3, $series);
        $this->assertEqualsWithDelta(0.30, $series[0], 1e-6);
        $this->assertEqualsWithDelta(0.40, $series[2], 1e-6);
        $this->assertEqualsWithDelta(0.35, trend_analysis::stability($series)['mean'], 1e-6);
    }
}
