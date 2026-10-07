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

/**
 * Whether a strategy's question budgets can be met by the pool it is given.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The arithmetic of question budgets against a pool, in one place.
 *
 * The experiment form asks it on submit, and the same rules — passed to the
 * browser by client_rules() — while the budgets are typed. Before this, the
 * check that a strategy covering every subscale could give each its minimum
 * read the model's number of response categories as the number of domains and
 * a field that does not exist as the number of subscales: the product was zero
 * and the check skipped itself. One hundred subscales at three questions each,
 * under a maximum of twenty-five, was saved without a word.
 *
 * The rules, each from the engine's own selection:
 * - a strategy that enforces the minimum per subscale (allsubs) needs at least
 *   minimum per subscale × subscales questions, within the global maximum;
 * - and at least that many items in every subscale;
 * - the maximum per subscale is a hard ceiling in every strategy's selection
 *   (mayberemovescale): maximum per subscale × subscales below the global
 *   minimum means every sitting runs out of questions before its minimum;
 * - an adaptive strategy needs at least its minimum number of items in the
 *   whole pool;
 * - a minimum above the maximum.
 *
 * The classical test plays every item of the scale and has no budget to check.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class budget_feasibility {
    /** @var string[] The problem codes, each with a string "feasibility:<code>". */
    public const CODES = ['minabovemax', 'floorabovemaximum', 'floorabovepool', 'ceilingbelowminimum', 'poolbelowminimum'];

    /**
     * What one strategy's budgets cannot do with this pool.
     *
     * @param string $strategy The strategy key.
     * @param array $budgets 'globalmin', 'globalmax', 'subscalemin', 'subscalemax': int, or null for none or unlimited.
     * @param int $leaves The number of subscales (leaf scales) of the pool.
     * @param int $itemspersubscale Items in each subscale.
     * @return array[] Each: code, field (the budget to change), a (the string's parameters).
     */
    public static function check(string $strategy, array $budgets, int $leaves, int $itemspersubscale): array {
        if (!strategy_catalog::has($strategy) || !strategy_catalog::uses($strategy, 'globalmax')) {
            return [];
        }
        $num = static fn($v): ?int => ($v === null || $v === '' || experiment_definition::is_unlimited($v)) ? null : (int) $v;
        $gmin = $num($budgets['globalmin'] ?? null);
        $gmax = $num($budgets['globalmax'] ?? null);
        $subscales = strategy_catalog::uses_subscales($strategy);
        $smin = $subscales ? $num($budgets['subscalemin'] ?? null) : null;
        $smax = $subscales ? $num($budgets['subscalemax'] ?? null) : null;
        $label = strategy_catalog::label($strategy);
        $problems = [];
        $add = static function (string $code, string $field, array $a) use (&$problems, $label): void {
            $problems[] = ['code' => $code, 'field' => $field, 'a' => ['strategy' => $label] + $a];
        };

        if ($gmin !== null && $gmax !== null && $gmax > 0 && $gmin > $gmax) {
            $add('minabovemax', 'globalmin', ['minimum' => $gmin, 'maximum' => $gmax]);
        }
        if ($leaves > 0 && strategy_catalog::enforces_per_subscale_minimum($strategy) && $smin !== null && $smin > 0) {
            if ($gmax !== null && $gmax > 0 && $smin * $leaves > $gmax) {
                $add('floorabovemaximum', 'globalmax', ['leaves' => $leaves, 'submin' => $smin,
                    'floor' => $smin * $leaves, 'maximum' => $gmax]);
            }
            if ($itemspersubscale > 0 && $smin > $itemspersubscale) {
                $add('floorabovepool', 'subscalemin', ['submin' => $smin, 'items' => $itemspersubscale]);
            }
        }
        if ($leaves > 0 && $smax !== null && $smax > 0 && $gmin !== null && $smax * $leaves < $gmin) {
            $add('ceilingbelowminimum', 'subscalemax', ['leaves' => $leaves, 'submax' => $smax,
                'ceiling' => $smax * $leaves, 'minimum' => $gmin]);
        }
        if ($leaves > 0 && $itemspersubscale > 0 && $gmin !== null && $leaves * $itemspersubscale < $gmin) {
            $add('poolbelowminimum', 'globalmin', ['pool' => $leaves * $itemspersubscale, 'minimum' => $gmin]);
        }

        return $problems;
    }

    /**
     * A problem as a sentence.
     *
     * @param array $problem One of check()'s problems.
     * @return string
     */
    public static function message(array $problem): string {
        return get_string('feasibility:' . $problem['code'], 'local_catquizlab', (object) $problem['a']);
    }

    /**
     * What the browser needs to apply the same rules while the form is filled in.
     *
     * @return array strategies (key => label and what it uses) and messages (code => string with {$a->…} placeholders).
     */
    public static function client_rules(): array {
        $strategies = [];
        foreach (strategy_catalog::keys() as $key) {
            if (!strategy_catalog::runnable($key)) {
                continue;
            }
            $strategies[$key] = [
                'label' => strategy_catalog::label($key),
                'global' => strategy_catalog::uses($key, 'globalmax'),
                'subscale' => strategy_catalog::uses_subscales($key),
                'floor' => strategy_catalog::enforces_per_subscale_minimum($key),
                'se' => strategy_catalog::uses_standard_error($key),
                'pilot' => in_array($key, strategy_catalog::using('pilot'), true),
            ];
        }
        $messages = [];
        $strings = get_string_manager();
        foreach (self::CODES as $code) {
            // The raw string, placeholders kept: the browser fills them in.
            $messages[$code] = $strings->get_string('feasibility:' . $code, 'local_catquizlab', null);
        }

        return ['strategies' => $strategies, 'messages' => $messages];
    }
}
