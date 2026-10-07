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

use local_catquizlab\local\engine_validity;
use local_catquizlab\local\result_validity;

/**
 * The one place that decides whether a result may go into the figures (#112).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquizlab\local\result_validity
 * @covers     \local_catquizlab\local\reason_catalog
 */
final class result_validity_test extends \advanced_testcase {
    /** @var array The engine's thresholds of a test that sets none: only the response pattern is checked. */
    protected const THRESHOLDS = ['nmintest' => 0, 'nminscale' => 0, 'semax' => null, 'rootscaleid' => 1];

    /**
     * Evaluate a sitting of a strategy.
     *
     * @param string $strategy The strategy.
     * @param int $played Items played.
     * @param array $with What differs from an ordinary sitting: facts, trace, measure, rule.
     * @return array
     */
    protected function evaluate(string $strategy, int $played, array $with = []): array {
        $trace = ($with['trace'] ?? []) + ['finaltheta' => 0.4, 'stopreason' => '', 'items' => range(1, max(1, $played)),
            'nitems' => $played];
        $facts = ($with['facts'] ?? []) + ['strategy' => $strategy, 'minitems' => 10, 'maxitems' => 30, 'semin' => 0.35,
            'poolsize' => 40, 'played' => $played, 'finalse' => 0.5, 'dropped' => 0, 'enginestatus' => null];
        $measure = array_key_exists('measure', $with) ? $with['measure'] : [
            'scales' => [1 => ['n' => $played, 'fraction' => 0.5, 'se' => (float) $facts['finalse']]],
            'n' => $played, 'fraction' => 0.5, 'engine' => ['valid' => true, 'scales' => []],
        ];

        return result_validity::evaluate_attempt(
            $trace,
            $facts,
            $measure,
            $with['thresholds'] ?? self::THRESHOLDS,
            $with['rule'] ?? engine_validity::RULE_UNIFORM
        );
    }

    /**
     * An adaptive strategy: valid at its target, at its maximum, out of items — not before its minimum.
     *
     * @return void
     */
    public function test_an_adaptive_sitting(): void {
        // The SE target reached after the minimum: the planned end.
        $v = $this->evaluate('fastest', 14, ['facts' => ['finalse' => 0.3]]);
        $this->assertSame([true, 'target_se_reached', []], [$v['valid'], $v['endcode'], $v['reasons']]);
        $this->assertSame([14, 14, 10, true, true, false, 'fastest'], [$v['played_items'], $v['answered_items'],
            $v['required_min_items'], $v['trace_complete'], $v['engine_complete'], $v['technical_failure'], $v['strategy']]);
        $this->assertTrue($v['designstop']);

        // The same SE after seven of ten: the design allows no end there.
        $v = $this->evaluate('fastest', 7, ['facts' => ['finalse' => 0.3]]);
        $this->assertSame([false, 'ended_before_minimum', 'ended_before_minimum'], [$v['valid'], $v['endcode'],
            $v['reasoncode']]);

        // The maximum number of questions: valid.
        $v = $this->evaluate('fastest', 30, ['facts' => ['enginestatus' => 4]]);
        $this->assertSame([true, 'max_items_reached'], [$v['valid'], $v['endcode']]);

        // Out of the pool: not the planned end, and not invalid for that.
        $v = $this->evaluate('fastest', 40, ['facts' => ['enginestatus' => 1, 'maxitems' => 100]]);
        $this->assertSame([true, 'pool_exhausted', false], [$v['valid'], $v['endcode'], $v['designstop']]);
        // Out of eligible questions before the minimum: invalid.
        $v = $this->evaluate('fastest', 6, ['facts' => ['enginestatus' => 1]]);
        $this->assertSame([false, 'no_eligible_item_before_minimum'], [$v['valid'], $v['endcode']]);

        // A time limit: valid where the minimum was reached, not valid before it — neither as such.
        $v = $this->evaluate('fastest', 12, ['facts' => ['enginestatus' => 8]]);
        $this->assertSame([true, 'timeout', []], [$v['valid'], $v['endcode'], $v['reasons']]);
        $v = $this->evaluate('fastest', 5, ['facts' => ['enginestatus' => 8]]);
        $this->assertSame([false, 'timeout', ['timeout']], [$v['valid'], $v['endcode'], $v['reasons']]);
    }

    /**
     * A fixed form is complete when it was played in full — whatever ended it.
     *
     * @return void
     */
    public function test_a_fixed_form(): void {
        $v = $this->evaluate('classic', 40);
        $this->assertSame([true, 'fixed_form_complete', 0], [$v['valid'], $v['endcode'], $v['required_min_items']]);
        $this->assertTrue($v['criterionstop']);

        $v = $this->evaluate('classic', 23);
        $this->assertSame([false, 'fixed_form_incomplete', ['fixed_form_incomplete']], [$v['valid'], $v['endcode'],
            $v['reasons']]);
        $this->assertFalse($v['designstop']);

        // Cut off by a time limit: a form not played in full.
        $v = $this->evaluate('classic', 23, ['facts' => ['enginestatus' => 9]]);
        $this->assertSame([false, 'timeout', ['timeout']], [$v['valid'], $v['endcode'], $v['reasons']]);
        // No minimum is asked of a fixed form: its form is its measure.
        $v = $this->evaluate('classic', 4, ['facts' => ['poolsize' => 4]]);
        $this->assertTrue($v['valid']);
        // A run that does not know its pool cannot call a form incomplete.
        $v = $this->evaluate('classic', 23, ['facts' => ['poolsize' => 0]]);
        $this->assertSame('fixed_form_complete', $v['endcode']);
    }

    /**
     * What makes a sitting no result at all, and the engine's definitions on top — for every strategy alike.
     *
     * @return void
     */
    public function test_what_else_makes_a_result_invalid(): void {
        foreach (['fastest', 'allsubs', 'lowestsub', 'highestsub', 'relsubs'] as $strategy) {
            // Without a final ability there is nothing to compare.
            $v = $this->evaluate($strategy, 14, ['trace' => ['finaltheta' => null], 'facts' => ['finalse' => 0.3]]);
            $this->assertSame([false, [result_validity::NO_FINAL_THETA]], [$v['valid'], $v['reasons']], $strategy);

            // The engine ended on an error of its own.
            $v = $this->evaluate($strategy, 14, ['facts' => ['enginestatus' => 3, 'finalse' => 0.3]]);
            $this->assertFalse($v['valid'], $strategy);
            $this->assertContains(result_validity::ENGINE_ERROR, $v['reasons']);
            $this->assertFalse($v['engine_complete']);

            // Every answer correct: no ability is determined — the engine's rule, applied to every strategy.
            $v = $this->evaluate($strategy, 14, ['facts' => ['finalse' => 0.3], 'measure' => [
                'scales' => [1 => ['n' => 14, 'fraction' => 1.0, 'se' => 0.3]], 'n' => 14, 'fraction' => 1.0,
                'engine' => ['valid' => true, 'scales' => []],
            ]]);
            $this->assertSame([false, [engine_validity::FRACTION]], [$v['valid'], $v['reasons']], $strategy);
            // By the engine's own verdict, which does not check the pattern here, it stands.
            $this->assertTrue($v['enginevalid']);
            $v = $this->evaluate($strategy, 14, ['rule' => engine_validity::RULE_ENGINE, 'facts' => ['finalse' => 0.3],
                'measure' => ['scales' => [1 => ['n' => 14, 'fraction' => 1.0, 'se' => 0.3]], 'n' => 14, 'fraction' => 1.0,
                    'engine' => ['valid' => true, 'scales' => []]]]);
            $this->assertTrue($v['valid'], $strategy);
            $this->assertFalse($v['uniformvalid']);
        }

        // The thresholds are the test's own: SE above its upper bound, fewer answers than its minimum.
        $strict = ['nmintest' => 20, 'nminscale' => 3, 'semax' => 0.4, 'rootscaleid' => 1];
        $v = $this->evaluate('fastest', 14, ['thresholds' => $strict, 'facts' => ['finalse' => 0.5, 'semin' => 0.2]]);
        $this->assertSame([engine_validity::N_MIN, engine_validity::SE_MAX], $v['reasons']);

        // Nothing on record of what was administered, and nothing from the engine: not judgeable.
        $v = result_validity::evaluate_attempt(
            ['finaltheta' => 0.1],
            ['strategy' => 'fastest', 'played' => 0],
            null,
            self::THRESHOLDS
        );
        $this->assertFalse($v['valid']);
        $this->assertFalse($v['trace_complete']);
        $this->assertContains(result_validity::TRACE_INCOMPLETE, $v['reasons']);
        $this->assertContains(engine_validity::NO_ENGINE_DATA, $v['reasons']);
        $this->assertNull($v['answered_items']);
    }

    /**
     * One scale's result: an administered item, a unique mapping, and the engine's definitions.
     *
     * @return void
     */
    public function test_a_scale_result(): void {
        $thresholds = ['nmintest' => 0, 'nminscale' => 3, 'semax' => 0.6, 'rootscaleid' => 1];
        $good = ['n' => 4, 'fraction' => 0.5, 'se' => 0.4];

        $this->assertSame([], result_validity::scale_reasons($good, 4, $thresholds));
        $this->assertSame([engine_validity::N_MIN], result_validity::scale_reasons(['n' => 2] + $good, 2, $thresholds));
        $this->assertSame([engine_validity::SE_MAX], result_validity::scale_reasons(['se' => 0.9] + $good, 4, $thresholds));
        $this->assertSame([engine_validity::FRACTION], result_validity::scale_reasons(['fraction' => 0.0] + $good, 4, $thresholds));
        // Two engine scales for one subscale of the design: whose estimate would it be?
        $this->assertSame([result_validity::MAPPING_AMBIGUOUS], result_validity::scale_reasons($good, 4, $thresholds, true));
        // The count unknown: not "no item", and not valid.
        $this->assertSame([engine_validity::ITEMS_UNKNOWN], result_validity::scale_reasons(null, null, $thresholds));
        $this->assertSame([engine_validity::ITEMS_UNKNOWN], result_validity::scale_reasons($good, null, $thresholds));
        // Known, and nothing answered on it.
        $this->assertSame([engine_validity::NOT_MEASURED], result_validity::scale_reasons(null, 0, $thresholds));
    }
}
