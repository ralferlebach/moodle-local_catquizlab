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
 * Prepare and verify a worker end-to-end run.
 *
 * The end-to-end job needs a real experiment, a provisioned run, a queued
 * attempt and a valid web-service token. Assembling that in workflow YAML would
 * mean a second provisioning path that could drift away from the one operators
 * use, so it lives here and goes through the ordinary services.
 *
 * @package    local_catquizlab
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

use local_catquizlab\local\attempt_scheduler;
use local_catquizlab\local\environment;
use local_catquizlab\local\experiment_definition;
use local_catquizlab\local\experiment_service;
use local_catquizlab\local\registry;
use local_catquizlab\local\run_orchestrator;

[$options, $unrecognised] = cli_get_params(
    [
        'help'    => false,
        'persons' => 1,
        'name'    => 'Worker E2E',
        'verify'  => false,
        'runid'   => 0,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(', ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln(<<<'TXT'
Prepare or verify a worker end-to-end run.

Preparation (default):
  Creates an experiment, expands it into a single run, provisions that run,
  queues its attempts, enables the worker web service and issues a token for a
  dedicated worker account. Prints key=value lines suitable for $GITHUB_OUTPUT.

Verification:
  --verify --runid=N  Exit 0 when the run's attempts finished, 1 otherwise.

Options:
  --persons=N  Simulated persons, and therefore attempts (default 1).
  --name=TEXT  Experiment name (default "Worker E2E").
  -h, --help   Show this help.
TXT);
    exit(0);
}

if ($options['verify']) {
    exit(local_catquizlab_e2e_verify((int) $options['runid']));
}

exit(local_catquizlab_e2e_prepare(
    (string) $options['name'],
    max(1, (int) $options['persons'])
));

/**
 * Verify that a run's attempts completed.
 *
 * @param int $runid The run to check.
 * @return int Exit code: 0 when every attempt finished, 1 otherwise.
 */
function local_catquizlab_e2e_verify(int $runid): int {
    global $DB;

    if ($runid <= 0) {
        cli_writeln('--verify needs --runid.');
        return 1;
    }

    $total = $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]);
    $finished = $DB->count_records_select(
        'local_catquizlab_attempt',
        'runid = :runid AND status = :status',
        ['runid' => $runid, 'status' => registry::STATUS_FINISHED]
    );
    $failed = $DB->count_records_select(
        'local_catquizlab_attempt',
        'runid = :runid AND status = :status',
        ['runid' => $runid, 'status' => registry::STATUS_FAILED]
    );

    cli_writeln("Run {$runid}: {$finished}/{$total} attempts finished, {$failed} failed.");

    if ($total === 0) {
        cli_writeln('No attempts were queued for this run.');
        return 1;
    }
    if ($failed > 0 || $finished < $total) {
        // A worker that silently played nothing must not pass as success: the
        // whole point of the end-to-end job is that an attempt really ran.
        cli_writeln('The worker did not complete every queued attempt.');
        return 1;
    }

    return 0;
}

/**
 * Prepare an experiment, a run and a worker token.
 *
 * @param string $name The experiment name.
 * @param int $persons The number of simulated persons.
 * @return int Exit code: 0 on success, 1 when the environment is not ready.
 */
function local_catquizlab_e2e_prepare(string $name, int $persons): int {
    global $DB;

    if (!environment::engine_available() || !environment::adaptivequiz_available()) {
        cli_writeln('The CAT engine or mod_adaptivequiz is missing; an end-to-end run is not possible.');
        return 1;
    }

    $definition = experiment_definition::example_baseline();
    $definition['name'] = $name . ' ' . time();
    $definition['persons']['count'] = $persons;
    // A pool small enough to materialise quickly; the job is about the worker
    // path, not about the size of the item bank.
    $definition['pool']['scales'] = [
        'categories'       => 2,
        'subcategories'    => 2,
        'itemspersubscale' => 5,
    ];
    $definition['budgets'] = [
        'global'   => ['minitems' => 2, 'maxitems' => 4],
        'subscale' => ['minitems' => 1, 'maxitems' => 2],
        'se'       => ['min' => 0.35, 'max' => 1.0],
    ];

    $saved = experiment_service::save($definition);
    if (empty($saved['valid'])) {
        cli_writeln('The definition did not validate: ' . implode('; ', $saved['errors']));
        return 1;
    }
    $experimentid = (int) $saved['id'];
    $sweep = experiment_service::create_sweep($experimentid);
    $runid = (int) reset($sweep['runs']);

    $setup = run_orchestrator::setup($runid, [
        'questioncategoryid' => local_catquizlab_e2e_question_category(),
    ]);
    if (empty($setup['ok'])) {
        cli_writeln('Run setup failed: ' . ($setup['reason'] ?? 'unknown'));
        return 1;
    }

    $queued = $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]);
    if ($queued === 0) {
        attempt_scheduler::schedule($runid);
        $queued = $DB->count_records('local_catquizlab_attempt', ['runid' => $runid]);
    }
    if ($queued === 0) {
        cli_writeln('No attempts were queued; the worker would have nothing to claim.');
        return 1;
    }

    $token = local_catquizlab_e2e_token();
    if ($token === null) {
        cli_writeln('No worker token could be issued.');
        return 1;
    }

    // Key=value lines, so a workflow can read them straight into its outputs.
    cli_writeln('experimentid=' . $experimentid);
    cli_writeln('runid=' . $runid);
    cli_writeln('attempts=' . $queued);
    cli_writeln('token=' . $token);

    return 0;
}

/**
 * The question category the generated items go into.
 *
 * @return int A question category id in the system context.
 */
function local_catquizlab_e2e_question_category(): int {
    global $DB;

    $context = context_system::instance();
    $existing = $DB->get_records('question_categories', ['contextid' => $context->id], 'id', 'id', 0, 1);
    if ($existing) {
        return (int) reset($existing)->id;
    }

    return (int) $DB->insert_record('question_categories', (object) [
        'name'       => 'CATLab E2E',
        'contextid'  => $context->id,
        'info'       => '',
        'infoformat' => FORMAT_HTML,
        'parent'     => 0,
        'sortorder'  => 999,
        'stamp'      => make_unique_id_code(),
    ]);
}

/**
 * Enable the worker service and issue a token for a dedicated worker account.
 *
 * @return string|null The token, or null when the service is unavailable.
 */
function local_catquizlab_e2e_token(): ?string {
    global $DB, $CFG;

    set_config('enablewebservices', 1);
    $protocols = explode(',', (string) get_config('core', 'webserviceprotocols'));
    if (!in_array('rest', $protocols, true)) {
        $protocols[] = 'rest';
        set_config('webserviceprotocols', trim(implode(',', array_filter($protocols)), ','));
    }

    $service = $DB->get_record('external_services', ['shortname' => 'local_catquizlab_worker']);
    if (!$service) {
        return null;
    }
    $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);

    $username = 'catquizlab_e2e_worker';
    $user = $DB->get_record('user', ['username' => $username]);
    if (!$user) {
        $user = create_user_record($username, 'Wrk-' . bin2hex(random_bytes(8)) . '!aA1', 'manual');
    }

    // The worker capability is the only privilege this account needs.
    $context = context_system::instance();
    $roleid = create_role('CATLab worker', 'catlabworker' . $user->id, 'End-to-end worker account.');
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
    assign_capability('local/catquizlab:worker', CAP_ALLOW, $roleid, $context->id, true);
    assign_capability('moodle/webservice:createtoken', CAP_ALLOW, $roleid, $context->id, true);
    role_assign($roleid, $user->id, $context->id);
    $DB->insert_record('external_services_users', (object) [
        'externalserviceid' => $service->id,
        'userid'            => $user->id,
        'timecreated'       => time(),
    ]);

    require_once($CFG->dirroot . '/lib/externallib.php');

    return external_generate_token(
        EXTERNAL_TOKEN_PERMANENT,
        $service,
        $user->id,
        $context
    );
}
