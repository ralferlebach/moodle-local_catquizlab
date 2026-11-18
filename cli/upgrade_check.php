<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * An upgrade from an installed older version, checked before and after (#167).
 *
 * --phase=before --out=FILE, with the older version installed (this file copied into its cli folder; it uses only
 * Moodle's database API then): the plugin's version, the row count of every table of the plugin, and for items, pools
 * and sittings a digest of each row as it is.
 *
 * --phase=after --before=FILE, after admin/cli/upgrade.php:
 * 1. the version stored is the one of version.php, and nothing is left to upgrade;
 * 2. the tables are those of db/install.xml, column by column and index by index (a fresh installation has the same);
 * 3. the columns and the index of 2026111500 (item identity, #154) are there;
 * 4. no row was lost or added by the upgrade, and items, pools, runs and sittings from before are unchanged in every
 *    column they had; the item identity of rows from before 2026111500 is empty, never guessed (#154); the one change 2026111501
 *    makes: sittings a stop closed as failed are cancelled now, by their stop text (#166), all others keep their
 *    status;
 * 5. the runs and experiments from before can be read: their labels, figures and progress.
 *
 * Prints what it checked; exit 0 with PASS, 1 with FAIL.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/ddllib.php');

[$options] = cli_get_params(['phase' => '', 'out' => '', 'before' => '']);
$phase = (string) $options['phase'];
if (!in_array($phase, ['before', 'after'], true)) {
    cli_error('--phase=before --out=FILE, or --phase=after --before=FILE');
}

/**
 * The plugin's tables as the database has them.
 *
 * @return string[]
 */
function local_catquizlab_upgrade_tables(): array {
    global $DB;
    $tables = array_values(array_filter(
        array_keys($DB->get_tables(false)),
        static fn(string $t): bool => strpos($t, 'local_catquizlab_') === 0
    ));
    sort($tables);

    return $tables;
}

/**
 * A digest of every row of a table, over the given columns (all when none are given).
 *
 * @param string $table The table.
 * @param string[] $columns The columns, or [] for all of them.
 * @return array{columns: string[], rows: array<int, string>}
 */
function local_catquizlab_upgrade_digest(string $table, array $columns = []): array {
    global $DB;
    if ($columns === []) {
        $columns = array_keys($DB->get_columns($table, false));
        sort($columns);
    }
    $rows = [];
    $recordset = $DB->get_recordset($table, null, 'id ASC', implode(', ', $columns));
    foreach ($recordset as $row) {
        $values = [];
        foreach ($columns as $column) {
            $value = $row->$column;
            // The same value reads alike on both databases: numbers as numbers, text as text, null as null.
            $values[$column] = $value === null ? null : (is_numeric($value) ? (string) (0 + $value) : (string) $value);
        }
        $rows[(int) $row->id] = sha1(json_encode($values));
    }
    $recordset->close();

    return ['columns' => $columns, 'rows' => $rows];
}

$digested = ['local_catquizlab_item', 'local_catquizlab_pool', 'local_catquizlab_attempt', 'local_catquizlab_run'];
// The sittings' status is checked apart: 2026111501 changes it for those a stop closed (#166), and only for those.
$skip = ['local_catquizlab_attempt' => ['status']];

if ($phase === 'before') {
    $out = (string) $options['out'];
    if ($out === '') {
        cli_error('--out=FILE is required');
    }
    $snapshot = ['version' => (int) get_config('local_catquizlab', 'version'), 'counts' => [], 'digests' => []];
    foreach (local_catquizlab_upgrade_tables() as $table) {
        $snapshot['counts'][$table] = $DB->count_records($table);
    }
    foreach ($digested as $table) {
        $columns = array_keys($DB->get_columns($table, false));
        $columns = array_values(array_diff($columns, $skip[$table] ?? []));
        sort($columns);
        $snapshot['digests'][$table] = local_catquizlab_upgrade_digest($table, $columns);
    }
    $snapshot['sittings'] = [];
    foreach ($DB->get_recordset('local_catquizlab_attempt', null, 'id ASC', 'id, runid, status, lasterror') as $row) {
        $snapshot['sittings'][(int) $row->id] = [(int) $row->status, (int) $row->runid,
            (int) $row->status === 40 ? (string) $row->lasterror : ''];
    }
    $snapshot['runstatus'] = array_map('intval', $DB->get_records_menu('local_catquizlab_run', null, '', 'id, status'));
    file_put_contents($out, json_encode($snapshot));
    cli_writeln(sprintf(
        'Before: local_catquizlab %d, %d tables, %d runs, %d items, %d pools, %d sittings.',
        $snapshot['version'],
        count($snapshot['counts']),
        $snapshot['counts']['local_catquizlab_run'] ?? 0,
        $snapshot['counts']['local_catquizlab_item'] ?? 0,
        $snapshot['counts']['local_catquizlab_pool'] ?? 0,
        $snapshot['counts']['local_catquizlab_attempt'] ?? 0
    ));
    exit(0);
}

$before = json_decode((string) @file_get_contents((string) $options['before']), true);
if (!is_array($before)) {
    cli_error('--before=FILE: the file of --phase=before');
}
$fail = [];
$say = static function (bool $ok, string $what) use (&$fail): void {
    cli_writeln(($ok ? 'ok    ' : 'FAIL  ') . $what);
    if (!$ok) {
        $fail[] = $what;
    }
};

// 1. Upgraded, and nothing left to do.
$plugin = new stdClass();
require($CFG->dirroot . '/local/catquizlab/version.php');
$stored = (int) get_config('local_catquizlab', 'version');
$say($stored === (int) $plugin->version, sprintf(
    'version %d → %d, version.php %d',
    $before['version'],
    $stored,
    $plugin->version
));
$say(!moodle_needs_upgrading(), 'nothing left to upgrade');

// 2. The schema of db/install.xml, as a fresh installation has it.
$dbman = $DB->get_manager();
$xmldb = new xmldb_file($CFG->dirroot . '/local/catquizlab/db/install.xml');
$xmldb->loadXMLStructure();
// The plugin's tables only: every other table of the site is not in this file.
$problems = $dbman->check_database_schema($xmldb->getStructure(), ['extratables' => false]);
$say($problems === [], 'tables, columns and indexes as in db/install.xml'
    . ($problems === [] ? '' : ': ' . json_encode($problems, JSON_UNESCAPED_SLASHES)));

// 3. What 2026111500 adds (#154).
$item = new xmldb_table('local_catquizlab_item');
foreach (['itemkey', 'contentfp', 'fingerprint'] as $column) {
    $say($dbman->field_exists($item, $column), 'local_catquizlab_item.' . $column);
}
$say(
    $dbman->index_exists($item, new xmldb_index('runid-itemkey', XMLDB_INDEX_NOTUNIQUE, ['runid', 'itemkey'])),
    'index local_catquizlab_item (runid, itemkey)'
);
$say($dbman->field_exists(new xmldb_table('local_catquizlab_pool'), 'fingerprint'), 'local_catquizlab_pool.fingerprint');

// 4. Nothing lost, nothing added, nothing rewritten.
$counts = [];
foreach (local_catquizlab_upgrade_tables() as $table) {
    $counts[$table] = $DB->count_records($table);
}
$changed = [];
foreach ($before['counts'] as $table => $n) {
    if (($counts[$table] ?? -1) !== (int) $n) {
        $changed[] = $table . ' ' . $n . ' → ' . ($counts[$table] ?? 'gone');
    }
}
$say($changed === [], 'rows per table as before (' . count($before['counts']) . ' tables, '
    . array_sum($before['counts']) . ' rows)' . ($changed === [] ? '' : ': ' . implode(', ', $changed)));
foreach ($before['digests'] as $table => $digest) {
    $now = local_catquizlab_upgrade_digest($table, $digest['columns']);
    $differ = array_keys(array_diff_assoc($digest['rows'], $now['rows']));
    $say($differ === [] && count($now['rows']) === count($digest['rows']), sprintf(
        '%s: %d row(s) from before unchanged in their %d column(s)%s',
        $table,
        count($digest['rows']),
        count($digest['columns']),
        $differ === [] ? '' : '; changed: ' . implode(', ', array_slice($differ, 0, 10))
    ));
}
// The status of every sitting: as before, but cancelled where a stop had closed it as failed (#166).
$statusnow = array_map('intval', $DB->get_records_menu('local_catquizlab_attempt', null, '', 'id, status'));
$reclassified = 0;
$keptfailed = 0;
$wrong = [];
foreach ($before['sittings'] as $id => [$status, $runid, $error]) {
    $stopped = $status === \local_catquizlab\local\attempt_scheduler::STATUS_FAILED && (
        (in_array($error, \local_catquizlab\local\experiment_stop::LEGACY_TEXTS, true)
            && ($before['runstatus'][$runid] ?? null) === \local_catquizlab\local\registry::STATUS_CANCELLED)
        || in_array($error, \local_catquizlab\local\purger::LEGACY_PIPELINE_TEXTS, true));
    $expected = $stopped ? \local_catquizlab\local\attempt_scheduler::STATUS_CANCELLED : $status;
    if (($statusnow[$id] ?? null) !== $expected) {
        $wrong[] = $id . ': ' . $status . ' → ' . ($statusnow[$id] ?? 'gone') . ' (expected ' . $expected . ')';
    }
    $reclassified += $stopped ? 1 : 0;
    $keptfailed += !$stopped && $status === \local_catquizlab\local\attempt_scheduler::STATUS_FAILED ? 1 : 0;
}
$say($wrong === [], sprintf(
    'sittings closed by a stop: %d now cancelled; failed for another reason: %d still failed; every other status as before',
    $reclassified,
    $keptfailed
) . ($wrong === [] ? '' : ': ' . implode(', ', array_slice($wrong, 0, 10))));

// Only where the data is older than item identity (2026111500): data written by a version that has it keeps what it
// had, which the unchanged-columns check above already holds (a Moodle upgrade with the same plugin, #136).
if ((int) $before['version'] < 2026111500) {
    $guessed = $DB->count_records_select(
        'local_catquizlab_item',
        'itemkey IS NOT NULL OR contentfp IS NOT NULL OR fingerprint IS NOT NULL'
    );
    $poolguessed = $DB->count_records_select('local_catquizlab_pool', 'fingerprint IS NOT NULL');
    $say($guessed + $poolguessed === 0, sprintf('identity of items and pools from before left empty (%d item(s), %d '
        . 'pool(s) with a value)', $guessed, $poolguessed));
} else {
    $say(true, 'identity of items and pools: written with it (version ' . (int) $before['version'] . '), kept as it was');
}

// 5. What was there can be read.
$runids = array_map('intval', $DB->get_fieldset_select('local_catquizlab_run', 'id', '1 = 1'));
$labels = \local_catquizlab\local\run_labels::for_runs($runids);
$say(count($labels) === count($runids), sprintf('labels of %d run(s)', count($runids)));
$figures = \local_catquizlab\local\run_figures::read($runids, time());
$say(count($figures['runs']) === count($runids), sprintf('figures of %d run(s)', count($runids)));
$read = 0;
$errors = [];
foreach ($DB->get_fieldset_select('local_catquizlab_experiment', 'id', '1 = 1') as $experimentid) {
    try {
        $snapshot = \local_catquizlab\local\live_progress::snapshot((int) $experimentid);
        $read += isset($snapshot['total']) ? 1 : 0;
    } catch (\Throwable $e) {
        $errors[] = $experimentid . ': ' . $e->getMessage();
    }
}
$experiments = $DB->count_records('local_catquizlab_experiment');
$say($read === $experiments && $errors === [], sprintf('progress of %d experiment(s)', $experiments)
    . ($errors === [] ? '' : ': ' . implode('; ', $errors)));

cli_writeln($fail === [] ? 'PASS: upgraded, schema as installed, data from before kept and readable.'
    : 'FAIL: ' . implode('; ', $fail));
exit($fail === [] ? 0 : 1);
