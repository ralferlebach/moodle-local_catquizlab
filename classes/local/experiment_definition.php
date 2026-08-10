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
 * Declarative experiment definition and its validator.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * A declarative experiment definition (E1.1): the JSON an experiment is written
 * as, before a sweep expands it into concrete runs.
 *
 * This class is the single source of truth for the definition format. It parses
 * a definition from JSON or an array, validates it (structure, enumerations,
 * ranges, and the requirements fixed in architektur.md 2.6 — item variants via
 * scales, persons as users, specifiable courses/tests, naming rules and
 * question templates), fills defaults, and reports every problem it finds
 * rather than stopping at the first.
 *
 * It performs no side effects: no database writes, no provisioning. Those live
 * in the sweep expander (E1.2) and the provisioning step (E2).
 */
class experiment_definition {
    /** @var string[] Allowed experiment tiers. */
    public const TIERS = ['baseline', 'main', 'robustness', 'operational'];

    /** @var string[] Allowed IRT models (dichotomous now; polytomous later). */
    public const MODELS = ['raschbirnbaum', 'rasch', '2pl', '3pl'];

    /** @var string[] Allowed pool variants. */
    public const VARIANTS = [
        'ideal', 'shifted', 'stretched', 'gappy',
        'calibrationerror', 'taggingerror', 'depleted', 'combined',
    ];

    /** @var string[] Allowed person strata. */
    public const STRATA = [
        'conforming', 'categoryvariation', 'subscalevariation', 'chaotic',
    ];

    /** @var string[] Allowed CAT strategies (mirrors the engine's strategies). */
    public const STRATEGIES = [
        'fastest', 'balanced', 'allsubs', 'lowestsub',
        'highestsub', 'pilot', 'classic', 'relsubs',
    ];

    /** @var array The normalised definition. */
    protected array $definition;

    /**
     * Construct from an already-decoded definition array.
     *
     * @param array $definition Raw definition data.
     */
    public function __construct(array $definition) {
        $this->definition = $definition;
    }

    /**
     * Build an instance from a JSON string.
     *
     * @param string $json JSON-encoded definition.
     * @return self
     * @throws \invalid_parameter_exception If the JSON cannot be decoded into an object/array.
     */
    public static function from_json(string $json): self {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \invalid_parameter_exception(get_string('def:notjson', 'local_catquizlab'));
        }
        return new self($decoded);
    }

    /**
     * Return the normalised definition (defaults applied).
     *
     * @return array
     */
    public function get_normalised(): array {
        return self::apply_defaults($this->definition);
    }

    /**
     * Validate the definition.
     *
     * @return array{valid: bool, errors: string[]} Validation outcome; errors are human-readable keys/messages.
     */
    public function validate(): array {
        $def = $this->definition;
        $errors = [];

        // Top-level required scalars.
        self::require_nonempty_string($def, 'name', $errors);
        self::require_enum($def, 'tier', self::TIERS, $errors);
        self::require_enum($def, 'model', self::MODELS, $errors);
        self::require_enum($def, 'strategy', self::STRATEGIES, $errors);
        self::require_positive_int($def, 'replications', $errors);
        self::require_int($def, 'seed', $errors);

        self::validate_pool($def, $errors);
        self::validate_persons($def, $errors);
        self::validate_budgets($def, $errors);

        // Courses and CAT tests are specifiable per run (2.6.C): at least one each.
        self::require_nonempty_list($def, 'courses', $errors);
        self::require_nonempty_list($def, 'tests', $errors);

        return ['valid' => count($errors) === 0, 'errors' => $errors];
    }

    /**
     * Validate the pool block: variant via scales (2.6.A), template and item naming (2.6.D).
     *
     * @param array $def The full definition.
     * @param string[] $errors Error accumulator (by reference).
     * @return void
     */
    protected static function validate_pool(array $def, array &$errors): void {
        if (!isset($def['pool']) || !is_array($def['pool'])) {
            $errors[] = self::msg('def:missingblock', 'pool');
            return;
        }
        $pool = $def['pool'];
        self::require_enum($pool, 'variant', self::VARIANTS, $errors, 'pool.variant');

        if (isset($pool['scales']) && is_array($pool['scales'])) {
            self::require_positive_int($pool['scales'], 'categories', $errors, 'pool.scales.categories');
            self::require_positive_int($pool['scales'], 'subcategories', $errors, 'pool.scales.subcategories');
            self::require_positive_int($pool['scales'], 'itemspersubscale', $errors, 'pool.scales.itemspersubscale');
        } else {
            $errors[] = self::msg('def:missingblock', 'pool.scales');
        }

        if (!isset($pool['questiontemplate']) || !is_array($pool['questiontemplate'])) {
            $errors[] = self::msg('def:missingblock', 'pool.questiontemplate');
        } else {
            self::require_nonempty_string($pool['questiontemplate'], 'type', $errors, 'pool.questiontemplate.type');
        }

        self::require_naming($pool, 'itemnaming', $errors, 'pool.itemnaming');
    }

    /**
     * Validate the persons block: each person becomes its own Moodle user (2.6.B).
     *
     * @param array $def The full definition.
     * @param string[] $errors Error accumulator (by reference).
     * @return void
     */
    protected static function validate_persons(array $def, array &$errors): void {
        if (!isset($def['persons']) || !is_array($def['persons'])) {
            $errors[] = self::msg('def:missingblock', 'persons');
            return;
        }
        $persons = $def['persons'];
        self::require_enum($persons, 'stratum', self::STRATA, $errors, 'persons.stratum');
        self::require_positive_int($persons, 'count', $errors, 'persons.count');
        self::require_naming($persons, 'naming', $errors, 'persons.naming');
    }

    /**
     * Validate the budgets block: a valid, non-degenerate item window.
     *
     * @param array $def The full definition.
     * @param string[] $errors Error accumulator (by reference).
     * @return void
     */
    protected static function validate_budgets(array $def, array &$errors): void {
        if (!isset($def['budgets']) || !is_array($def['budgets'])) {
            $errors[] = self::msg('def:missingblock', 'budgets');
            return;
        }
        $budgets = $def['budgets'];
        self::require_positive_int($budgets, 'minitems', $errors, 'budgets.minitems');
        self::require_positive_int($budgets, 'maxitems', $errors, 'budgets.maxitems');

        if (
            isset($budgets['minitems'], $budgets['maxitems'])
                && is_numeric($budgets['minitems']) && is_numeric($budgets['maxitems'])
                && (int) $budgets['minitems'] > (int) $budgets['maxitems']
        ) {
            $errors[] = self::msg('def:mingtmax', 'budgets');
        }
    }

    /**
     * Apply defaults to a definition without validating it.
     *
     * @param array $def Raw definition.
     * @return array Definition with defaults filled in.
     */
    public static function apply_defaults(array $def): array {
        $def += [
            'tier'         => 'baseline',
            'model'        => 'raschbirnbaum',
            'strategy'     => 'fastest',
            'replications' => 1,
            'seed'         => 42,
        ];
        $def['pool'] = ($def['pool'] ?? []) + [
            'variant' => 'ideal',
        ];
        $def['pool']['scales'] = ($def['pool']['scales'] ?? []) + [
            'categories'       => 10,
            'subcategories'    => 10,
            'itemspersubscale' => 25,
        ];
        $def['persons'] = ($def['persons'] ?? []) + [
            'stratum' => 'conforming',
            'count'   => 1,
        ];
        $def['budgets'] = ($def['budgets'] ?? []) + [
            'minitems' => 1,
            'maxitems' => 250,
            'setarget' => 0.35,
        ];
        $def['timing'] = ($def['timing'] ?? []) + [
            'spacingseconds' => 0,
            'faildelay'      => 60,
        ];
        return $def;
    }

    /**
     * A minimal valid baseline definition, useful as a template and in tests.
     *
     * @return array
     */
    public static function example_baseline(): array {
        return [
            'name'         => 'Baseline — ideal pool',
            'tier'         => 'baseline',
            'model'        => 'raschbirnbaum',
            'strategy'     => 'classic',
            'replications' => 1,
            'seed'         => 42,
            'pool'         => [
                'variant'          => 'ideal',
                'scales'           => [
                    'categories'       => 10,
                    'subcategories'    => 10,
                    'itemspersubscale' => 25,
                ],
                'questiontemplate' => [
                    'type'   => 'multichoice',
                    'blanks' => ['stem' => '{category}/{subscale} item {index}'],
                ],
                'itemnaming'       => ['pattern' => 'Q-{category}-{subscale}-{index:03d}'],
            ],
            'persons'      => [
                'stratum' => 'conforming',
                'count'   => 50,
                'naming'  => ['pattern' => 'P-{stratum}-{index:04d}'],
            ],
            'budgets'      => [
                'minitems' => 10,
                'maxitems' => 250,
                'setarget' => 0.35,
            ],
            'timing'       => [
                'spacingseconds' => 0,
                'faildelay'      => 60,
            ],
            'courses'      => [
                ['shortname' => 'catlab-baseline', 'reference' => null],
            ],
            'tests'        => [
                ['name' => 'Baseline CAT', 'reference' => null],
            ],
        ];
    }

    /**
     * Require a key to be a non-empty string.
     *
     * @param array $data Data to inspect.
     * @param string $key Key to check.
     * @param string[] $errors Error accumulator (by reference).
     * @param string|null $label Optional dotted label for messages.
     * @return void
     */
    protected static function require_nonempty_string(array $data, string $key, array &$errors, ?string $label = null): void {
        $label = $label ?? $key;
        if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
            $errors[] = self::msg('def:required', $label);
        }
    }

    /**
     * Require a key to be an integer.
     *
     * @param array $data Data to inspect.
     * @param string $key Key to check.
     * @param string[] $errors Error accumulator (by reference).
     * @param string|null $label Optional dotted label for messages.
     * @return void
     */
    protected static function require_int(array $data, string $key, array &$errors, ?string $label = null): void {
        $label = $label ?? $key;
        if (!isset($data[$key]) || !is_int($data[$key])) {
            $errors[] = self::msg('def:integer', $label);
        }
    }

    /**
     * Require a key to be a positive integer.
     *
     * @param array $data Data to inspect.
     * @param string $key Key to check.
     * @param string[] $errors Error accumulator (by reference).
     * @param string|null $label Optional dotted label for messages.
     * @return void
     */
    protected static function require_positive_int(array $data, string $key, array &$errors, ?string $label = null): void {
        $label = $label ?? $key;
        if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key] < 1) {
            $errors[] = self::msg('def:positiveint', $label);
        }
    }

    /**
     * Require a key to hold one of an allowed set of string values.
     *
     * @param array $data Data to inspect.
     * @param string $key Key to check.
     * @param string[] $allowed Allowed values.
     * @param string[] $errors Error accumulator (by reference).
     * @param string|null $label Optional dotted label for messages.
     * @return void
     */
    protected static function require_enum(array $data, string $key, array $allowed, array &$errors, ?string $label = null): void {
        $label = $label ?? $key;
        if (!isset($data[$key]) || !in_array($data[$key], $allowed, true)) {
            $errors[] = self::msg('def:enum', $label . ': ' . implode('|', $allowed));
        }
    }

    /**
     * Require a key to hold a non-empty list (indexed array).
     *
     * @param array $data Data to inspect.
     * @param string $key Key to check.
     * @param string[] $errors Error accumulator (by reference).
     * @param string|null $label Optional dotted label for messages.
     * @return void
     */
    protected static function require_nonempty_list(array $data, string $key, array &$errors, ?string $label = null): void {
        $label = $label ?? $key;
        if (!isset($data[$key]) || !is_array($data[$key]) || $data[$key] === []) {
            $errors[] = self::msg('def:nonemptylist', $label);
        }
    }

    /**
     * Require a naming block to exist and carry a non-empty pattern.
     *
     * @param array $data Parent data holding the naming block.
     * @param string $key Naming block key.
     * @param string[] $errors Error accumulator (by reference).
     * @param string $label Dotted label for messages.
     * @return void
     */
    protected static function require_naming(array $data, string $key, array &$errors, string $label): void {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $errors[] = self::msg('def:missingblock', $label);
            return;
        }
        self::require_nonempty_string($data[$key], 'pattern', $errors, $label . '.pattern');
    }

    /**
     * Format a validation message from a language string and a field label.
     *
     * @param string $stringkey Language string key.
     * @param string $label Field label inserted into the message.
     * @return string
     */
    protected static function msg(string $stringkey, string $label): string {
        return get_string($stringkey, 'local_catquizlab', $label);
    }
}
