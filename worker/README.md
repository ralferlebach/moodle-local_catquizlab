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

## Roadmap

See `../docs/design/backlog.md`, epic E3.
