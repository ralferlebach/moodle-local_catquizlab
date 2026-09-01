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
 * Run orchestrator: set up a full run end to end.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Chains the building blocks to set up a run end to end (E7).
 *
 * {@see self::plan_stages()} names the ordered pipeline (pure). {@see self::setup()}
 * runs it for a run: materialise the scale tree, then the questions and items, then
 * create and bind the CAT test, then the persons/users/course/enrolment, then queue
 * the attempts — advancing the run's status to scheduled. Every stage delegates to a
 * building block that guards the engine, so without the engine setup reports each
 * stage as skipped rather than failing (CI and stand-alone stay green). The actual
 * playing, collection and aggregation happen asynchronously via the worker and tasks.
 */
class run_orchestrator {
    /** @var string A mandatory stage returned nothing. */
    public const REASON_NO_RESULT = 'stage-returned-no-result';

    /** @var string The run has no root scale to build a test on. */
    public const REASON_NO_ROOT_SCALE = 'no-root-scale';

    /** @var string The adaptivequiz activity could not be created. */
    public const REASON_NO_TEST = 'test-not-created';

    /** @var string The effective configuration differs from the manifest. */
    public const REASON_MANIFEST_DRIFT = 'manifest-configuration-drift';

    /** @var string The realised pool is smaller than the test's own minimum. */
    public const REASON_POOL_TOO_SMALL = 'pool-smaller-than-minimum-questions';

    /** @var string Materialise the engine scale tree and context. */
    public const STAGE_SCALES = 'scales';

    /** @var string Materialise questions and register items. */
    public const STAGE_MATERIALISE = 'materialise';

    /** @var string Create and bind the adaptivequiz CAT test. */
    public const STAGE_TEST = 'test';

    /** @var string Generate persons and provision users, course and enrolment. */
    public const STAGE_PEOPLE = 'people';

    /** @var string Queue the simulated attempts. */
    public const STAGE_ATTEMPTS = 'attempts';

    /** @var string Resolving the shared course and the experiment's section. */
    public const STAGE_CONTAINER = 'container';

    /**
     * The ordered setup pipeline.
     *
     * @return string[]
     */
    public static function plan_stages(): array {
        // The container comes before the test, because an adaptivequiz needs a
        // course and a section to be created in. The old order put the test
        // stage first, where the run had no course yet, so test_provisioner
        // returned null every time and the run was still reported as ok.
        return [
            self::STAGE_SCALES,
            self::STAGE_MATERIALISE,
            self::STAGE_CONTAINER,
            self::STAGE_PEOPLE,
            self::STAGE_TEST,
            self::STAGE_ATTEMPTS,
        ];
    }

    /**
     * Set up a run end to end.
     *
     * @param int $runid The run to set up.
     * @param array $options 'questioncategoryid' for materialisation, 'template'. Polytomy follows the model.
     * @return array{ok: bool, reason?: string, stages: array<string, mixed>}
     */
    public static function setup(int $runid, array $options = []): array {
        global $DB;

        if (!environment::engine_available()) {
            return ['ok' => false, 'reason' => 'engine-unavailable', 'stages' => []];
        }

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
        $definition = self::definition_for($run);

        $drift = self::manifest_drift($run, $definition);
        if ($drift !== []) {
            $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FAILED, ['id' => $runid]);

            return [
                'ok'          => false,
                'reason'      => self::REASON_MANIFEST_DRIFT . ' (' . implode('; ', $drift) . ')',
                'failedstage' => null,
                'stages'      => [],
            ];
        }
        $seed = (int) $run->seed;
        $context = [
            'runid'      => $runid,
            'run'        => $run,
            'definition' => $definition,
            'seed'       => $seed,
            'seeds'      => self::seeds_for($run, $definition),
            'options'    => $options,
        ];

        // Stages stop at the first failure. Carrying on would build a test in a
        // section that does not exist, or enrol users into a course that was
        // never resolved, and bury the real cause under the consequences.
        $stages = [];
        $failedstage = null;
        foreach (self::plan_stages() as $stage) {
            $stages[$stage] = self::run_stage($stage, $context);

            if (self::stage_failed($stage, $stages[$stage])) {
                $failedstage = $stage;
                break;
            }

            // The pool exists now, so the one thing that can still doom the run
            // arithmetically is knowable: a minimum the pool cannot serve.
            if ($stage === self::STAGE_MATERIALISE && is_array($stages[$stage])) {
                $infeasible = self::budget_feasible(
                    $definition,
                    (int) ($stages[$stage]['enginevisible'] ?? 0)
                );
                if ($infeasible !== null) {
                    $stages[$stage]['failed'] = true;
                    $stages[$stage]['reason'] = $infeasible;
                    $failedstage = $stage;
                    break;
                }
            }
        }

        if ($failedstage !== null) {
            $reason = self::stage_reason($failedstage, $stages[$failedstage]);
            $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_FAILED, ['id' => $runid]);

            return [
                'ok'          => false,
                'reason'      => $reason,
                'failedstage' => $failedstage,
                'stages'      => $stages,
            ];
        }

        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_SCHEDULED, ['id' => $runid]);

        \local_catquizlab\event\run_scheduled::create([
            'objectid' => $runid,
            'context'  => \context_system::instance(),
        ])->trigger();

        return ['ok' => true, 'stages' => $stages];
    }

    /**
     * Resolve the shared course and the experiment's section.
     *
     * @param array $context The run context.
     * @return array The container outcome.
     */
    protected static function stage_container(array $context): array {
        $experimentid = (int) ($context['run']->experimentid ?? 0);
        $outcome = experiment_container::provision($experimentid);

        if (!empty($outcome['ok'])) {
            // Every run of an experiment shares its course, which is what makes
            // one section per experiment possible in the first place.
            global $DB;
            $DB->set_field(
                'local_catquizlab_run',
                'courseid',
                (int) $outcome['courseid'],
                ['id' => (int) $context['runid']]
            );
        }

        return $outcome + ['failed' => empty($outcome['ok'])];
    }

    /**
     * The derived seeds of a run, one per random source.
     *
     * @param \stdClass $run The run record.
     * @param array $definition The normalised definition.
     * @return array{master: int, person: int, deviation: int, pool: int, mutation: int, manifest: array}
     */
    protected static function seeds_for(\stdClass $run, array $definition): array {
        $master = (int) ($run->masterseed ?? 0);
        if ($master === 0) {
            $master = (int) ($definition['seed'] ?? $run->seed);
        }
        $replication = (int) ($run->replication ?? 1);
        $stratum = (string) ($definition['persons']['stratum'] ?? 'conforming');
        $severity = (string) ($definition['persons']['severity'] ?? 'none');
        $variant = (string) ($definition['pool']['variant'] ?? 'ideal');
        $poolcondition = (string) ($definition['model'] ?? '2pl');

        return [
            'master'    => $master,
            'person'    => seed_domains::person_base($master, $replication),
            'deviation' => seed_domains::person_deviation($master, $replication, $stratum, $severity),
            'pool'      => seed_domains::pool($master, $replication, $poolcondition),
            'mutation'  => seed_domains::mutation($master, $replication, $variant, $poolcondition),
            'manifest'  => seed_domains::manifest_block(
                $master,
                $replication,
                $stratum,
                $severity,
                $variant,
                $poolcondition
            ),
        ];
    }

    /**
     * Whether a stage result means the stage did not do its job.
     *
     * @param string $stage The stage name.
     * @param mixed $result What the stage returned.
     * @return bool
     */
    public static function stage_failed(string $stage, $result): bool {
        if ($result === null || $result === false) {
            return true;
        }
        if (is_array($result) && !empty($result['failed'])) {
            return true;
        }
        if ($stage === self::STAGE_MATERIALISE && is_array($result)) {
            return !self::materialisation_complete($result);
        }
        if ($stage === self::STAGE_TEST && is_array($result)) {
            // No activity, no run: this is the condition the whole issue is
            // about, and it must not survive as a scheduled run.
            return (int) ($result['testcmid'] ?? 0) <= 0;
        }

        return false;
    }

    /**
     * The reason a stage gives for its failure.
     *
     * @param string $stage The stage name.
     * @param mixed $result What the stage returned.
     * @return string A reason of the form "stage:name (detail)".
     */
    protected static function stage_reason(string $stage, $result): string {
        if ($result === null || $result === false) {
            return 'stage:' . $stage . ' (' . self::REASON_NO_RESULT . ')';
        }
        $reason = is_array($result) ? (string) ($result['reason'] ?? '') : '';
        if ($reason === '' && $stage === self::STAGE_MATERIALISE) {
            $reason = cat_item_provisioner::REASON_NOT_VISIBLE;
        }
        if ($reason === '' && $stage === self::STAGE_TEST) {
            $reason = self::REASON_NO_TEST;
        }

        return 'stage:' . $stage . ($reason !== '' ? ' (' . $reason . ')' : '');
    }

    /**
     * The first stage that did not meet its postconditions.
     *
     * A stage counts as failed when it says so, but also when it returns
     * nothing at all. A null return used to pass as success, so a stage that
     * bailed out early — no question category, no scale map — left the run
     * scheduled with no pool behind it.
     *
     * @param array $stages The stage results.
     * @return string|null A reason of the form "stage:name (detail)", or null when all stages hold.
     */
    protected static function first_failure(array $stages): ?string {
        foreach (self::plan_stages() as $name) {
            $result = $stages[$name] ?? null;

            if ($result === null || $result === false) {
                return 'stage:' . $name . ' (' . self::REASON_NO_RESULT . ')';
            }
            if (is_array($result) && !empty($result['failed'])) {
                $reason = (string) ($result['reason'] ?? '');

                return 'stage:' . $name . ($reason !== '' ? ' (' . $reason . ')' : '');
            }
            if ($name === 'materialise' && is_array($result) && !self::materialisation_complete($result)) {
                return 'stage:materialise (' . cat_item_provisioner::REASON_NOT_VISIBLE . ')';
            }
        }

        return null;
    }

    /**
     * Whether a materialisation result meets every postcondition of the stage.
     *
     * A run may only be scheduled when every planned item exists as a question,
     * is registered with the engine, carries active parameters and can be
     * retrieved through the engine's own path. Anything less is a pool the test
     * cannot actually be played from.
     *
     * @param array $result The materialisation stage result.
     * @return bool
     */
    /**
     * Whether the realised pool can satisfy the run's own item budget.
     *
     * A test whose minimum exceeds the number of items it can be given has no
     * way to finish: the engine runs out and reports an error, and the run
     * produces a trace of one item and a stop reason that blames the strategy.
     * The arithmetic is knowable before anything is played, so it is checked
     * here rather than discovered afterwards.
     *
     * @param array $definition The run's normalised definition.
     * @param int $available How many items the engine can retrieve for the run.
     * @return string|null A reason when the budget cannot be met, null otherwise.
     */
    public static function budget_feasible(array $definition, int $available): ?string {
        $minimum = (int) ($definition['budgets']['global']['minitems'] ?? 0);
        if ($minimum <= 0 || $available <= 0) {
            return null;
        }
        if ($available < $minimum) {
            return self::REASON_POOL_TOO_SMALL . ' (' . $available . ' items for a minimum of ' . $minimum . ')';
        }

        return null;
    }

    /**
     * Whether a materialisation result meets every postcondition of the stage.
     *
     * A run may only be scheduled when every planned item exists as a question,
     * is registered with the engine, carries active parameters and can be
     * retrieved through the engine's own path.
     *
     * @param array $result The materialisation stage result.
     * @return bool
     */
    public static function materialisation_complete(array $result): bool {
        $planned = (int) ($result['planned'] ?? 0);
        if ($planned <= 0) {
            return false;
        }
        if ((int) ($result['faileditems'] ?? 0) > 0) {
            return false;
        }

        foreach (['questionscreated', 'itemsregistered', 'parametersregistered', 'enginevisible'] as $counter) {
            if (!isset($result[$counter]) || (int) $result[$counter] !== $planned) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the normalised experiment definition for a run.
     *
     * @param \stdClass $run The run record.
     * @return array
     */
    protected static function definition_for(\stdClass $run): array {
        global $DB;

        // The cell definition from the manifest, not the experiment's base
        // definition. A sweep expands one base definition into cells that
        // differ in strategy, model, pool variant, stratum, severity or
        // budgets; reading the base definition back here would run every cell
        // with the same configuration while the cell key and the manifest
        // claimed otherwise. That is not a configuration mistake but an
        // invalidated experiment: the recorded intervention and the executed
        // one would differ.
        $manifest = json_decode((string) ($run->manifestjson ?? ''), true);
        $cell = $manifest['config']['definition'] ?? null;
        if (is_array($cell) && $cell !== []) {
            return (new experiment_definition($cell))->get_normalised();
        }

        // Only a run predating manifested cell definitions falls back, and it
        // says so, because for such a run the base definition is all there is.
        $configjson = (string) $DB->get_field('local_catquizlab_experiment', 'configjson', ['id' => $run->experimentid]);
        if ($configjson === '') {
            $configjson = json_encode(experiment_definition::example_baseline());
        }

        return experiment_definition::from_json($configjson)->get_normalised();
    }

    /**
     * Whether a run carries its own cell definition.
     *
     * @param \stdClass $run The run record.
     * @return bool False for legacy runs provisioned before manifests held one.
     */
    public static function has_cell_definition(\stdClass $run): bool {
        $manifest = json_decode((string) ($run->manifestjson ?? ''), true);
        $cell = $manifest['config']['definition'] ?? null;

        return is_array($cell) && $cell !== [];
    }

    /**
     * Check that the run was set up with the configuration its manifest records.
     *
     * The manifest is what a later reader treats as the description of the
     * intervention. If the effective configuration drifts from it, every result
     * of the run is attributed to conditions it did not run under, so a
     * mismatch is a hard failure rather than a warning.
     *
     * @param \stdClass $run The run record.
     * @param array $definition The definition the setup actually used.
     * @return string[] The fields that disagree; empty when they match.
     */
    public static function manifest_drift(\stdClass $run, array $definition): array {
        $manifest = json_decode((string) ($run->manifestjson ?? ''), true);
        $config = $manifest['config'] ?? null;
        if (!is_array($config)) {
            return [];
        }

        $checks = [
            'strategy' => [$config['strategy']['key'] ?? null, $definition['strategy'] ?? null],
            'model'    => [$config['model']['key'] ?? null, $definition['model'] ?? null],
            'variant'  => [$config['pool']['variant'] ?? null, $definition['pool']['variant'] ?? null],
            'stratum'  => [$config['persons']['stratum'] ?? null, $definition['persons']['stratum'] ?? null],
            'severity' => [$config['persons']['severity'] ?? null, $definition['persons']['severity'] ?? null],
        ];

        $drift = [];
        foreach ($checks as $field => [$recorded, $effective]) {
            if ($recorded !== null && (string) $recorded !== (string) $effective) {
                $drift[] = $field . ': manifest=' . $recorded . ', effective=' . $effective;
            }
        }

        return $drift;
    }

    /**
     * Dispatch a single setup stage.
     *
     * @param string $stage The stage name.
     * @param array $context The shared setup context.
     * @return mixed The stage result.
     */
    protected static function run_stage(string $stage, array $context) {
        switch ($stage) {
            case self::STAGE_SCALES:
                return self::stage_scales($context);
            case self::STAGE_MATERIALISE:
                return self::stage_materialise($context);
            case self::STAGE_CONTAINER:
                return self::stage_container($context);
            case self::STAGE_TEST:
                return self::stage_test($context);
            case self::STAGE_PEOPLE:
                return self::stage_people($context);
            case self::STAGE_ATTEMPTS:
                return self::stage_attempts($context);
            default:
                return null;
        }
    }

    /**
     * Scale-tree stage: build the engine context and scale tree.
     *
     * @param array $context The setup context.
     * @return array|null
     */
    protected static function stage_scales(array $context): ?array {
        $scales = $context['definition']['pool']['scales'] ?? [];
        $blueprint = [
            // The run identifies the scale tree; the cell key alone is empty
            // when nothing is swept, and identical across replications when it
            // is not.
            'name'          => 'CATLab run ' . (int) $context['runid']
                . (trim((string) ($context['run']->cellkey ?? '')) !== ''
                    ? ' – ' . $context['run']->cellkey
                    : ''),
            'categories'    => (int) ($scales['categories'] ?? 0),
            'subcategories' => (int) ($scales['subcategories'] ?? 0),
        ];
        return scale_provisioner::provision((int) $context['runid'], $blueprint);
    }

    /**
     * Materialisation stage: create questions and register items.
     *
     * @param array $context The setup context.
     * @return array|null
     */
    protected static function stage_materialise(array $context): ?array {
        $options = $context['options'];
        $seeds = $context['seeds'];

        // Polytomy follows from the model rather than from a separate switch:
        // a run whose definition says GPCM is polytomous, and one that says 2PL
        // cannot be made polytomous by an option passed at setup time.
        return materialiser::materialise((int) $context['runid'], $context['definition'], [
            'questioncategoryid' => (int) ($options['questioncategoryid'] ?? 0),
            'seed'               => (int) $context['seed'],
            'poolseed'           => $seeds['pool'],
            'mutationseed'       => $seeds['mutation'],
            'template'           => $options['template'] ?? null,
        ]);
    }

    /**
     * Test stage: create and bind the adaptivequiz CAT test.
     *
     * @param array $context The setup context.
     * @return int|null The test course-module id.
     */
    protected static function stage_test(array $context): array {
        $runid = (int) $context['runid'];
        $root = self::root_scale($runid);
        if ($root === null) {
            return ['failed' => true, 'reason' => self::REASON_NO_ROOT_SCALE, 'testcmid' => 0];
        }

        $container = experiment_container::existing((int) ($context['run']->experimentid ?? 0));
        if ($container['sectionid'] <= 0) {
            return ['failed' => true, 'reason' => experiment_container::REASON_NO_SECTION, 'testcmid' => 0];
        }

        // An activity this run already has is reused. Re-provisioning used to
        // add a second adaptivequiz to the section, leaving two activities for
        // one run and no way to tell which one its attempts belonged to.
        global $DB;
        $existingcmid = (int) ($context['run']->testcmid ?? 0);
        if ($existingcmid > 0 && $DB->record_exists('course_modules', ['id' => $existingcmid])) {
            return [
                'failed'   => false,
                'testcmid' => $existingcmid,
                'section'  => $container['sectionnum'],
                'reused'   => true,
            ];
        }
        // The definition is the only source for strategy, budgets and SE
        // bounds. Passing just the name here is what let two experimentally
        // different cells run with identical CAT settings.
        $options = test_provisioner::options_from_definition($context['definition'], [
            'name'    => experiment_container::activity_name($context['run']),
            'section' => $container['sectionnum'],
        ]);

        $testcmid = test_provisioner::create($runid, $root, self::subscale_ids($runid), $options);

        // A null here used to pass as success, which is how a run with no CAT
        // activity at all ended up scheduled.
        if ($testcmid === null || $testcmid <= 0) {
            return ['failed' => true, 'reason' => self::REASON_NO_TEST, 'testcmid' => 0];
        }

        return ['failed' => false, 'testcmid' => (int) $testcmid, 'section' => $container['sectionnum']];
    }

    /**
     * People stage: generate persons and provision users, course and enrolment.
     *
     * @param array $context The setup context.
     * @return array
     */
    protected static function stage_people(array $context): array {
        $runid = (int) $context['runid'];
        $persons = person_generator::generate_and_persist(
            $runid,
            $context['definition'],
            (int) $context['seeds']['person'],
            [
                'deviationseed' => (int) $context['seeds']['deviation'],
                'replication'   => (int) ($context['run']->replication ?? 1),
            ]
        );
        $users = user_provisioner::provision($runid);

        // The starting ability of every simulated person on every scale of the
        // run. The engine needs one before it can choose a first question, and
        // a person the worker drops straight into an attempt has none.
        $seeded = user_provisioner::seed_person_parameters($runid);

        $course = course_provisioner::provision($runid);

        // The last word before the test is created: everything the engine
        // caches about scales, contexts and items describes this run now.
        cat_item_provisioner::purge_engine_cache();

        return [
            'persons' => $persons,
            'users'   => $users,
            'seeded'  => $seeded,
            'course'  => $course,
        ];
    }

    /**
     * Attempts stage: queue the simulated attempts.
     *
     * @param array $context The setup context.
     * @return array
     */
    protected static function stage_attempts(array $context): array {
        return ['scheduled' => attempt_scheduler::schedule((int) $context['runid'])];
    }

    /**
     * The run's root engine scale id from the scale map.
     *
     * @param int $runid The run.
     * @return int|null
     */
    protected static function root_scale(int $runid): ?int {
        global $DB;

        $id = $DB->get_field(
            'local_catquizlab_scalemap',
            'catscaleid',
            ['runid' => $runid, 'level' => scale_provisioner::LEVEL_ROOT]
        );
        return $id ? (int) $id : null;
    }

    /**
     * Every engine scale of the run below the root.
     *
     * All of them, not only the leaves. The tree is root → domain → subscale,
     * and the items hang on the leaves — but a leaf is only reachable through
     * its domain. Reporting the leaves while leaving the domain out of the
     * test's scale selection describes a tree with a hole in the middle, and
     * the engine has to walk through that hole to find any item at all.
     *
     * @param int $runid The run.
     * @return int[]
     */
    protected static function subscale_ids(int $runid): array {
        global $DB;

        $rows = $DB->get_records_select(
            'local_catquizlab_scalemap',
            'runid = :runid AND level > :root',
            ['runid' => $runid, 'root' => scale_provisioner::LEVEL_ROOT],
            'level ASC, id ASC',
            'catscaleid'
        );

        return array_map('intval', array_keys($rows));
    }
}
