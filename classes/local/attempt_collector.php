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

/**
 * Attempt collector: copy a finished engine attempt into an analysis trace.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Reads a finished adaptive attempt from the engine tables into a lab trace (E3.5).
 *
 * After the worker has played an attempt through the adaptivequiz UI, the CAT
 * engine has recorded which items it selected, the responses, and the final
 * ability estimate with its standard error. This class copies that black-box
 * record — the adaptivequiz_attempt's question usage plus the engine's
 * local_catquiz_attempts / local_catquiz_personparams rows (the tables the
 * Wunderbyte simulation scripts write) — into a compact {@see attempt} trace and
 * marks the attempt collected.
 *
 * Reading the engine tables obviously needs the engine and the host activity, so
 * {@see self::collect()} returns null when either is absent (which keeps CI and
 * stand-alone installs green). The trace assembly itself, {@see self::build_trace()},
 * is pure and testable without any engine.
 */
class attempt_collector {
    /**
     * Queue the ad-hoc task that collects a run's attempts.
     *
     * @param int $runid The run whose attempts to collect.
     * @return void
     */
    public static function queue(int $runid): void {
        $task = new \local_catquizlab\task\collect_attempts();
        $task->set_custom_data(['runid' => $runid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Collect every attempt of a run that carries an engine attempt id.
     *
     * @param int $runid The run to collect.
     * @return array{candidates: int, collected: int, runtimems: int} What was collected and how long it took.
     */
    public static function collect_run(int $runid): array {
        global $DB;

        $started = microtime(true);
        $readsbefore = $DB->perf_get_reads();
        $writesbefore = $DB->perf_get_writes();
        $attempts = $DB->get_records_select(
            'local_catquizlab_attempt',
            'runid = :runid AND engineattemptid IS NOT NULL AND engineattemptid > 0',
            ['runid' => $runid],
            'id ASC',
            'id'
        );

        $collected = 0;
        foreach ($attempts as $attempt) {
            if (self::collect((int) $attempt->id) !== null) {
                $collected++;
            }
        }

        return [
            'candidates' => count($attempts),
            'collected'  => $collected,
            'runtimems'  => (int) round((microtime(true) - $started) * 1000),
            'dbreads'    => $DB->perf_get_reads() - $readsbefore,
            'dbwrites'   => $DB->perf_get_writes() - $writesbefore,
        ];
    }

    /**
     * Collect one attempt's trace from the engine and store it.
     *
     * @param int $attemptid The lab attempt id (local_catquizlab_attempt).
     * @return array|null The stored trace, or null when the engine data is unavailable.
     */
    public static function collect(int $attemptid): ?array {
        global $DB;

        $attempt = $DB->get_record('local_catquizlab_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        if (
            empty($attempt->engineattemptid)
            || !environment::engine_available()
            || !environment::adaptivequiz_available()
        ) {
            return null;
        }

        $engine = self::read_engine_attempt((int) $attempt->engineattemptid);
        if ($engine === null) {
            return null;
        }

        $trace = self::build_trace(
            $engine['finaltheta'],
            $engine['finalse'],
            $engine['responses'],
            $engine['stopreason']
        );
        $debug = $engine['debug'] ?? [];
        $trace['scaleabilities'] = $debug['scaleabilities'] ?? [];
        $trace['questionsperscale'] = $debug['questionsperscale'] ?? [];
        $trace['abilitypath'] = $debug['abilitypath'] ?? [];
        $trace['steps'] = $debug['steps'] ?? 0;
        // Per-scale standard errors come from the engine's person parameters,
        // not from debug_info. Without them the local agreement measures — is
        // the estimated deviation within one or two standard errors of the true
        // one — cannot be computed at all, and guessing a value would turn a
        // missing measurement into a fabricated one.
        $trace['scalestandarderrors'] = $engine['scalestandarderrors'] ?? [];
        // The progress row carries what debug_info does not: which scales were
        // active, dropped or locked, and the item sequence. It survives the
        // attempt today, but only until the activity is deleted, and the engine
        // ships a delete() for it — so the lab keeps its own copy rather than
        // depending on someone else's retention decision.
        $trace['progress'] = self::read_progress((int) $attempt->engineattemptid);
        $trace['steps'] = self::step_series($trace, $debug);

        $DB->update_record('local_catquizlab_attempt', (object) [
            'id'           => $attemptid,
            'status'       => attempt_scheduler::STATUS_COLLECTED,
            'tracejson'    => json_encode($trace, JSON_UNESCAPED_SLASHES),
            'timemodified' => time(),
        ]);

        return $trace;
    }

    /**
     * Assemble a compact trace from the engine's final estimate and responses.
     *
     * @param float $finaltheta The engine's final ability estimate.
     * @param float|null $finalse The final standard error, if known.
     * @param array $responses Map of question id to response fraction (0..1), in item order.
     * @param string $stopreason The engine's stop criterion, if any.
     * @return array The trace (finaltheta, finalse, items, responses, nitems, stopreason).
     */
    public static function build_trace(float $finaltheta, ?float $finalse, array $responses, string $stopreason = ''): array {
        $items = array_keys($responses);

        return [
            'finaltheta' => round($finaltheta, 5),
            'finalse'    => $finalse === null ? null : round($finalse, 5),
            'items'      => $items,
            'responses'  => $responses,
            'nitems'     => count($items),
            'stopreason' => $stopreason,
        ];
    }

    /**
     * Read a finished engine attempt: final ability/SE and the per-item responses.
     *
     * Mirrors the engine schema used by the Wunderbyte simulation scripts: the
     * adaptivequiz_attempt carries the question usage; question_attempts /
     * question_attempt_steps hold the played items and their fractions;
     * local_catquiz_attempts / local_catquiz_personparams hold the final ability
     * and standard error.
     *
     * @param int $engineattemptid The adaptivequiz_attempt id.
     * @return array|null The engine data, or null when the attempt is not found.
     */
    protected static function read_engine_attempt(int $engineattemptid): ?array {
        global $DB;

        $aq = $DB->get_record('adaptivequiz_attempt', ['id' => $engineattemptid]);
        if (!$aq) {
            return null;
        }

        $catquiz = $DB->get_record(
            'local_catquiz_attempts',
            ['attemptid' => $engineattemptid, 'component' => 'adaptivequiz']
        );
        $finaltheta = $catquiz ? (float) $catquiz->personability_after_attempt : 0.0;
        $finalse = self::final_standarderror($catquiz);

        return [
            'finaltheta' => $finaltheta,
            'finalse'    => $finalse,
            'responses'  => self::read_responses((int) $aq->uniqueid),
            'stopreason' => (string) ($aq->attemptstopcriteria ?? ''),
            'debug'      => self::parse_debug_info((string) ($catquiz->debug_info ?? '')),
            'scalestandarderrors' => self::read_scale_standarderrors($catquiz),
        ];
    }

    /**
     * Archive the engine's progress snapshot of an attempt, if it still exists.
     *
     * The row currently outlives the attempt and is removed only when the
     * activity itself is deleted. That is not something to rely on: the engine
     * defines progress::delete(), and the table is documented as holding the
     * data needed to continue an attempt. A missing row is therefore treated as
     * ordinary rather than as an error.
     *
     * @param int $engineattemptid The engine attempt id.
     * @return array The decoded snapshot, or an empty array when it is gone.
     */
    protected static function read_progress(int $engineattemptid): array {
        global $DB;

        if ($engineattemptid <= 0 || !$DB->get_manager()->table_exists('local_catquiz_progress')) {
            return [];
        }

        $record = $DB->get_records(
            'local_catquiz_progress',
            ['attemptid' => $engineattemptid],
            'id DESC',
            '*',
            0,
            1
        );
        if (!$record) {
            return [];
        }
        $record = reset($record);
        $decoded = json_decode((string) ($record->json ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }

        // Only the parts the test-flow view needs are kept. The rest is engine
        // bookkeeping and would bloat every trace row.
        $keep = [
            'playedquestions', 'playedquestionsbyscale', 'activescales',
            'droppedscales', 'lockedscales', 'responses', 'abilities',
            'preattemptabilities', 'starttime',
        ];

        return array_intersect_key($decoded, array_flip($keep));
    }

    /**
     * Build the per-step series of a test flow.
     *
     * Prefers the progress snapshot, which records the ability after each
     * response; falls back to the step count debug_info reports, which is all
     * that survives once the progress row is deleted.
     *
     * @param array $trace The trace being assembled.
     * @param array $debug The parsed debug_info.
     * @return int The number of steps.
     */
    protected static function step_series(array $trace, array $debug): int {
        $progress = (array) ($trace['progress'] ?? []);
        $played = (array) ($progress['playedquestions'] ?? []);

        return $played !== [] ? count($played) : (int) ($debug['steps'] ?? 0);
    }

    /**
     * Read the per-scale standard errors of a finished attempt.
     *
     * local_catquiz_personparams carries one row per scale with the ability and
     * its standard error. Older engine builds shipped the table without the
     * standarderror column, so a missing column is reported as no data rather
     * than as a fatal.
     *
     * @param \stdClass|false $catquiz The engine attempt row.
     * @return array<int, float> Standard error keyed by engine scale id.
     */
    protected static function read_scale_standarderrors($catquiz): array {
        global $DB;

        if (!$catquiz || empty($catquiz->attemptid)) {
            return [];
        }
        $columns = $DB->get_columns('local_catquiz_personparams');
        if (!isset($columns['standarderror']) || !isset($columns['attemptid'])) {
            return [];
        }

        $rows = $DB->get_records(
            'local_catquiz_personparams',
            ['attemptid' => (int) $catquiz->attemptid],
            '',
            'id, catscaleid, standarderror'
        );

        $out = [];
        foreach ($rows as $row) {
            if ($row->standarderror !== null) {
                $out[(int) $row->catscaleid] = (float) $row->standarderror;
            }
        }

        return $out;
    }

    /**
     * Extract the per-scale ability path and exposure from an engine debug_info blob.
     *
     * The engine records debug_info as a JSON list of per-step snapshots; the last
     * snapshot carries the final per-scale ability estimates (personabilities) and
     * the number of questions asked per scale (numquestionsperscale). These give
     * the subscale-level estimates the DPF diagnostics compare against the truth.
     *
     * @param string $json The debug_info JSON.
     * @return array{steps: int, scaleabilities: array<int, float>, questionsperscale: array}
     */
    public static function parse_debug_info(string $json): array {
        $empty = ['steps' => 0, 'scaleabilities' => [], 'questionsperscale' => []];
        if ($json === '') {
            return $empty;
        }
        $rows = json_decode($json, true);
        if (!is_array($rows) || $rows === []) {
            return $empty;
        }

        $last = null;
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['personabilities'])) {
                $last = $row;
            }
        }
        $last = $last ?? (array) end($rows);

        // The debug_info blob is a list of per-step snapshots, so the path is
        // the sequence of them — not just the final one. Reading only the last
        // snapshot, as this used to, discards the trajectory the test-flow view
        // exists to show.
        $path = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !isset($row['personabilities'])) {
                continue;
            }
            $path[] = [
                'step'      => $index + 1,
                'abilities' => self::normalise_abilities($row['personabilities']),
            ];
        }

        return [
            'steps'             => count($rows),
            'scaleabilities'    => self::normalise_abilities($last['personabilities'] ?? []),
            'abilitypath'       => $path,
            'questionsperscale' => is_array($last['numquestionsperscale'] ?? null)
                ? $last['numquestionsperscale']
                : [],
        ];
    }

    /**
     * Normalise a personabilities structure to a scaleid => ability map.
     *
     * Accepts a map keyed by scale id or a list of rows with a scale id and value.
     *
     * @param mixed $abilities The raw personabilities value.
     * @return array<int, float>
     */
    protected static function normalise_abilities($abilities): array {
        if (!is_array($abilities)) {
            return [];
        }

        $out = [];
        foreach ($abilities as $key => $value) {
            if (is_array($value)) {
                $scaleid = $value['catscaleid'] ?? $value['scaleid'] ?? $key;
                $ability = $value['ability'] ?? $value['value'] ?? null;
                if ($ability !== null) {
                    $out[(int) $scaleid] = (float) $ability;
                }
            } else {
                $out[(int) $key] = (float) $value;
            }
        }
        return $out;
    }

    /**
     * Read the latest standard error recorded for the engine attempt.
     *
     * @param \stdClass|false $catquiz The local_catquiz_attempts row, if any.
     * @return float|null
     */
    protected static function final_standarderror($catquiz): ?float {
        global $DB;

        if (!$catquiz) {
            return null;
        }
        $params = $DB->get_records(
            'local_catquiz_personparams',
            ['attemptid' => $catquiz->id],
            'timemodified DESC, id DESC',
            'id, standarderror',
            0,
            1
        );
        $param = reset($params);

        return $param && $param->standarderror !== null ? (float) $param->standarderror : null;
    }

    /**
     * Read the per-item responses of a question usage, in slot order.
     *
     * @param int $questionusageid The question usage (adaptivequiz_attempt.uniqueid).
     * @return array Map of question id to response fraction.
     */
    protected static function read_responses(int $questionusageid): array {
        global $DB;

        $attempts = $DB->get_records('question_attempts', ['questionusageid' => $questionusageid], 'slot ASC');
        $responses = [];
        foreach ($attempts as $attempt) {
            $steps = $DB->get_records(
                'question_attempt_steps',
                ['questionattemptid' => $attempt->id],
                'sequencenumber DESC, id DESC',
                'id, fraction',
                0,
                1
            );
            $step = reset($steps);
            $responses[(int) $attempt->questionid] = ($step && $step->fraction !== null) ? (float) $step->fraction : null;
        }
        return $responses;
    }
}
