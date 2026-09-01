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
 * Tests for the results export.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;

/**
 * Results-export tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_export
 */
final class results_export_test extends \advanced_testcase {
    /**
     * An experiment with two strategies, two replications and three attempts each.
     *
     * @return int The experiment id.
     */
    protected function seeded_experiment(): int {
        global $DB;

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Export demo';
        $definition['replications'] = 2;
        $definition['persons']['count'] = 3;
        $definition['sweep'] = ['factors' => ['strategy' => ['fastest', 'lowestsub']]];

        $experimentid = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($experimentid);

        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
            foreach ([-1.0, 0.0, 1.0] as $index => $ability) {
                $personid = (int) $DB->insert_record('local_catquizlab_person', (object) [
                    'runid'         => $run->id,
                    'twinid'        => sprintf('r%03d-t%05d', $run->replication, $index + 1),
                    'twinindex'     => $index + 1,
                    'severity'      => 'none',
                    'stratum'       => 'conforming',
                    'abilityglobal' => $ability,
                    'profilejson'   => json_encode([
                        'global'     => $ability,
                        'categories' => [[
                            'index' => 1,
                            'theta' => $ability,
                            'subscales' => [
                                ['index' => 1, 'theta' => $ability - 0.4],
                                ['index' => 2, 'theta' => $ability + 0.4],
                            ],
                        ]],
                    ]),
                    'moodleuserid'  => null,
                    'timecreated'   => time(),
                    'timemodified'  => time(),
                ]);

                $DB->insert_record('local_catquizlab_attempt', (object) [
                    'runid'        => $run->id,
                    'personid'     => $personid,
                    'status'       => 30,
                    'tracejson'    => json_encode([
                        'finaltheta' => $ability + 0.1,
                        'finalse'    => 0.32,
                        'items'      => [101, 102],
                        'nitems'     => 2,
                        'stopreason' => 'standarderror',
                    ]),
                    'runtimems'    => 1200,
                    'tries'        => 1,
                    'timecreated'  => time(),
                    'timemodified' => time(),
                ]);
            }
        }

        return $experimentid;
    }

    /**
     * The run level has one row per run.
     *
     * @return void
     */
    public function test_run_level(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $query = new results_query(['experimentid' => $id]);
        $dataset = results_export::dataset($query, results_export::LEVEL_RUN);

        $this->assertCount(4, $dataset['rows']);
        $this->assertContains('cellkey', $dataset['columns']);
        $this->assertContains('rmse', $dataset['columns']);
        foreach ($dataset['rows'] as $row) {
            $this->assertSame(3, $row['attempts']);
        }
    }

    /**
     * The attempt level has one row per attempt.
     *
     * @return void
     */
    public function test_attempt_level(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $query = new results_query(['experimentid' => $id]);
        $dataset = results_export::dataset($query, results_export::LEVEL_ATTEMPT);

        $this->assertCount(12, $dataset['rows']);
        $this->assertContains('twinid', $dataset['columns']);
        $this->assertContains('error', $dataset['columns']);
    }

    /**
     * The export honours the filter that is on screen.
     *
     * @return void
     */
    public function test_export_follows_the_filter(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $filtered = new results_query(['experimentid' => $id, 'strategy' => 'lowestsub']);
        $dataset = results_export::dataset($filtered, results_export::LEVEL_ATTEMPT);

        // Exporting more than the screen shows would be a trap: the reader
        // looks at one selection and receives another.
        $this->assertCount(6, $dataset['rows']);
        foreach ($dataset['rows'] as $row) {
            $this->assertSame('lowestsub', $row['strategy']);
        }
    }

    /**
     * Every level is a flat rectangle with the same keys in every row.
     *
     * @return void
     */
    public function test_every_level_is_rectangular(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $query = new results_query(['experimentid' => $id]);

        foreach (array_keys(results_export::levels()) as $level) {
            $dataset = results_export::dataset($query, $level);
            foreach ($dataset['rows'] as $row) {
                $this->assertSame(
                    $dataset['columns'],
                    array_keys($row),
                    'Level ' . $level . ' has a row whose keys differ from its columns.'
                );
                foreach ($row as $value) {
                    $this->assertFalse(is_array($value), 'Level ' . $level . ' contains a nested value.');
                }
            }
        }
    }

    /**
     * The CSV writes a missing value as an empty field, not as the word null.
     *
     * @return void
     */
    public function test_csv_writes_missing_values_as_empty(): void {
        $this->resetAfterTest();

        $csv = results_export::to_csv([
            'columns' => ['a', 'b', 'c'],
            'rows'    => [['a' => 1, 'b' => null, 'c' => true]],
        ]);
        $lines = explode("\n", trim($csv));

        $this->assertSame('a,b,c', $lines[0]);
        // The word "null" would arrive in a statistics package as a category.
        $this->assertSame('1,,1', $lines[1]);
    }

    /**
     * Quotes inside a value are escaped rather than breaking the row.
     *
     * @return void
     */
    public function test_csv_escapes_quotes(): void {
        $this->resetAfterTest();

        $csv = results_export::to_csv([
            'columns' => ['name'],
            'rows'    => [['name' => 'A "quoted" name, with a comma']],
        ]);

        $this->assertStringContainsString('"A ""quoted"" name, with a comma"', $csv);
        $this->assertCount(2, explode("\n", trim($csv)));
    }

    /**
     * The metadata states the filter, the level and the versions.
     *
     * @return void
     */
    public function test_metadata_describes_the_export(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $query = new results_query(['experimentid' => $id, 'strategy' => 'fastest']);
        $metadata = results_export::metadata($query, results_export::LEVEL_ATTEMPT);

        $this->assertSame('local_catquizlab/results', $metadata['schema']);
        $this->assertSame(results_export::LEVEL_ATTEMPT, $metadata['level']);
        $this->assertSame('fastest', $metadata['filter']['strategy']);
        $this->assertSame(6, $metadata['rows']);
        $this->assertSame(2, $metadata['runs']);
        $this->assertNotEmpty($metadata['plugin']['version']);
        $this->assertNotEmpty($metadata['dispersion']);
    }

    /**
     * The JSON export carries its metadata alongside the data.
     *
     * @return void
     */
    public function test_json_carries_its_metadata(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $query = new results_query(['experimentid' => $id]);
        $decoded = json_decode(results_export::to_json($query, results_export::LEVEL_RUN), true);

        $this->assertArrayHasKey('metadata', $decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertCount(4, $decoded['data']);
        $this->assertSame(results_export::LEVEL_RUN, $decoded['metadata']['level']);
    }

    /**
     * The file name carries the level and the filter.
     *
     * @return void
     */
    public function test_filename_carries_the_selection(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $query = new results_query(['experimentid' => $id, 'strategy' => 'lowestsub']);
        $filename = results_export::filename($query, results_export::LEVEL_SUBSCALE, 'csv');

        // A file that has been moved or renamed should still be identifiable.
        $this->assertStringStartsWith('catquizlab_subscale', $filename);
        $this->assertStringContainsString('strategy-lowestsub', $filename);
        $this->assertStringEndsWith('.csv', $filename);
    }

    /**
     * An unknown level is refused rather than silently defaulted.
     *
     * @return void
     */
    public function test_unknown_level_is_refused(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        results_export::dataset(new results_query(), 'telepathy');
    }

    /**
     * An empty selection exports its columns and no rows.
     *
     * @return void
     */
    public function test_empty_selection_still_has_columns(): void {
        $this->resetAfterTest();

        $dataset = results_export::dataset(new results_query(['experimentid' => 999999]), 'attempt');

        $this->assertSame([], $dataset['rows']);
        $this->assertNotEmpty($dataset['columns']);
        // A header-only CSV is still a valid file, and better than nothing at all.
        $this->assertStringContainsString('attemptid', results_export::to_csv($dataset));
    }
}
