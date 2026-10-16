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
 * What happened, in order, from every source at once.
 *
 * This plugin records in four places: the debug trace, the per-run execution
 * log, Moodle's task table and the worker logs on disk. Each is correct and
 * none is complete, so answering "what happened" meant reading all four and
 * merging them by hand — which is why people asked here rather than looking.
 *
 * One chronological list, filterable, and formatted so a person can select the
 * filtered part and paste it into a support thread without editing it. That
 * last part is not decoration: a log somebody has to reformat before sending is
 * a log that arrives incomplete.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_view {
    /** @var int How many lines to show at once. */
    public const PAGE = 500;

    /** @var int Rows each database source reads at least, newest first (#132). */
    public const SOURCE_ROWS = 2000;

    /** @var int The furthest back a reader can page: so many pages of PAGE lines (#132). */
    public const MAX_PAGES = 20;

    /**
     * The merged log.
     *
     * @param array $filter since, until, channel, experimentid, runid, attemptid, workerid, search.
     * @param int $limit How many lines.
     * @return array[]
     */
    public static function lines(array $filter = [], int $limit = self::PAGE): array {
        return self::window($filter, $limit, 0)['lines'];
    }

    /**
     * One page of the merged log: the newest $limit lines, or the $limit before them, and so on (#132).
     *
     * Bounded at every step. Each source applies the whole filter line by
     * line and keeps only its newest ($page + 1) × $limit lines, so neither a
     * day of request traces nor a busy debug table is ever held in memory as a
     * whole; the newest lines of the merged log are always among the newest of
     * each source, so nothing that belongs on the page is lost.
     *
     * @param array $filter As for lines().
     * @param int $limit Lines per page.
     * @param int $page 0 for the newest page, 1 for the one before it, …
     * @return array lines (array[]), page, limit, hasolder, hasnewer, scan (what was read, how long, how much memory).
     */
    public static function window(array $filter = [], int $limit = self::PAGE, int $page = 0): array {
        $started = microtime(true);
        $scan = null;
        $limit = max(1, $limit);
        $page = max(0, min(self::MAX_PAGES - 1, $page));
        // One more than the page needs: whether there is anything older.
        $keep = $limit * ($page + 1) + 1;

        $lines = [];
        foreach (
            [
                self::from_debug($filter, $keep),
                self::from_runlog($filter, $keep),
                self::from_attemptlog($filter, $keep),
                self::from_tasks($filter),
                self::from_dispatch($filter),
                self::from_requests($filter, $keep, $scan),
            ] as $source
        ) {
            $matching = array_values(array_filter($source, static fn(array $line): bool => self::matches($line, $filter)));
            $lines = array_merge($lines, self::newest($matching, $keep));
        }

        $lines = self::newest($lines, $keep);
        $hasolder = count($lines) > $limit * ($page + 1);
        $end = count($lines) - $limit * $page;
        $lines = $end > 0 ? array_slice($lines, max(0, $end - $limit), min($limit, $end)) : [];

        foreach ($lines as &$line) {
            $line['stamp'] = self::stamp($line);
        }
        unset($line);

        if (!empty($filter['newestfirst'])) {
            $lines = array_reverse($lines);
        }

        return [
            'lines' => $lines,
            'page' => $page,
            'limit' => $limit,
            'hasolder' => $hasolder && $page + 1 < self::MAX_PAGES,
            'hasnewer' => $page > 0,
            // What it took, for the page and the exports (#132 LOG-MEM-003): no request contents.
            'scan' => [
                'requestlines' => (int) ($scan['lines'] ?? 0),
                'requestbytes' => (int) ($scan['bytes'] ?? 0),
                'skippedbytes' => (int) ($scan['skippedbytes'] ?? 0),
                'partial' => !empty($scan['partial']),
                'seconds' => round(microtime(true) - $started, 3),
                'peakmb' => round(memory_get_peak_usage() / 1048576, 1),
            ],
        ];
    }

    /**
     * The newest so many lines, oldest first.
     *
     * Newest last: a log is read downwards, and a person pasting the tail of
     * it wants the end to be the end.
     *
     * @param array[] $lines Lines in any order.
     * @param int $keep How many.
     * @return array[]
     */
    protected static function newest(array $lines, int $keep): array {
        usort($lines, static function (array $a, array $b): int {
            // Milliseconds first: two entries of the same second used to keep
            // only the order their sources happened to be merged in.
            // The source last: the same ms and seq in two sources used to
            // leave their order to the merge (#132 LOG-MEM-001·5).
            return $a['ms'] <=> $b['ms'] ?: $a['seq'] <=> $b['seq'] ?: strcmp($a['source'], $b['source']);
        });

        return count($lines) > $keep ? array_slice($lines, -$keep) : $lines;
    }

    /**
     * The whole filtered selection as one block of text.
     *
     * @param array $filter As for lines().
     * @param int $limit How many lines.
     * @return string
     */
    public static function as_text(array $filter = [], int $limit = self::PAGE): string {
        $out = [];
        foreach (self::lines($filter, $limit) as $line) {
            $out[] = $line['stamp'] . '  ' . str_pad($line['source'], 9) . '  ' . $line['text'];
        }

        return implode("\n", $out);
    }

    /** @var int The most lines a download carries. */
    public const EXPORT_LIMIT = 5000;

    /**
     * The filtered selection as a file: text or JSON, saying what it holds (#132 AK 5).
     *
     * A download used to be the newest 5000 lines without a word about it:
     * whether the selection had more, which window it covered and which filter
     * made it was not in the file. Now each file names its filter, time range,
     * line limit, whether it is cut, and what was read to make it.
     *
     * @param array $filter As for lines().
     * @param string $format 'txt' or 'json'.
     * @param int $limit The most lines.
     * @return string
     */
    public static function export(array $filter, string $format, int $limit = self::EXPORT_LIMIT): string {
        $window = self::window($filter, $limit, 0);
        $meta = self::export_metadata($filter, $window);
        if ($format === 'json') {
            return (string) json_encode($meta + ['lines' => $window['lines']], JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $out = [];
        foreach ($meta as $key => $value) {
            $out[] = '# ' . $key . ': ' . (is_scalar($value) ? var_export($value, true)
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        foreach ($window['lines'] as $line) {
            $out[] = $line['stamp'] . '  ' . str_pad($line['source'], 9) . '  ' . $line['text'];
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * What a download holds: filter, time range, limit, truncation, order, what was read.
     *
     * @param array $filter The filter.
     * @param array $window The window() it was made from.
     * @return array
     */
    public static function export_metadata(array $filter, array $window): array {
        $until = (int) ($filter['until'] ?? 0);
        $since = (int) ($filter['since'] ?? 0);

        return [
            'generated' => date('c'),
            'filter' => array_filter($filter, static fn($value): bool => $value !== '' && $value !== 0 && $value !== null),
            'from' => $since > 0 ? date('c', $since) : null,
            'to' => date('c', $until > 0 ? $until : time()),
            'order' => !empty($filter['newestfirst']) ? 'newest first' : 'oldest first',
            'limit' => (int) $window['limit'],
            'count' => count($window['lines']),
            // Cut: there are older lines in the selection than the file holds.
            'truncated' => (bool) $window['hasolder'],
            // Not read: the request traces were larger than one view reads.
            'partial' => (bool) $window['scan']['partial'],
            'scan' => $window['scan'],
        ];
    }

    /** @var int A worker request that took this long, in milliseconds, is worth a warning. */
    public const SLOW_REQUEST_MS = 10000;

    /**
     * The worker's requests as the server saw them: status, duration, connection, exception (#110).
     *
     * From files, not the database (request_trace says why), so the time window
     * is applied here: the last two days unless one is given.
     *
     * Streamed and bounded (#132): the whole filter is applied to each line as
     * it is read, and only the newest $keep matching lines are held.
     *
     * @param array $filter The filter.
     * @param int $keep How many matching lines to keep, newest.
     * @param array|null $scan Filled with what was read: lines, bytes, skipped bytes, partial, seconds.
     * @return array[]
     */
    protected static function from_requests(array $filter, int $keep = self::PAGE, ?array &$scan = null): array {
        global $DB;

        $started = microtime(true);
        $scan = ['lines' => 0, 'bytes' => 0, 'skippedbytes' => 0, 'partial' => false, 'seconds' => 0.0];
        // A channel of their own: filtering by another used to bring every
        // request along, and read the whole day's file for them (#132).
        if (!empty($filter['channel']) && $filter['channel'] !== 'request') {
            return [];
        }

        $until = (int) ($filter['until'] ?? 0) ?: time();
        $since = (int) ($filter['since'] ?? 0) ?: $until - 2 * DAYSECS;
        // The sittings of the run or experiment asked for, read once: a query
        // per line, or per sitting, was what a large trace multiplied (#132 AK 6).
        $allowed = null;
        if (!empty($filter['runid']) || !empty($filter['experimentid'])) {
            $where = [];
            $params = [];
            if (!empty($filter['runid'])) {
                $where[] = 'a.runid = :runid';
                $params['runid'] = (int) $filter['runid'];
            }
            if (!empty($filter['experimentid'])) {
                $where[] = 'r.experimentid = :experimentid';
                $params['experimentid'] = (int) $filter['experimentid'];
            }
            $allowed = array_flip(array_map('intval', $DB->get_fieldset_sql(
                'SELECT a.id FROM {local_catquizlab_attempt} a JOIN {local_catquizlab_run} r ON r.id = a.runid WHERE '
                    . implode(' AND ', $where),
                $params
            )));
        }
        $wanted = (int) ($filter['attemptid'] ?? 0);

        $plan = request_trace::scan_plan($since, $until, request_trace::SCAN_BYTES);
        $scan['bytes'] = $plan['bytes'];
        $scan['skippedbytes'] = $plan['skippedbytes'];
        $scan['partial'] = $plan['skippedbytes'] > 0;
        $lines = [];
        $i = 0;
        foreach (request_trace::each($since, $until, $plan) as $request) {
            $i++;
            $attemptid = (int) ($request['attemptid'] ?? 0);
            if ($wanted > 0 && $wanted !== $attemptid) {
                continue;
            }
            if ($allowed !== null && !isset($allowed[$attemptid])) {
                continue;
            }
            $runid = 0;

            $status = (int) ($request['status'] ?? 0);
            $connection = (string) ($request['connection'] ?? 'normal');
            $exception = $request['exception'] ?? null;
            $fatal = $request['fatal'] ?? null;
            $text = sprintf(
                '%s %s → %d in %.1f s',
                $request['method'] ?? '',
                $request['path'] ?? '',
                $status,
                ((int) ($request['durationms'] ?? 0)) / 1000
            );
            if ($connection !== 'normal') {
                $text .= ', connection ' . $connection;
            }
            $text .= sprintf(', %.1f MB', (float) ($request['peakmb'] ?? 0));
            if (is_array($exception)) {
                $text .= ' — ' . $exception['class'] . ($exception['errorcode'] !== '' ? ' [' . $exception['errorcode'] . ']' : '')
                    . ': ' . $exception['message']
                    . ($exception['debuginfo'] !== '' ? ' (' . $exception['debuginfo'] . ')' : '')
                    . ' at ' . $exception['file'] . ':' . $exception['line'];
            }
            if (is_array($fatal)) {
                $text .= ' — PHP fatal: ' . $fatal['message'] . ' at ' . $fatal['file'] . ':' . $fatal['line'];
            }
            $failed = $status >= 500 || is_array($exception) || is_array($fatal) || $connection !== 'normal';
            $slow = (int) ($request['durationms'] ?? 0) >= self::SLOW_REQUEST_MS || $status >= 400;
            $ms = (int) $request['unixms'];
            $line = self::line(intdiv($ms, 1000), $i, 'request', $text, [
                'ms' => $ms,
                'runid' => $runid,
                'attemptid' => $attemptid,
                'correlationid' => (string) ($request['correlationid'] ?? ''),
                'failed' => $failed,
                'severity' => $failed ? self::ERROR : ($slow ? self::WARNING : self::INFO),
                'action' => 'request',
            ]);
            if (!self::matches($line, $filter)) {
                continue;
            }
            $lines[] = $line;
            // Trimmed in batches and by position in the file, which is the order
            // the requests ended in: no sort per batch. Twice what is needed is
            // kept, so a long request that began before shorter ones it ended
            // after still finds its place in the final sort.
            if (count($lines) >= 4 * $keep) {
                $lines = array_slice($lines, -2 * $keep);
            }
        }
        $lines = self::newest($lines, $keep);

        // The run of each line kept, in one query.
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($lines, 'attemptid')))));
        if ($ids !== []) {
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'att');
            $runs = $DB->get_records_select_menu('local_catquizlab_attempt', 'id ' . $insql, $params, '', 'id, runid');
            foreach ($lines as &$line) {
                $line['runid'] = (int) ($runs[$line['attemptid']] ?? 0);
            }
            unset($line);
        }
        $scan['lines'] = $i;
        $scan['seconds'] = round(microtime(true) - $started, 3);

        return $lines;
    }

    /**
     * Lines from the debug trace.
     *
     * @param array $filter The filter.
     * @param int $keep How many lines the page needs, newest (#132).
     * @return array[]
     */
    protected static function from_debug(array $filter, int $keep = self::PAGE): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_debug')) {
            return [];
        }

        $where = ['1=1'];
        $params = [];

        self::apply_time($filter, $where, $params);

        // Channel, run, sitting — and the experiment, which the debug log
        // records too and which used to be ignored here: filtering by an
        // experiment dropped its worker and debug lines (#90).
        foreach (['channel' => 'channel', 'runid' => 'runid', 'attemptid' => 'attemptid'] as $key => $column) {
            if (!empty($filter[$key])) {
                $where[] = $column . ' = :' . $key;
                $params[$key] = $filter[$key];
            }
        }
        if (!empty($filter['experimentid'])) {
            $where[] = '(experimentid = :experimentid OR runid IN (SELECT id FROM {local_catquizlab_run}'
                . ' WHERE experimentid = :experimentid2))';
            $params['experimentid'] = $filter['experimentid'];
            $params['experimentid2'] = $filter['experimentid'];
        }

        $rows = $DB->get_records_select(
            'local_catquizlab_debug',
            implode(' AND ', $where),
            $params,
            // The newest rows: the oldest 2000 of a busy window used to be all
            // the page ever saw, however much happened after them (#132).
            'timecreated DESC, id DESC',
            '*',
            0,
            max(self::SOURCE_ROWS, $keep)
        );
        $rows = array_reverse($rows, true);

        $lines = [];
        foreach ($rows as $row) {
            $detail = json_decode((string) $row->detail, true) ?: [];
            $text = $row->action
                . ($row->runid ? ' run=' . $row->runid : '')
                . ($row->outcome && $row->outcome !== 'ok' ? ' ' . $row->outcome : '')
                . ($row->params ? ' ' . $row->params : '')
                . ($detail !== [] ? ' ' . json_encode($detail, JSON_UNESCAPED_SLASHES) : '');

            $lines[] = self::line((int) $row->timecreated, (int) $row->id, $row->channel, $text, [
                'runid'         => (int) $row->runid,
                'correlationid' => (string) ($row->correlationid ?? ''),
                'failed'        => $row->outcome === 'error',
                'severity'      => $row->outcome === 'error' ? self::ERROR
                    : ($row->outcome === 'warning' ? self::WARNING : self::DEBUG),
                'action'        => (string) $row->action,
                'userid'        => (int) ($row->userid ?? 0),
                'ms'            => (int) ($row->timecreatedms ?? 0),
                'workerid'      => (string) ($row->workerid ?? ''),
                // The sitting, under its own name: it used to be filed as
                // "attemptno", the lifecycle attempt of a run — another thing.
                'attemptid'     => (int) ($row->attemptid ?? 0),
            ]);
        }

        return $lines;
    }

    /**
     * Lines from the sittings' histories: every execution, started, failed,
     * collected, put back — in the same chronology as the worker and the run
     * lifecycle (#90).
     *
     * @param array $filter The filter.
     * @param int $keep How many lines the page needs, newest (#132).
     * @return array[]
     */
    protected static function from_attemptlog(array $filter, int $keep = self::PAGE): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
            return [];
        }
        if (!empty($filter['channel']) && $filter['channel'] !== 'attempt') {
            return [];
        }

        $where = ['1=1'];
        $params = [];
        self::apply_time($filter, $where, $params);
        foreach (['runid' => 'runid', 'attemptid' => 'attemptid'] as $key => $column) {
            if (!empty($filter[$key])) {
                $where[] = $column . ' = :' . $key;
                $params[$key] = $filter[$key];
            }
        }
        if (!empty($filter['experimentid'])) {
            $where[] = 'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = :experimentid)';
            $params['experimentid'] = $filter['experimentid'];
        }

        $rows = $DB->get_records_select(
            'local_catquizlab_attemptlog',
            implode(' AND ', $where),
            $params,
            // The newest rows: the oldest 2000 of a busy window used to be all
            // the page ever saw, however much happened after them (#132).
            'timecreated DESC, id DESC',
            '*',
            0,
            max(self::SOURCE_ROWS, $keep)
        );
        $rows = array_reverse($rows, true);

        $lines = [];
        foreach ($rows as $row) {
            $failed = in_array($row->outcome, [attempt_history::FAILED, attempt_history::ABANDONED], true);
            $text = 'sitting=' . $row->attemptid . ' run=' . $row->runid . ' try=' . $row->tryno . ' ' . $row->outcome
                . (!empty($row->reasoncode) ? ' reason=' . $row->reasoncode : '')
                . (!empty($row->workerid) ? ' worker=' . $row->workerid : '')
                . (!empty($row->detail) ? ' ' . attempt_history::redact((string) $row->detail) : '');
            $lines[] = self::line((int) $row->timecreated, (int) $row->id, 'attempt', $text, [
                'runid'         => (int) $row->runid,
                'attemptid'     => (int) $row->attemptid,
                'correlationid' => (string) ($row->correlationid ?? ''),
                'failed'        => $failed,
                'severity'      => $failed ? self::ERROR : self::INFO,
                'action'        => 'attempt_' . $row->outcome,
                'workerid'      => (string) ($row->workerid ?? ''),
            ]);
        }

        return $lines;
    }

    /**
     * Lines from the per-run execution log.
     *
     * @param array $filter The filter.
     * @param int $keep How many lines the page needs, newest (#132).
     * @return array[]
     */
    protected static function from_runlog(array $filter, int $keep = self::PAGE): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_runlog')) {
            return [];
        }

        // Only when asked for, or when no channel is: filtering by "worker"
        // used to bring the whole run lifecycle along, which is the opposite
        // of what a channel filter is for.
        if (!empty($filter['channel']) && $filter['channel'] !== 'lifecycle') {
            return [];
        }

        $where = ['1=1'];
        $params = [];

        self::apply_time($filter, $where, $params);

        if (!empty($filter['runid'])) {
            $where[] = 'runid = :runid';
            $params['runid'] = $filter['runid'];
        }

        if (!empty($filter['experimentid'])) {
            $where[] = 'runid IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = :experimentid)';
            $params['experimentid'] = $filter['experimentid'];
        }

        $rows = $DB->get_records_select(
            'local_catquizlab_runlog',
            implode(' AND ', $where),
            $params,
            // The newest rows: the oldest 2000 of a busy window used to be all
            // the page ever saw, however much happened after them (#132).
            'timecreated DESC, id DESC',
            '*',
            0,
            max(self::SOURCE_ROWS, $keep)
        );
        $rows = array_reverse($rows, true);

        $lines = [];
        foreach ($rows as $row) {
            $detail = json_decode((string) $row->detail, true) ?: [];
            $text = 'run=' . $row->runid . ' attempt#' . $row->attemptno . ' ' . $row->event
                . ($row->stage ? ' stage=' . $row->stage : '')
                . ($row->dbqueries ? ' queries=' . $row->dbqueries : '')
                . ($detail !== [] ? ' ' . json_encode($detail, JSON_UNESCAPED_SLASHES) : '');

            $lines[] = self::line((int) $row->timecreated, (int) $row->id, 'lifecycle', $text, [
                'runid'         => (int) $row->runid,
                'correlationid' => (string) ($row->correlationid ?? ''),
                'failed'        => $row->event === run_log::STAGE_FAILED || $row->event === run_log::RUN_FAILED,
                'severity'      => in_array($row->event, [run_log::STAGE_FAILED, run_log::RUN_FAILED], true)
                    ? self::ERROR
                    : (in_array($row->event, [run_log::RUN_AUTOPAUSED, run_log::ABILITIES_OUTSIDE], true)
                        ? self::WARNING : self::INFO),
                'action'        => (string) $row->event,
                'attemptno'     => (int) ($row->attemptno ?? 0),
                'userid'        => (int) ($row->userid ?? 0),
                'ms'            => (int) ($row->timecreatedms ?? 0),
                'taskid'        => (int) ($row->taskid ?? 0),
            ]);
        }

        return $lines;
    }

    /**
     * Lines from Moodle's own ad-hoc task table.
     *
     * The queue as Moodle sees it, which is the part this plugin does not
     * control and the part people most often need to see: a task waiting out an
     * eight-hour retry looks like nothing happening at all.
     *
     * @param array $filter The filter.
     * @return array[]
     */
    protected static function from_tasks(array $filter): array {
        global $DB;

        if (!empty($filter['channel']) && $filter['channel'] !== 'task') {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(task_overview::ADHOC, SQL_PARAMS_NAMED, 'cls');

        $lines = [];
        foreach ($DB->get_records_select('task_adhoc', 'classname ' . $insql, $params) as $row) {
            $data = json_decode((string) $row->customdata, true) ?: [];
            $runid = (int) ($data['runid'] ?? 0);

            if (!empty($filter['runid']) && $runid !== (int) $filter['runid']) {
                continue;
            }

            $text = 'queued ' . self::short_class($row->classname)
                . ($runid ? ' run=' . $runid : '')
                . ' due=' . duration::until((int) $row->nextruntime)
                . ((int) $row->faildelay > 0 ? ' retrying in ' . duration::human((int) $row->faildelay) : '');

            $lines[] = self::line((int) $row->timecreated, (int) $row->id, 'task', $text, [
                'runid'  => $runid,
                'failed' => (int) $row->faildelay > 0,
            ]);
        }

        return $lines;
    }

    /**
     * The pipeline's last decision about starting a worker.
     *
     * Recorded on every tick whether or not debug recording is on: it is the
     * one line that answers "why is nothing running", and it used to exist
     * only in cron's output.
     *
     * @param array $filter The filter.
     * @return array[]
     */
    protected static function from_dispatch(array $filter): array {
        if (!empty($filter['channel']) && $filter['channel'] !== 'task') {
            return [];
        }

        $last = json_decode((string) get_config('local_catquizlab', 'lastdispatch'), true);
        if (!is_array($last) || empty($last['time'])) {
            return [];
        }
        if (!empty($filter['since']) && (int) $last['time'] < (int) $filter['since']) {
            return [];
        }

        $text = 'pipeline tick: started ' . (int) $last['launched'] . ' worker(s), '
            . (int) $last['claimable'] . ' sitting(s) claimable'
            . ((string) $last['reason'] !== '' ? ', reason=' . $last['reason'] : '');

        return [self::line((int) $last['time'], PHP_INT_MAX, 'task', $text, [
            'failed' => (int) $last['launched'] === 0 && (string) $last['reason'] !== '',
        ])];
    }

    /**
     * Remove entries older than the configured retention.
     *
     * A debug recording on a busy installation writes a row per service call;
     * left alone it grows without a ceiling. Nothing here is the record of an
     * experiment — that is in the run tables and the results — so a window
     * that covers troubleshooting is enough.
     *
     * @return int Rows removed.
     */
    public static function prune(): int {
        global $DB;

        $retention = (int) get_config('local_catquizlab', 'logretention');
        if ($retention <= 0) {
            return 0;
        }

        $cutoff = time() - $retention;
        $removed = 0;

        foreach (['local_catquizlab_debug', 'local_catquizlab_runlog'] as $table) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $removed += (int) $DB->count_records_select($table, 'timecreated < ?', [$cutoff]);
            $DB->delete_records_select($table, 'timecreated < ?', [$cutoff]);
        }

        return $removed;
    }

    /**
     * When the most recent entry of any source was written.
     *
     * @return int A timestamp, or 0 when nothing was ever recorded.
     */
    public static function latest(): int {
        global $DB;

        $times = [0];
        foreach (['local_catquizlab_runlog', 'local_catquizlab_debug'] as $table) {
            if ($DB->get_manager()->table_exists($table)) {
                $times[] = (int) $DB->get_field_sql('SELECT MAX(timecreated) FROM {' . $table . '}');
            }
        }
        $last = json_decode((string) get_config('local_catquizlab', 'lastdispatch'), true);
        $times[] = (int) ($last['time'] ?? 0);

        return max($times);
    }

    /**
     * Add the time window to a query.
     *
     * @param array $filter The filter.
     * @param array $where Conditions, by reference.
     * @param array $params Parameters, by reference.
     * @return void
     */
    protected static function apply_time(array $filter, array &$where, array &$params): void {
        if (!empty($filter['since'])) {
            $where[] = 'timecreated >= :since';
            $params['since'] = (int) $filter['since'];
        }

        if (!empty($filter['until'])) {
            $where[] = 'timecreated <= :until';
            $params['until'] = (int) $filter['until'];
        }
    }

    /**
     * Build one line.
     *
     * @param int $time When.
     * @param int $seq A tiebreaker within the same second.
     * @param string $source Which log it came from.
     * @param string $text What it says.
     * @param array $meta Run, correlation and whether it failed.
     * @return array
     */
    protected static function line(int $time, int $seq, string $source, string $text, array $meta = []): array {
        return [
            'time'   => $time,
            'seq'    => $seq,
            'source' => $source,
            'text'   => trim($text),
            // Filled in for the lines that are shown, not for every line read (#132).
            'stamp'  => '',
            'hasms'  => isset($meta['ms']) && $meta['ms'] > 0,
            'ms'     => (int) ($meta['ms'] ?? ((int) $time * 1000)),
            'workerid' => (string) ($meta['workerid'] ?? ''),
            'taskid'   => (int) ($meta['taskid'] ?? 0),
            'runid'  => (int) ($meta['runid'] ?? 0),
            'correlationid' => (string) ($meta['correlationid'] ?? ''),
            'failed' => !empty($meta['failed']),
            // A level on every line, so a reader can ask for the errors
            // without knowing which of the four sources wrote them.
            'severity' => (string) ($meta['severity'] ?? (!empty($meta['failed']) ? self::ERROR : self::INFO)),
            'action'   => (string) ($meta['action'] ?? ''),
            'attemptno' => (int) ($meta['attemptno'] ?? 0),
            'attemptid' => (int) ($meta['attemptid'] ?? 0),
            'userid'   => (int) ($meta['userid'] ?? 0),
        ];
    }

    /**
     * A line's time as shown: ISO-ish and sortable, with milliseconds where the source has them.
     *
     * A support thread is read by somebody in another timezone as often as not.
     *
     * @param array $line A line.
     * @return string
     */
    protected static function stamp(array $line): string {
        return userdate((int) $line['time'], '%Y-%m-%d %H:%M:%S')
            . (!empty($line['hasms']) ? sprintf('.%03d', (int) $line['ms'] % 1000) : '');
    }

    /** @var string Everything that happened, in order. */
    public const DEBUG = 'debug';

    /** @var string The normal course of events. */
    public const INFO = 'info';

    /** @var string Something that deserves attention but is not a failure. */
    public const WARNING = 'warning';

    /** @var string Something failed. */
    public const ERROR = 'error';

    /** @var string[] The levels, least to most severe. */
    public const SEVERITIES = [self::DEBUG, self::INFO, self::WARNING, self::ERROR];

    /**
     * Whether a line is at least as severe as the level asked for.
     *
     * @param string $line The line's severity.
     * @param string $wanted The lowest severity to show.
     * @return bool
     */
    public static function at_least(string $line, string $wanted): bool {
        $order = array_flip(self::SEVERITIES);

        return ($order[$line] ?? 1) >= ($order[$wanted] ?? 0);
    }

    /**
     * Whether a line passes the filters that are applied after it is gathered.
     *
     * Applied here rather than in each source's query: the sources store
     * different columns, and a reader asking for "everything about correlation
     * X, errors only, oldest first" should not have to know which of them can
     * answer in SQL. Line by line, so that a source can drop what does not
     * match while it reads instead of after it has read everything (#132).
     *
     * @param array $line A line.
     * @param array $filter The filter.
     * @return bool
     */
    protected static function matches(array $line, array $filter): bool {
        $search = trim((string) ($filter['search'] ?? ''));
        $severity = (string) ($filter['severity'] ?? '');
        $action = trim((string) ($filter['action'] ?? ''));
        $correlation = trim((string) ($filter['correlationid'] ?? ''));
        $attempt = (int) ($filter['attemptno'] ?? 0);
        $userid = (int) ($filter['userid'] ?? 0);
        $until = (int) ($filter['until'] ?? 0);
        $worker = trim((string) ($filter['workerid'] ?? ''));
        $task = (int) ($filter['taskid'] ?? 0);
        $sitting = (int) ($filter['attemptid'] ?? 0);

        return ($search === '' || stripos((string) $line['text'], $search) !== false)
            && ($severity === '' || self::at_least((string) $line['severity'], $severity))
            && ($action === '' || stripos((string) $line['action'] . ' ' . $line['text'], $action) !== false)
            && ($correlation === '' || stripos((string) $line['correlationid'], $correlation) !== false)
            && ($sitting <= 0 || (int) $line['attemptid'] === $sitting)
            && ($attempt <= 0 || (int) $line['attemptno'] === $attempt)
            && ($userid <= 0 || (int) $line['userid'] === $userid)
            && ($until <= 0 || (int) $line['time'] <= $until)
            && ($worker === '' || stripos((string) $line['workerid'], $worker) !== false)
            && ($task <= 0 || (int) $line['taskid'] === $task);
    }

    /**
     * A task class without its namespace.
     *
     * @param string $classname The class.
     * @return string
     */
    protected static function short_class(string $classname): string {
        $parts = explode('\\', trim($classname, '\\'));

        return end($parts) ?: $classname;
    }
}
