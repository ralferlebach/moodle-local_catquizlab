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
 * The life of every uncertain answer submission, to the millisecond, and on whose side it went wrong (#110 AK-CLARA-01).
 *
 * One event per submission the worker could not be sure of (a CDP timeout, a lost connection, a page without a
 * state, an HTTP error): reconciled and continued (attempt history "recovered"), or the end of its execution ("failed").
 * Laid side by side, each from its own record:
 *
 *   worker   when the POST began and how long the browser waited (the submission record), when the server was asked
 *            about it and what it said (the reconciliation), what became of the execution — collected, failed,
 *            requeued — and of the sitting;
 *   server   the request line of that POST (request_trace: the sitting and the execution in its correlation headers):
 *            when it began, how long it ran, its status, what became of the connection;
 *   engine   the engine's trace of that request (local_catquiz_trace of the sitting's engine attempt): its kind, its
 *            question, its own time and the selector's.
 *
 * Whose side: an HTTP error the server sent is the server's; otherwise the timing decides — no request line, the
 * request never reached PHP (client or network); a request line that ended before the browser gave up (allowing
 * END_SLACK_MS for PHP's shutdown), the server had answered and the browser did not get it (client side: CDP, the
 * browser, the way back); one still running when the browser gave up, the server was too slow (server side). An
 * event without the worker's times or without a sitting to look in is "unattributed" — said, never guessed.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cdp_timeline {
    /** @var string The request never reached PHP: client or network. */
    public const NOT_RECEIVED = 'request_not_received';

    /** @var string The server answered in time, the browser did not get it: client side. */
    public const NOT_DELIVERED = 'response_not_delivered';

    /** @var string The server was still at it, or cut off, when the browser gave up: server side. */
    public const SERVER_SLOW = 'server_slow';

    /** @var string The server answered with an error status: server side. */
    public const SERVER_ERROR = 'server_error';

    /** @var string Not enough recorded to say. */
    public const UNATTRIBUTED = 'unattributed';

    /** @var array<string,string> Cause => side. */
    public const SIDES = [
        self::NOT_RECEIVED => 'client',
        self::NOT_DELIVERED => 'client',
        self::SERVER_SLOW => 'server',
        self::SERVER_ERROR => 'server',
        self::UNATTRIBUTED => 'unknown',
    ];

    /** @var string[] The submission classes the worker reconciles (navigation.js UNCERTAIN), as reason codes. */
    public const UNCERTAIN = ['submit_cdp_timeout', 'submit_connect_failed', 'submit_connect_timeout', 'submit_no_state',
        'submit_http_5xx', 'submit_http_404', 'submit_http_4xx', 'submit_unexpected_redirect'];

    /**
     * @var int Milliseconds a request line may start before the worker's click: none on one machine's clock (the
     *     click comes first), a little for rounding. Wider took the answer before it (local run 22.11.2026: the
     *     previous POST one second earlier read as the lost request's).
     */
    public const SLACK_MS = 100;

    /** @var int Milliseconds a request line may end after the browser had its response (PHP's shutdown). */
    public const END_SLACK_MS = 1000;

    /**
     * Whose side an uncertain submission went wrong on.
     *
     * @param string $class The submission class (reason code without "submit_").
     * @param int|null $waitedms How long the browser waited, ms; null when not recorded.
     * @param float|null $postedms When the POST began, epoch ms; null when not recorded.
     * @param array|null $line The server's request line of that POST (unixms, durationms, status, connection); null for none.
     * @return string One of the causes.
     */
    public static function classify(string $class, ?int $waitedms, ?float $postedms, ?array $line): string {
        if ($line !== null && (int) ($line['status'] ?? 0) >= 500) {
            return self::SERVER_ERROR;
        }
        if (in_array($class, ['http_5xx', 'http_404', 'http_4xx', 'unexpected_redirect'], true)) {
            // The server answered, and the browser saw what: an error page or a page it did not expect.
            return $line === null ? self::UNATTRIBUTED : self::SERVER_ERROR;
        }
        if ($waitedms === null || $waitedms < 0 || $postedms === null) {
            return self::UNATTRIBUTED;
        }
        if ($line === null) {
            return self::NOT_RECEIVED;
        }
        $gaveup = $postedms + $waitedms;
        // The request line ends after PHP's shutdown (the engine's trace written, the session closed): the response
        // can be with the browser a little before that. More than END_SLACK_MS later: the server was still at it.
        $ended = (float) $line['unixms'] + (float) ($line['durationms'] ?? 0);
        if ($ended > $gaveup + self::END_SLACK_MS) {
            return self::SERVER_SLOW;
        }
        return self::NOT_DELIVERED;
    }

    /**
     * Every uncertain submission of a window, with its timeline and its cause.
     *
     * @param int $from Unix time.
     * @param int $until Unix time.
     * @return array{events: array[], counts: array, sides: array, unattributed: int}
     */
    public static function report(int $from, int $until): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::UNCERTAIN, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select(
            'local_catquizlab_attemptlog',
            "timecreated >= :from AND timecreated <= :until AND reasoncode {$insql}
             AND outcome IN ('recovered', 'failed')",
            $params + ['from' => $from, 'until' => $until + 5],
            'id ASC',
            'id, attemptid, runid, tryno, outcome, workerid, engineattemptid, detail, diagnosis, correlationid, reasoncode,
             timecreated'
        );
        $lines = [];
        foreach (request_trace::each($from - 120, $until + 120) as $line) {
            if ((int) ($line['attemptid'] ?? 0) > 0) {
                $lines[(int) $line['attemptid']][] = $line;
            }
        }
        $events = [];
        foreach ($rows as $row) {
            $events[] = self::event($row, $lines[(int) $row->attemptid] ?? []);
        }
        $counts = array_fill_keys(array_keys(self::SIDES), 0);
        $sides = ['client' => 0, 'server' => 0, 'unknown' => 0];
        foreach ($events as $event) {
            $counts[$event['cause']]++;
            $sides[self::SIDES[$event['cause']]]++;
        }
        return ['events' => $events, 'counts' => $counts, 'sides' => $sides,
            'unattributed' => $counts[self::UNATTRIBUTED]];
    }

    /**
     * One event: the worker's record, the server's line, the engine's trace, the cause.
     *
     * @param \stdClass $row The attempt history row.
     * @param array[] $lines The sitting's request lines.
     * @return array
     */
    public static function event(\stdClass $row, array $lines): array {
        global $DB;
        $diagnosis = json_decode((string) ($row->diagnosis ?? ''), true) ?: [];
        $entry = (array) ($diagnosis['reconciliation'] ?? []);
        $class = substr((string) $row->reasoncode, strlen('submit_'));
        // Recovered: the reconciliation carries the times (worker 0.7.78+). Failed: the submission's own text does
        // ("[submit:cdp_timeout] … at 2026-…Z in 60004 ms").
        $postedms = null;
        $waitedms = null;
        if (!empty($entry['postedat'])) {
            $postedms = self::ms((string) $entry['postedat']);
            $waitedms = isset($entry['waitedms']) && (int) $entry['waitedms'] >= 0 ? (int) $entry['waitedms'] : null;
        } else if (preg_match('/ at (\d{4}-\d\d-\d\dT[\d:.]+Z) in (\d+) ms/', (string) $row->detail, $m)) {
            $postedms = self::ms($m[1]);
            $waitedms = (int) $m[2];
        }
        $line = null;
        if ($postedms !== null) {
            $upper = $postedms + (int) ($waitedms ?? 0);
            foreach ($lines as $candidate) {
                if (
                    (int) ($candidate['execution'] ?? 0) !== (int) $row->tryno || ($candidate['method'] ?? '') !== 'POST'
                        || !str_contains((string) ($candidate['path'] ?? ''), '/mod/adaptivequiz/attempt.php')
                ) {
                    continue;
                }
                $start = (float) $candidate['unixms'];
                // The first POST of the execution at or after the click, within the browser's wait.
                if (
                    $start >= $postedms - self::SLACK_MS && $start <= $upper
                        && ($line === null || $start < (float) $line['unixms'])
                ) {
                    $line = $candidate;
                }
            }
        }
        $cause = self::classify($class, $waitedms, $postedms, $line);
        $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $row->attemptid], 'id, status, tries');
        $collected = $DB->get_field_select(
            'local_catquizlab_attemptlog',
            'MAX(timecreated)',
            'attemptid = ? AND outcome = ?',
            [$row->attemptid, attempt_history::COLLECTED]
        );
        $next = $DB->get_field_select(
            'local_catquizlab_attemptlog',
            'MIN(timecreated)',
            'attemptid = ? AND tryno > ? AND outcome = ?',
            [$row->attemptid, $row->tryno, attempt_history::STARTED]
        );
        return [
            'attemptid' => (int) $row->attemptid,
            'runid' => (int) $row->runid,
            'execution' => (int) $row->tryno,
            'workerid' => (string) $row->workerid,
            'correlationid' => (string) $row->correlationid,
            'engineattemptid' => (int) $row->engineattemptid,
            'class' => $class,
            'outcome' => (string) $row->outcome,
            'cause' => $cause,
            'side' => self::SIDES[$cause],
            'worker' => [
                'postedat' => $postedms === null ? null : self::iso($postedms),
                'waitedms' => $waitedms,
                'gaveupat' => $postedms === null || $waitedms === null ? null : self::iso($postedms + $waitedms),
                'reconciledat' => $entry['reconciledat'] ?? null,
                'verdict' => $entry['verdict'] ?? ($row->outcome === 'failed' ? 'execution ended' : null),
                'server' => $entry['server'] ?? null,
            ],
            'server' => $line === null ? null : [
                'startedat' => self::iso((float) $line['unixms']),
                'durationms' => (int) ($line['durationms'] ?? 0),
                'endedat' => self::iso((float) $line['unixms'] + (float) ($line['durationms'] ?? 0)),
                'status' => (int) ($line['status'] ?? 0),
                'connection' => (string) ($line['connection'] ?? ''),
                'dbms' => $line['dbms'] ?? null,
                'lockwaitms' => $line['lockwaitms'] ?? null,
            ],
            'engine' => $postedms === null ? null : self::engine(
                (int) $row->engineattemptid,
                $postedms,
                $postedms + (int) ($waitedms ?? 0)
            ),
            'afterwards' => [
                'retriedat' => $next ? gmdate('Y-m-d\TH:i:s\Z', (int) $next) : null,
                'sitting' => $attempt ? (int) $attempt->status : null,
                'collectedat' => $collected ? gmdate('Y-m-d\TH:i:s\Z', (int) $collected) : null,
            ],
        ];
    }

    /**
     * The engine's trace of a request in a window: kind, question, its time and the selector's.
     *
     * @param int $engineattemptid The engine attempt.
     * @param float $fromms Epoch ms.
     * @param float $untilms Epoch ms.
     * @return array|null
     */
    private static function engine(int $engineattemptid, float $fromms, float $untilms): ?array {
        global $DB;
        if ($engineattemptid <= 0 || !$DB->get_manager()->table_exists('local_catquiz_trace')) {
            return null;
        }
        $rows = $DB->get_records_select(
            'local_catquiz_trace',
            'adaptiveattemptid = ? AND timecreated >= ? AND timecreated <= ?',
            [$engineattemptid, (int) floor($fromms / 1000) - 1, (int) ceil($untilms / 1000) + 1],
            'id ASC',
            'id, kind, questionnumber, requestms, spans',
            0,
            1
        );
        $row = reset($rows);
        if (!$row) {
            return null;
        }
        $selector = null;
        foreach ((array) json_decode((string) $row->spans, true) as $span) {
            if (($span['name'] ?? '') === 'strategy') {
                $selector = isset($span['ms']) ? (float) $span['ms'] : null;
                break;
            }
        }
        return ['kind' => (string) $row->kind, 'question' => (int) $row->questionnumber,
            'requestms' => (float) $row->requestms, 'selectorms' => $selector];
    }

    /**
     * Epoch ms of an ISO time.
     *
     * @param string $iso The time.
     * @return float|null
     */
    private static function ms(string $iso): ?float {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $iso, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $iso, new \DateTimeZone('UTC'));
        return $date ? (float) $date->format('U.u') * 1000 : null;
    }

    /**
     * ISO time of epoch ms.
     *
     * @param float $ms Epoch ms.
     * @return string
     */
    private static function iso(float $ms): string {
        return gmdate('Y-m-d\TH:i:s', (int) floor($ms / 1000)) . sprintf('.%03dZ', (int) fmod($ms, 1000));
    }
}
