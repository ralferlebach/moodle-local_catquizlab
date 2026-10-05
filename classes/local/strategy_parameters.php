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
 * Which CAT parameters apply to which strategy, and what stands in for the rest.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The one place that decides whether a parameter applies (#101).
 *
 * A budget a strategy never reads is not a harmless extra: stored, it reads as
 * a design decision; provisioned, it can become a limit the engine applies after
 * all; shown in the results, it describes a stop rule that did not exist. This
 * class decides, from strategy_catalog::CAPABILITIES, what is stored, what the
 * engine receives, and what the run and its results report.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class strategy_parameters {
    /** @var string How a parameter that does not apply is recorded. */
    public const NEUTRALISED = 'N/A (neutralised)';

    /** @var string What the classical test plays: every item of the scale (#104). */
    public const ALL_ITEMS = 'all items of the scale';

    /** @var int Minimum questions per subscale that asks for none. */
    public const NEUTRAL_SUBSCALE_MIN = 0;

    /** @var int Maximum questions per subscale that sets no ceiling (the engine's -1). */
    public const NEUTRAL_SUBSCALE_MAX = -1;

    /**
     * Standard-error bounds that stop nothing.
     *
     * Only ever sent for the classical test, whose strategy replaces the
     * engine's standard-error filter with a no-op; the engine's form requires
     * the group to hold numbers, so it holds these: a lower bound of 0 is never
     * reached, and 1.0 is the engine's own default upper bound.
     */
    public const NEUTRAL_SE_MIN = 0.0;

    /** @var float The engine's default upper standard-error bound. */
    public const NEUTRAL_SE_MAX = 1.0;

    /**
     * Every strategy a definition would run.
     *
     * The chosen strategy and every level of a swept one.
     *
     * @param array $def The definition.
     * @return string[]
     */
    public static function strategies_in_play(array $def): array {
        $keys = [];
        if (is_string($def['strategy'] ?? null) && $def['strategy'] !== '') {
            $keys[] = $def['strategy'];
        }
        foreach ((array) ($def['sweep']['factors']['strategy'] ?? []) as $level) {
            if (is_string($level) && $level !== '') {
                $keys[] = $level;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Whether any strategy a definition would run uses a parameter.
     *
     * @param array $def The definition.
     * @param string $capability One of the strategy_catalog::CAPABILITIES columns.
     * @return bool
     */
    public static function any_uses(array $def, string $capability): bool {
        foreach (self::strategies_in_play($def) as $key) {
            if (strategy_catalog::uses($key, $capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A definition without the parameters none of its strategies uses.
     *
     * Applied when a definition is saved and to every run's own definition, so
     * that neither the experiment nor a run stores a limit nothing reads. For a
     * run — one strategy — that means exactly what its strategy uses.
     *
     * @param array $def The definition.
     * @param string|null $only For one run: its strategy alone, whatever the
     *     definition's sweep still lists.
     * @return array
     */
    public static function strip(array $def, ?string $only = null): array {
        $probe = $only === null ? $def : ['strategy' => $only];
        if (!self::any_uses($probe, 'subscalemax')) {
            unset($def['budgets']['subscale']);
        }
        if (!self::any_uses($probe, 'standarderror')) {
            unset($def['budgets']['se']);
        }

        // A strategy's own budgets, down to the levels it uses.
        foreach ((array) ($def['budgetsbystrategy'] ?? []) as $key => $levels) {
            if (!strategy_catalog::uses((string) $key, 'globalmax')) {
                unset($def['budgetsbystrategy'][$key]['global']);
            }
            if (!strategy_catalog::uses((string) $key, 'subscalemax')) {
                unset($def['budgetsbystrategy'][$key]['subscale']);
            }
            if (!strategy_catalog::uses((string) $key, 'standarderror')) {
                unset($def['budgetsbystrategy'][$key]['se']);
            }
            if (empty($def['budgetsbystrategy'][$key])) {
                unset($def['budgetsbystrategy'][$key]);
            }
        }

        return $def;
    }

    /**
     * A fixed form's global maximum: every item, unless one was set for it.
     *
     * The classical test plays a fixed form. A shared maximum of, say, 35 —
     * meant for the adaptive strategies beside it in a sweep — turned it into
     * a 35-item test and made the comparison one of lengths, not of
     * strategies (#104). Its maximum is therefore unlimited by default; a
     * maximum given for it in its own budgets still applies.
     *
     * @param array $definition A run's definition, before its per-strategy
     *     budgets are removed.
     * @param array $overrides The experiment's per-strategy budgets.
     * @return array
     */
    public static function fixed_form_default(array $definition, array $overrides): array {
        $strategy = (string) ($definition['strategy'] ?? '');
        if (!strategy_catalog::fixed_form($strategy)) {
            return $definition;
        }
        // Every item of the scale, always: no minimum, no maximum. A budget
        // named for it anywhere — shared, swept, per strategy, per cell — does
        // not apply; the classical test does not count questions.
        // A minimum of 1 is the smallest a definition takes; with no maximum
        // the engine plays every item of the scale either way.
        $definition['budgets']['global'] = [
            'minitems' => 1,
            'maxitems' => experiment_definition::UNLIMITED,
        ];

        return $definition;
    }

    /**
     * What the engine receives for one strategy, and which of it is a stand-in.
     *
     * @param string $strategy The strategy key.
     * @param array $budgets The budgets block of the run's definition.
     * @return array{subscalemin: int, subscalemax: int, semin: float, semax: float, na: array<string, bool>}
     */
    public static function engine_values(string $strategy, array $budgets): array {
        $subscale = (array) ($budgets['subscale'] ?? []);
        $se = (array) ($budgets['se'] ?? []);

        $na = [
            'subscale'      => !strategy_catalog::uses($strategy, 'subscalemax'),
            'standarderror' => !strategy_catalog::uses($strategy, 'standarderror'),
            'firstquestion' => !strategy_catalog::uses($strategy, 'firstquestion'),
        ];

        if ($na['subscale']) {
            $subscalemin = self::NEUTRAL_SUBSCALE_MIN;
            $subscalemax = self::NEUTRAL_SUBSCALE_MAX;
        } else {
            $subscalemin = (int) ($subscale['minitems'] ?? 3);
            $subscalemax = experiment_definition::engine_maximum($subscale['maxitems'] ?? null, 4);
        }

        return [
            'subscalemin' => $subscalemin,
            'subscalemax' => $subscalemax,
            'semin'       => $na['standarderror'] ? self::NEUTRAL_SE_MIN : (float) ($se['min'] ?? 0.35),
            'semax'       => $na['standarderror'] ? self::NEUTRAL_SE_MAX : (float) ($se['max'] ?? 1.0),
            'na'          => $na,
        ];
    }

    /**
     * The stop rules that are in force for a run, in words.
     *
     * Only the ones the engine applies to this strategy: a classical test has
     * no precision target to report, and "at most 4 per subscale" means nothing
     * to a strategy that does not count by subscale.
     *
     * @param array $effective What test_provisioner::effective_parameters() returned.
     * @return string[]
     */
    public static function stop_rules(array $effective): array {
        $component = 'local_catquizlab';
        $rules = [];

        $global = $effective['budgets']['global'] ?? [];
        if ($global === self::ALL_ITEMS) {
            // The classical test: it ends when every item of the scale is played.
            $rules[] = get_string('stoprule:allitems', $component);
        } else {
            $global = (array) $global;
            $max = $global['maxitems'] ?? null;
            $rules[] = experiment_definition::is_unlimited($max)
                ? get_string('stoprule:nomaximum', $component)
                : get_string('stoprule:maximum', $component, (int) $max);
            if ((int) ($global['minitems'] ?? 0) > 0) {
                $rules[] = get_string('stoprule:minimum', $component, (int) $global['minitems']);
            }
        }

        $se = $effective['se'] ?? null;
        if (is_array($se) && isset($se['min'])) {
            $rules[] = get_string('stoprule:precision', $component, format_float((float) $se['min'], 2));
        }

        $subscale = $effective['budgets']['subscale'] ?? null;
        if (is_array($subscale)) {
            $submax = $subscale['maxitems'] ?? null;
            if (!experiment_definition::is_unlimited($submax) && (int) $submax > 0) {
                $rules[] = get_string('stoprule:subscalemaximum', $component, (int) $submax);
            }
            if ((int) ($subscale['minitems'] ?? 0) > 0) {
                $rules[] = get_string('stoprule:subscaleminimum', $component, (int) $subscale['minitems']);
            }
        }

        return $rules;
    }
}
