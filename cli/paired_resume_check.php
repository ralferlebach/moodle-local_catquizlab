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
 * The same simulated people, once played through and once interrupted and resumed: is the result the same? (#174 AK3)
 *
 * Two experiments of the same definition and seed — the same twins, the same items (by their key), the same answers
 * drawn. One played without interruption, the other with each sitting's first execution ended after K answers and
 * continued by its retry (CATQUIZLAB_FAULTS=endexecution@K). Per twin and strategy compared: the items in order (by
 * key), the answer to each, the ability after every step (the engine's path), the final ability and standard error,
 * and the stop reason. The tolerance is fixed before anything is read: 1e-6 for the path, which the trace keeps
 * unrounded, and 5e-5 for the final ability and standard error, which it keeps to four places. A third experiment
 * played through again (--control) says whether two uninterrupted runs agree at all; without that, a difference could
 * not be put down to the interruption.
 *
 *     php local/catquizlab/cli/paired_resume_check.php --clean=E1 --resumed=E2 [--control=E3] [--json]
 *
 * Exit 1 when a twin differs beyond the tolerance, a twin is missing on one side, or nothing was compared.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params(
    ['clean' => 0, 'resumed' => 0, 'control' => 0, 'json' => false, 'help' => false],
    ['h' => 'help']
);
if ($options['help'] || (int) $options['clean'] <= 0 || (int) $options['resumed'] <= 0) {
    echo "Uninterrupted against interrupted and resumed, twin by twin (#174 AK3).\n"
        . "  --clean=E     The experiment played through.\n"
        . "  --resumed=E   The same experiment with each first execution ended and resumed.\n"
        . "  --control=E   Played through again: whether two uninterrupted runs agree.\n"
        . "  --json        As JSON.\n";
    exit(0);
}

/** Tolerance for the ability after each step (kept unrounded in the trace). */
const PATH_TOLERANCE = 1e-6;
/** Tolerance for the final ability and standard error (kept to four places). */
const FINAL_TOLERANCE = 5e-5;

/**
 * Every collected sitting of an experiment by strategy and twin, its items by key.
 *
 * @param int $experimentid The experiment.
 * @return array<string, array> "strategy|stratum|twinindex" => sitting.
 */
function paired_sittings(int $experimentid): array {
    global $DB;

    $out = [];
    foreach ($DB->get_records('local_catquizlab_run', ['experimentid' => $experimentid], 'id ASC') as $run) {
        $definition = \local_catquizlab\local\run_registry::definition_for($run);
        $strategy = (string) ($definition['strategy'] ?? '');
        $keys = $DB->get_records_menu('local_catquizlab_item', ['runid' => $run->id], '', 'questionid, itemkey');
        // Scales by their place in the design (nodekey), not by the engine's id: each run has scales of its own.
        $nodes = $DB->get_records_menu('local_catquizlab_scalemap', ['runid' => $run->id], '', 'catscaleid, nodekey');
        $sql = 'SELECT a.id, a.status, a.tries, a.tracejson, p.stratum, p.twinindex, p.abilityglobal
                  FROM {local_catquizlab_attempt} a
                  JOIN {local_catquizlab_person} p ON p.id = a.personid
                 WHERE a.runid = ?';
        foreach ($DB->get_records_sql($sql, [$run->id]) as $row) {
            $trace = json_decode((string) $row->tracejson, true) ?: [];
            $items = array_map(
                static fn($q): string => (string) ($keys[(int) $q] ?? ('q' . (int) $q)),
                (array) ($trace['items'] ?? [])
            );
            $answers = [];
            foreach ((array) ($trace['items'] ?? []) as $q) {
                $answers[] = $trace['responses'][(string) $q] ?? null;
            }
            $path = [];
            foreach ((array) ($trace['abilitypath'] ?? []) as $step) {
                $abilities = [];
                foreach ((array) ($step['abilities'] ?? []) as $scale => $value) {
                    $abilities[(string) ($nodes[(int) $scale] ?? ('s' . $scale))] = $value;
                }
                ksort($abilities);
                $path[] = $abilities;
            }
            $out[$strategy . '|' . $row->stratum . '|' . $row->twinindex] = [
                'sitting' => (int) $row->id, 'status' => (int) $row->status, 'tries' => (int) $row->tries,
                'ability' => (float) $row->abilityglobal, 'items' => $items, 'answers' => $answers, 'path' => $path,
                'finaltheta' => $trace['finaltheta'] ?? null, 'finalse' => $trace['finalse'] ?? null,
                'stopreason' => (string) ($trace['stopreason'] ?? ''),
            ];
        }
    }

    return $out;
}

/**
 * Where two sittings of one twin part, or [] when they agree within the tolerance.
 *
 * @param array $a One.
 * @param array $b The other.
 * @return string[]
 */
function paired_differences(array $a, array $b): array {
    $diff = [];
    if (abs($a['ability'] - $b['ability']) > 1e-12) {
        $diff[] = 'not the same twin (true ability ' . $a['ability'] . ' / ' . $b['ability'] . ')';
    }
    if ($a['items'] !== $b['items']) {
        $n = min(count($a['items']), count($b['items']));
        for ($i = 0; $i < $n && $a['items'][$i] === $b['items'][$i]; $i++) {
            continue;
        }
        $diff[] = sprintf('items part at position %d of %d/%d', $i + 1, count($a['items']), count($b['items']));
    }
    if ($a['answers'] !== $b['answers']) {
        $diff[] = 'answers differ';
    }
    $steps = max(count($a['path']), count($b['path']));
    for ($s = 0; $s < $steps; $s++) {
        $pa = $a['path'][$s] ?? null;
        $pb = $b['path'][$s] ?? null;
        if ($pa === null || $pb === null || array_keys($pa) != array_keys($pb)) {
            $diff[] = 'ability path parts at step ' . ($s + 1) . ' (scales or length)';
            break;
        }
        foreach ($pa as $scale => $value) {
            if (abs((float) $value - (float) $pb[$scale]) > PATH_TOLERANCE) {
                $diff[] = sprintf('ability path parts at step %d, scale %s: %.9f / %.9f', $s + 1, $scale, $value, $pb[$scale]);
                break 2;
            }
        }
    }
    foreach (['finaltheta', 'finalse'] as $key) {
        if ($a[$key] === null || $b[$key] === null || abs((float) $a[$key] - (float) $b[$key]) > FINAL_TOLERANCE) {
            $diff[] = sprintf('%s %s / %s', $key, var_export($a[$key], true), var_export($b[$key], true));
        }
    }
    if ($a['stopreason'] !== $b['stopreason']) {
        $diff[] = 'stop reason "' . $a['stopreason'] . '" / "' . $b['stopreason'] . '"';
    }

    return $diff;
}

$clean = paired_sittings((int) $options['clean']);
$resumed = paired_sittings((int) $options['resumed']);
$report = ['tolerance' => ['path' => PATH_TOLERANCE, 'final' => FINAL_TOLERANCE], 'twins' => 0, 'resumedsittings' => 0,
    'equal' => 0, 'differ' => [], 'missing' => [], 'control' => null];
foreach ($clean + $resumed as $key => $unused) {
    if (!isset($clean[$key]) || !isset($resumed[$key])) {
        $report['missing'][] = $key;
        continue;
    }
    $report['twins']++;
    $report['resumedsittings'] += $resumed[$key]['tries'] > 1 ? 1 : 0;
    $diff = paired_differences($clean[$key], $resumed[$key]);
    if ($diff === []) {
        $report['equal']++;
    } else {
        $report['differ'][$key] = ['clean' => $clean[$key]['sitting'], 'resumed' => $resumed[$key]['sitting'],
            'where' => $diff];
    }
}
if ((int) $options['control'] > 0) {
    $control = paired_sittings((int) $options['control']);
    $agree = 0;
    $apart = [];
    foreach ($clean as $key => $sitting) {
        if (isset($control[$key])) {
            $diff = paired_differences($sitting, $control[$key]);
            $diff === [] ? $agree++ : $apart[$key] = $diff;
        }
    }
    $report['control'] = ['agree' => $agree, 'apart' => $apart];
}

$ok = $report['twins'] > 0 && $report['resumedsittings'] > 0 && $report['differ'] === [] && $report['missing'] === []
    && ($report['control'] === null || $report['control']['apart'] === []);
if ($options['json']) {
    echo json_encode($report + ['verdict' => $ok ? 'PASS' : 'FAIL'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    cli_writeln(sprintf(
        '%d twin(s) compared, %d of them resumed on the interrupted side; equal: %d; differ: %d; missing: %d',
        $report['twins'],
        $report['resumedsittings'],
        $report['equal'],
        count($report['differ']),
        count($report['missing'])
    ));
    foreach ($report['differ'] as $key => $d) {
        cli_writeln("  $key (sittings {$d['clean']} / {$d['resumed']}): " . implode('; ', $d['where']));
    }
    if ($report['control'] !== null) {
        cli_writeln(sprintf(
            'Control (played through twice): agree %d, apart %d',
            $report['control']['agree'],
            count($report['control']['apart'])
        ));
    }
    cli_writeln($ok ? 'PASS' : 'FAIL');
}
exit($ok ? 0 : 1);
