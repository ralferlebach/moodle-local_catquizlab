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
 * Acceptance of every download family over HTTP, on the server it runs on (#121, DoD6).
 *
 * Logs in through the site's own web server — as a temporary user with the manager role, and as a temporary user
 * without any role — and fetches every kind of file the plugin hands out: the result exports of every level as CSV,
 * JSON and metadata, for one experiment, for all experiments and for an empty selection; the reproduction package;
 * a plot as PDF and as SVG; the log as text and as JSON; a run's artefact ZIP. For each it records the HTTP status,
 * the media type, the file name, the size, a SHA-256 checksum and whether the content is what its type promises
 * (CSV header and column counts, JSON with metadata, columns and rows, PDF, ZIP); for the user without a role, that no file
 * was handed out at all. The temporary users are deleted at the end.
 *
 *     sudo -u www-data php local/catquizlab/cli/accept_exports.php --experimentid=12 > /root/121-accept.txt
 *     sudo -u www-data php local/catquizlab/cli/accept_exports.php --experimentid=12 --json > /root/121-accept.json
 *
 * Needs manual authentication enabled and the site reachable from the server itself at its wwwroot. Writes nothing
 * but the two temporary users and their role assignment; prints no session key, password or cookie.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\acceptance_client;
use local_catquizlab\local\results_export;

// Query strings and form bodies with a plain "&": Moodle sets arg_separator.output to "&amp;" for its pages, and a request
// built with it loses every parameter after the first — the login, for one, then fails on its token.
ini_set('arg_separator.output', '&');

[$options] = cli_get_params(['experimentid' => 0, 'runid' => 0, 'json' => false, 'keepusers' => false,
    'insecure' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int) $options['experimentid'] <= 0) {
    echo "HTTP acceptance of every download (#121). --experimentid=N [--runid=N] [--json] [--insecure]\n";
    exit($options['help'] ? 0 : 1);
}
global $DB, $CFG;
$experimentid = (int) $options['experimentid'];
$runid = (int) $options['runid'] ?: (int) $DB->get_field_sql(
    'SELECT MIN(id) FROM {local_catquizlab_run} WHERE experimentid = ?',
    [$experimentid]
);
if (!is_enabled_auth('manual')) {
    cli_error('Manual authentication is not enabled: the temporary users cannot log in.');
}

/**
 * What a response holds, checked against what its family promises.
 *
 * @param array $response The response.
 * @param string $kind csv, json, meta, package, logjson, pdf, svg, zip, txt.
 * @param string[] $columns For CSV: the expected header.
 * @return array
 */
function local_catquizlab_accept_check(array $response, string $kind, array $columns = []): array {
    $body = $response['body'];
    $file = (bool) preg_match('/attachment|filename=/i', $response['disposition']) || $kind === 'svg';
    $ok = $response['status'] === 200 && $file;
    $detail = '';
    if ($ok && $kind === 'csv') {
        $lines = array_values(array_filter(preg_split('/\r?\n/', $body), static fn($l) => $l !== ''));
        $header = str_getcsv((string) ($lines[0] ?? ''));
        $widths = array_unique(array_map(static fn($l) => count(str_getcsv($l)), array_slice($lines, 0, 2000)));
        $ok = $header === $columns || ($columns === [] && $header !== []);
        $ok = $ok && count($widths) <= 1;
        $detail = 'rows=' . max(0, count($lines) - 1) . ' columns=' . count($header);
    } else if ($ok && in_array($kind, ['json', 'meta', 'package', 'logjson'], true)) {
        $data = json_decode($body, true);
        $keys = ['json' => ['metadata', 'columns', 'rows'], 'meta' => ['level', 'rows', 'columns'],
            'package' => ['experiment', 'versions', 'runs'],
            'logjson' => ['generated', 'lines']][$kind];
        $ok = is_array($data) && array_diff($keys, array_keys($data)) === [];
        $detail = !is_array($data) ? 'not JSON: ' . json_last_error_msg() : ($ok ? '' : 'missing ' . implode(
            ',',
            array_diff($keys, array_keys($data))
        ) . '; has ' . implode(',', array_slice(array_keys($data), 0, 8)));
        if ($ok && $kind === 'json') {
            // The file says how many rows it holds; it must hold them.
            $said = $data['metadata']['rows'] ?? null;
            $ok = $said === null || (int) $said === count((array) $data['rows']);
            $detail = 'rows=' . count((array) $data['rows']) . ($said === null ? '' : ' metadata.rows=' . $said);
        } else if ($ok && $kind === 'meta') {
            $detail = 'rows=' . $data['rows'] . ' columns=' . count((array) $data['columns']);
        } else if ($ok && $kind === 'package') {
            $detail = 'runs=' . count((array) $data['runs']);
        } else if ($ok && $kind === 'logjson') {
            $detail = 'lines=' . count((array) $data['lines']);
        }
    } else if ($ok && $kind === 'pdf') {
        $ok = strncmp($body, '%PDF-', 5) === 0 && strpos(substr($body, -64), '%%EOF') !== false;
    } else if ($ok && $kind === 'zip') {
        $path = make_request_directory() . '/a.zip';
        file_put_contents($path, $body);
        $zip = new ZipArchive();
        $ok = $zip->open($path) === true;
        $detail = $ok ? 'entries=' . $zip->numFiles : '';
    } else if ($ok && $kind === 'svg') {
        $ok = strpos($body, '<svg') === 0 && strpos($body, '</svg>') !== false;
    } else if ($ok && $kind === 'txt') {
        $ok = mb_check_encoding($body, 'UTF-8');
        $detail = 'lines=' . substr_count($body, "\n");
    }
    $leak = (bool) preg_match('/sesskey=(?!\[redacted\])\w{6,}|wstoken=(?!\[redacted\])\w{6,}/i', $body);
    return ['http' => $response['status'], 'type' => strtok($response['type'], ';'), 'file' => $file,
        'name' => preg_match('/filename="?([^";]+)/i', $response['disposition'], $m) ? $m[1] : '',
        'bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'seconds' => $response['seconds'],
        'valid' => $ok && !$leak, 'secretinfile' => $leak, 'detail' => $detail];
}

[$manager, $managerpassword] = acceptance_client::temporary_user('manager', true);
[$nobody, $nobodypassword] = acceptance_client::temporary_user('norole', false);
$report = ['site' => $CFG->wwwroot, 'version' => (string) core_plugin_manager::instance()
    ->get_plugin_info('local_catquizlab')->release, 'time' => date('c'), 'experimentid' => $experimentid,
    'runid' => $runid, 'cases' => []];
try {
    $client = new acceptance_client($manager->username, $managerpassword, (bool) $options['insecure']);
    $other = new acceptance_client($nobody->username, $nobodypassword, (bool) $options['insecure']);
    $sesskey = $client->sesskey();
    $base = $CFG->wwwroot . '/local/catquizlab/';
    $selections = ['experiment' => ['experimentid' => $experimentid], 'all' => [],
        'empty' => ['experimentid' => (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(id), 0) + 1000 FROM {local_catquizlab_experiment}'
        )]];
    $cases = [];
    foreach ($selections as $selection => $filter) {
        foreach (array_keys(results_export::levels()) as $level) {
            foreach (['csv', 'json', 'meta'] as $action) {
                if ($selection !== 'experiment' && $action === 'meta') {
                    continue;
                }
                $cases["export $level $action ($selection)"] = [$base . 'results.php?' . http_build_query($filter
                    + ['tab' => 'export', 'level' => $level, 'action' => $action], '', '&'), $action];
            }
        }
    }
    // The downloads that need the session key are posted, as their buttons do (#149): the key in the body, never in
    // the URL. Each is also asked for by GET with the key in the URL, and must refuse.
    $cases['reproduction package'] = [$base . 'results.php', 'package', ['action' => 'package',
        'experimentid' => $experimentid]];
    $cases['log text'] = [$base . 'logs.php', 'txt', ['runid' => $runid, 'download' => 1]];
    $cases['log json'] = [$base . 'logs.php', 'logjson', ['runid' => $runid, 'downloadjson' => 1]];
    $cases['run artefacts zip'] = [$base . 'runs.php', 'zip', ['runid' => $runid, 'action' => 'artefactzip']];
    // A plot: its SVG from the page, its PDF from the key the page gives it.
    $page = $client->get($base . 'results.php?' . http_build_query(
        ['experimentid' => $experimentid, 'tab' => 'overview'],
        '',
        '&'
    ));
    if (preg_match('/href="data:image\/svg\+xml;base64,([^"]+)"/', $page['body'], $m)) {
        $svg = ['status' => 200, 'type' => 'image/svg+xml', 'disposition' => '', 'body' => base64_decode($m[1]),
            'seconds' => 0.0];
        $report['cases']['plot svg'] = local_catquizlab_accept_check($svg, 'svg');
    } else {
        $report['cases']['plot svg'] = ['valid' => false, 'detail' => 'no plot on the overview'];
    }
    if (preg_match('/<input type="hidden" name="key" value="([0-9a-f]+)"/', $page['body'], $m)) {
        $cases['plot pdf'] = [$base . 'plotpdf.php', 'pdf', ['key' => $m[1]]];
    } else {
        $report['cases']['plot pdf'] = ['valid' => false, 'detail' => 'no PDF control on the overview'];
    }
    $report['cases']['plot png'] = ['valid' => null, 'detail' => 'drawn in the browser from the SVG; not an HTTP download'];

    $nofile = static fn(array $r): bool => !preg_match('/attachment|filename=/i', $r['disposition'])
        && strncmp($r['body'], '%PDF-', 5) !== 0 && strncmp($r['body'], 'PK', 2) !== 0;
    $othersesskey = $other->sesskey();
    foreach ($cases as $name => $case) {
        [$url, $kind] = $case;
        $post = $case[2] ?? null;
        $report['cases'][$name] = local_catquizlab_accept_check(
            $post === null ? $client->get($url) : $client->get($url, $post + ['sesskey' => $sesskey]),
            $kind
        ) + ['norole' => null];
        // The same request without the right to it: no file.
        $denied = $post === null ? $other->get($url) : $other->get($url, $post + ['sesskey' => $othersesskey]);
        $report['cases'][$name]['norole'] = $nofile($denied) ? 'refused (' . $denied['status'] . ')' : 'FILE HANDED OUT';
        // A posted download asked for by GET, the key in the URL: refused (#149).
        if ($post !== null) {
            $get = $client->get($url . '?' . acceptance_client::query($post + ['sesskey' => $sesskey]));
            $report['cases'][$name]['getrefused'] = $nofile($get);
            $report['cases'][$name]['valid'] = $report['cases'][$name]['valid'] && $nofile($get);
            $report['cases'][$name]['detail'] .= ' · GET with key: ' . ($nofile($get) ? 'refused (' . $get['status'] . ')'
                : 'FILE HANDED OUT');
        }
    }
} finally {
    if (!$options['keepusers']) {
        acceptance_client::remove_user($manager);
        acceptance_client::remove_user($nobody);
    }
}

$failed = 0;
foreach ($report['cases'] as $case) {
    $failed += ($case['valid'] ?? true) === false || ($case['norole'] ?? '') === 'FILE HANDED OUT' ? 1 : 0;
}
$report['failed'] = $failed;
if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed ? 3 : 0);
}
printf(
    "Issue #121 / DoD6 — %s, catquizlab %s, %s, experiment %d, run %d\n",
    $report['site'],
    $report['version'],
    $report['time'],
    $experimentid,
    $runid
);
foreach ($report['cases'] as $name => $c) {
    printf(
        "%-44s %-4s %-24s %10s B %6ss %-5s %-22s %s %s\n",
        $name,
        $c['http'] ?? '-',
        $c['type'] ?? '-',
        $c['bytes'] ?? '-',
        $c['seconds'] ?? '-',
        ($c['valid'] ?? null) === null ? 'n/a' : ($c['valid'] ? 'OK' : 'FAIL'),
        $c['norole'] ?? '',
        $c['detail'] ?? '',
        isset($c['sha256']) ? substr($c['sha256'], 0, 16) : ''
    );
}
printf("%d failed\n", $failed);
exit($failed ? 3 : 0);
