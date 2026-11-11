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
 * No secret in a URL (#149): crawls the plugin's pages as an administrator and lists every link, form or attribute that
 * would send a session key or another key by GET. Moodle's own links (log out, the admin bookmarks) are not the plugin's
 * and are left out.
 *
 * A session key in a link lands in the browser history, the web server's access log and the Referer of the next page;
 * a download key the same. Links that change something are not followed (anything with an action, a session key, a
 * download or a confirmation); the pages they sit on are read. Exit code 3 when anything is found.
 *
 *     sudo -u www-data php local/catquizlab/cli/scan_get_secrets.php [--pages=400] [--json]
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\acceptance_client;

ini_set('arg_separator.output', '&');

[$options] = cli_get_params(
    ['pages' => 400, 'json' => false, 'insecure' => false, 'start' => '', 'help' => false],
    ['h' => 'help']
);
if ($options['help']) {
    echo "Lists every link or form of the plugin's pages that sends a key by GET (#149). [--pages=400] [--json]\n";
    exit(0);
}
global $DB, $CFG;

/** @var string[] Parameters that are secrets in a URL. */
const LOCAL_CATQUIZLAB_SECRETS = ['sesskey', 'key', 'token', 'wstoken', 'logintoken'];

/**
 * Whether a URL carries a secret parameter with a value.
 *
 * @param string $url The URL, HTML-decoded.
 * @return string The parameter, or ''.
 */
function local_catquizlab_scan_secret(string $url): string {
    $query = (string) parse_url($url, PHP_URL_QUERY);
    parse_str($query, $params);
    foreach (LOCAL_CATQUIZLAB_SECRETS as $name) {
        if (isset($params[$name]) && is_string($params[$name]) && $params[$name] !== '') {
            return $name;
        }
    }

    return '';
}

[$admin, $password] = acceptance_client::temporary_user('admin', false);
$admins = (string) $CFG->siteadmins;
set_config('siteadmins', $admins . ',' . $admin->id);
$report = ['site' => $CFG->wwwroot, 'version' => (string) core_plugin_manager::instance()
    ->get_plugin_info('local_catquizlab')->release, 'time' => date('c'), 'pages' => 0, 'findings' => []];
try {
    $client = new acceptance_client($admin->username, $password, (bool) $options['insecure']);
    $base = $CFG->wwwroot . '/local/catquizlab/';
    // Where to start: the plugin's pages, and one experiment and one run with data.
    $queue = [$base . 'index.php', $base . 'runs.php', $base . 'logs.php', $base . 'operations.php', $base . 'presets.php',
        $base . 'tasks.php', $base . 'report.php', $base . 'results.php', $base . 'compare.php', $base . 'import.php',
        $base . 'enginediagnosis.php', $base . 'experiment.php'];
    $experiment = (int) $DB->get_field_sql('SELECT MAX(r.experimentid) FROM {local_catquizlab_run} r
        JOIN {local_catquizlab_attempt} a ON a.runid = r.id WHERE a.status = 3');
    $run = (int) $DB->get_field_sql('SELECT MAX(a.runid) FROM {local_catquizlab_attempt} a WHERE a.status = 3');
    if ($experiment > 0) {
        foreach (['overview', 'global', 'subscales', 'deficits', 'robustness', 'testflow', 'rawdata', 'export'] as $tab) {
            $queue[] = $base . 'results.php?experimentid=' . $experiment . '&tab=' . $tab;
        }
        $queue[] = $base . 'experiment.php?id=' . $experiment;
    }
    if ($run > 0) {
        $queue[] = $base . 'runs.php?runid=' . $run;
        $queue[] = $base . 'logs.php?runid=' . $run;
    }
    if ((string) $options['start'] !== '') {
        array_unshift($queue, (string) $options['start']);
    }
    $seen = [];
    $shapes = [];
    while ($queue !== [] && $report['pages'] < (int) $options['pages']) {
        $url = array_shift($queue);
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        // One page per shape: the same page with other ids says nothing new.
        ksort($params);
        $shape = $path . '?' . implode('&', array_map(static fn($k) => $k . (in_array(
            $k,
            ['tab', 'level', 'view', 'mode'],
            true
        ) ? '=' . (is_string($params[$k]) ? $params[$k] : '') : ''), array_keys($params)));
        if (isset($seen[$url]) || ($shapes[$shape] ?? 0) >= 2) {
            continue;
        }
        $seen[$url] = true;
        $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
        $page = $client->get($url);
        $report['pages']++;
        if (strpos($page['type'], 'text/html') !== 0) {
            continue;
        }
        $html = $page['body'];
        $where = substr($url, strlen($CFG->wwwroot));
        // Links and every attribute holding a URL.
        preg_match_all('/\s(href|src|action|formaction|data-[a-z-]+)="([^"]*)"/i', $html, $m, PREG_SET_ORDER);
        foreach ($m as [, $attribute, $value]) {
            $value = html_entity_decode($value, ENT_QUOTES);
            $secret = local_catquizlab_scan_secret($value);
            // The plugin's own URLs: Moodle's (log out, bookmarks) are Moodle's to answer for.
            $ours = strpos($value, $base) === 0 || strpos($value, '/local/catquizlab/') === 0
                || (strpos($value, '://') === false && strpos($value, '/') !== 0 && strpos($value, 'data:') !== 0);
            if ($secret !== '' && $ours && strtolower($attribute) !== 'action') {
                $report['findings'][] = ['page' => $where, 'what' => $attribute . ' with ' . $secret,
                    'url' => substr((string) preg_replace('/(' . $secret . '=)[^&]+/', '$1…', $value), 0, 200)];
            }
            if (
                strtolower($attribute) === 'href' && strpos($value, $base) === 0 && $secret === ''
                    && !preg_match('/[?&](action|download|downloadjson|confirm|delete|export|tail)=/', $value)
            ) {
                $queue[] = $value;
            }
        }
        // Forms sent by GET that carry a secret, in their action or a field.
        preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER);
        foreach ($forms as [, $attributes, $inner]) {
            $method = preg_match('/method="([a-z]+)"/i', $attributes, $mm) ? strtolower($mm[1]) : 'get';
            if ($method !== 'get') {
                continue;
            }
            $action = preg_match('/action="([^"]*)"/i', $attributes, $am) ? html_entity_decode($am[1], ENT_QUOTES) : '';
            $secret = local_catquizlab_scan_secret($action);
            foreach (LOCAL_CATQUIZLAB_SECRETS as $name) {
                if (preg_match('/<input[^>]*name="' . $name . '"[^>]*value="[^"]+"/i', $inner)) {
                    $secret = $name;
                }
            }
            if ($secret !== '') {
                $report['findings'][] = ['page' => $where, 'what' => 'GET form with ' . $secret,
                    'url' => substr((string) preg_replace('/(sesskey=|key=)[^&]+/', '$1…', $action), 0, 200)];
            }
        }
        // Script that builds a URL with the session key (fetch, location).
        if (preg_match_all('/(?:fetch|location\.href|window\.open)\s*\([^)]*sesskey[^)]*\)/i', $html, $js)) {
            foreach ($js[0] as $call) {
                $report['findings'][] = ['page' => $where, 'what' => 'script request with sesskey',
                    'url' => substr($call, 0, 200)];
            }
        }
    }
} finally {
    set_config('siteadmins', $admins);
    acceptance_client::remove_user($admin);
}
// The same finding on many pages is one finding.
$unique = [];
foreach ($report['findings'] as $finding) {
    $key = $finding['what'] . ' ' . preg_replace('/\d+/', 'N', (string) strtok($finding['url'], '?')) . ' '
        . preg_replace('/=[^&]*/', '', (string) parse_url($finding['url'], PHP_URL_QUERY));
    $unique[$key] = $unique[$key] ?? $finding + ['pagesfound' => 0];
    $unique[$key]['pagesfound']++;
}
$report['findings'] = array_values($unique);
if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    printf(
        "Issue #149 — %s, catquizlab %s, %s: %d pages read as an administrator\n",
        $report['site'],
        $report['version'],
        $report['time'],
        $report['pages']
    );
    foreach ($report['findings'] as $f) {
        printf("  %-34s %-40s %s (on %d page(s))\n", $f['what'], $f['page'], $f['url'], $f['pagesfound']);
    }
    printf("%d finding(s)\n", count($report['findings']));
}
exit($report['findings'] === [] ? 0 : 3);
