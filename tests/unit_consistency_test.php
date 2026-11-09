<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\results_export;
use local_catquizlab\local\results_query;
use local_catquizlab\output\results_page;

/**
 * Plot, table and export count the same sittings and the same people (#127): n = 1, n > 1, paired, replicated.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_query
 * @covers     \local_catquizlab\local\results_export
 * @covers     \local_catquizlab\local\stream_summary
 * @covers     \local_catquizlab\output\results_page
 */
final class unit_consistency_test extends \advanced_testcase {
    /**
     * An experiment of collected sittings.
     *
     * @param string[] $strategies The strategies swept (two: the same people under both, paired).
     * @param int $replications Replications.
     * @param int $people People per run.
     * @return int The experiment.
     */
    protected function experiment(array $strategies, int $replications, int $people): int {
        global $DB;

        $definition = experiment_definition::example_baseline();
        $definition['budgets']['global']['minitems'] = 1;
        $definition['name'] = 'Units ' . implode('-', $strategies) . " {$replications}x{$people}";
        $definition['replications'] = $replications;
        $definition['persons']['count'] = $people;
        if (count($strategies) > 1) {
            $definition['sweep'] = ['factors' => ['strategy' => $strategies]];
        } else {
            $definition['strategy'] = $strategies[0];
        }
        $experimentid = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($experimentid);

        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
            for ($index = 0; $index < $people; $index++) {
                $ability = -1.0 + $index * 0.5;
                $personid = (int) $DB->insert_record('local_catquizlab_person', (object) [
                    'runid' => $run->id, 'twinid' => sprintf('r%03d-t%05d', $run->replication, $index + 1),
                    'twinindex' => $index + 1, 'severity' => 'none', 'stratum' => 'conforming',
                    'abilityglobal' => $ability,
                    'profilejson' => json_encode(['global' => $ability, 'categories' => [['index' => 1,
                        'theta' => $ability, 'subscales' => [['index' => 1, 'theta' => $ability]]]]]),
                    'moodleuserid' => null, 'timecreated' => time(), 'timemodified' => time(),
                ]);
                $nitems = 2 + $index % 3;
                $items = range(101, 100 + $nitems);
                $DB->insert_record('local_catquizlab_attempt', (object) [
                    'runid' => $run->id, 'personid' => $personid, 'status' => 30,
                    'tracejson' => json_encode(['finaltheta' => $ability + 0.1, 'finalse' => 0.30 + 0.01 * $index,
                        'items' => $items, 'responses' => array_combine($items, array_map(
                            static fn(int $k): float => (float) ($k % 2),
                            array_keys($items)
                        )), 'nitems' => $nitems,
                        'stopreason' => 'standarderror']),
                    'runtimems' => 1000, 'tries' => 1, 'timecreated' => time(), 'timemodified' => time(),
                ]);
            }
        }

        return $experimentid;
    }

    /**
     * The scenarios: sittings and independent people expected.
     *
     * @return array
     */
    public static function scenarios(): array {
        return [
            'n = 1' => [['fastest'], 1, 1, 1, 1],
            'n > 1' => [['fastest'], 1, 5, 5, 5],
            'paired: the same five people under two strategies' => [['fastest', 'lowestsub'], 1, 5, 10, 5],
            'replicated: three replications are fifteen people' => [['fastest'], 3, 5, 15, 15],
        ];
    }

    /**
     * Plot, figures and export agree on the sittings, the people and the mean.
     *
     * @dataProvider scenarios
     * @param string[] $strategies Strategies.
     * @param int $replications Replications.
     * @param int $people People per run.
     * @param int $sittings Sittings expected.
     * @param int $units Independent people expected.
     * @return void
     */
    public function test_plot_table_and_export_agree(
        array $strategies,
        int $replications,
        int $people,
        int $sittings,
        int $units
    ): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $experimentid = $this->experiment($strategies, $replications, $people);
        $filter = ['experimentid' => $experimentid];

        // The figures.
        $overview = (new results_query($filter))->overview();
        $this->assertSame($sittings, $overview['n']);
        $this->assertSame($units, $overview['all']->people(), 'independent people');
        $stat = $overview['all']->describe('nitems');
        $this->assertSame($units, $stat['units']);
        if ($units === 1) {
            $this->assertNull($stat['ci95lo'], 'one person: no interval');
        } else {
            $this->assertNotNull($stat['ci95lo']);
        }

        // The export: one row per sitting, the same mean, the same people.
        $export = results_export::dataset(new results_query($filter), results_export::LEVEL_ATTEMPT);
        $this->assertCount($sittings, $export['rows']);
        $this->assertEqualsWithDelta(
            array_sum(array_column($export['rows'], 'nitems')) / $sittings,
            $stat['mean'],
            1e-9,
            'the mean test length of the export is the one shown'
        );
        $people = array_unique(array_map(
            static fn(array $row): string => $row['replication'] . '|' . $row['twinid'],
            $export['rows']
        ));
        $this->assertCount($units, $people);

        // The plot: every sitting drawn, as many markers as distinct points, counts adding up.
        $html = (new results_page(new results_query($filter), 'overview', $filter))->render_tab();
        preg_match_all('~data-count="(\d+)" data-region="point"~', $html, $counts);
        $this->assertNotEmpty($counts[1]);
        $this->assertSame($sittings, array_sum(array_map('intval', $counts[1])), 'every sitting in the plot');
        $distinct = array_unique(array_map(
            static fn(array $row): string => sprintf('%.9F|%.9F', $row['nitems'], $row['se']),
            $export['rows']
        ));
        $this->assertCount(count($distinct), $counts[1], 'one marker per distinct point');
    }
}
