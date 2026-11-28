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
 * What a past run's sittings went through, read from what is on record — fail-closed (#174 AK05, AK07–AK09; re-audit
 * 27.11.2026).
 *
 * Up to 0.7.83 every failed report deleted the person's rows in local_catquiz_personparams; a retry that continued the
 * same engine attempt then started its next estimate from the default (#174). Version 1 of this audit looked only at
 * sittings with tries > 1, took the first resume of each, and called every sitting without a resolvable engine attempt
 * "no engine attempt: nothing was continued" — on CLARA run 103, 26 sittings with two attempts each (ambiguous) were
 * reported so, and 24 more, whose try counter had been reset, not looked at. "at-risk: 0" read as an all-clear.
 *
 * Version 2 takes every sitting of the run, every engine attempt of its person on the run's activity, and every resume
 * in its history, in the history's order (its row ids — try numbers repeat after a reset). It reports findings, any
 * number per sitting, and counts them with their denominators:
 * - per sitting: no-engine-attempt (none exists at all), ambiguous (two or more, none bound), broken-binding (bound to
 *   one that is gone or not the person's, or the sitting's account or activity unknown);
 * - per engine attempt (generation): timeout (closed by the host's time limit), incomplete (open, or closed before its
 *   form or minimum), regular (complete, a design end, the whole form where there is one), unknown (not to be told);
 * - per resume: compromised-before-fix (the same engine attempt continued after a failure handled by a version that
 *   deleted the estimates), after-fix, new-attempt, resume-unknown (which attempt went on cannot be established).
 * What cannot be established is said as such, never counted as harmless; there is no "none". The upgrade time of the
 * fix is a risk boundary, not proof of what a worker ran. Reads only; mark() records findings in the histories, once
 * each, and changes no result, trace, status or binding.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resume_integrity {
    /** @var int Version of these rules. */
    public const RULES = 2;

    /** @var int The plugin version from which a failure no longer deletes the person parameters. */
    public const FIXED_VERSION = 2026112700;

    /** @var string The reason code of a mark for a resume at risk (kept from version 1). */
    public const REASON = 'resume_after_param_deletion';

    /** @var string Sitting: no engine attempt of the person on the activity at all. */
    public const NO_ENGINE = 'no-engine-attempt';

    /** @var string Sitting: two or more engine attempts, none bound: which is the sitting's is not known. */
    public const AMBIGUOUS = 'ambiguous';

    /** @var string Sitting: bound to an attempt that is gone or not the person's, or its account or activity unknown. */
    public const BROKEN_BINDING = 'broken-binding';

    /** @var string Engine attempt: closed by the host's time limit. */
    public const TIMEOUT = 'timeout';

    /** @var string Engine attempt: open, or closed before its form or minimum. */
    public const INCOMPLETE = 'incomplete';

    /** @var string Engine attempt: complete, a design end, the whole form where there is one. */
    public const REGULAR = 'regular';

    /** @var string Engine attempt: how it ended cannot be told from the record. */
    public const UNKNOWN = 'unknown';

    /** @var string Resume: the same engine attempt continued after a failure that deleted its estimates. */
    public const AT_RISK = 'compromised-before-fix';

    /** @var string Resume: continued after a failure handled by a version that keeps the estimates. */
    public const AFTER_FIX = 'after-fix';

    /** @var string Resume: the execution after the failure worked on another engine attempt. */
    public const NEW_ATTEMPT = 'new-attempt';

    /** @var string Resume: which engine attempt went on cannot be established. */
    public const RESUME_UNKNOWN = 'resume-unknown';

    /** @var string[] Findings marked in the history, and their reason codes. */
    public const MARKS = [
        self::AT_RISK => self::REASON,
        self::RESUME_UNKNOWN => 'audit_resume_unknown',
        self::AMBIGUOUS => 'audit_ambiguous',
        self::BROKEN_BINDING => 'audit_broken_binding',
        self::TIMEOUT => 'audit_timeout',
    ];

    /**
     * When this installation first ran a version with the fix, or null when it has not yet.
     *
     * @return int|null
     */
    public static function fixed_since(): ?int {
        global $DB;

        $time = $DB->get_field_sql(
            'SELECT MIN(timemodified) FROM {upgrade_log} WHERE plugin = ? AND ' . $DB->sql_cast_char2int('version') . ' >= ?',
            ['local_catquizlab', self::FIXED_VERSION]
        );

        return $time ? (int) $time : null;
    }

    /**
     * Audit a run.
     *
     * @param int $runid The run.
     * @param int|null $fixedsince When the fix arrived (null: not yet); fixed_since() when not given.
     * @return array run, rules, strategy, form, fixedsince, sittings (each with its findings, attempts and resumes),
     *     counts (findings => sittings, attempts or resumes having them) and denominators.
     */
    public static function audit(int $runid, ?int $fixedsince = -1): array {
        global $DB;

        $fixedsince = $fixedsince === -1 ? self::fixed_since() : $fixedsince;
        $design = attempt_collector::design_of($runid);
        $form = isset($design['form']) ? count(array_unique($design['form'])) : null;
        $out = ['run' => $runid, 'rules' => self::RULES, 'strategy' => (string) ($design['strategy'] ?? ''),
            'form' => $form, 'fixedsince' => $fixedsince, 'sittings' => [],
            'counts' => array_fill_keys([self::NO_ENGINE, self::AMBIGUOUS, self::BROKEN_BINDING, self::TIMEOUT,
                self::INCOMPLETE, self::REGULAR, self::UNKNOWN, self::AT_RISK, self::AFTER_FIX, self::NEW_ATTEMPT,
                self::RESUME_UNKNOWN], 0),
            'denominators' => ['sittings' => 0, 'engineattempts' => 0, 'resumes' => 0]];

        $sittings = $DB->get_records(
            'local_catquizlab_attempt',
            ['runid' => $runid],
            'id ASC',
            'id, runid, personid, status, tries, executionid, engineattemptid'
        );
        if ($sittings === []) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($sittings), SQL_PARAMS_NAMED, 'a');
        $history = [];
        $rows = $DB->get_recordset_select(
            'local_catquizlab_attemptlog',
            'attemptid ' . $insql,
            $params,
            'attemptid ASC, id ASC',
            'id, attemptid, tryno, executionid, outcome, reasoncode, engineattemptid, detail, diagnosis, timecreated'
        );
        foreach ($rows as $row) {
            $history[(int) $row->attemptid][] = $row;
        }
        $rows->close();
        $hasadaptive = $DB->get_manager()->table_exists('adaptivequiz_attempt');
        $stopcolumn = $hasadaptive && isset($DB->get_columns('adaptivequiz_attempt')['stopcode']);

        foreach ($sittings as $sitting) {
            $out['denominators']['sittings']++;
            [$userid, $instance, $why] = engine_identity::owner($sitting);
            $retired = engine_identity::retired((int) $sitting->id);
            $bound = (int) ($sitting->engineattemptid ?? 0);
            $attempts = [];
            if ($why === '' && $hasadaptive) {
                $records = $DB->get_records(
                    'adaptivequiz_attempt',
                    ['userid' => $userid, 'instance' => $instance],
                    'id ASC',
                    'id, uniqueid, attemptstate, questionsattempted, attemptstopcriteria, timecreated, timemodified'
                    . ($stopcolumn ? ', stopcode' : '')
                );
                foreach ($records as $a) {
                    $attempts[] = self::attempt($a, $design, $bound, $retired);
                }
            }
            $findings = [];
            $live = array_values(array_filter($attempts, static fn(array $a): bool => !$a['retired']));
            if ($why !== '') {
                $findings[] = self::BROKEN_BINDING;
            } else if ($attempts === []) {
                $findings[] = self::NO_ENGINE;
            } else if ($bound > 0 && !in_array($bound, array_column($attempts, 'engineattemptid'), true)) {
                $findings[] = self::BROKEN_BINDING;
            } else if ($bound <= 0 && count($live) > 1) {
                $findings[] = self::AMBIGUOUS;
            }
            foreach ($attempts as $a) {
                $out['denominators']['engineattempts']++;
                $out['counts'][$a['finding']]++;
                if (!in_array($a['finding'], $findings, true) && $a['finding'] !== self::REGULAR) {
                    $findings[] = $a['finding'];
                }
            }
            $resumes = self::resumes($history[(int) $sitting->id] ?? [], $attempts, $bound, $fixedsince);
            foreach ($resumes as $r) {
                $out['denominators']['resumes']++;
                $out['counts'][$r['finding']]++;
                if (!in_array($r['finding'], $findings, true)) {
                    $findings[] = $r['finding'];
                }
            }
            foreach ([self::NO_ENGINE, self::AMBIGUOUS, self::BROKEN_BINDING] as $code) {
                $out['counts'][$code] += in_array($code, $findings, true) ? 1 : 0;
            }
            $out['sittings'][] = [
                'sitting' => (int) $sitting->id,
                'status' => attempt_scheduler::status_label((int) $sitting->status),
                'tries' => (int) $sitting->tries,
                'bound' => $bound,
                'owner' => $why === '' ? 'known' : $why,
                'attempts' => $attempts,
                'resumes' => $resumes,
                'findings' => $findings,
            ];
        }

        return $out;
    }

    /**
     * One engine attempt as the audit sees it.
     *
     * @param \stdClass $a The adaptivequiz_attempt row.
     * @param array $design The run's design (attempt_collector::design_of()).
     * @param int $bound The sitting's bound attempt.
     * @param int[] $retired Its retired attempts.
     * @return array engineattemptid, qubaid, state, stopcode, answered, timecreated, timemodified, bound, retired,
     *     finding, why.
     */
    protected static function attempt(\stdClass $a, array $design, int $bound, array $retired): array {
        global $DB;

        $status = null;
        if ($DB->get_manager()->table_exists('local_catquiz_attempts')) {
            $value = $DB->get_field_sql('SELECT MAX(status) FROM {local_catquiz_attempts} WHERE attemptid = ?', [(int) $a->id]);
            $status = $value === false || $value === null ? null : (int) $value;
        }
        $stopcode = isset($a->stopcode) && $a->stopcode !== '' ? (string) $a->stopcode : null;
        $answered = (int) $a->questionsattempted;
        $entry = ['engineattemptid' => (int) $a->id, 'qubaid' => (int) $a->uniqueid, 'state' => (string) $a->attemptstate,
            'stopcode' => $stopcode, 'enginestatus' => $status, 'answered' => $answered,
            'timecreated' => (int) $a->timecreated, 'timemodified' => (int) $a->timemodified,
            'bound' => (int) $a->id === $bound, 'retired' => in_array((int) $a->id, $retired, true)];
        if ((string) $a->attemptstate !== 'complete') {
            return $entry + ['finding' => self::INCOMPLETE, 'why' => 'open (' . $a->attemptstate . '), ' . $answered
                . ' answered'];
        }
        $code = reason_catalog::engine_code((string) $a->attemptstopcriteria, $status, $stopcode);
        if ($stopcode === 'timelimit' || in_array($code, ['exceededmaxattempttime', 'attemptclosedbytimelimit'], true)) {
            return $entry + ['finding' => self::TIMEOUT, 'why' => 'closed by the time limit after ' . $answered . ' answered'];
        }
        // What can be told without the items: the host's count against the form, and the end code. A trace would
        // tell more (completion_contract); the audit reads the record as it is.
        $strategy = (string) ($design['strategy'] ?? '');
        if ($strategy !== '' && strategy_catalog::fixed_form($strategy)) {
            $form = count(array_unique((array) ($design['form'] ?? [])));
            if ($form <= 0) {
                return $entry + ['finding' => self::UNKNOWN, 'why' => 'planned form unknown'];
            }
            if ($answered < $form) {
                return $entry + ['finding' => self::INCOMPLETE, 'why' => sprintf('form %d of %d', $answered, $form)];
            }
            if ($answered > $form) {
                return $entry + ['finding' => self::UNKNOWN, 'why' => sprintf('%d answered, the form has %d', $answered, $form)];
            }
        }
        if (
            $code === null || in_array($code, result_validity::ENGINE_ERRORS, true)
                || in_array($stopcode, ['manual', 'restarted', 'error'], true)
        ) {
            return $entry + ['finding' => self::UNKNOWN, 'why' => 'end code ' . ($stopcode ?? $code ?? 'none')];
        }
        $end = reason_catalog::outcome((string) $a->attemptstopcriteria, ['strategy' => $strategy, 'enginestatus' => $status,
            'hoststopcode' => $stopcode, 'played' => $answered, 'minitems' => (int) ($design['minitems'] ?? 0),
            'poolsize' => count(array_unique((array) ($design['form'] ?? [])))]);
        if (in_array($end, reason_catalog::INVALID_OUTCOMES, true)) {
            return $entry + ['finding' => self::INCOMPLETE, 'why' => 'end ' . $end];
        }

        return $entry + ['finding' => self::REGULAR, 'why' => 'end ' . $end];
    }

    /**
     * Every resume of a sitting: each start of an execution after a failure or a requeue, in the history's order.
     *
     * Which engine attempt an execution worked on is taken from the history where it says so (the binding of 0.7.84 on,
     * the engine attempt of a report); otherwise inferred only when the person has exactly one attempt, created
     * before the failure — else it is not established.
     *
     * @param \stdClass[] $rows The sitting's history, oldest first.
     * @param array[] $attempts Its engine attempts (attempt()).
     * @param int $bound Its bound attempt.
     * @param int|null $fixedsince When the fix arrived.
     * @return array[]
     */
    protected static function resumes(array $rows, array $attempts, int $bound, ?int $fixedsince): array {
        $out = [];
        $failure = null;
        $before = 0;
        $live = array_values(array_filter($attempts, static fn(array $a): bool => !$a['retired']));
        foreach ($rows as $i => $row) {
            if ((int) ($row->engineattemptid ?? 0) > 0 && $failure === null) {
                $before = (int) $row->engineattemptid;
            }
            if (in_array($row->outcome, [attempt_history::FAILED, attempt_history::REQUEUED], true)) {
                $failure = $row;
                continue;
            }
            if ($row->outcome !== attempt_history::STARTED || $failure === null) {
                continue;
            }
            // The engine attempt of this execution: the first row after its start that names one, up to the next start.
            $after = 0;
            for ($j = $i + 1; $j < count($rows) && $rows[$j]->outcome !== attempt_history::STARTED; $j++) {
                if ((int) ($rows[$j]->engineattemptid ?? 0) > 0) {
                    $after = (int) $rows[$j]->engineattemptid;
                    break;
                }
            }
            $evidence = 'history';
            if ($before <= 0 || $after <= 0) {
                $only = count($live) === 1 ? $live[0] : null;
                if ($only !== null && $only['timecreated'] <= (int) $failure->timecreated) {
                    $before = $before > 0 ? $before : $only['engineattemptid'];
                    $after = $after > 0 ? $after : $only['engineattemptid'];
                    $evidence = 'single-attempt-created-before-the-failure';
                } else if ($only !== null && $only['timecreated'] > (int) $row->timecreated) {
                    $after = $after > 0 ? $after : $only['engineattemptid'];
                    $evidence = 'single-attempt-created-after-the-start';
                }
            }
            if ($before <= 0 && $after > 0 && $evidence === 'single-attempt-created-after-the-start') {
                $finding = self::NEW_ATTEMPT;
            } else if ($before <= 0 || $after <= 0) {
                $finding = self::RESUME_UNKNOWN;
                $evidence = 'not on record';
            } else if ($before !== $after) {
                $finding = self::NEW_ATTEMPT;
            } else if ($fixedsince === null || (int) $failure->timecreated < $fixedsince) {
                $finding = self::AT_RISK;
            } else {
                $finding = self::AFTER_FIX;
            }
            $out[] = [
                'historyrow' => (int) $row->id,
                'tryno' => (int) $row->tryno,
                'executionid' => (int) ($row->executionid ?? 0),
                'startedat' => (int) $row->timecreated,
                'failedat' => (int) $failure->timecreated,
                'failurecode' => (string) ($failure->reasoncode ?? ''),
                'answeredbefore' => self::answered_before($failure),
                'enginebefore' => $before,
                'engineafter' => $after,
                'evidence' => $evidence,
                'finding' => $finding,
            ];
            $before = $after;
            $failure = null;
        }

        return $out;
    }

    /**
     * Mark what an audit found in the sittings' histories: once per sitting and finding; nothing else changes.
     *
     * @param array $audit The result of audit().
     * @return int How many rows were written now.
     */
    public static function mark(array $audit): int {
        global $DB;

        $marked = 0;
        foreach ($audit['sittings'] as $sitting) {
            foreach ($sitting['findings'] as $finding) {
                $code = self::MARKS[$finding] ?? null;
                if (
                    $code === null || $DB->record_exists('local_catquizlab_attemptlog', ['attemptid' => $sitting['sitting'],
                        'outcome' => attempt_history::AUDITED, 'reasoncode' => $code])
                ) {
                    continue;
                }
                $resume = array_values(array_filter($sitting['resumes'], static fn($r) => $r['finding'] === $finding))[0]
                    ?? null;
                attempt_history::record($sitting['sitting'], attempt_history::AUDITED, [
                    'reasoncode' => $code,
                    'engineattemptid' => $sitting['bound'] > 0 ? $sitting['bound'] : null,
                    'executionid' => 0,
                    'detail' => $finding === self::AT_RISK && $resume !== null
                        ? get_string('history:auditedresume', 'local_catquizlab', (object) [
                            'tryno' => $resume['tryno'],
                            'answered' => $resume['answeredbefore'] ?? '?',
                            'failedat' => date('c', $resume['failedat']),
                        ])
                        : get_string('history:audited_' . str_replace('-', '', $finding), 'local_catquizlab', (object) [
                            'attempts' => count($sitting['attempts']),
                            'rules' => self::RULES,
                        ]),
                ]);
                $marked++;
            }
        }

        return $marked;
    }

    /**
     * How many answers the failed execution had given, as far as its record says.
     *
     * @param \stdClass $row The failure row.
     * @return int|null
     */
    protected static function answered_before(\stdClass $row): ?int {
        $diagnosis = json_decode((string) ($row->diagnosis ?? ''), true) ?: [];
        if (isset($diagnosis['progress']['answered']) && is_numeric($diagnosis['progress']['answered'])) {
            return (int) $diagnosis['progress']['answered'];
        }
        if (preg_match('/after (\d+) answer\(s\)/', (string) ($row->detail ?? ''), $match)) {
            return (int) $match[1];
        }

        return null;
    }
}
