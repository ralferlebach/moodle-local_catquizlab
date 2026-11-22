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
 * sum and at most), the database's connections, the load average and the memory the system still has; since 0.7.71
 * also the CPU busy and I/O wait share since the last sample, the disks' read and written MB (cumulative), the
 * browsers' processes and memory (the workers' Chromium), Apache's busy and idle workers from mod_status
 * (--status-url), and the database sessions that are active and what they wait for. Run beside a load step and stopped
 * after it (or --seconds); cli/load_matrix.sh does both.
 *
 *     php local/catquizlab/cli/load_sampler.php --seconds=600 --interval=2 \
 *         --status-url='http://127.0.0.1/server-status?auto' > samples.jsonl
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params(['seconds' => 600, 'interval' => 2, 'processes' => 'apache2,httpd,php-fpm',
    'browsers' => 'chrome,chromium,headless_shell', 'status-url' => '']);
$browsers = array_filter(array_map('trim', explode(',', (string) $options['browsers'])));
$statusurl = (string) $options['status-url'];
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

/**
 * The CPU's counters from /proc/stat: busy and total jiffies; the share busy follows from two samples.
 *
 * @return array{busy: int, total: int, iowait: int}|null
 */
function local_catquizlab_load_cpu(): ?array {
    $stat = @file_get_contents('/proc/stat');
    if ($stat === false || !preg_match('/^cpu\s+(.*)$/m', $stat, $m)) {
        return null;
    }
    $v = array_map('intval', preg_split('/\s+/', trim($m[1])));
    $idle = ($v[3] ?? 0) + ($v[4] ?? 0);

    return ['busy' => array_sum($v) - $idle, 'total' => array_sum($v), 'iowait' => $v[4] ?? 0];
}

/**
 * Sectors read and written by all block devices (/proc/diskstats), in MB.
 *
 * @return array{readmb: float, writtenmb: float}|null
 */
function local_catquizlab_load_disk(): ?array {
    $lines = @file('/proc/diskstats');
    if ($lines === false) {
        return null;
    }
    $read = 0;
    $written = 0;
    foreach ($lines as $line) {
        $f = preg_split('/\s+/', trim($line));
        // Whole disks and partitions are both listed; loop and ram devices are not disks.
        // Whole disks only: no loop, RAM or mapper devices, no partitions (a trailing digit, except nvme0n1).
        $partition = preg_match('/\d$/', $f[2]) && !preg_match('/^nvme\d+n\d+$/', $f[2]);
        if (count($f) < 10 || preg_match('/^(loop|ram|dm-)/', $f[2]) || $partition) {
            continue;
        }
        $read += (int) $f[5];
        $written += (int) $f[9];
    }

    return ['readmb' => round($read * 512 / 1048576, 1), 'writtenmb' => round($written * 512 / 1048576, 1)];
}

/**
 * Apache's scoreboard from mod_status (?auto): busy and idle workers.
 *
 * @param string $url The status URL, e.g. http://127.0.0.1/server-status?auto; '' for none.
 * @return array{apachebusy: int|null, apacheidle: int|null}
 */
function local_catquizlab_load_apache(string $url): array {
    if ($url === '') {
        return [];
    }
    $context = stream_context_create(['http' => ['timeout' => 2]]);
    $text = @file_get_contents($url, false, $context);
    if ($text === false) {
        return ['apachebusy' => null, 'apacheidle' => null];
    }
    $get = static fn(string $key): ?int => preg_match('/^' . $key . ':\s*(\d+)/m', $text, $m) ? (int) $m[1] : null;

    return ['apachebusy' => $get('BusyWorkers'), 'apacheidle' => $get('IdleWorkers')];
}

/**
 * Database sessions waiting, by wait event type (PostgreSQL), or threads running (MariaDB).
 *
 * @return array
 */
function local_catquizlab_load_dbwaits(): array {
    global $DB;
    try {
        if ($DB->get_dbfamily() === 'postgres') {
            $out = ['dbactive' => 0, 'dbwaiting' => []];
            foreach (
                $DB->get_records_sql("SELECT COALESCE(wait_event_type, 'none') AS k, state, COUNT(1) AS n
                                             FROM pg_stat_activity
                                            WHERE datname = current_database() AND pid <> pg_backend_pid()
                                         GROUP BY COALESCE(wait_event_type, 'none'), state") as $row
            ) {
                if ($row->state === 'active') {
                    $out['dbactive'] += (int) $row->n;
                    if ($row->k !== 'none') {
                        $out['dbwaiting'][$row->k] = ($out['dbwaiting'][$row->k] ?? 0) + (int) $row->n;
                    }
                }
            }
            return $out;
        }
        if ($DB->get_dbfamily() === 'mysql') {
            $row = $DB->get_record_sql("SHOW STATUS LIKE 'Threads_running'");
            return ['dbactive' => $row ? (int) $row->value : null];
        }
    } catch (\Throwable $e) {
        return [];
    }

    return [];
}

$cpu = local_catquizlab_load_cpu();
while (time() < $until) {
    $load = sys_getloadavg() ?: [null];
    $meminfo = @file_get_contents('/proc/meminfo');
    $available = $meminfo !== false && preg_match('/^MemAvailable:\s+(\d+) kB/m', $meminfo, $m) ? round((int) $m[1] / 1024) : null;
    $now = local_catquizlab_load_cpu();
    $cpushare = null;
    $iowaitshare = null;
    if ($cpu && $now && $now['total'] > $cpu['total']) {
        $cpushare = round(100 * ($now['busy'] - $cpu['busy']) / ($now['total'] - $cpu['total']), 1);
        $iowaitshare = round(100 * ($now['iowait'] - $cpu['iowait']) / ($now['total'] - $cpu['total']), 1);
    }
    $cpu = $now;
    $browser = local_catquizlab_load_processes($browsers);
    // The workers live and playing, the limit in force, the safety cap and the load guard (#110 AK-CLARA-04: the
    // throttling and whether the cap was ever passed, sample by sample).
    $workers = [];
    try {
        $capacity = \local_catquizlab\local\worker_capacity::snapshot();
        $workers = ['workerslive' => (int) $capacity['live'], 'workersplaying' => (int) $capacity['playing'],
            'workerlimit' => (int) $capacity['limit'], 'workercap' => (int) $capacity['cap'],
            'throttled' => !empty($capacity['guard']['throttled'])];
    } catch (\Throwable $e) {
        $workers = ['workersunreadable' => $e->getMessage()];
    }
    echo json_encode(['time' => time()] + local_catquizlab_load_processes($names) + $workers + [
        'dbconnections' => local_catquizlab_load_connections(), 'load1' => $load[0], 'memavailablemb' => $available,
        'cpupercent' => $cpushare, 'iowaitpercent' => $iowaitshare,
        'browserprocs' => $browser['webprocs'], 'browserrssmb' => $browser['webrssmb'],
    ] + (local_catquizlab_load_disk() ?? []) + local_catquizlab_load_apache($statusurl) + local_catquizlab_load_dbwaits())
        . "\n";
    sleep($interval);
}
