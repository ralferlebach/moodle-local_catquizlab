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
 * What happened when an experiment was deleted, kept beyond the experiment (#145 AK6, #161).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * The record of an experiment's deletion: one JSON line per event in moodledata/local_catquizlab/deletions/
 * experiment-<id>.jsonl, which outlives the experiment's rows.
 *
 * Events: queued (the confirmed POST, with whether deeply and who), started (the background task), engine-before and
 * engine-after per run (the engine footprint before and what of it was left or lost after), failed (with the error;
 * the task is retried), finished (with what is left in the lab's tables, which must be nothing). Each line has the
 * time, the request or task correlation id and the event's data: counts and ids, never names or secrets.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deletion_log {
    /** @var string The directory in moodledata. */
    public const DIRECTORY = 'local_catquizlab/deletions';

    /**
     * Write one event.
     *
     * @param int $experimentid The experiment.
     * @param string $event queued, started, engine-before, engine-after, failed or finished.
     * @param array $data What belongs to it.
     * @return void
     */
    public static function event(int $experimentid, string $event, array $data = []): void {
        global $CFG;

        if ($experimentid <= 0) {
            return;
        }
        $dir = $CFG->dataroot . '/' . self::DIRECTORY;
        if (!is_dir($dir) && !@mkdir($dir, $CFG->directorypermissions ?? 02777, true) && !is_dir($dir)) {
            return;
        }
        $line = ['time' => microtime(true), 'event' => $event, 'experimentid' => $experimentid,
            'correlationid' => debug_trace::correlation_id()] + $data;
        @file_put_contents(
            self::file($experimentid),
            json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * The events of an experiment's deletion, oldest first.
     *
     * @param int $experimentid The experiment.
     * @return array[]
     */
    public static function events(int $experimentid): array {
        $file = self::file($experimentid);
        if (!is_readable($file)) {
            return [];
        }
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * Where an experiment's deletion stands, from its record: deleted (finished, nothing left), failed (the last
     * attempt failed), running (started, not ended), queued, or none.
     *
     * @param int $experimentid The experiment.
     * @return array{state: string, events: int, lastevent: ?array}
     */
    public static function state(int $experimentid): array {
        $events = self::events($experimentid);
        $last = $events === [] ? null : end($events);
        $state = 'none';
        if ($last !== null) {
            $state = [
                'queued' => 'queued', 'started' => 'running', 'engine-before' => 'running', 'engine-after' => 'running',
                'failed' => 'failed', 'finished' => 'deleted',
            ][$last['event']] ?? 'running';
            if ($state === 'deleted' && !empty($last['left'])) {
                $state = 'failed';
            }
        }

        return ['state' => $state, 'events' => count($events), 'lastevent' => $last];
    }

    /**
     * The file of an experiment's record.
     *
     * @param int $experimentid The experiment.
     * @return string
     */
    public static function file(int $experimentid): string {
        global $CFG;

        return $CFG->dataroot . '/' . self::DIRECTORY . '/experiment-' . $experimentid . '.jsonl';
    }
}
