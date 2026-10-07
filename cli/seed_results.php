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
 * Results to look at, for the interface test of the plots (#105, section 10).
 *
 * An experiment over two strategies, each run with collected sittings whose
 * traces are shaped as the engine keeps them, and one twin family across both
 * runs. Prints PLOTS_EXPERIMENTID=<id>.
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
use local_catquizlab\local\test_flow;

\core\session\manager::set_user(get_admin());

$definition = experiment_definition::example_baseline();
$definition['name'] = 'Plots ' . date('Ymd-His');
$definition['sweep']['factors']['strategy'] = ['fastest', 'allsubs'];
// Six questions a sitting: within the budgets, so that the sittings are valid ones.
$definition['budgets']['global'] = ['minitems' => 3, 'maxitems' => 30];
$definition['budgets']['subscale'] = ['minitems' => 1, 'maxitems' => 10];
$definition['persons']['count'] = 20;
$definition['pool']['scales'] = ['categories' => 1, 'subcategories' => 1, 'itemspersubscale' => 6];
$experimentid = (int) experiment_service::save($definition)['id'];
experiment_service::create_sweep($experimentid);

mt_srand(105);
$items = [[1.2, -0.5], [0.8, 0.3], [1.5, 1.1], [1.0, -1.2], [0.9, 0.6], [1.3, -0.1]];
foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
    \local_catquizlab\local\run_lifecycle::set_status((int) $run->id, registry::STATUS_FINISHED, 'seeded');
    for ($i = 1; $i <= 20; $i++) {
        $truth = round((mt_rand() / mt_getrandmax() - 0.5) * 5, 3);
        $played = [];
        $path = [];
        foreach ($items as $k => [$a, $b]) {
            $played[(string) (11 + $k)] = ['id' => (string) (11 + $k), 'componentid' => (string) (11 + $k),
                'catscaleid' => '5', 'model' => 'raschbirnbaum', 'discrimination' => (string) $a,
                'difficulty' => (string) $b, 'guessing' => '0'];
            $estimate = round($truth + (mt_rand() / mt_getrandmax() - 0.5) * 2 / ($k + 1), 4);
            $path[] = ['step' => $k + 1, 'abilities' => ['1' => $estimate, '5' => $estimate]];
        }
        $final = end($path)['abilities']['1'];
        $information = 0.0;
        foreach ($played as $item) {
            $information += test_flow::item_information($item, $final);
        }
        $personid = $DB->insert_record('local_catquizlab_person', (object) [
            'runid' => $run->id, 'twinid' => sprintf('r001-t%05d', $i), 'twinindex' => $i, 'stratum' => 'subscalevariation',
            'severity' => 'medium', 'abilityglobal' => $truth,
            'profilejson' => json_encode(['global' => $truth, 'categories' => [[
                'theta' => $truth + 0.3, 'subscales' => [['theta' => $truth + 0.5], ['theta' => $truth - 0.4]],
            ]]]),
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $attemptid = $DB->insert_record('local_catquizlab_attempt', (object) [
            'runid' => $run->id, 'personid' => $personid, 'status' => attempt_scheduler::STATUS_COLLECTED, 'tries' => 1,
            'runtimems' => 4000, 'timecreated' => time(), 'timemodified' => time(),
            'tracejson' => json_encode([
                'finaltheta' => $final, 'finalse' => 1 / sqrt($information), 'information' => $information,
                'items' => array_keys($played), 'nitems' => count($played),
                'stopreason' => 'Reached maximum number of questions', 'scaleabilities' => [],
                'abilitypath' => $path,
                // Answered, some right and some wrong: a sitting whose validity can be judged (#112).
                'responses' => array_combine(array_map('intval', array_keys($played)), [1.0, 0.0, 1.0, 1.0, 0.0, 1.0]),
                'progress' => ['playedquestions' => $played, 'responses' => [], 'activescales' => [5],
                    'droppedscales' => [], 'lockedscales' => []],
            ]),
        ]);
        attempt_history::record($attemptid, attempt_history::COLLECTED, ['tryno' => 1]);
        attempt_history::record_outcome_reason($attemptid, 'Reached maximum number of questions');
    }
}

cli_writeln('PLOTS_EXPERIMENTID=' . $experimentid);
