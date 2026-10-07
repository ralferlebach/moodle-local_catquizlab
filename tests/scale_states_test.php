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

use local_catquizlab\local\scale_states;
use local_catquizlab\local\test_flow;

/**
 * Which scales were active, locked or dropped after each step — from the engine's record, never guessed (#106, #109).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\scale_states
 * @covers     \local_catquizlab\local\test_flow
 */
final class scale_states_test extends \advanced_testcase {
    /**
     * A sitting of five questions over three scales, as the engine records it in trace mode.
     *
     * Scales 11, 12 and 13 start active. After the second question 13 is
     * locked; after the third 12 is dropped; after the fourth 13 is unlocked
     * and activated again.
     *
     * @param bool $recorded Whether the engine recorded the changes of the scales' states.
     * @return array An observation with its trace.
     */
    protected function observation(bool $recorded = true): array {
        $played = [];
        foreach ([11, 12, 13, 11, 13] as $index => $scaleid) {
            $played[] = ['id' => 101 + $index, 'catscaleid' => $scaleid, 'fraction' => $index % 2];
        }
        $progress = [
            'playedquestions' => $played,
            'activescales' => [11, 13], 'droppedscales' => [12 => 12], 'lockedscales' => [],
        ];
        if ($recorded) {
            $progress['scalestatetrace'] = [
                ['step' => 0, 'scaleid' => 11, 'event' => 'activated'],
                ['step' => 0, 'scaleid' => 12, 'event' => 'activated'],
                ['step' => 0, 'scaleid' => 13, 'event' => 'activated'],
                ['step' => 2, 'scaleid' => 13, 'event' => 'locked'],
                ['step' => 3, 'scaleid' => 12, 'event' => 'dropped'],
                ['step' => 4, 'scaleid' => 13, 'event' => 'unlocked'],
                ['step' => 4, 'scaleid' => 13, 'event' => 'activated'],
            ];
        }

        return ['attemptid' => 9, 'runid' => 1, 'strategy' => 'lowestsub', 'trace' => [
            'items' => [101, 102, 103, 104, 105],
            'abilitypath' => array_map(
                static fn(int $step): array => ['step' => $step, 'abilities' => [1 => 0.1 * $step]],
                range(1, 5)
            ),
            'progress' => $progress,
        ]];
    }

    /**
     * The engine's changes, replayed: the state of every scale after every step.
     *
     * @return void
     */
    public function test_the_states_are_replayed_step_by_step(): void {
        $trace = $this->observation()['trace'];
        $this->assertTrue(scale_states::recorded($trace));
        $states = scale_states::by_step($trace, 5);

        $this->assertSame([11 => 'active', 12 => 'active', 13 => 'active'], $states[1]);
        $this->assertSame([11 => 'active', 12 => 'active', 13 => 'locked'], $states[2]);
        $this->assertSame([11 => 'active', 12 => 'dropped', 13 => 'locked'], $states[3]);
        // Unlocked and activated at the same step: active after it.
        $this->assertSame([11 => 'active', 12 => 'dropped', 13 => 'active'], $states[4]);
        $this->assertSame($states[4], $states[5]);
        $this->assertSame(['active' => 2, 'locked' => 1, 'dropped' => 0, 'inactive' => 0], scale_states::counts($states[2]));
        $this->assertSame(['active' => 2, 'locked' => 0, 'dropped' => 1, 'inactive' => 0], scale_states::counts($states[5]));

        // One scale's course, as runs and as a sentence.
        $course = scale_states::course($states, 13);
        $this->assertSame([[1, 1, 'active'], [2, 3, 'locked'], [4, 5, 'active']], array_map('array_values', $course));
        $this->assertSame('step 1 active; steps 2–3 locked; from step 4 active', scale_states::describe($course, 5));
        $this->assertSame('steps 1–2 active; from step 3 dropped', scale_states::describe(scale_states::course($states, 12), 5));
        $this->assertSame([], scale_states::course($states, 99), 'a scale never in the selection has no course');

        // A dropped scale stays dropped, whatever is recorded after.
        $trace['progress']['scalestatetrace'][] = ['step' => 5, 'scaleid' => 12, 'event' => 'activated'];
        $this->assertSame('dropped', scale_states::by_step($trace, 5)[5][12]);

        // An event this version does not know changes nothing.
        $trace['progress']['scalestatetrace'][] = ['step' => 5, 'scaleid' => 11, 'event' => 'paused'];
        $this->assertSame('active', scale_states::by_step($trace, 5)[5][11]);

        // No record: nothing is known per step, and nothing is made up.
        $this->assertFalse(scale_states::recorded($this->observation(false)['trace']));
        $this->assertNull(scale_states::by_step($this->observation(false)['trace'], 5));
    }

    /**
     * The steps of a sitting carry the counts; the comparison has them as a metric, for the test and for one scale.
     *
     * @return void
     */
    public function test_the_flow_carries_the_states(): void {
        $observation = $this->observation();
        $flow = test_flow::steps($observation);

        $this->assertSame([3, 2, 1, 2, 2], array_column($flow['steps'], 'activescales'));
        $this->assertSame([0, 1, 1, 0, 0], array_column($flow['steps'], 'lockedscales'));
        $this->assertSame([0, 0, 1, 1, 1], array_column($flow['steps'], 'droppedscales'));

        $this->assertContains('activescales', test_flow::METRICS);
        $global = test_flow::series($flow, $observation['trace'], 'activescales');
        $this->assertSame([3, 2, 1, 2, 2], array_column($global['points'], 'y'));
        // For one scale: whether it was active after the step, and its course.
        $scale = test_flow::series($flow, $observation['trace'], 'activescales', 13);
        $this->assertSame([1, 0, 0, 1, 1], array_column($scale['points'], 'y'));
        $this->assertSame([[1, 1, 'active'], [2, 3, 'locked'], [4, 5, 'active']], array_map('array_values', $scale['course']));

        // Without the engine's record: unknown per step — not zero — and the state at the end as before.
        $observation = $this->observation(false);
        $flow = test_flow::steps($observation);
        $this->assertSame([null, null, null, null, null], array_column($flow['steps'], 'activescales'));
        $this->assertNull($flow['scalestates']);
        $series = test_flow::series($flow, $observation['trace'], 'activescales', 12);
        $this->assertSame([null, null, null, null, null], array_column($series['points'], 'y'));
        $this->assertSame(['dropped', null], [$series['status'], $series['course']]);
        $this->assertSame([null, null, null, null, null], array_column(
            test_flow::series($flow, $observation['trace'], 'activescales')['points'],
            'y'
        ));
    }
}
