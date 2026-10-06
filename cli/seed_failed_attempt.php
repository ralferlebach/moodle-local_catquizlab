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
 * A run with one sitting that failed the way the live installation's did.
 *
 * For the interface test of issue #98: it needs a failure to diagnose and
 * retry, and waiting for the engine to produce one is not a test. Prints
 * RECOVERY_RUNID=<id> for a CI job to put into its environment.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;

global $DB;

\core\session\manager::set_user(get_admin());

$definition = experiment_definition::example_baseline();
$definition['name'] = 'Recovery test ' . time();
$experimentid = (int) experiment_service::save($definition)['id'];

$runid = (int) $DB->insert_record('local_catquizlab_run', (object) [
    'experimentid' => $experimentid, 'cellkey' => 'model=2pl;strategy=classic',
    'replication' => 1, 'seed' => 42, 'status' => registry::STATUS_FINISHED,
    'manifestjson' => json_encode($definition), 'timecreated' => time(), 'timemodified' => time(),
]);

$failure = 'Attempt did not reach the finish page after 1 answer(s). '
    . 'url=https://example.org/mod/adaptivequiz/attempt.php?cmid=12&sesskey=abc123 '
    . 'title="Fehler | Client01" error="Fehler: Division by zero" '
    . '| server replay: DivisionByZeroError at local/catquiz/classes/local/model/model_raschmodel.php:734';

foreach ([attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_COLLECTED, attempt_scheduler::STATUS_FAILED] as $status) {
    $attemptid = (int) $DB->insert_record('local_catquizlab_attempt', (object) [
        'runid' => $runid, 'personid' => 0, 'status' => $status,
        'tries' => $status === attempt_scheduler::STATUS_FAILED ? attempt_scheduler::MAX_TRIES : 1,
        'lasterror' => $status === attempt_scheduler::STATUS_FAILED ? $failure : null,
        'leaseowner' => 'catquizlab-exec-7',
        'timecreated' => time(), 'timemodified' => time(),
    ]);
    if ($status === attempt_scheduler::STATUS_FAILED) {
        attempt_history::record($attemptid, attempt_history::STARTED, ['workerid' => 'catquizlab-exec-7', 'tryno' => 1]);
        attempt_history::record($attemptid, attempt_history::FAILED, [
            'workerid' => 'catquizlab-exec-7', 'tryno' => 1, 'detail' => $failure,
        ]);
    }
}

echo 'RECOVERY_RUNID=' . $runid . PHP_EOL;
