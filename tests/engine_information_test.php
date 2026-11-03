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

use local_catquizlab\local\engine_information;
use local_catquizlab\local\test_flow;

/**
 * TI@n and the remaining potential, from the engine.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\engine_information
 */
final class engine_information_test extends \advanced_testcase {
    /**
     * A pool of six items, as the engine's item parameter list.
     *
     * @return array [list, item records]
     */
    protected function pool(): array {
        if (!engine_information::available()) {
            $this->markTestSkipped('Needs local_catquiz.');
        }
        $records = [];
        foreach ([[1.2, -0.5], [0.8, 0.3], [1.5, 1.1], [1.0, -2.0], [0.6, 0.0], [1.8, 0.2]] as $i => [$a, $b]) {
            $records[] = (object) [
                'id' => 100 + $i, 'componentid' => 100 + $i, 'componentname' => 'question', 'itemid' => 100 + $i,
                'model' => 'raschbirnbaum', 'status' => 4, 'difficulty' => $b, 'discrimination' => $a, 'guessing' => 0.0,
                'json' => json_encode(['difficulty' => $b, 'discrimination' => $a]), 'contextid' => 1,
                'catscaleid' => 1, 'timecreated' => 0, 'timemodified' => 0,
            ];
        }

        return [\local_catquiz\local\model\model_item_param_list::from_array($records), $records];
    }

    /**
     * TI@n: the n most informative items, not the first n.
     *
     * @return void
     */
    public function test_ti_at_n_takes_the_most_informative_items(): void {
        [$pool, $records] = $this->pool();
        $theta = 0.1;
        $informations = [];
        foreach ($records as $record) {
            $informations[] = test_flow::item_information([
                'model' => 'raschbirnbaum', 'discrimination' => $record->discrimination, 'difficulty' => $record->difficulty,
            ], $theta);
        }
        rsort($informations);

        foreach ([1, 2, 3, 6] as $n) {
            $this->assertEqualsWithDelta(
                array_sum(array_slice($informations, 0, $n)),
                engine_information::ti_at_n($theta, $pool, $n),
                1e-9,
                'TI@' . $n
            );
        }
        // More items than the pool holds: the whole pool.
        $this->assertEqualsWithDelta(array_sum($informations), engine_information::ti_at_n($theta, $pool, 50), 1e-9);
        $this->assertNull(engine_information::ti_at_n($theta, $pool, 0));
    }

    /**
     * The remaining potential leaves out what was played, and stops at what may still be played.
     *
     * @return void
     */
    public function test_the_remaining_potential(): void {
        [$pool] = $this->pool();
        $theta = 0.1;
        $all = engine_information::ti_at_n($theta, $pool, 6);
        $played = [105];
        $without = engine_information::remaining_max($theta, $pool, $played, 5);
        $this->assertLessThan($all, $without);
        $this->assertEqualsWithDelta($all - engine_information::ti_at_n($theta, $pool, 6)
            + engine_information::remaining_max($theta, $pool, [], 6) - $without, $all - $without, 1e-9);
        $this->assertSame(0.0, engine_information::remaining_max($theta, $pool, $played, 0));
        // The pool itself is not changed by it.
        $this->assertEqualsWithDelta($all, engine_information::ti_at_n($theta, $pool, 6), 1e-12);
    }

    /**
     * The remaining potential at its least: the weakest of the items not yet played (#106).
     *
     * @return void
     */
    public function test_the_remaining_minimum(): void {
        [$pool, $records] = $this->pool();
        $theta = -0.4;
        $played = [100, 103];
        $rest = [];
        foreach ($records as $record) {
            if (!in_array((int) $record->id, $played, true)) {
                $rest[] = test_flow::item_information([
                    'model' => 'raschbirnbaum', 'discrimination' => $record->discrimination,
                    'difficulty' => $record->difficulty,
                ], $theta);
            }
        }
        sort($rest);

        $step = engine_information::step($theta, $pool, 2, $played, 2);
        $this->assertEqualsWithDelta($rest[0] + $rest[1], $step['remainingmin'], 1e-9);
        $this->assertEqualsWithDelta($rest[3] + $rest[2], $step['remaining'], 1e-9);
        $this->assertLessThanOrEqual($step['remaining'], $step['remainingmin']);
        // Nothing may still be played: nothing remains, at most or at least.
        $none = engine_information::step($theta, $pool, 2, $played, 0);
        $this->assertSame([0.0, 0.0], [$none['remaining'], $none['remainingmin']]);
    }

    /**
     * The test flow of one subscale (#109): TI@n and the remaining TI counted on that scale alone, per step.
     *
     * Four steps; items 100 and 102 on scale 7, 103 on scale 8, then 101 on scale 7. On scale 7 the counts are
     * 1, 1, 1, 2 — the step on scale 8 changes nothing — and there is no value before the scale's first item.
     * Bounded by four questions per subscale, the rest is 4 − n of the items not yet played.
     *
     * @return void
     */
    public function test_the_flow_of_one_subscale_counts_its_own_items(): void {
        [$pool, $records] = $this->pool();
        $runid = 4242;
        $model = 'raschbirnbaum';
        // The pool the flow would read from the engine, put where the reader keeps it.
        $cache = new \ReflectionProperty(engine_information::class, 'pools');
        $cache->setAccessible(true);
        $cache->setValue(null, [$runid . '|7|' . $model => $pool]);

        $item = static fn(int $id, int $scale): array => ['id' => $id, 'componentid' => $id, 'catscaleid' => $scale,
            'model' => $model, 'difficulty' => (float) $records[$id - 100]->difficulty,
            'discrimination' => (float) $records[$id - 100]->discrimination];
        $played = [$item(100, 7), $item(103, 8), $item(102, 7), $item(101, 7)];
        $trace = [
            'progress' => ['playedquestions' => $played, 'activescales' => [7]],
            'abilitypath' => array_fill(0, 4, ['abilities' => [7 => 0.1]]),
        ];
        $flow = ['steps' => [['step' => 1], ['step' => 2], ['step' => 3], ['step' => 4]], 'final' => []];

        $tiatn = array_column(test_flow::series($flow, $trace, 'tiatn', 7, [7], $runid, 4)['points'], 'y');
        $rest = test_flow::series($flow, $trace, 'tiremaining', 7, [7], $runid, 4);
        $restmin = array_column(test_flow::series($flow, $trace, 'tiremainingmin', 7, [7], $runid, 4)['points'], 'y');

        // Expected from the same arithmetic, fed with the scale's own count and its own played items.
        $expect = [
            engine_information::step(0.1, $pool, 1, [100], 3),
            engine_information::step(0.1, $pool, 1, [100], 3),
            engine_information::step(0.1, $pool, 2, [100, 102], 2),
            engine_information::step(0.1, $pool, 3, [100, 102, 101], 1),
        ];
        foreach ($expect as $i => $step) {
            $this->assertEqualsWithDelta($step['tiatn'], $tiatn[$i], 1e-9, 'TI@n at step ' . ($i + 1));
            $this->assertEqualsWithDelta($step['remaining'], $rest['points'][$i]['y'], 1e-9, 'rest at step ' . ($i + 1));
            $this->assertEqualsWithDelta($step['remainingmin'], $restmin[$i], 1e-9, 'least rest at step ' . ($i + 1));
        }
        // The step on scale 8 does not move scale 7.
        $this->assertSame($tiatn[0], $tiatn[1]);
        // The best rest is never below the least.
        foreach ($restmin as $i => $least) {
            $this->assertGreaterThanOrEqual($least, $rest['points'][$i]['y']);
        }
        $this->assertSame('active', $rest['status']);

        // Unbounded (a category, or no per-subscale maximum): the rest is every unplayed item of the pool.
        $open = array_column(test_flow::series($flow, $trace, 'tiremaining', 7, [7], $runid, -1)['points'], 'y');
        $this->assertEqualsWithDelta(engine_information::step(0.1, $pool, 3, [100, 102, 101], 3)['remaining'], $open[3], 1e-9);

        // Before the scale's first item there is no value: the first step here is on scale 8.
        $late = $trace;
        $late['progress']['playedquestions'] = [$item(103, 8), $item(100, 7)];
        $flow2 = ['steps' => [['step' => 1], ['step' => 2]], 'final' => []];
        $points = array_column(test_flow::series($flow2, $late, 'tiatn', 7, [7], $runid, 4)['points'], 'y');
        $this->assertNull($points[0]);
        $this->assertNotNull($points[1]);
        $cache->setValue(null, []);
    }
}
