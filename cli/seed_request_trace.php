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
 * A day of request traces the size a load test leaves, for the logs page (#132).
 *
 * The logs page ran out of PHP memory on a day file of a few hundred thousand
 * lines. This writes such a file — every line as request_trace::record()
 * writes it, one in every 997 a server error — so the interface test opens the
 * page against the size that broke it. Prints LOGS_ERRORS=<n> (the number of
 * server errors written) for a CI job to put into its environment.
 *
 * Options: --lines=250000, --hours=6 (spread over the last hours).
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquizlab\local\trace_seed;

[$options] = cli_get_params(['lines' => 250000, 'hours' => 6]);
$n = max(1, (int) $options['lines']);
$span = max(1, (int) $options['hours']) * HOURSECS;

$written = trace_seed::write($n, $span, 'seed');
cli_writeln('LOGS_ERRORS=' . $written['errors']);
