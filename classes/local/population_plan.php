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
 * People, digital twins and sittings: the plan, and what was materialised.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquizlab\local;

/**
 * Three numbers kept apart (#117).
 *
 * Digital twins are the ground-truth profiles, per replication; the same twins
 * are used in every cell, which is what pairs a comparison. A run-person is a
 * twin in one run; a sitting is one run-person taking the test. Fifty twins in
 * six cells are fifty twins — and three hundred run-persons and three hundred
 * sittings. Each number follows from the definition, and each can be compared
 * with what was stored.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class population_plan {
    /** @var string A run not yet provisioned: nothing to compare yet. */
    public const PENDING = 'pending';

    /** @var string A run as planned. */
    public const OK = 'ok';

    /** @var string A twin more than once in the run. */
    public const DUPLICATE_TWINS = 'duplicate_twins';

    /** @var string More or fewer people than planned. */
    public const PEOPLE_MISMATCH = 'people_mismatch';

    /** @var string More or fewer sittings than people. */
    public const SITTINGS_MISMATCH = 'sittings_mismatch';

    /**
     * What an expansion plans, before anything is created.
     *
     * @param array $expansion What sweep::expand() returned.
     * @return array{twins: ?int, twinspercell: array, cells: int, replications: int, runs: int, runpersons: int, sittings: int}
     */
    public static function plan(array $expansion): array {
        $percell = [];
        $runpersons = 0;
        $replications = [];
        foreach ((array) ($expansion['runs'] ?? []) as $run) {
            $count = person_generator::planned_count((array) $run['definition']);
            $percell[(string) $run['cellkey']] = $count;
            $runpersons += $count;
            $replications[(int) ($run['replication'] ?? 1)] = true;
        }
        $distinct = array_unique(array_values($percell));

        return [
            // One number when every cell has the same twins; otherwise per cell.
            'twins'        => count($distinct) === 1 ? (int) reset($distinct) : null,
            'twinspercell' => count($distinct) > 1 ? $percell : [],
            'cells'        => count($percell),
            'replications' => count($replications),
            'runs'         => count((array) ($expansion['runs'] ?? [])),
            'runpersons'   => $runpersons,
            // One sitting per run-person: the test each of them takes.
            'sittings'     => $runpersons,
        ];
    }

    /**
     * What was materialised for an experiment, run by run, against its plan.
     *
     * @param int $experimentid The experiment.
     * @return array{consistent: bool, twins: int, runs: array[], expected: int, materialised: int, sittings: int,
     *               started: int, collected: int, failed: int}
     */
    public static function actual(int $experimentid): array {
        global $DB;

        $runs = [];
        $totals = ['expected' => 0, 'materialised' => 0, 'sittings' => 0];
        $consistent = true;
        foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
            $expected = person_generator::planned_count(run_registry::definition_for($run));
            $check = person_integrity::check_run((int) $run->id, $expected);
            $sittings = $DB->count_records('local_catquizlab_attempt', ['runid' => $run->id]);
            $state = self::OK;
            if ($check['rows'] === 0 && $sittings === 0) {
                $state = self::PENDING;
            } else if ($check['duplicates'] !== []) {
                $state = self::DUPLICATE_TWINS;
            } else if ($check['rows'] !== $expected) {
                $state = self::PEOPLE_MISMATCH;
            } else if ($sittings > 0 && $sittings !== $check['rows']) {
                $state = self::SITTINGS_MISMATCH;
            }
            if (!in_array($state, [self::OK, self::PENDING], true)) {
                $consistent = false;
            }
            $runs[] = [
                'runid' => (int) $run->id, 'expected' => $expected, 'materialised' => $check['rows'],
                'distinct' => $check['distinct'], 'sittings' => $sittings, 'state' => $state,
                'duplicates' => count($check['duplicates']),
            ];
            $totals['expected'] += $expected;
            $totals['materialised'] += $check['rows'];
            $totals['sittings'] += $sittings;
        }
        [$in, $params] = $runs === [] ? ['= 0', []]
            : $DB->get_in_or_equal(array_column($runs, 'runid'), SQL_PARAMS_NAMED, 'run');
        $twins = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT twinid) FROM {local_catquizlab_person}
              WHERE runid {$in} AND twinid IS NOT NULL AND twinid <> ''",
            $params
        );
        $bystatus = $DB->get_records_sql_menu(
            "SELECT status, COUNT(1) FROM {local_catquizlab_attempt} WHERE runid {$in} GROUP BY status",
            $params
        );
        $collected = (int) ($bystatus[attempt_scheduler::STATUS_COLLECTED] ?? 0)
            + (int) ($bystatus[attempt_scheduler::STATUS_VALIDATED] ?? 0);
        $failed = (int) ($bystatus[attempt_scheduler::STATUS_FAILED] ?? 0);
        $queued = (int) ($bystatus[attempt_scheduler::STATUS_QUEUED] ?? 0);

        return [
            'consistent' => $consistent, 'twins' => $twins, 'runs' => $runs,
            'expected' => $totals['expected'], 'materialised' => $totals['materialised'], 'sittings' => $totals['sittings'],
            // Started: every sitting that has left the queue.
            'started' => $totals['sittings'] - $queued, 'collected' => $collected, 'failed' => $failed,
        ];
    }

    /**
     * The population of an experiment in one sentence, and what is wrong with it.
     *
     * The same words on the progress page and on the results, from the same
     * numbers (#117).
     *
     * @param int $experimentid The experiment.
     * @return array{text: string, problems: string[], consistent: bool}
     */
    public static function describe(int $experimentid): array {
        $component = 'local_catquizlab';
        $actual = self::actual($experimentid);
        $problems = [];
        foreach ($actual['runs'] as $run) {
            if (!in_array($run['state'], [self::OK, self::PENDING], true)) {
                $problems[] = get_string('plan:runproblem_' . $run['state'], $component, (object) $run);
            }
        }

        return [
            'text' => get_string('plan:actual', $component, (object) [
                'twins' => $actual['twins'], 'runs' => count($actual['runs']), 'expected' => $actual['expected'],
                'materialised' => $actual['materialised'], 'sittings' => $actual['sittings'],
                'started' => $actual['started'], 'collected' => $actual['collected'], 'failed' => $actual['failed'],
            ]),
            'problems' => $problems,
            'consistent' => $actual['consistent'],
        ];
    }
}
