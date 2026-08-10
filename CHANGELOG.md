# Changelog — local_catquizlab

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [0.1.1] — 2026-08-10

Round E0 — plugin foundation completed. Turns the single-table stub into the
full lab-store skeleton with web services and the reproducibility manifest.
Still does nothing at runtime beyond installing and exposing (disabled)
services; provisioning, orchestration, oracle logic, metrics and export
remain open (E1–E7).

### Added
- Full lab-store schema in `db/install.xml`: the seven tables `run`, `pool`,
  `person`, `attempt`, `result`, `exportlog`, `transfer` alongside the
  existing `experiment`, with foreign keys and indexes.
- `db/upgrade.php` creating those seven tables from the plugin's own
  install.xml (via `xmldb_file`, no duplicated definitions) with an upgrade
  savepoint, so the already-installed test system migrates cleanly.
- Five web-service external functions under `classes/external/`:
  `oracle_answer`, `job_claim`, `job_complete`, `hub_submit_run`,
  `hub_fetch_results`. Each authenticates, validates and returns a
  well-formed stub response; `hub_submit_run` already performs the real
  SHA-256 integrity check. Defined in `db/services.php` and grouped into two
  pre-built, disabled, restricted-user services (worker, hub).
- Two capabilities for the service users: `local/catquizlab:worker` and
  `local/catquizlab:hubtransfer`.
- Run manifest builder `classes/local/manifest.php` (plugin/engine versions,
  best-effort engine git hash, Moodle/PHP/DB environment, seeds/config) with
  `manifestjson` columns ready to receive it on `run`.
- Tests: `manifest_test.php`, `external_test.php` (stub responses plus
  capability enforcement), extended `stub_test.php` (all eight tables,
  related-record round-trip), and generator methods `create_run`,
  `create_pool`, `create_person`.
- Language strings for the new capabilities and service status messages
  (en/de).

### Changed
- `version.php`: version 2026080900 → **2026081000**, release 0.1.0 →
  **0.1.1**. The bump is required because schema and services changed and the
  plugin is already installed on the test system — without it the new tables
  would not be created on existing installs.
- Privacy `privacy:metadata` string reworded to mention the (still empty)
  lab-store scaffolding; the provider stays a null provider because no rows
  are written yet, with the upgrade-to-full-provider trigger unchanged
  (backlog E2.3).

---

## [0.1.0] — 2026-08-09

Initial stub (milestone M0). The plugin installs cleanly and does nothing
beyond that — by design. Consolidated from a provided plugin stub that mixed
material from two ancestor projects (`local_instantcoursecompletion` and
`mod_vimipad`); everything below was renamed, rewritten or pruned for
`local_catquizlab`.

### Added
- Plugin skeleton `local_catquizlab` (component, version 2026080900,
  release 0.1.0, `MATURITY_ALPHA`, Moodle 4.5+ / supported up to 5.2).
- Settings page under *Local plugins*: master switch `enabled` (default
  off), instance role `node`/`hub` (default node), read-only environment
  status showing whether `local_catquiz` and `mod_adaptivequiz` are
  installed.
- `classes/local/environment.php` — runtime detection of the CAT engine
  (soft-dependency pattern; hard dependencies deferred until the attempt
  runner exists, documented in `version.php`).
- `db/install.xml` with the first lab-store table
  `local_catquizlab_experiment` (definition records: name, tier,
  configjson, status) proving the install path; `db/access.php` with
  `local/catquizlab:manage` (RISK_CONFIG | RISK_DATALOSS, manager
  archetype) and `local/catquizlab:view`.
- Null privacy provider with an explicit note that it must become a full
  provider once cohort/person and trace tables land (backlog E0.2).
- Language packs `en` and `de`.
- Tests: PHPUnit (`stub_test.php`: table exists, generator round-trip,
  environment invariant; `privacy_test.php`), plugin data generator
  (`create_experiment()`), Behat feature for the settings page
  (`@local_catquizlab`).
- Puppeteer worker stub under `worker/` (`run_attempt.js` argument
  validation only, `package.json` with Puppeteer 24, README). The worker
  ships with the plugin (`.gitattributes`) because it is a runtime
  component, not a dev tool.
- CI: `moodle-ci.yml` (dev branches) and `moodle-release.yml` (main) with
  PHPCS, PHPMD (informational), Gherkin lint, PHPDoc, structure validation,
  savepoints, PHPUnit matrix (Moodle 4.5/5.0/5.2 × PHP 8.1–8.3 ×
  MariaDB/PostgreSQL) and Behat matrix, plus a worker syntax-check job and
  a combined gate. `worker-e2e.yml` as manual placeholder for later real
  end-to-end runs.
- Makefile mirroring the CI suite for `local/catquizlab`, with
  `worker-setup` / `worker-check` targets.
- Docs: architecture Rev. 2 (`docs/design/architektur.md`), backlog E0–E7
  with milestones M1–M5 (`docs/design/backlog.md`), test-system setup guide
  (`docs/dev/testsystem-setup.md`), session log (`docs/sessions/`).

### Changed (vs. the provided stub material)
- All identifiers, docblocks, namespaces, language strings, Behat tags and
  CI references renamed from `local_instantcoursecompletion` /
  `mod_vimipad` to `local_catquizlab`.
- CI trimmed to what this plugin actually contains: removed the
  bundle-reproducibility job, all Grunt/AMD build-and-verify steps and the
  Mustache lint (no JS bundle, no AMD modules, no templates yet). Stylelint
  removed with them; Gherkin lint kept.
- `.gitattributes` distribution rules rewritten: `/worker` ships, `/docs`,
  CI plumbing and the makefile do not; the ancestor's `/tools`, `/js` and
  load/playwright exclusions dropped with their subjects.

### Removed (ancestor material not carried over)
- `local_instantcoursecompletion` domain code: observer, completion booker,
  scope resolver, reconcile/book tasks, event class, their tests and the
  scope/processing settings.
- `mod_vimipad` infrastructure that has no subject here: jMeter/k6 load
  harness (`tests/load/`, `load.yml`), Playwright suite
  (`tests/playwright/`, `playwright.yml` — superseded by the Puppeteer
  worker + `worker-e2e.yml`), `tools/` helper scripts, and the
  vimipad-specific design documents and PDFs under `docs/`.
- Ancestor prompt templates and session logs (a fresh session log for this
  plugin starts at `docs/sessions/session-001.md`).
