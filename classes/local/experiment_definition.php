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
 * a definition from JSON or an array, normalises it, validates it, and reports
 * every problem it finds rather than stopping at the first.
 *
 * Schema 2 closes the gap that made the definition decorative: a run's CAT
 * configuration, item parameters and pool mutation are now all stated here and
 * nowhere else. Concretely it adds
 *
 * - model parameters (the a and c distributions a 2PL/3PL run needs, the
 *   category structure a polytomous run needs),
 * - global and per-subscale item budgets as separate blocks,
 * - SE_min and SE_max as separate bounds instead of one setarget,
 * - a variant recipe, so a robustness condition carries its own parameters,
 * - severity and twin settings for the person strata.
 *
 * Definitions written against schema 1 keep validating: `setarget`, the flat
 * `minitems`/`maxitems` and the engine-side model names (`raschbirnbaum`) are
 * accepted as aliases and normalised on the way in. The normalised form keeps
 * the old keys mirrored, so callers that still read them keep working.
 *
 * It performs no side effects: no database writes, no provisioning. Those live
 * in the sweep expander (E1.2) and the provisioning step (E2).
 */
class experiment_definition {
    /** @var string The schema identifier written into exports. */
    public const SCHEMA = 'local_catquizlab/experiment';

    /** @var int The current schema version. */
    public const SCHEMAVERSION = 2;

    /** @var string[] Allowed experiment tiers. */
    public const TIERS = ['baseline', 'main', 'robustness', 'operational'];

    /** @var string[] Tiers whose runs are published and therefore need explicit parameters. */
    public const PUBLICATION_TIERS = ['main', 'robustness'];

    /** @var string[] Allowed pool variants. */
    public const VARIANTS = [
        'ideal', 'shifted', 'stretched', 'gappy',
        'calibrationerror', 'taggingerror', 'depleted', 'combined',
    ];

    /** @var string[] Allowed person strata. */
    public const STRATA = [
        'conforming', 'categoryvariation', 'subscalevariation', 'chaotic',
    ];

    /** @var string[] Allowed deviation severities. */
    public const SEVERITIES = ['none', 'mild', 'medium', 'strong'];

    /** @var array The definition as supplied. */
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
     * Allowed IRT models: the public keys plus the accepted legacy aliases.
     *
     * @return string[]
     */
    public static function models(): array {
        return model_catalog::accepted();
    }

    /**
     * Allowed CAT strategies.
     *
     * @return string[]
     */
    public static function strategies(): array {
        return strategy_catalog::keys();
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
     * Return the definition exactly as it was supplied.
     *
     * @return array
     */
    public function get_raw(): array {
        return $this->definition;
    }

    /**
     * Return the normalised definition (aliases resolved, defaults applied).
     *
     * @return array
     */
    public function get_normalised(): array {
        return self::apply_defaults($this->definition);
    }

    /**
     * Validate the definition.
     *
     * @return array{valid: bool, errors: string[], warnings: string[]} Validation outcome.
     */
    public function validate(): array {
        // Normalise aliases but do not invent required blocks: a definition
        // missing pool.scales has to be reported as missing, not quietly
        // completed and then declared valid.
        $def = self::normalise($this->definition, false);
        $errors = [];
        $warnings = [];

        self::require_nonempty_string($def, 'name', $errors);
        self::require_enum($def, 'tier', self::TIERS, $errors);
        self::require_positive_int($def, 'replications', $errors);
        self::require_int($def, 'seed', $errors);

        self::validate_schema($this->definition, $errors);
        self::validate_model($def, $errors, $warnings);
        self::validate_strategy($def, $errors);
        self::validate_pool($def, $errors);
        self::validate_persons($def, $errors);
        self::validate_budgets($def, $errors);

        // Courses and CAT tests are specifiable per run (2.6.C): at least one each.
        self::require_nonempty_list($def, 'courses', $errors);
        self::require_nonempty_list($def, 'tests', $errors);

        return ['valid' => count($errors) === 0, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Whether this definition describes a publication run, which may not fall
     * back on generic defaults for scientifically meaningful parameters.
     *
     * @param array $def A normalised definition.
     * @return bool
     */
    public static function is_publication(array $def): bool {
        if (isset($def['publication'])) {
            return (bool) $def['publication'];
        }
        return in_array($def['tier'] ?? '', self::PUBLICATION_TIERS, true);
    }

    /**
     * Reject a schema version this code does not understand.
     *
     * @param array $raw The raw definition.
     * @param string[] $errors Error accumulator (by reference).
     * @return void
     */
    protected static function validate_schema(array $raw, array &$errors): void {
        if (!isset($raw['schemaversion'])) {
            return;
        }
        $version = $raw['schemaversion'];
        if (!is_int($version) || $version < 1) {
            $errors[] = self::msg('def:positiveint', 'schemaversion');
            return;
        }
        if ($version > self::SCHEMAVERSION) {
            $errors[] = get_string('def:schematoonew', 'local_catquizlab', (object) [
                'found'    => $version,
                'expected' => self::SCHEMAVERSION,
            ]);
        }
    }

    /**
     * Validate the model choice and the parameters that follow from it.
     *
     * @param array $def The normalised definition.
     * @param string[] $errors Error accumulator (by reference).
     * @param string[] $warnings Warning accumulator (by reference).
     * @return void
     */
    protected static function validate_model(array $def, array &$errors, array &$warnings): void {
        $model = $def['model'] ?? null;
        if (!is_string($model) || !model_catalog::has($model)) {
            $errors[] = self::msg('def:enum', 'model: ' . implode('|', model_catalog::keys()));
            return;
        }
        $key = model_catalog::normalise($model);
        $params = (array) ($def['modelparams'] ?? []);
        $publication = self::is_publication($def);

        if (model_catalog::needs_discrimination($key)) {
            $errors = array_merge($errors, distribution::validate(
                $params['discrimination'] ?? null,
                'modelparams.discrimination'
            ));
            if (distribution::is_constant($params['discrimination'] ?? null)) {
                // A constant a is legitimate as a control condition, but for a
                // published 2PL run it is the very degeneracy the design tries
                // to avoid, so it has to be a deliberate, visible choice.
                $message = get_string('def:degeneratediscrimination', 'local_catquizlab', $key);
                if ($publication && empty($params['allowdegenerate'])) {
                    $errors[] = $message;
                } else {
                    $warnings[] = $message;
                }
            }
        }

        if (model_catalog::needs_guessing($key)) {
            $errors = array_merge($errors, distribution::validate(
                $params['guessing'] ?? null,
                'modelparams.guessing'
            ));
        }

        if (model_catalog::is_polytomous($key)) {
            $categories = $params['categories'] ?? null;
            if (!is_int($categories) || $categories < 2) {
                $errors[] = self::msg('def:polytomouscategories', 'modelparams.categories');
            }
            if (isset($params['stepspacing'])) {
                $errors = array_merge($errors, distribution::validate(
                    $params['stepspacing'],
                    'modelparams.stepspacing'
                ));
            }
            $template = $def['pool']['questiontemplate']['type'] ?? null;
            if ($template === 'truefalse') {
                $errors[] = self::msg('def:incompatibletemplate', $key . '/' . $template);
            }
        }
    }

    /**
     * Validate the strategy choice against the catalogue.
     *
     * @param array $def The normalised definition.
     * @param string[] $errors Error accumulator (by reference).
     * @return void
     */
    protected static function validate_strategy(array $def, array &$errors): void {
        self::require_enum($def, 'strategy', strategy_catalog::keys(), $errors);

        // A sweep may vary the strategy; every level has to be a known key too.
        $levels = $def['sweep']['factors']['strategy'] ?? null;
        if (is_array($levels)) {
            foreach ($levels as $level) {
                if (!is_string($level) || !strategy_catalog::has($level)) {
                    $errors[] = self::msg('def:enum', 'sweep.factors.strategy: '
                        . implode('|', strategy_catalog::keys()));
                    break;
                }
            }
        }
    }

    /**
     * Validate the pool block: variant, recipe, scales, template and naming.
     *
     * @param array $def The normalised definition.
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

        if (isset($pool['variant']) && is_string($pool['variant']) && in_array($pool['variant'], self::VARIANTS, true)) {
            $errors = array_merge($errors, pool_mutator::validate_recipe(
                $pool['variant'],
                (array) ($pool['recipe'] ?? []),
                self::is_publication($def)
            ));
        }
    }

    /**
     * Validate the persons block: stratum, severity, count and naming.
     *
     * @param array $def The normalised definition.
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
        self::require_enum($persons, 'severity', self::SEVERITIES, $errors, 'persons.severity');
        self::require_positive_int($persons, 'count', $errors, 'persons.count');
        self::require_naming($persons, 'naming', $errors, 'persons.naming');

        // The conforming stratum has no local deviation to scale, so a severity
        // there would be silently ignored — better to say so than to pretend.
        if (($persons['stratum'] ?? null) === 'conforming' && ($persons['severity'] ?? 'none') !== 'none') {
            $errors[] = self::msg('def:severitynotapplicable', 'persons.severity');
        }

        foreach (['mild', 'medium', 'strong'] as $level) {
            $scale = $persons['severityscale'][$level] ?? null;
            if ($scale !== null && (!is_numeric($scale) || (float) $scale < 0)) {
                $errors[] = self::msg('def:negative', 'persons.severityscale.' . $level);
            }
        }
    }

    /**
     * Validate the budgets block: item windows and precision bounds.
     *
     * @param array $def The normalised definition.
     * @param string[] $errors Error accumulator (by reference).
     * @return void
     */
    protected static function validate_budgets(array $def, array &$errors): void {
        if (!isset($def['budgets']) || !is_array($def['budgets'])) {
            $errors[] = self::msg('def:missingblock', 'budgets');
            return;
        }
        $budgets = $def['budgets'];

        foreach (['global', 'subscale'] as $level) {
            $block = $budgets[$level] ?? null;
            if (!is_array($block)) {
                $errors[] = self::msg('def:missingblock', 'budgets.' . $level);
                continue;
            }
            self::require_positive_int($block, 'minitems', $errors, 'budgets.' . $level . '.minitems');
            self::require_positive_int($block, 'maxitems', $errors, 'budgets.' . $level . '.maxitems');
            if (
                isset($block['minitems'], $block['maxitems'])
                    && is_numeric($block['minitems']) && is_numeric($block['maxitems'])
                    && (int) $block['minitems'] > (int) $block['maxitems']
            ) {
                $errors[] = self::msg('def:mingtmax', 'budgets.' . $level);
            }
        }

        $se = $budgets['se'] ?? null;
        if (!is_array($se)) {
            $errors[] = self::msg('def:missingblock', 'budgets.se');
            return;
        }
        foreach (['min', 'max'] as $bound) {
            if (!isset($se[$bound]) || !is_numeric($se[$bound])) {
                $errors[] = self::msg('def:numeric', 'budgets.se.' . $bound);
            } else if ((float) $se[$bound] <= 0.0) {
                $errors[] = self::msg('def:positivefloat', 'budgets.se.' . $bound);
            }
        }
        if (
            isset($se['min'], $se['max']) && is_numeric($se['min']) && is_numeric($se['max'])
                && (float) $se['min'] > (float) $se['max']
        ) {
            $errors[] = self::msg('def:mingtmax', 'budgets.se');
        }

        // A definition may still carry the flat schema-1 keys. They are part of
        // the author's intent, so they are checked too, and a value that
        // contradicts the split form is an error rather than a silent loser.
        $raw = $budgets;
        if (
            isset($raw['minitems'], $raw['maxitems'])
                && is_numeric($raw['minitems']) && is_numeric($raw['maxitems'])
                && (int) $raw['minitems'] > (int) $raw['maxitems']
        ) {
            $errors[] = self::msg('def:mingtmax', 'budgets');
        }
        foreach (['minitems', 'maxitems'] as $key) {
            $flat = $raw[$key] ?? null;
            $split = $raw['global'][$key] ?? null;
            if ($flat !== null && $split !== null && (int) $flat !== (int) $split) {
                $errors[] = self::msg('def:budgetconflict', 'budgets.' . $key);
            }
        }

        if (self::is_publication($def) && !empty($budgets['fromlegacy'])) {
            $errors[] = self::msg('def:legacybudgets', 'budgets');
        }
    }

    /**
     * Apply defaults and resolve legacy aliases without validating.
     *
     * @param array $def Raw definition.
     * @return array Normalised definition.
     */
    public static function apply_defaults(array $def): array {
        return self::normalise($def, true);
    }

    /**
     * Normalise a definition.
     *
     * @param array $def Raw definition.
     * @param bool $fillrequired Whether to supply defaults for blocks the author must state.
     * @return array
     */
    protected static function normalise(array $def, bool $fillrequired): array {
        $def = self::normalise_model($def);

        $def += [
            'schema'        => self::SCHEMA,
            'schemaversion' => self::SCHEMAVERSION,
            'tier'          => 'baseline',
            'strategy'      => 'fastest',
            'replications'  => 1,
            'seed'          => 42,
            // Study metadata. They carry no computational meaning, but a
            // published experiment has to be citable, and "the third one in the
            // list" is not a citation.
            'description'   => '',
            'experimentkey' => '',
            'version'       => '1.0.0',
            'tags'          => [],
            'enabled'       => true,
        ];
        $def['tags'] = array_values(array_filter(array_map('strval', (array) $def['tags'])));
        $def['publication'] = self::is_publication($def);

        if (is_array($def['pool'] ?? null) || $fillrequired) {
            $def['pool'] = ((array) ($def['pool'] ?? [])) + [
                'variant' => 'ideal',
                'recipe'  => [],
            ];
            $def['pool']['recipe'] = (array) $def['pool']['recipe'];
            if ($fillrequired || is_array($def['pool']['scales'] ?? null)) {
                $def['pool']['scales'] = ((array) ($def['pool']['scales'] ?? [])) + [
                    'categories'       => 10,
                    'subcategories'    => 10,
                    'itemspersubscale' => 25,
                ];
            }
        }

        if (is_array($def['persons'] ?? null) || $fillrequired) {
            $def['persons'] = ((array) ($def['persons'] ?? [])) + [
                'severity' => 'none',
            ];
            if ($fillrequired) {
                $def['persons'] += ['stratum' => 'conforming', 'count' => 1];
            }
        }
        if (!is_array($def['persons'] ?? null)) {
            return self::finish_normalisation($def, $fillrequired);
        }
        $def['persons']['twins'] = ((array) ($def['persons']['twins'] ?? [])) + [
            'enabled' => true,
        ];
        $def['persons']['severityscale'] = ((array) ($def['persons']['severityscale'] ?? [])) + [
            'mild'   => 0.5,
            'medium' => 1.0,
            'strong' => 2.0,
        ];

        return self::finish_normalisation($def, $fillrequired);
    }

    /**
     * Finish normalisation: budgets and timing, whatever the persons block looks like.
     *
     * @param array $def The partly normalised definition.
     * @param bool $fillrequired Whether to supply defaults for blocks the author must state.
     * @return array
     */
    protected static function finish_normalisation(array $def, bool $fillrequired): array {
        if (is_array($def['budgets'] ?? null) || $fillrequired) {
            $def['budgets'] = self::normalise_budgets((array) ($def['budgets'] ?? []), $fillrequired);
        }

        $def['timing'] = ((array) ($def['timing'] ?? [])) + [
            'spacingseconds' => 0,
            'faildelay'      => 60,
        ];

        return $def;
    }

    /**
     * Resolve the model name to its public key and fill the parameters the
     * model requires.
     *
     * @param array $def Raw definition.
     * @return array
     */
    protected static function normalise_model(array $def): array {
        // The model may be given as a plain string or as a block carrying its
        // parameters; both collapse to a public key plus a modelparams block.
        $params = (array) ($def['modelparams'] ?? []);
        $model = $def['model'] ?? 'raschbirnbaum';
        if (is_array($model)) {
            $params = array_merge($model, $params);
            unset($params['type']);
            $model = (string) (($def['model']['type']) ?? 'raschbirnbaum');
        }
        $model = is_string($model) ? $model : 'raschbirnbaum';

        $key = model_catalog::normalise($model);
        $def['model'] = $key ?? $model;
        if ($key === null) {
            $def['modelparams'] = $params;
            return $def;
        }

        // Defaults are deliberately degenerate rather than invented: a=1, c=0
        // reproduce the previous behaviour exactly, and a publication run has
        // to state something better (see validate_model()).
        if (model_catalog::needs_discrimination($key) && !isset($params['discrimination'])) {
            $params['discrimination'] = distribution::constant(1.0);
        }
        if (model_catalog::needs_guessing($key) && !isset($params['guessing'])) {
            $params['guessing'] = distribution::constant(0.0);
        }
        if (model_catalog::is_polytomous($key)) {
            $params += ['categories' => 4, 'stepspacing' => distribution::constant(1.0)];
        }
        $def['modelparams'] = $params;
        $def['enginemodel'] = model_catalog::engine_key($key);

        return $def;
    }

    /**
     * Normalise the budgets block, promoting schema-1 keys into the split form.
     *
     * @param array $budgets The raw budgets block.
     * @param bool $fillrequired Whether to supply defaults for values the author must state.
     * @return array
     */
    protected static function normalise_budgets(array $budgets, bool $fillrequired = true): array {
        $fromlegacy = false;

        $global = (array) ($budgets['global'] ?? []);
        if (!isset($global['minitems']) && isset($budgets['minitems'])) {
            $global['minitems'] = $budgets['minitems'];
            $fromlegacy = true;
        }
        if (!isset($global['maxitems']) && isset($budgets['maxitems'])) {
            $global['maxitems'] = $budgets['maxitems'];
            $fromlegacy = true;
        }
        if ($fillrequired) {
            $global += ['minitems' => 1, 'maxitems' => 250];
        }

        $subscale = (array) ($budgets['subscale'] ?? []);
        $subscale += ['minitems' => 3, 'maxitems' => 4];

        $se = (array) ($budgets['se'] ?? []);
        if (!isset($se['min']) && isset($budgets['setarget'])) {
            // The setarget key was the single precision target of schema 1. It is the
            // lower bound: the point at which the test may stop.
            $se['min'] = $budgets['setarget'];
            $fromlegacy = true;
        }
        $se += ['min' => 0.35, 'max' => 1.0];

        return $budgets + [
            'global'     => $global,
            'subscale'   => $subscale,
            'se'         => $se,
            // Mirrors, so schema-1 readers keep working.
            'minitems'   => $global['minitems'] ?? null,
            'maxitems'   => $global['maxitems'] ?? null,
            'setarget'   => $se['min'],
            'fromlegacy' => $fromlegacy,
        ];
    }

    /**
     * A minimal valid baseline definition, useful as a template and in tests.
     *
     * @return array
     */
    public static function example_baseline(): array {
        return [
            'schema'        => self::SCHEMA,
            'schemaversion' => self::SCHEMAVERSION,
            'name'          => 'Baseline — ideal pool',
            'tier'          => 'baseline',
            'model'         => '2pl',
            'modelparams'   => [
                'discrimination' => ['dist' => 'constant', 'value' => 1.0],
            ],
            'strategy'      => 'classic',
            'replications'  => 1,
            'seed'          => 42,
            'pool'          => [
                'variant'          => 'ideal',
                'recipe'           => [],
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
            'persons'       => [
                'stratum'  => 'conforming',
                'severity' => 'none',
                'count'    => 50,
                'naming'   => ['pattern' => 'P-{stratum}-{index:04d}'],
            ],
            'budgets'       => [
                'global'   => ['minitems' => 10, 'maxitems' => 250],
                'subscale' => ['minitems' => 3, 'maxitems' => 4],
                'se'       => ['min' => 0.35, 'max' => 1.0],
            ],
            'timing'        => [
                'spacingseconds' => 0,
                'faildelay'      => 60,
            ],
            'courses'       => [
                ['shortname' => 'catlab-baseline', 'reference' => null],
            ],
            'tests'         => [
                ['name' => 'Baseline CAT', 'reference' => null],
            ],
        ];
    }

    /**
     * A 2PL publication example with an explicit discrimination distribution.
     *
     * @return array
     */
    public static function example_publication_2pl(): array {
        $def = self::example_baseline();
        $def['name'] = 'Article main simulation (2PL)';
        $def['tier'] = 'main';
        $def['strategy'] = 'lowestsub';
        $def['replications'] = 100;
        $def['modelparams'] = self::study_item_parameters();
        $def['budgets'] = [
            'global'   => ['minitems' => 20, 'maxitems' => 25],
            'subscale' => ['minitems' => 3, 'maxitems' => 5],
            'se'       => ['min' => 0.35, 'max' => 0.75],
        ];
        return $def;
    }

    /**
     * The item-parameter distributions the study uses.
     *
     * Discrimination: 0 < a <= 5 with its most likely value at 2. A lognormal
     * places the mode at exp(meanlog - sdlog^2), so meanlog is set from the
     * requested mode rather than from a mean — the two differ for a skewed
     * distribution, and the design states the mode.
     *
     * Guessing: 0 < c < 0.5 with its most likely value at 0.25. That is a
     * bounded quantity with an interior mode, which a normal or a lognormal
     * cannot express; a symmetric beta on the interval puts the mode in the
     * middle by construction, so no clamping is needed and no probability
     * piles up at either end.
     *
     * @return array The model parameters.
     */
    public static function study_item_parameters(): array {
        return [
            'discrimination' => [
                // The study declares 0 < a <= 5 with the mode at 2. A beta
                // distribution honours both: it cannot leave its range, and
                // Beta(3, 4) on (0, 5] puts the mode exactly at 2, since a
                // beta's mode is min + (alpha-1)/(alpha+beta-2) * (max-min).
                //
                // A lognormal with the same mode was tried first and rejected
                // by measurement: with the range enforced by a clamp, 9.4% of
                // 20,000 draws landed on exactly 5.0 and the modal bin was the
                // top one. A clamp that catches a tenth of the draws is not a
                // guard, it is the shape — and it would have given a tenth of
                // the pool an identical, maximal discrimination.
                'dist'  => 'beta',
                'min'   => 0.0,
                'max'   => 5.0,
                'alpha' => 3.0,
                'beta'  => 4.0,
            ],
            'guessing' => [
                'dist'  => 'beta',
                'min'   => 0.0,
                'max'   => 0.5,
                // Symmetric shapes put the mode at the midpoint, 0.25.
                'alpha' => 2.0,
                'beta'  => 2.0,
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
