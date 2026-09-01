# Changelog — local_catquizlab

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [0.2.16] — 2026-09-01

Three defects behind the one-item attempt of 0.2.15, found by reading what the
engine itself recorded.

### Fixed
- **A run could be provisioned that no test could finish.** The engine's own
  record showed `total_number_of_testitems: 6` against
  `minimumquestions: 10`: the test ran out of items, reported an error, and the
  run produced a single item and a stop reason that blamed the strategy. The
  arithmetic is knowable before anything is played, so the pool is now checked
  against the run's own minimum right after materialisation. The failure names
  the numbers: *6 items for a minimum of 10*.
- **The worker shared one browser session across attempts.** The second
  simulated person would have sat the test as the first; the only thing that
  prevented it was the already-authenticated login page no longer offering a
  username field, which made the worker fall over for an unrelated-looking
  reason. Each attempt gets its own browser context now, and a login page
  without a username field is reported rather than worked around.
- **A failed login was invisible.** The worker stayed on the login page, where
  its start-attempt selectors matched the login button itself: it clicked, found
  no question, and reported that the attempt never started. Wrong credentials
  never appeared anywhere. The login is now verified, and the page's own error
  message is passed through.

### Verification
With a pool of 24 items the chain provisions green end to end
(`planned=24 questions=24 items=24 params=24 visible=24 failed=0`) and the
activity now carries a readable name. PHPUnit 398 tests, Behat 27 scenarios, 11
worker unit tests, phpcs and PHPDoc clean.

### Not yet
With the larger pool the worker still reports that no question was presented,
although a browser session with the same credentials reaches the activity page
and sees its start button. That is the next thread: the start click, on a
single-threaded development server, against an activity that already has an
attempt in progress.

---

## [0.2.15] — 2026-09-01

**A simulated person sat a real adaptive test.** The chain runs end to end:
provisioning, queue, claim, browser login, question, oracle answer, submission,
engine attempt, trace collection.

### Fixed
- **The worker never saw the question it had just triggered.** After starting an
  attempt it waited for navigation and swallowed the timeout, then checked for a
  question before the page had rendered one. It waits for the question itself
  now — the thing the next step actually needs.
- **Every oracle call failed because the worker sent the wrong id.** Moodle
  renders `question-{qubaid}-{slot}`; the worker took the first number out of
  that and sent it as a question id, so the oracle looked up an item that could
  not exist and reported itself as not ready. The page identifies a question by
  usage and slot, and the server resolves the question id through the question
  engine.
- **The oracle could not identify the person.** It read `$USER`, but the worker
  drives the browser as the simulated user while calling the web service with
  its own token — so `$USER` was the worker account and never matched a person.
  The lab attempt now names the person; the logged-in user remains the fallback.
- **The engine attempt id was scraped from a page that does not always show
  it.** The server looks it up from the run and the person instead, which is
  information it already has. The worker no longer fails an otherwise good
  attempt over a value it could not read.

### Verification
Against the real engine on a live Moodle: attempt 44 played through the
adaptivequiz interface, engine attempt 3 recorded, one item administered
(question 112), the trace collected and the engine's own person parameters
written. PHPUnit 396 tests, Behat 27 scenarios, 11 worker unit tests, phpcs and
PHPDoc clean.

### Not yet
The test stops after one item rather than exhausting its budget, and the
recorded stop reason is the engine's "An error occured". That is the next
thread to pull, and it is now visible precisely because everything before it
works.

---

## [0.2.14] — 2026-09-01

First worker run against a real installation. Four more defects that only a
worker actually trying to log in could reveal.

### Fixed
- **The worker could never have logged in.** It derived the username as
  `catlab_user_<id>`, while the provisioner makes usernames unique per run and
  produces names like `catlab_r47_p-conforming-0001`. The username now travels
  with the claimed job: the worker no longer guesses a name the server is free
  to choose, and falls back to the old convention only against a server that
  does not send one.
- **Simulated users had no password at all.** `user_create_user()` was called
  without one, so every account was unusable. Nothing noticed because no worker
  had ever tried. The password is derived from the user id, mirroring the
  convention the worker already had.
- **An attempt that answered nothing counted as finished.** The worker reported
  success after a run in which the answer loop never executed once — the same
  failure shape as issue #10, one layer further out: the queue would drain,
  every job would report success, and no trace would ever be collected. The
  worker now refuses to report an attempt with no answered question or no
  engine attempt id, and `job_complete` refuses to record one as collected.
- A type error in the password fix itself: `get_config()` returns `false` for
  an unset setting, so the cast belonged after the fallback rather than around
  a false.

### Verification
Against the real engine and a live Moodle: the worker logs in as the simulated
user, the web-service claim hands out `catlab_r47_p-conforming-0001` with its
job, and a browser session confirms the login lands on the dashboard. The
attempt itself does not yet complete — the activity shows an attempt in
progress and no question is presented — but that is now reported as a failure
instead of being counted as a success, which is what the rest of this release
is about. PHPUnit 396 tests, Behat 27 scenarios, 11 worker unit tests, phpcs
and PHPDoc clean.

---

## [0.2.13] — 2026-09-01

CI fix for the engine step introduced in 0.2.12.

### Fixed
- **The engine step could not find its script.** The PHPUnit and Behat jobs
  check the repository out into `plugin/`, so `.github/scripts/fetch-engine.sh`
  is not at the working directory — exit code 127 before a single test ran.
- **`--extra-plugins ../engine` pointed one level above the workspace.** Both
  the script's target directory and the install option now use an absolute
  path, so neither depends on where a step happens to start.

### Verification
The corrected paths were reproduced locally in a copy of the CI layout: the
script places the four plugins with the cat model inside its host, and
moodle-plugin-ci resolves all three top-level directories to the components
`local_catquiz`, `local_wunderbyte_table` and `mod_adaptivequiz` — checked
against a real moodle-plugin-ci ^4 rather than assumed. Its installer scans the
extra-plugins directory at depth 0, which is why the subplugin has to travel
inside `mod_adaptivequiz` and not beside it.

---

## [0.2.12] — 2026-09-01

The CAT engine in the development environment and in CI.

### Changed
- The **PHPUnit and Behat jobs install the engine**:
  `.github/scripts/fetch-engine.sh` fetches `local_catquiz`,
  `mod_adaptivequiz` at `v-3.0`, the `adaptivequizcatmodel_catquiz` bridge and
  `local_wunderbyte_table`, and moves the cat model into its host activity
  before the install — installed side by side it would land in the wrong
  directory and never be found. The lint jobs stay engine-free, so a broken
  engine checkout can never make the coding standard look red.
- The development environment now runs the same versions rather than the
  raised ones the previous release had to fake: mod_adaptivequiz 2026082705 and
  adaptivequizcatmodel_catquiz 2026082704 from the `v-3.0` branches satisfy the
  engine's dependency declaration as it stands.

### Fixed
- An experiment without swept factors produced an activity called
  `Run #9 –  – Rep 1`, with a gap where the condition should be. A run without
  conditions simply has none.

### Verification
The full chain against the real engine versions, without any local
modification: `planned=6 questions=6 items=6 params=6 visible=6 failed=0`, all
stages green, `verify` 6/6/6/6/6 OK, the activity carrying `catmodel=catquiz`
and the engine reporting all six items through its own retrieval path.
PHPUnit 393 tests, Behat 27 scenarios, phpcs and PHPDoc clean.

---

## [0.2.11] — 2026-09-01

**First run against a real CAT engine.** Five defects that no amount of testing
without the engine could have found.

### Fixed
- **A correct engine was reported as too old.** `strategy_catalog::engine_id()`
  checked for the engine's strategy constants without loading the engine's
  `lib.php`, where they are defined. Moodle loads a local plugin's library only
  when something asks for it, and in a CLI run, a scheduled task or a web
  service nothing has — so provisioning refused to start on a perfectly good
  installation.
- **Materialising from the command line died on a missing class.**
  `question_bank` is autoloaded only where something has already pulled in the
  question library. A web request usually has; CLI, tasks and web services have
  not.
- **A run of six items reported two visible.** The engine caches
  `get_testitems()` in a store that listens for `changesinadaptivequizattempt`,
  not for the item-change event that assignment fires. Writing the parameters
  afterwards left a snapshot taken when the scale held one item fewer, so every
  scale kept the list from its first item. This is exactly the failure issue #10
  describes — and this time it was reported rather than hidden.
- **The adaptive quiz could not be created at all.** `attemptfeedback` and
  `attemptfeedbackformat` are NOT NULL without a default, and `add_moduleinfo()`
  writes the module info straight to the database.
- **Re-provisioning built everything twice.** The course and the section were
  idempotent, the pool and the activity were not: a second run added six more
  questions, six more engine items and a second adaptive quiz, leaving two
  activities for one run. A complete pool is now reused — judged by the same
  engine retrieval a fresh materialisation uses, so a half-surviving pool is
  rebuilt rather than trusted.

### Added
- `testcase_names_test` also rejects the sixteen assertions PHPUnit 10 removed.
  They are warnings on PHPUnit 9 and fatal from 10 on, so a suite green on
  Moodle 4.5 can still be dead on 5.0 — which is how `assertObjectHasAttribute`
  slipped into a test written minutes earlier.

### Verification
The full chain against local_catquiz 2026082152, mod_adaptivequiz and the
catquiz cat model: `planned=6 questions=6 items=6 params=6 visible=6 failed=0`,
container, people, test and attempts stages green, `cli/verify.php` reporting
6/6/6/6/6 OK, and a second provisioning adding nothing. PHPUnit 393 tests
(11 skipped, being the no-engine guard paths), Behat 27 scenarios, phpcs and
PHPDoc clean.

---

## [0.2.10] — 2026-09-01

Closing the session: the last two result filters and the documentation.

### Added
- **Budget and cell as result filters.** The item budget is offered as one
  condition ("global 20-25, subscale 3-5 items") rather than as four numbers
  that only mean something together, and a full factor combination can be
  selected directly. These were the two filters still missing from the
  specified set.

### Changed
- `docs/sessions/session-002.md` carries the whole session: thirty-one phases,
  the verification state, and a table of the ten issues and eleven sub-findings
  with their status.
- `docs/design/status.md` reflects 0.2.9 and names what actually remains — the
  first real run against an installed CAT engine, since every engine-facing
  path has so far only been exercised as a guard path.

### Verification
PHPUnit 390 tests / 2679 assertions, Behat 27 scenarios / 187 steps, phpcs and
PHPDoc clean, 595 language strings per language, all test classes loading under
PHPUnit 11.5.

---

## [0.2.9] — 2026-09-01

Issue #9, findings 8 and 9: the full sweep design in the editor, and a run
lifecycle that says what it means.

### Added
- **Composite sweep factors.** Model, global budget, subscale budget, the SE
  window and the disturbance strength can now be varied from the web interface.
  Budgets and SE windows are swept as pairs rather than as two independent
  ends: "10 to 15 items" is one condition, and varying the ends separately
  would also produce 40/15, which describes nothing.
- **Disturbance strength is its own factor**, so a study can vary the kind of
  disturbance and its size independently. A strength that does not apply to a
  cell's variant — an ideal pool takes no shift — is dropped for that cell
  instead of making a cell the author plainly meant to include invalid.
- **Three lifecycle states**: ready (provisioned but not queued), aggregating
  (attempts done, results being computed) and cancelled. Cancelled is
  deliberately not a kind of failure: one records a decision, the other a
  defect, and a list where both look alike hides the defects among the
  decisions. It is also not shown in the colour that means something is wrong.
- **Status-dependent actions.** A run offers only what its state allows,
  because a button that cannot work reads as a defect in the suite rather than
  as a property of the run. A reproduction records which run it came from and
  links back to it.

### Fixed
- **A budget swept as a factor produced invalid cells.** The normalised base
  definition still carried the schema-1 mirrors of the budgets, which then
  contradicted the level the sweep had just set — so the validator rejected
  cells for a disagreement the sweep itself had created. The mirrors are
  dropped when a budget level is applied and rewritten from the new value.

### Verification
PHPUnit 390 tests / 2679 assertions, Behat 27 scenarios, phpcs and PHPDoc
clean, all test classes loading under PHPUnit 11.5.

---

## [0.2.8] — 2026-09-01

The remaining findings of issue #9: outcomes 4, 7, 10 and 11.

### Fixed
- **The outcome pipeline computed but did not persist.** Stop-rule success,
  exposure concentration and runtime were shown on screen and never written to
  the result store, so nothing downstream could aggregate them across
  replications, export them or compare them between cells. All three are
  result rows now, with the stop reasons kept beside the success rate: a rate
  of 0.67 says nothing about whether the rest ran out of items or were cut
  short by another criterion.
- **A single configured k hid what a strategy achieved.** Finding the single
  worst subscale and finding the worst five are different results. Top-k,
  precision, recall and nDCG are evaluated at k = 1, 3, 5 and 10 at once, and
  a k larger than the number of subscales is left out rather than invented.
- **The local deviations themselves were not reported**, only their ordering.
  A strategy can rank the subscales perfectly and still be a logit out on every
  one of them; local bias and local RMSE are persisted alongside the ranking.
- **The editor declared the control condition on the author's behalf.**
  Choosing a constant discrimination set `allowdegenerate` automatically, which
  defeated the very check it exists for: a run labelled 2PL would quietly be a
  Rasch run and the validator, which would have said so, was answered before it
  could ask. The flag is now a deliberate tick box, and the default
  distribution is log-normal — a model called 2PL should describe a 2PL unless
  someone decides otherwise.
- **Import matched experiments on their display name.** Renaming a study lost
  its history, and two unrelated studies sharing a name collided. The
  experiment key is the identity now, the version distinguishes stages of it,
  and the conflict report says which of the two matched. A new version keeps
  the key and raises the patch level.

### Changed
- Manifest and JSON export carry the experiment key and version.
- README, the test-system guide and the CI header no longer describe a stub
  with a placeholder worker workflow; they describe the worker pipeline that
  exists, including which job needs a Moodle and which does not.

### Verification
PHPUnit 376 tests / 2621 assertions, Behat 26 scenarios, worker check and 11
worker unit tests, phpcs and PHPDoc clean, every test class loading under
PHPUnit 11.5.

---

## [0.2.7] — 2026-09-01

The three findings from issue #9 that invalidate results rather than annoy.

### Fixed
- **Every run executed the base definition, not its own cell.**
  `run_orchestrator::definition_for()` read the experiment's `configjson` back
  instead of the cell definition the sweep had persisted in the run manifest.
  A sweep over four strategy/variant cells therefore ran the same condition
  four times while the cell key and the manifest claimed otherwise — the
  recorded intervention and the executed one were different things. The cell
  definition is now authoritative; only a run predating manifested cells falls
  back, and a configuration that contradicts its manifest fails the run
  outright rather than producing results attributed to conditions that never
  held.
- **Ground truth leaked into the estimated diagnosis.**
  `subscale_evaluator` classified both true and estimated subscale values
  against the *true* global ability, so the diagnostic output being evaluated
  was partly built from the answer it was being scored against. True and
  estimated deviations now use their own reference — ground truth for the
  truth, the engine's own global estimate for the estimate — and both are
  persisted so the separation can be checked rather than trusted.
- **Replication spread was pooled across experimental conditions.**
  `trend_analysis::metric_series()` gathered every run of an experiment
  regardless of cell, so the resulting standard deviation mixed replication
  noise with the differences between conditions and grew precisely when the
  experiment had worked. Aggregation is per cell now, the experiment report
  names its aggregation level, and the old method is deprecated.

### Added
- `experiment_validity_test`: seven tests covering cell execution, manifest
  drift, the legacy fallback and the two reference systems. Each was verified
  by reintroducing the original defect — two tests fail per bug.
- `report_builder_test` gains the case from the issue: two tight cells far
  apart must not be reported as one wide spread.

### Verification
PHPUnit 362 tests / 2580 assertions, Behat 26 scenarios, phpcs and PHPDoc
clean, every test class loading under PHPUnit 11.5.

---

## [0.2.6] — 2026-09-01

Every PHPUnit job on Moodle 5.0 and above died before running a test.

### Fixed
- **A test helper named `result()`.** PHPUnit 10 and 11 declare
  `TestCase::result()` final, so the helper was not a failing test but a fatal
  error while the file was loaded — which takes the whole suite down. Moodle
  4.5 still ships PHPUnit 9, where the method is not final, so it passed
  locally and on the 4.5 matrix and killed 5.0 and 5.2. Renamed to
  `materialisation()`, which also says what it returns.

### Added
- `testcase_names_test` checks every test file against the 86 method names
  PHPUnit 10.5 and 11.5 declare final, taken from their sources rather than
  from memory. It names the offending file and method, so the next collision is
  a one-line failure instead of a fatal with no context.

### Verification
Verified against a real PHPUnit 11.5.56: every test class of the plugin loads
under it, and reintroducing the original helper name reproduces the exact
error from the CI log. PHPUnit 354 tests / 2555 assertions on 4.5, phpcs and
PHPDoc clean.

---

## [0.2.5] — 2026-09-01

Issue #8: one shared experiment course instead of a course per run.

### Fixed
- **A run could report success with no CAT activity at all.** The pipeline ran
  `test` before `people`, but `test_provisioner::create()` needs the run's
  course — which `people` created. So it returned null on every run, the null
  passed as success, and the CLI printed `Run N: ok` for a course containing no
  adaptive quiz. The pipeline is now scales → materialise → container → people
  → test → attempts, and a test stage without an activity fails the run.
- **A sweep of a hundred replications produced a hundred courses.** The suite
  no longer creates courses. A person configures one experiment course; every
  experiment gets one section in it, every run one adaptivequiz in that
  section. Without a configured course nothing is provisioned and the reason
  says so, rather than a course being invented.
- **Activities landed in section 0.** They go into their experiment's section,
  so a shared course stays readable after more than one sweep.
- **Run cleanup could have deleted a shared course.** It now refuses to delete
  the configured experiment course, or any course another run still points at.
- **The course picker broke every admin page.** Building the option list in
  settings.php ran during the admin-tree build, and formatting a course name
  there set up the filter subsystem, which asked for the tree again —
  surfacing as "Duplicate admin page name: adminnotifications" site-wide. The
  choices load lazily now, which is what `load_choices()` is for.

### Added
- `experiment_container` resolves the shared course and the experiment section,
  idempotently: provisioning the same experiment twice reuses its section.
- The landing page shows the configured course, or says that none is set and
  links to the setting.
- Section names carry the experiment's creation time rather than the
  provisioning time, so the same experiment always names its section the same
  way. Activity names lead with the run id, which survives truncation in course
  listings.
- Eleven container tests, including the original bug as an assertion about
  stage order.

### Changed
- `course_provisioner` no longer creates anything; it enrols a run's users into
  the resolved course, idempotently, since many runs share it.
- The former "creates a course per run" test is replaced by one asserting the
  shared-course behaviour, as the issue requires.

### Database
`local_catquizlab_experiment` gains nullable `courseid` and `sectionid`.
Existing runs keep their own course; the upgrade moves nothing. Savepoint
2026083109.

### Verification
PHPUnit 353 tests / 2554 assertions, Behat 26 scenarios, phpcs and PHPDoc
clean, fresh install without debugging output.

---

## [0.2.4] — 2026-08-31

CI fix.

### Fixed
- **Every matrix job failed at the install step.** Three CHAR NOT NULL columns
  declared `DEFAULT=""`. Moodle rejects an empty-string default on a character
  column, rewrites it to NULL and prints a debugging message — and
  moodle-plugin-ci treats any debugging output during installation as a
  failure. So `itemname`, `fingerprint` and `twinid` took the whole matrix down
  over three attributes that had no effect in the first place.
- **`twinid` could not have been added to a populated table.** It was NOT NULL
  without a usable default, which works on a fresh site and fails on every real
  one. It is nullable now, which is also the honest value: a person generated
  before the paired design existed has no twin.
- **Three capabilities had no language strings.** `:edit`, `:execute` and
  `:export` would have shown up in the roles UI as raw identifiers, and
  `moodle-plugin-ci validate` refuses a plugin in that state.
- The CI workflow header still described a plugin with no templates and a
  worker stub; both stopped being true.

### Added
Four schema tests that catch this class of mistake before CI does: no column
declares a default Moodle will reject, no upgrade step adds a NOT NULL column
without a default, every capability is named, and the two language packs
describe the same sorted set of strings. Each was checked by reintroducing the
original defect and confirming the test goes red.

### Verification
PHPUnit 341 tests / 2520 assertions, Behat 24 scenarios, phpcs and PHPDoc
clean, and a fresh PHPUnit install now runs without a single debugging message.

---

## [0.2.3] — 2026-08-31

Reusable building blocks, the rebuilt landing page and editor, and the results
views. The release number stays in the 0.2 line: the plugin has not been run
against a live CAT engine yet, so none of this is field-proven.

### Added
- **Reusable building blocks** (`preset_library`, `presets.php`): an item-pool
  structure or a person model is saved once and cited by any number of
  experiments. Each block carries a fingerprint over its sorted payload,
  recorded in the run manifest, so two experiments can be shown to have used
  the same blueprint rather than two that merely look alike. A block cited by
  an experiment that has runs is locked. Deliberately not part of a block: the
  pool variant and its recipe, which belong to the study rather than to the
  pool it disturbs, and the person count, which is a design decision.
- **Landing page rebuilt to the mockup**: overview panel counting experiments
  and runs by state, primary actions above the fold, experiment table with
  per-row actions, the ten most recent runs with progress bars. It previously
  put everything into collapsed sections, so a first-time visitor saw three
  closed triangles and no way in.
- **Editor rebuilt to the mockup**: numbered section navigation with a one-line
  summary of each section, and a validation panel that stays visible while
  scrolling — a definition can be invalid in eight places at once, and a list
  at the top of a long form leaves the author hunting for the field. Study
  metadata added: description, a stable experiment key, version, tags.
- **Results views** (`results.php`) with eight tabs, all reading through one
  data source so a figure in a chart and the same figure in the table below it
  cannot disagree: Overview, Global metrics, Subscales, Deficit detection,
  Robustness, Test flow, Raw data, Export.
- **`scatter_chart`**: Moodle's chart API has no scatter and the design needs
  several, so this draws static inline SVG with labelled axes, units, reference
  lines and an accompanying summary table, since an SVG alone is unreadable to
  a screen reader.
- **`metrics::concentration`**: exposure inequality as Gini and Herfindahl. A
  mean exposure rate cannot distinguish an evenly used pool from one where a
  tenth of the items carry every test; items never shown count as zero, so an
  unused remainder raises the concentration instead of vanishing.
- **`local_analysis`**: local diagnostics on deviations rather than absolute
  subscale abilities. A test that places every subscale one logit too high has
  recovered the local structure and missed the global level; comparing absolute
  abilities would report the local diagnostics as failing too.
- **`robustness_analysis`**: deltas against the ideal pool under otherwise
  identical conditions, with the disturbance strength as its own coordinate.
- **`test_flow`**: the step-by-step course of one attempt, and a feasibility
  verdict — a precision target implies an information I = 1/SE², and a budget
  that cannot deliver it would have ended on exhaustion however well the items
  were chosen.
- **`results_export`**: four flat levels (run, attempt, subscale, item) taking
  the filter that is on screen, with the filter, level and versions travelling
  in the file's metadata and name.
- **`schema_test`**: compares the installed schema against the columns the code
  actually touches.

### Fixed
- **install.xml and upgrade.php had drifted.** twinid, twinindex and severity
  were added to the upgrade only, so every freshly installed site lost the
  digital-twin identity — the thing the paired design rests on.
- **`parse_debug_info` read only the last step snapshot**, discarding the
  ability trajectory the test-flow view exists to show.
- **`stop_reached()` matched 'error' as a substring**, filing 'standarderror' —
  the precision criterion doing its job — as the test running out of items.
- **`json_encode` dropped zero fractions**, so a discrimination of 1.0 came
  back as int 1 and silently changed type between saving and reuse.
- **`$row + [...]` in index.php** left the status label unused, because the `+`
  operator keeps the left operand.
- Two dropdowns had only an aria-label, and two results tabs showed nothing but
  "no data" without saying what they would have contained.

### Corrected documentation
Earlier notes claimed the engine deletes `local_catquiz_progress` when an
attempt finishes. It does not: `progress::delete()` is never called in the
production path, and the row is removed only when the activity is deleted. See
`docs/design/issue-catquiz-progress-retention.md` for the upstream issue this
raised.

### Database
New tables `local_catquizlab_preset`; `local_catquizlab_person` gains twinid,
twinindex and severity in install.xml as well as in the upgrade. Savepoint
2026083102.

### Verification
PHPUnit 324 tests / 2439 assertions, Behat 24 scenarios / 167 steps with
accessibility checks enabled, phpcs and PHPDoc clean.

---

## [0.2.1] — 2026-08-31

Worker CI: the toolchain job no longer fails by construction.

### Fixed
- **The "Worker toolchain installs" job was red every single time.** It "smoke
  tested" the worker by starting it against `https://example.invalid` with
  dummy credentials. The worker does not read that as a self test: it started
  its normal polling loop, called `local_catquizlab_job_claim` and died on
  `getaddrinfo ENOTFOUND`. The failure was deterministic and said nothing about
  the worker. The job now runs `npm run check`, `npm test` and an offline self
  test, needs no Moodle instance, no token and no external host.

### Added
- **`--self-test`** in `worker/run_attempt.js` (`npm run selftest`): checks
  argument parsing, URL normalisation, the web-service URL builder, the
  dichotomous and polytomous option choice, that Puppeteer loads and that a
  browser starts — which is what the old step was really meant to prove. It
  claims no job and calls no web service. `--no-browser` skips the browser
  start on runners without a Chromium download.
- **A real end-to-end job**, separate from the toolchain job and opt-in via
  `workflow_dispatch`. It provisions PostgreSQL, Moodle, the CAT engine and its
  host activity, prepares an experiment and a queued attempt, issues a worker
  token, plays one attempt through the real UI and verifies the queue
  afterwards.
- **`cli/e2e_prepare.php`**: prepares and verifies such a run through the
  ordinary services, so the end-to-end job cannot drift into a second
  provisioning path. It prints `key=value` lines for `$GITHUB_OUTPUT`, exits 1
  when the engine is absent, and its `--verify` mode fails unless every queued
  attempt actually finished — a worker that played nothing is not a success.
- Three further worker unit tests (self-test export, choice clamping, parameter
  escaping); eleven in total.

### Changed
- `cli/orchestrate.php` loses its `--polytomous` switch. Since 0.2.0 polytomy
  follows from the model in the experiment definition, and a separate setup
  parameter meant a run was not reconstructible from `configjson + seed` alone.
- The worker workflow runs on pushes touching `worker/**` instead of being
  manual-only, since it no longer needs anything unavailable in CI.

### Verification
`npm run check`, `npm test` (11 tests) and `npm run selftest` all pass locally,
including a real browser start (Chrome 148). Each was also checked to fail on a
deliberately broken syntax, a failing assertion and a broken helper, so a red
pipeline still means a real defect. PHPUnit 249 tests, phpcs and PHPDoc clean.

---

## [0.2.0] — 2026-08-31

Session 002: the experiment definition now drives the run, and there is a web
interface for it. Closes issues #1–#7.

### Added
- **Catalogues as single sources of truth**: `strategy_catalog` (internal key →
  engine constant → publication label) and `model_catalog` (1PL/2PL/3PL/PCM/
  GPCM/GRM/GGRM → engine catmodel key, required item parameters, oracle family).
  Engine ids are read from the engine's own constants at runtime; an installed
  but too old engine is refused with a readable message instead of being mapped
  silently. (#1, #3, #5, #6)
- **`distribution`**: declarative, seed-deterministic distributions for the
  discrimination and guessing parameters a 2PL/3PL run needs. (#3)
- **`seed_domains`**: separate random sources for person base, person deviation,
  pool, mutation and response. The person seed no longer depends on the cell
  key, so twins survive a change of strategy or pool variant. (#4)
- **Definition schema 2**: split global and per-subscale budgets, separate
  SE_min and SE_max, model parameters, variant recipes, person severity and
  twin settings, explicit `schema`/`schemaversion`. Schema-1 definitions keep
  validating; their keys are normalised and mirrored. (#1, #2, #3, #4)
- **`local_catquizlab_item`**: per-item ground truth kept apart from what the
  engine was told, which is what makes calibration and tagging errors real
  robustness conditions. (#2)
- **`experiment_service`**: the layer CLI, web UI and API share — validate,
  save, duplicate, preview, expand. UI preview and CLI expansion provably yield
  the same cells. (#7)
- **`experiment_io`**: JSON export in a declarative and a normalised variant,
  import with size limit, schema check, deterministic schema-1 migration and
  explicit conflict resolution. An import never starts a sweep. (#7)
- **Web interface**: experiment editor with field-level validation and sweep
  preview, JSON import page, run overview with filters, run detail with the
  reproducibility manifest, and a cell comparison with mean, SD and a 95%
  interval. (#7)
- **`run_registry`**: resolves a run's experimental coordinates from its
  manifest and aggregates replications into comparable cells. (#7)
- **Capabilities** `:edit`, `:execute` and `:export`, separate from `:manage`.
  Every state change is POST + sesskey + the capability for that action. (#7)
- **Behat**: nine scenarios covering the editor, validation, sweep preview,
  sweep creation, run filtering, the manifest and the import page, plus a data
  generator and a step for sweep expansion. (#7)
- **From the plugin template**: `tests/coverage.php`, `tools/`, `pix/`,
  `db/removed_files.txt` and the session prompt templates.
- **`docs/dev/environment-setup.md`**: the verification environment as actually
  built, including the failure modes met along the way.

### Fixed
- **The definition did not reach the run.** `stage_test()` passed only the test
  name, so two experimentally different cells ran with identical CAT settings
  and an unconfigured run silently became a weakest-subscale run via the
  numeric default 4. Strategy, both budget levels and both SE bounds now come
  from the definition. (#1)
- **Pool variants had no effect.** `pool_mutator::mutate()` was never called at
  runtime: a robustness cell ran on the ideal pool and still reported success.
  It is now wired into materialisation, and a mutation that cannot be realised
  fails the run instead of passing as scheduled. (#2)
- **`gappy` and `depleted` were the same disturbance.** Gappy is now a fixed-N
  redistribution — the item count stays constant and a gap with a pile-up on
  each side appears; depleted remains the variant that removes items. Study
  values corrected to +1.0 logit and ×1.25. (#2)
- **Calibration and tagging errors cancelled themselves out.** True and stored
  difficulty, and true and assigned subscale, are now kept apart end to end.
  The oracle answers against the truth, the engine works from the stored value.
  (#2)
- **2PL and 3PL materialised as 1PL.** `plan_items()` hardcoded
  `discrimination = 1.0` and `guessing = 0.0`, and `item_registrar` fell back to
  `raschbirnbaum` whenever no model was passed. Item parameters now follow the
  declared model. (#3)
- **Stratum 3 removed the variation of stratum 2.** `subscalevariation` was
  `[0.0, 0.5]`, which made the strata alternatives rather than a progression;
  it is now cumulative. `chaotic` became its own generator mode whose subscale
  abilities hang off the global value, so the hierarchy assumption is genuinely
  stressed rather than merely noisier. (#4)
- **GPCM was materialised as `grmgeneralized`.** The model now selects the
  engine key, and the oracle picks its response family through the catalogue
  rather than by looking for the substring "grm". (#5)
- **Polytomous questions had a fixed four options.** With five categories the
  fifth was unreachable and the item silently truncated; the option count now
  follows the model. (#5)
- **The management page was a dead end.** It now offers "New experiment" and
  "Import settings" instead of pointing at the CLI. (#7)
- **`$row + [...]` in `index.php`** left the status label unused, because the
  `+` operator keeps the left operand; the overview showed the numeric status.

### Changed
- `test_provisioner::DEFAULT_STRATEGY` is deprecated and no longer consulted.
- The run manifest records the effective CAT parameters, the target information
  `I = 1/SE²`, the model with its engine key, the variant with its resolved
  recipe, and which factors each derived seed depends on. (#1, #6)
- Exports carry `twinid`, `stratum` and `severity`, and a new item dataset with
  true beside stored parameters. (#2, #4, #6)
- Severity and model are usable as sweep factors. (#4)

### Database
- New table `local_catquizlab_item`; `local_catquizlab_pool` gains `runid`,
  `poolseed`, `mutationseed` and `itemcount` and is now used in the run
  lifecycle; `local_catquizlab_person` gains `twinid`, `twinindex` and
  `severity`; `local_catquizlab_run` gains `masterseed`. Savepoint 2026083100.

### Verification
PHPUnit 249 tests / 1774 assertions, Behat 14 scenarios / 79 steps (with the
accessibility checks enabled), phpcs clean, PHPDoc without findings.

---

## [0.1.50] — 2026-08-11

Session close: documentation finalised and a testing guide.

### Docs
- **Architecture** lifted to Rev. 2.3 (as-built): the operations/hardening layer
  (attempt retry/reclaim/abort, full teardown, worker pool, `pipeline_tick`, events,
  deviance, PF(t) toggle, query measurement, `se_diagnostics`, worker login modes)
  is mapped, and the open points are updated to their resolved state.
- **Testing guide** `docs/dev/testen.md` (new): the pure-vs-engine testing model,
  the static checks (phpcs/phpmd/savepoints), PHPUnit, Behat, the Node worker tests,
  and an instance smoke test.
- **Operator guide** `durchfuehrung.md` extended: unattended operation via
  `pipeline_tick`, worker login modes, complete teardown, the PF(t) toggle.
- **Session document** `docs/sessions/session-001.md` finalised with a session close
  summarising all 52 phases (0.1.0 → 0.1.50) and what remains instance-dependent.

### Fixed
- **PHPUnit**: `external_test` still asserted the pre-0.1.49 behaviour where a
  failed job was marked failed outright. Since 0.1.49 a failure requeues the
  attempt while retries remain, so the test now asserts the requeue (the once-claimed
  attempt returns to queued). Test-only change; no version bump.

- `version.php`: 2026081049, release **0.1.50**. No new upgrade step
  (documentation + test fix; no runtime change). Session 001 is complete.

---

## [0.1.49] — 2026-08-11

Operational hardening: retries, teardown, concurrency, scheduling, events.

### Added
- **Attempt retry/staggering** (E3.1): new `tries` and `nextruntime` columns
  (upgrade 2026081048). `attempt_scheduler` gains `retry_status` (pure),
  `reclaim_stale` (requeue crashed running attempts with backoff, or fail when
  exhausted), `retry_or_fail`, and `abort`. `job_claim` respects `nextruntime` and
  counts a try; `job_complete` requeues a failed attempt instead of failing it
  outright.
- **Scheduled task** `pipeline_tick` (+ `db/tasks.php`, disabled by default):
  reclaims stale attempts and, when the exec worker is enabled, dispatches the pool.
- **Worker pool**: `worker_concurrency` is now consumed — `worker_launcher::launch_pool()`
  starts N workers (`worker_ids` pure), wired into `dispatch_worker`.
- **Lifecycle events** `run_scheduled`, `run_aggregated`, `run_aborted`, fired from
  the orchestrator, the aggregation task and `abort`.
- **Deviant patterns** (E3.4): `response_oracle::deviant_ability()` shifts effective
  ability on targeted subscales (the DPF stress mechanism); the oracle applies a
  person's `deviance` spec, which `person_generator` carries from the definition.
- **PF(t) toggle**: `test_provisioner::build_quizsettings()` sets
  `catquiz_lasttimeplayedpenalty` (default on; `timepenalty => false` to disable).
- **Query measurement**: `attempt_collector::collect_run()` reports `dbreads`/`dbwrites`.

### Changed
- **`run_cleanup` teardown is now complete**: besides the lab-store rows it removes the
  run's engine artefacts (test module, items, item parameters, scale tree/context) and
  the scale map — engine-guarded, so a no-op without the engine.

- `version.php`: 2026081048, release **0.1.49**. Upgrade step 2026081048 (attempt
  columns). Covered by new/extended tests (attempt_scheduler, run_cleanup, events,
  worker_launcher, response_oracle, person_generator, test_provisioner).

---

## [0.1.48] — 2026-08-11

Code polish: resolve the remaining PHPMD advisories by refactoring.

### Changed
- **Removed boolean-flag arguments** (real refactors, not suppressions — Moodle's
  phpcs rejects `@SuppressWarnings`):
  - `diagnostics::deficit_labels()` drops its `$below` flag; a deficit is always
    below the reference (the DPF definition). No caller used the other direction.
  - `exporter::to_json()` is now pretty by default with no flag; the compact form
    moves to the new `exporter::to_json_compact()`.
- **Split the diagnostics class**: the SE-aware measures (`deficit_labels_se`,
  `agreement_within_se`) move to a new cohesive `se_diagnostics` class, bringing
  the diagnostics class complexity back under the threshold. Tests split into
  `se_diagnostics_test.php` accordingly.

### Notes
- The only PHPMD items now reported anywhere are the growing `db/upgrade` function
  and the required `pluginfile` signature parameters — both standard Moodle
  patterns that moodle-plugin-ci does not flag, and PHPMD is non-failing in CI
  regardless.

- `version.php`: 2026081046 → **2026081047**, release 0.1.47 → **0.1.48**. No new
  upgrade step (code-only round; no behaviour change).

---

## [0.1.47] — 2026-08-11

Flexible worker login (password or pre-authenticated URL).

### Added
- **Login mode** for the worker: settings `worker_login_mode` (username/password
  convention, or a pre-authenticated URL template), `worker_login_url_template`
  (a URL with a {userid} placeholder) and `worker_login_suffix`. `worker_launcher`
  passes them through as `--login-mode`, `--login-url-template` and `--login-suffix`.
- **Worker** `login()` now dispatches by mode: it navigates to the substituted
  pre-authenticated URL (via the new pure `loginUrlFor()`), or falls back to the
  username/password flow. Covered by the Node harness and the launcher test.

- `version.php`: 2026081045 → **2026081046**, release 0.1.46 → **0.1.47**. No new
  upgrade step. This lets different test-instance auth setups (SSO/key login) be used
  without editing the worker.

---

## [0.1.46] — 2026-08-11

Worker robustness and a Node test harness (E3.3).

### Changed
- **Worker** `worker/run_attempt.js` is now defensive against theme variation:
  question detection, radio options, the submit button and the start button each
  try a list of fallback selectors (`firstHandle`/`allHandles`/`clickFirst`), and
  navigation waits tolerate slow network idles and fall back to domcontentloaded.
  `startAttempt` no longer clicks blindly, `answerQuestion` reports when no option
  is found, and a per-page navigation timeout is set.
- **Pure helpers extracted and exported** for testing: `parseArgs`,
  `normaliseBaseUrl`, `buildWsUrl`, `parseQuestionId`, `parseEngineAttemptId`,
  `usernameFor`, `passwordFor` (alongside `chooseOptionIndex`). The browser/network
  code still runs only when the script is executed directly.

### Added
- **Node test harness** `worker/test/run_attempt.test.js` on Node's built-in
  `node --test` runner (no external dependencies); `npm test` in `worker/` runs it.
  Seven tests cover the pure helpers.

- `version.php`: 2026081044 → **2026081045**, release 0.1.45 → **0.1.46**. No new
  upgrade step (worker-only round; no PHP changes).

---

## [0.1.45] — 2026-08-11

Polytomous UI mapping: ordered categories to concrete options.

### Changed
- **`question_template::default_polytomous()`** is now a single-select graded item
  with one option per ordered response category (ascending credit 0, 1/3, 2/3, 1),
  instead of a 3-of-6 multi-select. This matches the GPCM/GRM model: the engine's
  chosen category k is exactly the k-th option. Answer shuffling is already disabled
  on save, so the on-screen order equals the definition order.
- **Worker** `worker/run_attempt.js`: `answerQuestion()` now takes the full oracle
  decision and, via the new pure `chooseOptionIndex()`, clicks the category-th option
  for a polytomous item (clamped) and the correct/distractor option for a dichotomous
  one. Node-checked against the mapping.

### Fixed
- **Worker packaging**: `worker/package.json` declared `"type": "module"` while the
  script is CommonJS (`require`/`module.exports`), so it could not actually run.
  Removed the declaration; the worker now loads correctly and its pure helper is
  importable for testing.

- `version.php`: 2026081043 → **2026081044**, release 0.1.44 → **0.1.45**. No new
  upgrade step (code-only round). The polytomous path is now concrete end to end:
  engine category -> proportional fraction -> the matching on-screen option.

---

## [0.1.44] — 2026-08-11

Polytomous response wiring in the oracle (E3.4 completion).

### Added
- **`response_oracle::respond_item()`**: a pure dispatcher that scores a presented
  item by type — a polytomous item (flagged, carrying step/threshold parameters)
  draws an ordered category via GPCM/GRM and reports it as the chosen category with a
  proportional score fraction; a dichotomous item is scored right/wrong. Tested.
- **Item step parameters end to end**: `item_registrar::build_itemparam()` stores
  polytomous steps in the params json; `item_repository` reads them back and flags the
  item `polytomous` with its `steps`; `materialiser::polytomous_steps()` derives
  ascending thresholds around each item's difficulty when materialising polytomous
  items. All covered by tests.

### Changed
- **`oracle_answer`** now answers polytomous items: it resolves the item's step
  parameters and returns the chosen category in `choice` (with a proportional
  `fraction`), instead of always scoring dichotomously. Dichotomous items are
  unchanged.

- `version.php`: 2026081042 → **2026081043**, release 0.1.43 → **0.1.44**. No new
  upgrade step (code-only round). This closes the last open item from E3.4: the
  polytomous category choice is wired through the oracle.

---

## [0.1.43] — 2026-08-11

Documentation: as-built architecture and an operator guide.

### Docs
- **Architecture** `docs/design/architektur.md` lifted to Rev. 2.2 (as-built): new
  section 4 maps every epic (E0–E7) to the implemented classes, records the engine
  facts confirmed from the local_catquiz source (test context via
  `catscale::get_context_id`, automatic test-row creation, item/itemparam shape,
  live personparams columns), and updates the open points to their resolved state.
- **Operator guide** `docs/dev/durchfuehrung.md` (new): a step-by-step, end-to-end
  walkthrough — define/expand a sweep, orchestrate a run (CLI/task), run the worker,
  collect and aggregate, view reports, export, optional hub submission, and cleanup —
  plus the instance-specific fine-tuning points.

- `version.php`: 2026081041 → **2026081042**, release 0.1.42 → **0.1.43**. No new
  upgrade step (documentation-only round; no code changes).

---

## [0.1.42] — 2026-08-11

Experiment orchestration and tiering (E7) — the backlog is complete.

### Added
- **Run orchestrator** `classes/local/run_orchestrator.php` (E7): `plan_stages()`
  names the ordered setup pipeline (scales -> materialise -> test -> people ->
  attempts) — pure and tested; `setup()` runs it for a run, delegating each stage to
  its building block (scale tree, questions/items, CAT test, persons/users/course/
  enrolment, queued attempts) and advancing the run to scheduled. Each stage guards
  the engine, so without it setup reports every stage as skipped rather than failing.
- **Tier planner** `classes/local/tier_planner.php` (E7): orders experiments and
  their runs by study tier (baseline -> main -> robustness -> operative; unknown tiers
  last, ties by id). Pure and tested.
- **CLI + task**: `cli/orchestrate.php` sets up a single run, all runs of an
  experiment, or every run in tier order; `classes/task/orchestrate_run.php` does the
  same off the web request.

- `version.php`: 2026081040 → **2026081041**, release 0.1.41 → **0.1.42**. No new
  upgrade step (code-only round). With this, E7 — and the whole backlog (E0–E7) — is
  complete: from experiment definition through a materialised, DPF-sensitive CAT run
  to metrics, diagnostics, reports, export and hub aggregation.

---

## [0.1.41] — 2026-08-11

CI fix: update the stale hub test and reduce method complexity.

### Fixed
- **PHPUnit** (the only hard CI failure): `external_test::test_hub_submit_verifies_hash`
  still expected the old stub behaviour (`accepted = false`) and used a malformed
  payload (`run => 1`, which now trips a PHP warning under `--fail-on-warning`). Since
  0.1.40 the hub actually verifies and ingests, so the test now sends a well-formed
  package and asserts it is accepted, and that a tampered hash is rejected.
- **Complexity**: extracted `oracle_answer::resolve()`/`compute()` and
  `subscale_evaluator::pool_confusion()`/`rate()`/`f1()` to bring both `execute()` and
  `aggregate()` back under the cyclomatic/NPath thresholds. Removed unused locals
  (`scale_provisioner` `$index`, `transfer_package` `$USER`/`$offset`).

### Notes
- PHPMD runs with `|| true` in CI, so it never fails the build; a few pre-existing
  style advisories remain (two boolean-flag arguments, the diagnostics class
  complexity, the growing upgrade function, the required pluginfile signature). These
  are left as-is because Moodle's phpcs rejects `@SuppressWarnings` and refactoring the
  public signatures would ripple widely for no CI benefit.

- `version.php`: 2026081039 → **2026081040**, release 0.1.40 → **0.1.41**. No new
  upgrade step (fix-only round).

---

## [0.1.40] — 2026-08-10

Hub mode (E5) — run packaging, ingest and cross-instance aggregation.

### Added
- **Transfer package** `classes/local/transfer_package.php` (E5): `build()` bundles a
  run (metadata, persons by index, attempts with traces, results) into a JSON payload
  with a SHA-256 hash; `verify()` checks integrity; `ingest()` recreates the run on the
  hub under a dedicated "Hub ingest" experiment, re-mapping person references by index;
  `submit_to_hub()` posts the package to the configured hub (guarded by hub settings).
  Packaging, verification and ingest are testable (build -> verify -> ingest round-trip).
- **Hub settings**: hub URL and token for node -> hub submission.

### Changed
- **hub_submit_run** now verifies and ingests the package on the hub, then recomputes
  metrics and DPF on the hub copy (cross-instance aggregation), instead of only checking
  the hash.
- **hub_fetch_results** now returns a run's stored metrics (looked up by cell key) as
  JSON, instead of a stub.

- `version.php`: 2026081038 → **2026081039**, release 0.1.39 → **0.1.40**. No new
  upgrade step (code-only round). With this, E5 is complete.

---

## [0.1.39] — 2026-08-10

Export level/scope selection (E6.1 remainder) — E6 fully complete.

### Added
- **Export dataset** `classes/local/export_dataset.php`: the selection layer for
  exports. The level chooses the dataset — raw (answer matrix), ground truth (each
  person's true profile in tidy long form: global, category and subscale rows) or
  metrics (the stored result rows) — and the scope resolves the runs — a single run,
  all runs of an experiment, or all runs of a tier. Each builder returns a
  {columns, rows} table. Reads only the lab store; covered by `export_dataset_test.php`.
- **Generic dataset export** `run_exporter::export_dataset()` and `store_table()`:
  render and store any level/scope dataset in the requested formats, logging each.

- `version.php`: 2026081037 → **2026081038**, release 0.1.38 → **0.1.39**. No new
  upgrade step (code-only round). With this the export module (E6) is complete:
  formats (csv/json/xml/xlsx/ods) x levels (raw/ground-truth/metrics) x scopes
  (run/experiment/tier), plus the answer matrix and the export task.

---

## [0.1.38] — 2026-08-10

Complete E6: answer matrix (E6.2), spreadsheet export, export task (E6.3).

### Added
- **Answer matrix** `classes/local/answer_matrix.php` (E6.2): builds the
  persons-by-items response matrix of a run from the collected traces
  (responses: questionid => fraction). Columns are the union of presented items,
  rows are the persons, and cells are empty where an item was not presented (an
  adaptive test shows different items to different people). `build()` reads the lab
  store; `to_rows()` flattens it for export — pure and tested; CSV round-trips.
- **Spreadsheet export** `exporter::to_spreadsheet_file()`: writes rows to xlsx or
  ods via Moodle's dataformat writers (skipped when the dataformat plugin is absent).
- **Run exporter + task** `classes/local/run_exporter.php` and
  `classes/task/export_run.php` (E6.3): render a run's answer matrix to CSV/JSON/XML
  (and xlsx/ods when available), store each in the system context, and log it. Covered
  by `run_exporter_test.php` (stored CSV + export log).
- **File serving**: `local_catquizlab_pluginfile()` serves the stored export files,
  gated by `local/catquizlab:view`.

- `version.php`: 2026081036 → **2026081037**, release 0.1.37 → **0.1.38**. No new
  upgrade step (code-only round). With this, E6 is complete.

---

## [0.1.37] — 2026-08-10

Per-subscale DPF diagnostics wired end to end.

### Added
- **Subscale evaluator** `classes/local/subscale_evaluator.php`: evaluates how well
  the engine recovers a person's subscale profile. It aligns the trace's per-scale
  ability estimates (scaleabilities, from debug_info) with the ground-truth subscale
  abilities via the scale map, treats a subscale below the person's global level as a
  deficit (the DPF definition), and runs the diagnostics measures (Spearman, Top-k,
  nDCG, confusion, precision/recall). `evaluate_person()` is pure and tested;
  `evaluate_run()` aggregates across a run and stores dpf_* result rows (with a pooled
  confusion detail). Covered by `subscale_evaluator_test.php`.

### Changed
- The aggregation task now also runs the DPF subscale evaluation, so one task
  produces the global, per-stratum and DPF results for a run.

- `version.php`: 2026081035 → **2026081036**, release 0.1.36 → **0.1.37**. No new
  upgrade step (code-only round). This closes the DPF evaluation loop: the whole point
  of the suite — detecting differential subscale functioning — is now measured against
  the ground truth.

---

## [0.1.36] — 2026-08-10

Complete E3: exec worker (E3.2), capacity (E3.6), debug-info trace (E3.5).

### Added
- **E3.2 exec worker** `classes/local/worker_launcher.php` + task
  `classes/task/dispatch_worker.php`: the alternative to queue-polling. `build_command()`
  assembles the worker argv (pure, tested); `launch()` runs the Puppeteer worker on
  this host, but only when the exec worker is enabled and fully configured and the
  script is readable (so it never runs in CI). New settings: enable, Node path, base
  URL, token, max jobs, concurrency.
- **E3.6 capacity** `classes/local/capacity.php` (milestone M1): `plan_batches()`
  splits a queue into concurrency-sized batches, `stagger_offsets()` spaces starts in
  time, and `throughput()` turns collected runtimes into mean/median runtime and the
  estimated attempts-per-minute at a concurrency. All pure and tested.

### Changed
- **E3.5 debug-info trace**: `attempt_collector::parse_debug_info()` extracts the
  final per-scale ability estimates (personabilities) and per-scale exposure
  (numquestionsperscale) from the engine's debug_info, and `collect()` stores them on
  the trace (scaleabilities, questionsperscale, steps). This gives the DPF diagnostics
  the subscale-level estimates to compare against the ground truth. Pure parser tested.

- `version.php`: 2026081034 → **2026081035**, release 0.1.35 → **0.1.36**. No new
  upgrade step (code-only round). With this, E3 is complete.

---

## [0.1.35] — 2026-08-10

Question and item materialisation (E2.1, part 3) — E2.1 complete.

### Added
- **Item registrar** `classes/local/item_registrar.php` (E2.1): `build_itemparam()`
  assembles the local_catquiz_itemparams record for a known calibration
  (raschbirnbaum, difficulty, discrimination 1.0, guessing 0.0) — pure and tested;
  `register_item()` links a question to a scale via the engine
  (catscale::add_or_update_testitem_to_scale), stores the parameters and marks
  them active on the item. Engine-guarded.
- **Materialiser** `classes/local/materialiser.php` (E2.1): `plan_items()` walks
  the blueprint and maps each item to its subscale's engine scale via the run's
  scale map (pure, tested); `materialise()` renders each item into a
  multiple-choice question ({@see question_template}), creates it in a question
  category and registers it as a CAT item. Engine-guarded; the question-creation
  step is the most instance-specific and is validated in the target instance.

### Changed
- Polytomous default fractions now use 7 decimals (0.3333333 / -0.3333333) to
  match Moodle's accepted multiple-choice fraction set.

- `version.php`: 2026081033 → **2026081034**, release 0.1.34 → **0.1.35**. No new
  upgrade step (code-only round). With this, E2.1 (materialisation) is complete:
  scales → questions → items, all from the blueprint.

---

## [0.1.34] — 2026-08-10

Scale materialisation and profile mapping (E2.1, part 2).

### Added
- **Schema**: new table `local_catquizlab_scalemap` mapping a run's materialised
  engine scales to the ground-truth profile (level, category and subscale index),
  with an upgrade step at savepoint 2026081033.
- **Scale provisioner** `classes/local/scale_provisioner.php` (E2.1):
  `plan_scales()` turns a (categories, subcategories) blueprint into a flat scale
  plan with profile indices (pure, tested); `provision()` creates an engine CAT
  context and scale tree (local_catquiz_catcontext / local_catquiz_catscales) and
  records a scalemap row per scale; `mapping_for()` reads a scale's profile
  indices. Creating engine rows needs the engine, so provisioning is a no-op
  without it. Covered by `scale_provisioner_test.php`.

### Changed
- **Oracle** now resolves the **subscale** ability: it looks up the presented
  item's scale in the run's scale map and asks `response_oracle::ability_for()`
  for that category/subscale (falling back to the global ability when no mapping
  exists). This makes the oracle DPF-sensitive once scales are materialised.

- `version.php`: 2026081032 → **2026081033**, release 0.1.33 → **0.1.34**. Schema
  change → upgrade step 2026081033.

---

## [0.1.33] — 2026-08-10

Question templating for materialisation (E2.1, part 1).

### Added
- **Question template** `classes/local/question_template.php` (E2.1): renders a
  templated multiple-choice question from an item spec. A template carries a
  question-text template, a `single` flag (single-choice = dichotomous 1-of-4,
  multi = polytomous 1..4-of-6) and option templates with grading fractions
  (1.0 correct, 0 or a negative malus for distractors, partial fractions for
  graded options). Question text and options support placeholders — {scalename},
  {scalenumber}, {itemname}, {itemnumber}, {itemid}, {difficulty},
  {discrimination}, {guessing}. Ships sensible dichotomous and polytomous defaults
  (the polytomous one balances credit and malus to zero). Pure and testable,
  covered by `question_template_test.php`. The engine-side question and item
  creation will consume its output.

- `version.php`: 2026081031 → **2026081032**, release 0.1.32 → **0.1.33**. No new
  upgrade step (code-only round).

---

## [0.1.32] — 2026-08-10

Test provisioner (E2.4, create path) — create an adaptivequiz CAT test.

### Added
- **Test provisioner** `classes/local/test_provisioner.php` (E2.4): creates a new
  adaptivequiz activity with catquiz settings for a run and binds it
  (`run.testcmid`). `build_quizsettings()` assembles the catquiz fields the engine
  needs — `catmodel=catquiz`, `catquiz_catscales`, `catquiz_selectteststrategy`,
  the min/max question and per-subscale and standard-error groups, and a
  `catquiz_subscalecheckbox_<id>` per activated scale — mirroring a real test's
  JSON. It is pure and tested. `create()` builds the module info (adaptivequiz base
  fields + settings) and calls `add_moduleinfo`; the engine's catmodel handler then
  writes the `local_catquiz_tests` row. Needs the engine and host activity, so it
  is a no-op without them (CI stays green). Grounded in the uploaded engine source
  and a real quizsettings JSON. Covered by `test_provisioner_test.php`.

### Notes
- Confirmed against the live schema that `local_catquiz_personparams` carries
  `attemptid` and `standarderror`, so the attempt collector's SE read is correct
  (the older bundled install.xml lacked those columns).

- `version.php`: 2026081030 → **2026081031**, release 0.1.31 → **0.1.32**. No new
  upgrade step (code-only round).

---

## [0.1.31] — 2026-08-10

Fix: resolve the CAT context from the scale, not a (non-existent) test column.

### Fixed
- **`test_binder::read_test_config`** read `local_catquiz_tests.contextid`, but the
  engine schema has no such column — the CAT context is derived from the scale.
  It now resolves the context via `\local_catquiz\catscale::get_context_id()`
  (guarded by `class_exists`), matching the engine. Grounded in the uploaded
  local_catquiz source. This unblocks the oracle wiring, which reads item
  parameters in that context.

- `version.php`: 2026081029 → **2026081030**, release 0.1.30 → **0.1.31**. No new
  upgrade step (code-only fix).

---

## [0.1.30] — 2026-08-10

Report UI (E4.5) — results page with tables and charts.

### Added
- **Report builder** `classes/local/report_builder.php`: groups a run's stored
  results by scope (run and each stratum), exposes the key run-scope scalars, and
  assembles per-metric value series with stability across an experiment's runs.
  DB-only, testable, covered by `report_builder_test.php`.
- **Report page** `report.php`: for a run it shows a metric table per scope and a
  bar chart of the key metrics; for an experiment it shows a stability table and a
  line chart of each metric across runs — using Moodle's built-in chart API. Guarded
  by `local/catquizlab:view`. New `report:*` strings (en/de). Behat scenario in
  `tests/behat/report.feature`.
- **Management page links**: each experiment name and run cell now links to its
  report.

- `version.php`: 2026081028 → **2026081029**, release 0.1.29 → **0.1.30**. No new
  upgrade step (code-only round). This completes E4.

---

## [0.1.29] — 2026-08-10

Trend and stability analyses (E4.3).

### Added
- **Trend analysis** `classes/local/trend_analysis.php` (E4.3): `stability()`
  reports the dispersion of a metric across replications (mean, sample SD,
  coefficient of variation, min/max, range); `linear_trend()` fits a metric
  against an ordered parameter (slope, intercept, correlation, r²) — e.g. how RMSE
  rises with pool degradation; `convergence()` tracks the running mean and flags
  when it settles within a tolerance. `metric_series()` gathers a stored metric
  across an experiment's runs (ordered by replication) so the analyses run on real
  aggregated results. The statistics are pure/tested; only the reader touches the
  database. Covered by `trend_analysis_test.php`.

- `version.php`: 2026081027 → **2026081028**, release 0.1.28 → **0.1.29**. No new
  upgrade step (code-only round).

---

## [0.1.28] — 2026-08-10

Polytomous response models (E3.4).

### Added
- **Polytomous models in `response_oracle`** (E3.4): `gpcm_probabilities()`
  (Generalized Partial Credit Model — cumulative step scores through a softmax)
  and `grm_probabilities()` (Graded Response Model — successive differences of
  cumulative logistic thresholds) return category probability vectors, and
  `respond_polytomous()` draws a seed-deterministic category from either. Pure and
  covered by `response_oracle_test.php` (probabilities sum to 1, the modal
  category rises with ability, draws are deterministic, mean category increases
  with θ). Wiring these into `oracle_answer` follows once item step/threshold
  parameters are resolved from the engine.

### Docs
- Restored the CHANGELOG version headers, which a version-numbering collision (the
  working tree was ahead of a session summary) had stripped from intermediate
  entries; the history is now a continuous 0.1.28 → 0.1.0 sequence.
- Corrected a stale backlog note: the collector's ad-hoc task and `collect_run()`
  runtime measurement (E3.5) were already implemented and tested.

- `version.php`: 2026081026 → **2026081027**, release 0.1.27 → **0.1.28**. No new
  upgrade step (code-only round).

---

## [0.1.27] — 2026-08-10

Batch collection as an ad-hoc task (E3.5 rest).

### Added
- **Batch collection**: `attempt_collector::collect_run()` collects every attempt
  of a run that carries an engine attempt id and reports candidates, collected
  count and its own runtime in milliseconds; `attempt_collector::queue()` enqueues
  the new ad-hoc task `classes/task/collect_attempts.php`, which runs collection
  off the web request (useful for re-collection or when a completion did not carry
  the engine attempt id). Without the engine it is a clean no-op (zero collected).
  New string `task:collectattempts`. Covered by `attempt_collector_test.php`
  (candidate counting, timing, task execution).

- `version.php`: 2026081025 → **2026081026**, release 0.1.26 → **0.1.27**. No new
  upgrade step (code-only round).

---

## [0.1.26] — 2026-08-10

Worker job queue and the Puppeteer worker (E3.2 / E3.3).

### Changed
- **`job_claim`** now atomically claims the oldest queued attempt (inside a
  transaction so two workers can't take the same one), marks it running, and
  returns the run id, attempt id, adaptivequiz course-module id and the simulated
  user id for the worker to act on.
- **`job_complete`** now records the reported outcome on the attempt — collected
  or failed, with runtime and the engine attempt id — and, on a finished attempt
  with an engine attempt id, triggers `attempt_collector::collect()` to pull the
  trace (a no-op without the engine). New parameter `engineattemptid`; new strings
  `job:claimed`, `job:unknownattempt`. The queue logic is core-only and covered by
  `external_test.php` (oldest-first hand-out, running/collected/failed transitions,
  unknown-id rejection).
- **`worker/run_attempt.js`** is now a full reference worker: it polls
  `job_claim`, logs in as the simulated user, opens the adaptivequiz, and for each
  presented question asks `oracle_answer`, answers and submits, loops until the
  engine stops, then calls `job_complete` with the runtime and engine attempt id.
  Selectors are documented as theme-tunable.

- `version.php`: 2026081024 → **2026081025**, release 0.1.25 → **0.1.26**. No new
  upgrade step (code-only round).

---

## [0.1.25] — 2026-08-10

Item repository and a live response oracle (E2.1 / E3.4 wiring).

### Added
- **Item repository** `classes/local/item_repository.php`: reads the engine's
  active item parameters — `for_question()` for one presented item and
  `for_scale()` for a whole scale subtree (a recursive walk over
  local_catquiz_catscales joined to local_catquiz_items and its active
  local_catquiz_itemparams), following the Wunderbyte schema. `shape_params()`
  casts fields and applies the 1PL defaults (discrimination 1.0, guessing 0.0)
  and is pure/tested; the reads return null / [] without the engine (CI stays
  green). Covered by `item_repository_test.php`.

### Changed
- **Oracle web service** `classes/external/oracle_answer.php` is now wired: when
  the engine and a bound CAT test are present, it identifies the person from the
  logged-in simulated user, resolves the presented item's parameters via the item
  repository, and returns a **seed-deterministic, model-consistent** response
  (`response_oracle`, matching the engine's raschbirnbaum likelihood) with
  `ready = true`. Without the engine / bound test / person / item it returns the
  well-formed not-ready response as before. New string `oracle:computed`. The
  ability used is currently the global one; per-subscale resolution follows once
  materialisation records the catscale↔subscale mapping.

- `version.php`: 2026081023 → **2026081024**, release 0.1.24 → **0.1.25**. No new
  upgrade step (code-only round).

---

## [0.1.24] — 2026-08-10

Test binder (E2.4, reference path) — bind a run to an adaptivequiz CAT test.

### Added
- **Test binder** `classes/local/test_binder.php` (E2.4): `read_test_config()`
  resolves an adaptivequiz activity by course-module id (course_modules → modules
  → adaptivequiz) and reads its CAT configuration from `local_catquiz_tests`
  (component `mod_adaptivequiz`): scale id, engine context id and the quiz
  settings JSON — the same rows the Wunderbyte scripts read. `bind_existing()`
  records the test on the run (`run.testcmid`). This completes the "reference an
  existing CAT test" half of E2.4. Resolving the config needs the engine and host
  activity, so both methods return null when either is absent (CI and stand-alone
  stay green). Covered by `test_binder_test.php` (guard path). Creating a new
  adaptivequiz+catquiz test from a definition is the remaining half and needs the
  activity form fields.

- `version.php`: 2026081022 → **2026081023**, release 0.1.23 → **0.1.24**. No new
  upgrade step (run.testcmid already exists).

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

## [0.1.22] — 2026-08-10

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

## [0.1.21] — 2026-08-10

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

## [0.1.20] — 2026-08-10

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

## [0.1.19] — 2026-08-10

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

## [0.1.18] — 2026-08-10

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

## [0.1.17] — 2026-08-10

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

## [0.1.16] — 2026-08-10

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

## [0.1.15] — 2026-08-10

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

## [0.1.14] — 2026-08-10

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

## [0.1.13] — 2026-08-10

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

## [0.1.12] — 2026-08-10

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

## [0.1.11] — 2026-08-10

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

## [0.1.10] — 2026-08-10

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

## [0.1.9] — 2026-08-10

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

## [0.1.8] — 2026-08-10

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

## [0.1.7] — 2026-08-10

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

## [0.1.5] — 2026-08-10

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

## [0.1.4] — 2026-08-10

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

## [0.1.3] — 2026-08-10

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

## [0.1.2] — 2026-08-10

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
