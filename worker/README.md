# catquizlab-worker

External Puppeteer worker of the CAT experiment suite (`local_catquizlab`).

The worker is a deliberately *dumb executor*: it logs in as a simulated user,
plays a CAT attempt through the real `mod_adaptivequiz` UI and asks the
plugin's **oracle web service** for every answer decision. All simulation
logic — ground-truth ability profiles, IRT likelihoods, seeded randomness,
deviant response patterns — lives server-side in the plugin. Runs are
triggered by **timed ad-hoc tasks** of the plugin (backlog E3.1/E3.2), either
by direct process start on the application server or via a job queue the
worker polls over a web service.

## Stub scope

`run_attempt.js` currently only validates its invocation arguments
(`--base-url`, `--run-id`, `--token`) and exits. No browser is launched yet.

## Setup

    npm install        # installs Puppeteer incl. Chromium
    npm run check      # syntax check
    node run_attempt.js --base-url=https://test.example --run-id=1 --token=abc

## Reports to Moodle: transport and limits (#111)

The worker calls Moodle's REST endpoint `webservice/rest/server.php` with
`POST` and sends **everything in the body** as
`application/x-www-form-urlencoded`: the token, the function name, the format
and every parameter. The URL is the same for every call, whatever is reported;
no parameter — and no token — appears in it, and so none appears in a web
server's access log. (Query parameters made a large failure report fail in turn
with HTTP 414, and put the token into every log line.)

Limits on what a report carries:

| Field | Limit | Beyond it |
|---|---|---|
| `message` (the failure) | 4,000 characters | cut at the end, with `… [truncated: N of M characters cut; full text in the artefacts]` |
| `diagnostics` (browser and transport record, JSON) | 60,000 bytes | cut by its structure — long strings shortened, the oldest browser events dropped — and always valid JSON; a `truncated` entry says how large it was and where the full one is |

When a diagnosis is larger than the limit, the full one is written beside the
attempt's artefacts as `diagnosis-full.json`
(`<artefact-dir>/experiment-…/run-…/attempt-…/execution-…/`), and its path is
sent in `truncated.full`. Nothing is cut silently.

## Reading the progress page in tests (#141)

`watch_progress.js` is not a worker. `cli/smoke.php --watch-page=FILE` starts it beside the workers: it signs in
as a temporary manager (credentials in `CATQUIZLAB_WATCH_USER` / `CATQUIZLAB_WATCH_PASSWORD`, never on the command
line), opens step 3 for the experiment and writes what the page shows every 5 s, one JSON line each. The smoke test
checks those readings against its own snapshots and deletes the user afterwards.

## Long sittings, the engine identity, faults (0.7.84, #172–#175)

- **No fixed number of turns.** A sitting ends at the activity's finish page or by the server's word. It stops early
  only on evidence (`sitting_watch.js`):
  - another usage, or a slot going backwards: `[identity:…]`;
  - the same question back three times after an answer, or 10 minutes without progress: `[guard:noprogress]`;
  - an answer beyond the run's pool, which the claim sends as `budget`: `[guard:budget]`.
- **The engine identity.** At its first question every execution calls `local_catquizlab_sitting_bind`. That call
  binds or confirms the activity attempt and gives the count to start from. A server that cannot be asked is asked
  three times, then the execution ends with `[state:unavailable]`, nothing answered. An attempt already complete
  before the start is collected, not started again.
- **The contract.** The worker sends `contract` (1) with every claim and plays nothing for a server of another
  contract.
- **Faults for tests** (`CATQUIZLAB_FAULTS`): `notstored@N` — from answer N on, the answer never reaches the server
  and the browser is sent back to the question. This is a failure a retry repeats (#175).
- **Tests.** `test/long_run.test.js` runs the real worker and a real browser against a stand-in for Moodle
  (`testlib/fake_moodle.js`): 999, 1000, 1001 and 2500 answers, a retry from 264, and the faults.
  - `CATQUIZLAB_LONG_RUN=0` keeps it to 1001 answers.
  - `CATQUIZLAB_WORKER_SCRIPT` runs it against another worker, for example 0.7.83's, as a negative control.

## Roadmap

See `../docs/design/backlog.md`, epic E3.
