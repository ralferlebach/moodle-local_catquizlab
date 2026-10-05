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
 * The execution history of a test sitting.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * What was tried on a sitting, by whom, and how it ended.
 *
 * A sitting's own row holds only its latest state: tries, last error, lease.
 * A retry clears those, which is how the diagnosis of the try before it was
 * lost — exactly when it is needed, namely after the retry did not help
 * either. This is append-only: every execution leaves a row, and the row of a
 * failure survives the retry that follows it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_history {
    /** @var string A worker took it and began playing. */
    public const STARTED = 'started';

    /** @var string It finished and was read back. */
    public const COLLECTED = 'collected';

    /** @var string It ended without a result. */
    public const FAILED = 'failed';

    /** @var string Somebody put it back in the queue. */
    public const REQUEUED = 'requeued';

    /** @var string A sitting given up: no tries left (#90). */
    public const ABANDONED = 'abandoned';

    /**
     * Record one execution.
     *
     * @param int $attemptid The sitting.
     * @param string $outcome One of the constants above.
     * @param array $detail workerid, engineattemptid, runtimems, tryno, detail.
     * @return int The new row's id, or 0 when nothing was written.
     */
    public static function record(int $attemptid, string $outcome, array $detail = []): int {
        global $DB;

        if ($attemptid <= 0 || !$DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
            return 0;
        }

        $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid], 'id, runid, tries');
        if (!$attempt) {
            return 0;
        }

        return (int) $DB->insert_record('local_catquizlab_attemptlog', (object) [
            'attemptid'       => $attemptid,
            'runid'           => (int) $attempt->runid,
            'tryno'           => (int) ($detail['tryno'] ?? $attempt->tries),
            'outcome'         => $outcome,
            'workerid'        => $detail['workerid'] ?? null,
            'engineattemptid' => isset($detail['engineattemptid']) ? (int) $detail['engineattemptid'] : null,
            'runtimems'       => isset($detail['runtimems']) ? (int) $detail['runtimems'] : null,
            // Kept whole: this is the thing somebody reads when a retry did
            // not help, and a truncated stack trace is a stack trace nobody
            // can follow.
            'detail'          => isset($detail['detail']) ? self::redact((string) $detail['detail']) : null,
            'diagnosis'       => self::diagnosis_json($detail),
            'correlationid'   => \core_text::substr(debug_trace::correlation_id(), 0, 64),
            // Why it ended, as a code (#107): for a failure now; for a sitting
            // that finished, once the collector knows its stop reason.
            'reasoncode'      => $outcome === self::FAILED
                ? reason_catalog::failure(
                    (string) ($detail['detail'] ?? ''),
                    (array) (json_decode((string) self::diagnosis_json($detail), true) ?: [])
                )
                : null,
            'timecreated'     => time(),
        ]);
    }

    /**
     * The diagnosis to store: the reported text taken apart, plus what the
     * browser recorded (events, statuses, transport, artefacts), secrets removed.
     *
     * @param array $detail What record() was given.
     * @return string|null JSON, or null when there is nothing to say.
     */
    protected static function diagnosis_json(array $detail): ?string {
        $diagnosis = isset($detail['detail']) && $detail['detail'] !== ''
            ? self::diagnose((string) $detail['detail'])
            : [];
        $extra = (array) ($detail['extra'] ?? []);
        foreach (['browser', 'transport', 'artefacts'] as $key) {
            if (!empty($extra[$key])) {
                $diagnosis[$key] = json_decode(self::redact(json_encode($extra[$key], JSON_UNESCAPED_SLASHES)), true);
            }
        }

        return $diagnosis === [] ? null : json_encode($diagnosis, JSON_UNESCAPED_SLASHES);
    }

    /**
     * A reported text without the secrets a page URL can carry.
     *
     * The worker reports the URL of the page it was on, and Moodle puts the
     * session key in some of them. Stored as reported, a session key sat in
     * the sitting's row, in its history, and on the run page for anybody with
     * access to it — the interface test found it there after the history had
     * already been cleaned.
     *
     * @param string $text The reported text.
     * @return string
     */
    public static function redact(string $text): string {
        // In a URL and in free text alike — "sesskey=…" reaches the logs as a
        // parameter list as well as inside a link (#90) — and as a JSON field.
        $text = (string) preg_replace(
            '/((?<![a-z_])(?:sesskey|wstoken|token|password)=)[^&\s"\'|,;]+/i',
            '$1[redacted]',
            $text
        );

        return (string) preg_replace('/("(?:sesskey|wstoken|token|password)"\s*:\s*")[^"]*(")/i', '$1[redacted]$2', $text);
    }

    /**
     * A reported failure, taken apart into what can be filtered and counted.
     *
     * The worker reports one line, built from what the browser saw and what
     * the server replay found. Every field is optional: whatever the line does
     * not say is left out rather than guessed.
     *
     *     Attempt did not reach the finish page after 1 answer(s).
     *       url=https://…/mod/adaptivequiz/attempt.php title="Fehler | Client01"
     *       error="Fehler: Division by zero" errorcode="…"
     *       | server replay: DivisionByZeroError at local/catquiz/…/model_raschmodel.php:734
     *
     * @param string $text The reported failure.
     * @return array phase, answers, slot, url, page, title, error, errorcode, exception, file, line.
     */
    public static function diagnose(string $text): array {
        $text = self::redact($text);
        $found = [];

        $nostart = stripos($text, 'No question was presented') !== false
            || stripos($text, 'attempt never started') !== false;
        $timeout = stripos($text, 'lease') !== false || stripos($text, 'timed out') !== false
            || stripos($text, 'timeout') !== false;
        $server = stripos($text, 'fetch failed') !== false || stripos($text, 'ECONNRESET') !== false
            || stripos($text, 'HTTP ERROR 500') !== false;

        if ($nostart) {
            $found['phase'] = 'start';
            $found['answers'] = 0;
            $found['slot'] = 1;
        } else if (preg_match('/did not reach the finish page after (\d+) answer/i', $text, $m)) {
            // The error came after that many answers, so on the next question.
            $found['phase'] = 'answering';
            $found['answers'] = (int) $m[1];
            $found['slot'] = (int) $m[1] + 1;
        } else if ($timeout) {
            $found['phase'] = 'timeout';
        } else if ($server) {
            $found['phase'] = 'server';
        }

        if (preg_match('/url=(\S+)/', $text, $m)) {
            // Without its session key: this history is kept, and a sesskey in
            // a stored URL is a credential in a table.
            $found['url'] = preg_replace('/([?&])(?:sesskey|wstoken|token)=[^&]*&?/i', '$1', rtrim($m[1], ',;|'));
            $found['url'] = rtrim($found['url'], '?&');
            $found['page'] = basename((string) parse_url($found['url'], PHP_URL_PATH));
        }
        foreach (['title', 'error', 'errorcode'] as $key) {
            if (preg_match('/' . $key . '="([^"]*)"/', $text, $m)) {
                $found[$key] = $m[1];
            }
        }
        // The older form of the same report: "… page=Fehler: Division by zero".
        if (!isset($found['error']) && preg_match('/page=([^|]+)/', $text, $m)) {
            $found['error'] = trim($m[1]);
        }
        // An error code is a code. The worker sometimes reports the text of
        // Moodle's "more information about this error" link in its place.
        if (isset($found['errorcode']) && !preg_match('/^[a-z0-9_\/]+$/i', $found['errorcode'])) {
            unset($found['errorcode']);
        }
        // The Moodle error code, where the message names one in brackets.
        if (!isset($found['errorcode']) && preg_match('/\(([a-z_]+\/[a-z_]+)\)/', $text, $m)) {
            $found['errorcode'] = $m[1];
        }
        // What the server replay located: exception class, file and line.
        if (preg_match('/([A-Za-z_\\\\]+(?:Error|Exception)) at (\S+?):(\d+)/', $text, $m)) {
            $found['exception'] = $m[1];
            $found['file'] = $m[2];
            $found['line'] = (int) $m[3];
        }

        return $found;
    }

    /**
     * Everything that happened to one sitting, oldest first.
     *
     * @param int $attemptid The sitting.
     * @return array[] Rows with tryno, outcome, workerid, detail, when.
     */
    public static function of_attempt(int $attemptid): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
            return [];
        }

        $rows = [];
        foreach ($DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $attemptid], 'id ASC') as $row) {
            $rows[] = [
                'tryno'           => (int) $row->tryno,
                'outcome'         => (string) $row->outcome,
                'outcomelabel'    => get_string('attemptlog:' . $row->outcome, 'local_catquizlab'),
                'failed'          => $row->outcome === self::FAILED,
                'workerid'        => (string) ($row->workerid ?? ''),
                'engineattemptid' => (int) ($row->engineattemptid ?? 0),
                'runtimems'       => (int) ($row->runtimems ?? 0),
                'detail'          => (string) ($row->detail ?? ''),
                'diagnosis'       => json_decode((string) ($row->diagnosis ?? ''), true) ?: [],
                'correlationid'   => (string) ($row->correlationid ?? ''),
                'reasoncode'      => (string) ($row->reasoncode ?? ''),
                'reasonlabel'     => ($row->reasoncode ?? '') !== '' ? reason_catalog::label((string) $row->reasoncode) : '',
                'when'            => userdate((int) $row->timecreated),
                'time'            => (int) $row->timecreated,
            ];
        }

        return $rows;
    }

    /**
     * How often each outcome occurred in a run.
     *
     * @param int $runid The run.
     * @return array<string, int>
     */
    public static function outcomes_of_run(int $runid): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
            return [];
        }

        $counts = [];
        $rows = $DB->get_records_sql(
            'SELECT outcome, COUNT(1) AS n
               FROM {local_catquizlab_attemptlog}
              WHERE runid = :runid
           GROUP BY outcome',
            ['runid' => $runid]
        );
        foreach ($rows as $row) {
            $counts[$row->outcome] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * Record why a finished sitting ended, once its stop reason is known.
     *
     * @param int $attemptid The sitting.
     * @param string $stopreason The stop reason from its trace.
     * @param array $facts What reason_catalog::outcome() tells the ends apart by.
     * @return string The code recorded.
     */
    public static function record_outcome_reason(int $attemptid, string $stopreason, array $facts = []): string {
        global $DB;

        $code = reason_catalog::outcome($stopreason, $facts);
        if (!$DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
            return $code;
        }
        $rows = $DB->get_records(
            'local_catquizlab_attemptlog',
            ['attemptid' => $attemptid, 'outcome' => self::COLLECTED],
            'id DESC',
            'id',
            0,
            1
        );
        $row = reset($rows);
        if ($row) {
            $DB->set_field('local_catquizlab_attemptlog', 'reasoncode', $code, ['id' => $row->id]);
        }

        return $code;
    }

    /**
     * How often a sitting has been put back and failed again since.
     *
     * A sitting that fails the same way after every retry is deterministic:
     * trying it again changes nothing. Counted from the history, so the count
     * survives the retry that clears the sitting's own row.
     *
     * @param int $attemptid The sitting.
     * @return int Retries that were followed by another failure.
     */
    public static function futile_retries(int $attemptid): int {
        $futile = 0;
        $afterrequeue = false;

        foreach (self::of_attempt($attemptid) as $row) {
            if ($row['outcome'] === self::REQUEUED) {
                $afterrequeue = true;
            } else if ($row['outcome'] === self::FAILED && $afterrequeue) {
                $futile++;
                $afterrequeue = false;
            } else if ($row['outcome'] === self::COLLECTED) {
                $futile = 0;
                $afterrequeue = false;
            }
        }

        return $futile;
    }

    /**
     * The last failure recorded for a sitting, whatever has happened since.
     *
     * @param int $attemptid The sitting.
     * @return string Empty when it has never failed.
     */
    public static function last_failure(int $attemptid): string {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_attemptlog')) {
            return '';
        }

        $rows = $DB->get_records(
            'local_catquizlab_attemptlog',
            ['attemptid' => $attemptid, 'outcome' => self::FAILED],
            'id DESC',
            'id, detail',
            0,
            1
        );

        $row = reset($rows);

        return $row ? (string) ($row->detail ?? '') : '';
    }
}
