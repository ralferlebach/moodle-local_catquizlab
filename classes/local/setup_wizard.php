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

        // Six steps, each answering one thing once. They used to be four
        // stages beside six separate diagnostic cards that repeated them —
        // Node, dependencies, browser and base URL appeared in the wizard and
        // again below it, which made the page more complete and less readable.
        $stages = [
            self::engine_stage($component),
            self::environment_stage($component),
            self::access_stage($component),
            self::runtime_stage($component),
            self::pipeline_stage($component),
            self::readiness_stage($component),
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
        global $CFG;

        // Moodle's own canonical setting, not a second one of ours. The task
        // administration needs this value too, and two fields for one path is
        // how they come to disagree.
        $php = trim((string) ($CFG->pathtophp ?? ''));
        $phpok = $php !== '' && is_executable($php);

        return self::stage('engine', get_string('wizard:engine', $component), [
            self::step('catquiz', get_string('health:engine', $component), environment::catquiz_available()),
            self::step(
                'adaptivequiz',
                get_string('health:activity', $component),
                environment::adaptivequiz_available()
            ),
            self::step(
                'phpcli',
                get_string('health:phpcli', $component),
                $phpok,
                self::php_cli_detail($php, $component)
            ),
        ], get_string('wizard:enginehint', $component));
    }

    /**
     * What is wrong with the configured PHP CLI path, in words.
     *
     * "Not configured" and "configured to something that is not there" need
     * different answers, and both used to surface as a scheduled task that
     * quietly did nothing.
     *
     * @param string $php The configured path.
     * @param string $component For the strings.
     * @return string
     */
    protected static function php_cli_detail(string $php, string $component): string {
        if ($php === '') {
            $found = self::find_php_cli();

            return $found !== null
                ? get_string('health:phpclimissingfound', $component, $found)
                : get_string('health:phpclimissing', $component);
        }

        if (!file_exists($php)) {
            return get_string('health:phpclinotthere', $component, $php);
        }

        if (!is_executable($php)) {
            return get_string('health:phpclinotexecutable', $component, $php);
        }

        return $php;
    }

    /**
     * A PHP CLI binary this server could use.
     *
     * @return string|null
     */
    public static function find_php_cli(): ?string {
        $candidates = ['/usr/bin/php', '/usr/local/bin/php', '/opt/php/bin/php'];

        $which = @exec('command -v php 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            array_unshift($candidates, trim($which));
        }

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
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
     * Step 3: may the worker talk to Moodle at all.
     *
     * Each of the eleven checks is its own line rather than a summary with a
     * card repeating them underneath: one place, one answer.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function access_stage(string $component): array {
        $access = worker_access::verify();

        $steps = [];
        foreach ($access['steps'] as $check) {
            $steps[] = self::step(
                (string) $check['id'],
                (string) $check['label'],
                !empty($check['ok']),
                (string) ($check['detail'] ?? '')
            );
        }

        return self::stage(
            'access',
            get_string('wizard:access', $component),
            $steps,
            $access['ok'] ? '' : get_string('wizard:accesshint', $component)
        );
    }

    /**
     * Step 4: can the worker actually run.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function runtime_stage(string $component): array {
        $runtime = worker_runtime::verify();

        $steps = [];
        foreach ($runtime['steps'] as $check) {
            $steps[] = self::step(
                (string) $check['id'],
                (string) $check['label'],
                !empty($check['ok']),
                (string) ($check['detail'] ?? '')
            );
        }

        return self::stage(
            'runtime',
            get_string('wizard:runtime', $component),
            $steps,
            $runtime['ok'] ? '' : get_string('wizard:runtimehint', $component)
        );
    }

    /**
     * Step 6: the answer the whole tab exists for.
     *
     * Not a check of its own — it restates what the five before it establish,
     * because "can this installation run an experiment" is the question
     * somebody came with, and five green rows are an argument rather than an
     * answer.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function readiness_stage(string $component): array {
        $ready = environment::catquiz_available()
            && environment::adaptivequiz_available()
            && worker_access::verify()['ok']
            && worker_runtime::verify()['ok']
            && self::pipeline_enabled();

        return self::stage('readiness', get_string('wizard:readiness', $component), [
            self::step(
                'canrun',
                get_string('wizard:canrun', $component),
                $ready,
                $ready ? get_string('wizard:canrunyes', $component)
                    : get_string('wizard:canrunno', $component)
            ),
        ]);
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

        return [
            'id'         => $id,
            'label'      => $label,
            'ok'         => $ok,
            'incomplete' => !$ok,
            'steps'      => $steps,
            'hint'       => $hint,
            // The action belongs to the step it fixes. It used to live in a card
            // below that repeated the step, which is why removing the cards
            // nearly took the only way to set up worker access with them.
            'action'     => $ok ? null : self::action_for($id),
        ];
    }

    /**
     * What fixes a step, when something can.
     *
     * @param string $id The step.
     * @return array|null Label and url, or null when there is nothing to press.
     */
    protected static function action_for(string $id): ?array {
        $component = 'local_catquizlab';
        $ops = new \moodle_url('/local/catquizlab/operations.php');

        $actions = [
            // The engine is installed by an administrator; a button here would
            // be a promise this plugin cannot keep.
            // The engine is installed by an administrator; a button here would
            // be a promise this plugin cannot keep. The PHP path is different:
            // it is a setting, and one this page can fill in.
            'engine'      => ['label' => get_string('health:setphpcli', $component), 'action' => 'setphpcli'],
            'environment' => ['label' => get_string('wizard:run', $component), 'action' => 'wizard'],
            'access'      => ['label' => get_string('access:setup', $component), 'action' => 'setupaccess'],
            'runtime'     => ['label' => get_string('runtime:setup', $component), 'action' => 'setupruntime'],
            'pipeline'    => ['label' => get_string('wizard:runandenable', $component), 'action' => 'wizardstart'],
            'readiness'   => ['label' => get_string('wizard:run', $component), 'action' => 'wizard'],
        ];

        $action = $actions[$id] ?? null;
        if ($action === null) {
            return null;
        }

        return [
            'label'   => $action['label'],
            'url'     => $ops->out(false),
            'command' => $action['action'],
        ];
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
