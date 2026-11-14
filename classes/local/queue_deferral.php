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
 * Other queued work set aside for a test run, and given back exactly.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Defers every other queued sitting of the installation while a test run plays its own, and gives each its time back.
 *
 * A worker takes whatever is claimable, so a smoke or load test on a busy installation would spend its time on other
 * experiments' sittings. Until 0.7.59 the test set them all an hour ahead and, at its end, all to 0: a retry's
 * backoff that the scheduler had set was lost either way (#120, audit 11.11.2026). Here each sitting's own nextruntime
 * is recorded - in the configuration, so a run that was killed is put right by the next - and restored only where the
 * deferral still holds it: a sitting the scheduler has since claimed, finished or given another time keeps that.
 *
 * @package    local_catquizlab
 */
class queue_deferral {
    /** @var string The configuration key holding what was deferred. */
    public const CONFIG = 'smoke_deferred';

    /**
     * Defer every queued sitting outside an experiment.
     *
     * @param int $experimentid The experiment whose sittings stay claimable.
     * @param int $seconds For how long.
     * @return int How many sittings were deferred.
     */
    public static function defer_others(int $experimentid, int $seconds): int {
        global $DB;

        // A record left by a run that did not end cleanly is given back first.
        self::restore();
        $until = time() + $seconds;
        $own = $DB->get_records_sql_menu(
            'SELECT id, nextruntime FROM {local_catquizlab_attempt}
              WHERE status = :queued
                AND runid NOT IN (SELECT id FROM {local_catquizlab_run} WHERE experimentid = :experimentid)',
            ['queued' => attempt_scheduler::STATUS_QUEUED, 'experimentid' => $experimentid]
        );
        if ($own === []) {
            return 0;
        }
        set_config(self::CONFIG, json_encode(['until' => $until, 'nextruntime' => $own]), 'local_catquizlab');
        foreach (array_chunk(array_keys($own), 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $DB->execute(
                'UPDATE {local_catquizlab_attempt} SET nextruntime = :later WHERE status = :queued AND id ' . $in,
                $params + ['later' => $until, 'queued' => attempt_scheduler::STATUS_QUEUED]
            );
        }

        return count($own);
    }

    /**
     * Give every deferred sitting its own nextruntime back, where the deferral is still what holds it.
     *
     * @return int How many sittings were given their time back.
     */
    public static function restore(): int {
        global $DB;

        $record = json_decode((string) get_config('local_catquizlab', self::CONFIG), true);
        unset_config(self::CONFIG, 'local_catquizlab');
        if (!is_array($record) || empty($record['nextruntime'])) {
            return 0;
        }
        $until = (int) ($record['until'] ?? 0);
        $bytime = [];
        foreach ((array) $record['nextruntime'] as $id => $nextruntime) {
            $bytime[(int) $nextruntime][] = (int) $id;
        }
        $restored = 0;
        foreach ($bytime as $nextruntime => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
                $params += ['queued' => attempt_scheduler::STATUS_QUEUED, 'deferred' => $until];
                $restored += $DB->count_records_select(
                    'local_catquizlab_attempt',
                    'status = :queued AND nextruntime = :deferred AND id ' . $in,
                    $params
                );
                $DB->execute(
                    'UPDATE {local_catquizlab_attempt} SET nextruntime = :own
                      WHERE status = :queued AND nextruntime = :deferred AND id ' . $in,
                    $params + ['own' => $nextruntime]
                );
            }
        }

        return $restored;
    }
}
