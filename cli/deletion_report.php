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
 * How an experiment's deletion went, from its record, also after the experiment is gone (#145 AK6, #161).
 *
 * Lists the events — queued, started, the engine footprint before and after per run, failed, finished — with their
 * times. With --expect-deleted it is a check: exit 0 only when the deletion finished with nothing left in the lab's
 * tables, every run's engine check passed, and the experiment is gone; 3 otherwise.
 *
 *     php local/catquizlab/cli/deletion_report.php --experimentid=42 [--json] [--expect-deleted]
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\deletion_log;

[$options] = cli_get_params(
    ['experimentid' => 0, 'json' => false, 'expect-deleted' => false, 'help' => false],
    ['h' => 'help']
);
$experimentid = (int) $options['experimentid'];
if ($options['help'] || $experimentid <= 0) {
    echo "How an experiment's deletion went (#145, #161). --experimentid=N [--json] [--expect-deleted]\n";
    exit(0);
}
global $DB;

$events = deletion_log::events($experimentid);
$state = deletion_log::state($experimentid);
$exists = $DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid]);
$engineok = true;
foreach ($events as $event) {
    if ($event['event'] === 'engine-after' && empty($event['ok'])) {
        $engineok = false;
    }
}
$deep = (bool) ($events[0]['deep'] ?? false);
$runsbefore = count(array_filter($events, static fn($e) => $e['event'] === 'engine-before'));
$verdict = $state['state'] === 'deleted' && !$exists && ($engineok || !$deep);

if ($options['json']) {
    echo json_encode(
        ['experimentid' => $experimentid, 'state' => $state['state'], 'experimentexists' => $exists,
        'deep' => $deep, 'enginechecks' => $runsbefore, 'engineok' => $engineok, 'complete' => $verdict, 'events' => $events],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} else {
    cli_writeln('Experiment ' . $experimentid . ': ' . $state['state'] . ($exists ? ' (its row is still there)' : ' (gone)')
        . ', ' . count($events) . ' event(s).');
    foreach ($events as $e) {
        $data = array_diff_key($e, array_flip(['time', 'event', 'experimentid', 'correlationid']));
        cli_writeln(sprintf(
            '  %s  %-13s %s',
            date('Y-m-d H:i:s', (int) $e['time']),
            $e['event'],
            json_encode($data, JSON_UNESCAPED_SLASHES)
        ));
    }
}
if ($options['expect-deleted']) {
    exit($verdict ? 0 : 3);
}
