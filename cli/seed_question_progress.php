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
 * One CLASSIC sitting of 625 questions being played, for the question progress in the browser (#141, #142).
 *
 * Without options: seeds the experiment, a run of 625 items, a sitting in flight that started ten minutes
 * ago and an engine attempt with --answered questions answered (default 100); prints
 * QPROGRESS_EXPERIMENTID and QPROGRESS_ENGINEATTEMPTID. With --engine and --answered: moves that engine
 * attempt on, as the engine does with every confirmed answer.
 *
 * Development and CI only.
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

[$options] = cli_get_params(['answered' => 100, 'engine' => 0, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "Seed a 625-question sitting in flight; --engine=<id> --answered=<n> moves it on.\n";
    exit(0);
}

global $DB;

\core\session\manager::set_user(get_admin());
if (!$DB->get_manager()->table_exists('adaptivequiz_attempt')) {
    cli_error('mod_adaptivequiz is not installed.');
}
$answered = max(0, min(625, (int) $options['answered']));

if ((int) $options['engine'] > 0) {
    $DB->set_field('adaptivequiz_attempt', 'questionsattempted', $answered, ['id' => (int) $options['engine']]);
    echo 'QPROGRESS_ANSWERED=' . $answered . PHP_EOL;
    exit(0);
}

$definition = experiment_definition::example_baseline();
$definition['name'] = 'Question progress ' . time();
$definition['strategy'] = 'classic';
$experimentid = (int) experiment_service::save($definition)['id'];
$runid = (int) $DB->insert_record('local_catquizlab_run', (object) [
    'experimentid' => $experimentid, 'cellkey' => 'strategy=classic', 'replication' => 1, 'seed' => 42,
    'status' => registry::STATUS_RUNNING,
    'manifestjson' => json_encode(['config' => ['definition' => $definition, 'strategy' => ['key' => 'classic']]]),
    'timecreated' => time(), 'timemodified' => time(),
]);
$items = [];
for ($i = 1; $i <= 625; $i++) {
    $items[] = (object) ['runid' => $runid, 'poolid' => 0, 'questionid' => 0, 'itemname' => 'q' . $i,
        'model' => '2pl', 'truedifficulty' => 0, 'storeddifficulty' => 0, 'discrimination' => 1, 'guessing' => 0,
        'timecreated' => time()];
}
$DB->insert_records('local_catquizlab_item', $items);

// The test the sitting is played on: the run's course module, by its instance.
$module = (int) $DB->get_field('modules', 'id', ['name' => 'adaptivequiz']);
$instance = 900000 + $runid;
$cmid = (int) $DB->insert_record('course_modules', (object) ['course' => SITEID, 'module' => $module,
    'instance' => $instance, 'section' => 0, 'visible' => 0, 'added' => time()]);
$DB->set_field('local_catquizlab_run', 'testcmid', $cmid, ['id' => $runid]);

$user = create_user_record('catquizlab_qprogress_' . $runid, random_string(16));
$personid = (int) $DB->insert_record('local_catquizlab_person', (object) [
    'runid' => $runid, 'twinid' => 't00001', 'twinindex' => 1, 'severity' => 'none', 'stratum' => 'conforming',
    'abilityglobal' => 0.0, 'profilejson' => json_encode(['global' => 0.0]), 'moodleuserid' => $user->id,
    'timecreated' => time(), 'timemodified' => time(),
]);
$engine = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
    'instance' => $instance, 'userid' => $user->id, 'uniqueid' => 0, 'attemptstate' => 'inprogress',
    'attemptstopcriteria' => '', 'questionsattempted' => $answered, 'timecreated' => time(), 'timemodified' => time(),
]);
$worker = 'catquizlab-exec-qprogress';
$attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
    'runid' => $runid, 'personid' => $personid, 'status' => attempt_scheduler::STATUS_RUNNING, 'tries' => 1,
    'leaseowner' => $worker, 'leaseexpires' => time() + 3600, 'timecreated' => time(), 'timemodified' => time(),
]);
attempt_history::record($attemptid, attempt_history::STARTED, ['workerid' => $worker, 'tryno' => 1]);
// Started ten minutes ago: a pace of six seconds a question at 100 answered.
$DB->set_field('local_catquizlab_attemptlog', 'timecreated', time() - 600, ['attemptid' => $attemptid]);

echo 'QPROGRESS_EXPERIMENTID=' . $experimentid . PHP_EOL;
echo 'QPROGRESS_ENGINEATTEMPTID=' . $engine . PHP_EOL;
