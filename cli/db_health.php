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
 * The database under a load step (#110; classes/local/db_health.php).
 *
 *     php local/catquizlab/cli/db_health.php --snapshot > before.json
 *     php local/catquizlab/cli/db_health.php --before=before.json --from=UNIXTIME --until=UNIXTIME > step.json
 *
 * The second reads the counters again, compares them with the first, and counts the database layer's exceptions in
 * the step's requests. Read only.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\db_health;
use local_catquizlab\local\request_trace;

[$options] = cli_get_params(['snapshot' => false, 'before' => '', 'from' => 0, 'until' => 0, 'help' => false]);
if (!empty($options['snapshot'])) {
    echo json_encode(db_health::snapshot(), JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
if (!empty($options['help']) || (string) $options['before'] === '' || (int) $options['from'] <= 0) {
    cli_writeln('php cli/db_health.php --snapshot | --before=FILE --from=UNIXTIME [--until=UNIXTIME]');
    exit(2);
}
$before = json_decode((string) @file_get_contents((string) $options['before']), true) ?: [];
$until = (int) $options['until'] > 0 ? (int) $options['until'] : time();
$lines = iterator_to_array(request_trace::each((int) $options['from'], $until), false);
echo json_encode(db_health::step($before, db_health::snapshot(), $lines), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
