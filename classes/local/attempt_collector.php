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
        ];
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
