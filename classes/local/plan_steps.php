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

namespace local_catquizlab\local;

/**
 * Defining an experiment, as the eight decisions it actually is.
 *
 * The editor is one long form. That is honest about the data and unhelpful
 * about the work: somebody defining their first experiment cannot tell which
 * fields belong together, which are still empty, or whether what they have is
 * enough to run. The form asks for everything at once and answers nothing.
 *
 * These are the same fields, described as the decisions they represent and
 * checked for whether each has been made. Nothing is hidden and no field moves
 * — the form stays what it is, and this says where in it somebody stands.
 *
 * The last two steps are not fields at all. Validating and creating the runs
 * are the things the whole form is for, and leaving them off the list made the
 * form feel like it ended before the work did.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plan_steps {
    /**
     * Where this experiment stands, step by step.
     *
     * @param int $experimentid The experiment, or 0 for a new one.
     * @return array{steps: array[], done: int, total: int, ready: bool, nextlabel: string}
     */
    public static function state(int $experimentid): array {
        global $DB;

        $component = 'local_catquizlab';

        $definition = [];
        $runs = 0;
        if ($experimentid > 0) {
            $experiment = $DB->get_record('local_catquizlab_experiment', ['id' => $experimentid]);
            if ($experiment) {
                $definition = json_decode((string) $experiment->configjson, true) ?: [];
                $runs = $DB->count_records('local_catquizlab_run', ['experimentid' => $experimentid]);
            }
        }

        $steps = [
            self::step('basics', 1, $component, self::has_basics($definition)),
            self::step('model', 2, $component, self::has_model($definition)),
            self::step('pool', 3, $component, self::has_pool($definition)),
            self::step('persons', 4, $component, self::has_persons($definition)),
            self::step('cat', 5, $component, self::has_budgets($definition)),
            self::step('sweep', 6, $component, self::has_sweep($definition)),
        ];

        // Validation passes when every decision above has been made: there is
        // nothing separate to check, and a seventh spinner would be theatre.
        $defined = true;
        foreach ($steps as $step) {
            $defined = $defined && !empty($step['done']);
        }

        $steps[] = self::step('validate', 7, $component, $defined);
        $steps[] = self::step('runs', 8, $component, $runs > 0);

        $done = 0;
        foreach ($steps as $step) {
            $done += empty($step['done']) ? 0 : 1;
        }

        return [
            'steps'     => $steps,
            'done'      => $done,
            'total'     => count($steps),
            'ready'     => $defined,
            'hasruns'   => $runs > 0,
            'runs'      => $runs,
            'nextlabel' => self::next_label($steps, $component),
            'progressurl' => $experimentid > 0
                ? (new \moodle_url('/local/catquizlab/runs.php', ['experimentid' => $experimentid]))->out(false)
                : '',
        ];
    }

    /**
     * The first thing still to do, in words.
     *
     * @param array[] $steps The steps.
     * @param string $component For the strings.
     * @return string
     */
    protected static function next_label(array $steps, string $component): string {
        foreach ($steps as $step) {
            if (empty($step['done'])) {
                return get_string('plan:next', $component, $step['label']);
            }
        }

        return get_string('plan:alldone', $component);
    }

    /**
     * Whether the experiment has a name and a description of what it is for.
     *
     * @param array $definition The definition.
     * @return bool
     */
    protected static function has_basics(array $definition): bool {
        return trim((string) ($definition['name'] ?? '')) !== '';
    }

    /**
     * Whether a model and a strategy have been chosen.
     *
     * @param array $definition The definition.
     * @return bool
     */
    protected static function has_model(array $definition): bool {
        return trim((string) ($definition['strategy'] ?? '')) !== ''
            && (!empty($definition['models']) || !empty($definition['model']));
    }

    /**
     * Whether the item pool has a shape.
     *
     * @param array $definition The definition.
     * @return bool
     */
    protected static function has_pool(array $definition): bool {
        $scales = $definition['pool']['scales'] ?? null;

        return is_array($scales) && (int) ($scales['categories'] ?? 0) > 0;
    }

    /**
     * Whether there are people to simulate.
     *
     * @param array $definition The definition.
     * @return bool
     */
    protected static function has_persons(array $definition): bool {
        return (int) ($definition['persons']['count'] ?? 0) > 0;
    }

    /**
     * Whether the test has budgets to stop on.
     *
     * @param array $definition The definition.
     * @return bool
     */
    protected static function has_budgets(array $definition): bool {
        return (int) ($definition['budgets']['global']['maxitems'] ?? 0) > 0;
    }

    /**
     * Whether the design says how many times to repeat each cell.
     *
     * @param array $definition The definition.
     * @return bool
     */
    protected static function has_sweep(array $definition): bool {
        return (int) ($definition['replications'] ?? 0) > 0;
    }

    /**
     * Build one step.
     *
     * @param string $id Stable identifier.
     * @param int $number Its position.
     * @param string $component For the strings.
     * @param bool $done Whether the decision has been made.
     * @return array
     */
    protected static function step(string $id, int $number, string $component, bool $done): array {
        return [
            'id'      => $id,
            'number'  => $number,
            'label'   => get_string('plan:step' . $id, $component),
            'done'    => $done,
            'pending' => !$done,
        ];
    }
}
