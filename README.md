# moodle-local_catquizlab

[![Moodle Plugin CI](https://github.com/ralferlebach/moodle-local_catquizlab/actions/workflows/moodle-ci.yml/badge.svg?branch=development)](https://github.com/ralferlebach/moodle-local_catquizlab/actions?query=workflow%3A%22Moodle+Plugin+CI%22)

The **CAT experiment suite** (`local_catquizlab`) is a Moodle local plugin
that manages the complete lifecycle of simulation experiments for the
DPF-based Computerized Adaptive Testing (CAT) engine implemented in
[`local_catquiz`](https://github.com/Wunderbyte-GmbH/moodle-local_catquiz)
together with its host activity `mod_adaptivequiz`.

One plugin covers everything — definition, provisioning, execution, trace
collection, evaluation, reporting and export:

- **Test preparation** happens through internal routines and Moodle APIs
  (user/enrol API, course-module API, question bank API, the engine's CSV
  importer and CAT contexts). No hand-crafted SQL into core tables.
- **Execution** is maximally implementation-near: simulated attempts are
  played by an external **Puppeteer worker** through the real
  `mod_adaptivequiz` attempt UI, triggered by **timed ad-hoc tasks** of this
  plugin. The worker is a dumb executor; every answer decision comes from
  the plugin's server-side **response oracle** (IRT likelihoods against the
  stored ground truth, seed-deterministic).
- **Evaluation** runs inside the plugin (PHP metric library: RMSE,
  correlation, bias, Spearman-ρ, precision@k, recall, nDCG@k, confusion
  matrices, test-length and stability analyses) — either directly on the
  collecting instance, or recalculated on a **central hub instance** running
  the same plugin in hub role. External computation is never required.
- **Export** of every dataset level (raw traces, ground truth, metrics,
  aggregations) is available as **xlsx, ods, csv, json** (Moodle dataformat
  API) and **xml**, so external tooling remains an option.

The full architecture and the epic/milestone backlog live in
[`docs/design/architektur.md`](docs/design/architektur.md) and
[`docs/design/backlog.md`](docs/design/backlog.md). For an end-to-end
walkthrough (define → orchestrate → run → collect → report → export), see
[`docs/dev/durchfuehrung.md`](docs/dev/durchfuehrung.md); how the plugin is
tested is described in [`docs/dev/testen.md`](docs/dev/testen.md).

## Repository

- GitHub: <https://github.com/ralferlebach/moodle-local_catquizlab>
- Author: Ralf Erlebach
- Branch model: `development` is the integration branch (dev CI runs there
  and on all feature branches); `main` carries releases and is guarded by
  the release CI gate.

## Status

**0.2.0** (`MATURITY_ALPHA`). The whole chain — definition, sweep expansion,
provisioning, execution, evaluation and export — is implemented and covered by
tests. Since 0.2.0 the **experiment definition is the sole source for what a
run does**: strategy, item budgets, SE bounds, IRT model with its item
parameters, and the pool variant with its recipe all come from the stored
definition and are recorded in the run manifest. Nothing falls back on silent
defaults.

A **web interface** covers the workflow end to end: define an experiment,
validate it, preview the sweep, expand it into runs, watch and filter them,
compare cells, and exchange settings as JSON. The command line
(`cli/sweep.php`) and the web interface use the same service layer, so an
identical definition expands to identical cells either way.

What remains instance-dependent is the first real run against an installed
engine: the four engine-facing points (materialisation, test creation, attempt,
trace collection) are so far exercised only with the engine absent.

## Requirements

- Moodle **4.5+** (developed and CI-tested against 4.5, 5.0 and 5.2 on
  PHP 8.1–8.3 with MariaDB and PostgreSQL).
- For actual experiment runs (not needed to install the plugin):
  [`local_catquiz`](https://github.com/Wunderbyte-GmbH/moodle-local_catquiz)
  and the Wunderbyte fork of `mod_adaptivequiz` including the
  `adaptivequizcatmodel_catquiz` bridge, plus their own dependency
  `local_wunderbyte_table`.
- For the Puppeteer worker: Node.js 20+ on the worker host.

The engine plugins are **deliberately not declared as hard dependencies**:
the suite detects them at runtime (`classes/local/environment.php`) and shows
the result on the settings page, so it installs stand-alone — notably in CI,
where the engine is absent and every engine-facing path is a guard path.
Without the engine the plugin is installable and testable but cannot
provision or play a run.

## Installation

Install like any other local plugin into

    /local/catquizlab

then visit *Site administration → Notifications* (or run
`php admin/cli/upgrade.php`). See
[`docs/dev/testsystem-setup.md`](docs/dev/testsystem-setup.md) for the
test-system walkthrough including verification steps.

After installation, check *Site administration → Plugins → Local plugins →
CAT experiment suite*: the environment status shows whether the CAT engine
is present, experiment runs are **disabled by default**, and the instance
role defaults to **Node**.

## Where to find it

Once installed (and after a cache purge), the management page is reachable in
two places, for any user with the `local/catquizlab:manage` capability:

- a **CATQUIZ-Lab** button in the navbar, directly next to the engine's
  **CATQUIZ** button; and
- **Site administration → Reports → CAT experiment suite**.

Both open the same page (`local/catquizlab/index.php`). Plugin *settings*
(master switch, instance role, environment status) stay under *Local plugins*
as above.

From there the workflow continues through four further pages:

    index.php        experiment and run overview, "New experiment", JSON import
    experiment.php   the editor: validation, sweep preview, sweep creation, export
    import.php       JSON import with a preview before anything is stored
    runs.php         run overview with filters; run detail with the manifest
    compare.php      cells side by side, with a chart and a CSV export

Four capabilities separate what a user may do: `:view` reads, `:edit` creates
and changes definitions, `:execute` starts and cancels runs, `:export` takes
data off the instance. Every state-changing action is a POST guarded by
`sesskey` and the capability belonging to that specific action.

## Repository layout

    version.php                     component, version, dependencies
    lib.php                         navbar button callback (next to CATQUIZ)
    index.php                       management/edit page (report layout)
    classes/local/environment.php   runtime detection of the CAT engine
    classes/local/manifest.php      run reproducibility manifest builder
    classes/local/experiment_definition.php  declarative experiment format + validator (E1.1)
    classes/local/sweep.php         sweep expansion: spec -> runs (E1.2)
    classes/local/registry.php      persist expansions; read runs for the UI (E1.3)
    classes/local/naming.php        name-pattern expansion (requirement 2.6.D)
    classes/local/person_generator.php  seed-deterministic ground-truth profiles (E2.3)
    classes/local/pool_planner.php  ideal-pool item blueprint (E2.1)
    classes/local/pool_mutator.php  deterministic pool variants (E2.2)
    classes/local/strategy_catalog.php   strategy key -> engine constant -> label
    classes/local/model_catalog.php      model name -> engine catmodel key + parameters
    classes/local/distribution.php       declarative item-parameter distributions
    classes/local/seed_domains.php       one seed per random source (digital twins)
    classes/local/experiment_service.php the layer CLI, web and API share
    classes/local/experiment_io.php      JSON export/import with schema versioning
    classes/local/run_registry.php       run listing, filtering and cell comparison
    classes/form/experiment_form.php     the experiment editor form
    classes/form/import_form.php         the JSON upload form
    classes/local/user_provisioner.php  create Moodle users from profiles (E2.3)
    classes/local/course_provisioner.php  course + enrolment per run (E2.4)
    classes/local/run_cleanup.php        reset/remove a run's residue (E2.5)
    classes/local/attempt_scheduler.php  queue a run's attempts (E3.1)
    classes/task/schedule_attempts.php   ad-hoc task wrapping the scheduler
    classes/local/response_oracle.php    IRT answer model (E3.4)
    classes/local/metrics.php            evaluation metrics vs ground truth (E4)
    classes/local/diagnostics.php        deficit ranking + detection measures (E4.2)
    classes/local/exporter.php           serialise data to CSV/JSON/XML (E6)
    classes/local/result_aggregator.php  traces -> stored metric rows, run+stratum (E4.4)
    classes/local/trend_analysis.php     trend + stability over metric series (E4.3)
    classes/local/report_builder.php     results grouped for the report page (E4.5)
    report.php                           results report: tables + charts (E4.5)
    classes/task/aggregate_results.php   async aggregation task (E4.4)
    classes/task/collect_attempts.php    async trace collection task (E3.5)
    classes/local/attempt_collector.php  engine attempt -> lab trace (E3.5)
    classes/local/test_binder.php        bind run to an adaptivequiz CAT test (E2.4)
    classes/local/test_provisioner.php   create an adaptivequiz CAT test (E2.4)
    classes/local/question_template.php  render templated MC questions (E2.1)
    classes/local/scale_provisioner.php  create engine scale tree + mapping (E2.1)
    classes/local/item_registrar.php     register questions as CAT items (E2.1)
    classes/local/materialiser.php       blueprint -> questions + items (E2.1)
    classes/local/worker_launcher.php    exec the Puppeteer worker (E3.2)
    classes/local/capacity.php           parallelisation + throughput (E3.6)
    classes/local/subscale_evaluator.php per-subscale DPF diagnostics (E4.2)
    classes/local/answer_matrix.php      persons x items response matrix (E6.2)
    classes/local/run_exporter.php       export a run answer matrix to files (E6.3)
    classes/local/export_dataset.php     export level/scope selection (E6.1)
    classes/local/transfer_package.php   hub run packaging + ingest (E5)
    classes/local/run_orchestrator.php   set up a full run end to end (E7)
    classes/local/tier_planner.php       order experiments by tier (E7)
    classes/local/se_diagnostics.php     SE-aware diagnostic measures (E4.2)
    classes/event/                       run lifecycle events (scheduled/aggregated/aborted)
    classes/task/pipeline_tick.php       scheduled reclaim + dispatch (E3.1/E3.2)
    classes/local/item_repository.php    read active item parameters (E2.1/E3.4)
    classes/local/response_oracle.php    IRT answer model incl. GPCM/GRM (E3.4)
    cli/sweep.php                   CLI: expand a sweep spec, persist or list runs
    classes/external/*.php          five web-service functions (oracle, jobs, hub)
    classes/privacy/provider.php    privacy provider for the lab store
    db/install.xml                  lab-store schema (eight tables)
    db/upgrade.php                  upgrade path for existing installs
    db/services.php                 worker and hub web services
    db/access.php                   manage/view/worker/hubtransfer capabilities
    settings.php                    settings page + Reports entry registration
    templates/manage.mustache       management page (collapsible sections)
    worker/                         Puppeteer worker + node --test harness
    tests/                          PHPUnit, generator, Behat
    docs/design/                    architecture (Rev. 2) and backlog E0–E7
    docs/dev/                       setup, operator guide (durchfuehrung.md), testing guide (testen.md)
    docs/sessions/                  session log
    makefile                        local mirror of the CI check suite

## Development

    make check          # PHPCS + worker syntax check
    make fix            # phpcbf auto-fix
    make phpunit        # plugin test suite (initialised phpunit env required)
    make behat          # @local_catquizlab scenarios
    make worker-setup   # npm install for the Puppeteer worker

CI (GitHub Actions) runs PHPCS, PHPDoc, structure validation, savepoints,
PHPUnit and Behat across the Moodle/PHP/DB matrix, plus a syntax check of the
worker.

The PHPUnit and Behat jobs install the CAT engine as well
(`.github/scripts/fetch-engine.sh` fetches `local_catquiz`,
`mod_adaptivequiz` at `v-3.0`, the `adaptivequizcatmodel_catquiz` bridge and
`local_wunderbyte_table`). The plugin still installs without them — the lint
jobs run engine-free on purpose — but the guard paths are not the interesting
ones: the first run against a real engine found five defects that testing
without it could not have shown.

`worker-e2e.yml` holds the worker's own pipeline and keeps two things apart
that answer different questions. Its toolchain job needs no Moodle, no token
and no network beyond the checkout: a syntax check, the unit tests over the
worker's pure helpers, and an offline self test that also starts a browser.
Its end-to-end job is opt-in and provisions PostgreSQL, Moodle, the CAT engine
and its host activity, prepares a run with a queued attempt, issues a worker
token, plays one attempt through the real `mod_adaptivequiz` interface and
verifies the queue afterwards. The preparation lives in
`cli/e2e_prepare.php`, so the end-to-end path uses the same provisioning an
operator does.

## License

GNU GPL v3 or later — see [LICENSE](LICENSE).

2026 Ralf Erlebach
