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
 * The lifecycle of a run or sitting, complete, as NDJSON — without the requests (#176).
 *
 * The same stream as the logs page's "Download the lifecycle": the executions' history (started, bound, failed, held,
 * requeued, collected, with where each execution started, what it confirmed and the server's last word) and the
 * run's lifecycle records, oldest first, in UTC with milliseconds. --limit ends early and prints where to go on;
 * --after continues there. Reads only.
 *
 *     php local/catquizlab/cli/export_lifecycle.php --runid=103 [--attemptid=46794] [--execution=2]
 *         [--from=2026-11-27T02:35:00Z] [--to=2026-11-27T03:40:00Z] [--limit=5000] [--after=CURSOR] > run103.ndjson
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\log_view;

[$options] = cli_get_params(['runid' => 0, 'experimentid' => 0, 'attemptid' => 0, 'execution' => 0, 'from' => '',
    'to' => '', 'limit' => 0, 'after' => '', 'help' => false], ['h' => 'help']);
if ($options['help'] || ((int) $options['runid'] <= 0 && (int) $options['experimentid'] <= 0 && (int) $options['attemptid'] <= 0)) {
    echo "The lifecycle of a run, experiment or sitting, complete, as NDJSON (#176).\n"
        . "  --runid=N | --experimentid=N | --attemptid=N   What to export.\n"
        . "  --execution=N     Only this execution of the sitting.\n"
        . "  --from, --to      Window (any strtotime form; UTC recommended).\n"
        . "  --limit=N         At most N lines; the last line says where to go on.\n"
        . "  --after=CURSOR    Go on after this cursor.\n";
    exit(0);
}
$filter = ['runid' => (int) $options['runid'], 'experimentid' => (int) $options['experimentid'],
    'attemptid' => (int) $options['attemptid'], 'execution' => (int) $options['execution'],
    'since' => $options['from'] !== '' ? (int) strtotime((string) $options['from']) : 0,
    'until' => $options['to'] !== '' ? (int) strtotime((string) $options['to']) : 0, 'after' => (string) $options['after']];
echo json_encode(
    ['meta' => ['generated' => gmdate('Y-m-d\TH:i:s\Z'), 'timezone' => 'UTC',
    'filter' => array_filter($filter), 'requested' => [
        'from' => $filter['since'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $filter['since']) : null,
        'to' => gmdate('Y-m-d\TH:i:s\Z', $filter['until'] > 0 ? $filter['until'] : time())]]],
    JSON_UNESCAPED_SLASHES
) . "\n";
$end = log_view::stream_lifecycle($filter, static function (array $line): void {
    echo json_encode(['time' => log_view::utc_ms((int) $line['ms']), 'cursor' => log_view::cursor_of($line)]
        + array_diff_key($line, ['stamp' => 1, 'hasms' => 1]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}, (int) $options['limit']);
echo json_encode(['end' => $end + ['complete' => $end['nextcursor'] === null]], JSON_UNESCAPED_SLASHES) . "\n";
