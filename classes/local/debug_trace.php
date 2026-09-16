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

    /** @var string[] Parameter names never written down. */
    protected const SECRET = ['token', 'wstoken', 'password', 'sesskey', 'secret'];

    /**
     * Whether the debug mode is on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (int) get_config('local_catquizlab', 'debugmode') === 1;
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

        if (!self::enabled() || !$DB->get_manager()->table_exists('local_catquizlab_debug')) {
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
                'runid'       => $runid,
                'userid'      => (int) ($USER->id ?? 0),
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
        foreach (['channel', 'runid'] as $key) {
            if (!empty($filter[$key])) {
                $conditions[$key] = $filter[$key];
            }
        }

        $rows = [];
        foreach ($DB->get_records('local_catquizlab_debug', $conditions, 'id DESC', '*', 0, $limit) as $row) {
            $rows[] = [
                'id'      => (int) $row->id,
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
