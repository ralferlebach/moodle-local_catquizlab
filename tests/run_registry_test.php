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
 * Tests for the run registry: listing, filtering and comparison.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_registry;

/**
 * Run-registry tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\run_registry
 */
final class run_registry_test extends \advanced_testcase {
    /**
     * Create an experiment whose sweep varies the strategy, and expand it.
     *
     * @return int The experiment id.
     */
    protected function seeded_experiment(): int {
        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Comparison demo';
        $definition['replications'] = 3;
        $definition['sweep'] = ['factors' => ['strategy' => ['fastest', 'lowestsub']]];

        $id = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($id);

        return $id;
    }

    /**
     * Attach a metric value to every run of an experiment.
     *
     * @param int $experimentid The experiment.
     * @param string $metric The metric name.
     * @param array $bystrategy Value per strategy key.
     * @return void
     */
    protected function seed_results(int $experimentid, string $metric, array $bystrategy): void {
        global $DB;

        $offset = 0.0;
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $record) {
            $row = run_registry::describe($record);
            $base = $bystrategy[$row['strategy']] ?? 1.0;
            $DB->insert_record('local_catquizlab_result', (object) [
                'runid'       => $record->id,
                'metric'      => $metric,
                'scope'       => 'run',
                // A small deterministic spread, so a standard deviation exists.
                'value'       => $base + $offset,
                'detailjson'  => null,
                'timecreated' => time(),
            ]);
            $offset = $offset >= 0.02 ? 0.0 : $offset + 0.01;
        }
    }

    /**
     * A run is described by the coordinates of the cell it came from.
     *
     * @return void
     */
    public function test_describe_resolves_the_experimental_coordinates(): void {
        global $DB;
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $record = $DB->get_records('local_catquizlab_run', ['experimentid' => $id], 'id ASC', '*', 0, 1);
        $row = run_registry::describe(reset($record));

        $this->assertSame('Comparison demo', $row['experiment']);
        $this->assertContains($row['strategy'], ['fastest', 'lowestsub']);
        $this->assertNotEmpty($row['strategylabel']);
        $this->assertSame('2pl', $row['model']);
        $this->assertSame('ideal', $row['variant']);
        $this->assertSame(42, $row['masterseed']);
    }

    /**
     * The listing returns every run of the sweep.
     *
     * @return void
     */
    public function test_listing_returns_all_runs(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $listing = run_registry::listing(['experimentid' => $id]);

        // Two strategies times three replications.
        $this->assertSame(6, $listing['total']);
        $this->assertCount(6, $listing['rows']);
    }

    /**
     * Filtering by a factor that lives in the definition still narrows the list.
     *
     * @return void
     */
    public function test_listing_filters_by_strategy(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $listing = run_registry::listing(['experimentid' => $id, 'strategy' => 'lowestsub']);

        $this->assertSame(3, $listing['total']);
        foreach ($listing['rows'] as $row) {
            $this->assertSame('lowestsub', $row['strategy']);
        }
    }

    /**
     * Filtering by status uses the database column.
     *
     * @return void
     */
    public function test_listing_filters_by_status(): void {
        global $DB;
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $records = $DB->get_records('local_catquizlab_run', ['experimentid' => $id], 'id ASC', '*', 0, 1);
        $first = reset($records);
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FAILED, ['id' => $first->id]);

        $failed = run_registry::listing(['experimentid' => $id, 'status' => registry::STATUS_FAILED]);

        $this->assertSame(1, $failed['total']);
    }

    /**
     * Paging returns a bounded page and the unbounded total.
     *
     * @return void
     */
    public function test_listing_pages(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $listing = run_registry::listing(['experimentid' => $id], 0, 4);

        $this->assertCount(4, $listing['rows']);
        $this->assertSame(6, $listing['total']);
    }

    /**
     * The comparison groups replications into one row per cell.
     *
     * @return void
     */
    public function test_compare_groups_by_factor(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $this->seed_results($id, 'rmse', ['fastest' => 0.50, 'lowestsub' => 0.30]);

        $rows = run_registry::compare($id, 'rmse', 'strategy');

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            // Three replications collapse into one comparable cell.
            $this->assertSame(3, $row['n']);
            $this->assertNotNull($row['mean']);
            $this->assertNotNull($row['ci95lo']);
            $this->assertLessThan($row['mean'], $row['ci95lo']);
            $this->assertGreaterThan($row['mean'], $row['ci95hi']);
        }

        $means = array_combine(array_column($rows, 'group'), array_column($rows, 'mean'));
        $this->assertLessThan($means['fastest'], $means['lowestsub']);
    }

    /**
     * Comparison rows carry the publication label, not just the internal key.
     *
     * @return void
     */
    public function test_compare_uses_publication_labels(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $this->seed_results($id, 'rmse', ['fastest' => 0.5, 'lowestsub' => 0.3]);

        $labels = array_column(run_registry::compare($id, 'rmse', 'strategy'), 'label');

        $this->assertContains('Detect weakest subscale', $labels);
        $this->assertContains('Estimate global ability (MFI)', $labels);
    }

    /**
     * A cell with a single replication reports no interval rather than a false one.
     *
     * @return void
     */
    public function test_compare_reports_no_interval_without_replications(): void {
        global $DB;
        $this->resetAfterTest();

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Single replication';
        $id = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($id);

        $records = $DB->get_records('local_catquizlab_run', ['experimentid' => $id], 'id ASC');
        $DB->insert_record('local_catquizlab_result', (object) [
            'runid'       => reset($records)->id,
            'metric'      => 'rmse',
            'scope'       => 'run',
            'value'       => 0.4,
            'detailjson'  => null,
            'timecreated' => time(),
        ]);

        $rows = run_registry::compare($id, 'rmse', 'strategy');

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['n']);
        $this->assertNull($rows[0]['ci95lo']);
    }

    /**
     * An unknown grouping factor is refused.
     *
     * @return void
     */
    public function test_compare_refuses_an_unknown_factor(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();

        $this->expectException(\coding_exception::class);
        run_registry::compare($id, 'rmse', 'astrology');
    }

    /**
     * Factor values come back as a usable filter menu.
     *
     * @return void
     */
    public function test_factor_values_build_a_filter_menu(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $values = run_registry::factor_values($id, 'strategy');

        $this->assertArrayHasKey('fastest', $values);
        $this->assertArrayHasKey('lowestsub', $values);
        $this->assertSame('Detect weakest subscale', $values['lowestsub']);
    }

    /**
     * A failed run offers a usable reason instead of "Run failed."
     *
     * @return void
     */
    public function test_failed_run_reports_a_reason(): void {
        global $DB;
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $records = $DB->get_records('local_catquizlab_run', ['experimentid' => $id], 'id ASC', '*', 0, 1);
        $run = reset($records);

        $manifest = json_decode((string) $run->manifestjson, true) ?: [];
        $manifest['config']['failure'] = 'Stage: materialisation — pool variant could not be realised.';
        $DB->set_field('local_catquizlab_run', 'manifestjson', json_encode($manifest), ['id' => $run->id]);
        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FAILED, ['id' => $run->id]);

        $detail = run_registry::detail((int) $run->id);

        $this->assertNotNull($detail['failure']);
        $this->assertStringContainsString('materialisation', $detail['failure']);
    }

    /**
     * A run's detail carries the manifest that pins it down.
     *
     * @return void
     */
    public function test_detail_exposes_the_manifest(): void {
        global $DB;
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $records = $DB->get_records('local_catquizlab_run', ['experimentid' => $id], 'id ASC', '*', 0, 1);

        $detail = run_registry::detail((int) reset($records)->id);

        $this->assertArrayHasKey('config', $detail['manifest']);
        $this->assertArrayHasKey('seeds', $detail['manifest']['config']);
        $this->assertArrayHasKey('cat', $detail['manifest']['config']);
        $this->assertNull($detail['failure']);
    }
}
