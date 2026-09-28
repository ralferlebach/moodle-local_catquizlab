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
     * What each strategy uses, as the engine's own form decides it (#101, #103).
     *
     * Read from local_catquiz 1.2.1, classes/teststrategy/info.php:
     * - questions per scale are hidden for FASTEST and CLASSIC
     *   ($strategieswithoutquestionsperscale);
     * - the standard-error group is shown only for LOWESTSUB, HIGHESTSUB,
     *   ALLSUBS, FASTEST and RELSUBS;
     * - pilot questions and the first-question settings are hidden for
     *   CLASSIC ($strategieswithoutpilotquestions);
     * - classicalcat overrides filterbystandarderror() and filterbytestinfo()
     *   as no-ops: a fixed form.
     * strategy_engine_catalog_test compares this table against that file, so a
     * change on the engine side cannot drift away from it unnoticed.
     * "balanced" and "pilot" have no engine class; their rows describe what
     * they were meant to be, for historic definitions only.
     */
    public const CAPABILITIES = [
        'fastest'    => ['globalmin' => true, 'globalmax' => true, 'subscalemin' => false, 'subscalemax' => false,
            'standarderror' => true, 'pilot' => true, 'firstquestion' => true, 'fixedform' => false],
        'balanced'   => ['globalmin' => true, 'globalmax' => true, 'subscalemin' => true, 'subscalemax' => true,
            'standarderror' => true, 'pilot' => true, 'firstquestion' => true, 'fixedform' => false],
        'allsubs'    => ['globalmin' => true, 'globalmax' => true, 'subscalemin' => true, 'subscalemax' => true,
            'standarderror' => true, 'pilot' => true, 'firstquestion' => true, 'fixedform' => false],
        'lowestsub'  => ['globalmin' => true, 'globalmax' => true, 'subscalemin' => true, 'subscalemax' => true,
            'standarderror' => true, 'pilot' => true, 'firstquestion' => true, 'fixedform' => false],
        'highestsub' => ['globalmin' => true, 'globalmax' => true, 'subscalemin' => true, 'subscalemax' => true,
            'standarderror' => true, 'pilot' => true, 'firstquestion' => true, 'fixedform' => false],
        'pilot'      => ['globalmin' => true, 'globalmax' => true, 'subscalemin' => false, 'subscalemax' => false,
            'standarderror' => true, 'pilot' => true, 'firstquestion' => true, 'fixedform' => false],
        'classic'    => ['globalmin' => true, 'globalmax' => true, 'subscalemin' => false, 'subscalemax' => false,
            'standarderror' => false, 'pilot' => false, 'firstquestion' => false, 'fixedform' => true],
        'relsubs'    => ['globalmin' => true, 'globalmax' => true, 'subscalemin' => true, 'subscalemax' => true,
            'standarderror' => true, 'pilot' => true, 'firstquestion' => true, 'fixedform' => false],
    ];

    /**
     * Whether a strategy uses a parameter.
     *
     * @param string $key The strategy key.
     * @param string $capability One of the CAPABILITIES columns.
     * @return bool
     */
    public static function uses(string $key, string $capability): bool {
        return !empty(self::CAPABILITIES[$key][$capability]);
    }

    /**
     * Whether a strategy has a lower bound on questions per sitting.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function uses_global_min(string $key): bool {
        return self::uses($key, 'globalmin');
    }

    /**
     * Whether a strategy has an upper bound on questions per sitting.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function uses_global_max(string $key): bool {
        return self::uses($key, 'globalmax');
    }

    /**
     * Whether a strategy has a lower bound on questions per subscale.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function uses_subscale_min(string $key): bool {
        return self::uses($key, 'subscalemin');
    }

    /**
     * Whether a strategy has an upper bound on questions per subscale.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function uses_subscale_max(string $key): bool {
        return self::uses($key, 'subscalemax');
    }

    /**
     * Whether a strategy stops or filters by standard error.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function uses_standard_error(string $key): bool {
        return self::uses($key, 'standarderror');
    }

    /**
     * Whether a strategy can include pilot questions.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function supports_pilot_items(string $key): bool {
        return self::uses($key, 'pilot');
    }

    /**
     * Whether a strategy uses the first-question policy.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function uses_first_question_policy(string $key): bool {
        return self::uses($key, 'firstquestion');
    }

    /**
     * Whether a strategy plays a fixed form rather than adapting.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function fixed_form(string $key): bool {
        return self::uses($key, 'fixedform');
    }

    /**
     * The strategies that use a parameter.
     *
     * @param string $capability One of the CAPABILITIES columns.
     * @return string[]
     */
    public static function using(string $capability): array {
        return array_keys(array_filter(self::CAPABILITIES, static function (array $row) use ($capability): bool {
            return !empty($row[$capability]);
        }));
    }

    /**
     * Whether a strategy works with subscales at all.
     *
     * "fastest" estimates one global ability and "classic" plays a fixed form:
     * a per-subscale budget means nothing to either, so it is neither required
     * nor offered for them.
     *
     * @param string $key The strategy key.
     * @return bool
     */
    public static function uses_subscales(string $key): bool {
        return self::uses($key, 'subscalemax');
    }

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

        // The engine's own name for it, where the engine is there to say
        // (#103): one source for what a strategy is called. This plugin's
        // descriptive name is kept as an alias, never instead.
        $engine = self::engine_strategies()[self::engine_id($key)] ?? null;
        if ($engine !== null && $engine['description'] !== '') {
            return $engine['description'];
        }

        return self::CATALOG[$key]['label'];
    }

    /**
     * This plugin's descriptive name for a strategy, marked as such.
     *
     * @param string $key The strategy key.
     * @return string
     */
    public static function alias(string $key): string {
        return self::has($key) ? self::CATALOG[$key]['label'] : $key;
    }

    /**
     * A strategy's name for display, marked where the engine cannot play it.
     *
     * For historic definitions and runs: they keep their strategy, and say
     * that it is no longer available rather than being renamed or hidden.
     *
     * @param string $key The strategy key.
     * @return string
     */
    public static function display_label(string $key): string {
        if (!self::has($key)) {
            return $key;
        }
        if (!self::runnable($key)) {
            return self::alias($key) . ' — ' . get_string('strategy:notinengine', 'local_catquizlab');
        }

        return self::label($key);
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
            // Only what the installed engine can play (#97). A strategy it has
            // no class for is not a choice: choosing it built an experiment
            // whose every sitting then failed on the engine side. A stored
            // definition that names one is refused by validation, with the
            // reason, rather than silently changed.
            if (self::runnable($key)) {
                $menu[$key] = self::CATALOG[$key]['label'];
            }
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
        return array_keys(array_filter(self::engine_strategies(), static function (array $strategy): bool {
            return $strategy['active'];
        }));
    }

    /** @var array|null The engine's strategies as read in this request. */
    protected static ?array $enginestrategies = null;

    /**
     * Forget what was read from the engine in this request.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$enginestrategies = null;
    }

    /**
     * The engine's strategy classes, as the engine itself finds them.
     *
     * @return string[] Class names without a leading backslash.
     */
    protected static function engine_classes(): array {
        $classes = array_keys(\core_component::get_component_classes_in_namespace(
            'local_catquiz',
            'teststrategy\\strategy'
        ));
        $classes = array_map(static fn(string $class): string => ltrim($class, '\\'), $classes);
        sort($classes);

        return $classes;
    }

    /**
     * What identifies the engine's strategy set: its version and its classes.
     *
     * A new engine release, or a strategy class added or removed, changes it.
     *
     * @return string
     */
    public static function engine_fingerprint(): string {
        if (!environment::engine_available()) {
            return '';
        }

        return sha1((string) get_config('local_catquiz', 'version') . '|' . implode(',', self::engine_classes()));
    }

    /**
     * The engine's release and version, as the plugin manager knows them.
     *
     * @return string
     */
    public static function engine_release(): string {
        $info = \core_plugin_manager::instance()->get_plugin_info('local_catquiz');
        if (!$info) {
            return '';
        }

        return trim((string) ($info->release ?? '') . ' (' . (string) ($info->versiondisk ?? '') . ')');
    }

    /**
     * The engine's strategies, keyed by id, as the engine describes them.
     *
     * Read through the engine's own API, with three guards (#103):
     *
     * - The engine caches its list under one key and keeps it until its
     *   invalidation event fires, so after an engine update the old list can
     *   outlive the code that made it. When the fingerprint differs from the
     *   one stored, the engine's cache is purged before it is read.
     * - An object whose class the engine no longer has is dropped, even where
     *   the fingerprint matches: a development checkout, a copied cache.
     * - The engine caches under "all" whatever $onlyactive was when the list
     *   was first built, so a list built with false holds inactive strategies
     *   when read with true. Each class's ACTIVE flag is checked here.
     *
     * @return array<int, array{id: int, class: string, description: string, active: bool, key: ?string}>
     */
    public static function engine_strategies(): array {
        if (self::$enginestrategies !== null) {
            return self::$enginestrategies;
        }

        self::$enginestrategies = [];
        if (!environment::engine_available()) {
            return self::$enginestrategies;
        }

        $fingerprint = self::engine_fingerprint();
        if ((string) get_config('local_catquizlab', 'enginestrategyfingerprint') !== $fingerprint) {
            \cache::make('local_catquiz', 'teststrategies')->purge();
            set_config('enginestrategyfingerprint', $fingerprint, 'local_catquizlab');
        }

        $objects = [];
        try {
            if (class_exists('\local_catquiz\teststrategy\info')) {
                $objects = (array) \local_catquiz\teststrategy\info::return_available_strategies(true);
            }
        } catch (\Throwable $ignored) {
            $objects = [];
        }

        $current = self::engine_classes();

        // Without the API, the classes as the engine itself reads them.
        if ($objects === []) {
            foreach ($current as $classname) {
                try {
                    $objects[] = new $classname();
                } catch (\Throwable $ignored) {
                    continue;
                }
            }
        }

        $keys = [];
        foreach (self::keys() as $key) {
            $keys[self::engine_id($key)] = $key;
        }

        foreach ($objects as $strategy) {
            if (!is_object($strategy) || !isset($strategy->id)) {
                continue;
            }
            $class = ltrim(get_class($strategy), '\\');
            if (!in_array($class, $current, true)) {
                continue;
            }
            $id = (int) $strategy->id;
            self::$enginestrategies[$id] = [
                'id'          => $id,
                'class'       => $class,
                'description' => method_exists($strategy, 'get_description') ? (string) $strategy->get_description() : '',
                'active'      => defined($class . '::ACTIVE') ? (bool) constant($class . '::ACTIVE') : true,
                'key'         => $keys[$id] ?? null,
            ];
        }
        ksort(self::$enginestrategies);

        return self::$enginestrategies;
    }

    /**
     * The engine's catalogue against this plugin's, for the readiness check.
     *
     * Red when the engine plays a strategy this plugin has no key for (it could
     * not be chosen), when this plugin would offer one the engine does not
     * play (it could only fail), or when there is nothing to compare.
     *
     * @return array{ok: bool, engineversion: string, engine: array, unknown: int[], phantom: string[]}
     */
    public static function catalogue_check(): array {
        $engine = self::engine_strategies();
        $unknown = [];
        foreach ($engine as $id => $strategy) {
            if ($strategy['active'] && $strategy['key'] === null) {
                $unknown[] = $id;
            }
        }

        $offered = array_keys(\local_catquizlab\form\experiment_form::strategy_menu());
        $phantom = [];
        foreach ($offered as $key) {
            $id = self::engine_id($key);
            if (!isset($engine[$id]) || !$engine[$id]['active']) {
                $phantom[] = $key;
            }
        }

        return [
            'ok'            => $engine !== [] && $unknown === [] && $phantom === [],
            'engineversion' => self::engine_release(),
            'engine'        => array_values($engine),
            'unknown'       => $unknown,
            'phantom'       => $phantom,
        ];
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
