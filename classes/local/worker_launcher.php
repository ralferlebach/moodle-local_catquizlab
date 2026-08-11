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
            '--token=' . (string) ($config['token'] ?? ''),
        ];
        if (!empty($config['workerid'])) {
            $argv[] = '--worker-id=' . $config['workerid'];
        }
        $maxjobs = (int) ($config['maxjobs'] ?? 0);
        if ($maxjobs > 0) {
            $argv[] = '--max-jobs=' . $maxjobs;
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
        ];
    }

    /**
     * Launch the worker if the exec variant is enabled and configured.
     *
     * @param array $config The worker config (see config_from_settings).
     * @return array|null exitcode and output, or null when not launched.
     */
    public static function launch(array $config): ?array {
        if (empty($config['enabled']) || !self::is_configured($config)) {
            return null;
        }

        $command = implode(' ', array_map('escapeshellarg', self::build_command($config)));
        $output = [];
        $exitcode = 0;
        exec($command . ' 2>&1', $output, $exitcode);

        return ['exitcode' => (int) $exitcode, 'output' => implode("\n", $output)];
    }

    /**
     * Whether the config has everything needed to launch.
     *
     * @param array $config The worker config.
     * @return bool
     */
    protected static function is_configured(array $config): bool {
        return !empty($config['node'])
            && !empty($config['script'])
            && !empty($config['baseurl'])
            && !empty($config['token'])
            && is_readable((string) $config['script']);
    }
}
