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

        // What the installation itself needs from the server: somewhere to
        // write, room to write it, and — only while something still has to be
        // downloaded — a way out to the internet. Shown before the button is
        // pressed, so a person learns what to ask their administrator for
        // rather than reading an npm error after two minutes of waiting.
        $needsdownload = !system_health::worker_modules_installed() || !self::browser_present();
        foreach (self::preflight($needsdownload) as $step) {
            $steps[] = $step;
        }

        $missing = [];
        foreach ($steps as $step) {
            if (!$step['ok']) {
                $missing[] = $step['id'];
            }
        }

        return ['ok' => $missing === [], 'steps' => $steps, 'missing' => $missing];
    }

    /** @var int Bytes the dependencies and a browser need, with room to spare. */
    public const DISK_NEEDED = 600 * 1024 * 1024;

    /** @var string A tiny endpoint of the npm registry, answering {} when reachable. */
    public const NPM_PING = 'https://registry.npmjs.org/-/ping';

    /** @var string Where Puppeteer downloads its Chrome from. */
    public const BROWSER_HOST = 'https://storage.googleapis.com/chrome-for-testing-public/';

    /** @var int Seconds a network check is trusted before it is repeated. */
    public const NETWORK_TTL = 600;

    /**
     * What the server must allow before anything can be installed.
     *
     * @param bool $network Whether to check the two download hosts as well.
     * @return array[] Steps, in the same shape as verify()'s.
     */
    public static function preflight(bool $network = true): array {
        $component = 'local_catquizlab';
        $steps = [];
        $user = self::process_user();

        // Every directory the installation and the worker write to, created
        // if missing and then actually written to. is_writable() alone answers
        // for the permission bits and not for a full disk, a read-only mount or
        // an ACL, all of which fail the same way later and less legibly.
        $unwritable = [];
        foreach (self::runtime_directories() as $dir) {
            if (!self::can_write($dir)) {
                $unwritable[] = $dir;
            }
        }
        $steps[] = self::step(
            'storage',
            get_string('preflight:storage', $component),
            $unwritable === [],
            $unwritable === []
                ? get_string('preflight:storageok', $component, $user)
                : get_string('preflight:storagefailed', $component, (object) [
                    'user' => $user,
                    'dirs' => implode(', ', $unwritable),
                    'root' => dirname($unwritable[0]),
                ])
        );

        $free = @disk_free_space(worker_launcher::runtime_dir() === '' ? '/' : dirname(worker_launcher::runtime_dir()));
        $enough = $free === false || $free >= self::DISK_NEEDED;
        $steps[] = self::step(
            'diskspace',
            get_string('preflight:diskspace', $component),
            $enough,
            $free === false
                ? get_string('preflight:diskspaceunknown', $component)
                : get_string($enough ? 'preflight:diskspaceok' : 'preflight:diskspacefailed', $component, (object) [
                    'free'   => display_size((int) $free),
                    'needed' => display_size(self::DISK_NEEDED),
                ])
        );

        if ($network) {
            $hosts = [
                'npmregistry'     => [self::NPM_PING, 'preflight:npmregistry'],
                'browserdownload' => [self::BROWSER_HOST, 'preflight:browserdownload'],
            ];
            foreach ($hosts as $id => [$url, $label]) {
                $reach = self::reachable($url);
                $steps[] = self::step(
                    $id,
                    get_string($label, $component),
                    $reach['ok'],
                    $reach['ok']
                        ? get_string('preflight:reachable', $component, (object) [
                            'host' => parse_url($url, PHP_URL_HOST),
                            'via'  => self::proxy_url() === '' ? get_string('preflight:direct', $component)
                                : get_string('preflight:viaproxy', $component, self::proxy_display()),
                        ])
                        : get_string('preflight:unreachable', $component, (object) [
                            'host'  => parse_url($url, PHP_URL_HOST),
                            'error' => $reach['error'],
                            'hint'  => self::proxy_url() === ''
                                ? get_string('preflight:hintnoproxy', $component)
                                : get_string('preflight:hintproxy', $component, self::proxy_display()),
                        ])
                );
            }
        }

        return $steps;
    }

    /**
     * Whether a host answers at all, through Moodle's own HTTP client.
     *
     * Moodle's client, so that the site's proxy settings apply exactly as they
     * will for npm and the browser download. Any HTTP answer counts, a 403
     * included: the question is whether the network lets us through, not
     * whether this particular path serves a page.
     *
     * @param string $url The URL.
     * @return array{ok: bool, error: string}
     */
    public static function reachable(string $url): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $key = 'preflight_' . md5($url);
        $cached = json_decode((string) get_config('local_catquizlab', $key), true);
        if (is_array($cached) && (int) ($cached['at'] ?? 0) > time() - self::NETWORK_TTL) {
            return ['ok' => (bool) $cached['ok'], 'error' => (string) $cached['error']];
        }

        $curl = new \curl(['ignoresecurity' => true]);
        $curl->head($url, ['CURLOPT_TIMEOUT' => 10, 'CURLOPT_CONNECTTIMEOUT' => 8]);
        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);
        $errno = (int) $curl->get_errno();

        $ok = $errno === 0 && $status > 0;
        $error = $ok ? '' : ($curl->error !== '' ? $curl->error : 'HTTP ' . $status);

        set_config($key, json_encode(['ok' => $ok, 'error' => $error, 'at' => time()]), 'local_catquizlab');

        return ['ok' => $ok, 'error' => $error];
    }

    /**
     * Forget cached network answers, so the next check asks again.
     *
     * @return void
     */
    public static function forget_network_checks(): void {
        foreach ([self::NPM_PING, self::BROWSER_HOST] as $url) {
            unset_config('preflight_' . md5($url), 'local_catquizlab');
        }
    }

    /**
     * The directories the installation and the worker write to.
     *
     * @return string[]
     */
    public static function runtime_directories(): array {
        global $CFG;

        $home = trim((string) get_config('local_catquizlab', 'worker_home'));
        if ($home === '') {
            $home = $CFG->dataroot . '/local_catquizlab/worker-home';
        }
        $cache = trim((string) get_config('local_catquizlab', 'worker_cache_dir'));
        if ($cache === '') {
            $cache = $home . '/.cache/puppeteer';
        }

        return array_values(array_unique([
            worker_launcher::runtime_dir(),
            $home,
            $cache,
            $CFG->dataroot . '/local_catquizlab/worker-logs',
        ]));
    }

    /**
     * Whether this process can create a file in a directory.
     *
     * @param string $dir The directory, created if it does not exist.
     * @return bool
     */
    protected static function can_write(string $dir): bool {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }

        $probe = $dir . '/.catquizlab-write-test-' . getmypid();
        if (@file_put_contents($probe, 'x') !== 1) {
            return false;
        }
        @unlink($probe);

        return true;
    }

    /**
     * The operating-system user this PHP process runs as.
     *
     * Named in every message about permissions, because "the web server user"
     * is not something an administrator can type into chown.
     *
     * @return string
     */
    public static function process_user(): string {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        $name = (string) @get_current_user();

        return $name !== '' ? $name : 'www-data';
    }

    /**
     * Moodle's proxy, as a URL npm and the browser download understand.
     *
     * @return string Empty when the site uses no proxy.
     */
    public static function proxy_url(): string {
        global $CFG;

        $host = trim((string) ($CFG->proxyhost ?? ''));
        if ($host === '') {
            return '';
        }

        $port = (int) ($CFG->proxyport ?? 0);
        $auth = '';
        if (trim((string) ($CFG->proxyuser ?? '')) !== '') {
            $auth = rawurlencode((string) $CFG->proxyuser) . ':' . rawurlencode((string) ($CFG->proxypassword ?? '')) . '@';
        }
        $scheme = (($CFG->proxytype ?? 'HTTP') === 'SOCKS5') ? 'socks5' : 'http';

        return $scheme . '://' . $auth . $host . ($port > 0 ? ':' . $port : '');
    }

    /**
     * The proxy as it may be shown: host and port, never the password.
     *
     * @return string
     */
    protected static function proxy_display(): string {
        global $CFG;

        $port = (int) ($CFG->proxyport ?? 0);

        return trim((string) ($CFG->proxyhost ?? '')) . ($port > 0 ? ':' . $port : '');
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

        // The server's side first, asked fresh. Starting an npm install that
        // cannot write or cannot reach the registry costs minutes and ends in
        // an error written for npm's developers, not for the person who
        // pressed the button.
        self::forget_network_checks();
        $needsdownload = !system_health::worker_modules_installed() || !self::browser_present();
        $blocked = array_filter(self::preflight($needsdownload), static function (array $step): bool {
            return empty($step['ok']);
        });
        if ($blocked !== []) {
            $verified = self::verify();

            return [
                'ok'      => false,
                'changed' => [],
                'log'     => array_values(array_map(static function (array $step): string {
                    return $step['label'] . ': ' . $step['detail'];
                }, $blocked)),
                'steps'   => $verified['steps'],
            ];
        }

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
        $source = $CFG->dirroot . '/local/catquizlab/worker';
        $dir = worker_launcher::runtime_dir();

        $npm = self::find_npm($node);
        if ($npm === null) {
            return ['exitcode' => 127, 'output' => get_string('runtime:nonpm', 'local_catquizlab', dirname($node) . '/npm')];
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
     * The npm that belongs to a Node binary, or any npm on the path.
     *
     * Beside the binary first, because that is the npm built for that Node.
     * Then the path: distribution packages put node and npm in different
     * packages, and a node without an npm beside it is the normal case on
     * Ubuntu, not a broken installation.
     *
     * @param string $node The Node binary.
     * @return string|null
     */
    protected static function find_npm(string $node): ?string {
        $candidates = [dirname($node) . '/npm', '/usr/bin/npm', '/usr/local/bin/npm'];

        $which = @exec('command -v npm 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            $candidates[] = trim($which);
        }

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
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
