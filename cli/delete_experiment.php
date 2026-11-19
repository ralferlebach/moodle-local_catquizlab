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
 * Delete one experiment with everything it made, on both sides — what cli/smoke.php does at its end unless --keep.
 *
 *     php cli/delete_experiment.php --id=N
 *
 * For the measurement scripts (cli/load_matrix.sh, cli/trace_matrix.sh): they play with --keep, read the engine's
 * traces and the lab's records of the step while they still exist, and only then delete it (#110, #164 re-audit
 * 19.11.2026: deleting first took the engine traces of the step with it, and the report read almost none).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params(['id' => 0, 'help' => false]);
if (!empty($options['help']) || (int) $options['id'] <= 0) {
    cli_writeln('php cli/delete_experiment.php --id=N');
    exit((int) $options['id'] > 0 ? 0 : 1);
}
\core\session\manager::set_user(get_admin());
$id = (int) $options['id'];
if (!$DB->record_exists('local_catquizlab_experiment', ['id' => $id])) {
    cli_error('No experiment ' . $id . '.');
}
$counts = \local_catquizlab\local\purger::delete_experiment($id, true, true);
cli_writeln('Deleted experiment ' . $id . ': ' . json_encode($counts));
