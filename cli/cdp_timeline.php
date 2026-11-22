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
 * Every uncertain answer submission of a time window, to the millisecond, and on whose side it went wrong
 * (#110 AK-CLARA-01; classes/local/cdp_timeline.php).
 *
 *     php local/catquizlab/cli/cdp_timeline.php --from=UNIXTIME [--until=UNIXTIME] [--json]
 *         [--expect=request_not_received:2,response_not_delivered:4]
 *
 * --expect: exactly these counts per cause (a step with injected faults knows what it injected); any other cause,
 * any unattributed event, or another count: exit 1. Without it: exit 1 only for unattributed events.
 * Ids, times and codes only; no answer, no question.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\cdp_timeline;

[$options] = cli_get_params(['from' => 0, 'until' => 0, 'json' => false, 'expect' => '', 'help' => false]);
if (!empty($options['help']) || (int) $options['from'] <= 0) {
    cli_writeln('php cli/cdp_timeline.php --from=UNIXTIME [--until=UNIXTIME] [--json] [--expect=cause:n,…]');
    exit((int) $options['from'] > 0 ? 0 : 2);
}
$from = (int) $options['from'];
$until = (int) $options['until'] > 0 ? (int) $options['until'] : time();
$report = cdp_timeline::report($from, $until);

$problems = [];
if ($report['unattributed'] > 0) {
    $problems[] = "{$report['unattributed']} event(s) unattributed: the worker's times or the sitting missing";
}
if ((string) $options['expect'] !== '') {
    $expected = [];
    foreach (explode(',', (string) $options['expect']) as $pair) {
        [$cause, $n] = array_pad(explode(':', trim($pair), 2), 2, '0');
        $expected[$cause] = (int) $n;
    }
    foreach ($report['counts'] as $cause => $n) {
        if ($n !== ($expected[$cause] ?? 0)) {
            $problems[] = "{$cause}: {$n} event(s), expected " . ($expected[$cause] ?? 0);
        }
    }
}
$report['problems'] = $problems;

if (!empty($options['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    cli_writeln(count($report['events']) . ' uncertain submission(s) ' . gmdate('c', $from) . ' – ' . gmdate('c', $until)
        . ': client side ' . $report['sides']['client'] . ', server side ' . $report['sides']['server']
        . ', unattributed ' . $report['unattributed'] . ' ' . json_encode($report['counts']));
    foreach ($report['events'] as $e) {
        cli_writeln(sprintf(
            '  sitting %d try %d %-14s %-22s posted %s waited %s ms; server %s; engine %s; %s',
            $e['attemptid'],
            $e['execution'],
            $e['class'],
            $e['cause'],
            $e['worker']['postedat'] ?? '?',
            $e['worker']['waitedms'] ?? '?',
            $e['server'] === null ? 'no request line'
                : "{$e['server']['startedat']} +{$e['server']['durationms']} ms → {$e['server']['status']}",
            $e['engine'] === null ? '–' : "{$e['engine']['kind']} q{$e['engine']['question']} "
                . round($e['engine']['requestms']) . ' ms',
            $e['worker']['verdict'] ?? ''
        ));
    }
    foreach ($problems as $problem) {
        cli_writeln('PROBLEM: ' . $problem);
    }
}
exit($problems === [] ? 0 : 1);
