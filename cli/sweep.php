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
 * CLI counterpart to the run registry: expand a sweep spec, then persist or
 * just report it, and list existing runs.
 *
 * Usage examples:
 *   php sweep.php --spec=/path/to/sweep.json --name="Main sweep" --tier=main
 *   php sweep.php --spec=/path/to/sweep.json --dry-run
 *   php sweep.php --list
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\sweep;
use local_catquizlab\local\registry;

[$options, $unrecognised] = cli_get_params(
    [
        'help'    => false,
        'spec'    => null,
        'name'    => null,
        'tier'    => 'baseline',
        'dry-run' => false,
        'list'    => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln("Expand a CAT experiment sweep spec and persist or report it.

Options:
  --spec=PATH    Path to a JSON sweep specification.
  --name=NAME    Experiment name used when persisting (default: derived from tier).
  --tier=TIER    baseline|main|robustness|operational (default: baseline).
  --dry-run      Expand and report only; do not write anything.
  --list         List existing runs and exit.
  -h, --help     Show this help.
");
    exit(0);
}

if ($options['list'] !== false) {
    $runs = registry::recent_runs(100);
    if (!$runs) {
        cli_writeln('No runs.');
        exit(0);
    }
    cli_writeln(sprintf('%-28s %-8s %-40s %-4s %-12s %s', 'experiment', 'tier', 'cell', 'rep', 'seed', 'status'));
    foreach ($runs as $run) {
        cli_writeln(sprintf(
            '%-28s %-8s %-40s %-4d %-12d %d',
            \core_text::substr($run->experimentname, 0, 28),
            $run->tier,
            \core_text::substr($run->cellkey, 0, 40),
            $run->replication,
            $run->seed,
            $run->status
        ));
    }
    exit(0);
}

if (empty($options['spec'])) {
    cli_error('Missing --spec=PATH (or use --list / --help).');
}
if (!is_readable($options['spec'])) {
    cli_error('Cannot read spec file: ' . $options['spec']);
}

$spec = json_decode(file_get_contents($options['spec']), true);
if (!is_array($spec)) {
    cli_error('Spec file is not valid JSON.');
}

try {
    $expansion = sweep::expand($spec);
} catch (\invalid_parameter_exception $e) {
    cli_error('Invalid sweep spec: ' . $e->getMessage());
}

$invalidcells = array_filter($expansion['cells'], static function (array $cell): bool {
    return !$cell['valid'];
});

$capacity = $expansion['capacity'];
cli_writeln(sprintf(
    'Cells: %d (excluded %d) | Runs: %d | Attempts: %d | Est. duration: %d s (%.1f h)',
    $capacity['cells'],
    $expansion['excluded'],
    $capacity['runs'],
    $capacity['attempts'],
    $capacity['estimatedseconds'],
    $capacity['estimatedseconds'] / 3600
));

if ($invalidcells) {
    cli_writeln('');
    cli_writeln('Some cells did not validate — nothing was written:');
    foreach ($invalidcells as $cell) {
        cli_writeln('  ' . $cell['cellkey'] . ': ' . implode('; ', $cell['errors']));
    }
    exit(1);
}

if ($options['dry-run']) {
    cli_writeln('');
    cli_writeln('Dry run — nothing written.');
    exit(0);
}

$name = $options['name'] ?? ('Sweep (' . $options['tier'] . ')');
$experimentid = registry::persist_expansion($name, $options['tier'], $expansion, $spec);
cli_writeln('');
cli_writeln('Persisted experiment id ' . $experimentid . ' with ' . $capacity['runs'] . ' runs.');
exit(0);
