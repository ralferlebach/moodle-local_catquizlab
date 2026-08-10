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
 * Tests for the run registry persistence.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\registry;
use local_catquizlab\local\sweep;
use local_catquizlab\local\experiment_definition;

/**
 * Registry persistence tests.
 *
 * @covers \local_catquizlab\local\registry
 */
final class registry_test extends \advanced_testcase {
    /**
     * Build a small expansion (2x2 factors, 2 replications) and its spec.
     *
     * @return array{0: array, 1: array} The expansion and the spec.
     */
    protected function expansion(): array {
        $base = experiment_definition::example_baseline();
        $base['persons']['count'] = 5;
        $spec = [
            'base'         => $base,
            'factors'      => [
                'variant' => ['ideal', 'shifted'],
                'stratum' => ['conforming', 'chaotic'],
            ],
            'replications' => 2,
            'seed'         => 42,
        ];
        return [sweep::expand($spec), $spec];
    }

    /**
     * Persisting an expansion writes one experiment and one row per run.
     *
     * @return void
     */
    public function test_persist_expansion(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$expansion, $spec] = $this->expansion();
        $expectedruns = count($expansion['runs']); // 4 cells x 2 replications = 8.

        $experimentid = registry::persist_expansion('Main sweep', 'main', $expansion, $spec);

        $this->assertGreaterThan(0, $experimentid);
        $this->assertSame('main', $DB->get_field('local_catquizlab_experiment', 'tier', ['id' => $experimentid]));
        $this->assertSame($expectedruns, registry::count_runs($experimentid));
        $this->assertSame($expectedruns, $DB->count_records('local_catquizlab_run', ['experimentid' => $experimentid]));

        // The spec is stored on the experiment for reproducibility.
        $configjson = $DB->get_field('local_catquizlab_experiment', 'configjson', ['id' => $experimentid]);
        $config = json_decode($configjson, true);
        $this->assertSame(42, $config['seed']);

        // Runs start as drafts and carry their cell/seed/replication.
        $runs = $DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid]);
        foreach ($runs as $run) {
            $this->assertSame(registry::STATUS_DRAFT, (int) $run->status);
            $this->assertNotEmpty($run->cellkey);
            $this->assertGreaterThanOrEqual(1, (int) $run->replication);
        }
    }

    /**
     * The global status summary and recent-runs query reflect what was written.
     *
     * @return void
     */
    public function test_summary_and_recent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$expansion, $spec] = $this->expansion();
        registry::persist_expansion('Main sweep', 'main', $expansion, $spec);

        $summary = registry::global_status_summary();
        $this->assertArrayHasKey(registry::STATUS_DRAFT, $summary);
        $this->assertSame(count($expansion['runs']), $summary[registry::STATUS_DRAFT]);

        $recent = registry::recent_runs(100);
        $this->assertCount(count($expansion['runs']), $recent);
        $first = reset($recent);
        $this->assertSame('Main sweep', $first->experimentname);
        $this->assertSame('main', $first->tier);
    }
}
