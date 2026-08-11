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
$string['def:enum'] = 'Invalid or missing value for {$a}.';
$string['def:integer'] = 'Field "{$a}" must be an integer.';
$string['def:mingtmax'] = 'In {$a}, minitems must not exceed maxitems.';
$string['def:missingblock'] = 'Required block "{$a}" is missing or malformed.';
$string['def:nonemptylist'] = 'Field "{$a}" must be a non-empty list.';
$string['def:notjson'] = 'The experiment definition is not valid JSON.';
$string['def:positiveint'] = 'Field "{$a}" must be a positive integer.';
$string['def:required'] = 'Field "{$a}" is required.';
$string['env:adaptivequizfound'] = 'Host activity mod_adaptivequiz: installed.';
$string['env:adaptivequizmissing'] = 'Host activity mod_adaptivequiz: NOT installed — experiment runs will not be possible on this site.';
$string['env:catquizfound'] = 'CAT engine local_catquiz: installed.';
$string['env:catquizmissing'] = 'CAT engine local_catquiz: NOT installed — experiment runs will not be possible on this site.';
$string['hub:hashmismatch'] = 'Payload hash did not match; the package was rejected.';
$string['hub:ingested'] = 'Run package ingested as run {$a}.';
$string['hub:malformed'] = 'The run package could not be read.';
$string['hub:noresults'] = 'No recalculated results are available for this run yet.';
$string['hub:resultsfound'] = 'Results found for run {$a}.';
$string['hub:verifiednotstored'] = 'Payload integrity verified. Hub storage is not implemented yet (stub).';
$string['instancerole:hub'] = 'Hub (central recalculation)';
$string['instancerole:node'] = 'Node (runs experiments)';
$string['job:acknowledged'] = 'Attempt report accepted.';
$string['job:claimed'] = 'A queued attempt was handed out.';
$string['job:none'] = 'No attempt job is currently available.';
$string['manage:col_cell'] = 'Cell';
$string['manage:col_experiment'] = 'Experiment';
$string['manage:col_name'] = 'Experiment';
$string['manage:col_replication'] = 'Rep.';
$string['manage:col_runs'] = 'Runs';
$string['manage:col_seed'] = 'Seed';
$string['manage:col_status'] = 'Status';
$string['manage:col_tier'] = 'Tier';
$string['manage:createhint'] = 'Experiments and sweeps are currently defined via the CLI (cli/sweep.php) and the experiment API; an in-page editor is planned. Use the CLI to populate the registry, then manage runs here.';
$string['manage:disabled'] = 'Experiment runs are currently disabled (master switch off). You can browse definitions, but no provisioning, scheduling or worker activity takes place.';
$string['manage:environment'] = 'Environment';
$string['manage:experiments'] = 'Experiments';
$string['manage:heading'] = 'CAT experiment suite';
$string['manage:intro'] = 'Prepare, run and evaluate DPF-based computerized adaptive tests against the CAT engine. This is the management landing page of the suite.';
$string['manage:noexperiments'] = 'No experiments defined yet.';
$string['manage:noruns'] = 'No runs defined yet. Expand a sweep with the CLI (cli/sweep.php) to populate the registry.';
$string['manage:pagetitle'] = 'CAT experiment suite';
$string['manage:runs'] = 'Runs';
$string['mutator:unknownvariant'] = 'Unknown pool variant: {$a}.';
$string['job:unknownattempt'] = 'Unknown attempt id.';
$string['naming:unknownplaceholder'] = 'Name pattern references an unknown placeholder: {$a}.';
$string['navbarbutton'] = 'CATQUIZ-Lab';
$string['oracle:computed'] = 'The oracle computed a model-consistent response.';
$string['oracle:notready'] = 'The response oracle is not implemented yet (stub); no answer was computed.';
$string['pluginname'] = 'CAT experiment suite';
$string['privacy:metadata:local_catquizlab_person'] = 'Simulated persons of the CAT experiment suite: each row links a ground-truth ability profile to the Moodle user provisioned to embody it.';
$string['privacy:metadata:local_catquizlab_person:abilityglobal'] = 'The simulated person\'s ground-truth global ability.';
$string['privacy:metadata:local_catquizlab_person:moodleuserid'] = 'The id of the Moodle user provisioned for the simulated person.';
$string['privacy:metadata:local_catquizlab_person:profilejson'] = 'The hierarchical ground-truth ability profile (per category and subscale).';
$string['privacy:metadata:local_catquizlab_person:runid'] = 'The experiment run the simulated person belongs to.';
$string['privacy:metadata:local_catquizlab_person:stratum'] = 'The person stratum the profile was generated for.';
$string['setting:enabled'] = 'Enable experiment runs';
$string['setting:enabled_desc'] = 'Master switch. While disabled, no provisioning, scheduling or worker triggering takes place. Keep disabled on any site that is not a dedicated test system.';
$string['setting:environment'] = 'Environment status';
$string['setting:hub'] = 'Hub connection';
$string['setting:hub_desc'] = 'Submit finished run packages to a central hub for cross-instance aggregation.';
$string['setting:hub_token'] = 'Hub token';
$string['setting:hub_token_desc'] = 'The web-service token for the hub.';
$string['setting:hub_url'] = 'Hub URL';
$string['setting:hub_url_desc'] = 'The wwwroot of the hub instance.';
$string['setting:instancerole'] = 'Instance role';
$string['setting:instancerole_desc'] = 'Node: this instance provisions and runs experiments and collects the data. Hub: this instance receives run packages from nodes and recalculates the evaluation centrally with the identical metric library.';
$string['report:cv'] = 'CV';
$string['report:experimentchart'] = 'Metric trend across runs';
$string['report:experimenttitle'] = 'Experiment report';
$string['report:heading'] = 'CAT experiment report';
$string['report:mean'] = 'Mean';
$string['report:metric'] = 'Metric';
$string['report:nodata'] = 'No results have been aggregated for this selection yet.';
$string['report:noselection'] = 'Select a run or an experiment to view its report.';
$string['report:runchart'] = 'Run metrics';
$string['report:runs'] = 'Runs';
$string['report:runtitle'] = 'Run report — {$a}';
$string['report:sd'] = 'SD';
$string['report:value'] = 'Value';
$string['report:viewreport'] = 'View report';
$string['status:draft'] = 'Draft';
$string['status:failed'] = 'Failed';
$string['status:finished'] = 'Finished';
$string['status:running'] = 'Running';
$string['status:scheduled'] = 'Scheduled';
$string['sweep:emptyfactor'] = 'Sweep factor "{$a}" has no levels.';
$string['sweep:unknownfactor'] = 'Unknown sweep factor "{$a}".';
$string['setting:worker'] = 'Local worker (exec variant)';
$string['setting:worker_base_url'] = 'Site base URL';
$string['setting:worker_base_url_desc'] = 'The wwwroot the worker calls back to.';
$string['setting:worker_concurrency'] = 'Worker concurrency';
$string['setting:worker_concurrency_desc'] = 'How many attempts may run in parallel (capacity planning).';
$string['setting:worker_desc'] = 'The dispatch task launches the Puppeteer worker on this host to drain the attempt queue.';
$string['setting:worker_exec_enabled'] = 'Enable the exec worker';
$string['setting:worker_exec_enabled_desc'] = 'When on, the dispatch task runs the worker on this host.';
$string['setting:worker_max_jobs'] = 'Maximum jobs per run';
$string['setting:worker_max_jobs_desc'] = 'How many attempts the worker plays per launch (0 = until the queue is empty).';
$string['setting:worker_node_path'] = 'Node.js path';
$string['setting:worker_node_path_desc'] = 'Path to the Node.js executable that runs the worker.';
$string['setting:worker_token'] = 'Web service token';
$string['setting:worker_token_desc'] = 'The token the worker uses for the plugin web services.';
$string['task:aggregateresults'] = 'Aggregate the results of a CAT experiment run';
$string['task:collectattempts'] = 'Collect a run\'s attempt traces from the CAT engine';
$string['task:dispatchworker'] = 'Launch the local Puppeteer worker';
$string['task:exportrun'] = 'Export a run answer matrix';
$string['task:scheduleattempts'] = 'Schedule the attempts of a CAT experiment run';
