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
 * Engine attempts without a question usage: an inventory, read only (#178, re-audit 27.11.2026).
 *
 * When the CAT selection fails before item one, mod_adaptivequiz leaves its attempt row behind: inprogress, uniqueid 0.
 * Until 0.7.84 this class deleted every such row of the lab's persons, from the pipeline tick (scheduled and ad hoc)
 * and from the operations page. But a regular start passes through the same state: the host writes its attempt with
 * uniqueid 0 (attempt.php of the pinned host, 529–545), and only later saves the question usage and links it
 * (cat_session.php 294–298). A tick between the two deleted a live attempt — CLARA, run 120, sitting 46856: the CAT
 * attempt 27988 and the usage 27579 of host attempt 29477 stayed, the host attempt was gone, the worker found a usage of
 * nobody's attempt (foreign-usage) and the sitting was held. Selecting by uniqueid 0 and deleting by id, without an age,
 * a lease, the sitting, a second look or the engine's lock, cannot tell a dead start from a live one.
 *
 * So nothing is deleted any more, by any path. What remains is the inventory: each such attempt with whose it is
 * (person, run, activity), whether a sitting of that person is being played on that activity now (a start in progress),
 * its age — and the decision, which is always "kept". A clean-up can come back only with the engine's own lifecycle
 * contract (the host closing or rolling back a start that failed, mod_adaptivequiz #27) and an official deletion over
 * every table involved; until then the leftovers are counted, not removed.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_hygiene {
    /** @var string Decision: kept — nothing is deleted (#178). */
    public const KEPT = 'kept';

    /** @var string A sitting of the person is being played on this activity: a start in progress. */
    public const INITIALISING = 'initialising';

    /** @var string No sitting of the person is being played on this activity: a start that may have failed. */
    public const NOT_PLAYED = 'not-played';

    /**
     * Count the engine attempts without a question usage of the lab's persons.
     *
     * @return int
     */
    public static function count_empty_attempts(): int {
        global $DB;

        if (!self::table_available()) {
            return 0;
        }

        return (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {adaptivequiz_attempt} aa
              WHERE aa.uniqueid = 0 AND aa.attemptstate = 'inprogress'
                    AND EXISTS (SELECT 1 FROM {local_catquizlab_person} p WHERE p.moodleuserid = aa.userid)"
        );
    }

    /**
     * The inventory: every such attempt, whose it is, whether a start is in progress, and the decision (kept).
     *
     * @param int $limit How many to list (the counts are of all).
     * @param int|null $now The clock.
     * @return array{count: int, initialising: int, notplayed: int, rows: array[], decision: string}
     */
    public static function inventory(int $limit = 50, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $out = ['count' => 0, 'initialising' => 0, 'notplayed' => 0, 'rows' => [], 'decision' => self::KEPT];
        if (!self::table_available()) {
            return $out;
        }
        // One statement for all of them, with whether a sitting of the account on that activity is being played; the
        // sittings themselves only for those listed (no query per attempt beyond the limit).
        $rows = $DB->get_recordset_sql(
            "SELECT aa.id, aa.userid, aa.instance, aa.timecreated,
                    CASE WHEN EXISTS (SELECT 1
                                        FROM {local_catquizlab_attempt} a
                                        JOIN {local_catquizlab_person} p ON p.id = a.personid
                                        JOIN {local_catquizlab_run} r ON r.id = a.runid
                                        JOIN {course_modules} cm ON cm.id = r.testcmid
                                       WHERE p.moodleuserid = aa.userid AND cm.instance = aa.instance
                                             AND a.status = :running)
                         THEN 1 ELSE 0 END AS playing
               FROM {adaptivequiz_attempt} aa
              WHERE aa.uniqueid = 0 AND aa.attemptstate = 'inprogress'
                    AND EXISTS (SELECT 1 FROM {local_catquizlab_person} p WHERE p.moodleuserid = aa.userid)
           ORDER BY aa.id ASC",
            ['running' => attempt_scheduler::STATUS_RUNNING]
        );
        foreach ($rows as $row) {
            $state = (int) $row->playing === 1 ? self::INITIALISING : self::NOT_PLAYED;
            $out['count']++;
            $out[$state === self::INITIALISING ? 'initialising' : 'notplayed']++;
            if (count($out['rows']) >= $limit) {
                continue;
            }
            // Whose: the sittings of this account on runs whose activity is this instance.
            $sittings = $DB->get_records_sql(
                "SELECT a.id, a.runid, a.status, a.executionid
                   FROM {local_catquizlab_attempt} a
                   JOIN {local_catquizlab_person} p ON p.id = a.personid
                   JOIN {local_catquizlab_run} r ON r.id = a.runid
                   JOIN {course_modules} cm ON cm.id = r.testcmid
                  WHERE p.moodleuserid = :userid AND cm.instance = :instance",
                ['userid' => (int) $row->userid, 'instance' => (int) $row->instance]
            );
            $playing = array_filter($sittings, static fn($s): bool => (int) $s->status === attempt_scheduler::STATUS_RUNNING);
            $out['rows'][] = [
                'attemptid' => (int) $row->id,
                'userid' => (int) $row->userid,
                'instance' => (int) $row->instance,
                'sittings' => array_map('intval', array_keys($sittings)),
                'playing' => array_map('intval', array_keys($playing)),
                'runs' => array_values(array_unique(array_map(static fn($s): int => (int) $s->runid, $sittings))),
                'executions' => array_values(array_map(static fn($s): int => (int) $s->executionid, $playing)),
                'state' => $state,
                'ageseconds' => $now - (int) $row->timecreated,
                'decision' => self::KEPT,
            ];
        }
        $rows->close();

        return $out;
    }

    /**
     * The hygiene's decision as a lifecycle event of the sittings it concerns (#176 AK06, #178 AK06): once per sitting
     * being played and engine attempt without a question usage, "kept", with the attempt, its state and age. A start in
     * progress reads as such in the sitting's history; nothing is written twice, and nothing for an attempt of no
     * sitting being played (said in the task's output and the debug trace instead).
     *
     * @param array $inventory From inventory().
     * @return int History rows written.
     */
    public static function record_decisions(array $inventory): int {
        global $DB;

        $written = 0;
        foreach ($inventory['rows'] as $row) {
            foreach ($row['playing'] ?? [] as $sittingid) {
                if (
                    $DB->record_exists('local_catquizlab_attemptlog', ['attemptid' => $sittingid,
                        'outcome' => attempt_history::HYGIENE_KEPT, 'engineattemptid' => $row['attemptid']])
                ) {
                    continue;
                }
                $written += attempt_history::record($sittingid, attempt_history::HYGIENE_KEPT, [
                    'engineattemptid' => $row['attemptid'],
                    'reasoncode' => 'hygiene_kept_' . $row['state'],
                    'detail' => "engine attempt {$row['attemptid']} without a question usage, {$row['ageseconds']} s old: "
                        . 'kept (a start in progress has this shape)',
                    'extra' => ['hygiene' => ['engineattemptid' => $row['attemptid'], 'state' => $row['state'],
                        'ageseconds' => $row['ageseconds'], 'decision' => $row['decision']]],
                ]) > 0 ? 1 : 0;
            }
        }

        return $written;
    }

    /**
     * The attempts as the operations page lists them.
     *
     * @param int $limit How many to return.
     * @return array[]
     */
    public static function list_empty_attempts(int $limit = 20): array {
        return array_map(static fn(array $row): array => [
            'attemptid' => $row['attemptid'],
            'runid' => $row['runs'][0] ?? 0,
            'state' => $row['state'],
            'since' => duration::human($row['ageseconds']),
        ], self::inventory($limit)['rows']);
    }

    /**
     * Whether the engine's attempt table is here to work with.
     *
     * @return bool
     */
    protected static function table_available(): bool {
        global $DB;

        return $DB->get_manager()->table_exists('adaptivequiz_attempt')
            && $DB->get_manager()->table_exists('local_catquizlab_person');
    }
}
