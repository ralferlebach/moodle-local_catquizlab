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
 * The worker's runtime: found, installed and repaired from the interface.
 *
 * What is left for a shell after this is the operating system itself — Node has
 * to exist, and the web server user has to be allowed to run it. Everything
 * downstream of that is mechanical: locating the binary, installing the
 * dependencies, fetching the browser into the cache the worker will read, and
 * knowing which base URL to hand it.
 *
 * Each step reports whether it holds and what would fix it, and `ensure()`
 * performs the fixes it can. The two are kept apart deliberately: a check that
 * changes the system cannot be run on a page load, and a page that only reports
 * problems leaves the work undone.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class worker_runtime {
    /** @var string[] Where a Node binary usually lives. */
    protected const NODE_CANDIDATES = [
        '/usr/bin/node',
        '/usr/local/bin/node',
        '/opt/node/bin/node',
        '/snap/bin/node',
    ];

    /**
     * Look at the runtime without touching it.
     *
     * @return array{ok: bool, steps: array[], missing: string[]}
     */
    public static function verify(): array {
        global $CFG;

        $component = 'local_catquizlab';
        $steps = [];

        $node = trim((string) get_config($component, 'worker_node_path'));
        $version = system_health::node_version();
        $steps[] = self::step(
            'node',
            get_string('health:node', $component),
            $version !== null && $version['major'] >= system_health::NODE_MAJOR,
            $version !== null
                ? $version['version'] . ' (' . $node . ')'
                : get_string('health:nodemissing', $component)
        );

        $steps[] = self::step(
            'modules',
            get_string('health:workermodules', $component),
            system_health::worker_modules_installed(),
            system_health::worker_modules_installed()
                ? get_string('health:workermodulesfound', $component)
                : get_string('health:workermodulesmissing', $component)
        );

        $steps[] = self::step(
            'browser',
            get_string('runtime:browser', $component),
            self::browser_present(),
            self::browser_present()
                ? get_string('runtime:browserfound', $component)
                : get_string('runtime:browsermissing', $component)
        );

        // The worker talks to Moodle over HTTP like any other client, so it
        // needs a URL that resolves from the server itself. Defaulting to
        // wwwroot is right far more often than leaving it empty.
        $baseurl = trim((string) get_config($component, 'worker_base_url'));
        $steps[] = self::step(
            'baseurl',
            get_string('runtime:baseurl', $component),
            $baseurl !== '',
            $baseurl !== '' ? $baseurl : get_string('runtime:baseurlmissing', $component)
        );

        $missing = [];
        foreach ($steps as $step) {
            if (!$step['ok']) {
                $missing[] = $step['id'];
            }
        }

        return ['ok' => $missing === [], 'steps' => $steps, 'missing' => $missing];
    }

    /**
     * Put the runtime in place as far as the plugin can.
     *
     * @return array{ok: bool, changed: string[], log: string[], steps: array[]}
     */
    public static function ensure(): array {
        global $CFG;

        $component = 'local_catquizlab';
        $changed = [];
        $log = [];

        if (system_health::node_version() === null) {
            $found = self::find_node();
            if ($found !== null) {
                set_config('worker_node_path', $found, $component);
                $changed[] = 'node';
                $log[] = get_string('runtime:nodefound', $component, $found);
            } else {
                // The one genuine operating-system prerequisite. Saying so
                // plainly beats a plugin pretending it can install Node.
                $log[] = get_string('runtime:nodenotfound', $component);
            }
        }

        if (!system_health::worker_modules_installed() && system_health::node_version() !== null) {
            $result = self::install_dependencies();
            $log[] = $result['output'];
            if ($result['exitcode'] === 0) {
                $changed[] = 'modules';
            }
        }

        if (!self::browser_present() && system_health::worker_modules_installed()) {
            $result = worker_launcher::install_browser(worker_launcher::config_from_settings());
            $log[] = self::tail($result['output']);
            if ($result['exitcode'] === 0) {
                $changed[] = 'browser';
            }
        }

        if (trim((string) get_config($component, 'worker_base_url')) === '') {
            set_config('worker_base_url', $CFG->wwwroot, $component);
            $changed[] = 'baseurl';
        }

        $verified = self::verify();

        return [
            'ok'      => $verified['ok'],
            'changed' => $changed,
            'log'     => array_values(array_filter($log)),
            'steps'   => $verified['steps'],
        ];
    }

    /**
     * A Node binary the web server user can actually run.
     *
     * @return string|null
     */
    public static function find_node(): ?string {
        $candidates = self::NODE_CANDIDATES;

        // The `which` command follows PATH, which may hold a version manager shim
        // that resolves differently for the web server user. It is consulted, but
        // the result is checked like any other candidate.
        $which = @exec('command -v node 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            array_unshift($candidates, trim($which));
        }

        foreach ($candidates as $candidate) {
            if (!is_executable($candidate)) {
                continue;
            }

            $output = [];
            $exit = 0;
            @exec(escapeshellarg($candidate) . ' --version 2>/dev/null', $output, $exit);
            $version = trim((string) ($output[0] ?? ''));
            if (
                $exit === 0 && $version !== ''
                && (int) ltrim(explode('.', $version)[0], 'v') >= system_health::NODE_MAJOR
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Install the worker's npm dependencies.
     *
     * @return array{exitcode: int, output: string}
     */
    public static function install_dependencies(): array {
        global $CFG;

        $node = trim((string) get_config('local_catquizlab', 'worker_node_path'));
        $npm = dirname($node) . '/npm';
        $source = $CFG->dirroot . '/local/catquizlab/worker';
        $dir = worker_launcher::runtime_dir();

        if (!is_executable($npm)) {
            return ['exitcode' => 127, 'output' => get_string('runtime:nonpm', 'local_catquizlab', $npm)];
        }

        // Installed in the dataroot, from the manifest the plugin ships. The
        // plugin directory is replaced on every upgrade; the dataroot is not.
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return ['exitcode' => 1, 'output' => get_string('worker:runtimedirfailed', 'local_catquizlab', $dir)];
        }
        foreach (['package.json', 'package-lock.json'] as $manifest) {
            if (is_readable($source . '/' . $manifest)) {
                copy($source . '/' . $manifest, $dir . '/' . $manifest);
            }
        }

        // The `npm ci` form where a lockfile exists: it installs exactly what was
        // tested, which is the difference between a reproducible worker and one
        // that picked up whatever was newest that morning.
        $subcommand = is_readable($dir . '/package-lock.json') ? 'ci' : 'install';

        $command = 'cd ' . escapeshellarg($dir) . ' && ' . worker_launcher::environment_prefix()
            . ' ' . escapeshellarg($npm) . ' ' . $subcommand . ' --no-audit --no-fund';

        $output = [];
        $exitcode = 0;
        @exec($command . ' 2>&1', $output, $exitcode);

        return ['exitcode' => (int) $exitcode, 'output' => self::tail(implode("\n", $output))];
    }

    /**
     * Whether a browser sits in the cache the worker reads.
     *
     * @return bool
     */
    public static function browser_present(): bool {
        $cache = null;
        foreach (worker_launcher::runtime_environment([]) as $pair) {
            [$name, $value] = explode('=', $pair, 2);
            if ($name === 'PUPPETEER_CACHE_DIR') {
                $cache = $value;
            }
        }

        if ($cache === null || !is_dir($cache . '/chrome')) {
            return false;
        }

        // A directory is not a browser: an interrupted download leaves one
        // behind, and the worker then fails as if nothing were installed.
        foreach (glob($cache . '/chrome/*/chrome-linux*/chrome') ?: [] as $binary) {
            if (is_executable($binary)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last few lines of command output, for a message.
     *
     * @param string $output The full output.
     * @param int $lines How many lines to keep.
     * @return string
     */
    protected static function tail(string $output, int $lines = 4): string {
        $all = array_values(array_filter(explode("\n", trim($output))));

        return implode("\n", array_slice($all, -$lines));
    }

    /**
     * Build one step result.
     *
     * @param string $id Stable identifier.
     * @param string $label What is checked.
     * @param bool $ok Whether it holds.
     * @param string $detail What was found.
     * @return array
     */
    protected static function step(string $id, string $label, bool $ok, string $detail): array {
        return ['id' => $id, 'label' => $label, 'ok' => $ok, 'missing' => !$ok, 'detail' => $detail];
    }
}
