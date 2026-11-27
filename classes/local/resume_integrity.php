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
 * Which sittings of a past run went on after an execution failed while the engine's person parameters were deleted
 * at that failure (#174 AK05).
 *
 * Up to 0.7.83 every failed report deleted the person's rows in local_catquiz_personparams in the run's contexts
 * (user_provisioner::forget_engine_person_params()). Both engine lines (1.2.1, 1.3.0) keep an attempt's intermediate
 * estimates there (validationstatus provisional, attemptid = the CAT attempt) and the next step of the same attempt
 * starts from them (personability_loader::load_saved_personparams(), issue #164 of the engine). A retry continues the
 * same engine attempt; with its intermediate estimates gone, its next estimate started from the default ability with
 * the default prior instead. Whether and how much that changed a sitting's result cannot be read from the tables now:
 * the deleted rows are gone. What can be read is which sittings were exposed — and that is what this lists, and with
 * --mark records in each sitting's history. Nothing else is changed: no result, no trace, no status.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resume_integrity {
    /** @var int The plugin version from which a failure no longer deletes the person parameters. */
    public const FIXED_VERSION = 2026112700;

    /** @var string The reason code of the mark. */
    public const REASON = 'resume_after_param_deletion';

    /** @var string At risk: continued after a failure that deleted the parameters. */
    public const AT_RISK = 'at-risk';

    /** @var string Continued after a failure, but after the fix: nothing was deleted. */
    public const AFTER_FIX = 'after-fix';

    /** @var string Started again on a new engine attempt: the deleted parameters belonged to one left behind. */
    public const NEW_ATTEMPT = 'new-attempt';

    /** @var string No engine attempt to continue: the failures came before one existed (a login, a start). */
    public const NO_ENGINE = 'no-engine-attempt';

    /** @var string Never continued after a failure. */
    public const NONE = 'none';

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
     * Audit a run's sittings.
     *
     * @param int $runid The run.
     * @param int|null $fixedsince When the fix arrived (null: not yet); fixed_since() when not given.
     * @return array{run: int, strategy: string, fixedsince: ?int, sittings: array[], counts: array<string, int>}
     */
    public static function audit(int $runid, ?int $fixedsince = -1): array {
        global $DB;

        $fixedsince = $fixedsince === -1 ? self::fixed_since() : $fixedsince;
        $run = $DB->get_record('local_catquizlab_run', ['id' => $runid]);
        $definition = $run ? run_registry::definition_for($run) : [];
        $out = ['run' => $runid, 'strategy' => (string) ($definition['strategy'] ?? ''), 'fixedsince' => $fixedsince,
            'sittings' => [], 'counts' => [self::AT_RISK => 0, self::AFTER_FIX => 0, self::NEW_ATTEMPT => 0, self::NO_ENGINE => 0,
            self::NONE => 0]];

        // Only sittings with more than one execution can have gone on after a failure; their histories in one query.
        $multi = $DB->get_records_select(
            'local_catquizlab_attempt',
            'runid = ? AND tries > 1',
            [$runid],
            'id ASC',
            'id, status, tries, engineattemptid'
        );
        $out['counts'][self::NONE] = $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]) - count($multi);
        if ($multi === []) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($multi), SQL_PARAMS_NAMED, 'a');
        $history = [];
        foreach (
            $DB->get_recordset_select(
                'local_catquizlab_attemptlog',
                'attemptid ' . $insql,
                $params,
                'attemptid ASC, id ASC',
                'id, attemptid, tryno, outcome, reasoncode, detail, diagnosis, timecreated'
            ) as $row
        ) {
            $history[(int) $row->attemptid][] = $row;
        }

        foreach ($multi as $sitting) {
            $rows = $history[(int) $sitting->id] ?? [];
            $engine = engine_identity::resolve((int) $sitting->id);
            $enginecreated = $engine['found']
                ? (int) $DB->get_field('adaptivequiz_attempt', 'timecreated', ['id' => (int) $engine['engineattemptid']]) : 0;
            $failures = [];
            $resumes = [];
            foreach ($rows as $row) {
                if (in_array($row->outcome, [attempt_history::FAILED, attempt_history::REQUEUED], true)) {
                    $failures[] = $row;
                }
                if ($row->outcome === attempt_history::STARTED && (int) $row->tryno > 1 && $failures !== []) {
                    $last = end($failures);
                    $resumes[] = [
                        'tryno' => (int) $row->tryno,
                        'startedat' => (int) $row->timecreated,
                        'failedat' => (int) $last->timecreated,
                        'failurecode' => (string) ($last->reasoncode ?? ''),
                        'answeredbefore' => self::answered_before($last),
                    ];
                }
            }
            $risk = self::NONE;
            $why = '';
            if ($resumes !== []) {
                $first = $resumes[0];
                if (!$engine['found']) {
                    $risk = self::NO_ENGINE;
                    $why = 'no engine attempt of the sitting (' . $engine['reason'] . '): nothing was continued';
                } else if ($enginecreated > $first['startedat']) {
                    $risk = self::NEW_ATTEMPT;
                    $why = 'the engine attempt was created after the resumed execution started';
                } else if ($fixedsince === null || $first['failedat'] < $fixedsince) {
                    $risk = self::AT_RISK;
                    $why = 'continued the same engine attempt after a failure that deleted its intermediate estimates';
                } else {
                    $risk = self::AFTER_FIX;
                    $why = 'continued after a failure handled by a version that keeps the estimates';
                }
            }
            $out['counts'][$risk]++;
            $out['sittings'][] = [
                'sitting' => (int) $sitting->id,
                'status' => attempt_scheduler::status_label((int) $sitting->status),
                'executions' => (int) $sitting->tries,
                'engineattemptid' => (int) $engine['engineattemptid'],
                'enginefound' => (bool) $engine['found'],
                'enginereason' => (string) $engine['reason'],
                'qubaid' => (int) $engine['qubaid'],
                'questionsattempted' => (int) $engine['questionsattempted'],
                'resumes' => $resumes,
                'risk' => $risk,
                'why' => $why,
            ];
        }

        return $out;
    }

    /**
     * Mark the sittings an audit found at risk, once each, in their history.
     *
     * @param array $audit The result of audit().
     * @return int How many were marked now.
     */
    public static function mark(array $audit): int {
        global $DB;

        $marked = 0;
        foreach ($audit['sittings'] as $sitting) {
            if ($sitting['risk'] !== self::AT_RISK) {
                continue;
            }
            if (
                $DB->record_exists('local_catquizlab_attemptlog', ['attemptid' => $sitting['sitting'],
                    'outcome' => attempt_history::AUDITED, 'reasoncode' => self::REASON])
            ) {
                continue;
            }
            $resume = $sitting['resumes'][0];
            attempt_history::record($sitting['sitting'], attempt_history::AUDITED, [
                'reasoncode' => self::REASON,
                'engineattemptid' => $sitting['engineattemptid'],
                'detail' => get_string('history:auditedresume', 'local_catquizlab', (object) [
                    'tryno' => $resume['tryno'],
                    'answered' => $resume['answeredbefore'] === null ? '?' : $resume['answeredbefore'],
                    'failedat' => date('c', $resume['failedat']),
                ]),
            ]);
            $marked++;
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
