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
 * Whose engine progress the stored sittings carry (#168): an inventory that only reads, and the decisions after it.
 *
 * Until 0.7.74 the collector could store another person's engine progress with a sitting (fixed in 0.7.75). This
 * assesses every stored trace (rules version progress_provenance::RULES): verified, mismatch, suspect,
 * notreconstructable, notassessed — counts, and the ids of all that are not verified or not assessed; separately the
 * sittings last changed before 0.7.75 was installed here. Ids and counts only: no question, answer or estimate is
 * printed. The database is fingerprinted before and after; the inventory fails (exit 3) if it changed.
 *
 *     php local/catquizlab/cli/progress_provenance.php                       inventory, every run (the default)
 *     php local/catquizlab/cli/progress_provenance.php --runs=12,13 --json   inventory of two runs, as JSON
 *
 * Decisions, each a row added to local_catquizlab_provenance (never a row changed, never a trace lost):
 *
 *     --record=ID,...     the verdict recorded, nothing else
 *     --exclude=ID,...    left out of every analysis and export (only mismatch, suspect, notreconstructable)
 *     --include=ID,...    taken back in (a new "recorded" row after an exclusion)
 *     --rederive=ID,...   the progress replaced by the sitting's own, where the engine still has it provably; the
 *                         stored trace kept in the row
 *     --revert=ID,...     the latest re-derivation undone
 *     --note="why"        stored with each decision
 *
 * Exit status: 0 nothing found that is not left out (or the decisions done), 1 sittings found mismatch or suspect and
 * not left out (or a decision refused), 3 the database changed while the inventory read.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\progress_provenance;

[$options] = cli_get_params([
    'runs' => '', 'json' => false, 'record' => '', 'exclude' => '', 'include' => '', 'rederive' => '', 'revert' => '',
    'note' => '', 'help' => false,
], ['h' => 'help']);
if ($options['help']) {
    echo "Engine progress provenance (#168). Inventory (read only): [--runs=1,2] [--json]\n"
        . "Decisions: --record=IDS | --exclude=IDS | --include=IDS | --rederive=IDS | --revert=IDS [--note=TEXT]\n";
    exit(0);
}
global $CFG, $DB;
\core\session\manager::set_user(get_admin());
$ids = static fn(string $list): array => array_values(array_unique(array_filter(array_map(
    'intval',
    explode(',', $list)
), static fn(int $id): bool => $id > 0)));

$decisions = array_filter(['record' => $ids((string) $options['record']), 'exclude' => $ids((string) $options['exclude']),
    'include' => $ids((string) $options['include']), 'rederive' => $ids((string) $options['rederive']),
    'revert' => $ids((string) $options['revert'])]);
if ($decisions !== []) {
    $note = (string) $options['note'];
    $failed = 0;
    foreach ($decisions as $action => $attemptids) {
        foreach ($attemptids as $attemptid) {
            if (!$DB->record_exists('local_catquizlab_attempt', ['id' => $attemptid])) {
                cli_writeln("{$action} {$attemptid}: no such sitting");
                $failed++;
                continue;
            }
            $verdict = progress_provenance::assess($attemptid)['verdict'];
            switch ($action) {
                case 'record':
                    $row = progress_provenance::record($attemptid, progress_provenance::RECORDED, $note);
                    cli_writeln("record {$attemptid}: {$verdict}, row {$row}");
                    break;
                case 'exclude':
                    if (
                        !in_array($verdict, [progress_provenance::MISMATCH, progress_provenance::SUSPECT,
                            progress_provenance::UNRECONSTRUCTABLE], true)
                    ) {
                        cli_writeln("exclude {$attemptid}: refused, its progress is {$verdict}");
                        $failed++;
                        break;
                    }
                    $row = progress_provenance::record($attemptid, progress_provenance::EXCLUDED, $note);
                    cli_writeln("exclude {$attemptid}: {$verdict}, left out of the analyses, row {$row}");
                    break;
                case 'include':
                    $row = progress_provenance::record($attemptid, progress_provenance::RECORDED, $note ?: 'taken back in');
                    cli_writeln("include {$attemptid}: {$verdict}, in the analyses again, row {$row}");
                    break;
                case 'rederive':
                    $done = progress_provenance::rederive($attemptid, $note);
                    cli_writeln("rederive {$attemptid}: " . ($done['done'] ? 'done, row ' . $done['row'] . ', now '
                        . progress_provenance::assess($attemptid)['verdict'] : 'not done — ' . $done['reason']));
                    $failed += $done['done'] ? 0 : 1;
                    break;
                case 'revert':
                    $done = progress_provenance::revert($attemptid);
                    cli_writeln("revert {$attemptid}: " . ($done ? 'the kept trace back in place, now '
                        . progress_provenance::assess($attemptid)['verdict'] : 'no re-derivation to undo'));
                    $failed += $done ? 0 : 1;
                    break;
            }
        }
    }
    exit($failed > 0 ? 1 : 0);
}

// The inventory: read only.
$runids = (string) $options['runs'] === '' ? null : $ids((string) $options['runs']);
// Since when this site runs a collector that reads only the sitting's own progress (0.7.75, 2026112100).
$since = (int) $DB->get_field_sql(
    "SELECT MIN(timemodified) FROM {upgrade_log} WHERE plugin = 'local_catquizlab' AND version >= ?",
    ['2026112100']
);
$started = microtime(true);
$inventory = progress_provenance::inventory($runids, $since);
// Found and not yet decided: not verified, and not left out of the analyses.
$latest = progress_provenance::latest();
$unresolved = array_values(array_filter(
    [...$inventory['ids'][progress_provenance::MISMATCH], ...$inventory['ids'][progress_provenance::SUSPECT]],
    static fn(int $id): bool => ($latest[$id]->decision ?? '') !== progress_provenance::EXCLUDED
));
$report = [
    'rules' => progress_provenance::RULES,
    // Which installation this is (#168 AK4: the development site and CLARA judged apart), and when it was read.
    'site' => ['wwwroot' => $CFG->wwwroot, 'dbtype' => $DB->get_dbfamily(),
        'plugin' => get_config('local_catquizlab', 'version'), 'engine' => get_config('local_catquiz', 'version'),
        'readat' => gmdate('Y-m-d\TH:i:s\Z')],
    'scope' => $runids === null ? 'all runs' : 'runs ' . implode(',', $runids),
    'fixedsince' => $since > 0 ? gmdate('Y-m-d\TH:i:s\Z', $since) : null,
    'total' => $inventory['total'],
    'counts' => $inventory['counts'],
    'beforefix' => $since > 0 ? $inventory['before'] : null,
    'ids' => $inventory['ids'],
    'decided' => $inventory['decided'],
    'excluded' => progress_provenance::excluded_count(array_map(
        'intval',
        $runids ?? array_keys($DB->get_records('local_catquizlab_run', null, '', 'id'))
    )),
    'unresolved' => $unresolved,
    // Whether the found sittings went out (#168 AK4): exports and research packages made after they were collected.
    'exposure' => progress_provenance::exposure([...$inventory['ids'][progress_provenance::MISMATCH],
        ...$inventory['ids'][progress_provenance::SUSPECT], ...$inventory['ids'][progress_provenance::UNRECONSTRUCTABLE]]),
    'fingerprint' => $inventory['fingerprint'],
    'seconds' => round(microtime(true) - $started, 2),
];
if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    cli_writeln("Engine progress provenance, rules v{$report['rules']}, {$report['site']['wwwroot']} "
        . "({$report['site']['dbtype']}, plugin {$report['site']['plugin']}, read {$report['site']['readat']}), "
        . "{$report['scope']}: "
        . "{$report['total']} stored sitting(s) in {$report['seconds']} s");
    foreach ($report['counts'] as $verdict => $n) {
        cli_writeln(sprintf('  %-20s %8d', $verdict, $n) . ($report['beforefix'] !== null
            ? sprintf(
                '   (%d last changed before 0.7.75 was installed, %s)',
                $report['beforefix'][$verdict],
                $report['fixedsince']
            ) : ''));
    }
    foreach ($report['ids'] as $verdict => $list) {
        if ($list !== []) {
            cli_writeln("  {$verdict}: " . implode(', ', array_slice($list, 0, 200)) . (count($list) > 200 ? ' …' : ''));
        }
    }
    cli_writeln('  decisions recorded: ' . json_encode((object) $report['decided']) . '; left out of the analyses now: '
        . $report['excluded'] . '; found and not left out: ' . (count($unresolved) ?: 'none'));
    cli_writeln('  found sittings carried by a later export or research package: ' . $report['exposure']['carried']
        . ' of ' . $report['exposure']['sittings'] . ' ' . json_encode((object) $report['exposure']['experiments'])
        . ' (' . $report['exposure']['unrecorded'] . ')');
    cli_writeln('  database ' . ($report['fingerprint']['unchanged'] ? 'unchanged' : 'CHANGED') . ' (fingerprint '
        . substr($report['fingerprint']['before'], 0, 16) . ' before, ' . substr($report['fingerprint']['after'], 0, 16)
        . ' after)');
}
if (!$report['fingerprint']['unchanged']) {
    exit(3);
}
exit($unresolved !== [] ? 1 : 0);
