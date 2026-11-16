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
 * An engine trace's lab sitting, also while the sitting is being played (#164 AK4).
 *
 * The lab stores the engine's attempt id (local_catquizlab_attempt.engineattemptid) when it collects a sitting. A
 * trace written while the sitting is being played — every one of them, the engine writes them per request — had no
 * lab sitting to point to before that: on the live installation 60 of the 60 latest traces read "lab attempt NULL"
 * while eleven workers were playing. That was not a lost trace; it was a link not made yet.
 *
 * The link without waiting for the collection: the engine writes the Moodle user of every trace (and of every
 * adaptivequiz attempt), and a simulated person's Moodle user is its own — the provisioner makes one user per person
 * and run (catlab_r<run>_…) and records it (local_catquizlab_person.moodleuserid). From the user, the person; from the
 * person, its run and its sitting; from the sitting, the worker holding its lease. Read only: nothing of the engine is
 * changed, and nothing is stored.
 *
 * How sure each link is, said per trace:
 * - stored: the sitting's own engineattemptid is the trace's adaptive attempt (collected, or reported by the worker);
 * - inplay: the person's sitting being played now (status running), the engine attempt not stored yet;
 * - person: the person's sitting, not in play (queued again for a retry, failed, waiting for its trace);
 * - none: the user is not a simulated person of this lab (a real user, or a person whose run was deleted).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_correlation {
    /** @var string The link is the sitting's own stored engine attempt. */
    public const STORED = 'stored';

    /** @var string The person's sitting being played now. */
    public const INPLAY = 'inplay';

    /** @var string The person's sitting, not in play. */
    public const PERSON = 'person';

    /** @var string Not a sitting of this lab. */
    public const NONE = 'none';

    /**
     * The lab sitting of each engine trace or adaptivequiz attempt, from its Moodle user.
     *
     * @param array $rows Objects or arrays with userid and adaptiveattemptid (engine traces) — or userid and id
     *      (adaptivequiz attempts, pass $attemptkey 'id').
     * @param string $attemptkey The field holding the adaptive attempt id.
     * @return array<int|string, array{how: string, labattemptid: int, runid: int, experimentid: int, personid: int,
     *      workerid: string, status: int, engineattemptid: int}> By the rows' keys.
     */
    public static function link(array $rows, string $attemptkey = 'adaptiveattemptid'): array {
        global $DB;

        $none = ['how' => self::NONE, 'labattemptid' => 0, 'runid' => 0, 'experimentid' => 0, 'personid' => 0,
            'workerid' => '', 'status' => 0, 'engineattemptid' => 0];
        $userids = [];
        foreach ($rows as $row) {
            $userid = (int) ((array) $row)['userid'];
            if ($userid > 0) {
                $userids[$userid] = true;
            }
        }
        $out = array_map(static fn(): array => $none, $rows);
        if ($userids === []) {
            return $out;
        }

        // The persons of these users, their runs and their sittings: three queries, however many traces.
        [$usql, $uparams] = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'u');
        $persons = $DB->get_records_select(
            'local_catquizlab_person',
            "moodleuserid {$usql}",
            $uparams,
            '',
            'id, runid, moodleuserid'
        );
        $byuser = [];
        foreach ($persons as $person) {
            $byuser[(int) $person->moodleuserid][] = $person;
        }
        $sittings = [];
        $experiments = [];
        if ($persons !== []) {
            [$psql, $pparams] = $DB->get_in_or_equal(array_keys($persons), SQL_PARAMS_NAMED, 'p');
            foreach (
                $DB->get_records_select(
                    'local_catquizlab_attempt',
                    "personid {$psql}",
                    $pparams,
                    'id ASC',
                    'id, runid, personid, status, engineattemptid, leaseowner'
                ) as $sitting
            ) {
                $sittings[(int) $sitting->personid][] = $sitting;
            }
            $runids = array_unique(array_map(static fn($p): int => (int) $p->runid, $persons));
            [$rsql, $rparams] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'r');
            $experiments = $DB->get_records_select_menu(
                'local_catquizlab_run',
                "id {$rsql}",
                $rparams,
                '',
                'id, experimentid'
            );
        }

        foreach ($rows as $key => $row) {
            $row = (array) $row;
            $userid = (int) $row['userid'];
            $engineattempt = (int) ($row[$attemptkey] ?? 0);
            $best = null;
            $how = self::NONE;
            foreach ($byuser[$userid] ?? [] as $person) {
                foreach ($sittings[(int) $person->id] ?? [] as $sitting) {
                    if ($engineattempt > 0 && (int) $sitting->engineattemptid === $engineattempt) {
                        [$best, $how] = [$sitting, self::STORED];
                        break 2;
                    }
                    if ((int) $sitting->status === attempt_scheduler::STATUS_RUNNING && $how !== self::INPLAY) {
                        [$best, $how] = [$sitting, self::INPLAY];
                    } else if ($best === null) {
                        [$best, $how] = [$sitting, self::PERSON];
                    }
                }
            }
            if ($best === null) {
                continue;
            }
            $out[$key] = [
                'how' => $how,
                'labattemptid' => (int) $best->id,
                'runid' => (int) $best->runid,
                'experimentid' => (int) ($experiments[(int) $best->runid] ?? 0),
                'personid' => (int) $best->personid,
                'workerid' => (string) ($best->leaseowner ?? ''),
                'status' => (int) $best->status,
                'engineattemptid' => (int) ($best->engineattemptid ?? 0),
            ];
        }

        return $out;
    }
}
