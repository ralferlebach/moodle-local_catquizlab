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
 * The host's time limit for a run's engine attempts, and how long each open one has left (P0 "[ENGINE-LIFECYCLE]").
 *
 * The policy as local_catquiz's task cancel_expired_attempts applies it (counted from the attempt's creation, not
 * stopped by a pause), the policy the run's manifest kept, and for every sitting with an open engine attempt its
 * deadline — or that it is not known. Reads only.
 *
 *     php local/catquizlab/cli/time_limits.php --runid=103 [--json]
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\host_time_limit;

[$options] = cli_get_params(['runid' => 0, 'json' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int) $options['runid'] <= 0) {
    echo "The host's time limit for a run's engine attempts, and what each open one has left. Reads only.\n"
        . "  --runid=N   The run.\n"
        . "  --json      As JSON.\n";
    exit(0);
}
$runid = (int) $options['runid'];
$policy = host_time_limit::policy($runid);
$manifest = json_decode((string) $DB->get_field('local_catquizlab_run', 'manifestjson', ['id' => $runid]), true) ?: [];
$out = ['run' => $runid, 'policy' => $policy, 'kept' => $manifest['hosttimelimit'] ?? null, 'sittings' => []];
$sittings = $DB->get_records_select(
    'local_catquizlab_attempt',
    'runid = :runid AND engineattemptid > 0',
    ['runid' => $runid],
    'id ASC',
    'id, status, engineattemptid'
);
foreach ($sittings as $s) {
    $deadline = host_time_limit::deadline((int) $s->engineattemptid, $policy);
    if ($deadline['state'] !== 'inprogress') {
        continue;
    }
    $out['sittings'][] = ['sitting' => (int) $s->id, 'engineattemptid' => (int) $s->engineattemptid] + $deadline;
}
if ($options['json']) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
cli_writeln("Run {$runid}: host time limit " . $policy['source'] . ($policy['seconds'] !== null
    ? ' (' . round($policy['seconds'] / HOURSECS, 2) . ' h, ' . $policy['counted'] . ', ' . $policy['pause'] . ')' : '')
    . ', task ' . $policy['task'] . ' ' . ($policy['schedule'] ?? 'schedule unknown'));
if ($out['kept'] !== null) {
    cli_writeln('Kept in the manifest when ' . ($out['kept']['when'] ?? '?') . ': ' . $out['kept']['source']
        . ($out['kept']['seconds'] !== null ? ' (' . round($out['kept']['seconds'] / HOURSECS, 2) . ' h)' : ''));
}
cli_writeln(count($out['sittings']) . ' sitting(s) with an open engine attempt:');
foreach ($out['sittings'] as $s) {
    cli_writeln(sprintf('  sitting %d  engine %d  %s', $s['sitting'], $s['engineattemptid'], $s['deadline'] !== null
        ? 'closed by the host at ' . gmdate('Y-m-d H:i', $s['deadline']) . 'Z (' . round($s['remaining'] / HOURSECS, 2)
            . ' h left, then the next run of the task)'
        : ($s['known'] ? 'no limit' : 'not known')));
}
