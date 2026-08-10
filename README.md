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
[`docs/design/backlog.md`](docs/design/backlog.md).

## Repository

- GitHub: <https://github.com/ralferlebach/moodle-local_catquizlab>
- Author: Ralf Erlebach
- Branch model: `development` is the integration branch (dev CI runs there
  and on all feature branches); `main` carries releases and is guarded by
  the release CI gate.

## Status

**0.1.1 — stub** (`MATURITY_ALPHA`). Round **E0 (plugin foundation) is
complete**: plugin structure, settings page (master switch, node/hub
instance role, engine environment status), the four base plus worker/hub
capabilities, the **full lab-store schema** (eight tables) with an upgrade
path, the **five web services** (response oracle, job queue claim/complete,
hub submit/fetch) grouped into two disabled restricted-user services, the
**run-manifest builder**, a test data generator and PHPUnit/Behat coverage
of exactly that scope, the Puppeteer worker stub under `worker/`, and the CI
pipeline. **It still does nothing at runtime beyond installing cleanly and
exposing the (disabled) services** — provisioning, orchestration, oracle
logic, metrics and export follow per the backlog (E1–E7).

## Requirements

- Moodle **4.5+** (developed and CI-tested against 4.5, 5.0 and 5.2 on
  PHP 8.1–8.3 with MariaDB and PostgreSQL).
- For actual experiment runs (not needed for the stub install):
  [`local_catquiz`](https://github.com/Wunderbyte-GmbH/moodle-local_catquiz)
  and the Wunderbyte fork of `mod_adaptivequiz` including the
  `adaptivequizcatmodel_catquiz` bridge, plus their own dependency
  `local_wunderbyte_table`.
- For the worker (later milestones): Node.js 20+ on the worker host.

The engine plugins are **deliberately not declared as hard dependencies**
yet: the stub detects them at runtime (`classes/local/environment.php`) and
shows the result on the settings page, so it installs stand-alone — notably
in CI. This will be revisited once the attempt runner lands (see
`version.php`).

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
    cli/sweep.php                   CLI: expand a sweep spec, persist or list runs
    classes/external/*.php          five web-service functions (oracle, jobs, hub)
    classes/privacy/provider.php    null provider (stub stores no personal data)
    db/install.xml                  lab-store schema (eight tables)
    db/upgrade.php                  upgrade path for existing installs
    db/services.php                 worker and hub web services
    db/access.php                   manage/view/worker/hubtransfer capabilities
    settings.php                    settings page + Reports entry registration
    worker/                         Puppeteer worker (stub) — ships with the plugin
    tests/                          PHPUnit, generator, Behat
    docs/design/                    architecture (Rev. 2) and backlog E0–E7
    docs/dev/                       test-system setup
    docs/sessions/                  session log
    makefile                        local mirror of the CI check suite

## Development

    make check          # PHPCS + worker syntax check
    make fix            # phpcbf auto-fix
    make phpunit        # plugin test suite (initialised phpunit env required)
    make behat          # @local_catquizlab scenarios
    make worker-setup   # npm install for the Puppeteer worker

CI (GitHub Actions) runs PHPCS, PHPDoc, structure validation, savepoints,
PHPUnit and Behat across the Moodle/PHP/DB matrix, plus a syntax check of
the worker. `worker-e2e.yml` is a manual placeholder until the attempt
runner exists.

## License

GNU GPL v3 or later — see [LICENSE](LICENSE).

2026 Ralf Erlebach
