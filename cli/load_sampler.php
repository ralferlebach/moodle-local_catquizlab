<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * What the server looks like during a load step, every few seconds (#110, #124, #136).
 *
 * One JSON line per sample: the web server's processes (Apache or PHP-FPM workers: how many, their resident memory in
 * sum and at most), the database's connections, the load average and the memory the system still has. Run beside a
 * load step and stopped after it (or --seconds); cli/load_matrix.sh does both.
 *
 *     php local/catquizlab/cli/load_sampler.php --seconds=600 --interval=2 > samples.jsonl
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params(['seconds' => 600, 'interval' => 2, 'processes' => 'apache2,httpd,php-fpm']);
$names = array_filter(array_map('trim', explode(',', (string) $options['processes'])));
$until = time() + max(1, (int) $options['seconds']);
$interval = max(1, (int) $options['interval']);

/**
 * The web server's processes: count, resident memory in sum and at most (MB).
 *
 * @param string[] $names Process names (the start of /proc/PID/comm).
 * @return array
 */
function local_catquizlab_load_processes(array $names): array {
    $n = 0;
    $sum = 0;
    $max = 0;
    foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
        $comm = @file_get_contents($dir . '/comm');
        if ($comm === false) {
            continue;
        }
        $comm = trim($comm);
        $match = false;
        foreach ($names as $name) {
            $match = $match || strncmp($comm, $name, strlen($name)) === 0;
        }
        if (!$match) {
            continue;
        }
        $status = @file_get_contents($dir . '/status');
        if ($status !== false && preg_match('/^VmRSS:\s+(\d+) kB/m', $status, $m)) {
            $n++;
            $sum += (int) $m[1];
            $max = max($max, (int) $m[1]);
        }
    }

    return ['webprocs' => $n, 'webrssmb' => round($sum / 1024, 1), 'webrssmaxmb' => round($max / 1024, 1)];
}

/**
 * The database's open connections.
 *
 * @return int|null
 */
function local_catquizlab_load_connections(): ?int {
    global $DB;
    try {
        if ($DB->get_dbfamily() === 'postgres') {
            return (int) $DB->get_field_sql('SELECT COUNT(1) FROM pg_stat_activity WHERE datname = current_database()');
        }
        if ($DB->get_dbfamily() === 'mysql') {
            $row = $DB->get_record_sql("SHOW STATUS LIKE 'Threads_connected'");
            return $row ? (int) $row->value : null;
        }
    } catch (\Throwable $e) {
        return null;
    }

    return null;
}

while (time() < $until) {
    $load = sys_getloadavg() ?: [null];
    $meminfo = @file_get_contents('/proc/meminfo');
    $available = $meminfo !== false && preg_match('/^MemAvailable:\s+(\d+) kB/m', $meminfo, $m) ? round((int) $m[1] / 1024) : null;
    echo json_encode(['time' => time()] + local_catquizlab_load_processes($names) + [
        'dbconnections' => local_catquizlab_load_connections(), 'load1' => $load[0], 'memavailablemb' => $available,
    ]) . "\n";
    sleep($interval);
}
