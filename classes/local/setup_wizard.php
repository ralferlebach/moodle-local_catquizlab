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

    /** @var bool|null Readiness as a test declares it, or null to compute it. */
    protected static $assumed = null;

    /**
     * Declare readiness, for tests that cannot install a browser.
     *
     * Honoured only under PHPUnit or Behat. Production never reaches this.
     *
     * @param bool|null $ready What to answer, or null to compute again.
     * @return void
     */
    public static function assume_ready_for_testing(?bool $ready): void {
        if (!defined('PHPUNIT_TEST') && !defined('BEHAT_SITE_RUNNING')) {
            return;
        }
        self::$assumed = $ready;
    }

    /**
     * The state of every stage, without changing anything.
     *
     * @return array{ready: bool, stages: array[], blockers: string[]}
     */
    public static function state(): array {
        $component = 'local_catquizlab';

        if (self::$assumed !== null) {
            return ['ready' => self::$assumed, 'stages' => [], 'blockers' => self::$assumed ? [] : ['assumed']];
        }

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
     * Whether an experiment can be prepared: built, not yet run.
     *
     * Preparation makes a course, questions, a scale tree and simulated
     * people. None of that needs a browser or a worker process, and requiring
     * them here refused to build anything until the whole runtime was in
     * place — so a person could not prepare on Friday and sort out the worker
     * on Monday, and a CI job that had not downloaded a browser yet could not
     * prepare at all. Running is what needs the rest, and running checks it.
     *
     * @return array{ready: bool, blockers: string[]}
     */
    public static function preparation_state(): array {
        $component = 'local_catquizlab';

        $blockers = [];
        foreach ([self::engine_stage($component), self::environment_stage($component)] as $stage) {
            foreach ($stage['steps'] as $step) {
                if (empty($step['ok'])) {
                    $blockers[] = $step['label'];
                }
            }
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers];
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
        global $CFG;

        $changed = false;

        if ((int) get_config('local_catquizlab', 'enabled') !== 1) {
            set_config('enabled', 1, 'local_catquizlab');
            $changed = true;
        }

        // The switch that lets the pipeline start a worker at all. It shipped
        // off, nothing in the setup turned it on, and nothing reported that it
        // was off — so every fresh installation prepared experiments that then
        // sat at "stalled" for as long as anybody watched. The one installation
        // where they ran was the one where I had flipped it by hand.
        if ((int) get_config('local_catquizlab', 'worker_exec_enabled') !== 1) {
            set_config('worker_exec_enabled', 1, 'local_catquizlab');
            $changed = true;
        }

        // The PHP binary the scheduler uses to spawn tasks. Detected and
        // stored here, so the setup does it rather than leaving it as the one
        // amber line a person has to go and find a settings page for.
        $php = trim((string) ($CFG->pathtophp ?? ''));
        if ($php === '' || !is_executable($php)) {
            $found = self::find_php_cli();
            if ($found !== null) {
                set_config('pathtophp', $found);
                $CFG->pathtophp = $found;
                $changed = true;
            }
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

        // Being executable is not being the right thing: a path to the FPM or
        // CGI binary runs and then behaves differently enough that a task using
        // it fails in ways nobody traces back to here.
        $probe = [];
        @exec(escapeshellarg($php) . ' -r "echo PHP_SAPI, \" \", PHP_VERSION;" 2>/dev/null', $probe);
        $reported = trim((string) ($probe[0] ?? ''));

        if ($reported === '') {
            return get_string('health:phpclinoanswer', $component, $php);
        }

        [$sapi, $version] = array_pad(explode(' ', $reported, 2), 2, '');
        if ($sapi !== 'cli') {
            return get_string('health:phpclinotcli', $component, (object) ['path' => $php, 'sapi' => $sapi]);
        }

        return get_string('health:phpcliok', $component, (object) ['path' => $php, 'version' => $version]);
    }

    /**
     * A PHP CLI binary this server could use.
     *
     * @return string|null
     */
    public static function find_php_cli(): ?string {
        $found = self::php_cli_candidates();

        return $found === [] ? null : $found[0]['path'];
    }

    /**
     * Every usable PHP binary on this server, with what each one says it is.
     *
     * More than one is the normal case on a server that has been upgraded: 8.1
     * beside 8.3, or a distribution PHP beside a vendor build. Picking the first
     * and not saying the others exist is how somebody ends up running tasks on a
     * version their site does not support.
     *
     * @return array[] Each with path, version and sapi.
     */
    public static function php_cli_candidates(): array {
        $paths = [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/php/bin/php',
            '/usr/bin/php8.3',
            '/usr/bin/php8.2',
            '/usr/bin/php8.1',
        ];

        $which = @exec('command -v php 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            array_unshift($paths, trim($which));
        }

        $found = [];
        foreach (array_unique($paths) as $path) {
            if (!is_executable($path) || isset($found[$path])) {
                continue;
            }

            $probe = [];
            @exec(escapeshellarg($path) . ' -r "echo PHP_SAPI, \" \", PHP_VERSION;" 2>/dev/null', $probe);
            $reported = trim((string) ($probe[0] ?? ''));
            if ($reported === '') {
                continue;
            }

            [$sapi, $version] = array_pad(explode(' ', $reported, 2), 2, '');

            // Only ones that are actually the CLI: an FPM binary runs and then
            // behaves differently enough that a task using it fails in ways
            // nobody traces back to here.
            if ($sapi !== 'cli') {
                continue;
            }

            $found[$path] = ['path' => $path, 'version' => $version, 'sapi' => $sapi];
        }

        return array_values($found);
    }

    /**
     * Whether a path somebody typed is a PHP CLI binary this server can run.
     *
     * @param string $path The path to check.
     * @return array{ok: bool, reason: string, version: string}
     */
    public static function validate_php_cli(string $path): array {
        $path = trim($path);

        if ($path === '' || !preg_match('#^/[^\0]+$#', $path)) {
            return ['ok' => false, 'reason' => 'notabsolute', 'version' => ''];
        }

        if (!file_exists($path)) {
            return ['ok' => false, 'reason' => 'notthere', 'version' => ''];
        }

        if (!is_executable($path)) {
            return ['ok' => false, 'reason' => 'notexecutable', 'version' => ''];
        }

        $probe = [];
        @exec(escapeshellarg($path) . ' -r "echo PHP_SAPI, \" \", PHP_VERSION;" 2>/dev/null', $probe);
        $reported = trim((string) ($probe[0] ?? ''));

        if ($reported === '') {
            return ['ok' => false, 'reason' => 'noanswer', 'version' => ''];
        }

        [$sapi, $version] = array_pad(explode(' ', $reported, 2), 2, '');

        if ($sapi !== 'cli') {
            return ['ok' => false, 'reason' => 'notcli', 'version' => $sapi];
        }

        return ['ok' => true, 'reason' => '', 'version' => $version];
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
        // The PHP CLI path is deliberately absent: an installation with working
        // cron and this plugin's own run-now can execute everything, and
        // refusing to call it ready over a path Moodle core needs for a
        // different button would be refusing over the wrong thing.
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
        global $CFG;

        $task = \core\task\manager::get_scheduled_task(self::TASK);

        // Three ways a task can be made to run, and they do not fail together.
        // Treating the PHP CLI path as a hard prerequisite was too strict: an
        // installation with working cron runs everything it needs, and this
        // plugin's own "run now" executes the task in-process. Only Moodle's own
        // run-now shells out.
        $php = trim((string) ($CFG->pathtophp ?? ''));
        $phpok = $php !== '' && is_executable($php);

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
            // The switch the pipeline needs to start a worker. It was checked
            // nowhere, so an installation could pass every step here and still
            // never play a sitting — "stalled", with nothing saying why.
            self::step(
                'workerexec',
                get_string('wizard:workerexec', $component),
                (int) get_config($component, 'worker_exec_enabled') === 1
            ),
            // Without cron the task exists and never runs, which looks exactly
            // like a task that is disabled.
            self::step('cron', get_string('wizard:cron', $component), self::cron_recent()),
            // Reported for what it actually gates rather than as a blocker: this
            // plugin's own run-now works without it.
            self::step(
                'phpcli',
                get_string('health:phpcli', $component),
                $phpok,
                self::php_cli_detail($php, $component)
            ),
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
            // The engine is installed by an administrator; a button here would
            // be a promise this plugin cannot keep.
            'engine'      => null,
            'environment' => ['label' => get_string('wizard:run', $component), 'action' => 'wizard'],
            'access'      => ['label' => get_string('access:setup', $component), 'action' => 'setupaccess'],
            'runtime'     => ['label' => get_string('runtime:setup', $component), 'action' => 'setupruntime'],
            'pipeline'    => ['label' => get_string('wizard:runandenable', $component), 'action' => 'wizardstart'],
            'phpcli'      => ['label' => get_string('health:setphpcli', $component), 'action' => 'setphpcli'],
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
            // Carried here rather than reached for with {{../../sesskey}}. That
            // path was one level short, so the field rendered empty and Moodle
            // answered "your session has most likely timed out" — which sent
            // people looking at their login for a template bug.
            'sesskey' => sesskey(),
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
