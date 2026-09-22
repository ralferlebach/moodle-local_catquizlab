# Changelog — local_catquizlab

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [0.6.79] — 2026-09-22

The evaluation still ran out of memory, further along.

0.6.78 stopped the page reading every row. What it did not stop was what each
surviving row carried: every observation held the **decoded** trace and the
**decoded** person profile. A hundred-subscale profile is three kilobytes as
JSON and about thirty as PHP arrays, so an observation weighed 54 kB and 577 of
them weighed 30 MB — on a page whose overview computes means.

Measured per tab before: 46 MB peak. After: **16 to 24 MB**, all tabs, at a
128 MB limit.

The two heavy structures left the row. `results_query::detail()` reads them for
the one observation being looked at and caches that one, so the tabs that need
them — subscales, deficits, robustness, test flow — hold one decoded pair at a
time instead of all of them. The row keeps the two ids it takes to fetch them.

The page also asks for the headroom Moodle gives its own reports
(`raise_memory_limit(MEMORY_EXTRA)`). An evaluation over every sitting of a
large experiment is a report, and a default web request is not sized for one.

The regression test now also asserts that observations carry neither structure,
that `detail()` supplies both for a row, and that a hundred observations
serialise to under half a megabyte.

PHPUnit 692 tests / 3752 assertions, Behat 32 scenarios / 235 steps, phpcs and
PHPDoc clean.

---

## [0.6.78] — 2026-09-22

The evaluation ran out of memory on a real experiment.

    Allowed memory size of 134217728 bytes exhausted
      in lib/dml/mysqli_native_moodle_database.php on line 1368

Nine runs of a thousand people each: `results_query::observations()` read every
sitting of every run — nine thousand rows, trace JSON and all — and then every
person, profile JSON and all, before computing a single figure. Measured on the
same shape of data: **54 MB for the two reads alone**, on top of everything a
Moodle page already holds. It died before the first mean.

Both reads are now proportional to what is actually reported:

- The sittings come through a recordset, one row at a time, restricted to those
  that were collected and carry a trace. What stays in memory is one small
  array per observation, not one database row per sitting.
- People are fetched as they are needed and cached. A person who never sat the
  test is never read. In the reported experiment that is 8423 of 9000.

Measured after the change, same data, PHP's memory limit left at 128 MB:
**577 observations, 44 MB peak, 0.1 seconds.** Before: no answer at all.

A test builds two hundred people of whom ten sat the test, and asserts that the
evaluation returns ten observations and reads fewer than forty times — a count
that cannot grow with the people who did not sit.

PHPUnit 692 tests / 3745 assertions, Behat 32 scenarios / 235 steps, phpcs and
PHPDoc clean.

---

## [0.6.77] — 2026-09-22

Debug recording switched itself off.

It never switched on. The settings form offered the setting and read it back,
but the code that saves the form iterated a hand-written list of field names
that did not include `debuglevel`. Choosing a level, saving, and finding "Off"
again was the form discarding the choice in silence — on every save, for
everyone.

There is one list now, `settings_form::saved_fields()`, used both to save the
form and to fill it, so the two cannot drift apart. A test walks the form's
own elements and asserts that each one is in that list: a field added to the
form and forgotten in the saving code fails the test rather than the user.

PHPUnit 691 tests / 3743 assertions, Behat 32 scenarios / 235 steps, phpcs and
PHPDoc clean.

---

## [0.6.76] — 2026-09-22

Division by zero, the second cause — and the worker that held its slot while
dead.

### It never needed the seeding. Fifty people are enough.
`updatepersonability::calculate_sd_from_past_attempts()` takes the standard
deviation of the abilities already in the CAT context as the prior for the next
estimate, once **fifty** of them are there (`NUM_ESTIMATION_THRESHOLD`).
`model_raschmodel.php:734` then divides by its square.

Simulated people all start from the same value. The engine writes one ability
per person as each sitting begins, so a run with fifty people fills the context
with fifty identical numbers, and their standard deviation is zero. Removing the
seeding in 0.6.71 removed one source of identical values; the engine's own were
the other. A run that was provisioned, played, reset and provisioned again kept
the previous round's fifty — which is why every sitting after the reset failed
immediately.

My own fifty-person run had reached twenty-nine collected sittings when I
called it a success. It would have failed at the fiftieth. That was luck, not
evidence.

**The fix is not a workaround.** The engine's person parameters for a simulated
person are removed once their sitting has been read back into this plugin's
tables — on collection, and on terminal failure. Person fifty-one being
estimated partly from persons one to fifty is a dependency between observations
that an experiment must not have: each simulated person sits the test once,
alone. Provisioning clears the run's contexts entirely for the same reason.

Recommended to the engine, and not changed here: a standard deviation of zero
is not a prior. A floor in `calculate_sd_from_past_attempts()` would have made
this a biased estimate rather than an error page.

### A crashed worker held the only slot for five minutes
The worker died on a web service error without reporting anything. Its registry
row kept a fresh heartbeat, so the page said "1 worker running", the run card
said "waiting for a worker", and the tick answered "all-slots-busy" — all three
true of the row, none true of the machine.

The worker now hands its slot back before exiting, with `fatal-error` as the
reason. And the registry checks whether the process still exists on this host
rather than waiting out the timeout: signal 0, this host only, a pid that
belongs to somebody else left alone. Measured both ways — a dead pid releases
the slot at once, a live one is untouched.

### `job_complete` refused the diagnosis it had asked for
The reply's `message` was `PARAM_TEXT` and the replayed exception carries angle
brackets, so the worker crashed while reporting why it had failed. `PARAM_RAW`,
as the request side already was.

### Verification
PHPUnit 690 tests / 3734 assertions, Behat 32 scenarios / 235 steps, phpcs and
PHPDoc clean, worker JS syntax clean.

What is measured: the mechanism (fifty identical abilities produce a standard
deviation of zero), the cleanup (per person on collection, whole context on
provisioning), and the slot release. What is not: a complete fifty-sitting run
past the threshold. One sitting of that experiment takes about two minutes here
and fifty of them exceed the time I had. The threshold is 50; it is now
impossible to reach, but I have not watched it not happen.

---

## [0.6.75] — 2026-09-22

The reset preview asked for a string that did not exist.

    Invalid get_string() identifier: 'purge:countactivity'
      line 586 of run_lifecycle.php: reset_preview_message()

The run reset preview counted the test activity under `activity`; the
experiment preview, written earlier, counted it under `activities`, and only
that string existed. Every reset of a run with a test showed the notice.

It was one of six places that turned a set of counts into text, each with its
own idea of which keys existed. The other five — reset and deletion results in
`runs.php` and `experiment.php` — printed the internal keys to the person:
"3 enginescales, 50 enrolments". `purger::count_label()` and `counts_line()` are
the one place now; the reset preview uses `activities`; and the sixteen keys that
had no name have one in both languages. An unknown key reads as itself rather
than failing.

### Checked across the plugin, not only here
All 886 string identifiers used literally in PHP, templates and JavaScript
exist. The 31 places that build an identifier from a variable were enumerated
against their actual value sets: this family was the only one with a gap.

A test names every count key, asserts each renders as its translated name, and
renders the reset preview of a run with a test activity — a debugging notice
fails a PHPUnit test by itself, so the reported notice cannot come back
unnoticed.

PHPUnit 688 tests / 3724 assertions, Behat 32 scenarios / 235 steps, phpcs and
PHPDoc clean.

---

## [0.6.74] — 2026-09-22

Everything green, nothing running, no logs — and why.

### "Stalled" was the wrong word
Every run on the reported installation had been held by the circuit breaker
after "Division by zero" (the seeded starting abilities fixed in 0.6.71). Held
runs keep their sittings queued and out of reach. The situation counted those
sittings as waiting for a worker and said "stalled — start workers"; the
launcher knew there was nothing a worker could take, answered
`no-claimable-work`, and started nothing. Both were right. Only the page was
wrong, and nothing anywhere said so.

Reproduced here, then: "5 attempts waiting, no worker running → Start workers",
with 0 claimable and 5 blocked.

The situation now counts only claimable work as waiting, and when queued work
belongs to a held run it says so, with the cause:

    Run #215 was stopped after repeated failures; 5 test sittings are held back.
    Cause: … Division by zero                                [Show and continue]

It comes before the not-ready check: held runs prove the installation has run.

### One press for all held runs
Step 3 offers "Clear the errors of all N runs and continue" when more than one
run is held. The reset removes the seeded starting abilities as well, so runs
held by the old Division by zero are repaired by the same press. Measured: five
held runs continued, 37 sittings claimable, and the situation moves on.

### The pipeline says what it decided
Each tick keeps its decision — workers started, sittings claimable, reason —
where step 5 shows it, whether or not debug recording is on. It used to exist
only in cron's output, which is exactly where the person looking at a page that
says nothing is happening cannot look. An empty log window now names the time
of the most recent entry and offers to show everything.

### Audit finding: live status for the whole site
The site-wide poll passed `:experimentid` to SQL that had no such placeholder.
Only the parameters the query uses are passed now; a test calls the poll with
and without an experiment and validates both against the return structure.

### Verification
PHPUnit 687 tests / 3670 assertions, Behat 32 scenarios / 235 steps, phpcs and
PHPDoc clean.

---

## [0.6.73] — 2026-09-22

"No npm found" on a server where npm was installed.

`/usr/bin/npm` existed and ran in the administrator's shell, and the plugin said
there was no npm next to Node. The candidate was checked with `is_executable()`,
which on a symlink answers for the link and not for where it points. An npm
linked into a personal home directory — nvm puts it there — works for that
person and not for the web server user, who cannot enter that home directory.
Telling that administrator to install npm sent them to apt, which refused
because npm was already there.

Each candidate is now actually run as the web server user, with the Node it will
be run with, and only a version answer counts. When none works, the message says
what was found and why it did not count:

    /usr/bin/npm exists, but points to /home/…/.nvm/…/npm, which the web server
    user "www-data" cannot read. This is typical of an npm installed with nvm in
    a personal home directory.

followed by how to install Node and npm system-wide as a pair. Reproduced as
`www-data` with a link into a mode-700 home directory; the search reports it and
falls back to a working npm where one exists.

The same fault a second time, for npx: the browser installation looked for npx
beside the Node binary with `is_executable()`, and its "no npx found next to the
configured Node binary" is the message that looks exactly like the old npm one.
It no longer uses npx at all. Puppeteer's own command line comes from the
packages npm has just installed and is run by Node directly, from the runtime
directory — where the previous version, running in the plugin directory, would
have downloaded Puppeteer a second time. Measured with no npx and no packages in
the plugin directory: `npm ci` 10 s, then the browser in 5 s.

PHPUnit 685 tests / 3665 assertions; phpcs and PHPDoc clean.

---

## [0.6.72] — 2026-09-22

Setting up the worker runtime on a server nobody prepared for it.

### Before anything is downloaded, the server is asked
The preparation step shows, and "Set up the worker runtime" checks first:

- **Writable directories.** Every directory the installation and the worker
  write to is created if missing and actually written to — not just checked
  with `is_writable()`, which answers for permission bits and not for a full
  disk, a read-only mount or an ACL. The message names the operating-system
  user and gives the command:

      The PHP process runs as user "www-data" and cannot write to: …/worker-runtime,
      …/worker-home, … Ask your server administrator to run:
      sudo chown -R www-data /…/moodledata/local_catquizlab

  Measured by running the check as `www-data` against directories owned by root.
- **Disk space.** About 600 MB for the packages and a browser.
- **The npm registry and the browser download**, through Moodle's own HTTP
  client so that the site's proxy settings apply. Checked only while something
  still has to be downloaded, and cached for ten minutes so a page load does
  not wait for the network.

If any of it fails, the setup stops before npm starts — measured: refused in
0.0 s, rather than two minutes of npm followed by an error written for npm's
developers.

### Moodle's proxy reaches npm and Puppeteer
It was not passed on at all. A server behind a proxy — the normal case at a
university — could reach the internet from Moodle and not from the installation
Moodle started, and the failure read as npm's network error. `HTTPS_PROXY`,
`HTTP_PROXY`, the npm equivalents and `NO_PROXY` now come from the site's web
proxy settings. The proxy password never appears in a message.

### From the round before, also in this release
The setup button crashed on `implode()`: it read a key `ensure()` never
returned, and the crash hid npm's output. Node 18 was refused although Puppeteer
24 supports it — it is what Ubuntu 24.04 ships; 18.19.1 now plays a full
sitting (measured, fifteen questions). npm is looked for on the path as well as
beside Node, and its absence says `sudo apt install npm`. "Knoten.js" is Node.js
again in the four strings about the runtime.

The dependencies install into the dataroot and the worker finds them through
`NODE_PATH`. I had a development `node_modules` in my plugin directory that hid
every fault in this path; it was removed for these measurements, and a fresh
setup through the button installed 98 packages and a browser in 10 seconds, after
which a worker started by the pipeline played sittings of a fifty-person
experiment on MariaDB.

### Verification
PHPUnit 685 tests / 3665 assertions, Behat 32 scenarios / 235 steps, phpcs and
PHPDoc clean.

---

## [0.6.71] — 2026-09-22

The Division by zero, found and removed. It was mine.

### Where it was
Reproduced on a fresh MariaDB installation with the reported experiment —
fastest, ten by ten subscales of 25 items, fifty people — and located by both
the browser with debug display and the server-side replay, independently:

    DivisionByZeroError at local/catquiz/classes/local/model/model_raschmodel.php:734
      ← model_raschmodel::get_ability_tr_jacobian()   catcalc.php:188
      ← mathcat::newton_raphson()                      catcalc.php:200

Line 734 divides by the square of a standard deviation: the prior of the trusted
region around the ability estimate. That standard deviation comes from
`updatepersonability::calculate_sd_from_past_attempts()`, which — once a context
holds **fifty** person parameters — takes the standard deviation of their
abilities as the prior. Fifty identical values have a standard deviation of
zero.

### Where the identical values came from
This plugin. `seed_person_parameters()` wrote one row per simulated person per
scale, all at 0.0, before any sitting — on the belief, documented in the code,
that the engine needed a value before it could choose a first question. Fifty
people, 111 scales: 5550 rows, all 0.0000, in every context of every run.

Every experiment I tested had one to five people. Below fifty, the engine
answers 1.0 and never computes the standard deviation. The reported experiment
had exactly fifty. The hunt through subscale counts, database families and
engine versions was a hunt in the wrong place: it was the head count.

### The fix
Provisioning writes no starting abilities. The engine chooses a first question
without them — verified server-side and in a browser — and writes its own
parameters as it measures. A person nobody knows anything about should have no
prior; the engine's default for an empty context is sd = 1.

`remove_seeded_parameters()` strips the rows an earlier version left in a run's
contexts — status 0, no standard error, nothing measured — and leaves anything
the engine wrote. It runs during provisioning and inside "Clear the errors and
continue", so a run prepared by 0.6.70 or earlier is repaired by the same button
that resumes it. Measured on MariaDB: 5527 seeded rows removed, then nine of ten
sittings with fifty people finished at 7–14 questions, no division. The tenth
was the first after a cache purge and fails a different, retried way.

A regression test seeds fifty people at 0.0 beside one measured parameter and
asserts the fifty go and the one stays.

### Recommended to the engine, not changed here
`calculate_sd_from_past_attempts()` should never return 0 — a prior with zero
variance is not a prior. A floor at line 528 would have turned this into a
biased estimate rather than an error page. That is a one-line change in the
`ALiSe-v-1.2.0-legacy` branch, and it is not made in this plugin. No file under
`local/catquiz` or `mod/adaptivequiz` is modified by this repository; I checked.

### Two CI jobs, both mine
The structure job: one `</div>` too many in the recovery section of
`progress.mustache`, left from wrapping the card in a `<details>` — every tag
now balances. The worker end-to-end job: `--verify` counted attempts with
`registry::STATUS_FINISHED`, a run status whose value means "validated" for an
attempt, a state no worker reaches. The job played its sitting to the end and
was counted as having finished nothing. Attempt statuses now.

### Also
`job_complete`'s message is `PARAM_RAW`: the replay trace contains `<` and
`PARAM_TEXT` refused it, which would have discarded exactly the information the
replay exists to keep.

### Verification
PHPUnit 684 tests / 3657 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, every template tag balanced. Fifty
people, MariaDB, real browser: nine of ten sittings finished, none divided.

---

## [0.6.70] — 2026-09-21

Why no run ever started on any installation but mine.

### The switch nothing flipped
`worker_exec_enabled` ships as 0. Nothing in the setup checked it and nothing
set it — not the wizard, not the self-test, not "Start workers". The pipeline
tick called `launch_pool()`, got `null`, and printed nothing. That is the whole
of "stalled": every fresh installation, and every CI job, prepared experiments
that could never be played, and no line anywhere said which switch was off.

On my installation the switch was on. I had set it by hand at some point and
forgotten. Every smoke test and every interface run I reported passed on a
machine in a state the product cannot reach by itself. Those reports were true
of that machine and worthless for yours.

### One press, now
`enable_pipeline()` sets `worker_exec_enabled` and detects `pathtophp` along with
the plugin switch and the scheduled task. Measured from factory state — every
switch off, no token, no PHP path, task disabled, six blockers:

    run(true): changed [course, storedtoken, pipeline]   0.3 s
    after:     ready, nothing open
    worker_exec_enabled=1   pathtophp=/usr/bin/php

The wizard lists the switch as a step, so an installation with it off cannot
report itself ready. A regression test starts from factory state and asserts
all of it.

### Nothing is silent any more
`launch_pool()` never returns `null`: a launch that cannot happen names what it
is missing — `not-configured: worker_exec_enabled, worker_token` — the tick
prints it, and the "stalled" card says which switch is off and points at the
button that flips it, instead of offering "Start workers" for a start that
cannot happen.

### The interface run, from factory state
The Playwright test used to press whatever setup buttons it found, up to six
times, and never checked whether any of it had worked — which is how it walked
past an installation that could not run and blamed the plugin. It presses the
one setup button once now and then asserts readiness: not "closer", ready.
And it waits until every sitting is collected rather than moving on at the
first.

Run with every switch off first: **1 passed (2.6 min)** — setup, self-test,
definition, preparation, start, sittings collected, results shown. That is the
run that was missing.

### `php -S` answered one request at a time
The self-test makes an HTTP call to the site it runs on, from inside a request.
PHP's development server serves one request at a time, so the call waited for
itself and reported the token as not answering — red in CI, green on a real
web server, for the same code. `PHP_CLI_SERVER_WORKERS=4` in both workflows.

### Carried from 0.6.69, now with 685 tests green
Readiness refusals block provisioning; typed floats are localised and validated;
`run.lasterror` exists; the circuit breaker is wired where the worker reports;
rotation carries its reason and a stop is never replaced; the runtime survives
upgrades; the live page swaps the visible card; `engine_dryrun` replays a failed
selection server-side and returns the exception with file, line and trace.

### Verification
PHPUnit 685 tests / 3654 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, both workflows parse, interface run from
factory state passes.

---

## [0.6.69] — 2026-09-21

The four audit findings, four defects found on the way, and a diagnostic for
the failure on the live installation that this plugin could not see.

### The failure it could not see
Six runs on a live installation failed every sitting with "Division by zero"
for two days. Every check this plugin had said they were ready — the checks
read configuration, and the failure was in what the engine did with it. The
browser saw an error page; the site shows no debug information; so the worker
reported the words and not the place.

Why the words reached a Moodle error page at all: the engine's own error
handling catches `Exception`, and `DivisionByZeroError` is an `Error`. It falls
through every catch block the engine has.

**`engine_dryrun`** asks the engine for a question exactly as the attempt page
does, in PHP, inside a transaction it rolls back, and catches `Throwable` — with
file, line and trace. It runs as the last part of readiness, so a run that
would fail on its first sitting is held before one is queued. And the worker
calls `local_catquizlab_diagnose_attempt` on every page error, which replays
the selection for that sitting server-side and appends
`DivisionByZeroError at local/catquiz/…/x.php:123` to the failure. The next
failure on that installation will say where it is.

I could not reproduce the failure here: the same configuration — ten by ten
subscales, three hundred items — plays twenty-five questions and finishes, on
an engine identical to that installation's `main`. The diagnostic is the honest
answer to that.

### A readiness refusal that did not refuse
The run log for that installation shows `stage_failed stage=readiness` — "300
questions, over the global maximum of 25" — followed by `provisioning_ready`.
`stage_failed()` looked for a `failed` key that only the container stage set;
readiness and access answered `ok => false`, were logged as failed, and were
then ignored. Any stage answering `ok => false` stops provisioning now.

### "0,3" was 0
The experiment form used `PARAM_FLOAT` for twelve typed numbers. Moodle's own
documentation says not to: on a site whose language writes decimals with a
comma, "0,3" becomes 0 and "2,5" becomes 2. A standard-error floor of 0 is a
different experiment from the one designed, silently. `PARAM_LOCALISEDFLOAT`
now, with the form rejecting what it cannot read rather than storing zero, and
values written back the way the person's language writes them.

### The breaker wrote the cause into a column that did not exist
`local_catquizlab_run` had no `lasterror`; Moodle dropped the field without a
word, so the cause was never on the run. The column exists now, and a test
reads it back.

### The audit findings
**Circuit breaker, wired where it fires.** The worker reports through
`job_complete`, which reached an older streak check that paused the run. The
breaker is there now, and the old method delegates to it. A regression test
reports ten terminal failures through `job_complete` itself and asserts the
run held, the five waiting sittings untouched and unclaimable, the ten causes
grouped as one, and the experiment BLOCKED.

**Rotation with a reason.** The worker's last heartbeat carries why it stopped.
A replacement is launched only for `max-jobs`; a worker that stopped because it
was asked to is not replaced — which it was, for a release, undoing the stop
somebody requested. `fatal-error` is recorded as a crash; the other three as a
worker doing what it was told. `launch_pool()` is called with the one argument
it takes.

**A worker the launcher did not start is adopted, not stopped.** It
authenticated; it takes a free slot. Telling it to stop made every CI worker
play nothing, reported as "played 1 attempt, 0 finished".

**The runtime survives an upgrade.** `node_modules` install into the dataroot
and are found through `NODE_PATH`; `package-lock.json` is versioned; the
pipeline task ships enabled and the upgrade restores it where the plugin is
enabled; the `enabled` switch is checked before the execution queue moves.

**The live page updates what is visible.** The poll returns each run's status
card rendered, and the page swaps it in — there is no status logic in
JavaScript to disagree with the server's. Counts are scoped to the experiment
in view and say so. An interrupted poll offers to resume.

### Preparing needs the engine; running needs the rest
`Prepare experiment` refused on any installation without a browser installed.
Preparation builds courses and questions and needs neither. It requires the
engine and somewhere to build now; the browser, worker and pipeline are checked
when an experiment is queued — with what is missing named — and again when its
turn comes.

### Verification
PHPUnit 683 tests / 3634 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, both workflows parse. Run against this
instance: all five strategies pass the 15-answer smoke test; the interface
end-to-end run passes (6.7 minutes, three people).

---

## [0.6.67] — 2026-09-19

Both end-to-end jobs, run locally until they passed.

### The interface run: two passwords in one file
    Sign-in failed for admin: Invalid login, please try again

The workflow installed Moodle with `Admin123!` and told the test to sign in with
`Admin#12345`. Two literals in one file drift apart the moment either is
touched, and these already had. There is one now, named in `env:` and used by
both the installer and the test.

Worth noting that the failure said what it was. The sign-in check added in
0.6.61 turned this from "the plugin's page does not contain the words it should"
— which sends somebody looking at the plugin — into "the sign-in failed", which
is where the problem actually was.

Run locally afterwards: **1 passed (2.6m)**.

### The worker run: four faults, one behind the other
Reproduced locally by running the workflow's own steps in order, which is the
only way each of these became visible — every one of them was hidden behind the
one before it.

**The service account was incomplete.** `create_user_record()` makes an account
with no name and no address, which Moodle calls "not fully set up" and for which
it refuses every web service call — reported as `Access control exception`, the
same words it uses for a missing capability. So the search went to the service
list and the plugin's capabilities and found nothing wrong with either. It never
showed before because the worker's only heartbeat was inside an attempt, wrapped
in error handling that swallowed it.

**`webservice/rest:use` was never granted.** Without it the account may not speak
the protocol at all, and every call is refused before the function is looked at.
Proved by calling `job_claim` — a function nobody had touched — over HTTP and
getting the identical message.

**The run stayed SCHEDULED.** Attempts of a run that is only scheduled count as
blocked rather than claimable, so the worker connected, authenticated, asked for
work and was correctly told there was none. That reads as an empty queue rather
than as a run nobody released.

**And a real one, found on the way:** `job_claim` fetched fifty candidate
attempts and then skipped the unusable ones. That works while unusable ones are
rare; on an installation with a few failed runs behind it, their attempts fill
the window and a perfectly good new run is never reached. The worker reports an
empty queue — true of what it was shown, false of the installation. Runs that
cannot hand out work are excluded in the query now.

### The end-to-end box is ticked by default
Somebody starting the worker workflow by hand almost always wants the end-to-end
job. Having to remember the box means the run that would have caught something
is the one that skipped it.

### Verification
PHPUnit 679 tests / 3599 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, both workflows parse, and the interface
run passes locally.

---

## [0.6.66] — 2026-09-18

The interface end-to-end job skipped itself.

### A condition for an input that does not exist
    if: github.event_name == 'workflow_dispatch' && inputs.run_e2e

I built this workflow from the worker job's scaffolding, which is how the
installation steps stay identical between the two — and the condition came with
it. The worker workflow has a `run_e2e` input; this one does not, so
`inputs.run_e2e` was always empty and the job skipped every time it was started.

It runs only on `workflow_dispatch`, so starting it is the consent the condition
was there to check. Removed rather than reproduced.

### Two inputs that did nothing
`persons` was offered on the form and never read by the test, which filled in
five whatever the person had chosen. It reaches the form now.

`keepvideo` controlled nothing and is gone. Video and trace are kept for every
run, with a comment saying why there is no switch: a recording that only exists
after a failure cannot answer "does the interface still work", which is the
question somebody starts this job to ask.

---

## [0.6.65] — 2026-09-18

Issue #85: everyday operation and technical recovery, kept apart.

### Five equal buttons asked the reader to diagnose
The progress step offered *start workers*, *reap*, *release orphans*, *kill
tasks* and *kill pipeline* side by side, all the time. That asks somebody to
know that a lease is not a claim, that reaping is not releasing, and which of
the five applies today — before they can act at all. Running an experiment
should not require learning the plumbing.

### One problem, one action
`recovery_advisor::advise()` looks at the installation and names the first thing
standing in the way, in the words of the thing that is not working:

    Run #195 was stopped after repeated failures. Nothing else will run
    for this experiment until it is dealt with.
    [ Show the error and the log ]

Others it recognises: sittings stuck because a process took them and stopped
reporting; sittings waiting with no process running; Moodle's task runner not
having run — that one recommends setting up cron rather than starting a worker
by hand, because starting one fixes this minute and not the next.

Ordered deliberately: a held run comes before a worker problem, since
everything else is downstream of it and restarting a worker would not help.

Nothing here is new capability. Every action it recommends already existed; what
it adds is the judgement about which one applies, which was being left to the
reader.

### Folded
The recovery section is a `<details>`, closed on an ordinary day and opened by
the page when the advisor has something to say. The five technical actions live
behind a second fold inside it, for whoever actually wants them. Measured: four
technical actions present, all behind the folds, one recommended action visible.

### Verification
PHPUnit 679 tests / 3599 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean.

---

## [0.6.64] — 2026-09-18

Issue #78: live progress at experiment, run and sitting level.

### One source, so nothing can disagree
`live_progress::snapshot()` computes the lot: the operational state, the overall
figures, a row per run, and what is happening this second. The page renders from
it and the poll serves it, so the first paint and every update after it come
from one place. The header saying one thing while the table said another was two
correct answers to two different questions, asked seconds apart.

There is no arithmetic on the JavaScript side any more. It sets text and widths.

### The states are narrower than "running"
`draft`, `starting`, `running`, `waiting`, `paused`, `blocked`, `aggregating`,
`finished` — and the ones that need a reason carry one:

    BLOCKED — Run #195 was stopped after repeated failures.
    Overall: 0 / 25 (0%)
    #195  0 / 25  0% (held)
    Right now: 0 workers, 0 in progress, 15 waiting, 0 done, 10 failed

**RUNNING requires sittings actually in flight**, not a worker existing
somewhere. A worker that has been launched and has not reported is STARTING. A
run with work queued and nobody playing it is WAITING, with the reason named —
no worker running, or workers busy elsewhere. "Simulation running" beside "0 in
progress" is the contradiction all of this exists to prevent.

**And 99% is the ceiling until it is really done.** Rounding 249 of 250 up to
100% tells somebody the run is over while a sitting is still playing.

### Polling
Two seconds while the tab is being looked at, thirty when it is not, and an
immediate poll on coming back — so returning to a tab shows the current state
rather than one up to thirty seconds old. A hidden tab polled at full rate costs
the server requests nobody reads and, on a laptop, battery.

### Verification
PHPUnit 679 tests / 3599 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, AMD built, 1141 strings per language.

---

## [0.6.63] — 2026-09-18

Issue #92, and the CI failure my last fix caused.

### The CI job, again — and this one was mine
The reported log:

    Invalid format '# experiment course: 2'

That line is mine. I added it last release as a diagnostic, and the step
redirects the script's entire output into `$GITHUB_OUTPUT`, where anything that
is not `key=value` is a parse error. I fixed a job and broke it with the fix.

Diagnostics go to stderr now. But the real correction is in the workflow: it
filters with `grep -E '^[a-z_]+='` instead of trusting the script, because
Moodle's own fatal errors also go to stdout and no amount of care inside the
script makes that safe. The full output is logged in a separate step, so
nothing is lost.

**And the failure hiding behind it:** `local_catquizlab_e2e_token()` called
`create_role()` unconditionally. It worked exactly once per installation; the
second run hit the unique shortname and died with "Error writing to database" —
a message that says nothing about a role, and which then went into the output
parser too. The role and the service membership are reused when they exist.
Verified by running it twice in a row.

### #92 — a run that fails ten times the same way now stops
A run whose sittings all fail for one reason keeps failing for that reason.
Retrying the eleventh produces an eleventh identical failure and some more
minutes of a browser's time, while the experiment goes on reporting itself as
running — so somebody watching sees progress that is only the failure counter
moving.

After ten consecutive failures the run is held, the experiment goes to
**BLOCKED**, and the remaining sittings stay queued and out of reach rather than
being spent on a known failure. Measured: ten failures, run held, five sittings
held back, experiment blocked.

**The cause, once:** errors are normalised — attempt ids, paths, hashes and
timestamps stripped — and grouped, so ten reports of one fault read as

    Division by zero (×10)

rather than as ten separate problems burying the one line somebody needs.

**Reading comes before retrying.** The card's action is *Show the error and the
log*, which links into step 5 filtered to that run. *Clear the errors and
continue* sits after it, because pressing it without looking produces the same
ten failures. Measured: 10 sittings requeued, run back to READY.

The trip and the reset are both recorded as `run_autopaused` and `run_resumed`,
with the failure count and the last error.

### Verification
PHPUnit 679 tests / 3599 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 1120 strings per language.

---

## [0.6.62] — 2026-09-18

Issues #93 and #94 — both consequences of my own earlier changes.

### #93 — a rotation limit was stopping experiments
A worker plays a set number of sittings and exits. That is a resource setting:
how long one browser process lives. It says nothing about the experiment, and
until now it stopped one — the next worker came with the five-minute scheduler
tick, so an experiment with hundreds of sittings queued sat idle because a
browser reached its configured limit.

A worker reporting that it is stopping now triggers an immediate replacement,
but only when one is actually needed: work still claimable and nobody left to
claim it. Two workers started because one rotated would be a different bug.

**And rotation no longer reads as a stall.** `summary()` counts `starting`
separately from `live`, so the run card can say *Simulation running, worker being
replaced* rather than *waiting for a worker* — which would have somebody
investigating a setting that is working exactly as configured.

### #94 — the header and the table disagreed by construction
The live poll asked about the whole installation (`args: {}`) while the table
beside it showed one experiment. They were answering different questions a few
seconds apart, and the difference looked like a bug in the numbers.

The poll takes the experiment in view and returns one snapshot: the experiment's
own figures, and a row per run carrying `state`, `done`, `total`, `percent`,
`open` and `failed`. Every verdict comes from `status_report::run()` — the same
service that renders the page — so a row updated by a poll and a row drawn by a
page load say the same thing because they came from the same place. There is no
second opinion about a run's state on the JavaScript side.

    Experiment: finished 10/10 (100%)
    Run 186: Run finished  10/10 (100%)

**A failed poll is now said rather than swallowed.** It used to stop silently
after one error, leaving somebody watching a page that had quietly stopped being
live — reading stale numbers as current ones. After three consecutive failures
it says so and stops.

### Verification
PHPUnit 679 tests / 3598 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, AMD built, 1113 strings per language.

---

## [0.6.61] — 2026-09-18

An interface end-to-end run, and the defect it found on its first honest pass.

### Two buttons that did nothing
`runs.php` guards its action handling with:

    if ($action !== '' && $runid > 0) {

The experiment-level actions added in 0.6.56 — **Prepare experiment** and **Run
experiment** — sit inside that block and carry an experiment id and no run id.
They were never reachable. The buttons rendered, the forms posted, the page came
back without a word, and the experiment stayed a draft with no runs.

Called through the façade the same preparation worked perfectly:

    prepare: ok=true state=ready 1/1

Code correct, interface dead. No unit test and no CLI smoke test could see
that — which is the entire argument for this run existing.

Moved ahead of the run-scoped block. Measured through a real POST:

    Experiment prepared: 1 of 1 runs ready.
    Runs afterwards: 1

### The run
`.github/workflows/ui-e2e.yml`, **manual only**: it installs Moodle, provisions
an experiment and plays five people's sittings through a real browser, which is
minutes of runner time for a question nobody asks on every push.

Everything happens through the interface. No CLI script sets anything up, no SQL
seeds the queue. The parameters are entered in the form: 15–20 questions per
test, 3–5 per scale, standard error 0.3–2.5, no time limits, five simulated
people.

    1 passed (7.2m)
    UI smoke: finished | attempts: {"planned":10,"collected":10}

Video, trace and screenshots are kept for **every** run, not only failures: a
passing run is what somebody wants to watch when they are asking whether the
interface still works, and a recording that only exists after a failure cannot
answer that. All of it, plus the HTML report and the Moodle, cron and worker
logs, is uploaded as one artifact.

### Eleven runs to get there
Each failure was a real obstacle: collapsed form sections, CAT-engine links
matching the same words, two `.nav-tabs` on one page, a navigation button caught
by `.first()` when submitting, a swallowed sign-in error, the experiment never
selected in the shell, and finally no cron — without which a queued experiment
correctly waits forever and the test times out on a system that is working.

Two were fixed in the plugin rather than in the test: the step tabs now carry
`data-region="catquizlab-steps"`, because matching on a class name is matching
on a coincidence.

### The worker end-to-end job
Both failures from the reported logs are fixed. `e2e_prepare.php` creates the
experiment course when none is configured — on a fresh CI installation the
setting points nowhere, and the job died at `stage:container`. And its errors
are emitted as a single-line `setup_error=…`; the previous message contained
colons and broke `$GITHUB_OUTPUT`, so the job reported a parse error instead of
the cause.

### Verification
PHPUnit 679 tests / 3598 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, both workflows parse, and the interface
run passes against this instance.

---

## [0.6.60] — 2026-09-18

Issue #90, and a counting mistake in the smoke test that was mine.

### The thirteen questions were never thirteen
Raising the smoke test's bar from 2 answers to 15 made every strategy fail at
exactly 13, against every budget, every standard-error floor and every pool
size. That stability is what a real constraint looks like, so I went looking for
one: through `maximumquestionscheck`, through `filterbystandarderror`, through
the per-subscale minimums, through the engine's test configuration.

All of it was configured correctly. The attempt had answered **twenty**
questions:

    adaptivequiz_attempt: questionsattempted = 20
    QUBA slots:                                20
    catquizlab trace:                          13 "steps"

Thirteen is the number of fields the trace records about an attempt —
`finaltheta`, `finalse`, `items`, `responses`, `nitems`, `stopreason`, and so
on. I had counted the keys of the trace object instead of reading its `steps`
field. The engine was right, the plugin was right, and the test was wrong in a
way that looked exactly like a defect in both.

Worth the detour: the investigation confirmed that the per-subscale floor
protects only the main scale from being dropped, which is real and worth knowing
even though it was not the cause here.

### The gate, with 15 answers required
    classic   20 questions   Reached maximum number of questions   PASS
    allsubs   20 questions   Reached maximum number of questions   PASS
    balanced  20 questions   Reached maximum number of questions   PASS
    fastest   15 questions   You ran out of questions              PASS
    relsubs   20 questions   Reached maximum number of questions   PASS

`--minanswers` is a parameter now, defaulting to 15, and each run reports **why**
the attempt stopped. `fastest` stopping at exactly the minimum because it ran
out of suitable items is the kind of thing that is worth seeing rather than
inferring.

### #90 — the fifth tab
**Logs.** One chronological list from the four places this plugin records: the
debug trace, the per-run execution log, Moodle's ad-hoc task table and the
worker reports. Each was correct and none was complete, so answering "what
happened" meant reading all four and merging them by hand.

    2026-09-18 10:54:56  lifecycle  run=108 attempt#1 stage_started stage=attempts
    2026-09-18 10:54:56  lifecycle  run=108 attempt#1 stage_completed queries=4
    2026-09-18 10:55:48  task       queued aggregate_results run=108 due=due now

Filterable by time window, channel, run and free text. The filtered selection is
rendered as a single `<pre>` block so that selecting it gives the text rather
than the markup — a log somebody has to reformat before sending is a log that
arrives incomplete — with a download for selections too long to select by hand.

### Verification
PHPUnit 679 tests / 3598 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 1111 strings per language. All five
strategies pass the 15-answer gate against this instance.

---

## [0.6.59] — 2026-09-18

Issue #89: an experiment played from definition to results, as a gate.

### Why this and not another unit test
Every part of this plugin was tested and the whole did not work. A worker that
played exactly one attempt, a heartbeat refused since 0.6.14, a status card
claiming a simulation that was not running: none of those were visible to a unit
test, and all of them were obvious the moment anybody watched an experiment try
to run.

`cli/smoke.php` watches. It defines an experiment, prepares it, starts a worker,
waits for real attempts against Moodle's own question engine, aggregates, and
checks the numbers — failing at the first step that does not hold.

### Measured, on this instance
    == CatQuizLab smoke test: classic ==
      Setup complete.
      Prepared 1/1 runs.
      2 attempts queued.
      Worker started and reported.
      2 collected, 0 failed. (65.0s)
      Every collected attempt answered at least 13 questions.
      28 result rows.
      bias 0.0545 · mae 0.4220 · correlation 1.0000 · meanlength 12.0000

    PASS: 2 attempts played, 2 with estimates, 28 result rows.

**Thirteen questions per attempt**, not one: the second question is the first
that depended on how the first was answered, which is the mechanism under test.
Answering one and stopping would pass a naive check and prove nothing about an
adaptive test.

All five strategies, run one after another:

    classic  PASS    allsubs  PASS    balanced PASS
    fastest  PASS    relsubs  PASS

### A failure that was mine, not the plugin's
`allsubs` failed the first time I ran it — because I had started two smoke tests
at once. They defer each other's queues and compete for worker slots, so each
waited out its timeout on the other's work. A strategy got an undeserved FAIL
and I nearly went looking for a defect that was in the test.

The script takes an exclusive lock now and refuses to run beside itself, and
`cli/smoke_all.sh` runs the strategies sequentially for the same reason.

### In CI
Added to `worker-e2e.yml` after the existing single-attempt check, with the
smoke logs and the worker logs collected on failure — the Moodle exception, the
attempt and the stage, which is what the issue asked to keep.

### Verification
PHPUnit 677 tests / 3595 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean. The readings above are from real runs
against this instance, not from the test suite.

---

## [0.6.58] — 2026-09-17

Issues #86, #87 and #88. #89 is not done — see below.

### #88 — the one that was mine
0.6.36 added `request_stop()` so that deleting an experiment asks a worker to
finish and leave. It never cleared the flag. Reusing a worker id reset the
status, the pid and the heartbeat and left `stoprequested` standing, so the new
worker was granted a stop it had never been asked for, at its first heartbeat —
and finished after one attempt with hundreds waiting.

`acquire_slot()` now clears everything belonging to the process that held the
identity before: the stop flag, the current attempt, the worker state. Measured:
stop requested, released, restarted, flag gone.

The worker also says why it stopped — `queue-empty`, `max-jobs`,
`stop-requested`, `fatal-error`. "Finished; played 1 attempt(s)" beside 250
waiting is alarming or routine depending on the reason, and the log said nothing
either way.

### #87 — a stopping worker was recorded as running
`report()` wrote `STATUS_RUNNING` whatever the worker said about itself, so a
process that had reported `stopping` and exited showed as idle and live with its
slot apparently taken — and the next start could be refused by a worker that no
longer existed. A worker reporting that it is stopping is now recorded as
stopped, holding no attempt. Measured: `live=1` while working, `live=0` after.

### #86 — the error message, not the navigation
`describePage()` took the first 200 characters of the body, which on a Moodle
error page is the skip link, the site name and the breadcrumb. The failure
reported `page="Zum Hauptinhalt Client01 Startseite…"` and said nothing about
what went wrong.

It now reads `.errormessage`, `.core-error-message`, `#region-main .alert-danger`
and the rest before falling back, and reports the error, the error code and the
debug block separately. Tokens, session keys and anything that looks like one
are redacted, because an error report is a thing people paste into issues.

### #89 — not done
The end-to-end gate over a multi-question attempt across every strategy is not
built. A worker run against this instance did play attempts through to
collection, and the `played 1 attempt` pattern is gone — it played three — but
that is an observation, not the gate the issue asks for. It stays open.

### Verification
PHPUnit 677 tests / 3595 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, worker JS syntax clean.

---

## [0.6.57] — 2026-09-17

Issues #80 and #81 — and the reason both were possible.

### #80 — "started" meant a shell command returned
`launch_pool()` counted a launch the moment `exec($command . ' &')` returned.
That means the shell was asked to start something. It does not mean Node ran,
that Puppeteer found a browser, or that the worker reached Moodle — and counting
it anyway is why **"1 worker started"** appeared beside **"250 claimable, 0 in
progress"**.

The worker says so itself now: it registers as soon as Node, Puppeteer and the
web service have all worked, and the launcher waits up to eight seconds for that
before counting anything. A worker that never reports has its slot released and
its last output kept, because a worker that dies on startup has already said
why.

Measured: a deliberately broken worker returns `launched=0, no-handshake` and
leaves no registry row. A working one returns `launched=1` in 0.8 seconds.

### The heartbeat had never worked
Making the handshake explicit exposed why this was possible at all.
`local_catquizlab_worker_heartbeat` was declared as an external function in
0.6.14 and **never added to the worker's service**, so every heartbeat a worker
ever sent came back `Access control exception`. The worker's own error handling
swallowed it — a missed heartbeat is not worth abandoning an attempt over — so
nothing ever looked wrong.

The consequence: workers never reported, and their liveness was read from the
registry row the launcher itself had written. Every "worker is alive" this
plugin has displayed was the launcher agreeing with itself.

### #81 — "Simulation running" now means simulation is running
The run card showed *running* whenever any worker was live, so a run could read
`running, 0%` beside `250 claimable, 0 in progress` — which cannot both be true,
and the one a person acts on is the second.

It asks whether **this run's** attempts are being held by a worker right now.
When none are, it says `Prepared, waiting for a worker`, which is true whether
the workers are busy elsewhere, still starting, or about to pick this up.
Measured both ways.

### Verification
PHPUnit 677 tests / 3595 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, worker JS syntax clean.

### Still open
#78 — live progress at experiment, run and sitting level.

---

## [0.6.56] — 2026-09-17

Issues #76, #77 and #83: the two actions, in the interface.

### #83 — one start, and the machinery below it
Step 3 leads with a single card: the experiment's state, its progress, and
whichever of the two buttons applies — **Prepare experiment** or **Run
experiment**. The per-run and per-worker controls are all still there, below it.
They are recovery, and offering them as peers of the one action anybody normally
wants is what made operating an experiment feel like operating machinery.

### #76 — preparation as one process
The button runs `experiment_runner::prepare()` over every run of the experiment
and reports one verdict. Blockers name the run, the cell and the stage — the
first three, because a wall of thirty is not more informative than three and a
count.

### #77 — a queue that survives cron
`local_catquizlab_execqueue`: experiments waiting their turn, in the order they
were asked for, advanced by the pipeline tick.

Measured:

    queue 36 → position 1
    queue 69 → position 2
    queue 36 again → already-queued
    advance → started 36
    advance → started 0 (busy)

**A table, not a list in memory**, because a queue a task holds forgets
everything the moment cron restarts, and somebody who lines up five experiments
before going home would find none of them had run.

**Strictly one at a time**, because two experiments running together share the
worker pool: each takes twice as long and neither had the machine to itself,
which for a timing-sensitive simulation is a measurement error rather than a
scheduling preference.

Readiness is checked again when an entry reaches the front, not only when it was
queued — an experiment can be reset or fail while it waits, and one that has is
skipped with a reason rather than stalling the line behind it.

### Verification
PHPUnit 677 tests / 3595 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 1098 strings per language.

### Still open
#78, #80 and #81 — live progress at three levels, and the status-truth pair.

---

## [0.6.55] — 2026-09-17

Issue #75: two actions, and the state model they need.

### The premise
Everything this plugin does for a person reduces to *prepare this experiment*
and *run it*. Which background task advances which run, which worker claims
which sitting, what state a queue is in — all of that the plugin has to know and
nobody should have to decide. The interface asked anyway, one button per
internal step, so operating an experiment meant understanding its machinery.

### `experiment_runner::prepare()`
One call takes an experiment from a definition to queued sittings: validate,
create the runs, provision each one, enrol the people, check access, queue the
attempts. Measured on a two-run experiment: **2 of 2 ready, 4 attempts queued,
5.4 seconds** — and a second press changes nothing, because pressing a button
twice is what people do when the first press seemed not to work.

**Validation happens before any mutation.** A definition that cannot be read
leaves nothing half-built: tested with broken JSON, zero runs created.

**Blockers name the run and the stage.** A bare "provisioning failed" for an
experiment of thirty runs is not something anybody can act on.

### Three states instead of thirty
`draft`, `preparing`, `ready`, `running`, `finished`, `blocked` — for the
experiment, derived from its runs. The per-run statuses stay exactly as they
are; they are the plugin's bookkeeping, and reporting them as the experiment's
state is how somebody ends up reading a table of thirty rows to answer one
question.

A failure anywhere blocks the whole experiment, deliberately: a result over the
runs that happened to work is a different quantity from the one that was
designed.

### What this is not
The internals are untouched — same services, same tasks, same stages. This is a
façade over them. It is also not yet wired into the interface: that is #76, #77
and #83, which come next.

### Verification
PHPUnit 674 tests / 3579 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean.

---

## [0.6.54] — 2026-09-17

Issues #79, #82 and #84.

### #79 — the self-test that had never run
`operations.php` had **two** handlers for `action=selftest`. The first, older
one ran the browser launcher and returned; the end-to-end self-test added in
0.6.35 sat thirty lines below it and was never reached. PHP does not warn about
this and a reader does not notice it.

Removing the first exposed a second fault in the second: it called
`\core\session\manager::restart()`, which does not exist. The handler threw on
its first real use — 0.6.35 said the self-test was measured, and it was, from
the CLI. Through the button it had never worked.

Both fixed. Measured through the browser: the button now runs the six real
checks and reports what it finds.

A third thing fell out of it: installing a browser and running the self-test
shared one session slot and produce different shapes, so whichever ran last was
read as the other. Separate slots now.

### #82 — two times, now labelled
The task table showed the last run and the next run side by side with no
headings. They say opposite things about whether something is wrong, and a
reader had to guess which was which. `Task | Last run | Next automatic run`.

### #84 — nothing is a state, not an empty page
With no results the overview returned an empty string, leaving filter controls
above nothing — which reads as a broken page rather than as "there is nothing
yet", and those need different responses. It now says so, explains what produces
results, and links to step 3 where the answer to "why is there nothing" actually
is.

### Verification
PHPUnit 669 tests / 3565 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 1083 strings per language.

### Still open
#75, #76, #77, #78, #80, #81 and #83 — the two-step execution architecture and
the status-truth issues. Those are a redesign of how a run is started and
watched, not corrections, and they are not started here.

---

## [0.6.53] — 2026-09-17

Issues #63, #64 and #65 — the last three from the audit.

### #63 — the recording is governed, not just switched on
**Its own capability.** `local/catquizlab:debug`, `RISK_PERSONAL`, managers only.
The recording holds what every operator did, with parameters; running
experiments is not a reason to read that. An operator with `:execute` and
without `:debug` sees no console — tested.

**Bounded by age as well as count.** Seven days, beside the 2000 entries. A quiet
installation kept two thousand entries for months, and a record of what somebody
did in June is not diagnosis, it is a log of colleagues nobody asked for.

**A download.** The console answers "what just happened" on screen; the export
answers "here is what happened" to somebody who is not at the screen — with the
site, the versions, the level and the retention policy beside the entries. Same
redaction, because the secrets were removed on the way in.

### #64 — the PHP path, chosen or typed
Several PHP versions on one server is the normal case after an upgrade.
`php_cli_candidates()` finds them all and reports what each says it is:

    /usr/bin/php      PHP 8.3.6 (cli)
    /usr/bin/php8.3   PHP 8.3.6 (cli)

Non-CLI binaries are left out rather than offered, because an FPM binary runs
and then behaves differently enough that a task using it fails in ways nobody
traces back here.

A typed path is validated before it is stored — absolute, present, executable,
answering, and actually CLI — each with its own message. An unvalidated path
becomes a scheduled task that quietly does nothing, which is the failure the
whole check exists to prevent.

### #65 — deep deletion takes its own accounts
A hundred simulated students left in the user list is something, and deep
deletion promises to leave nothing. Enrolments and accounts now go with it.

**Only its own.** Ownership is read from the run number this plugin stamps into
the usernames it creates. Tested in both directions: `catlab_r82_p1` goes,
`a_real_person` enrolled in the same course stays. Deleting a real user because
they happened to be in an experiment course would be unforgivable.

### Verification
PHPUnit 669 tests / 3565 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 1077 strings per language.

Every issue from the 2026091703 audit is now addressed.

---

## [0.6.52] — 2026-09-17

Issues #66, #67 and #61.

### #66 — a root existing is not a tree being sound
`existing_scales()` checked that the root scale still existed and reused the
tree on that alone. A run whose subscales were half deleted has a root, and
reusing it means materialising into a shape the engine cannot serve — which
surfaces much later as items it will not hand out.

It runs the same consistency check the interface does, and rebuilds when the
tree fails it. Measured: a healthy tree is reused; deleting one subscale from
the engine makes the next call refuse and rebuild.

**Structure only, not the blueprint comparisons.** `scale_health` reads the
expected shape from the run's manifest while provisioning is handed a blueprint
as an argument, and the two can legitimately differ. The first version compared
everything and broke idempotency for exactly that reason — the existing test
caught it, and the fix is to check what is actually broken (one root, one
context, engine scales present, unique nodes, valid parents, no cycles) rather
than what merely differs.

### #67 — the contexts went with the generations
`scale_inventory::cleanup()` deleted the abandoned scales and left their CAT
contexts standing. A context with no scales is invisible in every list and still
counts as a context — the engine's own selection walks them.

They are removed now, and **only when empty**: another run may share one, and a
shared context deleted from under it is a worse failure than a leftover.
Measured: two generations cleaned, one context removed, the shared one left.

### #61 — what the log says about a task
Every ad-hoc task now reports its task row id, its retry delay and its remaining
attempts. A failure on a third attempt waiting out an eight-hour delay is a
different situation from a first attempt, and the log said the same thing about
both. The id is stored on the run log row; the delay and attempt count travel
with the trace.

### Verification
PHPUnit 666 tests / 3544 assertions, Behat 32 scenarios / 235 steps, phpcs with
the Moodle standard clean, PHPDoc clean.

---

## [0.6.51] — 2026-09-17

Issues #52, #54 and #55: each step shows its own step.

### The plan step showed three other steps
`manage.mustache` carried the experiment course, an environment listing and the
run table. All three describe a different part of the process, and a reader on
the plan could not tell which step they were on because the plan answered
questions belonging to two others. Removed — the plan is now experiments, and
the actions that create them.

### Preparation had six cards, four of them second copies
Workers, tasks, the queue and the active runs were on the preparation tab **and**
on step 3. Not misplaced: duplicated, and competing with the originals. Whoever
changed one would have had to remember the other.

Preparation is two cards now — the wizard and the self-test — which is what
"can this installation run anything" needs, and nothing else.

### The experiment course moved rather than vanished
Taking the container block out of the plan removed the only way to set the
experiment course, which is not a tidy-up but a loss. It lives in preparation
now, beside the wizard that creates it: `Experiment course: … Change` when one
is set, `Choose an experiment course` when none is.

The Behat suite caught this, not me.

### The scenarios moved with the content
Six scenarios asserted the old layout. They assert the new one — following the
tab to where the thing now lives — rather than asserting what the interface used
to look like. The one that looked for an "All runs" link out of the plan now
uses the tab, because the plan no longer lists runs and has nothing to link out
of.

### Verification
PHPUnit 666 tests / 3544 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 1069 strings per language.

---

## [0.6.50] — 2026-09-17

The 2026091703 audit: #71, #58, #74, #73, and #53/#72.

### #71 — the last broken action path
`status_report::pipeline()` still handed the bare `tasks.php` URL to a card that
renders an action with no `command` as a link — so the one button on the card
that says "nothing is running" led to a request `tasks.php` rejects for want of
an action. It carries the full posted contract now, and the card passes
`classname` through for task actions.

### #58 — a table that never existed
The audit found `local_catquizlab_subscale` missing from the reset, and it was
right that it was missing. It is also not in `install.xml`, not in any upgrade
step and not in the database: four places named it, each behind a
`table_exists()` guard, so they had always been dead.

Dead code that makes an audit believe a reset leaves results behind is worse
than no code. All four references removed.

### #74 — one layer fewer, and the declensions
`Auftrag zur Testbearbeitung` was still a second name for a Testbearbeitung, so
the layer is gone. `geclaimt` became `übernommen`. And the declensions the blunt
pass left: `Anteil der simulierte Testbearbeitungen`, `Für diesen simulierten
Testbearbeitungen`, `einen simulierte Testbearbeitung`.

### #73 — the CLI hint is gone from the interface
    Noch keine Versuchsdurchläufe definiert. Mit dem CLI (cli/sweep.php) …

Sending somebody to a shell, from a GUI whose whole point is not needing one,
contradicts the process model printed three inches above it. It now says where
in the interface runs are made.

### #53/#72 — order, fallback, and dead calls
The shell reads **title → tabs → selector → status**: the status describes the
experiment the selector chose, so it follows it.

An `experimentid` for an experiment that no longer exists falls back to all
experiments, rather than scoping the reader to nothing and looking like a broken
query instead of a stale bookmark.

And the second `shell::render()` calls in `experiment.php` and `compare.php` are
gone. The render guard made them harmless, which is exactly why they were worth
removing: a call that only works because something else suppresses it is a trap
for whoever removes that something.

### Verification
PHPUnit 666 tests / 3568 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean, every template example context
parseable.

---

## [0.6.49] — 2026-09-17

The 2026091701 audit: #71's three remaining faults, and #74's.

### #71 — the method was fixed, the targets were not
Three faults, all mine, all from converting links to forms without following
each one to its handler:

**Pause and resume posted to the wrong controller.** The form's action was
`index.php?tab=setup` while `pauserun` is handled in `operations.php`. Correcting
the verb and leaving the target is half a fix.

**"Run now" was still a link in `operations.mustache`.** I converted the copy in
`progress.mustache` and not this one — and since the URL had deliberately lost
its `action` parameter, the link now led to `tasks.php` demanding a parameter it
was no longer given. The visible button was broken in a way it had not been
before the fix.

**The status card's data contract was one-sided.** The template expected `url`,
`command`, `runid` and `sesskey`; `status_report` still supplied only `label`
and `url`, so the forms posted no action at all. It supplies the full set now
for the two actions that change state, and the card renders a plain link for the
two that only navigate — a form for a navigation would post nothing and mean
nothing.

Measured in a browser: pressing "Run now" returns **"The task ran."**

### #74 — one object, one name
`Arbeitsauftrag` was my own coinage for what the glossary calls a
Testbearbeitung, and two names for one object is the defect a glossary exists to
prevent. Gone, along with the last `Sweep`.

**And one bad translation worth naming: `Node` had become `Knoten`.** Node.js is
a proper noun; `Knoten` is what a scale tree has. Somebody reading "Knoten muss
vorhanden und ausführbar sein" would look for the wrong thing entirely. Three
strings fixed.

Plus the grammar the blunt pass left: "eine Teilversuch", "alle eingereihten
simulierte Testbearbeitungen", "die Simulationsprozess-Registry".

### Verification
PHPUnit 666 tests / 3568 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 1069 strings per language. Every form
on the operations and progress views posts to the file that handles it —
checked by rendering them and reading the targets.

---

## [0.6.48] — 2026-09-17

Issue #62: the queries, taken apart and reduced where they are mine.

### Where they actually go
Measured per item, separately:

    Moodle's save_question():   27 queries
    question category lookup:    1
    setting the ID number:       4
    registering with the engine: 4
    the rest of materialising:  ~3

Twenty-seven of about thirty-nine are Moodle's own question API. That is the
half of this that is not a defect here, and knowing it stops the effort going
into the wrong place.

### What was reducible, reduced
The **category lookup** fetched the same row once per item; it is the same
category for every item on a scale. Held for the request.

The **ID numbers** cost four queries each — two lookups and a uniqueness check
per item — to write a label. They are now written together once every question
exists: one join for all the entries, one read of the labels already taken, then
the writes.

The **engine verification** moved from once per item to once per scale, and the
answer names the items the engine cannot see, so nothing about locating a
failure was traded away.

    before: 38.9 queries per item
    after:  35.3 queries per item

A ninth, not a tenth of what was reported. Against fourteen thousand items that
is roughly 545,000 → 494,000 — an improvement, and not a solution.

### A regression, caught by measuring the right thing
The first version of the batching wrote no ID numbers at all: 0 of 120. The
query count looked better precisely because the work was not being done. The
flush had been inserted at an anchor that no longer existed and silently did
nothing. Measured again after fixing it: 120 of 120, at 35.3 per item.

Worth stating plainly, because "faster" and "not doing it" produce the same
number.

### The budget
Now 40 per item, just above the measured 35.3. The previous rate of 38.9 would
fail it, which is the point: this plugin's share is caught growing rather than
absorbed into core's.

### What is not done
The 27 queries inside `save_question()` are untouched. Reducing them means
writing question rows without Moodle's question API — plausible for synthetic
items, and a decision about coupling to core's schema rather than an
optimisation. It is not made here.

So #62 is better and not finished. If it is closed, it should be closed on the
measurement and the reduced share, not on the total.

### Verification
PHPUnit 666 tests / 3568 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. Both rates above are readings from this
instance on a 120-item pool.

---

## [0.6.47] — 2026-09-17

The 2026091617 audit, worked through. #58, #71, #53/#72, #73, #74, and an honest
answer on #62.

### #58 — reset and rerun never reported success
`start()` returns `started`; `reset_and_rerun()` read `ok`. Every successful
restart came back as a failure — while the run had in fact restarted, so the
message and the database disagreed. Measured after the fix: `ok=true`, status
SCHEDULED.

### #71 — six state changes sat behind links
Pause and resume, run-a-task, and the action on every status card were `<a
href>` with `action=` in the URL. A state change behind a GET is one a
prefetcher, a crawler or a back button can make on somebody's behalf, and
pausing a run somebody is watching then looks like a bug in the plugin.

All posted now, the handler refuses `pauserun`/`resumerun` over GET, and a test
renders every template and fails on any `<a href>` carrying an action. Measured:
6 such links before, 0 after.

### #53/#72 — one place to start an experiment
`+ New experiment` now appears on the plan step and nowhere else — measured
across all four steps — and the separate primary button in `manage.mustache` is
gone. Two places to start one is two places to check when somebody cannot find
it.

### #73 — the real position, not the open tab
`here` was set by tab, so the progress step marked five stages at once as "you
are here" — which tells somebody nothing they did not know from the tab they
clicked. `reached_stage()` derives it from the runs: scheduled means preparing,
ready with nothing collected means queued, running means simulating, all
finished means evaluating. Measured: exactly one stage marked, and the right
one.

### #74 — the rest of the glossary
36 more strings: Versuchszelle → Teilversuch, provisionieren → technisch
vorbereiten, and Draft, Stage, Blueprint, Root-Scale, CAT-Context, Queue,
Preflight, Readiness and Recovery out of the German interface.

### #62 — what the queries actually are
Moving verification from per item to per scale changed the rate by almost
nothing, so the parts were measured separately:

    registering one item with the CAT engine:  4 queries
    purging the engine cache:                  0 queries
    asking the engine for a scale's items:     3 queries

Of about 39 queries per item, **4 are this plugin**. The rest is Moodle's own
question creation. The reported 549,727 against fourteen thousand items is
therefore very largely core's cost and not a defect here — worth knowing before
anybody optimises the wrong thing.

The budget is now 45 per item rather than 60: just above the measured rate, so a
change in this plugin's share is caught instead of being absorbed. That is not a
reduction of the number, and the issue should not be closed as one. Reducing it
further means going around Moodle's question API, which is a decision, not a
tidy-up.

### Found by the tests, not by me
Restricting `+ New experiment` to the plan step put it inside the
`hasexperiments` block, so an installation with no experiments had no way to
make one. The Behat scenario that caught it is called "The empty registry offers
a way forward instead of a dead end", which is exactly what I had broken.

### Verification
PHPUnit 666 tests / 3568 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 1069 strings per language.

---

## [0.6.46] — 2026-09-17

Issue #70: the audit, re-run against what is there now.

The audit judged 0.6.29 and found one issue closeable. It set the right
standard — "practical functionality and usable operation, not the mere presence
of classes or strings or changelog claims" — so this does not answer it with a
changelog. Every claim was measured against the running code.

Fifteen of sixteen held. Two did not, and both are fixed here.

### `+ Neues Experiment` was never in the selector
#53 asked for it beside the experiment dropdown and it was never added. Looking
for it on another tab is how somebody ends up making their second experiment by
editing their first.

### A verdict without its codes
`scale_health::check()` returns machine-readable failure codes — except on the
early path for a run with no scale tree, which returned no `codes` key at all.
A caller branching on them would have to know which return path produced the
verdict, and "no codes key" is not the same as "no failures". Every path carries
them now.

That one is worth noting: it was added in 0.6.37, the focused tests passed, and
it took a check written from the outside to find it. Which is the argument for
writing checks from the outside.

### The audit is a test now
`tests/audit_test.php` checks the promises at the seam where each would break:
one shell per request, the selector under the tabs, six preparation stages, the
PHP path gating the pipeline and not the engine, the database refusing a second
root, health verdicts carrying codes, one correlation id across both logs, and
deleting having its own `RISK_DATALOSS` capability.

Deliberately shallow — one check per claim — because its job is to notice a
promise regressing, not to re-test what the focused suites cover.

### Verification
PHPUnit 658 tests / 3561 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean, every template example context
parseable.

---

## [0.6.45] — 2026-09-17

Issue #73: the process, explained where somebody is standing in it.

### The gap
The interface shows a queue, a task, a worker and a run without saying how they
follow from one another. Somebody can read every panel on every tab and still
not know what happens first, what produces what, what runs by itself, or when
results appear.

### Eight stages, folded away
On every tab, behind "How does this work?":

    1. Define the experiment              waits for you
    2. Create the variants                waits for you
    3. Prepare each run technically       runs by itself   ← you are here
    4. Queue the test sittings            runs by itself   ← you are here
    5. Simulate the test sittings         waits for you    ← you are here
    6. Collect the data                   runs by itself   ← you are here
    7. Combine the results per run        runs by itself   ← you are here
    8. Evaluate the experiment            waits for you

Each says what it produces — "two strategies by three pool variants is six
sub-experiments; at five replications that is thirty runs" — and whether it is
waiting for a person. Those two are what somebody needs from a status they do
not recognise, and neither was anywhere in the interface.

It is closed by default: a reader who knows the process should not scroll past
it, and one who does not should not have to go looking.

### Verification
PHPUnit 652 tests / 3548 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean.

### A note on testing this one
Checking it in a browser produced `Section error!` on every page. That is what
Moodle says when a plugin's `settings.php` has not been loaded — which happens
when the version in version.php is ahead of the version in the database. The
plugin was fine; the upgrade had not been run. Worth writing down, because the
message names a section and the cause is a pending upgrade.

### Still open
#70 — the code and issue audit.

---

## [0.6.44] — 2026-09-17

CI fixes for 0.6.43.

### A duplicated upgrade block
`moodle-plugin-ci savepoints` failed:

    ERROR: Detected multiple 'savepoint' calls for version 2026091604

A whole upgrade block had been pasted in a second time. It is idempotent, so it
did no harm on a site that ran it — but two savepoints for one version means a
step that can run twice, and the check exists because that is usually not
harmless.

Removed, and a test pins it: one savepoint per block, each matching its own
condition, ascending, none claiming a version the plugin has not reached. Run
against a deliberately duplicated block it reports
`Duplicate savepoint versions: 2026091604` and fails.

### PHPUnit on Moodle 5.x
Six access-readiness tests failed on every 5.x job and passed here:

    null value in column "questioncategory" violates not-null constraint

The adaptive-quiz generator builds its own question pool when none is given, and
what that produces is null on 5.x. The tests pass an explicit category now.

This is the second finding this month that a one-version local environment
cannot see, and both surfaced only in CI. Worth remembering when a change looks
green locally.

### Verification
PHPUnit 648 tests / 3514 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean, all templates pass the Mustache linter,
upgrade savepoints unique and ordered.

### Still open
#70 (code and issue audit), #73 (explaining the process), #74 (German
terminology).

---

## [0.6.44] — 2026-09-17

CI fixes, and #74: the agreed German terminology.

### CI — two real defects, not flaky runs
**PHPUnit on every matrix leg but the local one.** The access-readiness tests
let the activity generator build its own question pool. On 4.5 that works; on
5.x `questioncategory` is NOT NULL and the insert fails. The tests now create
the category and pass it in — the kind of difference a one-version local
environment cannot see.

**`moodle-plugin-ci savepoints`.** A whole upgrade block had been pasted in
twice, so two savepoints claimed version 2026091604. A site running that step
twice does whatever the step does twice. The duplicate is gone, and a test now
checks that every block has exactly one savepoint, at its own version, in
ascending order — verified in the failing direction by re-inserting the
duplicate and watching it go red.

### #74 — The glossary, applied
310 German strings rewritten to the agreed terms:

    Worker            → Simulationsprozess
    Worker runtime    → Ausführungsumgebung des Simulationsprozesses
    Worker access     → Moodle-Zugang des Simulationsprozesses
    Pipeline          → automatische Ausführungssteuerung
    Task              → Hintergrundaufgabe
    Run               → Versuchsdurchlauf
    Attempt           → simulierte Testbearbeitung
    Job / Claim       → Arbeitsauftrag / Reservierung
    Heartbeat         → Lebenszeichen
    Sweep / Cell      → Versuchsplan / Teilversuch
    Provisioning      → technische Vorbereitung

and the run statuses as the glossary words them: `Technische Vorbereitung
eingeplant`, `Bereit zur Simulation`, `Simulation läuft`, `Ergebnisse werden
zusammengeführt`.

Three passes, because a blunt substitution is not a translation. English
pluralises with a bracketed s and German does not, so `Versuchsdurchlauf(s)`
became `Versuchsdurchläufe`. English compounds two nouns freely, so
`simulierte Testbearbeitung-Arbeitsaufträge` became `Arbeitsaufträge für
Testbearbeitungen`. And an adjective is capitalised at the start of a sentence
and nowhere else, so `Versuchsdurchläufe und Technische Vorbereitung` became
`… und technische Vorbereitung`.

The English strings are untouched: the technical terms are the right ones there,
and the glossary is about what the German interface says.

### Verification
PHPUnit 648 tests / 3514 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 1047 strings per language with
identical keys.

### Still open
#70 (code and issue audit) and #73 (explaining the process to a newcomer).

---

## [0.6.43] — 2026-09-16

Issues #71, #72 and #69.

### #71 — "Your session has most likely timed out" (P0)
Two buttons on the preparation tab posted an empty `sesskey`, so Moodle answered
with its session-timeout message — and the reader concluded their login was the
problem, or that experiments are tied to a browser session. Neither is true:
experiments are rows, and only the CSRF check is session-bound.

The cause was `{{../../sesskey}}`, one template level short of where the key
sits. Counting levels is right until somebody adds a wrapper, so the key now
travels inside the action it belongs to and no template reaches for it across
levels.

Measured before and after: 13 sesskey fields, 2 of them empty → 0 empty. A test
now renders every template from its example context and fails on any empty one.

### #72 — The experiment selector belongs under the tabs
Above them it read as a filter on the whole plugin. It scopes what the current
step shows, so it sits under the step it scopes.

### #69 — The last raw seconds
Attempt runtimes in the results were printed as `5400 s`. They read as spans of
time now, like every other duration since 0.6.30: four seconds stays four
seconds, and an hour and a half reads as an hour and a half. The reader is
asking how long it took, not counting.

### Verification
PHPUnit 647 tests / 3513 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean.

### Still open
#70 (code and issue audit), #73 (explaining the process to a newcomer) and #74
(consistent German terminology) — all three about what the plugin says rather
than what it does.

---

## [0.6.42] — 2026-09-16

Release gate, RG-002 — the last one.

### A form that asks for everything answers nothing
The editor is one long form. That is honest about the data and unhelpful about
the work: somebody defining their first experiment cannot tell which fields
belong together, which are still empty, or whether what they have is enough to
run.

The same fields, described as the eight decisions they are, above the form:

    ✓ 1. Name and purpose
    ✓ 2. Model and strategy
      3. Item pool
      4. Simulated people
      5. CAT budgets
      6. Design and replications
      7. Validate
      8. Create runs

    Next: 3. Item pool

Nothing is hidden and no field moves. The form stays what it is; this says where
in it somebody stands.

Validating is not a seventh ceremony — it follows from the six decisions above
it, and a spinner that checks what is already known would be theatre. Creating
the runs is the eighth, because it is the thing the whole form is for, and
leaving it off the list made the form feel like it ended before the work did.
Once the runs exist, the plan points at step 3, where they can be watched.

### The release gate is complete
RG-001 through RG-015, over seven releases:

    001 one shell per page          002 the plan as a process
    003 a self-test that tests      004 PHP CLI gates one path of three
    005 reset without leftovers     006 purge with its own authority
    007 the invariant in the DB     008 nine consistency checks
    009 access as the user sees it  010 one id through every layer
    011 task metadata in the log    012 regions patched, not reloaded
    013 completeness and a package  014 rate measured, reduced, gated
    015 durations read as time

### Verification
PHPUnit 640 tests / 3497 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 1042 language strings per language.

---

## [0.6.41] — 2026-09-16

Release gate, the rest of RG-014: the query budget is a gate, not a reading.

### Measuring did not stop it getting worse
0.6.34 found where 549,727 queries went and reduced the pool check from 39 to
1.17 per item. Nothing stopped the next change from putting them back.

Four budgets now fail the build:

    twenty engine lookups of one scale        ≤ 5 queries
    breaking down a queue of 60 attempts      ≤ 15 queries
    reading 50 debug entries                  ≤ 3 queries
    the per-stage rates this codebase has

They gate the **rate**, which is the only part of the number that is
actionable. A large pool costing many queries is arithmetic; a small pool
suddenly costing twice as many per item is a defect. So the budget passes the
reported 549,727 against fourteen thousand items and fails 1,900 against
twenty-four.

The thresholds are generous on purpose: a gate that fires on noise gets raised
until it fires on nothing.

### Verified in the failing direction
The engine-lookup cache was disabled and the test run:

    Twenty lookups of one scale cost 21 queries
    Tests: 1, Failures: 1

restored, and green again. A gate nobody has seen fail is a gate nobody knows
works.

They run inside `moodle-plugin-ci phpunit`, so they are in every CI run already.

### Verification
PHPUnit 634 tests / 3479 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean.

### Still open from the release gate
RG-002 — the experiment plan as a guided eight-step process. It is the last one,
and the largest.

---

## [0.6.40] — 2026-09-16

Release gate, RG-013: what a result is worth, stated with it.

### Completeness, above the analyses
A number from a simulation is worth what the reader can check about it, and the
first thing to check is how much of the design actually ran. That was below the
charts, or nowhere.

    Incomplete: 0 of 3 attempt(s) collected, 1 run(s) not finished.
    The analyses below cover what was collected, which is a different
    quantity from what was designed.

A mean over 40 of 120 planned attempts is not a worse version of the same
number; it is a different number, and the reader has to know before reading it.
The unfinished runs link to step 3, where they can be dealt with.

### A reproducibility package
One JSON file: the definition as typed, the definition as the plugin understood
it, every run with its seeds and manifest and execution log, the versions of all
four plugins and of Moodle, and the completeness statement.

The seeds are the whole claim: without them the design is a description and not
an instruction. The versions matter for the same reason — a result from a
version nobody can name is a result nobody can reproduce.

### Found while testing
The package normalised the *decoded* definition, so a definition that does not
decode arrived as an empty array and normalised to defaults — a package that
looked complete and described something that never ran. It reads the stored text
now, and says plainly when that text can no longer be understood.

### Verification
PHPUnit 630 tests / 3468 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. The completeness reading above is from
this instance.

### Still open from the release gate
RG-002, and the CI query budget from RG-014.

---

## [0.6.39] — 2026-09-16

Release gate, RG-012: the page updates instead of reloading.

### What a reload costs
The first live updater reloaded the whole page whenever the overall verdict
changed — and that is the thing somebody watching a run notices most. The scroll
position goes, an open detail closes, and a form half filled in is gone. Two
minutes of watching a queue drain meant two or three of those.

### Patched in place
The service returns the text of each region, and the module writes it:

    running: 1 → 0, navigations: 0

Text rather than markup: the page already has the elements, and sending HTML
for them would put two places in charge of what a status card looks like.

A reload is kept for the one change that cannot be patched honestly — the page
gaining or losing rows. Adding a run row from JavaScript would mean a second
renderer deciding what a run row looks like, and two renderers disagree
eventually. A fingerprint of the row counts decides which case it is.

The interval is two seconds on a visible tab, and polling stops on a hidden one
and after a long idle.

### Found while measuring
The page this exists for did not have it. The updater was wired into the
overview and never into step 3 — the page somebody actually watches while a run
plays. The first two measurements showed no change at all for that reason, which
is a better outcome than shipping it and being told.

### Verification
PHPUnit 626 tests / 3447 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean, all six template example contexts
parseable. The reading above is from this instance, with an attempt completed
from outside the page.

### Still open from the release gate
RG-002, RG-013, and the CI query budget from RG-014.

---

## [0.6.38] — 2026-09-16

Release gate, RG-010 and RG-011: one click, one id, one sequence.

### The problem with a list
The debug trace recorded actions, state changes, tasks and worker reports, in
order — and nothing said which of them belonged together. On an installation
where two people work, or where a task runs while somebody presses a button, the
entries are a list of things that happened near each other, and reading a defect
means guessing.

### A correlation id, carried
Every entry now carries one id, and a task adopts the id of the click that
queued it:

    ui         resetrerun             —                c3627cf2
    lifecycle  run_failed             —                c3627cf2
    lifecycle  orchestration_started  orchestrate_run  c3627cf2
    task       orchestrate            orchestrate_run  c3627cf2

Filtering on it turns the list into the sequence of one action. The run log
carries the same id, so the two logs read as one.

Tasks announce themselves rather than being guessed at: asking Moodle from
inside a run answers "something is running", not "this is". A failure inside a
task otherwise reads as a failure from nowhere.

### Four levels instead of a switch
`off`, `action`, `verbose`, `trace`. `action` keeps what a person did and what
changed because of it — the rest is volume that makes those two harder to find.
`verbose` adds services, tasks and workers; `trace` adds the exception detail.

### Fixed while testing
The task context is static and survived between tests, so one scenario's task
was recorded against another's entries. A web request ends and takes it with it;
a test process does not. There is a reset for that now.

### Verification
PHPUnit 626 tests / 3447 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. The sequence above is a real recording
from this instance.

### Still open from the release gate
RG-002, RG-012, RG-013, and the CI query budget from RG-014.

---

## [0.6.37] — 2026-09-16

Release gate, RG-008: the scale tree check, completed.

### Four more questions
The check asked five things and stopped where the reported failure had been. It
asks nine now:

    ✓ Exactly one root scale
    ✓ Exactly one CAT context
    ✓ Every mapped scale exists in the engine
    ✓ No duplicate nodes
    ✓ Every parent reference points into this run      ← new
    ✓ No cycles in the tree                            ← new
    ✓ Every node the blueprint calls for is present    ← new
    ✓ No nodes beyond the blueprint                    ← new
    ✓ Node count matches the blueprint

A node whose parent is not in this run's map belongs to a tree the run does not
own, and the engine will walk it anyway. A cycle makes every walk of the tree a
hang rather than an error. And a missing subscale and a stray extra one are
separate defects with separate repairs, so they are separate checks — the node
count alone reports both as "the wrong number".

### Machine-readable, and naming the objects
The verdict carries the failing check ids for anything that has to branch on it,
and the root and context ids rather than only their counts. A report that names
the objects beats one that counts them.

Measured against a deliberately broken tree — one subscale removed, one node
given a parent outside the run:

    × Every mapped scale exists in the engine      8 of 9 present.
    × Every parent reference points into this run  Nodes with a parent outside: c9s9
    × Every node the blueprint calls for is present  Missing: c1s1
    × No nodes beyond the blueprint                Unexpected: c9s9

    codes: enginescales, parents, expectedkeys, nounexpectedkeys

### Verification
PHPUnit 623 tests / 3437 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. Both readings above are from this
instance.

### Still open from the release gate
RG-002, RG-010 to RG-013, and the CI query budget from RG-014.

---

## [0.6.36] — 2026-09-16

Release gate, RG-006: deleting, made production-safe.

### Its own authority
`local/catquizlab:purge`, carrying `RISK_DATALOSS` and granted to managers. It
was `:execute` before — the same capability as starting a run and stopping a
worker. Someone who may operate the lab can now do their whole job without ever
being able to destroy a measurement, and whoever may destroy one was given that
deliberately. Tested: an operator with `:execute` and without `:purge` is
refused.

### The name, typed
A button that only needs a click is a button that gets clicked, and this one
removes runs, attempts, people, generated questions and adaptive quizzes. The
confirmation names what goes and asks for the experiment's name in a field; a
mismatch deletes nothing.

### A worker is asked, not overruled
`force` used to mean "delete anyway". Forcing past a worker mid-attempt strands
the claim it holds and leaves a browser playing a quiz whose questions are being
deleted underneath it.

The delete now asks every worker holding a live claim on the experiment to
finish its attempt and stop, and refuses:

    Löschen: ok=false, Grund=workers-stopping, gebeten=1
    Stopp angefordert nachher: ja
    Experiment existiert noch: ja

The stop request is the mechanism already built for graceful shutdown: the
worker reads it at its next heartbeat, finishes the attempt it is playing,
reports it and exits.

### Verification
PHPUnit 620 tests / 3428 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. The refusal above is a reading from
this instance.

### Still open from the release gate
RG-002, RG-008, RG-010 to RG-013, and the CI query budget from RG-014.

---

## [0.6.35] — 2026-09-16

Release gate, RG-003: a self-test that tests.

### The readiness step added up flags
Every check on the preparation tab reads configuration: a token exists, a path is
executable, a plugin is installed. All six can be green on an installation where
nothing runs, because "the browser is installed in the cache the worker reads"
and "the browser starts" are different claims and only the second matters.

### Six things done rather than read
    ✓ Worker access is configured
    ✓ Web service answers the stored token      — called, with the stored token
    ✓ Node and the worker dependencies
    ✓ Browser starts                            — started and closed cleanly
    ✓ Experiment course is usable
    ✓ Pipeline task executes                    — run, in this request

It is slow — seconds, because it starts a browser and runs a task — which is why
it is a button, and why the result is kept for the page rather than recomputed
on every load.

### What it found immediately
Run against an installation whose six configuration checks were green, it
reported two blockers: the scheduled task was disabled, and the web service did
not answer.

The second was the more interesting one. Moodle's own `curl` blocks local
addresses, which is right for a URL a user supplied and wrong here — this is the
site calling itself at the address the worker is configured to use. Without the
exemption the check reports "The URL is blocked" as a service failure, which
sends somebody looking at the token. The response text is now included in the
failure, because "The URL is blocked" and an HTML login page are different
problems and the text is what tells them apart.

### Verification
PHPUnit 618 tests / 3419 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. The six-green reading above is from
this instance, after fixing the two the test found.

### Still open from the release gate
RG-002, RG-006, RG-008, RG-010 to RG-013, and the CI query budget from RG-014.

---

## [0.6.34] — 2026-09-16

Release gate, RG-014: where the queries go, and where they stopped going.

### Found
`is_visible()` asks the engine for a scale's item list, and the pool check calls
it once per item — while the items of a run sit on a handful of scales. That is
the thirty-nine queries per item, and the half million against a pool of
fourteen thousand.

The engine's answer is held for the request now. Measured on a pool of 120
items:

    checking an existing pool:  4680 queries  →  140 queries
                                39 per item   →  1.17 per item

### Not fixed, and why
Creating items is unchanged, at about 41 queries each. The verification there
deliberately runs per item and against a freshly purged cache — it exists to
catch an engine snapshot taken while a scale held one item fewer, which is a
real defect this codebase has had. Holding an answer across the write that
changes it is exactly what that check is for.

Reducing that path means batching the verification to the end of the stage,
which trades a per-item error location for speed. That is a decision about
diagnostics, not an optimisation, and it is not made here.

### A regression I introduced and measured
The first version of this cache did hold the answer across the write. The run
failed with `engine-item-not-visible`: 120 items planned, 6 visible — each scale
answering from the list it had when its first item was written. The invalidation
now sits beside the engine's own cache purge, where it belongs, and a test pins
both halves.

### Verification
PHPUnit 618 tests / 3418 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. Both numbers above are readings from
this instance, before and after.

### Still open from the release gate
RG-002, RG-003, RG-006, RG-008, RG-010 to RG-013, and the CI query budget from
RG-014.

---

## [0.6.33] — 2026-09-16

Release gate, RG-007: the database refuses a second root.

### Why the old key could not
`UNIQUE(runid, catscaleid)` prevented the same physical scale appearing twice
under one run, and that was never the problem. Two generations have two
different scale ids, so as far as that key is concerned both are perfectly valid
roots — which is exactly how run #4 came to own roots 334 through 1444.

A logical key expresses what was meant: `nodekey` — `root`, `c1`, `c1s2` — with
`UNIQUE(runid, generation, nodekey)`. Measured: the second root insert is
refused by the database rather than by any code that has to remember to check.

### Ambiguity blocks, it does not get resolved by guessing
`current_root()` returns nothing when a run owns several generations, rather
than picking the newest. Picking the newest looks reasonable and is a guess: the
run's items were materialised into one of them, and which one is not knowable
from the map alone. An attempt played on the wrong tree produces a wrong answer
nobody can detect, which is worse than no answer.

So such a run hands out no work at all — `is_runnable()` refuses it, the test
stage refuses it, and the interface says what to do about it.

### A dead end, removed
A run in that state hands out no work, so any attempt already claimed on it can
never finish. The cleanup refused to run while attempts were open — which made
repair impossible for exactly the runs that needed it.

It now asks the right question: is a worker *alive and holding* one. Stranded
claims are handed back, and the repair proceeds. A live worker still blocks it,
because which of the trees it is reading from is not worth guessing.

### Verification
PHPUnit 616 tests / 3414 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. Measured end to end: two generations →
not runnable → cleanup → one generation → runnable.

### Still open from the release gate
RG-002, RG-003, RG-006, RG-008, RG-010 to RG-014.

---

## [0.6.32] — 2026-09-16

Release gate, RG-005: a reset that leaves nothing behind.

### The problem
`reset()` cleared the lab rows — attempts, people, items, the scale map — and
left the engine objects standing. That is worse than leaving both: clearing the
scale map while leaving the scales behind loses the record of which scales
belonged to which run, and an engine object nobody owns is not a leftover. It is
a scale a later selection can still find.

### The reset now takes what provisioning made
Measured on a freshly provisioned run:

    removed: activity 1, questions 24, engine scales 4, tasks 1,
             attempts 2, people 2, items 24, scale map 4

    engine scales afterwards: 0 of 4
    questions afterwards:     0 of 24

Its queued tasks go too — one that wakes up to provision a run that has been
reset would provision it a second time.

**Kept, on purpose:** the execution log, the cell key and the seed. The first is
what went wrong last time, which is what somebody needs while looking at the next
attempt; the other two are what make this run this run.

### A preview, and one action instead of two
The confirmation names what would go and what would stay, rather than warning
that it cannot be undone.

**Reset and run again** is one action. The two halves were always done together
and never as one thing, so a reset that succeeded and a start that was forgotten
looked exactly like a run nobody had touched.

### Fixed while testing
The preview refused what the reset itself would have allowed — it checked for
open attempts where the reset checks for open attempts *on a running run*. A
preview that refuses what the action permits teaches people to ignore the
preview.

### Verification
PHPUnit 611 tests / 3391 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean.

### Still open from the release gate
RG-002, RG-003, RG-006 to RG-008, RG-010 to RG-014.

---

## [0.6.31] — 2026-09-16

Release gate, RG-009: access asked as the simulated person would ask it.

### The failure this ends
Provisioning enrolled people, created an activity and checked that the objects
existed. They did. The course was hidden, so every enrolled student was told the
course was unavailable — and the worker logged in correctly, reached the
activity and found that sentence where the start button belonged.

What came back, ninety seconds later, was:

    No question was presented; the attempt never started.

True, and three steps from the cause. An access failure and a missing question
need completely different responses, and only one of them is about the test.

### Added
`access_readiness` asks Moodle the questions the user's own browser will ask,
before the run is called READY, through one of its simulated people — one, not
all of them, since they are provisioned identically and asking the same six
questions two hundred times answers nothing the first did not:

    ✓ Simulated user account is active
    ✓ Experiment course is visible to students
    ✓ Simulated user is enrolled and active
    ✓ Course is accessible to the simulated user
    ✓ Adaptive quiz is visible to the simulated user
    ✓ Simulated user may attempt the quiz

Eight codes rather than one failure, because each has its own repair:
`course-hidden`, `course-not-accessible`, `user-not-enrolled`,
`enrolment-suspended`, `user-inactive`, `activity-not-visible`,
`availability-restricted`, `missing-attempt-capability`.

On the reported shape:

    course-hidden — The experiment course is hidden. Its enrolled students are
    told it is unavailable, and the worker finds that sentence where the start
    button belongs.

It runs as a provisioning stage between readiness and the attempts: a run whose
people cannot open the activity should not have attempts made for them.

**Repair** undoes what this plugin caused — a course it made and hid, an
activity it hid — and reports the rest. A removed capability or an availability
restriction is a decision somebody made, and undoing a decision quietly is worse
than reporting it. Tested in both directions.

### Verification
PHPUnit 608 tests / 3382 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. Measured on this instance: hidden
course → `course-hidden` → repaired → reachable.

### Still open from the release gate
RG-002, RG-003, RG-005 to RG-008, RG-010 to RG-014.

---

## [0.6.30] — 2026-09-16

Release gate, first three: RG-001, RG-004, RG-015.

### RG-001 — One shell per page, structurally
`experiment.php` rendered the frame three times, `compare.php`, `import.php` and
`report.php` twice: several pages call it from more than one branch — a
confirmation dialogue and the main output — and a branch that falls through to
another produced two tab rows and two experiment selectors, giving the reader
two places to answer the same question.

Fixed in the frame rather than per page, because per page is a fix that comes
undone the next time somebody adds a branch: it renders once per request and
returns nothing after that.

The experiment editor now takes the experiment it is editing as its context,
rather than whatever the URL happened to carry. Opening an editor is choosing an
experiment.

### RG-004 — The PHP CLI path gates one path of three
Treating it as a hard engine prerequisite was too strict. A task can be made to
run three ways, and they do not fail together: Moodle cron runs everything it
needs without it, this plugin's own "run now" executes the task in-process, and
only Moodle core's run-now shells out.

So it sits beside the pipeline now, reported for what it actually gates, and
readiness no longer refuses over it. It is also validated rather than merely
found: the binary is asked for its SAPI and version, because a path to the FPM
or CGI binary runs and then behaves differently enough that a task using it
fails in ways nobody traces back to here.

    /usr/bin/php (PHP 8.3.6, CLI)
    /usr/bin/php-fpm runs, but reports itself as "fpm-fcgi" rather than CLI

### RG-015 — Durations read as time
    failed before, retrying in 30720 s      →  ... in 8 hours 32 mins

`format_time()` is gone from every call site, replaced by a `duration` helper
that wraps it for the two cases it handles badly: a measured step below one
second, where "0 secs" loses the measurement, and zero, where the reader wants
words rather than a count. Applied to retry delays, task due times, cron age,
heartbeats, claim ages and stage durations.

### Verification
PHPUnit 602 tests / 3369 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. The retry delay above is a real reading
from this instance with `faildelay` set to the reported value.

### Still open from the release gate
RG-002, RG-003, RG-005 to RG-014. The three closed here were chosen for being
defects with a definite shape; the rest are substantial pieces of work — a
guided plan process, a real end-to-end selftest, ownership-complete reset,
production-safe purge, a database-level scale invariant, access readiness from
the simulated user's view, debug correlation, region-level live updates, the
results package, and the query-rate reduction with a CI budget.

---

## [0.6.29] — 2026-09-14

Issue #68: the scale tree answers for itself.

### The old message was about a database call
    mdb->get_record() found more than one record!

That is a DML warning, arriving at the test stage, about a query. What it meant
was: run #4 owns several root scales and several CAT contexts — a statement
about the run, true since the moment the second tree was created, and available
long before anything tried to build a test on top of it.

### Added
`scale_health` asks the whole question rather than the part that happened to
throw, per run, and reports it in the plugin's own terms:

    Scale tree consistent: 1 context, 1 root, 4 nodes
      ✓ Exactly one root scale
      ✓ Exactly one CAT context
      ✓ Every mapped scale exists in the engine
      ✓ No duplicate nodes
      ✓ Node count matches the blueprint

and on the reported shape:

      × Exactly one root scale                  2 root scale(s) found.
      × Exactly one CAT context                 2 context(s) found.
      × Every mapped scale exists in the engine  0 of 2 present.
      × No duplicate nodes                      1 duplicate node(s).
      × Node count matches the blueprint        2 nodes, blueprint calls for 4.

Each check is separate because each has a different answer: a missing subscale
and a stray extra one are not the same problem, and a map row pointing at a
deleted scale is worse than a missing row — everything downstream then
materialises into a scale nobody can select from.

It runs **before** the test stage, where the fact was already true, rather than
being discovered while building on top of it. The run view shows it beside the
scale generations.

### Verification
PHPUnit 599 tests / 3362 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. Both outputs above are real: the
inconsistent one from the reported shape staged here, the consistent one from a
fresh provisioning.

---

## [0.6.28] — 2026-09-14

Issue #63: one recording instead of seven logs.

### The problem
Diagnosis was spread over Moodle notifications, ad-hoc task logs, worker logs,
the run manifest, the operations page, database state and Moodle's own
debugging. Each holds a fragment; none holds the order. So a defect could not be
read as what it is — somebody pressed a button, a handler ran with certain
parameters, something changed, an error came back.

### Added
`debug_trace`, a switch on the settings tab and a console on step 3. Five
channels — `ui`, `service`, `task`, `worker`, `lifecycle` — in one sequence:

    ui         provision        ok      {"runid":39,"sesskey":"(hidden)"}
    lifecycle  start_requested  ok
    lifecycle  run_failed       ok      {"reason":"pool zu klein"}
    task       orchestrate      error   moodle_exception at run_orchestrator.php:214

Every run state change already went through `run_log`; it now appears on the
debug channel too, so the console shows a run's transitions interleaved with the
actions that caused them. That interleaving is the point: it is the order
somebody reads a defect in.

Three things keep the recording from becoming its own problem:

- **Off by default.** An installation that records every action all the time is
  one where nobody reads the recording.
- **A ring buffer of 2000.** The question is "what just happened", and a table
  that grows without limit answers it worse the longer it runs.
- **Secrets recorded as present, not as their value.** Knowing a token was sent
  is diagnostic; knowing which token is a liability. Tested.

Recording can never be why something fails — a plugin that breaks while writing
about itself is worse than a defect nobody can reconstruct. Verified with an
action name far longer than its column and a non-scalar parameter.

### Verification
PHPUnit 596 tests / 3353 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. The sequence above is a real recording
from this instance, including the redaction.

---

## [0.6.27] — 2026-09-14

Issues #66 and #67: one run, one scale tree — and the installations that already
have more.

### #66 — The ambiguity is reported where it is
`root_scale()` used `get_field()`, which throws when a run owns more than one
root. The failure arrived at the test stage, long after the second tree was
created, with a message about a database call rather than about the run:

    mdb->get_record() found more than one record!

`scale_inventory` answers the question instead of assuming it away. It names the
generations a run owns, picks the newest as current — chosen the same way
everywhere, because picking by iteration order would make "the root" mean
whatever came back first — and records the ambiguity in the run log where it is
found rather than where it happens to be noticed.

### #67 — Cleaning up what is already there
0.6.19 stopped new duplicates appearing and did nothing for installations that
already had them. Your run #4 owned generations from root 334 to root 1444.

Reproduced here and cleaned:

    root 556  context 7  2 nodes   <- current
    root 445  context 6  2 nodes      abandoned
    root 334  context 5  2 nodes      abandoned

    cleaned: 2 generations, 4 nodes, 4 engine scales
    afterwards: one tree, root 556

The engine rows go with the abandoned generations: a scale nobody points at is
worse than no scale, because a selection that finds it draws items from a tree
the run left behind. A run being played is refused — which tree its worker is
reading from is not worth guessing.

Step 3 lists affected runs across the installation; the run view inventories its
generations and offers the cleanup, with the preview naming what would go.

### Verification
PHPUnit 590 tests / 3334 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. The reported shape — three generations,
roots 334/445/556 — was reproduced, cleaned, and checked back to one.

---

## [0.6.26] — 2026-09-14

Issues #64 and #65.

### #64 — The PHP CLI path, checked where it matters
Moodle's scheduled task administration needs `$CFG->pathtophp`, and when it is
empty a task that shells out simply does nothing — indistinguishable, from the
outside, from a task that is disabled.

Step 1 checks it now, using Moodle's own canonical setting rather than a second
one of ours: two fields for one path is how they come to disagree. The three
cases get three answers, because they need three different responses:

    not configured, and a usable PHP found at /usr/bin/php
    configured as /opt/php, which does not exist
    configured as /opt/php, which the web server user cannot run

Where it is unset and a binary was found, the step carries a button that sets
it — guarded by `moodle/site:config`, because it is that person's setting.

### #65 — Deleting an experiment, with a preview
`purger::delete_experiment` existed since 0.6.19 and had no way into it from the
interface. It has one now, and it says what it will do first:

    Delete experiment "Smoke Test 2" with all of its runs and results?
    1 run(s), 3 attempt(s), 2 simulated person(s), 24 item(s), 1 adaptive quiz

An irreversible action should be able to name the things it takes rather than
only warn that it cannot be undone. A run being played is named in the preview
too — before the button, not after it.

Deleting a run now takes its execution log with it. That is the opposite of the
reset case deliberately: a reset must keep the log, because the run survives to
be looked at again; a delete must not, because keeping a history of something
nobody can open is not keeping anything.

### Verification
PHPUnit 585 tests / 3315 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. The PHP path was measured through its
own action: empty, detected, set, and green on the next check.

---

## [0.6.25] — 2026-09-14

Issues #61 and #62: what happened to a run, kept — and where the queries go.

### #61 — A persistent execution and recovery log
A failed run's story was spread across the Moodle task log, the run manifest,
the worker log, the interface and the database. A reset destroyed most of it,
which is the wrong moment to lose it: what went wrong on the last attempt is
exactly what somebody needs while looking at this one.

`local_catquizlab_runlog` is append-only and numbered by execution attempt.
Resetting a run starts attempt 2 rather than erasing attempt 1 — measured: 17
entries before, all 17 still readable after, and the new attempt beginning
beside them.

Sixteen events are recorded across a normal provisioning, from
`start_requested` through each stage to `provisioning_ready`. Logging can never
be why something fails: a missing table or a bad write returns quietly, because
a run that completes without its story is worse than one with it and far better
than one that dies trying to write it.

### #62 — The 549,727 queries have an address
Every provisioning stage is now measured. On this instance:

    scales              13 queries    0.01 s
    materialise        937 queries    0.70 s
    container           18 queries    0.01 s
    people              89 queries    0.15 s
    test               163 queries    1.12 s
    readiness            6 queries    0.00 s
    attempts             7 queries    0.00 s

`materialise` is the whole story: about 39 queries per item. The reported
549,727 is that same rate against a pool of some fourteen thousand — a big
number, and not a different problem.

So the budget is per stage and per unit where the stage scales, and it exists to
notice a change in the rate rather than to police a total: 937 queries for 24
items passes, 5,000 for the same 24 does not, and 900 queries to create one
course is flagged where the same number materialising a pool is not.

The run view shows the log with its costs, over-budget steps marked.

### Verification
PHPUnit 582 tests / 3305 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean.

---

## [0.6.24] — 2026-09-14

A CI failure of my own making, and issue #57.

### CI — the emptiness test failed because it ran
`schema_test` refuses empty directories in the plugin tree, added in 0.6.18
after 123 foreign ones shipped in every release. On Moodle 5.x, PHPUnit places
`.phpunit.cache` inside the plugin — so the test failed because the run that
executed it had created a directory for itself.

The check now skips what the tooling makes while it works: `node_modules`,
`vendor`, `.git`, and the PHPUnit caches. Verified in the failing direction by
creating `.phpunit.cache` first.

Moodle 4.5 does not put it there, which is why nothing here caught it. That is
the cost of a one-version local environment, and the reason your CI runs matter.

### #57 — One experiment selector, not two
The results page carried its own experiment dropdown while the shell carried
another, on every step. Two selectors for one thing invite each other to
disagree, and make "which experiment am I looking at" a question with two
answers on one page.

The shell's selector is the only one now; the results page receives the choice
through the URL and keeps it in a hidden field so its own filters — tier, model,
strategy, variant, stratum, severity — submit against the current experiment
rather than resetting it. Those filters stay: they are the experimental
coordinates, which is a different question from which experiment.

### Verification
PHPUnit 577 tests / 3287 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. Confirmed in the browser: the results
page has exactly one `experimentid` selector, and it is the shell's.

---

## [0.6.23] — 2026-09-14

Issue #56: step 3 becomes the operations view it was supposed to be.

### The problem
A tab for "what is happening" did not exist. The parts of an answer were spread
across four pages — runs on one, tasks and workers on another, the queue on a
third, recovery actions on a fourth — so answering "why is nothing moving"
meant visiting all of them and holding the pieces together yourself.

### Added
`progress_view` and its template, on step 3, in the order things block each
other:

    1. Runs and provisioning   what should be happening
    2. Tasks and pipeline      what carries it
    3. Workers                 who does it
    4. Queue                   what is waiting
    5. Recovery                what to do when it is stuck

Every row uses the same state–reason–action contract, so a run, a worker and the
queue say their piece the same way. The run section shows only what is not
finished — this section answers "what is happening", and a finished run is not —
while the filterable list below it still covers everything.

What it reads like on this instance, with cron off and no worker:

    Run #6                     ✓ Run finished
    Tasks and pipeline         × Pipeline not running — cron has never run
    Workers                      No worker is registered
    Attempt queue              × 2 attempt(s) cannot be claimed
                                 Their runs are failed, cancelled or not ready

### Caught while building it
- The new template's example context contained `}}` inside a nested object,
  which is exactly the trap described in 0.6.21 — the linter cuts the docblock
  there. My own test caught it before the CI did, which is what it was for.
- A recovery button posted `release`, an action nothing handles; the real name
  is `releaseorphans`. Every action in the template is now checked against what
  `operations.php` answers.
- Replacing the run list with the new view would have taken the status filters
  with it. Behat noticed. The view sits above the list rather than instead of
  it.

### Verification
PHPUnit 577 tests / 3287 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean, 888 language strings per language, all
six template example contexts parseable by the linter's own extraction.

---

## [0.6.22] — 2026-09-14

Issues #54 and #55: the first two steps become processes.

### #54 — One process, six steps, each thing said once
Tab 1 showed the wizard and then six diagnostic cards that repeated it. Node,
dependencies, browser and base URL appeared in the wizard and again below —
technically more complete, and harder to read for it.

It is one process now:

    1. Systemvoraussetzungen   2. Experimentumgebung   3. Worker-Zugang
    4. Worker-Runtime          5. Pipeline             6. Startbereit

The eleven access checks and the four runtime checks are steps of it rather than
summaries with cards restating them. Step 6 is not a check: it answers the
question somebody came to the tab with, because five green rows are an argument
for readiness and not a statement of it.

**The action belongs to the step it fixes.** Removing the cards nearly removed
the only way to set up worker access with them — the button lived in the card,
not in the step it was about. Each incomplete step now carries its own action,
which is where it belonged.

Verified in the browser: Node.js, Worker access and Base URL appear exactly
once each.

### #55 — Tab 2 asks one question
The quick-action bar offered five buttons, two of which — "All runs" and
"Results" — led into steps 3 and 4 that the tabs above already reach. A row of
buttons leading out of a step is not process guidance; it is a second navigation
disagreeing with the first.

One primary action remains: define an experiment. Presets and import stay as
secondary offers, because they are ways of *starting* a definition rather than
peers of it.

The worker fleet and attempt queue panels moved out: this step answers "what
shall be run", and a panel about what is running now answers something else. The
setup warning and the state line went too — the shell says both on every page,
and two identical banners one above the other is how a reader learns to skip
both.

### Verification
PHPUnit 575 tests / 3277 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean. Nine Behat scenarios navigated through
the removed buttons and now go through the shell tabs, which is the navigation
they should have been using.

---

## [0.6.21] — 2026-09-14

CI made green locally, and the navigation rebuilt around the work: #52, #53.

### The CI tooling now runs here
Two things I had been unable to reproduce locally are reproducible now:
PHP_CodeSniffer 3.13.2 with the real Moodle standard, and the mustache linter
from moodle-local_ci.

**phpcs reports nothing.** The violations the CI found were in 0.6.17; the ones
still left in the working tree are fixed, including three that came from a stray
frame render my own edit script had dropped into a confirmation branch.

**The mustache failure is explained.** `moodle-plugin-ci mustache` reads the
docblock non-greedily — everything between `{{!` and the *first* `}}`. An
example context that parses perfectly here can be cut short there, which is a CI
failure with no local symptom. `templates_test` now reproduces that extraction
exactly, so the next one fails here instead.

### #52, #53 — one frame, four steps, everywhere
The navigation existed on `index.php` and nowhere else, so opening a run dropped
the reader out of the process they were in the middle of. And it was cut by
object — "experiments and runs", "settings" — so no place meant "what is
happening right now".

`output\shell` renders on every page:

    1. Vorbereitung   2. Experimentenplan   3. Verlauf   4. Ergebnisse

Settings is not a step; it is a link in the header, reached from preparation
where somebody setting an installation up is already looking. The chosen
experiment travels with the reader through a selector on every step, so moving
between them does not mean choosing it again. The state line sits above the tabs
on every page, with the same state–reason–action contract as everything else.

Verified in the browser on index, setup, runs, results and presets: four steps
each, the right one active.

### Verification
PHPUnit 575 tests / 3284 assertions, Behat 32 scenarios / 232 steps, phpcs with
the Moodle standard clean, PHPDoc clean.

The end-to-end run was not repeated after this change — the built-in web server
this container uses for it did not survive the test sequence. Provisioning was
confirmed after the rebuild (4 scales, 24 items, 2 persons, 2 attempts); the
worker leg was not.

---

## [0.6.20] — 2026-09-14

The chain runs end to end.

### The last blocker: the experiment course was hidden
0.6.9 created it with `visible = 0`, which looked tidy and was the reason no
attempt could ever be played. A hidden course tells its enrolled students *"this
course is currently unavailable"* — and the simulated persons are enrolled
students. The worker logged in correctly, reached the activity, and found that
sentence where the start button should have been.

Every symptom above it was a consequence: "no question was presented", the page
reported as the dashboard, attempts cycling back into the queue. The course is
visible now and kept out of the way by its category and its name instead, which
costs nothing.

### Measured, not asserted
A full run on this instance, from an empty installation:

    Setup            all four stages green in one action
    Experiment       1 experiment, 1 run
    Provisioning     4 scales, 24 items, 2 persons, 2 attempts
    Worker           1 started (1 claimable attempt, concurrency 1)
    Attempts         2 played, 12 items each
    Traces           theta -0.003 (SE 0.603) and -2.146 (SE 1.054)
    Aggregation      28 result rows
    Evaluation       true -0.479 → -0.003 (error +0.476)
                     true -1.778 → -2.146 (error -0.368)
    Run status       FINISHED
    Status card      [good] Run finished — 2 of 2 attempt(s) collected

### Also fixed on the way
`gotoSettle()` swallowed both navigation attempts, so a failed navigation left
the page where it was and the next step reported what it failed to find there.
It now names the URL it wanted and the one it landed on.

### Verification
PHPUnit 563 tests / 3236 assertions, Behat 32 scenarios / 232 steps, PHPDoc
clean. One existing test asserted the course was hidden; that expectation was
the defect, and it now asserts the opposite with the reason.

---

## [0.6.19] — 2026-09-14

Three P0 defects that stopped provisioning working at all: issues #60, #59, #58.

### #60 — Readiness threw on every provisioning
`run_stage()` reached for an undefined `$runid` in the readiness stage, so the
stage I added in 0.6.15 raised a fatal error every time a run was provisioned.
It uses the shared context now, like every other stage.

The tests did not catch it because they call `cat_readiness` directly rather
than through the stage that uses it. There is now a test that dispatches the
stage.

Behind it, a second one: `cat_readiness` threw when a run had no usable
definition. A readiness check that throws is worse than one that fails — the
caller gets an exception where it expected a verdict. It returns a stated
verdict now.

### #59 — Provisioning built a second scale tree each time
`scale_provisioner::provision()` created scales unconditionally. A retried
ad-hoc task, a "provision now" after one, a recovered run — each produced
another root and another set of subscales, and items materialised into whichever
map was named later. It reuses what the run already has, and checks that against
the engine rather than trusting its own map: a map row pointing at a deleted
scale is worse than no map, because everything downstream then materialises into
a scale nobody can select from.

### #58 — No way back from a stuck run
Re-checking suits a run whose cause was fixed outside it. **Reset to draft** is
for the other case: a run that is wrong in itself. It removes what provisioning
made — attempts, people, scale map, items, results — and keeps what the run *is*:
its cell and its seed. A run being played is refused, because resetting
underneath a worker strands the claim it holds.

### Also
`gotoSettle()` in the worker swallowed both of its navigation attempts, so a
failed navigation left the page wherever it was and the next step reported what
it failed to find there. The symptom was "no question was presented" on the
dashboard — true, and three steps from the cause. It now reports the URL it
wanted and the one it landed on.

### Verification
PHPUnit 562 tests / 3235 assertions, 11 worker tests. Each defect measured
against the running instance: the readiness stage dispatches, provisioning twice
yields one root scale, and a failed run resets to draft with its cell and seed
intact.

---

## [0.6.18] — 2026-09-14

Directories that were never ours, shipped in every release.

### The finding
`catmodel/` and `catquizcentralhub/` are subplugin directories of
`local_catquiz`. Empty copies of their whole tree — model folders, `classes`,
`tests`, `lang`, `host`, `client` — sat in this plugin's root and rode along as
**123 empty entries in every zip I delivered**.

They arrived through a source archive and came back after a restore, because I
removed them once by hand and nothing stopped them returning. A directory named
after a subplugin type, sitting in a plugin root, is also an invitation to be
scanned as one.

Twelve further empty directories went with them: leftovers of a removed AMD
module, of template folders, of a `doc/` beside the real `docs/`, and a
`downloads/` holding nothing. Git does not track empty directories, so these
existed only where somebody unpacked a zip — and then travelled into the next
one.

### Added
`schema_test` now refuses both: the two foreign names explicitly, and any empty
directory anywhere in the tree. Verified in both directions — recreating
`catmodel/rasch/classes` fails the test with "belongs to local_catquiz, not to
this plugin".

Removing them by hand is what I did the first time, and it lasted until the next
archive.

### Verification
PHPUnit 557 tests / 3219 assertions.

---

## [0.6.17] — 2026-09-14

Issue #51: one shape for every stateful thing.

### The problem this fixes is one I made
Ten issues of point repairs left each component saying its piece its own way: a
run showed a status word, the queue a number, a worker a count, a task a class
name. Every one more accurate than before, and the reader still had to hold four
vocabularies at once to answer "is anything wrong". "Scheduled", "1 worker",
"150 waiting", "0%" — all true, none actionable.

### Added
- **`status_report`**, one contract for runs, workers, the queue and the
  pipeline:

      state   — what it is, in words that mean something on their own
      reason  — the evidence: which task, which attempt, since when, how many
      action  — the one thing to do, or nothing

  The contract is deliberately narrow, because one that allows exceptions is a
  style guide. A healthy component has no action, and that is a statement rather
  than a gap: buttons on healthy things teach people to press buttons.

- **`statuscard.mustache`** renders it, so the shape is the same everywhere by
  construction rather than by discipline.

### What it reads like now
A scheduled run was "Scheduled". It is:

    Provisioning pending
    Waiting for Run provisioning, due now. Cron has never run on this site.
    [ Provision now ]

A ready run with work and no worker was "Running, 0%". It is:

    Waiting for a worker
    3 attempt(s) claimed by nobody.
    [ Start workers ]

A worker was "1 worker". It is `Attempt #57 of run #4, heartbeat 2 secs ago`, or
`Worker idle — no claimable attempts`, which answers the question the first
version invited.

A queue of attempts belonging to failed runs was "150 waiting", which reads as
"a worker will get to it". It is `5 attempt(s) cannot be claimed — their runs
are failed, cancelled or not ready`.

### Verification
PHPUnit 556 tests / 3216 assertions, Behat 32 scenarios / 232 steps, PHPDoc
clean. Seven of the new tests check the contract itself: every card carries all
three parts, lands in exactly one level, and a healthy one offers nothing to
press.

---

## [0.6.16] — 2026-09-14

Issue #46: the tasks everything waits on, in the plugin's own terms.

### The problem
Six Moodle tasks carry this plugin, and all of them are visible in Moodle's task
administration — which is the problem. An ad-hoc task there is a class name
beside a blob of JSON, so answering "is run 4 waiting for something, and for
what" meant reading `{"runid":4,"options":[]}` out of a list of identical rows.

### Added
- **A tasks and pipeline section** on the setup tab: the scheduled task with
  when it last ran and when it is next due, and the queued ad-hoc work named
  after its subject — `Run #2 (strategy=classic)` rather than its custom data.

- **Cron is reported beside them.** A task that is enabled and never runs looks
  exactly like a disabled one from every angle except its last-run time, and
  cron not running is the most common reason a pipeline sits still. A scheduled
  task more than fifteen minutes overdue is called out: that is not slow cron.

- **Run now**, for this plugin's own scheduled tasks only — a general "run any
  task" button on a plugin page is a way to run somebody else's task by
  accident. The task's `mtrace()` output comes back with the result, since that
  is how these tasks say what they did.

- A link to Moodle's task administration, as the supplement it should be rather
  than the normal route.

### Fixed
The tasks panel first rendered with its headings and no rows: the view supplied
no `tasks` key, so every section was skipped and the page looked correct. A
missing key in Mustache renders as nothing at all, which is a failure mode that
shows up as a page that seems fine.

`templates_test` now checks that the operations context provides every top-level
name its template uses, following the section nesting so a name belonging to a
section's own data is not demanded of the context.

### Verification
PHPUnit 548 tests / 3181 assertions, Behat 32 scenarios / 232 steps, PHPDoc
clean. Measured against the running instance: two queued ad-hoc tasks rendered
as `Run #2` with their due times, and the scheduled task with its run button.

---

## [0.6.15] — 2026-09-14

The run lifecycle, from three directions: issues #50, #45, #47, #49 and #48.

### #50 — The claim did not move the run (P0)
The documented lifecycle is READY → first attempt claimed → RUNNING, and the
call that performs it was missing from `job_claim` entirely: a run stayed READY
while its attempts were being played. It happens inside the claim's transaction
now — a run whose attempt is being played must not look READY to anything
reading in between, and a rolled-back claim must not leave a run marked running.

The transition is conditional on READY, so a scheduled run cannot skip the state
that says its pool was checked, and it reports whether *this* call moved the run
rather than whether the run is running — a later claim repeating a first claim's
side effects was the failure waiting behind the old shape.

### #45 — A scheduled run was a dead end (P0)
"Scheduled, 0%, workers idle beside it" with no action that moves it is
indistinguishable from work in progress, and a run can wait for an orchestrator
task that was never queued or was queued while cron was down. **Provision now**
runs the same orchestrator the task would have run and lets it reach its own
conclusion — READY is not settable by hand, because it stands for a check that
passed.

### #47 — Workers started with nothing to do (P0)
Dispatch knew nothing about whether work was claimable. Starting a worker
without any costs a Node and a Chrome process, shows "workers running" beside 0%
progress, and ends as an apparent crash when the process exits having found
nothing — three misleading signals, multiplied by the configured concurrency.

The pool now starts no workers when nothing is claimable, and no more workers
than there is work for. Measured: one claimable attempt with concurrency 4
starts one worker; none starts none.

### #49 — `QUEUED` was read as "waiting for a worker" (P0)
It is a storage state, and an attempt in it can be claimable now, not due yet
after a failure, blocked because its run hands out no work, or held by a paused
run. Four things needing four responses, reported as one number — which invited
waiting for attempts that would never be picked up. `queue_breakdown()` splits
them, grouped by run so a queue of 1600 is not 1600 lookups.

### #48 — A working worker was indistinguishable from a dead one (P0)
It spoke only when claiming and when finishing, and an attempt takes minutes: a
working worker went quiet for exactly as long as the timeout that declares it
dead. It reports every twenty seconds now, with the attempt it is playing, and
the reply carries a stop flag.

Stopping is a request, not a kill: the worker is mid-attempt in a browser, and
ending the process there leaves a claim with nobody to finish it — the state the
leases exist to prevent. It finishes the attempt, reports it, and exits. A
worker the registry no longer knows is told to stop, because its slot may
already have been given away.

### Verification
PHPUnit 545 tests / 3162 assertions, Behat 32 scenarios / 232 steps, PHPDoc
clean. Each change measured against the running instance: READY 15 → RUNNING 20
on first claim, one worker for one claimable attempt, `no-claimable-work` when
there is none, and the heartbeat's stop flag answering true after a stop request
and for an unknown worker.

---

## [0.6.14] — 2026-09-14

Live updating, a four-stage front end, and the test environment rebuilt.

### The environment
The container was reset between releases and 0.6.13 had to ship on inspection
alone. Moodle 4.5.14, PostgreSQL, the engine on `ALiSe-v-1.2.0-legacy`, PHPUnit,
Behat with ChromeDriver and Puppeteer are all back, and everything below was
exercised against them.

**What that immediately caught:** the `situation` ranking added in 0.6.13 could
only be tested by building a whole installation into each state, which is a
ranking nobody tests. Gathering the facts (`assess()`) and judging them
(`rank()`) are separate now, and two cases are pinned that were only assumptions
before: an unready installation outranks a failed run, while work already in the
queue outranks an unfinished setup — attempts in the queue mean the installation
ran at some point, so the setup warning is the stale one.

### #42 — The overview keeps itself current
`local_catquizlab_live_status` returns counts and the one-line verdict, and the
`livestatus` module updates them in place. Measured in the browser: the queue
figure went from 7 to 5 with zero navigations while attempts were completed from
outside the page.

The page reloads itself only when the *verdict* changes, because that is where
the run rows, their progress and the buttons stop matching the counters —
patching all of that from JavaScript would be a second renderer.

**A defect only the browser test could show:** the service was declared in
`db/services.php` and never registered, because Moodle re-reads that file only
when the plugin version changes. The page polled, Moodle answered `Can't find
data record in database table external_functions`, and nothing on the page said
so. A test now checks the registration, not just the declaration.

Polling stops when the tab is hidden and after a long idle period: a tab left
open overnight should not keep a server busy.

### #44 — Tabs follow the work
`1. Set up`, `2. Experiments and runs`, `3. Results`, `Settings`.

Not included deliberately: sending an unready installation straight to the setup
tab. It was written, and Behat showed what it costs — 28 scenarios went red
because the page moved out from under them. Somebody who opens the plugin to
look at their experiments should find their experiments; the banner at the top
already says what is missing and links to where it is fixed.

### Verification
PHPUnit 534 tests / 3132 assertions, Behat 32 scenarios / 232 steps, PHPDoc
clean, 790 language strings per language, the AMD module built with Moodle's own
grunt. phpcs could not run here: the Moodle standard needs a PHP_CodeSniffer
version this container cannot resolve without Composer — 3.7 is too old for its
dependencies and 4.0 too new for the standard itself. Style was checked by hand
across the changed files (line length, docblocks, trailing whitespace, comment
form); CI will have the final word.

---

## [0.6.13] — 2026-09-14

Two defects introduced in 0.6.12, and the overview's one-line verdict.

### #40 — An empty worker log broke the operations page
`log_tail()` computed a read window from the file size and called `fread()` with
it. A worker that has just started has a log file and nothing in it — the normal
state for the first seconds of every run, not an edge case — and reading zero
bytes threw, taking the whole page with it. That page is the one somebody opens
when a worker is not behaving.

Fixed, and a second defect found while testing it: `filesize()` reads PHP's
cached stat data, and this file is written by a different process. Without
`clearstatcache()` the log of a worker that had just written its first lines
still looked empty. Both are covered by tests now.

### #41 — The run view overwrote its own data
`$detail` holds the run's data from `run_registry::detail()` and is read further
down for the reproducibility manifest. The failure-reason block added in 0.6.12
assigned an HTML string to the same name, so every later access read a character
out of that string instead of an array.

Renamed. `page_scripts_test` now checks every page script for the general shape
of this mistake — a variable used as an array that is also assigned a plain
string — because these files are long, procedural and share one scope, which
makes exactly this easy.

### #43 — The overview said four correct things that disagreed
It could show, at once: 150 attempts queued, no worker running, one crashed, the
experiment "running", its run "scheduled" at 0%. Every figure right; together no
picture. The reader had to work out that nothing was progressing, that the
crashed worker was why, and that starting one was the thing to do.

`situation` assesses the installation as a whole and says one sentence with one
action. The states are ranked by how much they need doing about them and the
first that applies wins — an overview that reports three problems makes the
reader rank them, which is the work this class exists to do. Waiting work with
nobody on it outranks a failed run, because somebody is waiting on the first.

A healthy state gets no button: an action on a healthy state trains people to
press buttons that do not need pressing.

### Not included: #42
Live updating needs a web service and an AMD module, and this container no
longer has the Moodle installation to exercise them in. Shipping an untested
AJAX layer into the page somebody watches during a run is the wrong trade, so it
waits for an environment where it can be verified.

### Verification
Reduced: the working tree was lost with the container, and the plugin was
restored from the 2026091311 release archive. PHP syntax is clean across all
files, language files match at 789 strings each, template example contexts parse
and carry every key the new block uses, and both defects were reproduced and
fixed against isolated runs of the affected logic. PHPUnit and Behat could not
be run.

---

## [0.6.12] — 2026-09-14

Five findings from real operation: issues #35 to #39.

### #35 — Readiness was strategy-blind (P0)
A valid run was refused before a worker ever started: 100 subscales at 3
questions each against a global maximum of 25, under `fastest`.

The multiplication is only correct where a strategy makes the per-subscale
minimum binding on every subscale, and in the engine exactly one does —
`inferallsubscales` overrides `filterbyquestionsperscale()`, the base class
returns the candidates unchanged. For every other strategy the minimum bounds
what may be taken from a scale the selection visits, not what must be taken from
all of them. The same correction applies to per-subscale caps and to empty
subscales: an empty scale among ninety-nine full ones is a scale `fastest` will
not pick.

The refusal now names the strategy it applies to, because a number that is wrong
under one strategy and right under another should say which.

### #37 — Failed runs left claimable work (P0)
Readiness ran after provisioning, so a run that could not start had already had
its queue built: 3 failed runs and 150 attempts still claimable. Three changes,
each sufficient on its own and all three kept:

- Readiness is a provisioning stage between the test and the attempts, so the
  queue is not built for a run that cannot use it.
- `fail()` closes the run's queued attempts with the run's reason. Closed, not
  deleted: what was planned is worth knowing.
- `job_claim` checks the run's status server-side. Only `READY` and `RUNNING`
  hand out work — however attempts got into the queue.

### #38 — The failure reason was recorded and never shown
`lifecycle.failedreason` had been written since 0.6.1 and read by nothing, so a
run said FAILED and the reason sat in its manifest where only database access
found it. The run view shows it now, with the readiness counts beside it: "2
usable items against a minimum of 4" is actionable, "not ready" is not.

### #39 — Worker output went to /dev/null
A worker that died on startup wrote its reason to stderr and it went nowhere;
the registry then showed a slot held by a process that no longer existed, with
nothing to say why. Output goes to a per-worker log now, and the last lines are
on the operations view next to the worker they belong to.

### #36 — Recovery without reproducing
A run that failed readiness because a pool was too small is not broken for ever.
"Re-check and resume" re-runs the check, and on success puts back the attempts
that were closed when the run failed — only those: an attempt that failed while
a worker played it keeps its history, because reopening it would discard a real
result.

### Verification
PHPUnit 498 tests / 3013 assertions, Behat 32 scenarios / 232 steps, phpcs and
PHPDoc clean, 780 language strings per language, all templates rendering from
their example context. The reported configuration measured directly: `fastest`
passes, `allsubs` is still refused with the arithmetic.

---

## [0.6.11] — 2026-09-13

One page, and the CI failures that followed it.

Everything an operator does was spread over three: experiments on the landing
page, setup and diagnosis on an operations page, settings in the Moodle
administration tree. Each one was reachable, and using the plugin meant knowing
which of the three held which half. Adding a link between them, as 0.6.10 did,
treats the symptom.


### CI fixes on top of the one-page change

- **The Mustache lint failed on seven empty form actions.**
  `moodle-plugin-ci mustache` renders every template against the example context
  in its docblock, and `formurl` was not in it — so the rendered HTML had
  `action=""`. The same omission in the real context is what made the setup
  buttons inert: an empty action posts to the current page, where no handler
  lives, so the button looks right and does nothing.

  `manage.mustache` had the same gap for `resultsurl` and `settingsurl`.

  `templates_test` now renders every template from its own documented example
  and fails on any empty href or action, so this is caught before CI rather than
  by it.

- **One PHPUnit failure, only on Moodle 4.5.** `make_writable_directory()`
  reports a failure through `debugging()`, which PHPUnit counts as an unexpected
  call. The directory is created directly with `0700` now — which it wanted to
  be anyway, since that helper uses `$CFG->directorypermissions`, defaulting to
  `0777` across a dataroot. The warning is suppressed and immediately replaced
  by an exception carrying the path: nothing is swallowed, and the diagnostic
  channel is not used to report something the caller is told about properly.

### Changed
- **The plugin's own page carries three tabs**: Experiments, Setup and
  operations, Settings. The setup view renders inside it rather than on a page
  of its own, and `operations.php` remains only as the handler its forms post
  to, redirecting anyone who arrives there.

- **The settings that an operator turns are on the Settings tab**: experiment
  course as a chooser rather than an id, master switch, base URL, Node path,
  concurrency, maximum jobs. They stay in the Moodle settings tree as well —
  that is the right place for a site administrator configuring a plugin once,
  and the wrong place for somebody running an experiment.

  The node path is checked on save rather than discovered later by a worker
  that cannot start: the error is the same, but here it arrives while somebody
  is looking at the field. The token is shown read-only with its state, because
  an operator who can see it is empty understands why nothing runs — and typing
  one in by hand is what this page exists to make unnecessary.

### Fixed
- The setup forms posted to an empty action, so the buttons did nothing. Found
  by pressing them through the browser and then checking the database rather
  than reading the page: the page's own text said `token, storedtoken`, which
  was the list of what was *missing*, and could be read as a report of success.

### Verified along the path a person takes
Token cleared, then only the plugin's page: landing page → Setup tab → "Set up
worker access" → "Worker access is complete. Changed: token, storedtoken", 32
characters in the database, and that token calls `local_catquizlab_job_claim`
successfully. No detour through the Moodle administration at any point.

### Verification
PHPUnit 490 tests / 2981 assertions, Behat 32 scenarios / 232 steps, phpcs and
PHPDoc clean, every template rendered from its example context.

---

## [0.6.10] — 2026-09-13

The setup was built and not signposted.

0.6.8 made the worker access creatable in one click, and left the button on a
page an administrator has to already know about. Somebody standing in the plugin
settings in front of an empty token field saw nothing suggesting the plugin
could fill it — which is exactly where the ten-step manual sequence used to
begin. A capability nobody can find is not a capability.

### Added
- **The settings page states the access status above the token field**, and
  when it is incomplete says plainly not to create a token by hand, with a link
  to the page that creates it.
- **The token field's own description** names where it comes from.
- **The landing page warns when the installation is not ready**, lists what is
  missing and links to the setup. That is the first page anyone opens, and it
  was silent about an installation that could not run anything.

### Verified along the path a person actually takes
Token cleared to reproduce a fresh installation, then: the settings page shows
the notice and a link, the operations page's button runs the setup, the field
holds a 32-character token, and that token calls `local_catquizlab_job_claim`
successfully.

### Verification
PHPUnit 487 tests / 2960 assertions, Behat 32 scenarios / 229 steps, phpcs and
PHPDoc clean, 757 language strings per language.

---

## [0.6.9] — 2026-09-13

Issues #32, #33 and #34 — a fresh installation made ready from its own pages.

### #34 — A setup and readiness view
The pieces existed but were spread across the settings page, the operations
page and Moodle's own administration, so a fresh installation needed somebody
who knew the internal dependencies and the order to satisfy them in. That is
knowledge about this plugin's implementation, not about experiments.

`setup_wizard` answers one question in one place, in four stages ordered by what
depends on what: engine, experiment environment, worker, pipeline. Each step
says whether it holds and what would fix it; `run()` performs the fixes it can
and stops at the first stage it cannot complete — setting up a worker against a
missing engine produces a second failure that hides the first.

The pipeline stage is last on purpose. `pipeline_tick` ships disabled, which is
right: a task that hands out work should not start the moment a plugin is
installed. That is an argument for enabling it knowingly, not for making
somebody find it in the scheduled task administration — so it is offered here,
and only once the three stages it depends on are green. Cron itself is checked
beside it, because a task that exists and never runs looks exactly like a task
that is disabled.

### #33 — The experiment course creates itself
It is not a course in the ordinary sense: one section per experiment, one
adaptive quiz per run, everything generated, nobody teaching in it. Its
shortname, format and visibility follow from that role, which made asking an
administrator to create it first a question with one right answer.

`ensure_course()` adopts before it creates — an installation that already has
the course, from an earlier setup or a restore, must not end up with two, and
the second would silently hold half the experiments. A hidden category is
created alongside it, falling back to any category rather than failing the whole
setup over where a technical course sits.

### #32 — The worker runtime sets itself up
`worker_runtime` finds a usable Node, installs the npm dependencies (`npm ci`
where a lockfile exists, so the worker is the one that was tested), fetches the
browser into the cache the worker actually reads, and defaults the base URL to
`wwwroot`. Each through the same runtime environment as the worker, or they
install something nobody will find.

What is left for a shell is the operating system itself: Node has to exist and
be runnable by the web server user. The wizard says so in those words rather
than appearing to work on it.

An empty `chrome/` directory is not a browser — an interrupted download leaves
one behind, and the worker then fails as if nothing were installed — so the
check looks for an executable.

### Tests
Eight more: the four stages in dependency order, the wizard stopping without an
engine, the pipeline refusing to start over a broken setup, the course created
once and adopted when present, Node discovered without configuration, a wrong
Node path repairing itself, and a half-downloaded browser not counting as
installed.

### Verification
PHPUnit 487 tests / 2960 assertions, Behat 32 scenarios / 229 steps, phpcs and
PHPDoc clean, 752 language strings per language. Exercised on this instance from
an unset course and an empty browser cache: both were created, and the wizard
went from four blockers to one — cron, which does not run in this container.

---

## [0.6.8] — 2026-09-13

Issue #31: the worker's access to Moodle, set up by the plugin that needs it.

### The problem
Getting a worker running took ten steps across four areas of the Moodle
administration: enable web services, enable REST, enable the external service,
create a technical user, create a system role, grant two capabilities, assign
the role, authorise the user for the restricted service, mint a token for
exactly that pair, and paste it back into the plugin setting.

Any one of those missing produces the same symptom — a worker that claims
nothing — and there were thirteen listed ways to get it wrong. None of it is a
configuration decision: the service exists for this worker, its three functions
are this plugin's, and `local/catquizlab:worker` is granted to no role by
default precisely because it is not meant to be handed around.

### Added
- **`worker_access`** with two entry points, and the distinction matters:
  `verify()` only looks, so it runs on every page load, and `ensure()` changes
  the site when somebody asks. Idempotent by construction — every step checks
  before it acts, so running it after a partial manual setup completes that
  setup rather than duplicating it.

- **The access panel on the operations page** lists all eleven checks with an
  individual verdict, and offers one button when anything is missing.

### Notes on two decisions
- **A dedicated account, not the administrator's.** The token carries exactly
  the three functions the worker calls; if it leaks it is worth exactly that.
  An administrator's token is worth the administrator.
- **`auth = 'webservice'`, not `'nologin'`.** The first version used `nologin`,
  which looks equivalent and is not: the call came back
  `wsaccessusernologin`, which reads as a permission problem and is an
  account-type problem. Found by calling the web service with the token rather
  than by inspecting the rows — the setup verified as complete either way. The
  authentication plugin is enabled as part of the setup, since an account whose
  type is disabled is refused however correct everything else is.

### Tests
Five more: the whole access created in one operation, a second run changing
nothing, the token belonging to the technical account rather than to whoever
pressed the button, a partial setup completed without a second account or role,
and the role carrying both capabilities in the system context only.

### Verification
PHPUnit 479 tests / 2946 assertions, Behat 32 scenarios / 229 steps, phpcs and
PHPDoc clean, 716 language strings per language. End to end on this instance:
the setup ran from nothing to complete, and the resulting token then called
`local_catquizlab_job_claim` successfully.

---

## [0.6.7] — 2026-09-13

Issues #26, #27, #28, #29 and #30 — two of them defects in code written earlier
the same day.

### Security
- **The web service token no longer reaches the command line (#29).** It was
  passed as `--token=…`, where a process listing shows it to anyone who can run
  `ps`, and it opens every web service function the worker is allowed to call.

  The first fix was incomplete and the test caught it: moving the secret into an
  `env NAME=value` prefix only moves it from the worker's argv into env's own,
  which is just as visible. It is exported into the PHP process now and
  inherited by the child — `/proc/<pid>/environ` is readable by the owner and
  root, argv by anyone. The worker reads `CATQUIZLAB_WORKER_TOKEN` and still
  accepts `--token` for a manual run, where the person typing it already has the
  token in their shell history.

- **Runtime directories are no longer created world-writable (#30).** They were
  made with `@mkdir(…, 0777)`. They are now created through Moodle's own helper
  and then tightened to `0700` explicitly, rather than left to
  `$CFG->directorypermissions` — which defaults to `0777` across a dataroot.
  That default is reasonable for files a site serves and is not reasonable for
  a browser profile, its cookies and its cache.

  The error suppression is gone with it: a directory that cannot be created or
  written now fails where it happens, naming the path. Suppressed, it surfaced
  later as an EACCES from inside Puppeteer, and the reader debugged the browser
  instead of the file system.

### Changed
- **The engine is a declared dependency (#26, #27).** The note in `version.php`
  promised this — *"promote local_catquiz and mod_adaptivequiz to declared
  dependencies once the attempt runner exists"* — and the runner exists. The
  suite creates `mod_adaptivequiz` instances, writes `local_catquiz` test
  environments, materialises items into engine scales and plays attempts
  through the real activity; an installation without those plugins cannot do
  any of it. Versions are the ALiSe-v-1.2.0-legacy set, which is also the
  newest line that still supports Moodle 4.5.

### Added
- **The zero-question failure says what the page said (#28)**: url, title, the
  Moodle error or notification if there is one, otherwise the main region's
  text, plus the engine attempt id. "No question was presented" names the
  symptom and nothing else, and the cause is almost always on the screen the
  worker was looking at.

### Tests
Three more: the token absent from both argv and the assembled command while
present in the environment, the runtime directory not world-writable, and an
unusable runtime directory reported where it happens (skipped as root, which
ignores permission bits — saying so beats passing on a false premise). One
existing expectation was inverted: `worker_launcher_test` asserted the token
*was* in argv.

### Verification
PHPUnit 474 tests / 2918 assertions, Behat 32 scenarios / 229 steps, 11 worker
tests, phpcs and PHPDoc clean. Runtime directories verified at `0700` on disk.

---

## [0.6.6] — 2026-09-13

Issue #25: engine attempts that were created but never started.

### The defect
When the CAT selection fails before item one, `mod_adaptivequiz` throws and the
attempt row it had already written stays behind — `inprogress` with
`uniqueid = 0`, no stop reason, no finish time. It is neither a running attempt
nor a finished one; it is a record of a start that did not happen.

One is a curiosity. This instance had **20 of them among 167 attempts**, because
every retry of a failing attempt makes another: they accumulate rather than
appear. They distort attempt counts, resume paths can find them again, and where
an activity limits attempts they consume the allowance.

### Added
- **`engine_hygiene`** removes them, on the operations page and automatically in
  `pipeline_tick`. Scope is deliberately narrow: only attempts belonging to this
  lab's simulated persons, and only those with no question usage at all. A row
  with a `uniqueid` has answers attached and is somebody's data whatever state
  it is in — a plugin that deletes rows it did not create is worse than the
  defect it cleans up after.

  Deleted rather than closed: a closed empty attempt still counts as an attempt
  wherever attempts are counted, and it carries nothing worth keeping. The
  reason the start failed is on the lab attempt, where the worker put it.

- **`docs/design/issue-adaptivequiz-empty-attempt.md`** — the fix belongs
  upstream, and this is a workaround. The draft offers both designs: roll the
  attempt back before the exception, or close it explicitly with a stop reason
  and `resultvalid = 0`. Where the attempt count is limited, the rollback is the
  better of the two. The existing comment at that line already names the problem
  correctly — a leftover empty attempt should not be completed — but the branch
  then does nothing at all, while the branch beside it handles the normal case
  in full.

### Verified on this instance
20 empty attempts found and removed, the 147 real ones untouched.

### Verification
PHPUnit 471 tests / 2916 assertions, Behat 32 scenarios / 229 steps, 11 worker
tests, phpcs and PHPDoc clean, 694 language strings per language.

---

## [0.6.5] — 2026-09-13

Two P0 defects from real operation: issues #23 and #24.

### #24 — Runs are queued only once the CAT test can actually start

A structurally complete preflight passed — scales present, person parameters
present, enrolments present — and 1600 attempts were queued against a test that
could never select item one. Every job was going to fail identically before the
first question.

`cat_readiness` checks the arithmetic that decides this, before attempts are
queued:

- a test cannot ask more questions than its pool can answer;
- per-subscale minima multiply — twenty subscales at three questions each is
  sixty questions, whatever the global maximum says;
- per-subscale maxima cap the total the same way, so a global minimum above
  that sum is unreachable;
- an item the engine still treats as a pilot contributes nothing to the
  estimate, so a pool of pilots is an empty pool as far as selection goes.

Item counts come from the engine's own tables rather than from the lab's record
of what it created: the question is what the selection will see, not what was
intended. A run that fails the check goes to FAILED with the arithmetic in its
manifest — "2 usable items against a minimum of 4" is actionable where "not
ready" is not.

### #23 — The browser runtime is pinned, and fixable from the interface

A self-test passed as the interactive user while the worker failed as the web
server user with `Could not find Chrome`. Puppeteer resolves its cache from the
runtime of whoever runs it, so the two were never looking in the same place.

- `worker_launcher::runtime_environment()` pins `HOME`, `PUPPETEER_CACHE_DIR`
  and the three XDG paths, and creates the directories rather than assuming
  them — the failure they cause otherwise is an `EACCES` deep inside Puppeteer
  that reads as a plugin problem. Both launch paths and the self-test use it,
  which is what makes a green self-test mean something about the worker.
- **Run worker self-test** and **Install browser for the worker** on the
  operations page. The self-test now prints the user, uid and paths it ran
  with, so a run by hand and a run from cron are distinguishable in a report.

Verified end to end on this instance: the self-test first reproduced
`FAIL browser starts (Could not find Chrome …)`, the install action placed
Chrome in the worker's own cache, and the same self-test then reported
`ok browser starts (Chrome/148.0.7778.97)`.

### Tests
Eight more: a run whose pool cannot serve its minimum never reaching ready, the
refusal naming the arithmetic, a pool of pilot questions counted as empty,
subscale caps checked against the global minimum and floors against the global
maximum, a sound configuration passing, and the runtime being explicit rather
than inherited.

### Verification
PHPUnit 469 tests / 2910 assertions, Behat 32 scenarios / 229 steps, phpcs and
PHPDoc clean, 689 language strings per language, upgrade path replayed from the
previous version.

---

## [0.6.4] — 2026-09-13

Operating the suite from the plugin. Completes issues #14, #15 and the
circuit-breaker half of #17.

### The rule this release is about
Running, diagnosing and recovering an experiment has to be possible from the
plugin's own pages. A shell and a database client are how one investigates a
defect, not how one operates a plugin — and the reported installation needed
both to find out why 1600 queued attempts were not moving.

### Added
- **An operations view** (Reports → Operations) with four sections: system
  health, workers, the attempt queue and the runs in flight. Every question
  that previously needed SSH is answered there: is the worker ready, is one
  running, how many attempts wait, which one is stuck and since when, which run
  it belongs to, how many tries it has had, what the last error was, and
  whether the pipeline is blocked or merely slow.

- **`system_health`** — eight checks, each reporting three things: whether it
  passes, what it found, and where to go to fix it. A check that only says
  "failed" moves the work to the reader rather than doing it. Among them the
  Node major version, because a worker that starts on Node 18 and dies on its
  first dependency is worse than one that refuses to start: the queue looks
  served.

- **`worker_setup::ensure_token()`** — the worker token was the one piece that
  forced an operator out of the workflow entirely. It now enables web services
  and REST, authorises the account in the restricted service, mints the token
  and writes it into the plugin setting, so the worker command the interface
  shows is complete as it stands.

- **Recovery actions in the interface**: start workers, check for dead workers,
  release orphaned claims without waiting out a timeout, and pause or resume an
  individual run.

- **A circuit breaker.** Ten consecutive failures pause a run by themselves. A
  run whose attempts all fail the same way does not improve by being retried
  1600 times — it exhausts the queue and leaves nothing to diagnose. The streak
  is counted over the most recent attempts, so a run that failed early and
  recovered is not punished for its history, and a paused run hands out
  nothing: a pause that still gives work away is not a pause.

### Tests
Six more in `worker_registry_test`: a paused run handing out no work, a failing
run pausing itself at the limit and not one attempt earlier, a recent success
breaking the streak, every operational question having a health check, the
stalled pipeline named as a blocker rather than left to inference, and the
token created from the plugin without minting a second one.

### Verification
PHPUnit 461 tests / 2884 assertions, Behat 32 scenarios / 229 steps, phpcs and
PHPDoc clean, 675 language strings per language. The operations view rendered
against the live instance with real figures.

---

## [0.6.3] — 2026-09-13 (part 2)

Worker operation: slots, leases, heartbeats and visible failure reasons.
Addresses the reported issues #13, #16, #17 and #18, and the observable half of
#15.

### The reported failure
A site limited to `worker_concurrency = 1` ended up with two attempts claimed
at once, and nothing in the installation could say that a worker had gone.
Concurrency that only holds while nobody interrupts anything is not a limit.

### Added
- **`local_catquizlab_worker`, a worker registry.** Without it a worker exists
  only as an operating-system process: nothing can say how many are running,
  whether a slot is already taken, or when one last reported in.

  A **slot** is a concurrency place, so `worker_concurrency = 1` now means one
  live worker installation-wide rather than one job per process that happens to
  be started. Claiming a slot is an insert against a unique index, so two
  simultaneous dispatches cannot both win — the database decides, not a
  read-then-write in PHP that two processes can interleave. A worker restarting
  into its own slot is not treated as a collision.

  A **heartbeat** is the difference between slow and gone. A busy worker keeps
  reporting; a dead one stops. Declaring a live worker crashed is the expensive
  mistake — its attempt would be played twice — so the timeout is generous.

- **Leases on attempts.** A claim now names its holder and says when it lapses.
  Recovery reads the lease instead of `timemodified`, which a worker refreshes
  while it works: a genuinely stuck attempt and a slow one used to look
  identical. Attempts claimed before leases existed keep the timeout fallback,
  so none of them is stranded.

- **Failure reasons are kept.** The worker sends its own reason with the
  completion report, and it is stored on the attempt. A retried attempt used to
  offer nothing but a rising try count.

- **A worker and queue panel on the landing page**: live workers, crashed
  workers, the queue split by state, the five most recent failure reasons, and
  a named warning for the one combination that never resolves itself — attempts
  waiting with no worker running.

### Fixed
- **`launch_pool()` started the configured number of workers on every call.** It
  now starts only workers for slots nobody holds, and takes the slot before
  starting the process rather than after — starting first leaves a window in
  which a second dispatch sees the slot free. Workers are launched detached:
  the blocking `exec()` is why interrupting the caller used to leave a claimed
  attempt with nobody to finish it.
- **`pipeline_tick` reaps dead workers before falling back to the timeout.**
  Reaping hands attempts back with a known reason; the timeout can only guess
  that something went wrong somewhere.

### Tests
`worker_registry_test`, 13 tests: one worker per slot, a restart into its own
slot, free-slot accounting, a silent worker reaped while a reporting one is
left alone, the attempts of a dead worker released, release scoped to its
owner, recovery following the lease rather than the clock, the leaseless
fallback, the recorded failure reason, and a crash told apart from a clean stop.

### Verification
PHPUnit 455 tests / 2853 assertions, Behat 31 scenarios / 219 steps, 11 worker
tests, phpcs and PHPDoc clean. The reported case measured directly: with
`concurrency = 1`, the first dispatch takes slot 1 and a second attempt on the
same slot returns nothing.

---

## [0.6.3] — 2026-09-13 (part 1)

The engine comes from one coordinated branch again.

### Changed
- **`fetch-engine.sh` uses `ALiSe-v-1.2.0-legacy` for all three CAT plugins.**
  Checked rather than assumed: the branch exists in all three repositories, the
  highest Moodle requirement across them is 2024100700 (so Moodle 4.5 upwards),
  `local_catquiz`'s declared dependencies on the other two are satisfied within
  the set, `mod_adaptivequiz` carries its `subplugins.json`, and the branch
  contains the fixes for catquiz#59, #62 and the #64 stage counts. Verified by
  installing it and running the suite: all five engine pins pass.

  The `v-3.0` line is unusable here — `mod_adaptivequiz` requires 2025100600
  there, which only Moodle 5.2 meets, and Moodle aborts the whole installation
  rather than skipping the one plugin.

### Fixed
- **Nine tests failed with the engine installed, on a missing `version.php`.**
  `catquizcentralhub/client` and `/host` are Git submodules; a plain clone
  leaves them as empty directories, and because `local_catquiz` declares
  `catquizcentralhub` as a subplugin type, Moodle scans them and fails. Not with
  a warning — it aborts whatever asked for the plugin list, which is why
  ordinary tasks and tests died with it.

  `fetch-engine.sh` removes unpopulated submodule directories. They are not
  needed to drive the engine, and a half-materialised subplugin shell is worse
  than none. This is also the warning that appeared in the earlier CI logs.

### Verification
Installed locally on Moodle 4.5 with this branch: upgrade clean, PHPUnit 442
tests / 2811 assertions, Behat 31 scenarios / 219 steps, phpcs and PHPDoc
clean, all five engine pins passing, and an end-to-end run provisioning
`planned=24 questions=24 items=24 params=24 visible=24 failed=0` with a played
attempt whose trace carries scale abilities, standard errors and the ability
path.

---

## [0.6.2] — 2026-09-13

CI fix: the engine outgrew the Moodle releases this suite supports.

### Fixed
- **Every PHPUnit and Behat job failed before a single test ran.**
  `mod_adaptivequiz` on the `v-3.0` branch now declares
  `requires = 2025100600`, which only Moodle 5.2 meets. Moodle aborts the whole
  installation with `pluginrequirementsnotmet`, so the 4.5 and 5.0 jobs died
  during setup — not on anything this plugin does.

  `fetch-engine.sh` now reads the requirement out of the engine's own
  `version.php` files and compares it with the release the job is installing.
  Where the release cannot carry the engine, the directory is left empty and the
  job proceeds without it: the suite installs stand-alone by design, and its
  engine-facing tests skip when none is present. The check is made across all
  the engine's plugins at once, because they depend on each other — installing
  some of them is not a smaller engine, it is a broken one.

- **Three lifecycle tests assumed an engine.** They called
  `run_lifecycle::start()`, which the preflight correctly refuses without one —
  a run queued without an engine would look started and never move. Those three
  now skip where no engine is installed; the transition tests were rewritten to
  set up the post-start state directly, so the lifecycle itself stays covered on
  a site without an engine. Verified both ways by removing the engine and
  running the suite: 442 tests green with it and without it.

- `phpcs.xml` excludes `.github/`. CI helpers run before Moodle exists and
  cannot satisfy the `MOODLE_INTERNAL` check the Moodle standard requires of
  plugin code.

### Verification
PHPUnit 442 tests with the engine (12 skipped) and without it (11 skipped),
Behat 31 scenarios / 219 steps, phpcs and PHPDoc clean. `fetch-engine.sh`
exercised for both `MOODLE_405_STABLE` (engine skipped, with the reason stated)
and `MOODLE_502_STABLE` (engine placed).

---

## [0.6.1] — 2026-09-13

The web workflow closed end to end, from the reported lifecycle report.

### Fixed
- **"Choose an experiment course" led to `sectionerror`.** `settings.php`
  registered `local_catquizlab_settings` while the landing page linked
  `local_catquizlab` — two literals that had to agree, in two files. Both read
  `registry::SETTINGS_SECTION` now. It was the one link a fresh installation
  needs, and it was broken.
- **An experiment read "Executed" while every run was a draft at 0%.**
  `create_sweep()` set the status directly. Creating runs is not running them.
  There are now `EXPANDED`, `RUNNING` and `FAILED` states, and the experiment
  status is **derived** from its runs rather than stored, so the two can no
  longer tell different stories.
- **The web interface had no way to start a run.** Backend and task existed;
  nothing called them. There are now start actions per run, per experiment
  ("start all draft runs") and combined ("create sweep and start"). The
  interface implements no orchestration of its own — it calls
  `run_lifecycle::start()`, which queues the existing `orchestrate_run` task.
- **The handover from the last attempt to the result was never closed.** No
  status moved a run to aggregation, and nothing set it to finished afterwards.

### Added
- **`run_lifecycle`** — the one place that decides what state a run is in.
  Before this the same decision was made in the interface, in the worker's claim
  and complete calls and in the tasks, and they could disagree. The path is
  `DRAFT → SCHEDULED → READY → RUNNING → AGGREGATING → FINISHED`, with failure
  reasons recorded in the run manifest rather than only in a log.
- **`preflight`** — engine, host activity, experiment course, capability and
  worker are checked before a start, and what is missing is named. A missing
  worker warns rather than blocks: the run provisions and its attempts wait. A
  missing course or engine blocks, because a run queued without them looks
  started and never moves.
- The results view now says **why** it is empty: "No run has been started yet"
  plus the run tally, instead of blaming the filter for a run that never ran.
- `docs/design/issue-status-2026-09-13.md` — every one of the ten open issues
  checked against the code and the tests, with the places to verify each claim.

### Tests
- `run_lifecycle_test`, 18 tests: the full path as a status sequence, the
  aggregation queued exactly once across repeated completion callbacks, a run
  whose attempts all failed never reaching aggregation, the deleted experiment
  course, and a regression test that makes the reported state unreachable.
- Behat: four scenarios covering the settings link without `sectionerror`,
  "created is not executed", starting drafts from the web, and the results view
  explaining itself.
- `phpcs.xml` excludes `.github/`: CI helpers run before Moodle exists and
  cannot satisfy a `MOODLE_INTERNAL` check meant for plugin code.

### Verification
PHPUnit 442 tests / 2811 assertions, Behat 31 scenarios / 219 steps, phpcs and
PHPDoc clean, 618 language strings per language.

---

## [0.6.0] — 2026-09-03

**Beta.** `MATURITY_ALPHA` → `MATURITY_BETA`.

The whole chain has been exercised end to end against a real CAT engine, not
only against guard paths: a simulated person sits an adaptive test through the
`mod_adaptivequiz` interface, the trace is collected, and the recovered ability
is compared with the ground truth that person was generated from. A study of 90
attempts across three pool variants has been run through it, and all eight tabs
of the results interface render on it.

What beta means here, stated so nobody has to guess: the function is complete
and measured; what is missing is field use. Replication counts in a real study
need to be well above the five used so far — at 30 attempts per cell the
confidence intervals of the pool variants still overlap almost completely.

### Note on the engine
Checked at release time: `local_catquiz` `main` is at 2026083025 and does not
yet carry the fixes for catquiz#59, #62 and the #64 stage counts — the last
commit there is `fc76efb`. The version-gated pins in
`tests/engine_defects_test.php` therefore skip against `main` and take effect
by themselves once the merge lands, without a change here.

### Verification
PHPUnit 424 tests / 2753 assertions, Behat 27 scenarios / 187 steps, worker
check and 11 worker tests, phpcs and PHPDoc clean, 600 language strings per
language, all test classes loading under PHPUnit 11.5, savepoint below the
version ceiling.

---

## [0.5.1] — 2026-09-03

CI fix for the engine pins added in 0.5.0.

### Fixed
- **The pins failed in CI because they assumed an engine version.** They were
  written against `local_catquiz` 2026090204, where the fixes for catquiz#59,
  #62 and the #64 stage counts landed, but CI installs the engine's `main` —
  currently 2026083025, which predates all three. Every PHPUnit job in the
  matrix went red reporting a regression that had not happened, which is the
  worst kind of red build: it trains people to ignore the colour.

  The pins now read the engine's version and skip below 2026090204 with a
  message saying so. A guard on a repair can only speak about a release that
  has the repair.

### Verification
Both directions measured with the engine's version switched by hand: on
2026090204 all five pins run and pass; on 2026083025 the three version-gated
ones skip and the suite stays green. PHPUnit 424 tests, phpcs and PHPDoc clean,
all test classes loading under PHPUnit 11.5.

---

## [0.5.0] — 2026-09-03

Verified against `local_catquiz` 2026090204 (`improve-performance-testadministration`).

### The claim that was overtaken
> "`$CFG->debug = DEBUG_DEVELOPER` schließt `local_catquiz | store_debug_info = 1`
> derzeit aus."

**No longer true.** With both active, an attempt now plays 16 items, writes
10,954 characters of `debug_info` across 17 rows, and the ability path is
collected (`path: 17`). Both engine defects this suite reported are fixed in
2026090204: `get_ability_range()` declares `int` (catquiz#59) and `debuginfo.php`
reads `$newdata['lastquestion'] ?? []` (catquiz#62).

The pins in `tests/engine_defects_test.php` did their job: they failed on the
new engine and their messages named what to update. They now hold the repairs
in place instead of the defects — a signature that loosens again would bring
back an abort that points at the wrong place.

### Reproduction of catquiz#64, and what the new diagnosis shows

1. `local_catquiz` 2026090204 installed.
2. Reproduced with `maxquestionspersubscale = 1`: the attempt stops after **2
   answered questions** of a required minimum of 10, with 22 unplayed items
   left in the pool. `mod_adaptivequiz` records `attemptstopcriteria = "An error
   occured"`.
3. From the attempt's cache: **both keys are absent.**

```json
{"available": false, "error": null, "stagecounts": null,
 "reason": "stage-counts-not-recorded-by-this-engine"}
```

The engine's own attempt row explains why: `catquizerror = false`. The
selection never reported a failure, so `after_error()` — the only place that
writes `catquizerror` and `catquizstagecounts` — does not run. **The diagnosis
added for #64 is not set for the abort #64 describes.**

That is the finding, and it is a useful one: without it, any statement about
which stage empties the pool would be guesswork, which is exactly what the
counts were meant to end. Writing them on every completed selection, not only
in the error path, would close the gap.

### Added
- **`enginediagnosis.php`** reads `catquizerror` and `catquizstagecounts` from
  the attempt's session cache. It has to run in the browser session of the
  person whose attempt it was — the cache is session-scoped, so a CLI script or
  a call under the worker's own token sees an empty cache. It distinguishes "no
  counts recorded" from "a stage counted zero", because those point at
  completely different things.

---

## [0.4.3] — 2026-09-02

Release documentation.

### Changed
- `docs/sessions/session-003.md` records the session end to end: issue #9 with
  its eleven sub-findings, the first real engine integration, the chain of
  defects between a queued attempt and a completed one, the study parameters,
  and the closing study.
- `docs/design/status.md` describes 0.4.2 and names what remains — two engine
  defects held by test, and a replication count that needs to grow well beyond
  five before the pool variants can be told apart.

---

## [0.4.2] — 2026-09-02

**A study at a size where the dispersion means something.** Three pool variants
× five replications × six persons = 90 attempts, all played through the
browser.

### Result

| variant | n | items | SE | bias | 95% CI |
|---|---|---|---|---|---|
| ideal | 30 | 16.0 | 0.913 | +0.706 | [+0.256; +1.156] |
| calibration error | 30 | 16.0 | 0.937 | +0.512 | [+0.044; +0.980] |
| depleted | 30 | 11.2 | 1.071 | +0.659 | [+0.173; +1.145] |

The three confidence intervals overlap almost completely, so **no disturbance
shows a demonstrable effect on the bias** at this size. That is the honest
reading, and it is why the interface reports the interval beside the point
estimate: the ΔRMSE of −0.039 and +0.065 against the ideal pool are a fraction
of a standard error apart and mean nothing on their own.

What the disturbances *do* show is elsewhere. A depleted pool cannot fill the
test — 11.2 items instead of 16 — and pays for it in precision: SE 1.071
against 0.913. That is a real, interpretable effect, and it appears in the
length and precision columns rather than in the bias.

Local diagnostics over 180 subscale observations: bias −0.030, RMSE 1.776,
1-SE coverage 52.2%, 2-SE coverage 80.6%.

### Verified
All eight tabs render on 90 attempts, including robustness with a full ideal
reference for the first time. Raw data lists 90 rows; the exports produce 16,
91, 181 and 299 lines.

---

## [0.4.1] — 2026-09-02

Engine updated to `main` (2026083025) and its defects pinned by test.

### Checked against the new engine

| Point | State |
|---|---|
| `progressretention` / `progressretentiondays` | **implemented** — progress-row retention is configurable now, which was the first thing this suite asked for upstream |
| catquiz#59, `get_ability_range(array_key_first(...))` | open, `feedbackgenerator.php:446` unchanged |
| `debuginfo.php` reading `lastquestion` unguarded | open, line 347 unchanged |
| `local_catquiz_personparams.standarderror` | still written empty |

Measured, not read: with `DEBUG_DEVELOPER` and `store_debug_info` both on, an
attempt still does not start on the new engine.

### Added
- **`tests/engine_defects_test.php`** pins each of the four points. Every pin
  fails once the engine is fixed — deliberately. A workaround that outlives its
  cause is not free: it hides the repaired behaviour and keeps a limitation in
  the documentation that no longer exists. Each failure message names what to
  remove. The tests skip where no engine is installed, so CI stays green.
- `docs/dev/environment-setup.md` records the state and the practical
  consequence: `DEBUG_DEVELOPER` and `store_debug_info` exclude each other
  until the `lastquestion` access is guarded, so collecting the ability path
  means setting `$CFG->debug = 0` for that run.

---

## [0.4.0] — 2026-09-02

The three remaining gaps, closed.

### Added
- **Standard errors computed from the items a person actually saw.** The engine
  records one on its own attempt row but leaves
  `local_catquiz_personparams.standarderror` empty, so nothing downstream could
  say how precise an estimate was — and a diagnosis without a precision is a
  number without a claim.

  The lab can compute it, because it knows the true item parameters it
  generated: Fisher information per item, summed over the administered items,
  SE = 1/√I. This is the identity the feasibility view already used in the
  other direction. Two limits are stated in the class: the information is
  evaluated at the *estimated* ability, which is what an operational test can
  do, and it assumes the model the items were generated under.

  Measured on the sweep: global SE 0.538 from I = 3.456 over 16 items;
  per-scale 0.752 and 0.768; 23 of 24 subscale observations now carry a local
  SE, giving 1-SE coverage 43.5% and 2-SE coverage 87.0%. The engine's own
  values are still preferred where it writes any — empty means "nothing
  usable", not "no rows", since it writes rows full of nulls.

- **`docs/design/issue-catquiz-debuginfo-lastquestion.md`**, short: the
  unguarded `lastquestion` access is a PHP notice, and only `DEBUG_DEVELOPER`
  turns it into an exception that aborts the attempt.

### Verified
- **The robustness tab, with data for the first time.** A sweep over three pool
  variants with everything else held constant — the only way a difference is
  attributable to the disturbance rather than to the strategy:

  | variant | items | RMSE | ΔRMSE vs. ideal | Δbias |
  |---|---|---|---|---|
  | ideal | 24 | 0.769 | — | — |
  | calibration error | 24 | 0.905 | **+0.136** | −0.118 |
  | depleted | 10 | 0.694 | −0.075 | −0.436 |

  A miscalibrated pool costs precision, which is what the design predicts. The
  depleted pool comes out slightly better, which at three attempts per cell
  says nothing — and the interface reports the dispersion beside it rather than
  the point estimate alone.

### Verification
PHPUnit 419 tests / 2748 assertions, phpcs and PHPDoc clean.

---

## [0.3.6] — 2026-09-02

**The results interface walked through with real data.** All eight tabs render
without an exception and without a missing language string, on the twelve
attempts of the two-strategy sweep:

| tab | tables | rows | charts |
|---|---|---|---|
| Overview | 3 | 6 | — |
| Global metrics | 4 | 13 | 3 |
| Subscales | 3 | 9 | 1 |
| Deficit detection | 4 | 10 | — |
| Robustness | — | — | — |
| Test flow | 3 | 24 | 1 |
| Raw data | 1 | 12 | — |
| Export | 1 | 4 | — |

Every tab names its aggregation: *"Aggregated over 12 attempts in 4 runs and 2
replications. Dispersion: 95% confidence interval over replications."*

The global tab reports bias 0.960 [0.257; 1.663], RMSE 1.5289 and a correlation
with ground truth of 0.7506, beside a scatter plot with labelled axes and a
y = x reference.

Robustness is empty on purpose and says why: *"Only ideal-pool runs match this
filter, so there is nothing to compare."* The sweep varied the strategy, not
the pool, so a robustness figure would have compared conditions that differ in
something else — which is exactly what the tab refuses to do.

### Note for operators
Every page returned "Section error!" until the plugin upgrade was run. A version
bump without `admin/cli/upgrade.php` leaves Moodle unable to load the plugin's
`settings.php`, so the admin page it registers does not exist and every URL
under it fails. Nothing in the plugin causes it, but it looks alarming and
costs time to trace, so it is written down here.

---

## [0.3.5] — 2026-09-02

### Changed
- The upstream issue on `lastquestion` is now a short one, and it leads with
  the condition rather than the symptom: the unguarded access is a PHP notice,
  and only `DEBUG_DEVELOPER` turns it into an exception. On a normal instance
  `(array) null` becomes `[]` and nothing happens.
- `docs/dev/environment-setup.md` states the consequence for this environment.
  The two settings do not currently combine, so a choice has to be made:

  | Purpose | `$CFG->debug` | `store_debug_info` |
  |---|---|---|
  | Play attempts, collect the ability path | `0` | `1` |
  | Work on the plugin, PHPUnit, Behat | `DEBUG_DEVELOPER` | `0` |

  The environment now sits on the first row, since without the path half the
  evaluation stays empty. The `config.php` line says why, so the next person
  does not switch it back and lose the traces.

### Note on collection runs
A sweep of four runs with three persons each provisions cleanly (24 items
engine-visible per run), but playing twelve attempts through a real browser
takes longer than a single command in this environment allows — roughly half a
minute per attempt against the built-in PHP server, which also drops
connections under parallel sessions. Collecting a full sweep needs either a
longer-running worker outside the session or a proper web server.

---

## [0.3.5] — 2026-09-02

**First sweep with real data.** Two strategies, two replications, three persons
each — twelve attempts played through the browser, then evaluated:

| strategy | items | error (mean) | runtime |
|---|---|---|---|
| Estimate global ability (MFI) | 8.0 | +0.84 | 16.0 s |
| Fixed-form baseline | 16.0 | +1.08 | 26.3 s |

MFI reaches a slightly smaller error with half the items and in two thirds of
the time, which is the behaviour the design predicts. Exposure: 63 of 96 items
used, 33 never shown, maximum rate 0.25, Gini 0.475. Local diagnostics: 24
subscale observations with true and estimated deltas.

The four export levels produce 5, 13, 25 and 97 lines respectively.

### Added
- `docs/design/issue-catquiz-debuginfo-lastquestion.md`, shortened to the point:
  the unguarded `lastquestion` access is a PHP notice, and only at
  `DEBUG_DEVELOPER` does Moodle turn it into an exception that aborts the
  attempt. On a normal instance `(array) null` becomes `[]` and nothing
  happens. Scope first, then cause, then the one-line fix.

---

## [0.3.4] — 2026-09-02

**The step-by-step ability path is collected.** A full adaptive test, one θ
estimate per step:

    step  1  question 880  ability -0.4600
    step  2  question 881  ability -0.4752
    step  3  question 882  ability -0.6992
    ...
    step 16  question 899  ability -0.2774
    true θ -0.4795

### Corrected
My previous finding was half right, and Ralf's counter-observation settled it.
The unguarded access to `lastquestion` in `debuginfo.php:347` is real, but it
only aborts an attempt on an instance running `DEBUG_DEVELOPER`, where Moodle
turns the PHP notice into an exception. On a normal instance `(array) null`
becomes an empty array and the test runs — which is why it works in a full
environment. Measured both ways with everything else identical. The upstream
issue now states the scope first, and its title says "at developer debugging"
rather than claiming attempts are broken in general.

### Fixed
- **The ability path was parsed as a map and is a rendered line.** The engine
  writes `"Scale: 0.5, Scale / K1: 0.4"` into each debug row — readable in a
  report, useless to a machine. Without translating the names back into scale
  ids the entire path was dropped silently, which is why `path: 0` persisted
  even where `debug_info` was present. A name the run does not know is skipped
  rather than guessed at.

### Verification
17 debug rows collected, 16 flow steps with an ability each, `scaleabilities`
4 per attempt. PHPUnit 414 tests, phpcs and PHPDoc clean.

---

## [0.3.3] — 2026-09-01

### Answered
**Why `local_catquiz_attempts.debug_info` stays empty even with
`local_catquiz | store_debug_info = 1`.** Measured in isolation on a fresh run:
with the setting on, no attempt starts at all — the first question never
appears and the activity reports "couldn't define the first question". With the
setting off and everything else identical, the same test plays 16 items.

The cause is in the engine and is the same shape as the colour-key defect fixed
in 0.3.0: an exception in the feedback path aborts the question selection, and
the visible message points somewhere else. `debuginfo.php:347` reads
`$newdata['lastquestion']` without checking it exists, while every neighbouring
field in the same structure is guarded with `isset()`. At the first question of
an attempt there is no last question, so the access throws, the exception is
caught in `return_next_testitem()` and turned into a general error.

`lastquestion` is also removed from the attempt data on purpose in
`catquiz.php:1874`, so the key is missing systematically rather than only on
the first call.

Drafted as `docs/design/issue-catquiz-debuginfo-lastquestion.md`. Until it is
resolved, `debug_info` is unreachable — the field is only written when the
setting is on — and the engine's ability path stays unavailable with it.

The engine tree was restored byte-identically after the measurement.

---

## [0.3.2] — 2026-09-01

The study's item-parameter distributions, one question category per experiment,
and the reason `store_debug_info` still breaks an attempt.

### Added
- **The declared item-parameter distributions.** Discrimination 0 < a ≤ 5 with
  its most likely value at 2, guessing 0 < c < 0.5 with its most likely value
  at 0.25. Both are stated as *modes*, so the parameters are derived from the
  mode and not from a mean — for a skewed distribution the two are different
  numbers, and taking one for the other would shift the whole pool.

  Discrimination uses a lognormal with `meanlog = log(2) + sdlog²`, which puts
  the mode exactly at 2. Guessing needed a new distribution: it is bounded on
  both sides *and* has an interior mode, which neither a normal nor a lognormal
  can express, and clamping either would pile probability onto the very
  boundaries the design keeps clear of. A symmetric beta on the interval puts
  the mode at the midpoint by construction. Drawn 20 000 times: a in
  [0.34, 5.00] with its mode near 1.8, c in [0.001, 0.499] with its mode near
  0.26.
- **One question category per experiment**, created in the course context and
  named after the experiment. A shared bank becomes unreadable after a few
  sweeps, and an item should be traceable to its study without consulting the
  lab's tables.

### Diagnosed
`store_debug_info` still prevents an attempt from starting, and it is not the
colour bug from 0.3.0. Instrumenting the chain again shows the selection
working and the debug feedback generator failing:

    select_question -> question 728
    update_attemptfeedback THREW: Undefined array key "lastquestion"
      @ feedbackgenerator/debuginfo.php:347

`debuginfo.php` reads `$newdata['lastquestion']` unconditionally, and at the
first question of an attempt there is none. That is the same shape as issue #59
— a feedback generator assuming state that does not exist yet — and it belongs
in the same report. The instrumentation was removed; the engine tree is
byte-identical to its checkout.

Until that is fixed the ability path stays unavailable, because it exists only
in `debug_info`.

---

## [0.3.2] — 2026-09-01

Study parameters, one question category per experiment, and a note on the trace
sources.

### Changed
- **The discrimination distribution is now a beta, not a lognormal.** The
  design states 0 < a ≤ 5 with the mode at 2. The lognormal met the mode but
  not the range: measured over 20,000 draws, **9.4% landed on exactly 5.0** and
  the modal bin was the top one. A clamp catching a tenth of the draws is not a
  guard, it is the shape — and it would have given a tenth of every pool an
  identical, maximal discrimination. `Beta(3, 4)` on (0, 5] cannot leave its
  range and has its mode exactly at 2. Measured: median 2.12, modal bin
  2.0–2.1, maximum 4.76.
- The guessing distribution was already `Beta(2, 2)` on (0, 0.5) with its mode
  at 0.25, which the same measurement confirms: median 0.251, modal bin
  0.24–0.25, maximum 0.497.
- **One question category per experiment.** Everything in a single category
  leaves a bank nobody can navigate after a few sweeps. The existing helper
  only resolved a category once the experiment had a course recorded, and
  materialisation runs before the container stage — so every run fell back to
  the shared category. It now uses the configured course until the experiment
  has its own.

### On the trace sources
`store_debug_info` was retested now that the colour-key defect is fixed, since
that defect lived in the same feedback path. It does not help: with the setting
on, the attempt still does not start. The engine's ability path therefore
remains unavailable until catquiz#59 is resolved, and the per-scale abilities
continue to come from the engine's attempt row, which every site writes.

`local_catquiz_progress.json` is archived with each trace and carries the item
sequence, the responses with their fractions and the scale lifecycle. What it
does not carry is a path: `progress::update_ability()` overwrites the value per
scale rather than appending, so only the final estimate survives there.

---

## [0.3.1] — 2026-09-01

**The full DPF evaluation runs against real data.** A person with subscale
deviations was measured, and the local diagnostics have something to work with:

    twin r001-t00001 (subscalevariation): true -0.4795  est -1.9777
      K1.1: true delta +0.1833   estimated +0.7677
      K1.2: true delta -0.5800   estimated -0.3823
    local recovery: n=4, bias +0.976, RMSE 1.178, r 0.369
    ranking: Spearman 1.0, top-1 agreement 1.0

### Fixed
- **The per-scale abilities never reached the trace.** They were read from
  `debug_info`, which a site only writes with debug information switched on, so
  a plain run collected nothing and every local diagnostic was left without
  data. The engine also records them on its own attempt row, and that is the
  fallback now — `scaleabilities` went from 0 to 4 per attempt.
- **Per-scale standard errors were looked up by attempt id only.** A completed
  attempt left its person parameters with a null attempt id, so the lookup
  found nothing at all. The user and context of the attempt identify the same
  rows.
- **"An error occured" counted as the stop rule succeeding.** The host activity
  reports it whenever the engine returns no question — including when every
  subscale has simply reached its own maximum, which is what ends a healthy run
  here. Counting it as a success would have inflated the stop-rule rate with
  runs that ran out of room.

### Note
A test ends after 16 items because `maxquestionspersubscale` is 8 and there are
two subscales. That is the configuration working, not a defect — but the
engine's stop reason does not say so, which is why the figure is now reported
as an unsuccessful stop rather than a successful one.

---

## [0.3.0] — 2026-09-01

**A simulated person completed an adaptive test.** Sixteen items, and the
estimate lands on the ground truth:

| | true θ | estimated θ | error |
|---|---|---|---|
| person 1 | −0.4795 | **−0.4552** | 0.024 |
| person 2 | −1.7780 | **−1.9777** | 0.200 |

### Fixed
- **The feedback colour keys were invented, not chosen from the engine's
  palette.** The palette depends on the number of bands — for two bands the
  valid keys are `3` and `6`, not `1` and `2` — and an unknown key made
  `comparetotestaverage` fail on an undefined index.

  The consequence was completely out of proportion to the cause. The failure
  happens in `update_attemptfeedback()`, which the engine calls *after* it has
  already selected the next question: `select_question` returned question 632
  with `iserr=false`, the exception was raised while rendering feedback, and
  the attempt ended holding a question it could not show. Every diagnosis until
  now therefore looked at the pool — which was never the problem.

### How it was found
By instrumenting the engine's preselect chain temporarily and counting
candidates after every step, as the analysis suggested. The counts settled the
question in one run:

    initial 24 → removeplayedquestions 23 → ... → filterbyquestionsperscale 23
    select_question -> question 632, iserr=false
    update_attemptfeedback THREW: Undefined array key 1

No filter ever emptied the pool. The instrumentation was removed afterwards;
the engine tree is byte-identical to its checkout.

### Still open
The trace records `stop="An error occured"` and carries no per-scale abilities,
standard errors or ability path, so the local diagnostics and the test-flow view
have no data yet. The global outcome — sixteen items and a recovered θ — is
there.

---

## [0.2.26] — 2026-09-01

### Fixed
- **The worker treated the absence of a question as a finished attempt.** A
  failure page, a redirect and a page that has not rendered yet all look alike
  from the browser's side, and treating them alike turns any of them into a
  successful run. It now requires the activity's own finish page and otherwise
  reports the URL, the title and what the page says.

### Ruled out for the one-question attempt
Measured against the real engine, not reasoned about:

| Hypothesis | Result |
|---|---|
| The worker's DOM handling | **Not the cause.** A manual browser run with no worker code involved ends identically, on `attemptfinished.php`, with "minimum number of questions was not reached". |
| `remove_uncalculated()` dropping items | **No.** The engine returns 24 items, all with a model, a difficulty and status 4. |
| `updatepersonability()` failing | **No.** The response arrives (`state: gradedright`, `fraction: 1.000`) and the abilities update from 0 to 0.330 on all three scales — the scale-selection fix of 0.2.25 did that. |
| Effective maxima being 1 | **No.** `maximumquestions` 250, `max_attempts_per_scale` 8, 24 items. |
| The SE stop rule biting immediately | **No.** Narrowing the window to 0.05/0.30 changes nothing. |
| Crossed question/item/param references | **No.** All links hold across 400 items, verified with the id sequences deliberately offset. |

The DOM id format the analysis describes is confirmed — `question-110-1` is
usage 110, slot 1 — and the worker has read it that way since 0.2.15, sending
usage and slot for the server to resolve.

What remains, for whoever picks this up: after one answered question the
abilities are updated and 23 unplayed items with known parameters remain, yet
mod_adaptivequiz ends the attempt regularly. The candidates left in the shared
path are `maximumquestionscheck`, `mayberemovescale` and `noremainingquestions`.

---

## [0.2.25] — 2026-09-01

### Fixed
- **The test's scale selection had a hole in the middle.** Only the leaves were
  reported as subscales, so a three-level tree (root → domain → subscale)
  reached the engine as root plus leaves with the domain missing — while the
  items hang on the leaves and a leaf is only reachable through its domain.
  Every scale below the root is selected now.

### Added
- **`run_verifier::link_report()`** and the `links` section of `cli/verify.php`
  check, per item, that `question.id = local_catquiz_items.componentid`, that
  `itemparams.componentid` names the same question, that `activeparamid` points
  at that parameter set, that `itemparams.itemid` points back at the item, that
  both share a CAT context, and that the scale lives in it. Row counts per
  table cannot catch a crossed reference — they agree while a pointer is wrong —
  so each link gets its own verdict and a failure names the item.

  Verified by crossing a reference on purpose: the report named exactly the two
  links that broke. The check was also run with the item-parameter sequence
  pushed ahead deliberately, so the ids could not coincide; without that, all
  three ids run in lockstep on a fresh site and the join proves less than it
  appears to.

### Measured
For the record, against the real engine: 24 distinct question ids, 24 engine
items, 24 parameter sets, all seven links holding across 400 items and 31 runs.
The root scale returns 24 items when subscales are included and none without,
and the attempt's own settings carry `includesubscales: true`.

---

## [0.2.24] — 2026-09-01

### Fixed
- **Questions did not carry their item name.** The name lived only in the lab's
  own table, so `<idnumber>` came out empty in an XML export and a question in
  the bank could not be traced back to the item it represents. The item name is
  now the question's ID number — which is where Moodle keeps exactly this kind
  of external identifier, and it travels through export and import — prefixed
  with the run, because several runs share one question category and an ID
  number has to be unique within it.
- **The question name did not mention the item either.** It read
  `CATLab CATLab run 183 / K1.2 #12`, repeating the scale and omitting the
  identifier. It now reads `Q-1-2-012 — CATLab run 183 / K1.2`.

### Note on question ids
High question ids in a demonstration export are accumulation, not a defect: 31
runs at 24 items each had produced 400 questions in this environment. Each run
does create exactly its planned items. They all land in a single question
category, though, which is worth revisiting — one category per experiment would
mirror the course sections and keep a bank usable after a few sweeps.

---

## [0.2.23] — 2026-09-01

### Added
- **`cli/export_pool.php`** exports a run's pool for inspection elsewhere:
  the questions as importable Moodle XML, the item parameters as CSV with the
  lab's ground truth beside the values the engine actually holds, and the scale
  tree with the item count per scale. The three belong together — questions
  without parameters are unusable for CAT, parameters without questions
  describe items that do not exist, and both without the tree carry scale ids
  that mean nothing on another site.

  The CSV includes `is_known_parameter`, which is the distinction that decides
  whether the engine learns from an item at all.

---

## [0.2.22] — 2026-09-01

Every lab item counted as a pilot question.

### Fixed
- **Item parameters were stored with status `CALCULATED` (1).** The engine
  treats an item as a pilot question while its parameter status is below
  `UPDATED_MANUALLY` (4) *and* it has fewer responses than the pilot threshold
  — and a pilot contributes nothing to the ability estimate. Every lab item met
  both conditions, so the engine administered one, learned nothing from it, and
  ended the attempt. `catquiz_includepilotquestions = "0"` did not help: the
  items were not excluded, they were simply uninformative.

  A lab item genuinely is a manually set parameter. Its difficulty and
  discrimination are the ground truth the simulation was built from, not an
  estimate from responses, which is exactly the case the engine calls "updated
  manually". Stored as status 4 now, and the progress snapshot confirms
  `is_pilot=false`.

### Verification
The engine's error on finishing an attempt is gone — the stop reason is empty
rather than "An error occured". PHPUnit 404 tests, Behat 27 scenarios, phpcs
and PHPDoc clean.

### Next thread
The attempt still ends after one item, now without an error. Two observations
from the progress snapshot, for whoever picks this up:

- `activescales` contains only the root scale (246); the two subscales that
  hold the items (248, 249) are not among them.
- `abilities` stays at `{"246": 0}` after the answer — the estimate is not
  updated, so the stop criteria cannot move either.

Both point at the same place: the scales that carry items are not the scales
the attempt considers active.

---

## [0.2.21] — 2026-09-01

Stale engine caches, and what the per-scale limits actually mean.

### Fixed
- **A freshly provisioned run could not present its first question.** The
  engine caches what it knows about scales, contexts and items; a run whose
  caches still described the previous one showed no question at all, and the
  engine's own message blamed the configuration. Provisioning now purges every
  store that describes the pool a test will be played from —
  `changesintestitems`, `changesincatscales`, `changesincatcontexts` plus the
  `adaptivequizattempt` and `catscales` stores — as its last act before the
  test is created. The earlier purge covered only the item stores, which was
  enough to make items visible and not enough to make a test playable.

### Learned
`min_attempts_per_scale` and `max_attempts_per_scale` are the number of
questions asked per scale, not a property of the pool. That makes the two
budgets a pair that has to add up: with two subscales and a maximum of four
questions each, a test can ask at most eight — while `minimumquestions` asked
for ten. The engine then ends with *"minimum number of questions was not
reached"*, and nothing in either setting looks wrong on its own.

Item counts per scale in the demonstration run: 12 for each of the two
subscales, none directly on the root or the domain, which is the intended
shape — items hang on the leaves.

### Verification
Both queued attempts of a run were played without any manual cache purge, and
engine attempts 81 and 82 were recorded. PHPUnit 403 tests, Behat 27 scenarios,
phpcs and PHPDoc clean.

### Not yet
Each attempt still ends after its first answered question. The engine selects
no second item although each subscale holds twelve, which is the next thing to
look at.

---

## Untersuchungsstand (Stand 0.2.20)

Beobachtungen aus dem Lauf gegen die reale Engine, festgehalten für die
Fortsetzung:

| Strategie | Verhalten |
|---|---|
| `classic` (engine 7) | Attempt startet, Frage 1 erscheint, Antwort wird angenommen. Danach Ende mit *„Test result can not be calculated because minimum number of questions was not reached"*, `skip_reason: lastquestionnull`. |
| `fastest` (engine 1) | Attempt startet nicht; die Seite bricht im Feedback-Pfad ab. |

Gemeinsame Rahmenbedingungen beider Läufe: 24 Items, alle über den
Engine-Abrufpfad sichtbar, Itemparameter mit Status `CALCULATED`,
Personenparameter auf allen Skalen gesetzt, `minimumquestions = 10`,
`maxquestionsscalegroup = 3/4`, `standarderrorgroup = 0.35/1.0`, Feedback mit
zwei Bändern je Skala.

Auffällig: Die Wurzelskala hat genau ein Kind (`164 → 165`), die eigentlichen
Subskalen hängen eine Ebene tiefer. `min_attempts_per_scale = 3` und
`max_attempts_per_scale = 4` beziehen sich möglicherweise auf eine andere
Ebene, als der Pool sie anbietet.

---

## [0.2.20] — 2026-09-01

The feedback configuration, which the attempt cannot start without.

### Fixed
- **The quiz settings described no feedback ranges.** The engine reads
  `numberoffeedbackoptionsselect` and the per-scale
  `feedback_scaleid_limit_lower/upper_<scaleid>_<n>` keys whenever it builds an
  attempt's feedback — including at the very first question — so without them
  the attempt could not start at all. Nothing about the word "feedback"
  suggests that a test which shows none still needs the settings, which is why
  this was missed for so long. Each scale now gets two evenly spread bands over
  the ability range, plus `catquiz_scalereportcheckbox_<scaleid>`, so the
  engine computes and reports a per-scale ability at all.

  The bands are spread evenly on purpose: the lab measures abilities rather
  than interpreting them, and any other split would state a judgement the study
  has not made.

### Verification
Attempts now start against the real engine: both queued attempts of a run were
played, engine attempts 12 and 13 were recorded and linked, and traces were
collected. PHPUnit 402 tests, Behat 27 scenarios, phpcs and PHPDoc clean.

### Not yet
Each attempt still ends after its first answered question with the engine's
"An error occured" and no ability path. That is the next step, and it is one
question further than the last release reached.

---

## [0.2.19] — 2026-09-01

The real error message, and what was hiding it.

### Diagnosis
With `local_catquiz | store_debug_info` enabled, the `TypeError` reported in
0.2.17 gives way to the message that actually explains the failure:

    Sorry, but couldn't define the first question to start the attempt,
    the quiz is possibly misconfigured.
        mod_adaptivequiz\cat_session::run_item_administration_locked

So the `TypeError` in `get_ability_range()` was never the cause — it is what
the engine raises while trying to render the feedback for a failure that has
already happened, and it replaces the diagnosis with a stack trace pointing at
the wrong place. The upstream issue has been extended accordingly: securing the
method restores the engine's own error message, which today depends on whether
debug information is switched on.

Checked and ruled out along the way: item status (`ACTIVE`), item-parameter
status (`CALCULATED`), engine visibility (24 of 24), person parameters (seeded
on all four scales in the right context), scale names, and the item budget
against the pool size. The remaining candidate is the first-question selection
itself — `firstquestionselector` takes a peer mean or the configured fallback,
and this is where the run stops.

### Verification
PHPUnit 401 tests, Behat 27 scenarios, phpcs and PHPDoc clean.

---

## [0.2.18] — 2026-09-01

Starting person parameters, and the upstream issue drafted.

### Added
- **Simulated persons are given a starting ability of 0.0 on every scale of
  their run.** The engine expects a person to have one before it chooses a
  first question; in normal use the activity's entry path establishes it from a
  peer mean or the configured fallback. A person the worker drops straight into
  an attempt has never been through that path, so the lab states the value
  itself. 0.0 is also the right value for an experiment: every simulated person
  starts at the scale midpoint, so an estimate is shaped by that person's
  answers rather than by whoever sat the test before them. An ability the
  engine has since measured is never overwritten — seeding is a starting point,
  not a reset.
- `docs/design/issue-catquiz-ability-range-null.md`: a short upstream issue for
  local_catquiz. `attemptfeedback::update_data()` returns before setting
  `catscales` when no person abilities exist, and `feedbackgenerator.php:419`
  reads the key regardless and passes `array_key_first()` — that is, `null` —
  to a method declaring `int`. The proposal is to fall back to the test's
  primary scale and to let the signature state the expectation, so a bad call
  reports where it originates rather than one layer down in a constructor.

### Verification
Provisioning writes eight parameter rows for two persons over four scales and
stays green end to end against the real engine. PHPUnit 401 tests, Behat 27
scenarios, phpcs and PHPDoc clean.

---

## [0.2.17] — 2026-09-01

Named scales, and the attempt failure traced to its source.

### Fixed
- **Scales had no names.** The root scale took its name from the run's cell key,
  which is empty when nothing is swept, so the engine recorded a nameless root
  and children called `" / K1.1"` — unreadable in the CAT manager and carried by
  the engine into its own feedback structures. Scales are now named after the
  run, with the cell key appended when there is one.

### Diagnosis
The one-item attempt of 0.2.15 and the silent start failure of 0.2.16 have the
same cause, and it is not in this plugin. Starting a fresh attempt raises, in
the engine:

    local_catquiz\catscale::__construct(): Argument #1 ($catscaleid) must be of
    type int, null given, called in .../teststrategy/feedback_helper.php:484

`feedbackgenerator.php:419` calls `get_ability_range(array_key_first($catscales))`
without checking that `$catscales` is non-empty, which it is at the very first
question of an attempt that has produced no abilities yet. A page that renders
an exception presents no question, so the worker correctly reports that the
attempt never started — the message is accurate, the cause simply lies one
plugin further down.

This wants an upstream issue of its own, alongside the progress-retention one.

### Verification
Provisioning stays green against the real engine with 24 items
(`planned=24 questions=24 items=24 params=24 visible=24 failed=0`) and scales
now read `CATLab run 77 / K1.1`. PHPUnit 398 tests, Behat 27 scenarios, phpcs
and PHPDoc clean.

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
