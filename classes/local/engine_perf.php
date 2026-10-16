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

namespace local_catquizlab\local;

/**
 * The engine's own request timing for the sittings of a run (#110, local_catquiz #136).
 *
 * local_catquiz 1.2.1 / 1.3.0 (2026100907 on) can write, per request that
 * administers an item, how long the request took, its database queries and a
 * timeline of spans — attempt start, pool, strategy, the host's question
 * engine — to local_catquiz_trace (setting local_catquiz/attempttrace). This
 * class reads it, and only reads it: whether it is switched on is the engine's
 * setting, which the readiness view names.
 *
 * Per sitting a compact summary goes into the trace at collection, so that the
 * figures survive the engine's own retention; per run the percentiles of the
 * request time and of every span, first item and following items apart. With
 * the worker's request files (#110) and the correlation id, a slow or failing
 * answer can be traced into the engine.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_perf {
    /** @var string The engine's report class. */
    protected const REPORT = '\\local_catquiz\\local\\monitoring\\trace_report';

    /** @var string The engine's table. */
    protected const TABLE = 'local_catquiz_trace';

    /** @var int Spans kept per sitting summary, slowest first. */
    public const TOP_SPANS = 8;

    /**
     * Whether the engine can trace requests at all (local_catquiz 2026100907 or later).
     *
     * @return bool
     */
    public static function available(): bool {
        global $DB;

        return class_exists(self::REPORT) && $DB->get_manager()->table_exists(self::TABLE);
    }

    /**
     * Whether the engine is tracing requests now.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return self::available() && (bool) get_config('local_catquiz', 'attempttrace');
    }

    /**
     * A sitting's engine requests in brief: count, first item, following items, queries, the slowest spans.
     *
     * @param int $engineattemptid The adaptivequiz attempt.
     * @return array|null Null where the engine recorded nothing for it.
     */
    public static function for_sitting(int $engineattemptid): ?array {
        if ($engineattemptid <= 0 || !self::available()) {
            return null;
        }
        $report = self::REPORT;
        $traces = $report::for_attempt($engineattemptid);
        if ($traces === []) {
            return null;
        }
        $first = null;
        $next = [];
        $queries = 0;
        $spans = [];
        foreach ($traces as $trace) {
            if ($trace['kind'] === 'first_item' && $first === null) {
                $first = $trace['requestms'];
            } else if ($trace['requestms'] !== null) {
                $next[] = (float) $trace['requestms'];
            }
            $queries += (int) ($trace['dbqueries'] ?? 0);
            foreach ($trace['spans'] as $span) {
                if (!isset($span['name'], $span['ms'])) {
                    continue;
                }
                $name = (string) $span['name'];
                $spans[$name] = $spans[$name] ?? ['n' => 0, 'ms' => 0.0, 'max' => 0.0];
                $spans[$name]['n']++;
                $spans[$name]['ms'] += (float) $span['ms'];
                $spans[$name]['max'] = max($spans[$name]['max'], (float) $span['ms']);
            }
        }
        uasort($spans, static fn(array $a, array $b): int => $b['ms'] <=> $a['ms']);
        $nextp = $report::percentiles($next);

        return [
            'requests' => count($traces),
            'firstms' => $first === null ? null : round((float) $first, 1),
            'nextp50ms' => $nextp['p50'] === null ? null : round((float) $nextp['p50'], 1),
            'nextp95ms' => $nextp['p95'] === null ? null : round((float) $nextp['p95'], 1),
            'nextmaxms' => $nextp['max'] === null ? null : round((float) $nextp['max'], 1),
            'dbqueries' => $queries,
            'spans' => array_map(static fn(array $s): array => [
                'n' => $s['n'], 'ms' => round($s['ms'], 1), 'max' => round($s['max'], 1),
            ], array_slice($spans, 0, self::TOP_SPANS, true)),
        ];
    }

    /**
     * Percentiles over the run's sittings: request time and every span, first item and following items apart.
     *
     * @param int $runid The run.
     * @return array{sittings: int, traced: int, kinds: array} kinds: kind => count, requestms, queries, spans.
     */
    public static function for_run(int $runid): array {
        global $DB;

        $out = ['sittings' => 0, 'traced' => 0, 'kinds' => []];
        $engineids = array_values(array_filter(array_map('intval', $DB->get_fieldset_select(
            'local_catquizlab_attempt',
            'engineattemptid',
            'runid = :runid AND engineattemptid > 0',
            ['runid' => $runid]
        ))));
        $out['sittings'] = count($engineids);
        if ($engineids === [] || !self::available()) {
            return $out;
        }
        $report = self::REPORT;
        $request = [];
        $queries = [];
        $spans = [];
        $traced = [];
        foreach (array_chunk($engineids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'a');
            $rows = $DB->get_recordset_select(
                self::TABLE,
                "adaptiveattemptid {$insql}",
                $params,
                'id ASC',
                'id, adaptiveattemptid, kind, requestms, dbqueries, spans'
            );
            foreach ($rows as $row) {
                $traced[(int) $row->adaptiveattemptid] = true;
                $kind = (string) $row->kind;
                if ($row->requestms !== null) {
                    $request[$kind][] = (float) $row->requestms;
                }
                if ($row->dbqueries !== null) {
                    $queries[$kind][] = (float) $row->dbqueries;
                }
                foreach (json_decode((string) $row->spans, true) ?: [] as $span) {
                    if (isset($span['name'], $span['ms'])) {
                        $spans[$kind][(string) $span['name']][] = (float) $span['ms'];
                    }
                }
            }
            $rows->close();
        }
        $out['traced'] = count($traced);
        foreach (array_unique(array_merge(array_keys($request), array_keys($spans))) as $kind) {
            $byspan = array_map([$report, 'percentiles'], $spans[$kind] ?? []);
            // Slowest first, by their 95th percentile.
            uasort($byspan, static fn(array $a, array $b): int => ($b['p95'] ?? 0) <=> ($a['p95'] ?? 0));
            $out['kinds'][$kind] = [
                'count' => count($request[$kind] ?? []),
                'requestms' => $report::percentiles($request[$kind] ?? []),
                'queries' => $report::percentiles($queries[$kind] ?? []),
                'spans' => $byspan,
            ];
        }
        ksort($out['kinds']);

        return $out;
    }
}
