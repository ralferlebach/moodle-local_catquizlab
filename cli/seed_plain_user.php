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
 * A signed-in user without any CatQuizLab capability, for the refusal tests (#121).
 *
 * A download that refuses only the anonymous visitor has not been tested
 * against the case that matters: a colleague with an account and no rights.
 * Prints PLAIN_USER=<username> and PLAIN_PASSWORD=<password>; the password is
 * random for every call, and the user is created on a test site only.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');

global $DB;

$username = 'catlabplain';
$password = 'Plain#' . random_string(10);

$user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
if (!$user) {
    $user = (object) [
        'username' => $username, 'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
        'firstname' => 'Plain', 'lastname' => 'User', 'email' => 'plain@example.com',
    ];
    $user->id = user_create_user($user, false, false);
}
update_internal_user_password($DB->get_record('user', ['id' => $user->id]), $password);

cli_writeln('PLAIN_USER=' . $username);
cli_writeln('PLAIN_PASSWORD=' . $password);
