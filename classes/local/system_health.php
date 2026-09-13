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
 * Every question an operator has to answer, answered in the interface.
 *
 * The rule this class exists to keep: running, diagnosing and recovering an
 * experiment has to be possible from the plugin's own pages. A shell and a
 * database client are how one investigates a defect, not how one operates a
 * plugin — and the reported installation needed both to find out why 1600
 * queued attempts were not moving.
 *
 * Each check reports three things: whether it passes, what it found, and where
 * to go to fix it. A check that only says "failed" moves the work to the reader
 * rather than doing it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class system_health {
    /** @var string Everything this check needs is in place. */
    public const OK = 'ok';

    /** @var string Usable, but something will bite later. */
    public const WARN = 'warn';

    /** @var string This stops experiments from running. */
    public const FAIL = 'fail';

    /** @var int The Node major version the worker needs. */
    public const NODE_MAJOR = 20;

    /**
     * The whole health picture.
     *
     * @return array{status: string, checks: array[], blockers: int, warnings: int}
     */
    public static function health(): array {
        $checks = array_merge(
            self::engine_checks(),
            self::course_checks(),
            self::worker_checks(),
            self::pipeline_checks()
        );

        $blockers = 0;
        $warnings = 0;
        foreach ($checks as $check) {
            if ($check['status'] === self::FAIL) {
                $blockers++;
            } else if ($check['status'] === self::WARN) {
                $warnings++;
            }
        }

        return [
            'status'   => $blockers > 0 ? self::FAIL : ($warnings > 0 ? self::WARN : self::OK),
            'ok'       => $blockers === 0 && $warnings === 0,
            'checks'   => $checks,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * Is the CAT engine present?
     *
     * @return array[]
     */
    protected static function engine_checks(): array {
        $component = 'local_catquizlab';
        $engine = environment::catquiz_available();
        $activity = environment::adaptivequiz_available();

        return [
            self::check(
                'engine',
                get_string('health:engine', $component),
                $engine ? self::OK : self::FAIL,
                $engine
                    ? get_string('health:enginefound', $component)
                    : get_string('preflight:noengine', $component)
            ),
            self::check(
                'activity',
                get_string('health:activity', $component),
                $activity ? self::OK : self::FAIL,
                $activity
                    ? get_string('health:activityfound', $component)
                    : get_string('preflight:noactivity', $component)
            ),
        ];
    }

    /**
     * Is there a course to provision into?
     *
     * @return array[]
     */
    protected static function course_checks(): array {
        global $DB;

        $component = 'local_catquizlab';
        $courseid = (int) get_config($component, 'experimentcourseid');
        $label = get_string('health:course', $component);
        $settings = registry::settings_url()->out(false);

        if ($courseid <= 0) {
            return [self::check(
                'course',
                $label,
                self::FAIL,
                get_string('preflight:nocourse', $component),
                $settings
            )];
        }

        if (!$DB->record_exists('course', ['id' => $courseid])) {
            // Worse than none at all: the setting looks right, and the failure
            // then happens deep inside a provisioning stage.
            return [self::check(
                'course',
                $label,
                self::FAIL,
                get_string('preflight:coursemissing', $component),
                $settings
            )];
        }

        return [self::check(
            'course',
            $label,
            self::OK,
            format_string((string) $DB->get_field('course', 'fullname', ['id' => $courseid]))
        )];
    }

    /**
     * Can a worker be started, and will it be able to work?
     *
     * @return array[]
     */
    protected static function worker_checks(): array {
        $component = 'local_catquizlab';
        $checks = [];

        // The token is the piece that used to require leaving the plugin
        // entirely: it had to be created by hand outside the workflow.
        $token = self::worker_token();
        $checks[] = self::check(
            'workertoken',
            get_string('health:workertoken', $component),
            $token !== null ? self::OK : self::FAIL,
            $token !== null
                ? get_string('health:workertokenfound', $component)
                : get_string('health:workertokenmissing', $component)
        );

        $node = self::node_version();
        if ($node === null) {
            $checks[] = self::check(
                'node',
                get_string('health:node', $component),
                self::FAIL,
                get_string('health:nodemissing', $component),
                registry::settings_url()->out(false)
            );
        } else {
            // A worker that starts on Node 18 and dies on its first dependency
            // is worse than one that refuses to start: the queue looks served.
            $oldenough = $node['major'] >= self::NODE_MAJOR;
            $checks[] = self::check(
                'node',
                get_string('health:node', $component),
                $oldenough ? self::OK : self::FAIL,
                $oldenough ? $node['version'] : get_string('health:nodetooold', $component, (object) [
                    'found'    => $node['version'],
                    'required' => self::NODE_MAJOR,
                ])
            );
        }

        $modules = self::worker_modules_installed();
        $checks[] = self::check(
            'workermodules',
            get_string('health:workermodules', $component),
            $modules ? self::OK : self::FAIL,
            $modules
                ? get_string('health:workermodulesfound', $component)
                : get_string('health:workermodulesmissing', $component)
        );

        $fleet = worker_registry::summary();
        $checks[] = self::check(
            'workerfleet',
            get_string('health:workerfleet', $component),
            $fleet['live'] > 0 ? self::OK : self::WARN,
            $fleet['live'] > 0
                ? get_string('health:workerslive', $component, $fleet['live'])
                : get_string('health:workersnone', $component)
        );

        return $checks;
    }

    /**
     * Is work moving, or only waiting?
     *
     * @return array[]
     */
    protected static function pipeline_checks(): array {
        global $DB;

        $component = 'local_catquizlab';
        $queued = $DB->count_records('local_catquizlab_attempt', ['status' => attempt_scheduler::STATUS_QUEUED]);
        $live = worker_registry::summary()['live'];

        // Work waiting with nobody to do it is the one combination that never
        // resolves itself, and it is the state the reported installation sat in
        // with 1600 attempts.
        if ($queued > 0 && $live === 0) {
            return [self::check(
                'pipeline',
                get_string('health:pipeline', $component),
                self::FAIL,
                get_string('health:pipelinestalled', $component, $queued)
            )];
        }

        return [self::check(
            'pipeline',
            get_string('health:pipeline', $component),
            self::OK,
            get_string('health:pipelinemoving', $component, $queued)
        )];
    }

    /**
     * A token for the worker web service, if one exists.
     *
     * @return string|null
     */
    public static function worker_token(): ?string {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => 'local_catquizlab_worker']);
        if (!$service || (int) $service->enabled !== 1) {
            return null;
        }

        $tokens = $DB->get_records('external_tokens', ['externalserviceid' => (int) $service->id], 'id DESC', '*', 0, 1);

        return $tokens ? (string) reset($tokens)->token : null;
    }

    /**
     * The Node version the configured binary reports.
     *
     * @return array{version: string, major: int}|null
     */
    public static function node_version(): ?array {
        $path = trim((string) get_config('local_catquizlab', 'worker_node_path'));
        if ($path === '' || !is_executable($path)) {
            return null;
        }

        $output = [];
        $exit = 0;
        @exec(escapeshellarg($path) . ' --version 2>/dev/null', $output, $exit);
        $version = trim((string) ($output[0] ?? ''));
        if ($exit !== 0 || $version === '') {
            return null;
        }

        return ['version' => $version, 'major' => (int) ltrim(explode('.', $version)[0], 'v')];
    }

    /**
     * Whether the worker's dependencies have been installed.
     *
     * @return bool
     */
    public static function worker_modules_installed(): bool {
        global $CFG;

        return is_dir($CFG->dirroot . '/local/catquizlab/worker/node_modules/puppeteer');
    }

    /**
     * Build one check result.
     *
     * @param string $id Stable identifier.
     * @param string $label What is being checked.
     * @param string $status One of OK, WARN, FAIL.
     * @param string $detail What was found.
     * @param string|null $actionurl Where to go to fix it.
     * @return array
     */
    protected static function check(
        string $id,
        string $label,
        string $status,
        string $detail,
        ?string $actionurl = null
    ): array {
        return [
            'id'        => $id,
            'label'     => $label,
            'status'    => $status,
            'ok'        => $status === self::OK,
            'warn'      => $status === self::WARN,
            'fail'      => $status === self::FAIL,
            'detail'    => $detail,
            'actionurl' => $actionurl,
        ];
    }
}
