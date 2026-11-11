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
 * HTTP acceptance of the logs page under a large request trace (#132), on the server it runs on.
 *
 * Writes --lines request trace lines of the run's sittings into the server's own trace files, spread over --hours
 * (by default across a day boundary, so that two day files are read as one log), then asks the logs page through the web
 * server, as a temporary manager: the page, its pages, newest first, the errors only, a search, both downloads, the live
 * tail while lines are being written, and a window across midnight. Each request goes out with the trace header, so the
 * server writes down what it cost — status, time, peak PHP memory, queries, the process's resident memory — and this
 * reads it back. Then retention: an expired day file goes, the current ones stay. Finally the lines it wrote are taken
 * out again (--keep leaves them), and the temporary users are deleted.
 *
 * Every check says what was expected and what came; the exit code is 3 when any failed.
 *
 *     sudo -u www-data php local/catquizlab/cli/accept_logs.php --runid=51 > /root/132-accept.txt
 *     sudo -u www-data php local/catquizlab/cli/accept_logs.php --runid=51 --json > /root/132-accept.json
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\acceptance_client;
use local_catquizlab\local\log_view;
use local_catquizlab\local\request_trace;
use local_catquizlab\local\trace_seed;

ini_set('arg_separator.output', '&');

[$options] = cli_get_params(['runid' => 0, 'lines' => 250000, 'hours' => 30, 'tailseconds' => 60, 'limitmb' => 256,
    'keep' => false, 'json' => false, 'insecure' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int) $options['runid'] <= 0) {
    echo "HTTP acceptance of the logs page under a large request trace (#132).\n"
        . "--runid=N [--lines=250000] [--hours=30] [--tailseconds=60] [--limitmb=256] [--keep] [--json] [--insecure]\n";
    exit($options['help'] ? 0 : 1);
}
global $DB, $CFG;
$runid = (int) $options['runid'];
$attemptids = array_map('intval', $DB->get_fieldset_select('local_catquizlab_attempt', 'id', 'runid = ?', [$runid]));
if ($attemptids === []) {
    cli_error('Run ' . $runid . ' has no sittings: the lines would belong to nothing the filter could find.');
}
if (!is_enabled_auth('manual')) {
    cli_error('Manual authentication is not enabled: the temporary users cannot log in.');
}
$lines = max(1000, (int) $options['lines']);
$span = max(1, (int) $options['hours']) * HOURSECS;
$limitmb = (float) $options['limitmb'];
$mark = 'acc132';

/**
 * The request trace line the server wrote for one request of ours.
 *
 * @param string $probe The correlation id it was sent with.
 * @return array|null
 */
function local_catquizlab_accept_logs_cost(string $probe): ?array {
    global $CFG;
    $needle = '"correlationid":"' . $probe . '"';
    // The line is written at the end of the request, after the answer: a moment's grace.
    for ($try = 0; $try < 20; $try++) {
        foreach ([gmdate('Y-m-d'), gmdate('Y-m-d', time() - 3600)] as $day) {
            $file = $CFG->dataroot . '/' . request_trace::DIRECTORY . '/' . $day . '.jsonl';
            if (!is_readable($file)) {
                continue;
            }
            $size = filesize($file);
            $handle = fopen($file, 'rb');
            fseek($handle, max(0, $size - 1048576));
            $tail = (string) stream_get_contents($handle);
            fclose($handle);
            $at = strrpos($tail, $needle);
            if ($at !== false) {
                $start = strrpos(substr($tail, 0, $at), "\n");
                $end = strpos($tail, "\n", $at);
                $line = json_decode(substr(
                    $tail,
                    $start === false ? 0 : $start + 1,
                    ($end === false ? strlen($tail) : $end) - ($start === false ? 0 : $start + 1)
                ), true);
                if (is_array($line)) {
                    return $line;
                }
            }
        }
        usleep(100000);
    }

    return null;
}

/**
 * The lines on a logs page: their times in milliseconds and their severities, in the order shown.
 *
 * @param string $body The page.
 * @return array{ms: int[], severity: string[]}
 */
function local_catquizlab_accept_logs_lines(string $body): array {
    preg_match_all('/<span data-ms="(\d+)" data-stamp="[^"]*" class="catquizlab-logline" data-severity="([a-z]*)"/', $body, $m);

    return ['ms' => array_map('intval', $m[1]), 'severity' => $m[2]];
}

/**
 * Whether a list of numbers rises (or falls) throughout.
 *
 * @param int[] $values The numbers.
 * @param bool $rising Rising, or falling.
 * @return bool
 */
function local_catquizlab_accept_logs_ordered(array $values, bool $rising = true): bool {
    for ($i = 1, $n = count($values); $i < $n; $i++) {
        if ($rising ? $values[$i] < $values[$i - 1] : $values[$i] > $values[$i - 1]) {
            return false;
        }
    }

    return true;
}

/**
 * The times of the lines trace_seed::write() writes, in milliseconds, as the trace files hold them.
 *
 * @param int $lines As given to write().
 * @param int $seconds As given to write().
 * @param int $until As given to write().
 * @return \Generator<int, array{ms: int, error: bool}>
 */
function local_catquizlab_accept_logs_expected(int $lines, int $seconds, int $until): \Generator {
    $start = $until - $seconds;
    for ($i = 0; $i < $lines; $i++) {
        $started = $start + $seconds * $i / $lines;
        yield $i => ['ms' => 1000 * (int) $started + (int) (fmod($started, 1) * 1000),
            'error' => $i % trace_seed::ERROR_EVERY === 0];
    }
}

$report = ['site' => $CFG->wwwroot, 'version' => (string) core_plugin_manager::instance()
    ->get_plugin_info('local_catquizlab')->release, 'time' => date('c'), 'php' => PHP_VERSION, 'db' => $DB->get_dbfamily(),
    'memorylimit' => (string) ini_get('memory_limit'), 'runid' => $runid, 'sittings' => count($attemptids),
    'limitmb' => $limitmb, 'cases' => []];

$until = time() - 60;
$started = microtime(true);
$seeded = trace_seed::write($lines, $span, $mark, $attemptids, $until);
$report['seed'] = ['lines' => $seeded['lines'], 'errors' => $seeded['errors'],
    'mb' => round($seeded['bytes'] / 1048576, 1), 'seconds' => round(microtime(true) - $started, 1),
    'files' => array_map(static fn(string $f): string => basename($f), array_keys($seeded['files'])),
    'filesizesmb' => array_map(
        static fn(string $f): float => round(filesize($f) / 1048576, 1),
        array_keys($seeded['files'])
    )];

[$manager, $managerpassword] = acceptance_client::temporary_user('manager', true);
[$nobody, $nobodypassword] = acceptance_client::temporary_user('norole', false);
$probe = 0;
try {
    $client = new acceptance_client($manager->username, $managerpassword, (bool) $options['insecure']);
    $other = new acceptance_client($nobody->username, $nobodypassword, (bool) $options['insecure']);
    $sesskey = $client->sesskey();
    $base = $CFG->wwwroot . '/local/catquizlab/logs.php?';
    $hours = (int) ceil($span / HOURSECS) + 2;

    // One request, with what the server says it cost.
    $ask = static function (
        string $name,
        array $params,
        ?callable $check = null,
        ?acceptance_client $as = null,
        string $refusal = ''
    ) use (
        &$report,
        &$probe,
        $client,
        $base,
        $mark,
        $limitmb
    ): array {
        $id = $mark . 'probe' . (++$probe) . substr(md5((string) microtime(true)), 0, 6);
        // What needs the session key is posted, as the page's buttons do (#149); the rest is asked for by GET.
        $headers = [request_trace::HEADER_CORRELATION . ': ' . $id];
        $response = isset($params['sesskey'])
            ? ($as ?? $client)->get(rtrim($base, '?'), $params, $headers)
            : ($as ?? $client)->get($base . acceptance_client::query($params), null, $headers);
        $cost = local_catquizlab_accept_logs_cost($id);
        $case = ['http' => $response['status'], 'type' => strtok($response['type'], ';'), 'bytes' => strlen($response['body']),
            'seconds' => $response['seconds'], 'server' => $cost === null ? null : array_intersect_key(
                $cost,
                array_flip(['status', 'durationms', 'peakmb', 'queries', 'rssmb', 'hwmmb', 'connection', 'pid'])
            )];
        // A refusal is an exception Moodle turns into its error page: expected there, and only that one.
        $refused = $refusal !== '' && $cost !== null && $cost['fatal'] === null
            && ($cost['exception']['errorcode'] ?? '') === $refusal;
        $fatal = $cost !== null && !$refused && ($cost['fatal'] !== null || $cost['exception'] !== null);
        $within = $cost !== null && (float) $cost['peakmb'] < $limitmb;
        [$ok, $detail] = $check === null ? [$response['status'] === 200, ''] : $check($response);
        $case['valid'] = $ok && $within && !$fatal && $cost !== null;
        $case['detail'] = trim($detail . ($cost === null ? ' · no trace line for the request' : '')
            . ($fatal ? ' · FATAL/EXCEPTION ' . json_encode($cost['fatal'] ?? $cost['exception']) : '')
            . ($cost !== null && !$within ? ' · peak ' . $cost['peakmb'] . ' MB ≥ ' . $limitmb . ' MB' : ''), ' ·');
        $report['cases'][$name] = $case;

        return $response;
    };
    $page = static fn(array $extra): array => ['runid' => $runid, 'hours' => $hours] + $extra;

    // The page, then two older pages: 500 lines each, none twice, oldest first throughout.
    $seen = [];
    foreach ([0, 1, 2] as $n) {
        $ask(
            "page $n (runid, request lines)",
            $page(['channel' => 'request', 'page' => $n]),
            static function (array $r) use (&$seen, $n): array {
                $l = local_catquizlab_accept_logs_lines($r['body']);
                $seen[$n] = $l['ms'];
                $overlap = $n > 0 ? count(array_intersect($l['ms'], $seen[$n - 1])) : 0;
                $joined = $n === 0 || ($l['ms'] !== [] && end($l['ms']) <= $seen[$n - 1][0]);
                return [$r['status'] === 200 && count($l['ms']) === log_view::PAGE && $overlap === 0 && $joined
                    && local_catquizlab_accept_logs_ordered($l['ms']),
                    'lines=' . count($l['ms']) . ' overlap=' . $overlap . ' joins=' . ($joined ? 'yes' : 'NO')
                    . ' order=' . (local_catquizlab_accept_logs_ordered($l['ms']) ? 'oldest first' : 'MIXED')];
            }
        );
    }
    // The newest of all the run's seeded lines is the last on page 0.
    $expectednewest = null;
    $errors = 0;
    $from = $until - $span;
    foreach (local_catquizlab_accept_logs_expected($lines, $span, $until) as $e) {
        $expectednewest = $e['ms'];
        $errors += $e['error'] && $e['ms'] >= $from * 1000 ? 1 : 0;
    }
    $report['cases']['page 0 ends with the newest line'] = ['valid' => end($seen[0]) === $expectednewest,
        'detail' => 'expected ' . $expectednewest . ' got ' . end($seen[0])];

    $ask('newest first', $page(['channel' => 'request', 'newestfirst' => 1]), static function (array $r) use ($seen): array {
        $l = local_catquizlab_accept_logs_lines($r['body'])['ms'];
        return [$l === array_reverse($seen[0]), 'lines=' . count($l) . ' same as page 0 reversed: '
            . ($l === array_reverse($seen[0]) ? 'yes' : 'NO')];
    });
    $ask(
        'errors only',
        $page(['channel' => 'request', 'severity' => 'error']),
        static function (array $r) use ($errors): array {
            $l = local_catquizlab_accept_logs_lines($r['body']);
            $all = array_unique($l['severity']) === ['error'];
            return [count($l['ms']) === min($errors, log_view::PAGE) && $all,
                'lines=' . count($l['ms']) . ' expected=' . min($errors, log_view::PAGE) . ' all errors: ' . ($all ? 'yes' : 'NO')];
        }
    );
    $needle = 'seed=' . intdiv($lines, 3);
    $ask(
        'search one line',
        $page(['channel' => 'request', 'search' => $needle]),
        static function (array $r): array {
            $n = count(local_catquizlab_accept_logs_lines($r['body'])['ms']);
            return [$n === 1, 'lines=' . $n . ' expected=1'];
        }
    );
    $ask('page, all sources', $page([]));

    // A window across midnight: two day files read as one log, in order.
    $midnight = (int) (floor($until / 86400) * 86400);
    if ($midnight - 600 >= $from) {
        $wfrom = $midnight - 600;
        $wto = $midnight + 600 <= $until ? $midnight + 600 : $until;
        $inwindow = 0;
        foreach (local_catquizlab_accept_logs_expected($lines, $span, $until) as $e) {
            $inwindow += $e['ms'] >= $wfrom * 1000 && $e['ms'] < ($wto + 1) * 1000 ? 1 : 0;
        }
        $ask(
            'download json across midnight',
            ['runid' => $runid, 'channel' => 'request', 'downloadjson' => 1,
            'sesskey' => $sesskey, 'from' => gmdate('Y-m-d\TH:i:s\Z', $wfrom), 'to' => gmdate('Y-m-d\TH:i:s\Z', $wto)],
            static function (array $r) use ($inwindow, $midnight): array {
                $d = json_decode($r['body'], true);
                $ms = is_array($d) ? array_map('intval', array_column($d['lines'] ?? [], 'ms')) : [];
                $before = count(array_filter($ms, static fn(int $m): bool => $m < $midnight * 1000));
                $want = min($inwindow, log_view::EXPORT_LIMIT);
                return [count($ms) === $want && $before > 0 && $before < count($ms) && local_catquizlab_accept_logs_ordered($ms),
                    'lines=' . count($ms) . ' expected=' . $want . ' before/after midnight=' . $before . '/'
                    . (count($ms) - $before) . ' order=' . (local_catquizlab_accept_logs_ordered($ms) ? 'rising' : 'MIXED')];
            }
        );
    } else {
        $report['cases']['download json across midnight'] = ['valid' => null,
            'detail' => 'the seeded span does not reach back over midnight UTC; --hours=30 does'];
    }

    // Both downloads say what they hold.
    $ask(
        'download json',
        $page(['channel' => 'request', 'downloadjson' => 1, 'sesskey' => $sesskey]),
        static function (array $r) use ($runid): array {
            $d = json_decode($r['body'], true);
            $ok = is_array($d) && (int) ($d['filter']['runid'] ?? 0) === $runid && ($d['filter']['channel'] ?? '') === 'request'
                && isset($d['from'], $d['to'], $d['limit'], $d['count'], $d['scan']) && count($d['lines']) === (int) $d['count']
                && local_catquizlab_accept_logs_ordered(array_map('intval', array_column($d['lines'], 'ms')));
            return [$ok, is_array($d) ? 'count=' . $d['count'] . ' limit=' . $d['limit'] . ' truncated='
                . json_encode($d['truncated']) . ' partial=' . json_encode($d['partial']) . ' filter='
                . json_encode($d['filter']) . ' read=' . $d['scan']['requestlines'] . ' lines/'
                . round($d['scan']['requestbytes'] / 1048576, 1) . ' MB' : 'not JSON'];
        }
    );
    $ask(
        'download text',
        $page(['channel' => 'request', 'download' => 1, 'sesskey' => $sesskey]),
        static function (array $r) use ($runid): array {
            $head = preg_match('/^# filter: (.*)$/m', $r['body'], $m) ? json_decode($m[1], true) : null;
            $count = preg_match('/^# count: (\d+)$/m', $r['body'], $c) ? (int) $c[1] : -1;
            $body = count(preg_grep('/^\d{4}-\d\d-\d\d/', explode("\n", $r['body'])));
            $ok = is_array($head) && (int) ($head['runid'] ?? 0) === $runid && $count === $body && $count > 0
                && mb_check_encoding($r['body'], 'UTF-8');
            return [$ok, 'count=' . $count . ' lines=' . $body . ' filter=' . json_encode($head)];
        }
    );

    // A download asked for by GET, the session key in the URL: refused, no file (#149).
    $url = $base . acceptance_client::query($page(['channel' => 'request', 'downloadjson' => 1, 'sesskey' => $sesskey]));
    $get = $client->get($url);
    $report['cases']['download by GET refused'] = ['http' => $get['status'], 'bytes' => strlen($get['body']),
        'valid' => !preg_match('/attachment|filename=/i', $get['disposition']),
        'detail' => preg_match('/attachment|filename=/i', $get['disposition']) ? 'FILE HANDED OUT' : 'no file'];

    // The live tail while lines are being written: each poll brings exactly the lines written since the last.
    $last = end($seen[0]);
    $polls = 0;
    $tailok = true;
    $tailnotes = [];
    $deadline = time() + max(10, (int) $options['tailseconds']);
    $batch = 0;
    while (time() < $deadline) {
        $batch++;
        $now = time();
        $written = trace_seed::write(3, 2, $mark . 'tail' . $batch . 'x', $attemptids, $now);
        $polls++;
        $response = $ask(
            'live tail ' . $polls,
            $page(['channel' => 'request', 'tail' => 1, 'after' => $last]),
            static function (array $r) use ($written, &$last): array {
                $d = json_decode($r['body'], true);
                $ms = is_array($d) ? array_map('intval', array_column($d['lines'] ?? [], 'ms')) : [];
                $ok = count($ms) === $written['lines'] && ($ms === [] || min($ms) > $last)
                    && local_catquizlab_accept_logs_ordered($ms);
                $was = $last;
                $last = $ms === [] ? $last : max($ms);
                return [$ok, 'new=' . count($ms) . ' written=' . $written['lines'] . ' after=' . $was];
            }
        );
        $tailok = $tailok && $report['cases']['live tail ' . $polls]['valid'];
        sleep(5);
    }
    // And a poll with nothing new.
    $ask(
        'live tail, nothing new',
        $page(['channel' => 'request', 'tail' => 1, 'after' => $last]),
        static function (array $r): array {
            $d = json_decode($r['body'], true);
            return [is_array($d) && ($d['lines'] ?? null) === [], 'new=' . count((array) ($d['lines'] ?? []))];
        }
    );

    // Without the right to it: nothing of the log.
    $ask('no role: refused', $page(['channel' => 'request']), static function (array $r): array {
        $lines = count(local_catquizlab_accept_logs_lines($r['body'])['ms']);
        return [$lines === 0 && strpos($r['body'], 'acc132') === false && $r['status'] >= 400,
            'http=' . $r['status'] . ' lines shown=' . $lines . ' (accessdenied, as expected)'];
    }, $other, 'accessdenied');
} finally {
    acceptance_client::remove_user($manager);
    acceptance_client::remove_user($nobody);
}

// Retention: a day file past it goes, today's stays, line for line.
$days = (int) get_config('local_catquizlab', 'artefact_retention_days');
$dir = $CFG->dataroot . '/' . request_trace::DIRECTORY;
$old = $dir . '/' . gmdate('Y-m-d', time() - (max(1, $days ?: 30) + 2) * 86400) . '.jsonl';
$preexisting = file_exists($old);
if (!$preexisting) {
    file_put_contents($old, '{"time":"' . gmdate('Y-m-d\TH:i:s.000\Z', time() - (max(1, $days ?: 30) + 2) * 86400)
        . '","correlationid":"' . $mark . 'old"}' . "\n");
}
$today = $dir . '/' . gmdate('Y-m-d') . '.jsonl';
$before = is_readable($today) ? count(file($today)) : 0;
$removedfiles = request_trace::purge_older_than(max(1, $days ?: 30));
$after = is_readable($today) ? count(file($today)) : 0;
$report['cases']['retention'] = ['valid' => !file_exists($old) && $after >= $before,
    'detail' => 'retention=' . ($days > 0 ? $days . ' days (artefact_retention_days)' : 'not set; checked with 30 days')
    . ' expired file removed=' . (file_exists($old) ? 'NO' : 'yes') . ($preexisting ? ' (an existing one)' : '')
    . ' files removed=' . $removedfiles . ' today kept=' . $before . '→' . $after . ' lines'];

if (!$options['keep']) {
    $report['removed'] = trace_seed::remove($mark);
}

$failed = 0;
$peak = 0.0;
$rss = 0.0;
foreach ($report['cases'] as $case) {
    $failed += ($case['valid'] ?? true) === false ? 1 : 0;
    $peak = max($peak, (float) ($case['server']['peakmb'] ?? 0));
    $rss = max($rss, (float) ($case['server']['rssmb'] ?? 0));
}
$report['maxpeakmb'] = $peak;
$report['maxrssmb'] = $rss;
$report['failed'] = $failed;
if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed ? 3 : 0);
}
printf(
    "Issue #132 — %s, catquizlab %s, %s, PHP %s, %s, memory_limit %s, run %d (%d sittings)\n",
    $report['site'],
    $report['version'],
    $report['time'],
    $report['php'],
    $report['db'],
    $report['memorylimit'],
    $runid,
    $report['sittings']
);
printf(
    "Seeded %d lines (%d server errors), %.1f MB in %s (%s MB) in %.1f s\n",
    $report['seed']['lines'],
    $report['seed']['errors'],
    $report['seed']['mb'],
    implode(', ', $report['seed']['files']),
    implode(', ', $report['seed']['filesizesmb']),
    $report['seed']['seconds']
);
printf(
    "%-34s %-4s %8s %7s | %-4s %7s %7s %6s %7s | %-4s %s\n",
    'case',
    'http',
    'bytes',
    'client',
    'srv',
    'ms',
    'peakMB',
    'query',
    'rssMB',
    'ok',
    'detail'
);
foreach ($report['cases'] as $name => $c) {
    $s = $c['server'] ?? [];
    printf(
        "%-34s %-4s %8s %7s | %-4s %7s %7s %6s %7s | %-4s %s\n",
        $name,
        $c['http'] ?? '-',
        $c['bytes'] ?? '-',
        isset($c['seconds']) ? $c['seconds'] . 's' : '-',
        $s['status'] ?? '-',
        $s['durationms'] ?? '-',
        $s['peakmb'] ?? '-',
        $s['queries'] ?? '-',
        $s['rssmb'] ?? '-',
        ($c['valid'] ?? null) === null ? 'n/a' : ($c['valid'] ? 'OK' : 'FAIL'),
        $c['detail'] ?? ''
    );
}
printf(
    "max peak %.1f MB, max process RSS %.1f MB (limit %s MB); removed %s seeded lines\n",
    $peak,
    $rss,
    $limitmb,
    $report['removed'] ?? 'no (--keep)'
);
printf("%d failed\n", $failed);
exit($failed ? 3 : 0);
