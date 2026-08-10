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
 * Web service definitions for local_catquizlab.
 *
 * Two pre-built services group the functions by counterpart: the worker
 * service (oracle + job queue, used by the Puppeteer worker's token) and the
 * hub service (run-package transfer, used by node/hub tokens). Both ship
 * disabled; an administrator enables the relevant one on a test system and
 * mints a restricted-user token for it.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_catquizlab_oracle_answer' => [
        'classname'   => 'local_catquizlab\\external\\oracle_answer',
        'methodname'  => 'execute',
        'description' => 'Return the answer a simulated person gives to a presented item.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'local/catquizlab:worker',
    ],
    'local_catquizlab_job_claim' => [
        'classname'   => 'local_catquizlab\\external\\job_claim',
        'methodname'  => 'execute',
        'description' => 'Claim the next queued attempt job for a worker.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/catquizlab:worker',
    ],
    'local_catquizlab_job_complete' => [
        'classname'   => 'local_catquizlab\\external\\job_complete',
        'methodname'  => 'execute',
        'description' => 'Report an attempt as finished or failed.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/catquizlab:worker',
    ],
    'local_catquizlab_hub_submit_run' => [
        'classname'   => 'local_catquizlab\\external\\hub_submit_run',
        'methodname'  => 'execute',
        'description' => 'Submit a completed run package from a node to the hub.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/catquizlab:hubtransfer',
    ],
    'local_catquizlab_hub_fetch_results' => [
        'classname'   => 'local_catquizlab\\external\\hub_fetch_results',
        'methodname'  => 'execute',
        'description' => 'Fetch recalculated results for a submitted run from the hub.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'local/catquizlab:hubtransfer',
    ],
];

$services = [
    'CAT experiment suite worker' => [
        'shortname'      => 'local_catquizlab_worker',
        'functions'      => [
            'local_catquizlab_oracle_answer',
            'local_catquizlab_job_claim',
            'local_catquizlab_job_complete',
        ],
        'restrictedusers' => 1,
        'enabled'         => 0,
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
    'CAT experiment suite hub' => [
        'shortname'      => 'local_catquizlab_hub',
        'functions'      => [
            'local_catquizlab_hub_submit_run',
            'local_catquizlab_hub_fetch_results',
        ],
        'restrictedusers' => 1,
        'enabled'         => 0,
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
];
