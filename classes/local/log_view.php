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

    /**
     * The merged log.
     *
     * @param array $filter since, until, channel, experimentid, runid, attemptid, workerid, search.
     * @param int $limit How many lines.
     * @return array[]
     */
    public static function lines(array $filter = [], int $limit = self::PAGE): array {
        $lines = array_merge(
            self::from_debug($filter),
            self::from_runlog($filter),
            self::from_tasks($filter),
            self::from_dispatch($filter)
        );

        // Newest last: a log is read downwards, and a person pasting the tail
        // of it wants the end to be the end. Reversible, because somebody
        // watching something happen wants the newest line first.
        usort($lines, static function (array $a, array $b): int {
            return $a['time'] <=> $b['time'] ?: $a['seq'] <=> $b['seq'];
        });

        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            $lines = array_values(array_filter($lines, static function (array $line) use ($search): bool {
                return stripos($line['text'], $search) !== false;
            }));
        }

        $lines = self::narrow($lines, $filter);
        $lines = array_slice($lines, -$limit);

        if (!empty($filter['newestfirst'])) {
            $lines = array_reverse($lines);
        }

        return $lines;
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

    /**
     * Lines from the debug trace.
     *
     * @param array $filter The filter.
     * @return array[]
     */
    protected static function from_debug(array $filter): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_debug')) {
            return [];
        }

        $where = ['1=1'];
        $params = [];

        self::apply_time($filter, $where, $params);

        foreach (['channel' => 'channel', 'runid' => 'runid'] as $key => $column) {
            if (!empty($filter[$key])) {
                $where[] = $column . ' = :' . $key;
                $params[$key] = $filter[$key];
            }
        }

        $rows = $DB->get_records_select(
            'local_catquizlab_debug',
            implode(' AND ', $where),
            $params,
            'timecreated ASC, id ASC',
            '*',
            0,
            2000
        );

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
            ]);
        }

        return $lines;
    }

    /**
     * Lines from the per-run execution log.
     *
     * @param array $filter The filter.
     * @return array[]
     */
    protected static function from_runlog(array $filter): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_runlog')) {
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
            'timecreated ASC, id ASC',
            '*',
            0,
            2000
        );

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
                    : ($row->event === run_log::RUN_AUTOPAUSED
                        ? self::WARNING : self::INFO),
                'action'        => (string) $row->event,
                'attemptno'     => (int) ($row->attemptno ?? 0),
                'userid'        => (int) ($row->userid ?? 0),
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
            // ISO-ish and sortable: a support thread is read by somebody in
            // another timezone as often as not.
            'stamp'  => userdate($time, '%Y-%m-%d %H:%M:%S'),
            'runid'  => (int) ($meta['runid'] ?? 0),
            'correlationid' => (string) ($meta['correlationid'] ?? ''),
            'failed' => !empty($meta['failed']),
            // A level on every line, so a reader can ask for the errors
            // without knowing which of the four sources wrote them.
            'severity' => (string) ($meta['severity'] ?? (!empty($meta['failed']) ? self::ERROR : self::INFO)),
            'action'   => (string) ($meta['action'] ?? ''),
            'attemptno' => (int) ($meta['attemptno'] ?? 0),
            'userid'   => (int) ($meta['userid'] ?? 0),
        ];
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
     * The filters that narrow the lines after they are gathered.
     *
     * Applied here rather than in each source's query: the four sources store
     * different columns, and a reader asking for "everything about correlation
     * X, errors only, oldest first" should not have to know which of them can
     * answer in SQL.
     *
     * @param array[] $lines The gathered lines.
     * @param array $filter The filter.
     * @return array[]
     */
    protected static function narrow(array $lines, array $filter): array {
        $severity = (string) ($filter['severity'] ?? '');
        $action = trim((string) ($filter['action'] ?? ''));
        $correlation = trim((string) ($filter['correlationid'] ?? ''));
        $attempt = (int) ($filter['attemptno'] ?? 0);
        $userid = (int) ($filter['userid'] ?? 0);
        $until = (int) ($filter['until'] ?? 0);

        return array_values(array_filter($lines, static function (array $line) use (
            $severity,
            $action,
            $correlation,
            $attempt,
            $userid,
            $until
        ): bool {
            if ($severity !== '' && !self::at_least((string) $line['severity'], $severity)) {
                return false;
            }
            if ($action !== '' && stripos((string) $line['action'] . ' ' . $line['text'], $action) === false) {
                return false;
            }
            if ($correlation !== '' && stripos((string) $line['correlationid'], $correlation) === false) {
                return false;
            }
            if ($attempt > 0 && (int) $line['attemptno'] !== $attempt) {
                return false;
            }
            if ($userid > 0 && (int) $line['userid'] !== $userid) {
                return false;
            }
            if ($until > 0 && (int) $line['time'] > $until) {
                return false;
            }

            return true;
        }));
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
