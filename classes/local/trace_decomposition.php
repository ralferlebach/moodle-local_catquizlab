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
 * The time of the worker's requests taken apart, from measured counters only (#164 AK02, DoD02).
 *
 * Four parts, each from its own counter, none derived from another:
 *
 *   persistence  the engine trace's own INSERT (local_catquiz_trace), each timed by Moodle's database layer and logged
 *                by its query log (dboptions logall) while the flag file QUERYLOG_FLAG exists in the data root; matched
 *                to its trace by the request id inside the row. The engine computes requestms before it writes, so
 *                this time is not in any trace — it is measured here, apart.
 *   server       per request at its edge (request_trace::edge_figures()): the whole request on the server, the time of
 *                all its queries, the session lock waited for and held.
 *   selector     the engine's span "strategy" (the whole CAT strategy) — read by trace_report.php from the traces.
 *   client       per sitting: the worker's own runtime of it, less the time the server was busy with its requests
 *                (the union of their intervals): the time in the browser, CDP and the navigation, and on the way.
 *
 * Fail closed: a part that was not measured, or measured for only some of its requests, is said to be so, with why,
 * and verdict() is "measured" only when every part that applies was measured whole. Nothing here estimates.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class trace_decomposition {
    /** @var string While this file exists in the data root, config.php turns Moodle's query log on (logall). */
    public const QUERYLOG_FLAG = 'catquizlab-querylog';

    /** @var string A part measured whole. */
    public const MEASURED = 'measured';

    /** @var string A part measured for only some of its requests. */
    public const INCOMPLETE = 'incomplete';

    /** @var string A part with nothing to measure it by. */
    public const NOT_MEASURED = 'not_measured';

    /** @var string A part that does not apply (the engine did not trace). */
    public const NOT_APPLICABLE = 'not_applicable';

    /** @var float How far a sitting's client time may fall below the server's busy time, in ms (clock granularity). */
    public const TOLERANCE_MS = 5.0;

    /**
     * The path of the flag file.
     *
     * @return string
     */
    public static function flag_path(): string {
        global $CFG;
        return $CFG->dataroot . '/' . self::QUERYLOG_FLAG;
    }

    /**
     * All parts for a time window.
     *
     * @param int $from Unix time.
     * @param int $until Unix time.
     * @param bool $tracing Whether the engine traced in the window.
     * @return array{persistence: array, server: array, client: array, verdict: string, missing: string[]}
     */
    public static function build(int $from, int $until, bool $tracing): array {
        $lines = iterator_to_array(request_trace::each($from, $until), false);
        $out = [
            'persistence' => $tracing ? self::persistence(
                self::trace_requestids($from, $until),
                self::insert_times($from, $until + 5)
            ) : ['status' => self::NOT_APPLICABLE],
            'server' => self::server($lines),
            'client' => self::client($lines, self::runtimes(array_unique(array_map(
                static fn(array $l): int => (int) ($l['attemptid'] ?? 0),
                $lines
            )))),
        ];
        return $out + self::verdict($out);
    }

    /**
     * The verdict over the parts: measured only when every part that applies was measured whole.
     *
     * @param array $parts persistence, server, client.
     * @return array{verdict: string, missing: string[]}
     */
    public static function verdict(array $parts): array {
        $missing = [];
        foreach (['persistence', 'server', 'client'] as $part) {
            $status = (string) ($parts[$part]['status'] ?? self::NOT_MEASURED);
            if ($status !== self::MEASURED && $status !== self::NOT_APPLICABLE) {
                $missing[] = $part . ': ' . $status . (isset($parts[$part]['why']) ? ' — ' . $parts[$part]['why'] : '');
            }
        }
        return ['verdict' => $missing === [] ? self::MEASURED : self::NOT_MEASURED, 'missing' => $missing];
    }

    /**
     * The engine trace INSERTs against the traces: every trace's own INSERT time, or why not.
     *
     * @param string[] $requestids The request ids of the traces of the window.
     * @param array $inserts The logged INSERTs.
     * @return array status, why, traces, matched, stats (ms), unmatched (a few request ids)
     */
    public static function persistence(array $requestids, array $inserts): array {
        $byrequest = [];
        foreach ($inserts as $insert) {
            if ($insert['requestid'] !== null) {
                $byrequest[$insert['requestid']] = $insert['ms'];
            }
        }
        $times = [];
        $unmatched = [];
        foreach ($requestids as $id) {
            if (isset($byrequest[$id])) {
                $times[] = $byrequest[$id];
            } else {
                $unmatched[] = $id;
            }
        }
        $out = ['traces' => count($requestids), 'logged' => count($inserts), 'matched' => count($times),
            'stats' => self::stats($times), 'unmatched' => array_slice($unmatched, 0, 10)];
        if ($requestids === []) {
            return ['status' => self::NOT_MEASURED, 'why' => 'no engine trace in the window'] + $out;
        }
        if ($inserts === []) {
            return ['status' => self::NOT_MEASURED, 'why' => 'no INSERT of an engine trace in the query log: '
                . 'the query log was off (' . self::QUERYLOG_FLAG . ' missing, or config.php does not read it)'] + $out;
        }
        if ($unmatched !== []) {
            return ['status' => self::INCOMPLETE, 'why' => count($unmatched) . ' of ' . count($requestids)
                . ' trace(s) without their logged INSERT'] + $out;
        }
        return ['status' => self::MEASURED] + $out;
    }

    /**
     * The server's side of every request line: all of them with the edge counters, or why not.
     *
     * @param array[] $lines request_trace lines.
     * @return array status, why, lines, counted, and stats of durationms, dbms, lockwaitms, lockheldms, otherms
     */
    public static function server(array $lines): array {
        $series = ['durationms' => [], 'dbms' => [], 'lockwaitms' => [], 'lockheldms' => [], 'otherms' => []];
        $counted = 0;
        foreach ($lines as $line) {
            if (!isset($line['dbms'], $line['durationms'])) {
                continue;
            }
            $counted++;
            $series['durationms'][] = (float) $line['durationms'];
            $series['dbms'][] = (float) $line['dbms'];
            if (isset($line['lockwaitms'])) {
                $series['lockwaitms'][] = (float) $line['lockwaitms'];
            }
            if (isset($line['lockheldms'])) {
                $series['lockheldms'][] = (float) $line['lockheldms'];
            }
            // Neither the database nor the wait for the lock: PHP, the engine's computing, the file system.
            $series['otherms'][] = max(0.0, (float) $line['durationms'] - (float) $line['dbms']
                - (float) ($line['lockwaitms'] ?? 0));
        }
        $out = ['lines' => count($lines), 'counted' => $counted] + array_map([self::class, 'stats'], $series);
        if ($lines === []) {
            return ['status' => self::NOT_MEASURED, 'why' => 'no request line in the window'] + $out;
        }
        if ($counted < count($lines)) {
            return ['status' => self::INCOMPLETE, 'why' => (count($lines) - $counted) . ' of ' . count($lines)
                . ' request line(s) without the edge counters (written by a version before 0.7.77)'] + $out;
        }
        return ['status' => self::MEASURED] + $out;
    }

    /**
     * The client's side of every sitting: its runtime less the server's busy time for it.
     *
     * @param array[] $lines request_trace lines.
     * @param array $runtimes Sitting id => the worker's runtime of it, ms (only finished sittings).
     * @return array status, why, sittings, measured, stats (ms of client wait), negative (sitting ids)
     */
    public static function client(array $lines, array $runtimes): array {
        $intervals = [];
        foreach ($lines as $line) {
            $id = (int) ($line['attemptid'] ?? 0);
            // The worker's web service calls are not the browser's.
            if ($id <= 0 || str_contains((string) ($line['path'] ?? ''), '/webservice/')) {
                continue;
            }
            $start = (float) ($line['unixms'] ?? 0);
            $intervals[$id][] = [$start, $start + (float) ($line['durationms'] ?? 0)];
        }
        $waits = [];
        $negative = [];
        $without = 0;
        foreach ($intervals as $id => $list) {
            if (!isset($runtimes[$id])) {
                $without++;
                continue;
            }
            $busy = self::union($list);
            $wait = (float) $runtimes[$id] - $busy;
            if ($wait < -self::TOLERANCE_MS) {
                $negative[] = $id;
                continue;
            }
            $waits[] = max(0.0, $wait);
        }
        $out = ['sittings' => count($intervals), 'measured' => count($waits), 'withoutruntime' => $without,
            'stats' => self::stats($waits), 'negative' => array_slice($negative, 0, 10)];
        if ($waits === [] && $negative === []) {
            return ['status' => self::NOT_MEASURED, 'why' => 'no finished sitting with request lines in the window'] + $out;
        }
        if ($negative !== []) {
            return ['status' => self::INCOMPLETE, 'why' => count($negative) . ' sitting(s) with less runtime than the '
                . 'server was busy for them: their lines and their runtime do not describe the same requests'] + $out;
        }
        return ['status' => self::MEASURED] + $out;
    }

    /**
     * The length of the union of intervals.
     *
     * @param array $intervals [start, end] each.
     * @return float
     */
    public static function union(array $intervals): float {
        usort($intervals, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $total = 0.0;
        $end = null;
        $start = null;
        foreach ($intervals as [$a, $b]) {
            if ($end === null || $a > $end) {
                $total += $end === null ? 0.0 : $end - $start;
                [$start, $end] = [$a, $b];
            } else {
                $end = max($end, $b);
            }
        }
        return $total + ($end === null ? 0.0 : $end - $start);
    }

    /**
     * The request id of an engine trace INSERT, from the parameters the query log kept.
     *
     * @param string $sqlparams var_export() of the parameters.
     * @return string|null
     */
    public static function requestid_of(string $sqlparams): ?string {
        return preg_match('/"requestid"\s*:\s*"([^"\\\\]{1,128})"/', $sqlparams, $m) ? $m[1] : null;
    }

    /**
     * The engine trace INSERTs Moodle's query log holds for a window.
     *
     * @param int $from Unix time.
     * @param int $until Unix time.
     * @return array<int,array{requestid: ?string, ms: float}>
     */
    public static function insert_times(int $from, int $until): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('log_queries')) {
            return [];
        }
        $out = [];
        $rs = $DB->get_recordset_select(
            'log_queries',
            'timelogged >= :from AND timelogged <= :until AND ' . $DB->sql_like('sqltext', ':pattern', false),
            ['from' => $from, 'until' => $until, 'pattern' => '%INSERT INTO ' . $DB->get_prefix() . 'local_catquiz_trace %'],
            'id ASC',
            'id, sqlparams, exectime'
        );
        foreach ($rs as $row) {
            $out[] = ['requestid' => self::requestid_of((string) $row->sqlparams), 'ms' => round(1000 * (float) $row->exectime, 3)];
        }
        $rs->close();
        return $out;
    }

    /**
     * The request ids of the engine traces of a window.
     *
     * @param int $from Unix time.
     * @param int $until Unix time.
     * @return string[]
     */
    public static function trace_requestids(int $from, int $until): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_catquiz_trace')) {
            return [];
        }
        $out = [];
        $rs = $DB->get_recordset_select(
            'local_catquiz_trace',
            'timecreated >= ? AND timecreated <= ?',
            [$from, $until],
            'id ASC',
            'id, spans'
        );
        foreach ($rs as $row) {
            $spans = json_decode((string) $row->spans, true);
            $out[] = (string) ($spans[0]['meta']['requestid'] ?? ('trace ' . $row->id));
        }
        $rs->close();
        return $out;
    }

    /**
     * The worker's runtime of the finished sittings.
     *
     * @param int[] $attemptids The sittings.
     * @return array<int,int> Sitting id => ms.
     */
    public static function runtimes(array $attemptids): array {
        global $DB;
        $attemptids = array_values(array_filter($attemptids));
        if ($attemptids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($attemptids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $rows = $DB->get_records_select_menu(
                'local_catquizlab_attempt',
                "id {$insql} AND runtimems > 0 AND tries = 1",
                $params,
                '',
                'id, runtimems'
            );
            foreach ($rows as $id => $ms) {
                $out[(int) $id] = (int) $ms;
            }
        }
        return $out;
    }

    /**
     * n, mean, p50, p95, p99 and max of a series.
     *
     * @param float[] $values The values.
     * @return array{n: int, mean: float|null, p50: float|null, p95: float|null, p99: float|null, max: float|null}
     */
    public static function stats(array $values): array {
        sort($values);
        $n = count($values);
        $q = static fn(float $p): ?float => $n === 0 ? null : round($values[(int) min($n - 1, max(0, ceil($p * $n) - 1))], 3);
        return ['n' => $n, 'mean' => $n === 0 ? null : round(array_sum($values) / $n, 3), 'p50' => $q(0.5),
            'p95' => $q(0.95), 'p99' => $q(0.99), 'max' => $n === 0 ? null : round($values[$n - 1], 3)];
    }
}
