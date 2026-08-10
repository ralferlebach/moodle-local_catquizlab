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
 * Capability definitions for local_catquizlab.
 *
 * The suite provisions users, courses, activities and item pools and can wipe
 * them again — that is deliberate RISK_CONFIG | RISK_DATALOSS territory, so
 * management is limited to the manager archetype (and admins) by default.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/catquizlab:manage' => [
        'riskbitmask'  => RISK_CONFIG | RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/catquizlab:view' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Granted to the dedicated web-service user whose token the Puppeteer
    // worker uses. It answers item requests from the oracle and claims/reports
    // attempt jobs — no configuration or data-loss risk of its own.
    'local/catquizlab:worker' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // Granted to the web-service user a node authenticates as when submitting
    // run packages to, or fetching recalculated results from, the central hub.
    'local/catquizlab:hubtransfer' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
];
