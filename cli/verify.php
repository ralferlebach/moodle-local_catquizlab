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
 * Check a provisioned run against the real CAT engine.
 *
 * Walks the chain from the scale map to what the engine can actually retrieve
 * and reports the first link that is broken, so a failing installation can be
 * diagnosed without comparing tables by hand.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\run_verifier;

[$options, $unrecognised] = cli_get_params(
    [
        'help'         => false,
        'runid'        => 0,
        'experimentid' => 0,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(', ', $unrecognised)));
}

if ($options['help'] || (!$options['runid'] && !$options['experimentid'])) {
    cli_writeln(<<<'TXT'
Verify a provisioned run against the CAT engine.

Counts, for every run, how far the materialisation chain actually got:
scale mappings, planned items, lab ground-truth rows, Moodle questions, engine
item rows, active parameters, and the items the engine can retrieve through the
same path the CAT manager uses. Reports the first broken link.

Options:
  --runid=N         Verify one run.
  --experimentid=N  Verify every run of an experiment.
  -h, --help        Show this help.

Exit code 0 when every verified run is complete, 1 otherwise.
TXT);
    exit(0);
}

$runids = [];
if ((int) $options['runid'] > 0) {
    $runids = [(int) $options['runid']];
} else {
    $runids = array_map('intval', array_keys($DB->get_records(
        'local_catquizlab_run',
        ['experimentid' => (int) $options['experimentid']],
        'id',
        'id'
    )));
}

if ($runids === []) {
    cli_writeln('No matching runs.');
    exit(0);
}

$failures = 0;
foreach ($runids as $runid) {
    $report = run_verifier::verify($runid);

    cli_writeln('Run ' . $runid);
    foreach ($report['counts'] as $label => $value) {
        cli_writeln(sprintf('  %-20s %6d', $label . ':', $value));
    }
    cli_writeln('  result: ' . ($report['ok'] ? 'OK' : 'FAILED'));

    // The per-item links. Row counts agree while a reference is crossed, so
    // each link gets its own verdict.
    $linkfailures = run_verifier::link_failures($runid);
    if ($linkfailures === []) {
        cli_writeln('  links: all OK');
    } else {
        cli_writeln('  links: FAILED');
        foreach ($linkfailures as $link => $count) {
            cli_writeln('    ' . $link . ': ' . $count . ' item(s)');
        }
    }

    if (!$report['ok'] || $linkfailures !== []) {
        $failures++;
        cli_writeln('  first failure: ' . $report['firstfailure']);
        foreach ($report['details'] as $detail) {
            cli_writeln('    ' . $detail);
        }
    }
    cli_writeln('');
}

if ($failures > 0) {
    cli_writeln($failures . ' run(s) failed verification.');
    exit(1);
}

exit(0);
