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
 * How a sitting's engine attempt ended, told apart: technically finished, and a regular result (P0 issue
 * "[ENGINE-LIFECYCLE]", re-audit 27.11.2026).
 *
 * "Complete" on the host says only that the attempt is closed. CLARA run 103: fifty CLASSIC attempts of 2500 items
 * were closed by the engine's clean-up task after 24 hours (local_catquiz cancel_expired_attempts, stop reason
 * "timelimit") with 1362 to 2459 answers — complete, and not one of them a test that was sat to its end. Until 0.7.84
 * the lab let the trace decide; the end code was read after the trace was stored and the engine's person parameters
 * were gone.
 *
 * One verdict, the same on every path that ends a sitting — the worker's report, an attempt complete before the start,
 * a late report, a reconciliation, the collection and its later looks, an experiment resumed — because all of them end
 * in the collector, which asks this class before it stores anything. It says apart:
 * - host: the host's own state of the attempt (state, stop code, counts, times);
 * - technical: a technical end — the host's time limit, an engine error, an end by somebody — or none;
 * - end: the end code of the reason catalogue (reason_catalog::outcome());
 * - form: for a fixed form, the planned form and what was played of it — unique items of the run's pool, each answered;
 * - trace: whether the trace holds what the collector requires (#138);
 * - class: REGULAR (a design end, the form complete where there is one), INCOMPLETE (an end before the design allowed
 *   one), TECHNICAL (closed for a technical reason), UNKNOWN (not to be decided: fail-closed), INPROGRESS;
 * - recovery: what may happen to the sitting — nothing more (regular), keep it as a diagnosis and decide explicitly
 *   (technical, incomplete: never a new attempt by itself), decide (unknown), continue (in progress);
 * - provenance: which path decided, when, by which rules.
 *
 * The lab's designs set no time limit (test_provisioner: catquiz_includetimelimit 0), so the host's time limit ends a
 * lab sitting only through the engine's default for tests without one: a technical end in every case, never a stop
 * of the design — also when the form had been answered in full, since then the engine would have ended it itself.
 * Unknown codes and combinations are not regular. The figures take regular results only (result_validity).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_contract {
    /** @var int Version of these rules, kept with every verdict. */
    public const RULES = 1;

    /** @var string A design end — for a fixed form, the whole form — with a trace that holds it: a regular result. */
    public const REGULAR = 'regular';

    /** @var string An end before the design allowed one: a form broken off, a minimum not reached. */
    public const INCOMPLETE = 'incomplete';

    /** @var string Closed for a technical reason: the host's time limit, an engine error, by somebody. */
    public const TECHNICAL = 'technical';

    /** @var string Not to be decided from what is known: not a result. */
    public const UNKNOWN = 'unknown';

    /** @var string The host's attempt is still open. */
    public const INPROGRESS = 'inprogress';

    /** @var string Technical end: the host's time limit (the engine's clean-up task, or the host on resume). */
    public const HOST_TIMEOUT = 'host_timeout';

    /** @var string Technical end: the engine says it failed. */
    public const ENGINE_ERROR = 'engine_error';

    /** @var string Technical end: a teacher closed it, or a new attempt was started over it. */
    public const CLOSED_BY_SOMEBODY = 'closed_by_somebody';

    /** @var string Recovery: nothing — a regular result. */
    public const RECOVERY_NONE = 'none';

    /** @var string Recovery: kept as a diagnosis; played again only on an explicit decision, as a new generation. */
    public const RECOVERY_KEEP = 'keep';

    /** @var string Recovery: a person decides (sitting_recovery); nothing happens by itself. */
    public const RECOVERY_DECIDE = 'decide';

    /** @var string Recovery: the attempt is open and its sitting may be played on. */
    public const RECOVERY_CONTINUE = 'continue';

    /** @var string The stop texts of older engines that reason_catalog::outcome() reads (#118): the only text accepted. */
    protected const LEGACY_TEXTS = '/maximum number of questions|reachedmaximumquestions|max\\.? number of questions'
        . '|time exc?eeded|exceededmaxattempttime|timelimit|noremainingquestions|no (more |remaining )?(questions|items)'
        . '|ran out of questions/i';

    /** @var string[] Host stop codes of a technical end, and the technical reason they are. */
    protected const HOST_TECHNICAL = [
        'timelimit' => self::HOST_TIMEOUT,
        'manual' => self::CLOSED_BY_SOMEBODY,
        'restarted' => self::CLOSED_BY_SOMEBODY,
        'error' => self::ENGINE_ERROR,
    ];

    /**
     * The verdict on a sitting's end.
     *
     * @param array $host The host's attempt: state, stopcode, enginestatus, stopreason, answered, slots, engineattemptid,
     *     qubaid, timecreated, timefinished (what is known; missing keys are unknown, never 0).
     * @param array|null $trace The trace as built (null when there is none).
     * @param string[] $defects The trace's defects (attempt_collector::defects()), when it was checked.
     * @param array $design strategy, minitems, form (question ids of the run's pool, for a fixed form), maxitems.
     * @param string $source The path that asks (collector, trace-pending, resume, …).
     * @return array The verdict, as stored in completionjson.
     */
    public static function judge(array $host, ?array $trace, array $defects, array $design, string $source): array {
        $state = (string) ($host['state'] ?? '');
        $stopcode = isset($host['stopcode']) && $host['stopcode'] !== '' ? (string) $host['stopcode'] : null;
        $status = isset($host['enginestatus']) && is_numeric($host['enginestatus']) ? (int) $host['enginestatus'] : null;
        $stopreason = (string) ($host['stopreason'] ?? ($trace['stopreason'] ?? ''));
        $strategy = (string) ($design['strategy'] ?? '');
        $fixedform = $strategy !== '' && strategy_catalog::fixed_form($strategy);

        $verdict = [
            'rules' => self::RULES,
            'source' => $source,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'host' => array_intersect_key($host, array_flip(['state', 'stopcode', 'enginestatus', 'answered', 'slots',
                'engineattemptid', 'qubaid', 'timecreated', 'timefinished'])),
            'technical' => '',
            'end' => '',
            'form' => null,
            'trace' => $trace === null ? 'missing' : ($defects === [] ? 'intact' : 'defective'),
            'defects' => array_values($defects),
            'class' => self::UNKNOWN,
            'recovery' => self::RECOVERY_DECIDE,
            'why' => '',
        ];

        if ($state === 'inprogress') {
            return ['class' => self::INPROGRESS, 'recovery' => self::RECOVERY_CONTINUE, 'why' => 'open on the host']
                + $verdict;
        }
        if ($state !== 'complete') {
            return ['why' => $state === '' ? 'host state unknown' : 'host state ' . $state] + $verdict;
        }

        // The engine's and the host's codes before any text (#118): status number, host stop code, then the text.
        $enginecode = reason_catalog::engine_code($stopreason, $status, $stopcode);
        $technical = $stopcode !== null ? (self::HOST_TECHNICAL[$stopcode] ?? '') : '';
        if ($technical === '' && in_array($enginecode, ['exceededmaxattempttime', 'attemptclosedbytimelimit'], true)) {
            $technical = self::HOST_TIMEOUT;
        }
        if ($technical === '' && in_array((string) $enginecode, result_validity::ENGINE_ERRORS, true)) {
            $technical = self::ENGINE_ERROR;
        }
        $verdict['technical'] = $technical;

        $played = $trace === null ? null : array_values(array_map('intval', (array) ($trace['items'] ?? [])));
        if ($fixedform) {
            $verdict['form'] = self::form((array) ($design['form'] ?? []), $played, (array) ($trace['responses'] ?? []));
        }
        $facts = [
            'strategy' => $strategy,
            'enginestatus' => $status,
            'hoststopcode' => $stopcode,
            'minitems' => (int) ($design['minitems'] ?? 0),
            'maxitems' => (int) ($design['maxitems'] ?? 0),
            'finalse' => $trace['finalse'] ?? null,
            'semin' => $design['semin'] ?? null,
            'dropped' => count((array) ($trace['progress']['droppedscales'] ?? [])),
        ];
        if ($played !== null) {
            $facts['played'] = count($played);
        }
        if ($fixedform && $verdict['form'] !== null) {
            $facts['poolsize'] = (int) $verdict['form']['planned'];
        }
        $end = reason_catalog::outcome($stopreason, $facts);
        $verdict['end'] = $end;

        // Technical first: whatever else is true of the attempt, a technical end is not a result.
        if ($technical !== '') {
            return ['class' => self::TECHNICAL, 'recovery' => self::RECOVERY_KEEP,
                'why' => 'closed by ' . $technical . ($stopcode !== null ? ' (host ' . $stopcode . ')' : '')] + $verdict;
        }
        if ($verdict['trace'] !== 'intact') {
            return ['why' => 'trace ' . $verdict['trace']] + $verdict;
        }
        // No code at all, and no text the catalogue's legacy patterns know: not decided, not regular — the catalogue's
        // own fallback ("no item left the strategy would choose") is a reading, not a fact.
        if ($enginecode === null && !preg_match(self::LEGACY_TEXTS, $stopreason)) {
            return ['why' => 'no known end code'] + $verdict;
        }
        if ($fixedform) {
            $form = $verdict['form'];
            if ($form === null || (int) $form['planned'] <= 0) {
                return ['why' => 'planned form unknown'] + $verdict;
            }
            if ($form['foreign'] > 0 || $form['repeated'] > 0 || $form['unanswered'] > 0) {
                return ['why' => sprintf(
                    'form inconsistent: %d foreign, %d repeated, %d unanswered',
                    $form['foreign'],
                    $form['repeated'],
                    $form['unanswered']
                )] + $verdict;
            }
            if ($form['missing'] > 0) {
                return ['class' => self::INCOMPLETE, 'recovery' => self::RECOVERY_KEEP,
                    'why' => sprintf('form %d of %d', $form['unique'], $form['planned'])] + $verdict;
            }
            if ($end !== 'fixed_form_complete') {
                return ['why' => 'form complete, end ' . $end] + $verdict;
            }

            return ['class' => self::REGULAR, 'recovery' => self::RECOVERY_NONE, 'why' => 'whole form'] + $verdict;
        }
        if (in_array($end, reason_catalog::INVALID_OUTCOMES, true)) {
            return ['class' => self::INCOMPLETE, 'recovery' => self::RECOVERY_KEEP, 'why' => 'end ' . $end] + $verdict;
        }
        if ($end === 'timeout' || $end === 'finished_other') {
            return ['why' => 'end ' . $end] + $verdict;
        }

        return ['class' => self::REGULAR, 'recovery' => self::RECOVERY_NONE, 'why' => 'end ' . $end] + $verdict;
    }

    /**
     * What was played of a fixed form: planned, unique items of it, items not of it, repeated, missing, unanswered.
     *
     * @param int[] $form The question ids of the planned form.
     * @param int[]|null $played The items played, in order (null: not known).
     * @param array $responses The responses by question id.
     * @return array|null
     */
    public static function form(array $form, ?array $played, array $responses): ?array {
        if ($played === null) {
            return null;
        }
        $planned = array_flip(array_map('intval', $form));
        $unique = array_unique($played);
        $of = array_filter($unique, static fn(int $q): bool => isset($planned[$q]));
        $answered = array_filter($responses, static fn($fraction): bool => $fraction !== null);

        return [
            'planned' => count($planned),
            'played' => count($played),
            'unique' => count($of),
            'foreign' => count($unique) - count($of),
            'repeated' => count($played) - count($unique),
            'missing' => count($planned) - count($of),
            'unanswered' => count(array_filter($of, static fn(int $q): bool => !array_key_exists($q, $answered)
                && !array_key_exists((string) $q, $answered))),
        ];
    }

    /**
     * Whether a stored verdict makes a sitting a regular result; a sitting without one (collected before 0.7.85) is
     * judged by the validity rules alone, as before.
     *
     * @param array|null $verdict The verdict (completionjson, or the trace's 'completion').
     * @return bool|null True or false, null without a verdict.
     */
    public static function regular(?array $verdict): ?bool {
        if ($verdict === null || !isset($verdict['class'])) {
            return null;
        }

        return $verdict['class'] === self::REGULAR;
    }
}
