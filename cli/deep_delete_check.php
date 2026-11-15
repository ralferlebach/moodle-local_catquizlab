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
 * A deep deletion of a played experiment against the real engine, checked before and after (#161 DoD2, #145 AK1).
 *
 * Three phases, so that the deletion itself runs as it does on a site — queued by the confirmation, carried out by the
 * background task with Moodle's real module deletion (course_delete_module(), from 5.2 the course format's action) and
 * the activity's own callbacks:
 *
 *     php local/catquizlab/cli/deep_delete_check.php --experimentid=N --phase=before > before.json
 *     php local/catquizlab/cli/deep_delete_check.php --experimentid=N --phase=queue
 *     php admin/cli/adhoc_task.php --execute --classname='\local_catquizlab\task\delete_experiment'
 *     php local/catquizlab/cli/deep_delete_check.php --experimentid=N --phase=after --before=before.json
 *
 * Before: every run's engine footprint (what of the engine, the activity and Moodle is the run's alone, and the rows of
 * others sharing an attempt number) and the engine's rows without an owner. After, PASS only when: the experiment is
 * gone; nothing of any run's footprint is left; every row of others is still there; no kind of ownerless engine row
 * grew; the deletion record says queued, started, a passed engine check per run and finished with nothing left. Exit
 * 0 PASS, 3 FAIL.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\deletion_log;
use local_catquizlab\local\engine_footprint;
use local_catquizlab\local\engine_inventory;

[$options] = cli_get_params(['experimentid' => 0, 'phase' => '', 'before' => '', 'help' => false], ['h' => 'help']);
$experimentid = (int) $options['experimentid'];
if ($options['help'] || $experimentid <= 0 || !in_array($options['phase'], ['before', 'queue', 'after'], true)) {
    echo "A deep deletion against the real engine, checked (#161). --experimentid=N --phase=before|queue|after"
        . " [--before=FILE]\n";
    exit($options['help'] ? 0 : 1);
}
global $DB;

if ($options['phase'] === 'before') {
    $runs = [];
    foreach ($DB->get_fieldset_select('local_catquizlab_run', 'id', 'experimentid = ?', [$experimentid]) as $runid) {
        $runs[(int) $runid] = engine_footprint::of_run((int) $runid);
    }
    if ($runs === []) {
        cli_error('Experiment ' . $experimentid . ' has no run.');
    }
    echo json_encode(
        ['experimentid' => $experimentid, 'runs' => $runs, 'orphans' => engine_inventory::orphans()],
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
}

if ($options['phase'] === 'queue') {
    // As the confirmation form does, as an administrator: stop the experiment, queue its deep deletion.
    \core\session\manager::set_user(get_admin());
    $stopped = \local_catquizlab\local\experiment_stop::stop($experimentid);
    \local_catquizlab\task\delete_experiment::queue($experimentid, true);
    cli_writeln('Experiment ' . $experimentid . ' stopped (' . $stopped['runs'] . ' run(s)) and queued for deep deletion.');
    exit(0);
}

$before = json_decode((string) file_get_contents((string) $options['before']), true);
if (!is_array($before) || (int) ($before['experimentid'] ?? 0) !== $experimentid) {
    cli_error('--before must be the output of --phase=before for this experiment.');
}
$problems = [];
if ($DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
    $problems[] = 'the experiment is still there';
}
$owned = 0;
$atrisk = 0;
foreach ($before['runs'] as $runid => $footprint) {
    $owned += array_sum(array_map('count', $footprint['owned']));
    $atrisk += array_sum(array_map('count', $footprint['atrisk']));
    $check = engine_footprint::check($footprint);
    if ($check['left'] !== []) {
        $problems[] = 'run ' . $runid . ': left ' . engine_footprint::line($check['left']);
    }
    if ($check['lost'] !== []) {
        $problems[] = 'run ' . $runid . ': others\' rows lost ' . engine_footprint::line($check['lost']);
    }
    cli_writeln(sprintf(
        '  run %d: %s owned before, %s of others at risk; left: %s; lost: %s',
        $runid,
        array_sum(array_map('count', $footprint['owned'])),
        array_sum(array_map('count', $footprint['atrisk'])),
        engine_footprint::line($check['left']) ?: 'nothing',
        engine_footprint::line($check['lost']) ?: 'nothing'
    ));
}
foreach (engine_inventory::orphans() as $kind => $now) {
    $was = (int) ($before['orphans'][$kind]['count'] ?? 0);
    if ($now['count'] > $was) {
        $problems[] = 'more ' . $kind . ': ' . $was . ' → ' . $now['count'];
    }
}
$events = deletion_log::events($experimentid);
$names = array_column($events, 'event');
$checks = array_values(array_filter($events, static fn($e) => $e['event'] === 'engine-after'));
if (!in_array('queued', $names, true) || !in_array('started', $names, true) || end($names) !== 'finished') {
    $problems[] = 'the deletion record does not end with finished: ' . implode(', ', $names);
}
if (count($checks) !== count($before['runs']) || array_filter($checks, static fn($e) => empty($e['ok'])) !== []) {
    $problems[] = 'not every run\'s engine check passed in the record';
}
cli_writeln('Experiment ' . $experimentid . ': ' . count($before['runs']) . ' run(s), ' . $owned . ' row(s) of their own in the'
    . ' engine, the activity and Moodle before, ' . $atrisk . ' row(s) of others sharing a number; record: '
    . implode(' → ', $names) . '.');
foreach ($problems as $problem) {
    cli_writeln('  FAIL: ' . $problem);
}
cli_writeln($problems === [] ? 'PASS: deleted completely, nothing of others touched, no new ownerless engine row.'
    : 'FAIL');
exit($problems === [] ? 0 : 3);
