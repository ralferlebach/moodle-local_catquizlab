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
 * Tests for the test-flow reconstruction and the feasibility verdict.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab;

use local_catquizlab\local\test_flow;

/**
 * Test-flow tests.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\test_flow
 */
final class test_flow_test extends \advanced_testcase {
    /**
     * An observation with a progress snapshot.
     *
     * @return array
     */
    protected function rich_observation(): array {
        return [
            'attemptid'   => 7,
            'runid'       => 1,
            'twinid'      => 'r001-t00001',
            'strategy'    => 'lowestsub',
            'truetheta'   => 0.5,
            'esttheta'    => 0.42,
            'se'          => 0.30,
            'nitems'      => 3,
            'stopreached' => true,
            'trace'       => [
                'items'        => [101, 102, 103],
                // The trajectory lives in debug_info's per-step snapshots;
                // progress only ever holds the final ability per scale.
                'abilitypath'  => [
                    ['step' => 1, 'abilities' => [1 => 0.1]],
                    ['step' => 2, 'abilities' => [1 => 0.3]],
                    ['step' => 3, 'abilities' => [1 => 0.42]],
                ],
                'progress' => [
                    'playedquestions' => [
                        ['id' => 101, 'catscaleid' => 11, 'fraction' => 1.0],
                        ['id' => 102, 'catscaleid' => 12, 'fraction' => 0.0],
                        ['id' => 103, 'catscaleid' => 11, 'fraction' => 1.0],
                    ],
                    'abilities'       => [1 => 0.42],
                    'activescales'    => [11, 12],
                    'droppedscales'   => [13],
                    'lockedscales'    => [],
                    'playedquestionsbyscale' => [11 => 2, 12 => 1],
                ],
            ],
        ];
    }

    /**
     * An observation whose progress snapshot is gone.
     *
     * @return array
     */
    protected function thin_observation(): array {
        $observation = $this->rich_observation();
        unset($observation['trace']['progress'], $observation['trace']['abilitypath']);
        $observation['trace']['questionsperscale'] = [11 => 2, 12 => 1];

        return $observation;
    }

    /**
     * The information a precision target demands.
     *
     * @return void
     */
    public function test_required_information(): void {
        $this->resetAfterTest();

        // I = 1 / SE^2: an SE of 0.5 needs 4, an SE of 0.3 needs about 11.1.
        $this->assertEqualsWithDelta(4.0, test_flow::required_information(0.5), 0.000001);
        $this->assertEqualsWithDelta(11.111111, test_flow::required_information(0.3), 0.00001);
        $this->assertEqualsWithDelta(1.0, test_flow::required_information(1.0), 0.000001);
        $this->assertNull(test_flow::required_information(0.0));
        $this->assertNull(test_flow::required_information(-0.3));
    }

    /**
     * Information and standard error are inverses of each other.
     *
     * @return void
     */
    public function test_information_and_standard_error_round_trip(): void {
        $this->resetAfterTest();

        foreach ([0.2, 0.35, 0.5, 1.0] as $se) {
            $information = test_flow::required_information($se);
            $this->assertEqualsWithDelta($se, test_flow::standard_error($information), 0.000001);
        }
        $this->assertNull(test_flow::standard_error(0.0));
    }

    /**
     * The step-by-step debug information yields the ability path.
     *
     * @return void
     */
    public function test_rich_source_gives_the_ability_path(): void {
        $this->resetAfterTest();

        $flow = test_flow::steps($this->rich_observation());

        $this->assertSame(test_flow::SOURCE_PROGRESS, $flow['source']);
        $this->assertCount(3, $flow['steps']);
        $this->assertSame(101, $flow['steps'][0]['questionid']);
        $this->assertSame(11, $flow['steps'][0]['scaleid']);
        $this->assertEqualsWithDelta(0.42, $flow['steps'][2]['ability'], 0.000001);
        $this->assertSame([13], $flow['scales']['dropped']);
    }

    /**
     * Without a trajectory the flow falls back to the items and says so.
     *
     * @return void
     */
    public function test_thin_source_is_marked_as_such(): void {
        $this->resetAfterTest();

        $flow = test_flow::steps($this->thin_observation());

        $this->assertSame(test_flow::SOURCE_DEBUG, $flow['source']);
        $this->assertCount(3, $flow['steps']);
        // The ability path is genuinely absent here. Reporting it as null
        // rather than as zero keeps "not recorded" apart from "did not move".
        $this->assertNull($flow['steps'][0]['ability']);
        $this->assertArrayNotHasKey('active', $flow['scales']);
    }

    /**
     * An attempt with neither source reports nothing rather than an empty flow.
     *
     * @return void
     */
    public function test_no_source(): void {
        $this->resetAfterTest();

        $flow = test_flow::steps(['trace' => []]);

        $this->assertSame(test_flow::SOURCE_NONE, $flow['source']);
        $this->assertSame([], $flow['steps']);
    }

    /**
     * An attempt that met its target is reported as reaching it.
     *
     * @return void
     */
    public function test_target_reached(): void {
        $this->resetAfterTest();

        $cat = ['se' => ['min' => 0.35], 'budgets' => ['global' => ['maxitems' => 25]]];
        $verdict = test_flow::feasibility($this->rich_observation(), $cat);

        // An achieved SE of 0.30 beats a target of 0.35.
        $this->assertSame('reached', $verdict['verdict']);
        $this->assertGreaterThan($verdict['required'], $verdict['achieved']);
    }

    /**
     * Running out of items short of the target is named as such.
     *
     * @return void
     */
    public function test_budget_exhaustion_is_distinguished_from_a_bad_strategy(): void {
        $this->resetAfterTest();

        $observation = $this->rich_observation();
        $observation['se'] = 0.6;
        $observation['nitems'] = 25;
        $observation['stopreached'] = false;
        $cat = ['se' => ['min' => 0.30], 'budgets' => ['global' => ['maxitems' => 25]]];

        $verdict = test_flow::feasibility($observation, $cat);

        // Blaming the strategy here would blame it for an arithmetic
        // impossibility: the budget was spent and the target was still short.
        $this->assertSame('budgetexhausted', $verdict['verdict']);
        $this->assertLessThan($verdict['required'], $verdict['achieved']);
    }

    /**
     * Stopping on another criterion short of the target is its own outcome.
     *
     * @return void
     */
    public function test_stopped_on_another_criterion(): void {
        $this->resetAfterTest();

        $observation = $this->rich_observation();
        $observation['se'] = 0.55;
        $observation['nitems'] = 10;
        $observation['stopreached'] = true;
        $cat = ['se' => ['min' => 0.30], 'budgets' => ['global' => ['maxitems' => 25]]];

        $this->assertSame('stoppedearly', test_flow::feasibility($observation, $cat)['verdict']);
    }

    /**
     * Without a recorded standard error the verdict is unknown, not a guess.
     *
     * @return void
     */
    public function test_missing_data_gives_an_unknown_verdict(): void {
        $this->resetAfterTest();

        $observation = $this->rich_observation();
        $observation['se'] = null;

        $verdict = test_flow::feasibility($observation, ['se' => ['min' => 0.3]]);

        // Declaring a target infeasible on no evidence would excuse a strategy
        // that simply chose badly.
        $this->assertSame('unknown', $verdict['verdict']);
        $this->assertNull($verdict['achieved']);
    }

    /**
     * Without a configured target there is nothing to judge against.
     *
     * @return void
     */
    public function test_no_target_configured(): void {
        $this->resetAfterTest();

        $this->assertSame('unknown', test_flow::feasibility($this->rich_observation(), [])['verdict']);
    }

    /**
     * The verdicts are counted per outcome.
     *
     * @return void
     */
    public function test_feasibility_summary(): void {
        $this->resetAfterTest();

        $counts = test_flow::summarise_feasibility([
            ['verdict' => 'reached'],
            ['verdict' => 'reached'],
            ['verdict' => 'budgetexhausted'],
            ['verdict' => 'unknown'],
        ]);

        $this->assertSame(4, $counts['n']);
        $this->assertSame(2, $counts['reached']);
        $this->assertSame(1, $counts['budgetexhausted']);
        $this->assertSame(0, $counts['missed']);
    }

    /**
     * The implied item count follows from the target and the item information.
     *
     * @return void
     */
    public function test_implied_items(): void {
        $this->resetAfterTest();

        // An SE of 0.3 needs about 11.1 information; at 0.5 per item that is 23.
        $this->assertSame(23, test_flow::implied_items(0.3, 0.5));
        $this->assertSame(4, test_flow::implied_items(0.5, 1.0));
        $this->assertNull(test_flow::implied_items(0.3, 0.0));
        $this->assertNull(test_flow::implied_items(0.0, 0.5));
    }
}
