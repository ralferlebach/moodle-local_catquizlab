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
 * What was clicked, what ran, and what came back — in one sequence.
 *
 * Diagnosis was spread over Moodle notifications, ad-hoc task logs, worker logs,
 * the run manifest, the operations page, database state and Moodle's own
 * debugging. Each holds a fragment; none holds the order. So a defect could not
 * be reconstructed as what it is: somebody pressed a button, a handler ran with
 * certain parameters, something changed, and an error came back.
 *
 * Two things keep this from becoming a second problem:
 *
 * It is off by default and on deliberately, because an installation that
 * records every action all the time is one where nobody reads the recording.
 *
 * It is a ring buffer. The question this answers is "what just happened", and a
 * table that grows without limit answers it worse the longer it runs.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debug_trace {
    /** @var string An operator pressed something. */
    public const UI = 'ui';

    /** @var string A web service was called. */
    public const SERVICE = 'service';

    /** @var string A task ran. */
    public const TASK = 'task';

    /** @var string A worker reported. */
    public const WORKER = 'worker';

    /** @var string A run changed state. */
    public const LIFECYCLE = 'lifecycle';

    /** @var int How many entries to keep. */
    public const KEEP = 2000;

    /** @var int Seconds after which an entry is dropped regardless of count. */
    public const KEEP_SECONDS = 7 * DAYSECS;

    /** @var string Nothing is recorded. */
    public const LEVEL_OFF = 'off';

    /** @var string Operator actions and state changes. */
    public const LEVEL_ACTION = 'action';

    /** @var string And service calls, tasks and worker reports. */
    public const LEVEL_VERBOSE = 'verbose';

    /** @var string And the exception detail behind a failure. */
    public const LEVEL_TRACE = 'trace';

    /** @var string|null The id tying this request's entries together. */
    protected static $correlationid = null;

    /** @var string|null The task class running right now. */
    protected static $task = null;

    /** @var array The id, retry delay and attempt count of that task. */
    protected static $taskmeta = [];

    /**
     * The id every entry of this request carries.
     *
     * One click produces entries in the trace, the run log, an ad-hoc task and
     * a worker. Without a shared id they are a list of things that happened
     * near each other, and reading a defect means guessing which belong
     * together.
     *
     * @return string
     */
    public static function correlation_id(): string {
        if (self::$correlationid === null) {
            self::$correlationid = substr(md5(uniqid((string) mt_rand(), true)), 0, 32);
        }

        return self::$correlationid;
    }

    /**
     * Adopt an id from elsewhere — a task carrying one from the click that queued it.
     *
     * @param string $correlationid The id to continue.
     * @return void
     */
    public static function continue_correlation(string $correlationid): void {
        if (trim($correlationid) !== '') {
            self::$correlationid = substr(trim($correlationid), 0, 32);
        }
    }

    /**
     * The recording level.
     *
     * @return string
     */
    public static function level(): string {
        $level = (string) get_config('local_catquizlab', 'debuglevel');

        return in_array($level, [self::LEVEL_ACTION, self::LEVEL_VERBOSE, self::LEVEL_TRACE], true)
            ? $level
            : self::LEVEL_OFF;
    }

    /**
     * Whether a channel is recorded at the current level.
     *
     * @param string $channel The channel.
     * @return bool
     */
    protected static function records(string $channel): bool {
        $level = self::level();

        if ($level === self::LEVEL_OFF) {
            return false;
        }

        if ($level === self::LEVEL_ACTION) {
            // What a person did and what changed because of it. The rest is
            // volume that makes those two harder to find.
            return in_array($channel, [self::UI, self::LIFECYCLE], true);
        }

        return true;
    }

    /** @var string[] Parameter names never written down. */
    protected const SECRET = ['token', 'wstoken', 'password', 'sesskey', 'secret'];

    /**
     * Whether the debug mode is on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return self::level() !== self::LEVEL_OFF;
    }

    /**
     * Say which task is running, and continue the id that queued it.
     *
     * Declared rather than guessed: Moodle knows what is running, and asking it
     * from inside the run is a lookup that answers "something is running"
     * rather than "this is".
     *
     * @param string $classname The task class.
     * @param string $correlationid The id from the action that queued it, if any.
     * @param int $taskid The ad-hoc task row, where there is one.
     * @param int $faildelay How long it is waiting out after a failure.
     * @param int $attempt How many attempts remain.
     * @return void
     */
    public static function enter_task(
        string $classname,
        string $correlationid = '',
        int $taskid = 0,
        int $faildelay = 0,
        int $attempt = 0
    ): void {
        self::$task = $classname;
        self::$taskmeta = [
            'taskid'    => $taskid,
            'faildelay' => $faildelay,
            'attempt'   => $attempt,
        ];
        self::continue_correlation($correlationid);
    }

    /**
     * What is known about the task running right now.
     *
     * A failure inside a task that has already failed twice and is waiting out
     * an eight-hour delay is a different situation from a first attempt, and
     * the log said the same thing about both.
     *
     * @return array{taskid: int, faildelay: int, attempt: int}
     */
    public static function task_meta(): array {
        return self::$taskmeta + ['taskid' => 0, 'faildelay' => 0, 'attempt' => 0];
    }

    /**
     * Leave the task context.
     *
     * @return void
     */
    public static function leave_task(): void {
        self::$task = null;
        self::$taskmeta = [];
    }

    /**
     * Forget the correlation id and the task context.
     *
     * A web request ends and takes these with it; a test process runs many
     * scenarios and would otherwise carry one scenario's task into the next.
     *
     * @return void
     */
    public static function reset_for_testing(): void {
        self::$correlationid = null;
        self::$task = null;
        self::$taskmeta = [];
    }

    /**
     * The task running right now, where one is.
     *
     * @return string|null
     */
    protected static function current_task(): ?string {
        return self::$task;
    }

    /**
     * The task class running right now, for anything that stores it.
     *
     * @return string|null
     */
    public static function task_classname(): ?string {
        return self::$task;
    }

    /**
     * Record one event, if the mode is on.
     *
     * @param string $channel One of the class constants.
     * @param string $action What was done.
     * @param array $params What it was given.
     * @param string $outcome ok, refused or error.
     * @param array $detail Result, reason or exception.
     * @param int $runid The run it concerned, where one did.
     * @return void
     */
    public static function record(
        string $channel,
        string $action,
        array $params = [],
        string $outcome = 'ok',
        array $detail = [],
        int $runid = 0
    ): void {
        global $DB, $USER, $PAGE;

        if (!self::records($channel) || !$DB->get_manager()->table_exists('local_catquizlab_debug')) {
            return;
        }

        try {
            $DB->insert_record('local_catquizlab_debug', (object) [
                'channel'     => $channel,
                'action'      => \core_text::substr($action, 0, 80),
                'page'        => self::current_page(),
                'params'      => self::redact($params),
                'outcome'     => $outcome,
                'detail'      => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_SLASHES),
                'runid'         => $runid,
                'correlationid' => self::correlation_id(),
                'taskclassname' => self::current_task(),
                'userid'        => (int) ($USER->id ?? 0),
                'timecreated' => time(),
            ]);

            self::trim();
        } catch (\Throwable $e) {
            // Recording must never be why something fails. A defect nobody can
            // reconstruct is bad; a plugin that breaks while writing about
            // itself is worse.
            return;
        }
    }

    /**
     * Record an exception, with where it came from.
     *
     * @param string $channel One of the class constants.
     * @param string $action What was being done.
     * @param \Throwable $e What went wrong.
     * @param int $runid The run it concerned.
     * @return void
     */
    public static function exception(string $channel, string $action, \Throwable $e, int $runid = 0): void {
        self::record($channel, $action, [], 'error', [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            // One frame, not the whole trace: the line that threw is the
            // question, and a hundred frames in a table cell is not an answer.
            'where'     => basename($e->getFile()) . ':' . $e->getLine(),
        ], $runid);
    }

    /**
     * The recorded events, newest first.
     *
     * @param array $filter channel, action, runid or since.
     * @param int $limit How many to return.
     * @return array[]
     */
    public static function entries(array $filter = [], int $limit = 200): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_debug')) {
            return [];
        }

        $conditions = [];
        foreach (['channel', 'runid', 'correlationid'] as $key) {
            if (!empty($filter[$key])) {
                $conditions[$key] = $filter[$key];
            }
        }

        $rows = [];
        foreach ($DB->get_records('local_catquizlab_debug', $conditions, 'id DESC', '*', 0, $limit) as $row) {
            $rows[] = [
                'id'      => (int) $row->id,
                // The thread through the entries: filtering on it turns the
                // list into the sequence of one click.
                'correlationid' => (string) ($row->correlationid ?? ''),
                'shortid'       => substr((string) ($row->correlationid ?? ''), 0, 8),
                'taskclassname' => (string) ($row->taskclassname ?? ''),
                'channel' => $row->channel,
                'action'  => $row->action,
                'page'    => (string) $row->page,
                'params'  => (string) $row->params,
                'outcome' => (string) $row->outcome,
                'failed'  => $row->outcome === 'error',
                'refused' => $row->outcome === 'refused',
                'detail'  => (string) $row->detail,
                'runid'   => (int) $row->runid,
                'time'    => userdate((int) $row->timecreated, get_string('strftimedatetimeshort')),
            ];
        }

        return $rows;
    }

    /**
     * The recording as a file somebody can attach to a bug report.
     *
     * The console answers "what just happened" on screen; this answers "here is
     * what happened" to somebody who is not at the screen. Same redaction: the
     * parameters were already stored with the secrets removed, so there is
     * nothing to strip on the way out.
     *
     * @param array $filter channel, runid or correlationid.
     * @param int $limit How many entries.
     * @return array
     */
    public static function export(array $filter = [], int $limit = self::KEEP): array {
        global $CFG;

        return [
            'generated'  => date('c'),
            'site'       => $CFG->wwwroot,
            'moodle'     => $CFG->release,
            'plugin'     => (string) get_config('local_catquizlab', 'version'),
            'level'      => self::level(),
            'retention'  => ['entries' => self::KEEP, 'days' => self::KEEP_SECONDS / DAYSECS],
            'filter'     => $filter,
            'entries'    => self::entries($filter, $limit),
        ];
    }

    /**
     * Forget everything recorded so far.
     *
     * @return int How many entries went.
     */
    public static function clear(): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquizlab_debug')) {
            return 0;
        }

        $count = $DB->count_records('local_catquizlab_debug');
        $DB->delete_records('local_catquizlab_debug');

        return $count;
    }

    /**
     * Drop the oldest entries beyond the buffer size.
     *
     * @return void
     */
    protected static function trim(): void {
        global $DB;

        // Age as well as count. A quiet installation keeps two thousand entries
        // for months, and a recording of what somebody did in June is not
        // diagnosis — it is a log of colleagues nobody asked for.
        $DB->delete_records_select(
            'local_catquizlab_debug',
            'timecreated < ?',
            [time() - self::KEEP_SECONDS]
        );

        $count = $DB->count_records('local_catquizlab_debug');
        if ($count <= self::KEEP) {
            return;
        }

        // Only occasionally: trimming on every write would make the recording
        // more expensive than the thing being recorded.
        $cutoff = $DB->get_field_sql(
            'SELECT MIN(id) FROM (SELECT id FROM {local_catquizlab_debug} ORDER BY id DESC LIMIT ?) AS keepers',
            [self::KEEP]
        );

        if ($cutoff) {
            $DB->delete_records_select('local_catquizlab_debug', 'id < ?', [$cutoff]);
        }
    }

    /**
     * Parameters with the secrets taken out.
     *
     * @param array $params What was submitted.
     * @return string|null
     */
    protected static function redact(array $params): ?string {
        if ($params === []) {
            return null;
        }

        $safe = [];
        foreach ($params as $key => $value) {
            $lower = \core_text::strtolower((string) $key);

            $secret = false;
            foreach (self::SECRET as $needle) {
                if (str_contains($lower, $needle)) {
                    $secret = true;
                    break;
                }
            }

            // Recorded as present rather than as its value: knowing a token was
            // sent is diagnostic, knowing which token it was is a liability.
            $safe[$key] = $secret ? '(hidden)' : (is_scalar($value) ? $value : '(' . gettype($value) . ')');
        }

        return json_encode($safe, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Which page this happened on.
     *
     * @return string
     */
    protected static function current_page(): string {
        global $SCRIPT;

        if (!empty($SCRIPT)) {
            return \core_text::substr((string) $SCRIPT, 0, 120);
        }

        return CLI_SCRIPT ? 'cli' : '';
    }
}
