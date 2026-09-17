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
 * Does this installation actually work — asked by doing the things.
 *
 * The readiness step aggregates other checks, and every one of them reads
 * configuration: a token exists, a path is executable, a plugin is installed.
 * All six can be green on an installation where nothing runs, because "the
 * browser is installed in the cache" and "the browser starts" are different
 * claims, and only the second is the one anybody cares about.
 *
 * So this does them: it starts the browser, calls the web service with the
 * stored token, opens the experiment course, and runs the scheduled task. Each
 * step reports what it found, and a failure names the thing that failed rather
 * than the flag that was clear.
 *
 * It is slow — seconds, not milliseconds — which is why it is a button and not
 * part of every page load.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selftest {
    /**
     * Run every check, in the order each depends on the last.
     *
     * @return array{ok: bool, checks: array[], summary: string}
     */
    public static function run(): array {
        $component = 'local_catquizlab';

        $checks = [];
        $checks[] = self::check_access($component);
        $checks[] = self::check_webservice($component);
        $checks[] = self::check_runtime($component);
        $checks[] = self::check_browser($component);
        $checks[] = self::check_course($component);
        $checks[] = self::check_task($component);

        $ok = true;
        $firstfailure = '';
        foreach ($checks as $check) {
            if (empty($check['ok'])) {
                $ok = false;
                $firstfailure = $firstfailure ?: (string) $check['label'];
            }
        }

        return [
            'ok'      => $ok,
            'checks'  => $checks,
            'summary' => $ok
                ? get_string('selftest:ready', $component)
                : get_string('selftest:blocked', $component, $firstfailure),
        ];
    }

    /**
     * The worker's access, as configuration.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function check_access(string $component): array {
        $access = worker_access::verify();

        return self::row(
            'access',
            get_string('selftest:access', $component),
            $access['ok'],
            $access['ok'] ? '' : implode(', ', $access['missing'])
        );
    }

    /**
     * The web service, called for real with the stored token.
     *
     * A token that exists and a token that works are different things: the
     * account can be suspended, the service unauthorised, the capability gone.
     * Every one of those leaves the setting looking correct.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function check_webservice(string $component): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $token = (string) get_config('local_catquizlab', 'worker_token');
        if ($token === '') {
            return self::row(
                'webservice',
                get_string('selftest:webservice', $component),
                false,
                get_string('selftest:notoken', $component)
            );
        }

        $base = (string) get_config('local_catquizlab', 'worker_base_url') ?: $CFG->wwwroot;
        $url = rtrim($base, '/') . '/webservice/rest/server.php';

        // Moodle's curl blocks local addresses by default, which is right for
        // a URL a user supplied and wrong here: this is the site calling
        // itself, at the address the worker is configured to use. Without the
        // exemption the check reports "The URL is blocked" as a service
        // failure, which sends somebody looking at the token.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setopt(['CURLOPT_TIMEOUT' => 20, 'CURLOPT_CONNECTTIMEOUT' => 10]);
        $response = $curl->post($url, [
            'wstoken'            => $token,
            'wsfunction'         => 'local_catquizlab_job_claim',
            'moodlewsrestformat' => 'json',
            'workerid'           => 'selftest',
        ]);

        $errno = $curl->get_errno();
        if ($errno) {
            return self::row(
                'webservice',
                get_string('selftest:webservice', $component),
                false,
                get_string('selftest:unreachable', $component, $base)
            );
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            // What came back, not just that it was wrong: "The URL is blocked"
            // and an HTML login page are different problems, and the text is
            // what tells them apart.
            return self::row(
                'webservice',
                get_string('selftest:webservice', $component),
                false,
                get_string('selftest:badresponse', $component)
                    . ' ' . \core_text::substr(trim(strip_tags((string) $response)), 0, 120)
            );
        }

        if (isset($decoded['exception'])) {
            // The service answered and refused, which is the useful case: the
            // error code says which of the eleven access steps is wrong.
            return self::row(
                'webservice',
                get_string('selftest:webservice', $component),
                false,
                (string) ($decoded['errorcode'] ?? $decoded['exception'])
            );
        }

        return self::row(
            'webservice',
            get_string('selftest:webservice', $component),
            true,
            get_string('selftest:serviceanswered', $component)
        );
    }

    /**
     * Node and the worker's dependencies.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function check_runtime(string $component): array {
        $runtime = worker_runtime::verify();

        return self::row(
            'runtime',
            get_string('selftest:runtime', $component),
            $runtime['ok'],
            $runtime['ok'] ? '' : implode(', ', $runtime['missing'])
        );
    }

    /**
     * The browser, started.
     *
     * "Installed in the cache the worker reads" and "starts when the worker
     * runs it" are different claims, and the gap between them is where an
     * afternoon went: the self-test passed as the interactive user and the
     * worker failed as the web server user, over a cache path.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function check_browser(string $component): array {
        $result = worker_launcher::self_test(worker_launcher::config_from_settings());

        $ok = (int) ($result['exitcode'] ?? 1) === 0;

        return self::row(
            'browser',
            get_string('selftest:browser', $component),
            $ok,
            $ok
                ? get_string('selftest:browserstarted', $component)
                : \core_text::substr(trim((string) ($result['output'] ?? '')), -200)
        );
    }

    /**
     * The experiment course, present and usable.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function check_course(string $component): array {
        global $DB;

        $courseid = (int) get_config('local_catquizlab', 'experimentcourseid');
        $course = $courseid > 0 ? $DB->get_record('course', ['id' => $courseid]) : null;

        if (!$course) {
            return self::row(
                'course',
                get_string('selftest:course', $component),
                false,
                get_string('selftest:nocourse', $component)
            );
        }

        // Visible, because a hidden course tells its enrolled students it is
        // unavailable — and the simulated people are enrolled students.
        if ((int) $course->visible === 0) {
            return self::row(
                'course',
                get_string('selftest:course', $component),
                false,
                get_string('access:code:course-hidden', $component)
            );
        }

        return self::row(
            'course',
            get_string('selftest:course', $component),
            true,
            format_string($course->fullname)
        );
    }

    /**
     * The pipeline task, executed here and now.
     *
     * @param string $component For the strings.
     * @return array
     */
    protected static function check_task(string $component): array {
        $task = \core\task\manager::get_scheduled_task(setup_wizard::TASK);
        if ($task === false) {
            return self::row(
                'task',
                get_string('selftest:task', $component),
                false,
                get_string('task:notfound', $component)
            );
        }

        if ($task->get_disabled()) {
            return self::row(
                'task',
                get_string('selftest:task', $component),
                false,
                get_string('task:notscheduled', $component)
            );
        }

        // Run it rather than read its flag. A task that is enabled and throws
        // is indistinguishable, from its settings, from one that works.
        ob_start();
        $error = '';
        try {
            $task->execute();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        ob_end_clean();

        return self::row(
            'task',
            get_string('selftest:task', $component),
            $error === '',
            $error !== '' ? $error : get_string('selftest:taskran', $component)
        );
    }

    /**
     * Build one check row.
     *
     * @param string $id Stable identifier.
     * @param string $label What was tried.
     * @param bool $ok Whether it worked.
     * @param string $detail What came back.
     * @return array
     */
    protected static function row(string $id, string $label, bool $ok, string $detail): array {
        return ['id' => $id, 'label' => $label, 'ok' => $ok, 'failed' => !$ok, 'detail' => $detail];
    }
}
