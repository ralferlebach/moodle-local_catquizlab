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

    /**
     * The ordered setup pipeline.
     *
     * @return string[]
     */
    public static function plan_stages(): array {
        return [
            self::STAGE_SCALES,
            self::STAGE_MATERIALISE,
            self::STAGE_TEST,
            self::STAGE_PEOPLE,
            self::STAGE_ATTEMPTS,
        ];
    }

    /**
     * Set up a run end to end.
     *
     * @param int $runid The run to set up.
     * @param array $options 'questioncategoryid' for materialisation, 'polytomous', 'template'.
     * @return array{ok: bool, reason?: string, stages: array<string, mixed>}
     */
    public static function setup(int $runid, array $options = []): array {
        global $DB;

        if (!environment::engine_available()) {
            return ['ok' => false, 'reason' => 'engine-unavailable', 'stages' => []];
        }

        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid], '*', MUST_EXIST);
        $definition = self::definition_for($run);
        $seed = (int) $run->seed;
        $context = [
            'runid'      => $runid,
            'run'        => $run,
            'definition' => $definition,
            'seed'       => $seed,
            'options'    => $options,
        ];

        $stages = [];
        foreach (self::plan_stages() as $stage) {
            $stages[$stage] = self::run_stage($stage, $context);
        }

        $DB->set_field('local_catquizlab_run', 'status', registry::STATUS_SCHEDULED, ['id' => $runid]);

        return ['ok' => true, 'stages' => $stages];
    }

    /**
     * Resolve the normalised experiment definition for a run.
     *
     * @param \stdClass $run The run record.
     * @return array
     */
    protected static function definition_for(\stdClass $run): array {
        global $DB;

        $configjson = (string) $DB->get_field('local_catquizlab_experiment', 'configjson', ['id' => $run->experimentid]);
        if ($configjson === '') {
            $configjson = json_encode(experiment_definition::example_baseline());
        }
        return experiment_definition::from_json($configjson)->get_normalised();
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
            'name'          => (string) ($context['run']->cellkey ?? 'CATLab'),
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
        return materialiser::materialise((int) $context['runid'], $context['definition'], [
            'questioncategoryid' => (int) ($options['questioncategoryid'] ?? 0),
            'seed'               => (int) $context['seed'],
            'polytomous'         => !empty($options['polytomous']),
            'template'           => $options['template'] ?? null,
        ]);
    }

    /**
     * Test stage: create and bind the adaptivequiz CAT test.
     *
     * @param array $context The setup context.
     * @return int|null The test course-module id.
     */
    protected static function stage_test(array $context): ?int {
        $runid = (int) $context['runid'];
        $root = self::root_scale($runid);
        if ($root === null) {
            return null;
        }
        return test_provisioner::create($runid, $root, self::subscale_ids($runid), [
            'name' => (string) ($context['run']->cellkey ?? ('CATLab test ' . $runid)),
        ]);
    }

    /**
     * People stage: generate persons and provision users, course and enrolment.
     *
     * @param array $context The setup context.
     * @return array
     */
    protected static function stage_people(array $context): array {
        $runid = (int) $context['runid'];
        $persons = person_generator::generate_and_persist($runid, $context['definition'], (int) $context['seed']);
        $users = user_provisioner::provision($runid);
        $course = course_provisioner::provision($runid);
        return ['persons' => $persons, 'users' => $users, 'course' => $course];
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
     * The run's subscale engine scale ids from the scale map.
     *
     * @param int $runid The run.
     * @return int[]
     */
    protected static function subscale_ids(int $runid): array {
        global $DB;

        $rows = $DB->get_records(
            'local_catquizlab_scalemap',
            ['runid' => $runid, 'level' => scale_provisioner::LEVEL_SUBSCALE],
            '',
            'catscaleid'
        );
        return array_map('intval', array_keys($rows));
    }
}
