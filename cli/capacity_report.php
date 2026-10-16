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
 * The worker capacity of this installation, as evidence before and after a change (#124).
 *
 * Prints the limit in force and why, the live workers by state, the load
 * guard's window, executions and capacity failures per five minutes over the
 * last hours, and the installation's capacity events. Run it before and after
 * changing worker_concurrency or worker_safety_cap, and during a load
 * measurement at 1/5/10/25/50 workers (docs/dev/worker-kapazitaet.md).
 *
 * Options: --hours=2, --json.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_history;
use local_catquizlab\local\circuit_breaker;
use local_catquizlab\local\worker_capacity;

[$options] = cli_get_params(['hours' => 2, 'json' => false]);
$hours = max(1, (int) $options['hours']);
$now = time();
$since = $now - $hours * HOURSECS;

$snapshot = worker_capacity::snapshot($now);

// Per five minutes: executions finished, capacity failures, other failures, recovered submissions.
$buckets = [];
$rows = $DB->get_recordset_select(
    'local_catquizlab_attemptlog',
    'timecreated > :since AND outcome IN (:collected, :failed, :recovered)',
    ['since' => $since, 'collected' => attempt_history::COLLECTED, 'failed' => attempt_history::FAILED,
        'recovered' => attempt_history::RECOVERED],
    'timecreated ASC',
    'id, timecreated, outcome, reasoncode'
);
foreach ($rows as $row) {
    $bucket = (int) (floor((int) $row->timecreated / 300) * 300);
    $b = &$buckets[$bucket];
    $b = $b ?? ['executions' => 0, 'capacity' => 0, 'other' => 0, 'recovered' => 0];
    if ($row->outcome === attempt_history::RECOVERED) {
        $b['recovered']++;
        if (in_array((string) $row->reasoncode, circuit_breaker::CAPACITY_CAUSES, true)) {
            $b['capacity']++;
        }
        unset($b);
        continue;
    }
    $b['executions']++;
    if ($row->outcome === attempt_history::FAILED) {
        $capacity = in_array((string) $row->reasoncode, circuit_breaker::CAPACITY_CAUSES, true);
        $b[$capacity ? 'capacity' : 'other']++;
    }
    unset($b);
}
$rows->close();

$events = array_values($DB->get_records_select(
    'local_catquizlab_runlog',
    'runid = 0 AND timecreated > :since',
    ['since' => $since],
    'id ASC',
    'id, timecreated, event, detail'
));

if (!empty($options['json'])) {
    echo json_encode(
        ['now' => $now, 'capacity' => $snapshot, 'buckets' => $buckets, 'events' => $events],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . "\n";
    exit(0);
}

cli_writeln('Capacity at ' . date('c', $now));
cli_writeln('  ' . $snapshot['text']['line']);
if ($snapshot['text']['why'] !== '') {
    cli_writeln('  ' . $snapshot['text']['why']);
}
cli_writeln(sprintf(
    '  load guard: %s; window %d s: %d capacity failures / %d executions (%.0f %%)',
    $snapshot['guard']['throttled'] ? 'THROTTLED until ' . date('H:i:s', $snapshot['guard']['until']) : 'off',
    worker_capacity::GUARD_WINDOW,
    $snapshot['guard']['failures'],
    $snapshot['guard']['executions'],
    100 * $snapshot['guard']['share']
));
cli_writeln('');
cli_writeln('Per five minutes (executions finished, capacity failures, other failures, recovered submissions):');
foreach ($buckets as $at => $b) {
    cli_writeln(sprintf(
        '  %s  %5d  %5d  %5d  %5d',
        date('H:i', $at),
        $b['executions'],
        $b['capacity'],
        $b['other'],
        $b['recovered']
    ));
}
cli_writeln('');
cli_writeln('Installation events:');
foreach ($events as $event) {
    cli_writeln('  ' . date('Y-m-d H:i:s', (int) $event->timecreated) . '  ' . $event->event . '  ' . $event->detail);
}
