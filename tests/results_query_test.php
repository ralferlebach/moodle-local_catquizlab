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
 * Tests for the results data source and its aggregation.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\metrics;
use local_catquizlab\local\results_query;

/**
 * Results-query tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\results_query
 * @covers     \local_catquizlab\local\metrics
 */
final class results_query_test extends \advanced_testcase {
    /**
     * Build an experiment whose sweep varies the strategy, and seed attempts
     * with a known bias per strategy.
     *
     * @param float $biasfastest The bias to inject into the fastest cells.
     * @param float $biaslowest The bias to inject into the lowestsub cells.
     * @return int The experiment id.
     */
    protected function seeded_experiment(float $biasfastest = 0.2, float $biaslowest = -0.1): int {
        global $DB;

        $definition = experiment_definition::example_baseline();
        $definition['name'] = 'Results demo';
        $definition['replications'] = 2;
        $definition['persons']['count'] = 3;
        $definition['sweep'] = ['factors' => ['strategy' => ['fastest', 'lowestsub']]];

        $experimentid = (int) experiment_service::save($definition)['id'];
        experiment_service::create_sweep($experimentid);

        $abilities = [-1.0, 0.0, 1.5];
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
            $manifest = json_decode((string) $run->manifestjson, true) ?: [];
            $strategy = $manifest['config']['strategy']['key'] ?? 'fastest';
            $bias = $strategy === 'fastest' ? $biasfastest : $biaslowest;

            foreach ($abilities as $index => $ability) {
                $personid = (int) $DB->insert_record('local_catquizlab_person', (object) [
                    'runid'         => $run->id,
                    'twinid'        => sprintf('r%03d-t%05d', $run->replication, $index + 1),
                    'twinindex'     => $index + 1,
                    'severity'      => 'none',
                    'stratum'       => 'conforming',
                    'abilityglobal' => $ability,
                    'profilejson'   => json_encode(['global' => $ability, 'categories' => []]),
                    'moodleuserid'  => null,
                    'timecreated'   => time(),
                    'timemodified'  => time(),
                ]);

                $trace = [
                    'finaltheta' => $ability + $bias,
                    'finalse'    => 0.3 + 0.05 * $index,
                    'items'      => [101, 102, 103 + $index],
                    'nitems'     => 3,
                    'stopreason' => $index === 2 ? 'maxquestions' : 'standarderror',
                ];
                $DB->insert_record('local_catquizlab_attempt', (object) [
                    'runid'        => $run->id,
                    'personid'     => $personid,
                    'status'       => 30,
                    'tracejson'    => json_encode($trace),
                    'runtimems'    => 1500,
                    'tries'        => 1,
                    'timecreated'  => time(),
                    'timemodified' => time(),
                ]);
            }
        }

        return $experimentid;
    }

    /**
     * Every seeded attempt turns into one observation with its coordinates.
     *
     * @return void
     */
    public function test_observations_carry_their_coordinates(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $rows = (new results_query(['experimentid' => $id]))->observations();

        // Two strategies x two replications x three persons.
        $this->assertCount(12, $rows);
        foreach ($rows as $row) {
            $this->assertContains($row['strategy'], ['fastest', 'lowestsub']);
            $this->assertSame('2pl', $row['model']);
            $this->assertSame('conforming', $row['stratum']);
            $this->assertSame('baseline', $row['tier']);
            $this->assertNotEmpty($row['twinid']);
        }
    }

    /**
     * The coordinate filters narrow the observations.
     *
     * @return void
     */
    public function test_filter_narrows_the_observations(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $rows = (new results_query(['experimentid' => $id, 'strategy' => 'lowestsub']))->observations();

        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            $this->assertSame('lowestsub', $row['strategy']);
        }
    }

    /**
     * An attempt without a trace is left out rather than counted as zero.
     *
     * @return void
     */
    public function test_untraced_attempts_are_excluded(): void {
        global $DB;
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $runs = $DB->get_records('local_catquizlab_run', ['experimentid' => $id], 'id ASC', 'id', 0, 1);
        $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid'        => reset($runs)->id,
            'personid'     => 0,
            'status'       => 40,
            'tracejson'    => null,
            'runtimems'    => 0,
            'tries'        => 1,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        // Counting a traceless attempt as a zero would bias every mean it
        // entered towards the origin.
        $this->assertCount(12, (new results_query(['experimentid' => $id]))->observations());
    }

    /**
     * The injected bias comes back out of the aggregation.
     *
     * @return void
     */
    public function test_aggregation_recovers_the_injected_bias(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment(0.25, -0.15);
        $rows = (new results_query(['experimentid' => $id]))->observations();

        $groups = results_query::group($rows, 'strategy', 'error');
        $means = array_combine(array_column($groups, 'group'), array_column($groups, 'mean'));

        $this->assertEqualsWithDelta(0.25, $means['fastest'], 0.0001);
        $this->assertEqualsWithDelta(-0.15, $means['lowestsub'], 0.0001);
    }

    /**
     * A group of one reports no interval instead of a false one.
     *
     * @return void
     */
    public function test_single_observation_has_no_interval(): void {
        $this->resetAfterTest();

        $stat = results_query::describe_values([0.4]);

        $this->assertSame(1, $stat['n']);
        $this->assertSame(0.4, $stat['mean']);
        $this->assertNull($stat['sd']);
        $this->assertNull($stat['ci95lo']);
    }

    /**
     * The descriptive statistics are the textbook ones.
     *
     * @return void
     */
    public function test_describe_values(): void {
        $this->resetAfterTest();

        $stat = results_query::describe_values([2.0, 4.0, 4.0, 4.0, 5.0, 5.0, 7.0, 9.0]);

        $this->assertSame(8, $stat['n']);
        $this->assertEqualsWithDelta(5.0, $stat['mean'], 0.0001);
        // Sample standard deviation, n-1 in the denominator.
        $this->assertEqualsWithDelta(2.13809, $stat['sd'], 0.0001);
        $this->assertEqualsWithDelta(4.5, $stat['median'], 0.0001);
        $this->assertSame(2.0, $stat['min']);
        $this->assertSame(9.0, $stat['max']);
    }

    /**
     * Exhausting the item budget does not count as the stop rule succeeding.
     *
     * @return void
     */
    public function test_stop_rule_success(): void {
        $this->resetAfterTest();

        // The value "standarderror" is the precision criterion doing its job;
        // matching it against a bare "error" substring would file it as exhaustion.
        $this->assertTrue(results_query::stop_reached('standarderror'));
        $this->assertTrue(results_query::stop_reached('standarderrorpersubscale'));
        $this->assertFalse(results_query::stop_reached('maxquestions'));
        $this->assertFalse(results_query::stop_reached('error'));
        $this->assertFalse(results_query::stop_reached('nomoreitems'));
        $this->assertFalse(results_query::stop_reached(''));
    }

    /**
     * Even use gives a Gini of zero; total concentration approaches one.
     *
     * @return void
     */
    public function test_concentration_bounds(): void {
        $this->resetAfterTest();

        $even = metrics::concentration([0.5, 0.5, 0.5, 0.5]);
        $this->assertEqualsWithDelta(0.0, $even['gini'], 0.0001);

        $concentrated = metrics::concentration([1.0, 0.0, 0.0, 0.0]);
        $this->assertEqualsWithDelta(0.75, $concentrated['gini'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $concentrated['hhi'], 0.0001);
    }

    /**
     * Items that were never shown raise the concentration.
     *
     * Ignoring the unused remainder would make a pool that uses a tenth of its
     * items look as even as one that uses all of them.
     *
     * @return void
     */
    public function test_unused_items_count_towards_concentration(): void {
        $this->resetAfterTest();

        $withoutpool = metrics::concentration([0.5, 0.5]);
        $withpool = metrics::concentration([0.5, 0.5], 10);

        $this->assertEqualsWithDelta(0.0, $withoutpool['gini'], 0.0001);
        $this->assertGreaterThan(0.5, $withpool['gini']);
        $this->assertSame(10, $withpool['n']);
    }

    /**
     * An empty exposure list does not divide by zero.
     *
     * @return void
     */
    public function test_concentration_of_nothing(): void {
        $this->resetAfterTest();

        $stat = metrics::concentration([]);

        $this->assertSame(0, $stat['n']);
        $this->assertNull($stat['gini']);
    }

    /**
     * The provenance block states what the figures rest on.
     *
     * @return void
     */
    public function test_provenance_reports_the_aggregation_level(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $provenance = (new results_query(['experimentid' => $id]))->provenance();

        $this->assertSame(4, $provenance['runs']);
        $this->assertSame(12, $provenance['attempts']);
        $this->assertSame(2, $provenance['replications']);
        $this->assertSame(results_query::DISPERSION_CI95, $provenance['dispersion']);
    }

    /**
     * The filter menus offer only values the data contains.
     *
     * @return void
     */
    public function test_available_values_come_from_the_data(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $query = new results_query(['experimentid' => $id]);

        $this->assertSame(['fastest', 'lowestsub'], array_keys($query->available('strategy')));
        $this->assertSame(['ideal'], array_keys($query->available('variant')));
    }

    /**
     * Exposure is computed over the filtered attempts.
     *
     * @return void
     */
    public function test_exposure_across_the_filtered_attempts(): void {
        $this->resetAfterTest();

        $id = $this->seeded_experiment();
        $exposure = (new results_query(['experimentid' => $id]))->exposure();

        // Items 101 and 102 appear in every attempt; 103..105 in a third each.
        $this->assertSame(12, $exposure['nattempts']);
        $this->assertSame(1.0, $exposure['rates'][101]);
        $this->assertArrayHasKey('concentration', $exposure);
        $this->assertGreaterThan(0.0, $exposure['concentration']['gini']);
    }
}
