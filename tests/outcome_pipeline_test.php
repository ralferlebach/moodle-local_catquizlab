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
 * Tests that every outcome the article requires is actually persisted.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\result_aggregator;
use local_catquizlab\local\subscale_evaluator;

/**
 * Outcome-pipeline tests.
 *
 * The point is not that the figures can be computed — they could be all along,
 * on screen. The point is that they are written to the result store, because an
 * outcome that only exists while a page is open cannot be aggregated across
 * replications, exported, or compared between cells.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\result_aggregator
 * @covers     \local_catquizlab\local\subscale_evaluator
 */
final class outcome_pipeline_test extends \advanced_testcase {
    /**
     * Create a run with attempts covering both stop outcomes.
     *
     * @return int The run id.
     */
    protected function run_with_attempts(): int {
        global $DB;

        /** @var \local_catquizlab_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_catquizlab');
        $run = $generator->create_run();

        // Three attempts: two stop on the precision criterion, one runs out of
        // items, so the success rate has a value other than 0 or 1.
        $attempts = [
            ['theta' => -0.5, 'est' => -0.4, 'stop' => 'standarderror', 'runtime' => 1000],
            ['theta' => 0.0, 'est' => 0.1, 'stop' => 'standarderror', 'runtime' => 2000],
            ['theta' => 1.0, 'est' => 1.4, 'stop' => 'maxquestions', 'runtime' => 3000],
        ];

        foreach ($attempts as $index => $attempt) {
            $personid = (int) $DB->insert_record('local_catquizlab_person', (object) [
                'runid'         => $run->id,
                'twinid'        => 'r001-t0000' . ($index + 1),
                'twinindex'     => $index + 1,
                'severity'      => 'none',
                'stratum'       => 'conforming',
                'abilityglobal' => $attempt['theta'],
                'profilejson'   => json_encode([
                    'global'     => $attempt['theta'],
                    'categories' => [[
                        'index'     => 1,
                        'theta'     => $attempt['theta'],
                        'subscales' => [
                            ['index' => 1, 'theta' => $attempt['theta'] - 0.6],
                            ['index' => 2, 'theta' => $attempt['theta'] - 0.2],
                            ['index' => 3, 'theta' => $attempt['theta'] + 0.3],
                            ['index' => 4, 'theta' => $attempt['theta'] + 0.5],
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
                    'finaltheta' => $attempt['est'],
                    'finalse'    => 0.3,
                    'items'      => [101, 102, 103 + $index],
                    'nitems'     => 3,
                    'stopreason' => $attempt['stop'],
                ]),
                'runtimems'    => $attempt['runtime'],
                'tries'        => 1,
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }

        return (int) $run->id;
    }

    /**
     * The metric values stored for a run, keyed by metric.
     *
     * @param int $runid The run.
     * @param string $scope The scope.
     * @return array<string, mixed>
     */
    protected function stored(int $runid, string $scope = 'run'): array {
        global $DB;

        $out = [];
        foreach ($DB->get_records('local_catquizlab_result', ['runid' => $runid, 'scope' => $scope]) as $row) {
            $out[$row->metric] = $row->value;
        }

        return $out;
    }

    /**
     * Every mandatory global outcome reaches the result store.
     *
     * @return void
     */
    public function test_global_outcomes_are_persisted(): void {
        $this->resetAfterTest();

        $runid = $this->run_with_attempts();
        result_aggregator::aggregate($runid, 10);
        $stored = $this->stored($runid);

        foreach (
            [
            'bias', 'rmse', 'correlation', 'meanse', 'meanlength',
            'stopsuccess', 'concentration', 'runtimems',
            ] as $metric
        ) {
            $this->assertArrayHasKey($metric, $stored, 'Missing global outcome: ' . $metric);
        }
    }

    /**
     * The stop-rule success rate counts criteria, not exhaustion.
     *
     * @return void
     */
    public function test_stop_success_rate(): void {
        $this->resetAfterTest();

        $runid = $this->run_with_attempts();
        result_aggregator::aggregate($runid, 10);
        $stored = $this->stored($runid);

        // Two of three attempts stopped on the precision criterion; the third
        // ran out of items, which is the rule failing to bite.
        $this->assertEqualsWithDelta(2 / 3, (float) $stored['stopsuccess'], 0.0001);
    }

    /**
     * The reasons behind the success rate stay available.
     *
     * @return void
     */
    public function test_stop_reasons_are_kept(): void {
        global $DB;
        $this->resetAfterTest();

        $runid = $this->run_with_attempts();
        result_aggregator::aggregate($runid, 10);

        $detail = $DB->get_field('local_catquizlab_result', 'detailjson', [
            'runid' => $runid, 'metric' => 'stopsuccess', 'scope' => 'run',
        ]);
        $reasons = json_decode((string) $detail, true);

        // A rate of 0.67 says nothing about why the remaining third failed.
        $this->assertSame(2, $reasons['standarderror']);
        $this->assertSame(1, $reasons['maxquestions']);
    }

    /**
     * Runtime is stored as a mean per attempt.
     *
     * @return void
     */
    public function test_runtime_is_an_outcome(): void {
        $this->resetAfterTest();

        $runid = $this->run_with_attempts();
        result_aggregator::aggregate($runid, 10);

        $this->assertEqualsWithDelta(2000.0, (float) $this->stored($runid)['runtimems'], 0.001);
    }

    /**
     * Exposure concentration is stored as its own figure.
     *
     * @return void
     */
    public function test_exposure_concentration_is_persisted(): void {
        $this->resetAfterTest();

        $runid = $this->run_with_attempts();
        result_aggregator::aggregate($runid, 10);
        $stored = $this->stored($runid);

        // Items 101 and 102 are in every attempt, 103-105 in one each, and five
        // of the ten pool items were never shown: an uneven pool.
        $this->assertArrayHasKey('concentration', $stored);
        $this->assertArrayHasKey('concentrationhhi', $stored);
        $this->assertGreaterThan(0.0, (float) $stored['concentration']);
    }

    /**
     * Outcomes are also stored per stratum.
     *
     * @return void
     */
    public function test_outcomes_are_grouped_by_stratum(): void {
        $this->resetAfterTest();

        $runid = $this->run_with_attempts();
        result_aggregator::aggregate($runid, 10);
        $stored = $this->stored($runid, 'stratum:conforming');

        $this->assertArrayHasKey('rmse', $stored);
        $this->assertArrayHasKey('stopsuccess', $stored);
    }

    /**
     * The local evaluation reports several k at once.
     *
     * @return void
     */
    public function test_local_outcomes_cover_several_k(): void {
        $this->resetAfterTest();

        $scalemap = [11 => '1:1', 12 => '1:2', 13 => '1:3', 14 => '1:4'];
        $profile = [
            'global'     => 0.0,
            'categories' => [[
                'index'     => 1,
                'theta'     => 0.0,
                'subscales' => [
                    ['index' => 1, 'theta' => -0.9],
                    ['index' => 2, 'theta' => -0.3],
                    ['index' => 3, 'theta' => 0.4],
                    ['index' => 4, 'theta' => 0.8],
                ],
            ]],
        ];
        $estimates = [11 => -0.8, 12 => -0.2, 13 => 0.5, 14 => 0.7];

        $result = subscale_evaluator::evaluate_person($profile, $estimates, $scalemap, ['estglobal' => 0.05]);

        // A strategy that finds the single worst subscale and one that finds
        // the worst three are different achievements; one configured k hides
        // which of the two happened.
        foreach ([1, 3] as $k) {
            $this->assertArrayHasKey('topk' . $k, $result);
            $this->assertArrayHasKey('ndcg' . $k, $result);
            $this->assertArrayHasKey('precision' . $k, $result);
            $this->assertArrayHasKey('recall' . $k, $result);
        }

        // Four subscales cannot support k = 5 or 10; a value there would be
        // invented rather than measured.
        $this->assertArrayNotHasKey('topk5', $result);
        $this->assertArrayNotHasKey('topk10', $result);
    }

    /**
     * The local recovery of the deviations is reported, not only their order.
     *
     * @return void
     */
    public function test_local_recovery_is_reported(): void {
        $this->resetAfterTest();

        $scalemap = [11 => '1:1', 12 => '1:2'];
        $profile = [
            'global'     => 0.0,
            'categories' => [[
                'index'     => 1,
                'theta'     => 0.0,
                'subscales' => [['index' => 1, 'theta' => -1.0], ['index' => 2, 'theta' => 1.0]],
            ]],
        ];

        $result = subscale_evaluator::evaluate_person(
            $profile,
            [11 => -1.0, 12 => 1.0],
            $scalemap,
            ['estglobal' => 0.0]
        );

        // A perfect ordering can still get every deviation wrong by a logit,
        // so the deviations themselves are reported alongside the ranking.
        $this->assertSame([-1.0, 1.0], $result['truedeltas']);
        $this->assertSame([-1.0, 1.0], $result['estdeltas']);
    }
}
