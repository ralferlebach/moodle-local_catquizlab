# Changelog — local_catquizlab

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [0.1.23] — 2026-08-10

Attempt collector (E3.5) — engine trace into a lab trace.

### Added
- **Attempt collector** `classes/local/attempt_collector.php` (E3.5): after a
  worker has played an attempt, `collect()` reads the finished attempt from the
  engine tables — the adaptivequiz_attempt's question usage
  (question_attempts / question_attempt_steps) for the played items and their
  response fractions, and local_catquiz_attempts / local_catquiz_personparams for
  the final ability estimate and standard error — and stores a compact
  `attempt.tracejson` (finaltheta, finalse, items, responses, nitems, stopreason),
  marking the attempt collected. The schema follows the Wunderbyte simulation
  scripts. Reading engine tables needs the engine and the host activity, so
  `collect()` returns null when either is absent (CI and stand-alone stay green);
  the trace assembly `build_trace()` is pure and unit-tested. Covered by
  `attempt_collector_test.php`.

- `version.php`: 2026081021 → **2026081022**, release 0.1.22 → **0.1.23**. No new
  upgrade step (the attempt table already carries tracejson/engineattemptid).

---

E2.5 run cleanup, and an outdated management-page hint fixed.

### Added
- **Run cleanup** `classes/local/run_cleanup.php` (E2.5): `cleanup()` clears a
  run's lab-store residue (attempts, results, person rows), deletes the Moodle
  users the run provisioned, and resets the run to draft. Options delete a
  suite-created course (recognised by the `catlab_run_` short name — a referenced
  existing course is left intact) and/or the run row itself. Core-only,
  idempotent. Covered by `run_cleanup_test.php`.

### Fixed
- **Management page hint** wrongly said experiment editing "arrives with the next
  milestone (E1)"; E1 is complete. The `manage:createhint` string now describes
  the current CLI/API workflow (both languages).

- `version.php`: 2026081020 → **2026081021**, release 0.1.21 → **0.1.22**. No new
  upgrade step (code-only round).

---

Completed E4.2 (diagnostics) and E4.4 (async aggregation), plus a status overview.

### Added
- **E4.2 completed** in `classes/local/diagnostics.php`: `deficit_labels_se()`
  (a subscale is a deficit only when it lies more than *k* standard errors below
  the reference — the 1·SE/2·SE definition), `agreement_within_se()` (share of
  subscales recovered within *k* SE), and `precision_recall_at_k()` (precision@k
  and recall@k against a variable relevant set of true deficits). Covered by new
  `diagnostics_test.php` cases.
- **E4.4 completed**: `classes/local/result_aggregator.php` now writes **per-stratum**
  result rows (`scope = stratum:<name>`) alongside the run scope, and a new ad-hoc
  task `classes/task/aggregate_results.php` (with `result_aggregator::queue()`)
  runs the aggregation off the web request so large evaluations cannot time out.
  The `result` table serves as the persistent result cache. Covered by new
  `result_aggregator_test.php` cases.
- **`docs/design/status.md`**: a project status overview (done vs. open per epic,
  milestones, and the CI-safe vs. engine-dependent split).

- `version.php`: 2026081019 → **2026081020**, release 0.1.20 → **0.1.21**. No new
  upgrade step (code-only round; the result table already exists).

---

CI fix (privacy test: int-vs-string id comparison).

### Fixed
- **`privacy_test::test_contexts_and_userlist` failure (persisted).** The real
  cause was the assertion, not the provider: `get_contextids()` / `get_userids()`
  return ids as **strings** (from the DB), while `context_system::instance()->id`
  and the user id are **ints**, and PHPUnit's `assertContains` compares strictly,
  so `assertContains(1, ['1'])` failed. (`test_delete_for_user` passed because it
  uses `record_exists`, not a strict array check — which is why the provider
  looked correct.) The test now compares type-tolerantly (`assertCount` + an
  `(int)`-cast id, and `array_map('intval', …)` for the userlist). The
  `add_from_sql` provider implementation from 0.1.19 is kept — it is the standard
  pattern.

- `version.php`: 2026081018 → **2026081019**, release 0.1.19 → **0.1.20**. No new
  upgrade step (test-only fix round).

---

CI fixes (PHPUnit failure and risky test).

### Fixed
- **`privacy_test::test_contexts_and_userlist` failure.** `get_contexts_for_userid`
  and `get_users_in_context` now use the canonical `add_from_sql` pattern to add
  the system context / users, instead of `add_system_context()` / a fieldset plus
  `add_users`. This resolves the empty context list and is the standard Moodle
  privacy implementation.
- **Risky test** `attempt_scheduler_test::test_task_respects_master_switch`: the
  ad-hoc task's `mtrace()` output is now captured (`ob_start`/`ob_end_clean`) in
  the test, so Moodle's strict "no output during tests" rule no longer flags it.
- **PHPMD advisory**: `xmldb_local_catquizlab_upgrade()` exceeded the cyclomatic
  threshold (11) after the E2.4 step; the run-column additions were extracted to
  a documented helper (`local_catquizlab_upgrade_add_run_course_columns()`), with
  the savepoint left inline so the savepoints check is unaffected. (The remaining
  two boolean-flag advisories are non-blocking and kept for API clarity.)

- `version.php`: 2026081017 → **2026081018**, release 0.1.18 → **0.1.19**. No new
  upgrade step (fix-only round; the highest savepoint is unchanged).

---

Result aggregation — the bridge from traces to stored results (E4/E6).

### Added
- **Result aggregator** `classes/local/result_aggregator.php`: reads a run's
  attempts that carry a trace, pairs each with its person's ground-truth ability,
  computes the metrics summary and writes one `local_catquizlab_result` row per
  scalar metric (n, bias, rmse, mae, correlation, mean/min/max test length, mean
  SE) plus an exposure detail row, at run scope. Recompute is idempotent
  (run-scope rows are replaced). `results()` reads them back as flat, export-ready
  rows for the exporter. It parses the trace JSON the collect step stores, so it
  needs no engine and is fully testable with synthetic traces (expected trace
  shape: `finaltheta`, `finalse`, `items`). Covered by
  `result_aggregator_test.php`. This wires evaluation (E4) to export (E6).

- `version.php`: 2026081016 → **2026081017**, release 0.1.17 → **0.1.18**. No new
  upgrade step (the attempt and result tables already exist).

---

Data export to CSV, JSON and XML (E6, core formats).

### Added
- **Exporter** `classes/local/exporter.php` (E6): serialises the tabular data the
  registry, metrics and diagnostics produce. `to_csv()` writes a header plus
  RFC 4180 quoting (commas, quotes, newlines) with an optional column selection;
  `to_json()` pretty-prints with unescaped slashes/unicode; `to_xml()` builds a
  well-formed document via DOMDocument, escaping values and sanitising element
  names. Booleans, null and nested arrays render consistently across formats.
  A pure, side-effect-free serialiser — no database or filesystem access — so
  gathering rows and writing files stay separate and testable. The spreadsheet
  formats (xlsx/ods) are a later step using Moodle's workbook writer. Covered by
  `exporter_test.php`.

- `version.php`: 2026081015 → **2026081016**, release 0.1.16 → **0.1.17**. No new
  upgrade step (code-only round).

---

Diagnostic / ranking measures for deficit recovery (E4.2).

### Added
- **Diagnostics** `classes/local/diagnostics.php` (E4.2): measures how well the
  algorithm recovers a person's true ability deficits from aligned true/estimated
  per-subscale profiles. `spearman()` gives the rank correlation; `topk_agreement()`
  the overlap of the k most-deficient subscales; `ndcg_at_k()` the graded ranking
  quality of the deficit order; `confusion()` (with `deficit_labels()` at a
  threshold) the detected-vs-true matrix with precision, recall, F1, accuracy and
  specificity; `evaluate()` composes them. Ties get averaged ranks; undefined
  cases return null. Pure and side-effect-free — no engine — and covered by
  `diagnostics_test.php`.

- `version.php`: 2026081014 → **2026081015**, release 0.1.15 → **0.1.16**. No new
  upgrade step (code-only round).

---

Evaluation metrics (E4, computational core).

### Added
- **Metrics** `classes/local/metrics.php` (E4): evaluates a run's collected
  attempts against the ground truth. `ability_recovery()` gives bias, RMSE, MAE
  and the true-vs-estimate correlation; `efficiency()` gives test length (mean/
  min/max) and mean standard error; `exposure()` gives per-item counts and rates,
  the maximum exposure rate and (with a pool size) the number of unused items;
  `summarise()` composes all three. Pure and side-effect-free — it evaluates
  against the ground truth the plugin already holds, so it needs no engine and is
  fully testable with synthetic traces. Empty and degenerate inputs are handled
  safely (correlation is null when undefined). Covered by `metrics_test.php`.

- `version.php`: 2026081013 → **2026081014**, release 0.1.14 → **0.1.15**. No new
  upgrade step (code-only round).

---

Response oracle: the IRT answer model (E3.4, computational core).

### Added
- **Response oracle** `classes/local/response_oracle.php` (E3.4): computes how a
  simulated person answers an item. `probability()` is the logistic IRT model in
  its three-parameter form — `c + (1 - c) / (1 + exp(-a * (theta - b)))` — with
  defaults giving the Rasch/1PL model; `respond()` draws a seed-deterministic
  correct/incorrect answer from it; `ability_for()` resolves the relevant ability
  from a person's hierarchical ground-truth profile (global / category /
  subscale, with fallbacks), which is what lets the DPF conditions probe local
  deviations. Pure and side-effect-free — it computes against the ground truth
  the plugin already stores, no engine needed. Covered by
  `response_oracle_test.php`.

### Changed
- `oracle_answer` external function: note updated — its IRT computation now lives
  in `response_oracle`; the endpoint will call it once a presented question can be
  mapped to its ground-truth item parameters (after pool materialisation). The
  stub's "not ready" behaviour is unchanged until that mapping exists.
- `version.php`: 2026081012 → **2026081013**, release 0.1.13 → **0.1.14**. No new
  upgrade step (code-only round).

---

Attempt scheduling (E3.1).

### Added
- **Attempt scheduler** `classes/local/attempt_scheduler.php` and its ad-hoc task
  `classes/task/schedule_attempts.php` (E3.1): for a run, the scheduler inserts
  one queued attempt row per provisioned person (skipping persons without a
  Moodle user and any already scheduled) and marks the run "scheduled". The timed
  ad-hoc task carries the run id in its custom data, respects the master switch
  (does nothing while runs are disabled), and calls the scheduler when cron runs.
  Pure lab-store/core-task work — no engine, no worker started here; the
  collect/execute steps act on the queued rows. Idempotent. Covered by
  `attempt_scheduler_test.php`.

- `version.php`: 2026081011 → **2026081012**, release 0.1.12 → **0.1.13**. No new
  upgrade step (code-only round; the attempt table already exists).

---

Course provisioning and enrolment (E2.4, core half).

### Added
- **Course provisioner** `classes/local/course_provisioner.php` (E2.4): for a run
  it resolves the course — an existing one when specified, otherwise a new hidden
  course — enrols the run's provisioned users as students, and records the course
  on the run (2.6.C). Core-only (course + enrolment APIs), so it runs on any
  Moodle; creating the adaptivequiz CAT test in that course needs the host
  activity and is the engine-side follow-up (it will fill `run.testcmid`).
  Idempotent. Covered by `course_provisioner_test.php`.

### Changed
- **Schema:** `local_catquizlab_run` gains `courseid` (foreign key to course, the
  course the run's users are enrolled in) and `testcmid` (the adaptivequiz test's
  course-module id, filled later). `db/upgrade.php` adds both with a savepoint.
- `version.php`: 2026081010 → **2026081011**, release 0.1.11 → **0.1.12**. The
  bump and upgrade step are required by the schema change.

---

Management page moved to a Mustache template with collapsible sections.

### Changed
- **`index.php` now renders from a Mustache template** instead of building HTML
  in PHP. The new `templates/manage.mustache` presents the environment,
  experiments and runs as **collapsible sections** using native
  `<details>`/`<summary>` (open by default) — accessible, no JavaScript, and
  free of the Bootstrap 4-vs-5 differences between Moodle 4.5 and 5.x. `index.php`
  now only assembles a template context; markup lives in the template. Table
  cells are Mustache-escaped, and labels come from `{{#str}}`.
- CI: added a `moodle-plugin-ci mustache` lint step, so the template is checked
  on every run. Locally it is covered by `make mustache` (via moodle-plugin-ci
  when present).

- `version.php`: 2026081009 → **2026081010**, release 0.1.10 → **0.1.11**. No new
  upgrade step (code/template-only round).

---

Makefile fixes (screen clear + resilient PHPUnit), user provisioning (E2.3
part 2), and the privacy provider upgrade that goes with it.

### Fixed
- **`makefile`: `make check` no longer clears the screen / PHPUnit aborted on a
  stale env.** Restored `clear` as the first prerequisite of `all/fix/check/
  check-static/ci` (with completion echoes) like the mod_vimipad original, so
  the terminal is cleared first again. The `phpunit` target now mirrors the
  original's resilience: it skips cleanly when `phpunit_dataroot` is not
  configured, and **auto-reinitialises** the test environment
  (`php admin/tool/phpunit/cli/init.php`) when it detects
  "initialised for different version" instead of failing.

### Added
- **User provisioner** `classes/local/user_provisioner.php` (E2.3, part 2): for
  a run's persons without a linked user it creates a real Moodle user via the
  core user API (2.6.B), records `person.moodleuserid`, and optionally gathers
  them into a system cohort. Usernames and names derive from the naming-engine
  label, so users trace back to their ground truth. Core-only (no engine); course
  enrolment / CAT-test binding and login credentials are separate later steps.
  Idempotent. Covered by `user_provisioner_test.php`.

### Changed
- **Privacy provider upgraded from null to a full metadata/request provider.**
  Now that person rows link to real users, the provider declares the
  `local_catquizlab_person` table and its user link, and implements
  get_contexts_for_userid, get_users_in_context, export, and delete (per user,
  per userlist, and for the whole system context) at the system context.
  `privacy_test.php` rewritten accordingly; metadata language strings added.
- `version.php`: 2026081008 → **2026081009**, release 0.1.9 → **0.1.10**. No new
  upgrade step (code-only round).

---

Restored the full local check suite, and the pool mutator (E2.2).

### Changed
- **`makefile`: full functional suite restored.** `make check` had been trimmed
  to PHP lint + worker only; it now mirrors CI (moodle-ci.yml): worker syntax,
  PHPCS, PHPMD, Mustache, Grunt/Gherkin, PHPDoc, structure `validate` and
  upgrade `savepoints`, plus PHPUnit. `make check-static` is the fast static
  subset, `make ci` adds Behat. The moodle-plugin-ci-only checks run through
  `moodle-plugin-ci` when present (exact CI parity) and fall back to a
  direct-tool equivalent or a clear skip note otherwise, so `make check` is
  meaningful with or without it. Compared against the mod_vimipad ancestor:
  every still-applicable check it ran (PHP style, PHPDoc, Mustache, PHPUnit) is
  back, plus the checks CI added (PHPMD, Gherkin lint, validate, savepoints);
  only the React/AMD and jMeter/k6 targets remain removed.

### Added
- **Pool mutator** `classes/local/pool_mutator.php` (E2.2): derives the design's
  pool variants from the ideal blueprint — shifted, stretched, gappy, depleted,
  calibrationerror, taggingerror and combined — as pure, seed-deterministic
  transformations that never touch the question bank. Per 2.6.A a variant is a
  genuinely different item set; the true difficulty stays ground truth
  (set/difficulty variants change items, import-error variants only add
  annotations). Covered by `pool_mutator_test.php`.

- `version.php`: 2026081007 → **2026081008**, release 0.1.8 → **0.1.9**. No new
  upgrade step (code-only round).

---

Pool planner: the item ground-truth blueprint (E2.1, part 1).

### Added
- **Pool planner** `classes/local/pool_planner.php`: lays out the scale tree
  (categories × subcategories × items) and draws item difficulties from the
  design's nested distributions — category mean ~ N(0, 2), subscale mean ~
  N(category mean, 0.75), item difficulty ~ N(subscale mean, 0.5). Item names
  come from the naming engine (2.6.D). Seed-deterministic and side-effect-free;
  the default full ideal pool is 10 × 10 × 25 = 2500 items. The item counterpart
  to the person generator — it fixes the item ground truth as pure data.
  Materialising it into real questions via the engine importer and deriving the
  mutated variants (2.6.A, E2.2) are engine-dependent follow-ups that do not
  touch the question bank here. Distribution parameters are read from the
  definition with design defaults. Covered by `pool_planner_test.php`.

- `version.php`: 2026081006 → **2026081007**, release 0.1.7 → **0.1.8**. No new
  upgrade step (code-only round).

---

Language-file ordering fix and the person ground-truth generator (E2.3, part 1).

### Fixed
- **`make check` (PHPCS) failure**: the `naming:unknownplaceholder` string was
  ordered after `navbarbutton`; the Moodle lang-file ordering sniff (present in
  moodle-cs ≥ 3.7) requires it before. Both `lang/en` and `lang/de` are now
  correctly ordered. (The container used an older moodle-cs that lacked this
  sniff; it has been updated so the check now matches the local `make check`.)

### Added
- **Person ground-truth generator** `classes/local/person_generator.php`
  (E2.3, first part): for a run's persons it draws a global ability and, by
  stratum, category/subscale deviations, producing the hierarchical θ profile
  the oracle will answer against; names come from the naming engine.
  Seed-deterministic and side-effect-free, with a `persist()` that writes the
  profiles to `local_catquizlab_person` (moodleuserid stays null — turning
  profiles into real Moodle users and enrolling them comes later, once courses
  and the CAT test exist). Distribution parameters are read from the definition
  with documented first-cut defaults, keeping the statistical design in the
  definition rather than hard-coded. Covered by `person_generator_test.php`.

- `version.php`: 2026081005 → **2026081006**, release 0.1.6 → **0.1.7**. No new
  upgrade step (code-only round; the person table already exists).

---

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
