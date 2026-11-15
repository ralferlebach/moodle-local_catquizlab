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
 * No secret in any log the plugin writes, shown on a running site (#149 AK2).
 *
 * Signs in as a temporary manager and sends the plugin's pages requests that carry a probe value in every place a
 * secret can hide: the listed names (sesskey, token, key, password, secret, authorization, email) spelled plainly,
 * encoded, encoded twice, in capitals, as arrays and twice over; an e-mail address under a harmless name; a parameter
 * nobody listed; a line break; a fragment; a POST body. The requests carry the worker's trace header, so the plugin
 * traces them as it traces the worker's own. Then it reads everything the plugin wrote — request traces, worker logs,
 * artefacts, the debug trace and the attempt history in the database — for the probe value. The probe's own trace lines
 * must be there (otherwise nothing was shown) and must not hold it.
 *
 * With --server-log=FILE the web server's log is read too and reported apart: what the server writes of a request is
 * the server's configuration, not the plugin's (docs/dev/log-datenschutz.md).
 *
 *     php local/catquizlab/cli/probe_log_secrets.php [--server-log=/tmp/webserver.log] [--insecure]
 *
 * Exit code 0: the probe value is in no log of the plugin; 3: it is, or the probe left no trace; 1: the probe could
 * not be run.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\acceptance_client;
use local_catquizlab\local\request_trace;

[$options] = cli_get_params(['server-log' => '', 'insecure' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "Sends the plugin requests full of a probe value and shows that no log of the plugin keeps it (#149).\n"
        . "  --server-log=FILE  Also read the web server's log (reported apart).\n  --insecure  Skip TLS checks.\n";
    exit(0);
}
global $CFG, $DB;

$probe = 'CQLPROBE' . strtoupper(bin2hex(random_bytes(6)));
$correlation = 'probe149-' . bin2hex(random_bytes(4));
$since = time() - 1;

// The probe value in every spelling of every listed name, twice each; and what else could carry it.
$names = ['sesskey', 'token', 'key', 'password', 'secret', 'authorization', 'email', 'wstoken', 'MoodleSession'];
// First what the end of a long line could hide: a parameter nobody listed, an e-mail address under a harmless name,
// a line break that would start a forged line.
$pairs = ['unlisted=' . $probe, 'note=' . rawurlencode(strtolower($probe) . '@example.org'), 'x=1%0D%0Asesskey=' . $probe];
foreach ($names as $name) {
    $twice = implode('', array_map(static fn(string $c): string => '%25' . strtoupper(bin2hex($c)), str_split($name)));
    foreach ([$name, strtoupper($name), rawurlencode($name), $twice, $name . '[]', $name . '[0]'] as $spelled) {
        $pairs[] = $spelled . '=' . $probe;
        $pairs[] = $spelled . '=' . rawurlencode($probe . '/x');
    }
}
$query = implode('&', $pairs) . '#token=' . $probe;
$experiment = (int) $DB->get_field_sql('SELECT MIN(id) FROM {local_catquizlab_experiment}');
$targets = [
    ['GET', '/local/catquizlab/results.php?experimentid=' . $experiment . '&tab=overview&' . $query, null],
    ['GET', '/local/catquizlab/runs.php?experimentid=' . $experiment . '&' . $query, null],
    ['GET', '/local/catquizlab/logs.php?hours=1&' . $query, null],
    ['GET', '/local/catquizlab/index.php?' . $query, null],
    ['POST', '/local/catquizlab/results.php?experimentid=' . $experiment . '&' . $query,
        ['action' => 'nosuchaction', 'sesskey' => $probe, 'password' => $probe, 'token' => $probe, 'comment' => $probe]],
];

[$user, $password] = acceptance_client::temporary_user('probe149', true);
$sent = 0;
try {
    $client = new acceptance_client($user->username, $password, (bool) $options['insecure']);
    foreach ($targets as $i => [$method, $path, $post]) {
        $response = $client->get($CFG->wwwroot . $path, $post, [
            request_trace::HEADER_CORRELATION . ': ' . $correlation,
            request_trace::HEADER_ATTEMPT . ': 0.' . ($i + 1),
        ]);
        cli_writeln(sprintf('  %s %s → HTTP %d', $method, strtok($path, '?'), (int) ($response['status'] ?? 0)));
        $sent++;
    }
} catch (\Throwable $e) {
    cli_writeln('The probe could not be sent: ' . $e->getMessage());
    acceptance_client::remove_user($user);
    exit(1);
}
acceptance_client::remove_user($user);
// The traces are written at the end of each request; give the server a moment for the last one.
sleep(2);

// Everything the plugin wrote: its directories in the dataroot, and its log tables.
$found = [];
$ownlines = [];
$dirs = [$CFG->dataroot . '/' . request_trace::DIRECTORY, $CFG->dataroot . '/local_catquizlab'];
$files = 0;
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        // The worker's installation is code, not a log.
        if (!$file->isFile() || strpos($file->getPathname(), '/worker-home/') !== false || $file->getMTime() < $since) {
            continue;
        }
        $files++;
        $handle = fopen($file->getPathname(), 'rb');
        $number = 0;
        while ($handle && ($line = fgets($handle)) !== false) {
            $number++;
            if (stripos($line, $probe) !== false) {
                $found[] = $file->getPathname() . ':' . $number;
            }
            if (strpos($line, $correlation) !== false) {
                $ownlines[] = trim($line);
            }
        }
        if ($handle) {
            fclose($handle);
        }
    }
}
$rows = 0;
$dbman = $DB->get_manager();
foreach (['local_catquizlab_debug' => 'timecreated', 'local_catquizlab_attemptlog' => 'timecreated'] as $table => $time) {
    if (!$dbman->table_exists($table)) {
        continue;
    }
    $columns = array_keys($DB->get_columns($table));
    $since2 = in_array($time, $columns, true) ? $since : 0;
    $records = $DB->get_recordset_select($table, in_array($time, $columns, true) ? $time . ' >= ?' : '1 = 1', [$since2]);
    foreach ($records as $record) {
        $rows++;
        foreach ((array) $record as $column => $value) {
            if (is_string($value) && stripos($value, $probe) !== false) {
                $found[] = $table . '.' . $column . ' (id ' . $record->id . ')';
            }
        }
    }
    $records->close();
}

cli_writeln('Probe ' . $correlation . ': ' . $sent . ' requests; read ' . $files . ' files written since, ' . $rows
    . ' log rows.');
cli_writeln('  trace lines of the probe: ' . count($ownlines));
foreach (array_slice($ownlines, 0, 3) as $line) {
    $decoded = json_decode($line, true);
    $shown = is_array($decoded) ? ($decoded['method'] ?? '') . ' ' . ($decoded['path'] ?? '') : substr($line, 0, 300);
    cli_writeln('    ' . $shown);
}
cli_writeln('  the probe value in the plugin\'s logs: ' . count($found));
foreach (array_slice($found, 0, 20) as $where) {
    cli_writeln('    FOUND: ' . $where);
}
if ($options['server-log'] !== '' && is_readable($options['server-log'])) {
    $count = 0;
    foreach (new SplFileObject($options['server-log']) as $line) {
        $count += stripos((string) $line, $probe) !== false ? 1 : 0;
    }
    cli_writeln('  in the web server\'s own log (its configuration, not the plugin\'s): ' . $count . ' line(s)');
}
if ($ownlines === []) {
    cli_writeln('NOT SHOWN: the probe left no trace line, so the absence of the value proves nothing.');
    exit(3);
}
if ($found !== []) {
    cli_writeln('FAIL: the probe value stands in a log of the plugin.');
    exit(3);
}
cli_writeln('PASS: the probe value is in none of the plugin\'s logs.');
exit(0);
