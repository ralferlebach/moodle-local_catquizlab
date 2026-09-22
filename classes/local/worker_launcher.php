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
 * Worker launcher: run the Puppeteer worker on this host (E3.2 exec variant).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Launches the Puppeteer worker locally to drain the attempt queue (E3.2).
 *
 * This is the exec variant of the worker binding (the queue-polling variant is
 * the job_claim / job_complete web services). {@see self::build_command()} turns
 * a config into the worker's argument vector — pure and testable.
 * {@see self::launch()} runs it, but only when the exec worker is enabled and
 * fully configured and the script is readable, so it never runs during CI.
 */
class worker_launcher {
    /**
     * Build the worker command argument vector from a config.
     *
     * @param array $config node, script, baseurl, token, workerid, maxjobs.
     * @return string[] The argument vector.
     */
    public static function build_command(array $config): array {
        $argv = [
            (string) ($config['node'] ?? 'node'),
            (string) ($config['script'] ?? ''),
            '--base-url=' . (string) ($config['baseurl'] ?? ''),
            // The token is NOT here: see runtime_environment(). Command-line
            // arguments are visible in process listings, monitoring output and
            // crash reports, and this one opens every web service function the
            // worker is allowed to call.
        ];
        if (!empty($config['workerid'])) {
            $argv[] = '--worker-id=' . $config['workerid'];
        }
        $maxjobs = (int) ($config['maxjobs'] ?? 0);
        if ($maxjobs > 0) {
            $argv[] = '--max-jobs=' . $maxjobs;
        }
        if (!empty($config['loginmode'])) {
            $argv[] = '--login-mode=' . $config['loginmode'];
        }
        if (!empty($config['loginurltemplate'])) {
            $argv[] = '--login-url-template=' . $config['loginurltemplate'];
        }
        if (($config['loginsuffix'] ?? '') !== '') {
            $argv[] = '--login-suffix=' . $config['loginsuffix'];
        }
        return $argv;
    }

    /**
     * Read the worker config from the plugin settings.
     *
     * @return array
     */
    public static function config_from_settings(): array {
        global $CFG;

        return [
            'enabled' => (bool) get_config('local_catquizlab', 'worker_exec_enabled'),
            'node'    => (string) get_config('local_catquizlab', 'worker_node_path'),
            'script'  => $CFG->dirroot . '/local/catquizlab/worker/run_attempt.js',
            'baseurl' => (string) get_config('local_catquizlab', 'worker_base_url'),
            'token'   => (string) get_config('local_catquizlab', 'worker_token'),
            'maxjobs' => (int) get_config('local_catquizlab', 'worker_max_jobs'),
            'workerid' => 'catquizlab-exec',
            'loginmode' => (string) get_config('local_catquizlab', 'worker_login_mode'),
            'loginurltemplate' => (string) get_config('local_catquizlab', 'worker_login_url_template'),
            'loginsuffix' => (string) get_config('local_catquizlab', 'worker_login_suffix'),
            'concurrency' => max(1, (int) get_config('local_catquizlab', 'worker_concurrency')),
        ];
    }

    /**
     * Launch the worker if the exec variant is enabled and configured.
     *
     * @param array $config The worker config (see config_from_settings).
     * @return array|null exitcode and output, or null when not launched.
     */
    /**
     * Run the worker's self-test the way the worker itself will be run.
     *
     * The point is the context, not the checks: the reported installation had a
     * self-test that passed as the interactive user and a worker that could not
     * find Chrome as the web server user. Running it from here — same process
     * owner, same environment, same binary — is what makes the two comparable.
     *
     * @param array $config The worker configuration.
     * @return array{exitcode: int, output: string, command: string}
     */
    public static function self_test(array $config): array {
        $node = (string) ($config['node'] ?? get_config('local_catquizlab', 'worker_node_path'));
        $script = (string) ($config['script'] ?? ($GLOBALS['CFG']->dirroot . '/local/catquizlab/worker/run_attempt.js'));

        $command = self::command_with_environment($config, [$node, $script, '--self-test']);

        $output = [];
        $exitcode = 0;
        @exec($command . ' 2>&1', $output, $exitcode);

        return [
            'exitcode' => (int) $exitcode,
            'output'   => implode("\n", $output),
            // Shown so the same command can be repeated in a shell when the
            // result needs to be taken further.
            'command'  => $command,
        ];
    }

    /**
     * Install the browser into the cache the worker will actually read.
     *
     * Puppeteer resolves its cache from the runtime of whoever runs it, so a
     * browser installed by the interactive user is invisible to the web server
     * user — which is the whole of the reported defect. Installing through the
     * same environment the worker gets puts it where the worker looks, and
     * doing it from the interface keeps an operator out of a shell for what is
     * a one-line command with one easily-missed precondition.
     *
     * @param array $config The worker configuration.
     * @return array{exitcode: int, output: string, command: string}
     */
    public static function install_browser(array $config): array {
        global $CFG;

        $node = (string) ($config['node'] ?? get_config('local_catquizlab', 'worker_node_path'));

        // Puppeteer's own command line, from the packages npm just installed,
        // run by Node directly. This used npx found beside the Node binary: it
        // failed the same way npm did when npx was a link into somebody's home
        // directory, and where it worked it downloaded Puppeteer a second time
        // because it ran in the plugin directory, where no packages are.
        $cli = self::runtime_dir() . '/node_modules/puppeteer/lib/cjs/puppeteer/node/cli.js';
        if (!is_readable($cli)) {
            $cli = $CFG->dirroot . '/local/catquizlab/worker/node_modules/puppeteer/lib/cjs/puppeteer/node/cli.js';
        }
        if (!is_readable($cli)) {
            return [
                'exitcode' => 127,
                'output'   => get_string('runtime:nopuppeteercli', 'local_catquizlab'),
                'command'  => '',
            ];
        }

        $argv = [$node, $cli, 'browsers', 'install', 'chrome'];
        $command = 'cd ' . escapeshellarg(self::runtime_dir()) . ' && '
            . self::command_with_environment($config, $argv);

        $output = [];
        $exitcode = 0;
        @exec($command . ' 2>&1', $output, $exitcode);

        return [
            'exitcode' => (int) $exitcode,
            'output'   => implode("\n", $output),
            'command'  => $command,
        ];
    }

    /**
     * One shell command, with the browser's environment in front of it.
     *
     * @param array $config The worker configuration.
     * @param string[] $argv The argument vector.
     * @return string
     */
    protected static function command_with_environment(array $config, array $argv): string {
        return self::environment_prefix($config) . ' ' . implode(' ', array_map('escapeshellarg', $argv));
    }

    /**
     * The `env …` prefix that puts a command into the worker's runtime.
     *
     * Shared with the dependency installer: npm and the browser download must
     * write into the same cache the worker reads, or they install something
     * nobody will find.
     *
     * @param array $config The worker configuration.
     * @return string
     */
    public static function environment_prefix(array $config = []): string {
        $env = array_map('escapeshellarg', self::runtime_environment($config));

        // The token is deliberately absent from this string. Putting it in an
        // `env NAME=value` prefix would only move it from the worker's argv to
        // env's own, which is just as visible in a process listing. It is
        // exported into this process instead, and the child inherits it —
        // /proc/<pid>/environ is readable by the owner and root, argv by
        // anyone.
        self::export_token($config);

        return 'env ' . implode(' ', $env);
    }

    /**
     * Where a worker's output is kept.
     *
     * Under the plugin's own directory in dataroot, one file per worker, so a
     * restarted worker appends to its own history rather than to a shared file
     * nobody can untangle.
     *
     * @param string $workerid The worker instance.
     * @return string
     */
    public static function log_path(string $workerid): string {
        global $CFG;

        $dir = $CFG->dataroot . '/local_catquizlab/worker-logs';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            // Falling back rather than failing the launch: a worker without a
            // log is worse off than one with, and far better than none at all.
            return '/dev/null';
        }

        return $dir . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $workerid) . '.log';
    }

    /**
     * The tail of a worker's log, for the interface.
     *
     * @param string $workerid The worker instance.
     * @param int $lines How many lines to return.
     * @return string
     */
    public static function log_tail(string $workerid, int $lines = 40): string {
        $path = self::log_path($workerid);
        if ($path === '/dev/null' || !is_readable($path)) {
            return '';
        }

        // A worker that has just started has a log file and nothing in it, and
        // that is the normal case for the first seconds of every run — not an
        // edge case. Reading zero bytes from it threw, and the exception took
        // the whole operations page with it: the one place somebody looks when
        // a worker is not behaving.
        // The file is written by a different process while this one reads it,
        // so PHP's cached stat data can be older than the file. Without this
        // the log of a worker that has just written its first lines still looks
        // empty — which is exactly when somebody is looking at it.
        clearstatcache(true, $path);

        $size = (int) filesize($path);
        if ($size <= 0) {
            return '';
        }

        // Read the end rather than the file: these grow for as long as a worker
        // runs, and the interesting part is always the last thing said.
        $window = min($size, 64 * 1024);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        fseek($handle, -$window, SEEK_END);
        $text = (string) fread($handle, $window);
        fclose($handle);

        $all = array_values(array_filter(explode("\n", trim($text))));

        return implode("\n", array_slice($all, -$lines));
    }

    /**
     * Put the worker token into this process's environment, for the child.
     *
     * @param array $config The worker configuration.
     * @return void
     */
    protected static function export_token(array $config): void {
        $token = (string) ($config['token'] ?? get_config('local_catquizlab', 'worker_token'));
        if ($token !== '') {
            putenv('CATQUIZLAB_WORKER_TOKEN=' . $token);
        }
    }

    /**
     * The environment a headless browser needs to start at all.
     *
     * Puppeteer resolves its cache from the runtime environment of the unix
     * user that runs it. A worker started by hand runs as the interactive user
     * and finds the browser; the same worker started from cron runs as the web
     * server user, whose HOME may not exist, and reports that Chrome cannot be
     * found — after a self-test that passed. Chrome then needs XDG paths for
     * its crash handler, which fails with "--database is required" when they
     * point nowhere writable.
     *
     * Setting these explicitly makes the two contexts the same one. The
     * directories are created here rather than assumed, because the failure
     * they cause otherwise (EACCES on mkdir) reads as a permissions problem
     * with the plugin.
     *
     * @param array $config The worker configuration.
     * @return string[] name=value pairs for the command prefix.
     */
    public static function runtime_environment(array $config): array {
        global $CFG;

        $home = trim((string) ($config['home'] ?? get_config('local_catquizlab', 'worker_home')));
        if ($home === '') {
            // Under the plugin's own data directory: it belongs to the web
            // server user by construction, which is the user that will run the
            // worker from cron.
            $home = $CFG->dataroot . '/local_catquizlab/worker-home';
        }

        $cache = trim((string) get_config('local_catquizlab', 'worker_cache_dir'));
        if ($cache === '') {
            $cache = $home . '/.cache/puppeteer';
        }

        // Moodle's own directory permissions, not 0777: these hold a browser
        // profile and its cache, which nobody but the web server user has any
        // business reading. And no error suppression — a runtime directory that
        // cannot be created surfaces later as a Chrome or Puppeteer error, and
        // the reader then debugs the browser instead of the file system.
        foreach ([$home, $cache, $home . '/.config', $home . '/.local/share'] as $dir) {
            if (is_dir($dir)) {
                continue;
            }

            // 0700 directly rather than through make_writable_directory(),
            // for two reasons. It uses $CFG->directorypermissions, which
            // defaults to 0777 across a dataroot — reasonable for files a site
            // serves, not for a browser profile with its cookies and cache. And
            // it reports a failure through debugging(), which is a diagnostic
            // channel: the caller then has both a debugging message and, from
            // the line below, an exception saying the same thing twice.
            //
            // The warning is suppressed and immediately replaced by an
            // exception carrying the path, so nothing is swallowed — the
            // failure arrives where it happened, with the information needed to
            // act on it.
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \moodle_exception(
                    'worker:runtimedirfailed',
                    'local_catquizlab',
                    '',
                    $dir
                );
            }
        }

        // Existing directories are checked too: one created once by the wrong
        // user stays unusable, and that is exactly the case that produced an
        // EACCES from inside Puppeteer rather than from here.
        foreach ([$home, $cache] as $dir) {
            if (!is_writable($dir)) {
                throw new \moodle_exception(
                    'worker:runtimedirunwritable',
                    'local_catquizlab',
                    '',
                    $dir
                );
            }
        }

        $environment = [];

        // Moodle's proxy, for npm and for Puppeteer's browser download. Without
        // it a site behind a proxy — the normal case at a university — could
        // reach the internet from Moodle and not from the installation it
        // started, and the failure read as npm's network error.
        $proxy = worker_runtime::proxy_url();
        if ($proxy !== '') {
            $names = ['HTTPS_PROXY', 'HTTP_PROXY', 'https_proxy', 'http_proxy', 'npm_config_proxy', 'npm_config_https_proxy'];
            foreach ($names as $name) {
                $environment[] = $name . '=' . $proxy;
            }
            $bypass = trim((string) ($CFG->proxybypass ?? ''));
            if ($bypass !== '') {
                $environment[] = 'NO_PROXY=' . $bypass;
                $environment[] = 'no_proxy=' . $bypass;
            }
        }

        $environment = array_merge($environment, [
            'HOME=' . $home,
            'PUPPETEER_CACHE_DIR=' . $cache,
            // Where the worker's dependencies live: in the dataroot, which
            // survives a plugin upgrade. In the plugin directory they did not —
            // every upgrade replaced the folder, the modules vanished, and an
            // installation that was ready on Friday was not on Monday. Node
            // falls back to NODE_PATH for anything it cannot find beside the
            // script, so the script itself needs no change.
            'NODE_PATH=' . self::runtime_dir() . '/node_modules',
            'XDG_CACHE_HOME=' . $home . '/.cache',
            'XDG_CONFIG_HOME=' . $home . '/.config',
            'XDG_DATA_HOME=' . $home . '/.local/share',
        ]);

        return $environment;
    }

    /**
     * The directory the worker's dependencies are installed in.
     *
     * @return string
     */
    public static function runtime_dir(): string {
        global $CFG;

        return $CFG->dataroot . '/local_catquizlab/worker-runtime';
    }

    /**
     * Run one worker in the foreground and return what it reported.
     *
     * @param array $config The worker configuration.
     * @return array{exitcode: int, output: string}|null Null when not configured.
     */
    public static function launch(array $config): ?array {
        if (empty($config['enabled']) || !self::is_configured($config)) {
            return null;
        }

        $command = self::command_with_environment($config, self::build_command($config));
        $output = [];
        $exitcode = 0;
        exec($command . ' 2>&1', $output, $exitcode);

        return ['exitcode' => (int) $exitcode, 'output' => implode("\n", $output)];
    }

    /**
     * Distinct worker ids for a pool of the given size.
     *
     * @param string $base The base worker id.
     * @param int $concurrency The pool size (at least 1).
     * @return string[]
     */
    public static function worker_ids(string $base, int $concurrency): array {
        $concurrency = max(1, $concurrency);
        if ($concurrency === 1) {
            return [$base];
        }
        $ids = [];
        for ($i = 1; $i <= $concurrency; $i++) {
            $ids[] = $base . '-' . $i;
        }
        return $ids;
    }

    /**
     * Launch a pool of workers that drain the queue in parallel.
     *
     * All but the last worker are started in the background; the last runs in the
     * foreground so its exit code is captured. Returns null when disabled or not
     * configured, so it is a safe no-op in CI.
     *
     * @param array $config The worker configuration (includes 'concurrency').
     * @return array|null ['launched' => int, 'exitcode' => int, 'output' => string]
     */
    public static function launch_pool(array $config): ?array {
        // Never a silent null. The tick called this every five minutes for
        // fifteen minutes on an installation whose worker switch was off, got
        // null back, printed nothing, and the page said "stalled" with no word
        // about what was missing.
        $missing = self::missing($config);
        if ($missing !== []) {
            return [
                'launched' => 0,
                'skipped'  => 0,
                'reason'   => 'not-configured: ' . implode(', ', $missing),
                'failures' => [],
                'exitcode' => 0,
                'output'   => '',
            ];
        }

        $concurrency = max(1, (int) ($config['concurrency'] ?? 1));
        $prefix = (string) ($config['workerid'] ?? 'catquizlab-exec');

        // Work first, workers second. Starting a worker with nothing to claim
        // costs a Node and a Chrome process, shows "workers running" beside 0%
        // progress, and ends as an apparent crash when the process exits having
        // found nothing — three misleading signals for no benefit, multiplied
        // by the configured concurrency.
        $breakdown = attempt_scheduler::queue_breakdown();
        if ($breakdown['claimable'] === 0) {
            return [
                'launched' => 0,
                'skipped'  => $concurrency,
                'reason'   => 'no-claimable-work',
                'exitcode' => 0,
                'output'   => '',
            ];
        }

        // And no more workers than there is work for: four workers for two
        // attempts means two processes that start, find nothing and exit.
        $concurrency = min($concurrency, $breakdown['claimable']);

        // Only the slots nobody holds. Before this every dispatch started the
        // configured number of workers again, so a site limited to one job at a
        // time accumulated workers with each scheduler tick and claimed several
        // attempts in parallel — exactly what the limit was set to prevent.
        $free = worker_registry::free_slots($concurrency);
        if ($free === []) {
            return [
                'launched' => 0,
                'skipped'  => $concurrency,
                'reason'   => 'all-slots-busy',
                'exitcode' => 0,
                'output'   => '',
            ];
        }

        $launched = 0;
        $failures = [];
        foreach ($free as $slot) {
            $workerid = $prefix . '-' . $slot;

            // The slot is taken before the process starts, not after. Starting
            // first would leave a window in which a second dispatch sees the
            // slot free and starts a second worker for it.
            if (worker_registry::acquire_slot($slot, $workerid) === null) {
                continue;
            }

            $command = self::command_with_environment(
                $config,
                self::build_command(['workerid' => $workerid] + $config)
            );

            // Detached: the caller must not wait for a worker that plays
            // attempts for minutes. A blocking exec() is also why interrupting
            // the caller used to leave a claimed attempt with nobody to finish
            // it and nothing recording that the worker was gone.
            //
            // Output goes to a per-worker log rather than /dev/null. A worker
            // that dies on startup wrote its reason to stderr and it went
            // nowhere: the registry then showed a slot taken by a process that
            // no longer existed, with nothing to say why.
            $log = self::log_path($workerid);
            exec($command . ' >> ' . escapeshellarg($log) . ' 2>&1 &');

            // A successful exec() of a backgrounded command means the shell was
            // asked to start something. It does not mean Node ran, that
            // Puppeteer found a browser, or that the worker reached Moodle —
            // and counting it as a launch is why "workers started: 1" appeared
            // beside "250 claimable, 0 in progress".
            //
            // So the worker has to say so itself. It registers a heartbeat as
            // soon as it is up; until that arrives, nothing was started.
            $handshake = self::await_handshake($workerid);
            if ($handshake['ok']) {
                $launched++;
                continue;
            }

            // It never reported. Release the slot it was holding, and keep what
            // it wrote on the way down: a worker that dies on startup has
            // already said why, and throwing that away leaves a registry entry
            // for a process that never existed.
            worker_registry::release($workerid);
            $failures[] = [
                'workerid' => $workerid,
                'reason'   => $handshake['reason'],
                'output'   => self::log_tail($workerid, 400),
            ];
        }

        $reason = '';
        if ($launched === 0) {
            $reason = $failures !== [] ? 'no-handshake' : 'no-slot-acquired';
        }

        return [
            'launched' => $launched,
            'skipped'  => $concurrency - $launched,
            'reason'   => $reason,
            'failures' => $failures,
            'exitcode' => 0,
            'output'   => $failures === [] ? '' : (string) ($failures[0]['output'] ?? ''),
        ];
    }

    /**
     * Wait for a worker to report that it is actually up.
     *
     * Short by design: a worker that is going to start does so in a second or
     * two, and one that is going to fail has usually failed by then. Waiting
     * longer would make the button feel broken for the case it is meant to
     * diagnose.
     *
     * @param string $workerid The worker.
     * @param float $seconds How long to wait.
     * @return array{ok: bool, reason: string, waited: float}
     */
    protected static function await_handshake(string $workerid, float $seconds = 8.0): array {
        $started = microtime(true);
        $deadline = $started + $seconds;

        while (microtime(true) < $deadline) {
            // 200ms: short enough that a fast worker is not kept waiting, long
            // enough that this is not a busy loop against the database.
            usleep(200000);

            if (worker_registry::has_reported($workerid)) {
                return ['ok' => true, 'reason' => '', 'waited' => microtime(true) - $started];
            }
        }

        return ['ok' => false, 'reason' => 'no-heartbeat', 'waited' => microtime(true) - $started];
    }

    /**
     * Whether the config has everything needed to launch.
     *
     * @param array $config The worker config.
     * @return bool
     */
    protected static function is_configured(array $config): bool {
        return self::missing($config) === [];
    }

    /**
     * What a launch would need and does not have, by name.
     *
     * @param array $config The launch configuration.
     * @return string[]
     */
    public static function missing(array $config): array {
        $missing = [];
        if (empty($config['enabled'])) {
            $missing[] = 'worker_exec_enabled';
        }
        if (empty($config['node'])) {
            $missing[] = 'worker_node_path';
        }
        if (empty($config['baseurl'])) {
            $missing[] = 'worker_base_url';
        }
        if (empty($config['token'])) {
            $missing[] = 'worker_token';
        }
        if (empty($config['script']) || !is_readable((string) $config['script'])) {
            $missing[] = 'worker script';
        }

        return $missing;
    }
}
