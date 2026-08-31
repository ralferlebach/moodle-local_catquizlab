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
 * Plugin version definition for local_catquizlab.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'local_catquizlab';
$plugin->version      = 2026083101;
$plugin->requires     = 2024100700;   // Moodle 4.5.0 — hard minimum.
$plugin->supported    = [405, 502];   // Tested on Moodle 4.5, 5.0 and 5.2; raise as new majors are added to CI.
$plugin->maturity     = MATURITY_ALPHA;
$plugin->release      = '0.2.1';

// Deliberately NO hard dependencies: the suite drives local_catquiz and
// mod_adaptivequiz as a black box, but the stub must install stand-alone
// (e.g. in CI, where neither is present). Their availability is detected at
// runtime (see classes/local/environment.php) and surfaced on the settings
// page. This mirrors the optional-integration pattern used in earlier
// reference plugins. Revisit before beta: once the attempt runner exists,
// promote local_catquiz and mod_adaptivequiz to declared dependencies.
$plugin->dependencies = [];
