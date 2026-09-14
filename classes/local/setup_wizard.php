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
 * One question, answered in one place: can this installation run an experiment?
 *
 * The pieces existed — engine detection, course check, worker health, self-test,
 * browser install, registry, queue view — but they were spread across the
 * settings page, the operations page and Moodle's own administration. A fresh
 * installation therefore needed somebody who knew the internal dependencies and
 * the order they had to be satisfied in, which is knowledge about this plugin's
 * implementation rather than about experiments.
 *
 * Four stages, in the order they depend on each other:
 *
 *   1. engine      — the CAT plugins, which nothing here can install
 *   2. environment — the experiment course
 *   3. worker      — access to Moodle, and the runtime to run a browser
 *   4. pipeline    — the scheduled task and the master switch
 *
 * The last stage is deliberately last. `pipeline_tick` ships disabled, which is
 * right: a task that hands out work should not start doing so the moment a
 * plugin is installed. But that is an argument for enabling it knowingly, not
 * for making somebody find it in the scheduled task administration — so it is
 * offered here, and only once the three stages it depends on are green.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup_wizard {
    /** @var string The scheduled task that moves the pipeline along. */
    public const TASK = '\local_catquizlab\task\pipeline_tick';

    /**
     * The state of every stage, without changing anything.
     *
     * @return array{ready: bool, stages: array[], blockers: string[]}
     */
    public static function state(): array {
        $component = 'local_catquizlab';

        $stages = [
            self::engine_stage($component),
            self::environment_stage($component),
            self::worker_stage($component),
            self::pipeline_stage($component),
        ];

        $blockers = [];
        foreach ($stages as $stage) {
            foreach ($stage['steps'] as $step) {
                if (empty($step['ok'])) {
                    $blockers[] = $step['label'];
                }
            }
        }

        return ['ready' => $blockers === [], 'stages' => $stages, 'blockers' => $blockers];
    }

    /**
     * Do everything that can be done automatically, in dependency order.
     *
     * Stops at the first stage that cannot be completed rather than pressing
     * on: setting up a worker against an engine that is not there produces a
     * second failure that hides the first.
     *
     * @param bool $enablepipeline Whether to switch the pipeline on at the end.
     * @return array{ready: bool, changed: string[], log: string[], stages: array[]}
     */
    public static function run(bool $enablepipeline = false): array {
        $changed = [];
        $log = [];

        // Stage 1 is not ours to fix: the CAT plugins are installed by an
        // administrator, and saying so plainly beats a wizard that appears to
        // be working on it.
        if (!environment::catquiz_available() || !environment::adaptivequiz_available()) {
            return [
                'ready'   => false,
                'changed' => $changed,
                'log'     => [get_string('wizard:engineneeded', 'local_catquizlab')],
                'stages'  => self::state()['stages'],
            ];
        }

        $courseid = experiment_container::ensure_course();
        if ($courseid > 0 && (int) get_config('local_catquizlab', 'experimentcourseid') === $courseid) {
            $changed[] = 'course';
        }

        $access = worker_access::ensure();
        $changed = array_merge($changed, $access['changed']);

        $runtime = worker_runtime::ensure();
        $changed = array_merge($changed, $runtime['changed']);
        $log = array_merge($log, $runtime['log']);

        // Only after the rest holds. A pipeline switched on over a broken setup
        // does not produce results, it produces failing jobs.
        if ($enablepipeline && $access['ok'] && $runtime['ok']) {
            if (self::enable_pipeline()) {
                $changed[] = 'pipeline';
            }
        }

        $state = self::state();

        return [
            'ready'   => $state['ready'],
            'changed' => array_values(array_unique($changed)),
            'log'     => $log,
            'stages'  => $state['stages'],
        ];
    }

    /**
     * Switch on the scheduled task and the master switch.
     *
     * @return bool Whether anything changed.
     */
    public static function enable_pipeline(): bool {
        $changed = false;

        if ((int) get_config('local_catquizlab', 'enabled') !== 1) {
            set_config('enabled', 1, 'local_catquizlab');
            $changed = true;
        }

        $task = \core\task\manager::get_scheduled_task(self::TASK);
        if ($task && $task->get_disabled()) {
            $task->set_disabled(false);
            \core\task\manager::configure_scheduled_task($task);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Whether the pipeline is switched on at both levels.
     *
     * @return bool
     */
    public static function pipeline_enabled(): bool {
        if ((int) get_config('local_catquizlab', 'enabled') !== 1) {
            return false;
        }

        $task = \core\task\manager::get_scheduled_task(self::TASK);

        return $task !== false && !$task->get_disabled();
    }

    /**
     * Stage 1: the CAT plugins.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function engine_stage(string $component): array {
        return self::stage('engine', get_string('wizard:engine', $component), [
            self::step('catquiz', get_string('health:engine', $component), environment::catquiz_available()),
            self::step(
                'adaptivequiz',
                get_string('health:activity', $component),
                environment::adaptivequiz_available()
            ),
        ], get_string('wizard:enginehint', $component));
    }

    /**
     * Stage 2: somewhere to provision into.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function environment_stage(string $component): array {
        global $DB;

        $courseid = (int) get_config($component, 'experimentcourseid');
        $exists = $courseid > 0 && $DB->record_exists('course', ['id' => $courseid]);

        return self::stage('environment', get_string('wizard:environment', $component), [
            self::step('course', get_string('health:course', $component), $exists),
        ]);
    }

    /**
     * Stage 3: the worker's access and its runtime.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function worker_stage(string $component): array {
        $steps = [];

        $access = worker_access::verify();
        $steps[] = self::step(
            'access',
            get_string('access:heading', $component),
            $access['ok'],
            $access['ok'] ? '' : implode(', ', $access['missing'])
        );

        $runtime = worker_runtime::verify();
        $steps[] = self::step(
            'runtime',
            get_string('runtime:heading', $component),
            $runtime['ok'],
            $runtime['ok'] ? '' : implode(', ', $runtime['missing'])
        );

        return self::stage('worker', get_string('wizard:worker', $component), $steps);
    }

    /**
     * Stage 4: the switches that let work actually move.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function pipeline_stage(string $component): array {
        $task = \core\task\manager::get_scheduled_task(self::TASK);

        return self::stage('pipeline', get_string('wizard:pipeline', $component), [
            self::step(
                'enabled',
                get_string('wizard:masterswitch', $component),
                (int) get_config($component, 'enabled') === 1
            ),
            self::step(
                'task',
                get_string('wizard:task', $component),
                $task !== false && !$task->get_disabled()
            ),
            // Without cron the task exists and never runs, which looks exactly
            // like a task that is disabled.
            self::step('cron', get_string('wizard:cron', $component), self::cron_recent()),
        ], get_string('wizard:pipelinehint', $component));
    }

    /**
     * Whether cron has run recently enough to be considered working.
     *
     * @return bool
     */
    protected static function cron_recent(): bool {
        $last = (int) get_config('tool_task', 'lastcronstart');
        if ($last === 0) {
            $last = (int) get_config('core', 'lastcron');
        }

        // A day: generous, because the question is whether cron runs at all,
        // not whether it ran in the last minute.
        return $last > 0 && (time() - $last) < DAYSECS;
    }

    /**
     * Build one stage.
     *
     * @param string $id Stable identifier.
     * @param string $label The stage name.
     * @param array[] $steps Its steps.
     * @param string $hint What to do when it is not green.
     * @return array
     */
    protected static function stage(string $id, string $label, array $steps, string $hint = ''): array {
        $ok = true;
        foreach ($steps as $step) {
            $ok = $ok && !empty($step['ok']);
        }

        return ['id' => $id, 'label' => $label, 'ok' => $ok, 'incomplete' => !$ok,
            'steps' => $steps, 'hint' => $hint];
    }

    /**
     * Build one step.
     *
     * @param string $id Stable identifier.
     * @param string $label What it checks.
     * @param bool $ok Whether it holds.
     * @param string $detail What is missing, when something is.
     * @return array
     */
    protected static function step(string $id, string $label, bool $ok, string $detail = ''): array {
        return ['id' => $id, 'label' => $label, 'ok' => $ok, 'missing' => !$ok, 'detail' => $detail];
    }
}
