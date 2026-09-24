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
 * The single source of truth for CAT test strategies.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Maps a Catquizlab strategy key to the engine's strategy constant and to the
 * publication label used in the manuscript, the UI and the exports.
 *
 * Three names exist for the same thing and each has its own job. The internal
 * key (`lowestsub`) is the stable identifier: it appears in definitions, cell
 * keys and historical run rows, so it must never change. The engine constant
 * (`LOCAL_CATQUIZ_STRATEGY_LOWESTSUB`) is the technical contract with
 * local_catquiz. The label ("Detect weakest subscale") says what the condition
 * is diagnostically for, which is what a reader of the article needs.
 *
 * Keeping all three in one table is the point: before this class the numeric
 * ids were duplicated as literals (`DEFAULT_STRATEGY = 4`), which silently made
 * every unconfigured run a weakest-subscale run.
 *
 * The engine's numeric ids are read from its constants at runtime. The values
 * listed here are the documented contract used when the engine is absent — in
 * CI and stand-alone installs — so the settings builder stays testable. They
 * are never used to override a constant the engine actually defines.
 */
class strategy_catalog {
    /**
     * Every strategy: engine constant name, contract id, label and description.
     *
     * @var array<string, array{constant: string, contractid: int, label: string, description: string}>
     */
    protected const CATALOG = [
        'fastest'    => [
            'constant'    => 'LOCAL_CATQUIZ_STRATEGY_FASTEST',
            'contractid'  => 1,
            'label'       => 'Estimate global ability (MFI)',
            'description' => 'Maximises Fisher information at the current estimate, ignoring content balance.',
        ],
        'balanced'   => [
            'constant'    => 'LOCAL_CATQUIZ_STRATEGY_BALANCED',
            'contractid'  => 2,
            'label'       => 'Balanced content control',
            'description' => 'Trades information against an even spread across the content structure.',
        ],
        'allsubs'    => [
            // The only strategy that overrides filterbyquestionsperscale() and
            // therefore actually enforces a minimum on every subscale. For the
            // others the base class returns the context unchanged, so a
            // per-subscale minimum is a ceiling the selection may use, not a
            // quota it must fill — and treating it as a quota refuses valid
            // configurations before a worker ever runs.
            'enforcespersubscaleminimum' => true,
            'constant'    => 'LOCAL_CATQUIZ_STRATEGY_ALLSUBS',
            'contractid'  => 3,
            'label'       => 'Cover all subscales',
            'description' => 'Visits every subscale, so each one receives its item budget.',
        ],
        'lowestsub'  => [
            'constant'    => 'LOCAL_CATQUIZ_STRATEGY_LOWESTSUB',
            'contractid'  => 4,
            'label'       => 'Detect weakest subscale',
            'description' => 'Concentrates items where ability appears lowest, to pin down the deficit.',
        ],
        'highestsub' => [
            'constant'    => 'LOCAL_CATQUIZ_STRATEGY_HIGHESTSUB',
            'contractid'  => 5,
            'label'       => 'Detect strongest subscale',
            'description' => 'The mirror image of the weakest-subscale mode.',
        ],
        'pilot'      => [
            'constant'    => 'LOCAL_CATQUIZ_STRATEGY_PILOT',
            'contractid'  => 6,
            'label'       => 'Pilot-item mode',
            'description' => 'Mixes in uncalibrated pilot items alongside the operational selection.',
        ],
        'classic'    => [
            'constant'    => 'LOCAL_CATQUIZ_STRATEGY_CLASSIC',
            'contractid'  => 7,
            'label'       => 'Fixed-form baseline',
            'description' => 'A non-adaptive fixed form, the comparison baseline of the design.',
        ],
        'relsubs'    => [
            'constant'    => 'LOCAL_CATQUIZ_STRATEGY_RELSUBS',
            'contractid'  => 8,
            'label'       => 'Cover relevant subscales',
            'description' => 'Restricts the budget to the subscales that carry diagnostic information.',
        ],
    ];

    /**
     * All strategy keys, in catalogue order.
     *
     * @return string[]
     */
    public static function keys(): array {
        return array_keys(self::CATALOG);
    }

    /**
     * Whether a key is a known strategy.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function has(string $key): bool {
        return isset(self::CATALOG[$key]);
    }

    /**
     * The full descriptor of one strategy.
     *
     * @param string $key The strategy key.
     * @return array{key: string, engineid: int, constant: string, label: string, description: string}
     * @throws \coding_exception If the key is unknown.
     */
    public static function descriptor(string $key): array {
        if (!self::has($key)) {
            throw new \coding_exception('Unknown CAT strategy key: ' . $key);
        }
        $entry = self::CATALOG[$key];
        return [
            'key'         => $key,
            'engineid'    => self::engine_id($key),
            'constant'    => $entry['constant'],
            'label'       => $entry['label'],
            'description' => $entry['description'],
        ];
    }

    /**
     * All descriptors, keyed by strategy key.
     *
     * @return array<string, array>
     */
    public static function all(): array {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = self::descriptor($key);
        }
        return $out;
    }

    /**
     * The engine's numeric strategy id for a key.
     *
     * Prefers the engine's own constant. Falls back to the documented contract
     * value only when the engine is not installed at all; an engine that is
     * installed but does not define the constant is too old to run the
     * experiment and is rejected rather than silently mapped.
     *
     * @param string $key The strategy key.
     * @return int The engine strategy id.
     * @throws \coding_exception If the key is unknown.
     * @throws \moodle_exception If the installed engine does not define the constant.
     */
    public static function engine_id(string $key): int {
        if (!self::has($key)) {
            throw new \coding_exception('Unknown CAT strategy key: ' . $key);
        }
        $constant = self::CATALOG[$key]['constant'];
        if (defined($constant)) {
            return (int) constant($constant);
        }

        // The engine defines its strategy constants in its lib.php, and Moodle
        // loads a local plugin's lib.php only when something asks for it. In a
        // CLI run or a web service nothing has, so the constants are absent
        // even though the engine is installed and correct. Checking without
        // loading first reported a perfectly good engine as too old.
        self::load_engine_library();
        if (defined($constant)) {
            return (int) constant($constant);
        }

        if (environment::engine_available()) {
            throw new \moodle_exception('strategy:engineincompatible', 'local_catquizlab', '', $constant);
        }

        return (int) self::CATALOG[$key]['contractid'];
    }

    /**
     * Load the engine's library, which is where its constants live.
     *
     * Loading it more than once is harmless and cheap; not loading it at all
     * makes a correct engine look incompatible.
     *
     * @return void
     */
    protected static function load_engine_library(): void {
        global $CFG;

        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $library = $CFG->dirroot . '/local/catquiz/lib.php';
        if (is_readable($library)) {
            require_once($library);
        }
    }

    /**
     * The publication label of a strategy.
     *
     * @param string $key The strategy key.
     * @return string
     * @throws \coding_exception If the key is unknown.
     */
    /**
     * Whether this strategy makes the per-subscale minimum binding.
     *
     * Only `allsubs` overrides `filterbyquestionsperscale()` in the engine; the
     * base class returns the candidate set unchanged. For every other strategy
     * the subscale minimum bounds what the selection *may* take from a scale it
     * visits, not what it *must* take from all of them — so multiplying it
     * across every subscale describes a test the engine would never administer.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function enforces_per_subscale_minimum(string $key): bool {
        // Read from the catalogue rather than through descriptor(), which
        // assembles a fixed set of keys for display and would drop this one.
        return self::has($key) && !empty(self::CATALOG[$key]['enforcespersubscaleminimum']);
    }

    /**
     * The display label of a strategy.
     *
     * @param string $key The strategy key.
     * @return string
     */
    public static function label(string $key): string {
        if (!self::has($key)) {
            throw new \coding_exception('Unknown CAT strategy key: ' . $key);
        }
        return self::CATALOG[$key]['label'];
    }

    /**
     * The short description of a strategy, for the UI.
     *
     * @param string $key The strategy key.
     * @return string
     * @throws \coding_exception If the key is unknown.
     */
    public static function description(string $key): string {
        if (!self::has($key)) {
            throw new \coding_exception('Unknown CAT strategy key: ' . $key);
        }
        return self::CATALOG[$key]['description'];
    }

    /**
     * Key => label, for form select elements.
     *
     * @return array<string, string>
     */
    public static function menu(): array {
        $menu = [];
        foreach (self::keys() as $key) {
            $label = self::CATALOG[$key]['label'];

            // Offered, but named as what it is. Removing it silently would
            // leave somebody wondering where a strategy went; choosing it
            // unmarked let them build an experiment whose every sitting then
            // failed on the engine side.
            if (!self::runnable($key)) {
                $label .= ' — ' . get_string('strategy:notinengine', 'local_catquizlab');
            }

            $menu[$key] = $label;
        }
        return $menu;
    }

    /**
     * Check that the installed engine defines every strategy constant.
     *
     * @return array{compatible: bool, missing: string[]} Missing constant names, empty when compatible.
     */
    public static function engine_compatibility(): array {
        $runnable = self::runnable_engine_ids();
        $missing = [];

        foreach (self::CATALOG as $key => $entry) {
            // A constant is not a strategy. The engine builds its list from the
            // classes under teststrategy\strategy, and this fork ships six of
            // them for eight constants: "balanced" and "pilot" could be chosen
            // here, provisioned, and every sitting of them then failed, because
            // nothing on the engine side answers to those numbers.
            if (!defined($entry['constant']) || !in_array(self::engine_id($key), $runnable, true)) {
                $missing[] = $entry['constant'];
            }
        }

        return ['compatible' => $missing === [], 'missing' => $missing];
    }

    /**
     * The strategy numbers the installed engine can actually play.
     *
     * Read from the engine's own classes, the same way the engine reads them,
     * so a fork with more or fewer strategies is described correctly rather
     * than assumed.
     *
     * @return int[]
     */
    public static function runnable_engine_ids(): array {
        static $ids = null;

        if ($ids !== null) {
            return $ids;
        }

        $ids = [];
        if (!environment::engine_available()) {
            return $ids;
        }

        $classes = \core_component::get_component_classes_in_namespace(
            'local_catquiz',
            'teststrategy\\strategy'
        );
        foreach ($classes as $classname => $unused) {
            try {
                $strategy = new $classname();
                if (isset($strategy->id)) {
                    $ids[] = (int) $strategy->id;
                }
            } catch (\Throwable $ignored) {
                // A class that cannot be built cannot play a sitting either.
                continue;
            }
        }

        return $ids;
    }

    /**
     * Whether the installed engine can play this strategy.
     *
     * @param string $key The catalogue key.
     * @return bool
     */
    public static function runnable(string $key): bool {
        return self::has($key) && in_array(self::engine_id($key), self::runnable_engine_ids(), true);
    }
}
