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
 * English language strings for local_catquizlab.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['catquizlab:hubtransfer'] = 'Transfer run packages between a node and the central hub';
$string['catquizlab:manage'] = 'Manage CAT experiment suite (provision, run and delete experiments)';
$string['catquizlab:view'] = 'View CAT experiment results';
$string['catquizlab:worker'] = 'Answer oracle requests and claim attempt jobs (Puppeteer worker)';
$string['env:adaptivequizfound'] = 'Host activity mod_adaptivequiz: installed.';
$string['env:adaptivequizmissing'] = 'Host activity mod_adaptivequiz: NOT installed — experiment runs will not be possible on this site.';
$string['env:catquizfound'] = 'CAT engine local_catquiz: installed.';
$string['env:catquizmissing'] = 'CAT engine local_catquiz: NOT installed — experiment runs will not be possible on this site.';
$string['hub:hashmismatch'] = 'Payload hash did not match; the package was rejected.';
$string['hub:noresults'] = 'No recalculated results are available for this run yet.';
$string['hub:verifiednotstored'] = 'Payload integrity verified. Hub storage is not implemented yet (stub).';
$string['instancerole:hub'] = 'Hub (central recalculation)';
$string['instancerole:node'] = 'Node (runs experiments)';
$string['job:acknowledged'] = 'Attempt report accepted.';
$string['job:none'] = 'No attempt job is currently available.';
$string['oracle:notready'] = 'The response oracle is not implemented yet (stub); no answer was computed.';
$string['pluginname'] = 'CAT experiment suite';
$string['privacy:metadata'] = 'The CAT experiment suite stub stores experiment definitions and empty lab-store scaffolding only, and no personal data. This will change when provisioning writes simulated-user cohorts and attempt traces; the privacy provider will then be extended accordingly.';
$string['setting:enabled'] = 'Enable experiment runs';
$string['setting:enabled_desc'] = 'Master switch. While disabled, no provisioning, scheduling or worker triggering takes place. Keep disabled on any site that is not a dedicated test system.';
$string['setting:environment'] = 'Environment status';
$string['setting:instancerole'] = 'Instance role';
$string['setting:instancerole_desc'] = 'Node: this instance provisions and runs experiments and collects the data. Hub: this instance receives run packages from nodes and recalculates the evaluation centrally with the identical metric library.';
