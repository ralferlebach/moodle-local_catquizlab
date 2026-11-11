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
 * Real parallel writers and readers on the collection of real engine sittings (#138).
 *
 * Takes collected sittings whose engine attempt exists, and plays their end again under concurrency: the engine's
 * attempt is taken away (as when the engine has not persisted it yet), the sittings are set running, and then
 * separate PHP processes at once — workers reporting completion again and again (double complete), the pipeline
 * tick looking for missing traces, a run re-collection, the lease recovery — race for them, while a reader process
 * watches every sitting's row. After a delay the engine attempt reappears. Checked at the end, per sitting:
 *
 * - never "collected" before the engine attempt was there again (the reader's observations against the moment);
 * - never "collected" without its trace or its engine attempt;
 * - collected in the end, exactly once in its history, with the same trace as before;
 * - the engine attempt not doubled.
 *
 * The sittings are put back as they were afterwards, also when a check fails; --restore=FILE puts them back from the
 * snapshot after an interrupted run. Development and CI only: it changes the sittings it plays with.
 *
 *     php local/catquizlab/cli/race_collect.php --sittings=8 --reporters=4 --seconds=40 --delay=12
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\attempt_collector;
use local_catquizlab\local\attempt_history;
use local_catquizlab\local\attempt_scheduler;

[$options] = cli_get_params([
    'sittings' => 8, 'reporters' => 4, 'ticks' => 2, 'recollectors' => 1, 'recoverers' => 1, 'seconds' => 40,
    'delay' => 12, 'child' => '', 'ids' => '', 'until' => 0, 'marker' => '', 'restore' => '', 'json' => false,
    'help' => false,
], ['h' => 'help']);
if ($options['help']) {
    echo "Race the collection of real engine sittings (#138). --sittings --reporters --ticks --recollectors --recoverers\n"
        . "--seconds --delay --json --restore=FILE\n";
    exit(0);
}
\core\session\manager::set_user(get_admin());
global $DB, $CFG;

$worker = 'race-worker';

// A child: one role, until the given time, one JSON line per thing it saw or did.
if ($options['child'] !== '') {
    $ids = array_map('intval', explode(',', (string) $options['ids']));
    $until = (float) $options['until'];
    $role = (string) $options['child'];
    $events = 0;
    while (microtime(true) < $until) {
        try {
            foreach ($ids as $id) {
                if ($role === 'reader') {
                    $row = $DB->get_record_sql('SELECT status, engineattemptid, CASE WHEN tracejson IS NULL THEN 0 ELSE 1 END
                        AS traced FROM {local_catquizlab_attempt} WHERE id = ?', [$id]);
                    if ($row && (int) $row->status === attempt_scheduler::STATUS_COLLECTED) {
                        echo json_encode(['role' => $role, 'id' => $id, 'at' => microtime(true), 'status' => 'collected',
                            'traced' => (int) $row->traced, 'engine' => (int) $row->engineattemptid]) . "\n";
                    }
                } else if ($role === 'reporter') {
                    $engine = (int) $DB->get_field('local_catquizlab_attempt', 'engineattemptid', ['id' => $id]);
                    $answer = \local_catquizlab\external\job_complete::execute(
                        $id,
                        'finished',
                        60000,
                        $engine,
                        '',
                        '',
                        $worker
                    );
                    echo json_encode(['role' => $role, 'id' => $id, 'at' => microtime(true),
                        'acknowledged' => (bool) ($answer['acknowledged'] ?? false)]) . "\n";
                } else if ($role === 'tick') {
                    $out = attempt_scheduler::retry_pending_traces(time() + 3600);
                    echo json_encode(['role' => $role, 'at' => microtime(true)] + $out) . "\n";
                    break;
                } else if ($role === 'recollect') {
                    $runid = (int) $DB->get_field('local_catquizlab_attempt', 'runid', ['id' => $id]);
                    $out = attempt_collector::collect_run($runid);
                    echo json_encode(['role' => $role, 'id' => $id, 'at' => microtime(true),
                        'collected' => $out['collected']]) . "\n";
                } else if ($role === 'recover') {
                    $out = attempt_scheduler::reclaim_stale(null, 0);
                    echo json_encode(['role' => $role, 'at' => microtime(true), 'reclaimed' => $out]) . "\n";
                    break;
                }
                $events++;
            }
        } catch (\Throwable $e) {
            echo json_encode(['role' => $role, 'at' => microtime(true), 'exception' => get_class($e) . ': '
                . $e->getMessage()]) . "\n";
        }
        usleep($role === 'reader' ? 20000 : 50000);
    }
    exit(0);
}

$dir = make_writable_directory($CFG->dataroot . '/temp/catquizlab_race');

/**
 * Put the sittings and engine attempts back as the snapshot has them.
 *
 * @param array $snapshot The snapshot.
 * @return void
 */
function local_catquizlab_race_restore(array $snapshot): void {
    global $DB;
    foreach ($snapshot['engine'] as $row) {
        if (!$DB->record_exists('adaptivequiz_attempt', ['id' => $row['id']])) {
            $DB->insert_record_raw('adaptivequiz_attempt', (object) $row, false, false, true);
        }
    }
    foreach ($snapshot['lab'] as $row) {
        $DB->update_record('local_catquizlab_attempt', (object) $row);
    }
    // The history the race wrote goes with it: the sittings are as they were.
    $ids = array_map(static fn(array $r): int => (int) $r['id'], $snapshot['lab']);
    if ($ids !== []) {
        [$in, $params] = $DB->get_in_or_equal($ids);
        $DB->delete_records_select(
            'local_catquizlab_attemptlog',
            'id > ? AND attemptid ' . $in,
            array_merge([(int) $snapshot['lastlog']], $params)
        );
    }
}

if ($options['restore'] !== '') {
    local_catquizlab_race_restore(json_decode((string) file_get_contents($options['restore']), true));
    cli_writeln('restored from ' . $options['restore']);
    exit(0);
}

// The sittings: collected ones whose engine attempt is there, at most one per engine attempt.
$rows = $DB->get_records_sql(
    'SELECT a.* FROM {local_catquizlab_attempt} a
       JOIN {adaptivequiz_attempt} q ON q.id = a.engineattemptid
      WHERE a.status = :collected AND a.tracejson IS NOT NULL AND q.uniqueid > 0
   ORDER BY a.id DESC',
    ['collected' => attempt_scheduler::STATUS_COLLECTED],
    0,
    (int) $options['sittings']
);
if (count($rows) < 1) {
    cli_error('No collected sitting with its engine attempt to play with.');
}
$ids = array_map('intval', array_keys($rows));
$snapshot = ['lab' => [], 'engine' => [], 'history' => [], 'reference' => [], 'engines' => [],
    'lastlog' => (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {local_catquizlab_attemptlog}')];
foreach ($rows as $row) {
    $snapshot['lab'][] = (array) $row;
    $engine = $DB->get_record('adaptivequiz_attempt', ['id' => $row->engineattemptid]);
    $snapshot['engine'][] = (array) $engine;
    $snapshot['history'][(int) $row->id] = $DB->count_records(
        'local_catquizlab_attemptlog',
        ['attemptid' => $row->id, 'outcome' => attempt_history::COLLECTED]
    );
    // The reference: this sitting collected now, undisturbed, by this version — not the trace an older version wrote.
    $trace = attempt_collector::collect((int) $row->id) ?? json_decode((string) $row->tracejson, true);
    $snapshot['reference'][(int) $row->id] = [$trace['finaltheta'] ?? null, $trace['finalse'] ?? null,
        $trace['nitems'] ?? null, count((array) ($trace['responses'] ?? []))];
    $snapshot['engines'][(int) $row->id] = $DB->count_records(
        'adaptivequiz_attempt',
        ['userid' => $engine->userid, 'instance' => $engine->instance]
    );
}
$file = $dir . '/race-' . date('Ymd-His') . '.json';
file_put_contents($file, json_encode($snapshot));

$report = ['db' => $DB->get_dbfamily(), 'sittings' => $ids, 'snapshot' => $file, 'checks' => [], 'events' => []];
try {
    // The end played again: running, leased to the racing worker, no trace; the engine's attempt gone for now.
    foreach ($rows as $row) {
        $DB->update_record('local_catquizlab_attempt', (object) ['id' => $row->id, 'status' => attempt_scheduler::STATUS_RUNNING,
            'tracejson' => null, 'tracebytes' => null, 'digestjson' => null, 'digestbytes' => null, 'leaseowner' => $worker,
            'leaseexpires' => time() + 3600, 'nextruntime' => 0]);
        $DB->delete_records('adaptivequiz_attempt', ['id' => $row->engineattemptid]);
    }

    $until = microtime(true) + (int) $options['seconds'];
    $roles = array_merge(
        array_fill(0, (int) $options['reporters'], 'reporter'),
        array_fill(0, (int) $options['ticks'], 'tick'),
        array_fill(0, (int) $options['recollectors'], 'recollect'),
        array_fill(0, (int) $options['recoverers'], 'recover'),
        ['reader']
    );
    $children = [];
    foreach ($roles as $index => $role) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child=' . $role
            . ' --ids=' . implode(',', $index % 2 ? array_reverse($ids) : $ids) . ' --until=' . $until;
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $_ENV ?: null);
        stream_set_blocking($pipes[1], false);
        $children[] = [$process, $pipes, $role];
    }

    // The engine's attempt appears again, after the delay, while everything races.
    $restoreat = microtime(true) + (int) $options['delay'];
    $restored = null;
    $output = array_fill(0, count($children), '');
    while (true) {
        if ($restored === null && microtime(true) >= $restoreat) {
            foreach ($snapshot['engine'] as $engine) {
                $DB->insert_record_raw('adaptivequiz_attempt', (object) $engine, false, false, true);
            }
            $restored = microtime(true);
        }
        $running = 0;
        foreach ($children as $index => [$process, $pipes]) {
            $output[$index] .= (string) stream_get_contents($pipes[1]);
            if (proc_get_status($process)['running']) {
                $running++;
            }
        }
        if ($running === 0 && $restored !== null) {
            break;
        }
        usleep(20000);
    }
    foreach ($children as $index => [$process, $pipes]) {
        $output[$index] .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    // What everybody saw and did.
    $events = [];
    foreach ($output as $text) {
        foreach (array_filter(explode("\n", $text)) as $line) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }
    }
    $count = static fn(callable $f): int => count(array_filter($events, $f));
    $report['events'] = [
        'reports' => $count(static fn($e) => ($e['role'] ?? '') === 'reporter'),
        'reportsacknowledged' => $count(static fn($e) => ($e['role'] ?? '') === 'reporter' && !empty($e['acknowledged'])),
        'ticks' => $count(static fn($e) => ($e['role'] ?? '') === 'tick'),
        'recollections' => $count(static fn($e) => ($e['role'] ?? '') === 'recollect'),
        'recoveries' => $count(static fn($e) => ($e['role'] ?? '') === 'recover'),
        'observations' => $count(static fn($e) => ($e['role'] ?? '') === 'reader'),
        'exceptions' => array_values(array_unique(array_column(
            array_filter($events, static fn($e) => isset($e['exception'])),
            'exception'
        ))),
        'restoredafter' => round($restored - ($until - (int) $options['seconds']), 2),
    ];

    // A last look by the tick, as the pipeline would make after the race.
    attempt_scheduler::retry_pending_traces(time() + 7200);

    foreach ($ids as $id) {
        $after = $DB->get_record('local_catquizlab_attempt', ['id' => $id]);
        $trace = json_decode((string) $after->tracejson, true) ?: [];
        $seen = array_filter($events, static fn($e) => ($e['role'] ?? '') === 'reader' && (int) ($e['id'] ?? 0) === $id);
        $engine = $snapshot['engine'][array_search($id, array_map(static fn($r) => (int) $r['id'], $snapshot['lab']), true)];
        $report['checks'][$id] = [
            'nevercollectedbeforetheengine' => !array_filter($seen, static fn($e) => $e['at'] < $restored),
            'nevercollectedwithouttrace' => !array_filter($seen, static fn($e) => empty($e['traced']) || empty($e['engine'])),
            'collectedintheend' => (int) $after->status === attempt_scheduler::STATUS_COLLECTED && $after->tracejson !== null,
            'collectedonce' => $DB->count_records('local_catquizlab_attemptlog', ['attemptid' => $id,
                'outcome' => attempt_history::COLLECTED]) === $snapshot['history'][$id] + 1,
            'sametrace' => [$trace['finaltheta'] ?? null, $trace['finalse'] ?? null, $trace['nitems'] ?? null,
                count((array) ($trace['responses'] ?? []))] === $snapshot['reference'][$id],
            'engineattemptkept' => (int) $after->engineattemptid === (int) $engine['id'],
            'enginenotdoubled' => $DB->count_records('adaptivequiz_attempt', ['userid' => $engine['userid'],
                'instance' => $engine['instance']]) === $snapshot['engines'][$id],
            'leasereleased' => empty($after->leaseowner),
            'history' => array_values($DB->get_fieldset_select(
                'local_catquizlab_attemptlog',
                'outcome',
                'attemptid = ? AND timecreated >= ?',
                [$id, (int) floor($until - (int) $options['seconds'])]
            )),
        ];
    }
} finally {
    // Back as they were, whatever happened.
    local_catquizlab_race_restore($snapshot);
}

$failed = 0;
foreach ($report['checks'] as $checks) {
    foreach ($checks as $name => $ok) {
        if ($name !== 'history' && $ok !== true) {
            $failed++;
        }
    }
}
$report['passed'] = $failed === 0;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($failed === 0 ? 0 : 3);
