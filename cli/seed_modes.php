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
 * Mode-specific detection to look at in the browser (#144): a deficit mode, "all sub" and the classical test.
 *
 * Six twins with the same true profile (a deficit on subscale 1, a strength on 2, two near the level). The
 * deficit mode plays subscales 1 and 3; "all sub" all four, but sees the deficit too faintly and errs upwards on
 * 3; its sixth sitting answers everything right and is not valid. So against "all sub": five pairs, two common
 * subscales, ten signed points on two coordinates — (+0.6; +0.2) and (+0.2; −0.1), five each.
 *
 * Prints MODES_EXPERIMENTID=<id>. Development and CI only.
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
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_registry;
use local_catquizlab\local\scale_provisioner;

global $DB;

\core\session\manager::set_user(get_admin());

// The true local deviation of the four subscales, and what each strategy plays and estimates of them.
$truth = [1 => -1.0, 2 => 0.9, 3 => 0.1, 4 => -0.2];
$plays = [
    'lowestsub' => [1 => -0.8, 3 => 0.0],
    'allsubs' => [1 => -0.4, 2 => 0.7, 3 => 0.3, 4 => -0.2],
    'classic' => [1 => -1.0, 2 => 0.9, 3 => 0.1, 4 => -0.2],
];

$definition = experiment_definition::example_baseline();
$definition['name'] = 'Modes ' . date('Ymd-His');
$definition['persons']['count'] = 6;
$definition['pool']['scales'] = ['categories' => 1, 'subcategories' => 4, 'itemspersubscale' => 3];
$definition['budgets'] = [
    'global'   => ['minitems' => 2, 'maxitems' => 12],
    'subscale' => ['minitems' => 1, 'maxitems' => 3],
    'se'       => ['min' => 0.35, 'max' => 1.0],
];
$definition['sweep']['factors']['strategy'] = array_keys($plays);
$experimentid = (int) experiment_service::save($definition)['id'];
experiment_service::create_sweep($experimentid);

foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
    \local_catquizlab\local\run_lifecycle::set_status((int) $run->id, registry::STATUS_FINISHED, 'seeded');
    $strategy = (string) run_registry::describe($run)['strategy'];
    $base = (int) $run->id * 100;
    $questions = [];
    foreach ([1, 2, 3, 4] as $subscale) {
        $DB->insert_record('local_catquizlab_scalemap', (object) [
            'runid' => $run->id, 'level' => scale_provisioner::LEVEL_SUBSCALE,
            'catscaleid' => $base + $subscale, 'parentcatscaleid' => $base, 'contextid' => 1,
            'nodekey' => 'c1s' . $subscale, 'generation' => 1, 'categoryindex' => 1,
            'subscaleindex' => $subscale, 'timecreated' => time(),
        ]);
        foreach ([1, 2, 3] as $n) {
            $questionid = $base * 10 + $subscale * 10 + $n;
            $questions[$subscale][] = $questionid;
            $DB->insert_record('local_catquizlab_item', (object) ['runid' => $run->id, 'questionid' => $questionid,
                'assignedcatscaleid' => $base + $subscale, 'truecatscaleid' => $base + $subscale,
                'itemname' => 'q' . $questionid, 'timecreated' => time()]);
        }
    }
    for ($twin = 1; $twin <= 6; $twin++) {
        $global = 0.0;
        $subscales = [];
        foreach ($truth as $subscale => $delta) {
            $subscales[] = ['index' => $subscale, 'theta' => $global + $delta];
        }
        $personid = (int) $DB->insert_record('local_catquizlab_person', (object) [
            'runid' => $run->id, 'twinid' => sprintf('r001-t%05d', $twin), 'twinindex' => $twin,
            'severity' => 'none', 'stratum' => 'conforming', 'abilityglobal' => $global,
            'profilejson' => json_encode(['global' => $global,
                'categories' => [['index' => 1, 'theta' => $global, 'subscales' => $subscales]]]),
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $items = [];
        $responses = [];
        $abilities = [];
        // Each scale's standard error, as the engine stores it: the run's rules ask for one (#112).
        $ses = [];
        $allcorrect = $strategy === 'allsubs' && $twin === 6;
        foreach ($plays[$strategy] as $subscale => $estimate) {
            $played = $strategy === 'classic' ? $questions[$subscale] : array_slice($questions[$subscale], 0, 2);
            foreach ($played as $index => $questionid) {
                $items[] = $questionid;
                $responses[$questionid] = $allcorrect || $index % 2 === 0 ? 1.0 : 0.0;
            }
            $abilities[$base + $subscale] = $global + $estimate;
            $ses[$base + $subscale] = 0.4;
        }
        $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $personid, 'status' => attempt_scheduler::STATUS_COLLECTED,
            'tries' => 1, 'runtimems' => 2000, 'timecreated' => time(), 'timemodified' => time(),
            'tracejson' => json_encode(['finaltheta' => $global, 'finalse' => 0.3, 'items' => $items,
                'responses' => $responses, 'nitems' => count($items), 'stopreason' => 'se',
                'scaleabilities' => $abilities, 'scalestandarderrors' => $ses]),
        ]);
        attempt_history::record($attemptid, attempt_history::COLLECTED, ['tryno' => 1]);
    }
}

cli_writeln('MODES_EXPERIMENTID=' . $experimentid);
