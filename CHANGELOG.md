# Changelog — local_catquizlab

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [0.1.6] — 2026-08-10

CI fixes, an icon-only navbar button, and the naming engine (E2 groundwork).

### Fixed
- **PHPUnit failure** (`stub_test::test_generator_creates_related_records`):
  compared an `int` id with `$DB->get_field()`, which returns a `string`, under
  `assertSame`. The integer DB values are now cast to `int` before comparison.
- **PHPDoc check errors** in `sweep.php`: the Moodle PHPDoc checker rejects
  generic `array<K, V>` type syntax ("incomplete parameters list"). All generic
  `array<...>` annotations across the plugin were simplified to plain `array`
  with the structure described in words.
- **PHPMD advisories** cleared as well: unused `global $CFG` removed from the
  navbar callback, and `experiment_definition::validate()` and `sweep::expand()`
  refactored (extracted `validate_pool/persons/budgets` and
  `select_combinations`) to bring cyclomatic/NPath complexity under threshold.
  Behaviour is unchanged and covered by the existing tests.

### Changed
- **Navbar button is now icon-only** — a `fa-cat` glyph instead of the text
  label. The label is kept as the accessible name (title + aria-label), so the
  Reports entry and Behat coverage still work.

### Added
- **Naming engine** `classes/local/naming.php` (requirement 2.6.D): expands name
  patterns with `{key}` and zero-padded `{key:0Nd}` placeholders, plus a
  `sequence()` helper for numbered series (e.g. `P-{stratum}-{index:04d}`).
  Deterministic and side-effect-free; provisioning (E2) will use it to name
  simulated persons and generated items. Covered by `naming_test.php`.

- `version.php`: 2026081004 → **2026081005**, release 0.1.5 → **0.1.6**. No new
  upgrade step (code-only round).

---

Run registry (E1.3): expanded sweeps become persisted runs, shown in the
management page and reachable from the CLI.

### Added
- `classes/local/registry.php`: persists a sweep expansion as one experiment
  plus one run per replication (all at status draft), stores the sweep spec on
  the experiment for reproducibility, and provides read helpers (run count per
  experiment, global status summary, recent runs joined with their experiment).
  No Moodle users/courses/questions are created here — that is provisioning
  (E2). Covered by `registry_test.php`.
- Management page (`index.php`) now shows a **Runs** section: a run-count column
  on the experiments table, a status summary, and a table of recent runs
  (experiment, tier, cell, replication, seed, status). Rendered with a core
  table so the plugin stays installable and CI-green without the engine;
  `local_wunderbyte_table` remains a later enhancement when the engine is
  present.
- `cli/sweep.php`: expands a JSON sweep spec and either reports it (`--dry-run`),
  persists it, or lists existing runs (`--list`); prints the capacity estimate.

### Changed
- `version.php`: 2026081003 → **2026081004**, release 0.1.4 → **0.1.5**. No new
  upgrade step (code-only round; existing schema already covers runs).

---

Sweep expansion (E1.2) and a documentation convention.

### Added
- **E1.2 sweep expansion:** `classes/local/sweep.php` turns a factorial sweep
  spec (base definition + swept factors `variant`/`stratum`/`strategy`) into
  concrete runs: cartesian product, exclusion rules, optional deterministic
  cell cap (coarse fractionation), R replications per cell with a seed derived
  deterministically per (cell, replication), per-cell validation against the
  experiment definition, and a capacity estimate (cells, runs, attempts,
  expected duration). Pure logic, no database writes. Covered by
  `sweep_test.php`; input documented in `docs/design/experiment-format.md`.

### Changed
- **Documentation convention "1 session = 1 chat"** recorded in
  `docs/sessions/README.md`: each chat maps to exactly one session log, appended
  to over the chat rather than split into per-round files. The previous
  per-round logs (session-002…005) were consolidated into a single
  `session-001.md` for this chat.
- `version.php`: 2026081002 → **2026081003**, release 0.1.3 → **0.1.4**. No new
  upgrade step (code-only round).

---

CI fixes and the declarative experiment format (E1.1).

### Fixed
- **Install failure on all PHPUnit and Behat jobs:** four CHAR NOT NULL columns
  (`run.cellkey`, `person.stratum`, `transfer.remotehost`, `transfer.payloadhash`)
  declared `DEFAULT=""`. Moodle rejects empty-string defaults on NOT NULL char
  columns during install (debugging output, which fails the CI install step).
  Removed the empty-string defaults; the columns stay NOT NULL with no default
  and are always set on insert. Existing installs are unaffected (the physical
  columns were already created).
- **PHP Mess Detector:** unused `$params` in `oracle_answer::execute` — now
  unset like the sibling stubs.

### Added
- **E1.1 declarative experiment format:** `classes/local/experiment_definition.php`
  parses a definition (from array or JSON), validates it (structure,
  enumerations, ranges, and the architektur.md 2.6 requirements — pool variant
  via scales, persons with count and naming rule, question template, specifiable
  courses and tests), fills defaults and reports all problems at once. Includes
  a bundled `example_baseline()`. Covered by `experiment_definition_test.php`
  (valid baseline, JSON round-trip, garbage rejection, one test per defect,
  defaults). Format documented in `docs/design/experiment-format.md`.

### Changed
- `version.php`: 2026081001 → **2026081002**, release 0.1.2 → **0.1.3**. No new
  upgrade step: the schema fix changes only install metadata, and E1.1 is
  code-only.

---

Two things: the UI entry point (management page + navigation), and a design
correction to how item-pool variants are realised. The correction changes the
`pool` schema, so a version bump and an upgrade step are required.

### Added
- Management/edit page `index.php`: the plugin's UI landing page, rendered as an
  admin report page. Shows the engine environment status, the master-switch
  state and the list of defined experiments (empty-state notice for now).
- `lib.php` with the `local_catquizlab_render_navbar_output` callback: places a
  **CATQUIZ-Lab** button in the navbar directly next to the engine's CATQUIZ
  button, shown only to users with `local/catquizlab:manage`.
- Registration of the page under **Site administration › Reports** via an
  `admin_externalpage` (id `local_catquizlab_manage`) in `settings.php`, gated
  on `local/catquizlab:manage`.
- Behat coverage (`tests/behat/navigation.feature`) for both entry points.
- Language strings for the page, the navbar button and run status labels (en/de).

### Changed
- **Requirements clarification (architektur.md 2.6, normative):** different item
  parameterisations are realised as physically different questions grouped by
  **item scales, not CAT contexts**; each simulated person is a **distinct
  Moodle user**; **courses and CAT tests are specifiable per run** and persons
  are enrolled into them; **person and item/question names follow specifiable
  rules**, and questions are storable as **templates with blanks**. Threaded
  through backlog E1.1 and E2.1–E2.4.
- **Schema:** `local_catquizlab_pool` drops `contextid` and gains `scaleid`
  (root item scale of the variant) and `questioncategoryid` (question-bank
  category with the variant's questions), realising the correction above.
  `db/upgrade.php` performs the field changes; the generator is updated to
  match.
- `version.php`: 2026081000 → **2026081001**, release 0.1.1 → **0.1.2**.

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
