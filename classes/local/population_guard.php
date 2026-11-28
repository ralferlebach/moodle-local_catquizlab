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
 * Simulated persons stay independent of one another: no one's estimate is another's prior (#174 AK04, re-audit
 * 27.11.2026).
 *
 * The engine reads the admissible abilities of everybody in a test's context (catquiz::get_person_abilities()) and,
 * once there are at least as many as its thresholds — updatepersonability::NUM_ESTIMATION_THRESHOLD for the prior's
 * mean and spread, firstquestionselector::MINIMUM_PARAMS_FOR_ESTIMATE for the start; 50 in both engine lines — takes
 * them as the population a new estimate starts from. A simulated person's prior would then depend on which other twins
 * happened to have finished and not yet been collected: the order of the queue and the speed of the server would
 * become part of the result. The lab removes a person's parameters once its trace is stored (attempt_collector), so
 * the context is empty in the normal course; between the engine's end of an attempt and its collection — a report on
 * its way, a trace that waits (TRACE_PENDING), a trace given up — they are there.
 *
 * Guarded by construction, not by timing: a run hands out a sitting only while the admissible abilities in its
 * contexts, plus the run's other sittings being played (each of which may end before it is collected), stay below the
 * engine's threshold. Then no estimate of any sitting can start from a population, whatever finishes when. When the
 * engine's thresholds cannot be read, nothing is blocked and the guard says so.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class population_guard {
    /** @var string The engine's threshold for the prior's population. */
    protected const PRIOR = '\local_catquiz\teststrategy\preselect_task\updatepersonability::NUM_ESTIMATION_THRESHOLD';

    /** @var string The engine's threshold for the start from the population. */
    protected const START = '\local_catquiz\teststrategy\preselect_task\firstquestionselector::MINIMUM_PARAMS_FOR_ESTIMATE';

    /**
     * The fewest admissible abilities from which the engine uses a population, or null when it cannot be read.
     *
     * @return int|null
     */
    public static function threshold(): ?int {
        $values = [];
        foreach ([self::PRIOR, self::START] as $constant) {
            [$class] = explode('::', $constant);
            if (class_exists($class) && defined($constant)) {
                $values[] = (int) constant($constant);
            }
        }

        return $values === [] ? null : min($values);
    }

    /**
     * Where a run stands: admissible abilities in its contexts, the sittings being played in them, the threshold.
     *
     * @param int $runid The run.
     * @param int $exclude A sitting not to count among those being played (the one asked about).
     * @return array{blocked: bool, admissible: int, running: int, threshold: ?int}
     */
    public static function state(int $runid, int $exclude = 0): array {
        global $DB;

        $threshold = self::threshold();
        $admissible = 0;
        if (class_exists('\local_catquiz\catquiz') && $DB->get_manager()->table_exists('local_catquizlab_scalemap')) {
            $scales = $DB->get_records_select(
                'local_catquizlab_scalemap',
                'runid = :runid AND contextid > 0 AND level = 0',
                ['runid' => $runid],
                '',
                'id, contextid, catscaleid'
            );
            $seen = [];
            foreach ($scales as $scale) {
                $key = (int) $scale->contextid . ':' . (int) $scale->catscaleid;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $admissible = max($admissible, count(\local_catquiz\catquiz::get_person_abilities(
                    (int) $scale->contextid,
                    [(int) $scale->catscaleid]
                )));
            }
        }
        // Every sitting being played in a context this run shares — of this run or another on the same contexts.
        $runids = [$runid];
        $contextids = array_map('intval', $DB->get_fieldset_select(
            'local_catquizlab_scalemap',
            'DISTINCT contextid',
            'runid = :runid AND contextid > 0',
            ['runid' => $runid]
        ));
        if ($contextids !== []) {
            [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
            $runids = array_values(array_unique(array_merge($runids, array_map('intval', $DB->get_fieldset_select(
                'local_catquizlab_scalemap',
                'DISTINCT runid',
                'contextid ' . $insql,
                $params
            )))));
        }
        [$inruns, $runparams] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED, 'run');
        $running = $DB->count_records_select(
            'local_catquizlab_attempt',
            "runid {$inruns} AND status = :running AND id <> :exclude",
            $runparams + ['running' => attempt_scheduler::STATUS_RUNNING, 'exclude' => $exclude]
        );

        return [
            'blocked' => $threshold !== null && $admissible + $running >= $threshold,
            'admissible' => $admissible,
            'running' => $running,
            'threshold' => $threshold,
        ];
    }
}
