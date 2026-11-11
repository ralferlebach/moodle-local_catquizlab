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
 * A large experiment of long sittings for measuring the results page (#147, D3).
 *
 * Twins played by two strategies (the classical test and "all subscales"), each sitting --questions long, with the
 * engine's record of every step for --scales subscales: the progress of each question played, the ability path of
 * every scale, the responses, the end estimates and standard errors of every scale. A share of the sittings is
 * invalid (all answers correct; no final ability), as on a real experiment. Written with their digests unless
 * --nodigest, which writes them as a version before 0.7.57 would have.
 *
 *     php local/catquizlab/cli/seed_results_scale.php --sittings=9000 --questions=625 --scales=20
 *     php local/catquizlab/cli/seed_results_scale.php --delete=<experimentid>
 *
 * Prints SCALE_EXPERIMENTID, the sittings, the bytes of trace and of digest. Development and measurement only.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;
use local_catquizlab\local\scale_provisioner;
use local_catquizlab\local\trace_digest;

[$options] = cli_get_params(['sittings' => 1000, 'questions' => 625, 'scales' => 20, 'runs' => 2, 'nodigest' => false,
    'delete' => 0, 'seed' => 147, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "Seed a large experiment of long sittings (#147). --sittings --questions --scales --runs --nodigest --delete=ID\n";
    exit(0);
}
\core\session\manager::set_user(get_admin());
global $DB;

if ((int) $options['delete'] > 0) {
    $experimentid = (int) $options['delete'];
    $runids = array_keys($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], '', 'id'));
    foreach (array_chunk($runids, 200) as $chunk) {
        [$in, $params] = $DB->get_in_or_equal($chunk);
        $tables = ['local_catquizlab_attempt', 'local_catquizlab_person', 'local_catquizlab_scalemap', 'local_catquizlab_item'];
        foreach ($tables as $table) {
            $DB->delete_records_select($table, 'runid ' . $in, $params);
        }
        $DB->delete_records_select('local_catquizlab_run', 'id ' . $in, $params);
    }
    $DB->delete_records('local_catquizlab_experiment', ['id' => $experimentid]);
    cli_writeln('deleted experiment ' . $experimentid . ' with ' . count($runids) . ' runs');
    exit(0);
}

$sittings = max(2, (int) $options['sittings']);
$questions = max(1, (int) $options['questions']);
$scales = max(1, (int) $options['scales']);
$runsperstrategy = max(1, (int) $options['runs']);
mt_srand((int) $options['seed']);

$definition = experiment_definition::example_baseline();
$definition['name'] = sprintf('Scale %d x %dq x %ds %s', $sittings, $questions, $scales, date('Ymd-His'));
$definition['sweep']['factors']['strategy'] = ['classic', 'allsubs'];
$definition['sweep']['replications'] = $runsperstrategy;
$definition['budgets']['global'] = ['minitems' => 1, 'maxitems' => $questions];
$definition['budgets']['subscale'] = ['minitems' => 1, 'maxitems' => $questions];
$definition['pool']['scales'] = ['categories' => 1, 'subcategories' => $scales,
    'itemspersubscale' => (int) ceil($questions / $scales)];
$experimentid = (int) experiment_service::save($definition)['id'];
experiment_service::create_sweep($experimentid);
$runs = array_values($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC'));
$perrun = (int) ceil($sittings / count($runs));

$draw = static fn(): float => mt_rand() / mt_getrandmax();
$tracebytes = 0;
$digestbytes = 0;
$written = 0;
foreach ($runs as $runindex => $run) {
    \local_catquizlab\local\run_lifecycle::set_status((int) $run->id, registry::STATUS_FINISHED, 'seeded');
    $base = (int) $run->id * 1000;
    $items = [];
    $rows = [];
    for ($s = 1; $s <= $scales; $s++) {
        $DB->insert_record('local_catquizlab_scalemap', (object) ['runid' => $run->id,
            'level' => scale_provisioner::LEVEL_SUBSCALE, 'catscaleid' => $base + $s, 'parentcatscaleid' => $base,
            'contextid' => 1, 'nodekey' => 'c1s' . $s, 'generation' => 1, 'categoryindex' => 1, 'subscaleindex' => $s,
            'name' => 'S' . $s, 'timecreated' => time()]);
    }
    for ($q = 0; $q < $questions; $q++) {
        $scaleid = $base + 1 + ($q % $scales);
        $questionid = (int) $run->id * 100000 + $q + 1;
        $items[] = [$questionid, $scaleid, round(0.5 + 2 * $draw(), 3), round(-2 + 4 * $draw(), 3)];
        $rows[] = (object) ['runid' => $run->id, 'questionid' => $questionid, 'itemname' => 'q' . $questionid,
            'assignedcatscaleid' => $scaleid, 'truecatscaleid' => $scaleid, 'timecreated' => time()];
    }
    $DB->insert_records('local_catquizlab_item', $rows);

    $people = [];
    $attempts = [];
    for ($i = 1; $i <= $perrun && $written < $sittings; $i++, $written++) {
        // Twins: the same person in both strategies of a replication.
        $twin = sprintf('r%03d-t%05d', (int) floor($runindex / 2) + 1, $i);
        mt_srand(crc32($twin));
        $global = round(-2 + 4 * $draw(), 3);
        $subs = [];
        for ($s = 1; $s <= $scales; $s++) {
            $subs[] = ['index' => $s, 'theta' => round($global - 1 + 2 * $draw(), 3)];
        }
        mt_srand((int) $options['seed'] + $written);
        $people[] = (object) ['runid' => $run->id, 'twinid' => $twin, 'twinindex' => $i, 'stratum' => 'subscalevariation',
            'severity' => 'medium', 'abilityglobal' => $global,
            'profilejson' => json_encode(['global' => $global, 'categories' => [['index' => 1, 'theta' => $global,
                'subscales' => $subs]]]), 'timecreated' => time(), 'timemodified' => time()];
        $kind = $i % 50 === 0 ? 'allcorrect' : ($i % 77 === 0 ? 'nofinal' : 'valid');
        $played = [];
        $path = [];
        $responses = [];
        $abilities = array_fill_keys(range($base + 1, $base + $scales), 0.0);
        foreach ($items as $k => [$questionid, $scaleid, $a, $b]) {
            $played[(string) $questionid] = ['id' => (string) $questionid, 'componentid' => (string) $questionid,
                'catscaleid' => (string) $scaleid, 'model' => 'raschbirnbaum', 'discrimination' => (string) $a,
                'difficulty' => (string) $b, 'guessing' => '0', 'fisherinformation' => round($draw(), 6),
                'lastattempttime' => time() - $questions + $k];
            $responses[$questionid] = $kind === 'allcorrect' ? 1.0 : ($draw() < 0.5 ? 1.0 : 0.0);
            $abilities[$scaleid] = round($global + ($draw() - 0.5) * 2 / sqrt($k + 1), 5);
            $path[] = ['step' => $k + 1, 'abilities' => array_map(static fn($v) => round($v, 5), $abilities)];
        }
        $ses = array_map(static fn(): float => round(0.25 + 0.2 * mt_rand() / mt_getrandmax(), 4), $abilities);
        $trace = ['finalse' => 0.3, 'information' => 11.1, 'items' => array_column($items, 0), 'nitems' => $questions,
            'stopreason' => 'Reached maximum number of questions', 'hoststopcode' => 'maximumquestions',
            'scaleabilities' => $abilities, 'scalestandarderrors' => $ses, 'abilitypath' => $path,
            'responses' => $responses,
            'progress' => ['playedquestions' => $played, 'responses' => [], 'activescales' => array_keys($abilities),
                'droppedscales' => [], 'lockedscales' => []]];
        if ($kind !== 'nofinal') {
            $trace['finaltheta'] = round($global + ($draw() - 0.5) * 0.4, 4);
        }
        $json = json_encode($trace);
        $digest = $options['nodigest'] ? ['digestjson' => null, 'digestbytes' => null] : trace_digest::fields($json);
        $tracebytes += strlen($json);
        $digestbytes += (int) (trace_digest::fields($json)['digestbytes']);
        $attempts[] = ['runid' => $run->id, 'status' => attempt_scheduler::STATUS_COLLECTED, 'tries' => 1,
            'runtimems' => 600000, 'timecreated' => time(), 'timemodified' => time(), 'tracejson' => $json,
            'tracebytes' => strlen($json)] + $digest;
        if (count($attempts) === 20) {
            local_catquizlab_seed_scale_flush($people, $attempts);
        }
    }
    local_catquizlab_seed_scale_flush($people, $attempts);
    cli_writeln(sprintf('run %d: %d sittings so far, %.0f MB of trace', $run->id, $written, $tracebytes / 1048576));
}

/**
 * Write the people and their sittings held so far.
 *
 * @param array $people The people, emptied.
 * @param array $attempts Their sittings, in the same order, emptied.
 * @return void
 */
function local_catquizlab_seed_scale_flush(array &$people, array &$attempts): void {
    global $DB;
    foreach ($people as $index => $person) {
        $personid = $DB->insert_record('local_catquizlab_person', $person);
        $DB->insert_record('local_catquizlab_attempt', (object) (['personid' => $personid] + $attempts[$index]));
    }
    $people = [];
    $attempts = [];
}

cli_writeln('SCALE_EXPERIMENTID=' . $experimentid);
cli_writeln('SCALE_RUNID=' . (int) $runs[0]->id);
cli_writeln(sprintf(
    'SCALE_SITTINGS=%d SCALE_TRACE_MB=%.0f SCALE_DIGEST_MB=%.0f',
    $written,
    $tracebytes / 1048576,
    $digestbytes / 1048576
));
