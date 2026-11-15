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
 * What one progress poll costs with many runs (#158 DoD4, #160 AK12): queries, time and memory of an experiment's
 * snapshot and of the overview of all experiments, at 1, 10, 100 and 1000 runs.
 *
 * Seeds experiments with runs and sittings straight into the tables (no courses, no engine), measures, and removes
 * them again unless --keep. The figures are those of the installation it runs on.
 *
 * Usage: php local/catquizlab/cli/bench_progress.php [--sizes=1,10,100,1000] [--perexperiment=10] [--sittings=5]
 *        [--keep]
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\live_progress;
use local_catquizlab\local\progress_overview;
use local_catquizlab\local\question_progress;
use local_catquizlab\local\registry;

[$options] = cli_get_params(['sizes' => '1,10,100,1000', 'perexperiment' => 10, 'sittings' => 5, 'keep' => false]);
\core\session\manager::set_user(get_admin());

$sizes = array_map('intval', explode(',', (string) $options['sizes']));
$perexperiment = max(1, (int) $options['perexperiment']);
$sittings = max(1, (int) $options['sittings']);
$created = [];

/**
 * Seed $runs runs in experiments of $perexperiment, each with $sittings sittings in mixed states.
 *
 * @param int $runs Runs.
 * @param int $perexperiment Runs per experiment.
 * @param int $sittings Sittings per run.
 * @return int[] The experiments.
 */
function bench_seed(int $runs, int $perexperiment, int $sittings): array {
    global $DB;

    $now = time();
    $experiments = [];
    for ($made = 0; $made < $runs; $made += $perexperiment) {
        $experimentid = (int) $DB->insert_record('local_catquizlab_experiment', (object) ['name' => 'Bench ' . $runs . ' ' .
            count($experiments), 'tier' => 'baseline', 'configjson' => '{}', 'status' => 0, 'usermodified' => 0,
            'timecreated' => $now, 'timemodified' => $now]);
        $experiments[] = $experimentid;
        for ($r = 0; $r < min($perexperiment, $runs - $made); $r++) {
            $strategy = $r % 2 ? 'fastest' : 'classic';
            $runid = (int) $DB->insert_record('local_catquizlab_run', (object) ['experimentid' => $experimentid,
                'cellkey' => $strategy . '-' . $r, 'seed' => 1, 'replication' => 1, 'status' => registry::STATUS_RUNNING,
                'manifestjson' => json_encode(['config' => ['strategy' => ['key' => $strategy],
                    'definition' => ['strategy' => $strategy]]]), 'usermodified' => 0, 'timecreated' => $now,
                'timemodified' => $now]);
            $attempts = [];
            for ($i = 0; $i < $sittings; $i++) {
                $status = [attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_QUEUED,
                    attempt_scheduler::STATUS_RUNNING][$i % 3];
                $attempts[] = (object) ['runid' => $runid, 'personid' => 0, 'status' => $status, 'tries' => 1,
                    'runtimems' => 30000, 'leaseexpires' => $status === attempt_scheduler::STATUS_RUNNING ? $now + 3600 : 0,
                    'nextruntime' => 0, 'timecreated' => $now, 'timemodified' => $now];
            }
            $DB->insert_records('local_catquizlab_attempt', $attempts);
            $logs = [];
            foreach ($DB->get_records('local_catquizlab_attempt', ['runid' => $runid], '', 'id, status') as $a) {
                $logs[] = (object) ['attemptid' => $a->id, 'runid' => $runid, 'tryno' => 1,
                    'outcome' => attempt_history::STARTED, 'runtimems' => null, 'timecreated' => $now - 120];
                if ((int) $a->status === attempt_scheduler::STATUS_COLLECTED) {
                    $logs[] = (object) ['attemptid' => $a->id, 'runid' => $runid, 'tryno' => 1,
                        'outcome' => attempt_history::COLLECTED, 'runtimems' => 30000, 'timecreated' => $now - 90];
                }
            }
            $DB->insert_records('local_catquizlab_attemptlog', $logs);
        }
    }

    return $experiments;
}

/**
 * Queries, seconds and peak memory of one call.
 *
 * @param callable $fn The call.
 * @return array{queries: int, seconds: float, memory: float}
 */
function bench_measure(callable $fn): array {
    global $DB;

    question_progress::reset_caches();
    gc_collect_cycles();
    $start = microtime(true);
    $reads = $DB->perf_get_reads();
    $memory = memory_get_usage();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $fn();

    return ['queries' => $DB->perf_get_reads() - $reads, 'seconds' => round(microtime(true) - $start, 3),
        'memory' => round((memory_get_peak_usage() - $memory) / 1048576, 1)];
}

cli_writeln(sprintf('%-6s %-12s %8s %8s %8s', 'runs', 'what', 'queries', 'seconds', 'MB'));
foreach ($sizes as $size) {
    $experiments = bench_seed($size, $perexperiment, $sittings);
    $created = array_merge($created, $experiments);
    $one = bench_measure(static fn() => live_progress::snapshot($experiments[0]));
    $all = bench_measure(static fn() => progress_overview::build(0));
    $poll = bench_measure(static fn() => \local_catquizlab\external\live_status::execute(0, 0));
    $pollone = bench_measure(static fn() => \local_catquizlab\external\live_status::execute($experiments[0], 0));
    foreach (['experiment' => $one, 'overview' => $all, 'poll (all)' => $poll, 'poll (one)' => $pollone] as $what => $m) {
        cli_writeln(sprintf('%-6d %-12s %8d %8.3f %8.1f', $size, $what, $m['queries'], $m['seconds'], $m['memory']));
    }
}

if (empty($options['keep'])) {
    foreach ($created as $experimentid) {
        $runids = $DB->get_fieldset_select('local_catquizlab_run', 'id', 'experimentid = ?', [$experimentid]);
        if ($runids) {
            [$in, $params] = $DB->get_in_or_equal($runids);
            $DB->delete_records_select('local_catquizlab_attemptlog', "runid $in", $params);
            $DB->delete_records_select('local_catquizlab_attempt', "runid $in", $params);
            $DB->delete_records_select('local_catquizlab_run', "id $in", $params);
        }
        $DB->delete_records('local_catquizlab_experiment', ['id' => $experimentid]);
    }
    cli_writeln('Seeded rows removed.');
}
