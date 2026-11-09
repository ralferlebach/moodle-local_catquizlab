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
 * Stop an experiment completely and now, from the console (0.7.49).
 *
 *   cd /tmp && sudo -u www-data php /var/www/moodle/local/catquizlab/cli/stop_experiment.php --experimentid=16
 *   cd /tmp && sudo -u www-data php /var/www/moodle/local/catquizlab/cli/stop_experiment.php --experimentid=16 --yes
 *
 * Without --yes it says what it would do and changes nothing. The same stop as "Stop experiment" on the
 * progress page: see \local_catquizlab\local\experiment_stop.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\debug_trace;
use local_catquizlab\local\experiment_stop;

[$options] = cli_get_params(['experimentid' => 0, 'yes' => false, 'help' => false], ['h' => 'help']);
$component = 'local_catquizlab';
$experimentid = (int) $options['experimentid'];
if ($options['help'] || $experimentid <= 0) {
    cli_writeln(get_string('stop:cliusage', $component));
    exit($options['help'] ? 0 : 2);
}

global $DB;
if (!$DB->record_exists('local_catquizlab_experiment', ['id' => $experimentid])) {
    cli_error(get_string('stop:clinoexperiment', $component, $experimentid));
}

$preview = experiment_stop::preview($experimentid);
if (!$preview['any']) {
    cli_writeln(get_string('stop:nothing', $component));
    exit(0);
}

if (!$options['yes']) {
    cli_writeln(strip_tags(get_string('stop:confirm', $component, (object) $preview)));
    if ($preview['aggregating'] > 0) {
        cli_writeln(get_string('stop:keepsaggregating', $component, $preview['aggregating']));
    }
    cli_writeln(get_string('stop:clidryrun', $component));
    exit(0);
}

$stopped = experiment_stop::stop($experimentid);
debug_trace::record(debug_trace::LIFECYCLE, 'stopexperiment', ['experimentid' => $experimentid] + $stopped);
cli_writeln(get_string('stop:done', $component, (object) $stopped));
