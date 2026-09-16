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
$plugin->version      = 2026091415;
$plugin->requires     = 2024100700;   // Moodle 4.5.0 — hard minimum.
$plugin->supported    = [405, 502];   // Tested on Moodle 4.5, 5.0 and 5.2; raise as new majors are added to CI.
// Beta since 0.6.0: the whole chain — definition, provisioning, engine
// materialisation, played attempt, trace, evaluation, export — has been
// exercised end to end against a real CAT engine, and a study of 90 attempts
// was run through it. What is missing for stable is field use, not function.
$plugin->maturity     = MATURITY_BETA;
$plugin->release      = '0.6.29';

// The engine is a hard dependency now, as the note here used to promise it
// would become. The suite no longer drives it as an optional black box: it
// creates mod_adaptivequiz instances, writes local_catquiz test environments,
// materialises items into engine scales and plays attempts through the real
// activity. An installation without those plugins cannot do any of it, and the
// honest place to say so is here rather than in a runtime check that reports a
// broken installation after the fact.
//
// The versions are the ALiSe-v-1.2.0-legacy set: the earliest release of each
// plugin whose behaviour this suite has been verified against, and the newest
// line that still supports Moodle 4.5 — the v-3.0 line requires Moodle 5.2.
$plugin->dependencies = [
    'local_catquiz'                => 2026090553,
    'mod_adaptivequiz'             => 2026090604,
    'adaptivequizcatmodel_catquiz' => 2026082704,
];
