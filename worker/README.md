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

## Roadmap

See `../docs/design/backlog.md`, epic E3.
