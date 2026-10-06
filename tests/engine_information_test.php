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
}
