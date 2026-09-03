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
 * Read the engine's per-attempt diagnosis out of the session cache.
 *
 * The engine records why a question selection came back empty — the error
 * status and, since local_catquiz 2026090204, the number of candidates left
 * after each stage of the preselect chain. Both live in a session-scoped cache,
 * so they can only be read by the person whose attempt it was: a CLI script or
 * a web service call under the worker's own token sees an empty cache.
 *
 * The worker therefore fetches this page in the browser session it already has
 * open as the simulated person, immediately after the attempt ends, and hands
 * the result to job_complete. Reconstructing the numbers later from outside the
 * request is not the same thing: by then the pool has moved on.
 *
 * Output is JSON and contains no personal data beyond the ids of the attempt
 * the caller is already playing.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);

require_login();

$cm = get_coursemodule_from_id('adaptivequiz', $cmid, 0, false, MUST_EXIST);
require_login($cm->course, false, $cm);

header('Content-Type: application/json; charset=utf-8');

$out = [
    'cmid'        => $cmid,
    'userid'      => (int) $USER->id,
    'available'   => false,
    'error'       => null,
    'stagecounts' => null,
    'endtime'     => null,
];

if (!\local_catquizlab\local\environment::catquiz_available()) {
    $out['reason'] = 'engine-absent';
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit(0);
}

$cache = cache::make('local_catquiz', 'adaptivequizattempt');

// The get() call returns false for a key the engine never wrote, which is not
// the same as a recorded zero. The distinction matters: "no stage counts"
// and "a stage counted zero" point at completely different things.
$error = $cache->get('catquizerror');
$counts = $cache->get('catquizstagecounts');
$endtime = $cache->get('endtime');

$out['available'] = ($error !== false) || ($counts !== false);
$out['error'] = $error === false ? null : $error;
$out['stagecounts'] = $counts === false ? null : $counts;
$out['endtime'] = $endtime === false ? null : (int) $endtime;

if ($counts === false) {
    // Older engines record the status but not the counts. Saying so is better
    // than an empty object that looks like an answer.
    $out['reason'] = 'stage-counts-not-recorded-by-this-engine';
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit(0);
