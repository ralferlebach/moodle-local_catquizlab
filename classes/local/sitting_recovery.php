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
 * An explicit decision on a sitting whose engine attempt is not clear, or not a result (#173 AK06–AK08, P0
 * "[ENGINE-LIFECYCLE]" AK04; re-audit 27.11.2026).
 *
 * Nothing here happens by itself. A sitting with two engine attempts and none bound is held (experiment_resume, the
 * worker's identity check); an attempt the host closed on its time limit is kept as a diagnosis (completion_contract).
 * Which attempt is the sitting's, or whether it is to be played again, a person decides — and the decision is kept:
 * - inspect: what is known — the sitting, its binding, every engine attempt of its person on the activity (retired or
 *   not), the completion verdict, the history's last word. Reads only.
 * - bind: this attempt is the sitting's. Only an attempt of the person on the run's activity, not retired, and only
 *   while no other is bound. Nothing else changes: put the sitting back with the usual actions.
 * - newgeneration: none of its attempts is to be the sitting's any more: each is retired (local_catquizlab_retired,
 *   with its state and count at that moment), the binding cleared. Its next execution starts a new attempt — the next
 *   generation — and the predecessors stay in the engine, untouched, and in the record. The person's estimates in the
 *   run's contexts go (#174): the engine would start the new generation from the old one's ability as its own
 *   carry-over, and other twins from it as a population.
 * - keep: the end stands as it is (a technical end accepted as final, not played again); recorded. When none of the
 *   person's attempts is open any more, its estimates in the run's contexts go too — nothing will continue from them,
 *   and left there they count towards the population the engine could take as a prior (population_guard).
 * What the decision removed is said in its record; the engine's attempts and the sitting's trace stay.
 * Each decision is idempotent: made twice, it changes nothing the second time. A running sitting is not decided on.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sitting_recovery {
    /** @var string Decision: bind the named attempt. */
    public const BIND = 'bind';

    /** @var string Decision: retire every attempt, so that the next execution starts a new generation. */
    public const NEW_GENERATION = 'newgeneration';

    /** @var string Decision: the end stands. */
    public const KEEP = 'keep';

    /** @var string[] The reason codes of the decisions, as the history keeps them. */
    public const CODES = [
        self::BIND => 'recovery_bind',
        self::NEW_GENERATION => 'recovery_newgeneration',
        self::KEEP => 'recovery_keep',
    ];

    /**
     * What is known of a sitting, for a decision. Reads only.
     *
     * @param int $sittingid The sitting.
     * @return array sitting, owner, candidates (every attempt of the person on the activity), bound, retired,
     *     completion, resolved (engine_identity::resolve()), decidable.
     */
    public static function inspect(int $sittingid): array {
        global $DB;

        $sitting = $DB->get_record('local_catquizlab_attempt', ['id' => $sittingid], 'id, runid, personid, status, tries, '
            . 'executionid, engineattemptid, leaseowner, leaseexpires, lasterror, completionjson');
        if (!$sitting) {
            return ['found' => false];
        }
        [$userid, $instance, $why] = engine_identity::owner($sitting);
        $retired = engine_identity::retired($sittingid);
        $candidates = [];
        if ($why === '' && $DB->get_manager()->table_exists('adaptivequiz_attempt')) {
            foreach (
                $DB->get_records(
                    'adaptivequiz_attempt',
                    ['userid' => $userid, 'instance' => $instance],
                    'id ASC',
                    'id, uniqueid, attemptstate, questionsattempted, timecreated, timemodified'
                ) as $attempt
            ) {
                $candidates[] = [
                    'engineattemptid' => (int) $attempt->id,
                    'qubaid' => (int) $attempt->uniqueid,
                    'state' => (string) $attempt->attemptstate,
                    'answered' => (int) $attempt->questionsattempted,
                    'timecreated' => (int) $attempt->timecreated,
                    'timemodified' => (int) $attempt->timemodified,
                    'bound' => (int) $attempt->id === (int) ($sitting->engineattemptid ?? 0),
                    'retired' => in_array((int) $attempt->id, $retired, true),
                ];
            }
        }

        return [
            'found' => true,
            'sitting' => [
                'id' => (int) $sitting->id, 'runid' => (int) $sitting->runid, 'status' => (int) $sitting->status,
                'tries' => (int) $sitting->tries, 'executionid' => (int) $sitting->executionid,
                'engineattemptid' => (int) ($sitting->engineattemptid ?? 0), 'lasterror' => (string) $sitting->lasterror,
            ],
            'owner' => ['userid' => $userid, 'instance' => $instance, 'reason' => $why],
            'candidates' => $candidates,
            'retired' => $retired,
            'completion' => json_decode((string) ($sitting->completionjson ?? ''), true) ?: null,
            'resolved' => engine_identity::resolve($sittingid),
            'decidable' => (int) $sitting->status !== attempt_scheduler::STATUS_RUNNING,
        ];
    }

    /**
     * Make a decision, under the sitting's lock.
     *
     * @param int $sittingid The sitting.
     * @param string $decision BIND, NEW_GENERATION or KEEP.
     * @param string $note Why, in a few words (required).
     * @param int $engineattemptid For BIND: the attempt.
     * @param int $userid Who decides.
     * @return array{ok: bool, changed: bool, reason: string, retired: int[], personparamsremoved?: int}
     */
    public static function decide(
        int $sittingid,
        string $decision,
        string $note,
        int $engineattemptid = 0,
        int $userid = 0
    ): array {
        if (!isset(self::CODES[$decision])) {
            return ['ok' => false, 'changed' => false, 'reason' => 'unknown-decision', 'retired' => []];
        }
        if (trim($note) === '') {
            return ['ok' => false, 'changed' => false, 'reason' => 'no-note', 'retired' => []];
        }
        [$had, $answer] = sitting_lock::run(
            $sittingid,
            sitting_lock::REPORT_WAIT,
            static fn(): array => self::apply($sittingid, $decision, trim($note), $engineattemptid, $userid)
        );
        if (!$had) {
            return ['ok' => false, 'changed' => false, 'reason' => 'busy', 'retired' => []];
        }

        return $answer;
    }

    /**
     * Apply a decision, with the sitting's lock held.
     *
     * @param int $sittingid The sitting.
     * @param string $decision The decision.
     * @param string $note Why.
     * @param int $engineattemptid For BIND.
     * @param int $userid Who decides.
     * @return array
     */
    protected static function apply(int $sittingid, string $decision, string $note, int $engineattemptid, int $userid): array {
        global $DB;

        $state = self::inspect($sittingid);
        $refuse = static fn(string $why): array => ['ok' => false, 'changed' => false, 'reason' => $why, 'retired' => []];
        if (!$state['found']) {
            return $refuse('sitting-not-found');
        }
        if (!$state['decidable']) {
            return $refuse('running');
        }
        $note = \core_text::substr($note, 0, 255);
        $bound = (int) $state['sitting']['engineattemptid'];
        $changed = false;
        $retired = [];
        switch ($decision) {
            case self::BIND:
                $candidate = null;
                foreach ($state['candidates'] as $c) {
                    if ($c['engineattemptid'] === $engineattemptid) {
                        $candidate = $c;
                    }
                }
                if ($candidate === null) {
                    return $refuse('not-a-candidate');
                }
                if ($candidate['retired']) {
                    return $refuse('retired');
                }
                if ($bound > 0 && $bound !== $engineattemptid) {
                    return $refuse('bound-to-another');
                }
                if ($bound !== $engineattemptid) {
                    $DB->set_field('local_catquizlab_attempt', 'engineattemptid', $engineattemptid, ['id' => $sittingid]);
                    $changed = true;
                }
                break;
            case self::NEW_GENERATION:
                foreach ($state['candidates'] as $c) {
                    if ($c['retired']) {
                        continue;
                    }
                    $DB->insert_record('local_catquizlab_retired', (object) ['attemptid' => $sittingid,
                        'engineattemptid' => $c['engineattemptid'], 'qubaid' => $c['qubaid'], 'state' => $c['state'],
                        'answered' => $c['answered'], 'reason' => self::CODES[$decision], 'note' => $note,
                        'userid' => $userid, 'timecreated' => time()]);
                    $retired[] = $c['engineattemptid'];
                }
                if ($bound > 0 || $retired !== []) {
                    $DB->set_field('local_catquizlab_attempt', 'engineattemptid', null, ['id' => $sittingid]);
                    $changed = true;
                }
                break;
            case self::KEEP:
                $last = $DB->get_records('local_catquizlab_attemptlog', ['attemptid' => $sittingid,
                    'outcome' => attempt_history::DECIDED], 'id DESC', 'id, reasoncode', 0, 1);
                $changed = $last === [] || (string) reset($last)->reasoncode !== self::CODES[self::KEEP];
                break;
        }
        // The person's estimates, where nothing continues from them any more (#174 AK04).
        $forgotten = 0;
        $open = array_filter($state['candidates'], static fn(array $c): bool => !$c['retired'] && $c['state'] === 'inprogress');
        if ($changed && ($decision === self::NEW_GENERATION || ($decision === self::KEEP && $open === []))) {
            $forgotten = user_provisioner::forget_engine_person_params(
                (int) $state['sitting']['runid'],
                (int) $state['owner']['userid']
            );
        }
        if ($changed) {
            attempt_history::record($sittingid, attempt_history::DECIDED, [
                'reasoncode' => self::CODES[$decision],
                'engineattemptid' => $decision === self::BIND ? $engineattemptid : ($bound > 0 ? $bound : null),
                'detail' => get_string('history:decided_' . $decision, 'local_catquizlab', (object) [
                    'note' => $note,
                    'engine' => $engineattemptid,
                    'retired' => $retired === [] ? '-' : implode(', ', $retired),
                ]),
                'executionid' => 0,
                'extra' => ['server' => engine_identity::snapshot($sittingid, 'sitting_recovery:' . $decision)
                    + ['personparamsremoved' => $forgotten]],
            ]);
        }

        return ['ok' => true, 'changed' => $changed, 'reason' => '', 'retired' => $retired, 'personparamsremoved' => $forgotten];
    }
}
