/**
 * Puppeteer worker for local_catquizlab (backlog E3.2 / E3.3).
 *
 * Polls the plugin's job queue and plays each claimed attempt through the real
 * mod_adaptivequiz UI so the CAT engine performs its own adaptive item
 * selection. For every presented question it asks the plugin's response oracle
 * for a model-consistent, seed-deterministic decision, sets and submits it, and
 * loops until the engine ends the attempt. It then reports the outcome (and the
 * engine attempt id, so the plugin can collect the trace) back to the queue.
 *
 * The web service token must belong to an account with local/catquizlab:worker.
 * The simulated user is logged in per attempt; how (password or a login token)
 * depends on the deployment and is provided via --login-* options.
 *
 * Invocation (as the ad-hoc task calls it):
 *   CATQUIZLAB_WORKER_TOKEN=<wstoken> node run_attempt.js --base-url=<wwwroot> [--worker-id=<id>]
 *
 * The token comes from the environment. `--token=<wstoken>` still works, but a
 * command line is visible to every user of the machine in a process listing.
 *                       [--max-jobs=<n>] [--login-suffix=<pw>]
 *
 * This is a reference implementation. The DOM interaction is written defensively
 * against theme variation (each interaction tries a list of selectors), but the
 * selector lists and the login convention are the parts most likely to need
 * tuning to a given deployment. The pure helpers at the bottom are exported for
 * unit testing (see worker/test/).
 */

'use strict';

// puppeteer is required lazily inside launch so the pure helpers below can be
// imported (e.g. for tests) in an environment without the dependency installed.

// Selector lists, tried in order until one matches, for theme robustness.
const QUESTION_SELECTORS = ['.que', 'div[id^="question-"]', '.qtext'];
const RADIO_SELECTORS = [
    '.que .answer input[type="radio"]',
    '.que input[type="radio"]',
    'input[type="radio"]',
];
const SUBMIT_SELECTORS = [
    'input[name="submitanswer"]',
    'button[name="submitanswer"]',
    '#responseform input[type="submit"]',
    '.submitbtns input[type="submit"]',
    'form[action*="attempt.php"] input[type="submit"]',
];
const START_SELECTORS = [
    '#id_submitbutton',
    'form[action*="attempt.php"] input[type="submit"]',
    'input[type="submit"]',
    'button[type="submit"]',
    'a.btn-primary',
];

const NAV_TIMEOUT = 30000;

const args = parseArgs(process.argv.slice(2));
const BASE_URL = normaliseBaseUrl(args['base-url']);
// The token comes from the environment, not from argv: command-line arguments
// are visible in process listings, and this one opens every web service
// function the worker may call. The argument is still accepted for a manual
// run, where the person typing it already has the token in their shell history.
const TOKEN = process.env.CATQUIZLAB_WORKER_TOKEN || args.token || '';
const WORKER_ID = args['worker-id'] || 'catquizlab-worker';
const MAX_JOBS = parseInt(args['max-jobs'] || '0', 10); // 0 = until the queue is empty.
// How long to wait after reporting in before claiming work (#120): the launcher
// gives every worker its own, so that they do not all begin in the same second.
const START_DELAY_MS = startDelayMs(args['start-delay']);
const LOGIN_SUFFIX = args['login-suffix'] || '';
const LOGIN_MODE = args['login-mode'] || 'password';
const LOGIN_URL_TEMPLATE = args['login-url-template'] || '';

const SELF_TEST = args['self-test'] === true;
// Where a failed execution leaves its screenshots, DOM and events (#107), and
// whether successful ones do too ('all', for explicit debugging only).
const ARTEFACT_DIR = args['artefact-dir'] || '';
const CAPTURE = args.capture === 'all' ? 'all' : 'failure';
const nav = require('./navigation.js');

// The self test never talks to Moodle, so it must not demand credentials.
if (require.main === module && !SELF_TEST && (!BASE_URL || !TOKEN)) {
    console.error('Missing required --base-url and/or --token.');
    process.exit(2);
}

/**
 * Call a plugin web service function via the REST endpoint (JSON).
 *
 * @param {string} wsfunction The external function name.
 * @param {object} params The function parameters.
 * @returns {Promise<object>} The decoded response.
 */
async function callWs(wsfunction, params) {
    const {url, body, headers} = buildWsRequest(BASE_URL, TOKEN, wsfunction, params, currentTrace);
    const started = Date.now();
    let response = null;
    let data = null;
    try {
        response = await fetch(url, {
            method: 'POST',
            headers,
            body,
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        data = await response.json();
    } catch (error) {
        // "fetch failed" on its own said that something went wrong between
        // here and Moodle, and nothing else. What was called, where, with
        // which status or network error, after how long, and for whom — the
        // difference between a server under load and a worker misconfigured.
        const transport = describeTransportError(wsfunction, url, error, Date.now() - started,
            response ? response.status : 0, {workerid: WORKER_ID, attemptid: currentAttemptId});
        const wrapped = new Error(transport.message);
        wrapped.transport = transport.detail;
        throw wrapped;
    }
    if (data && data.exception) {
        throw new Error(`${wsfunction}: ${data.message}`);
    }
    return data;
}

/** @type {number} The attempt being played, for transport diagnoses. */
let currentAttemptId = 0;

/**
 * Claim the next queued attempt, or null when the queue is empty.
 *
 * @returns {Promise<object|null>}
 */
async function claimJob() {
    // A new job: the previous sitting's headers end here — its report went out with them.
    currentTrace = {};
    const job = await callWs('local_catquizlab_job_claim', {workerid: WORKER_ID});
    return job && job.hasjob ? job : null;
}

/**
 * Play one claimed attempt end to end and report the outcome.
 *
 * @param {object} browser The Puppeteer browser.
 * @param {object} job The claimed job (runid, attemptid, quizcmid, userid).
 * @returns {Promise<void>}
 */
async function playAttempt(browser, job) {
    const started = Date.now();

    // A context of its own per attempt. Sharing the browser's default context
    // carries the previous person's session into the next attempt, so the
    // second simulated person would have sat the test as the first — and the
    // only reason that did not happen is that the login page, already
    // authenticated, no longer offered a username field and the worker fell
    // over. An isolated context makes each attempt genuinely its own person.
    const context = typeof browser.createBrowserContext === 'function'
        ? await browser.createBrowserContext()
        : await browser.createIncognitoBrowserContext();
    const page = await context.newPage();
    page.setDefaultNavigationTimeout(NAV_TIMEOUT);
    let engineAttemptId = 0;
    let status = 'failed';
    let failure = '';

    // What the browser sees, kept for the attempt history and — on failure —
    // written out as screenshots, DOM and events (#107).
    const recorder = new nav.Recorder(page, {capture: CAPTURE});
    // Every request of this sitting carries the execution's correlation id, so
    // that a server-side error can be found by it (#107, #110).
    // And which sitting and execution it is: the server's line for each
    // request names it, and the logs page filters by it (#110).
    currentTrace = traceHeaders(job);
    if (Object.keys(currentTrace).length > 0) {
        await page.setExtraHTTPHeaders(currentTrace);
    }
    let transport = null;
    let artefacts = null;
    currentAttemptId = job.attemptid;

    try {
        await login(page, job.userid, job.username, recorder);
        await gotoSettle(page, `${BASE_URL}/mod/adaptivequiz/view.php?id=${job.quizcmid}`);
        let state = await startAttempt(page, recorder);

        // Answer loop, driven by the state each click leads to: a question, the
        // finish page, or an error page — nothing in between (#100). The loop
        // used to run "while a question is visible", and an intermediate page
        // on the way to the next question ended it as if the test were over.
        let guard = 0;
        let answeredCount = 0;
        while (state === nav.STATE.QUESTION && guard++ < 1000) {
            const {qubaid, slot} = await nav.withContextRetry(page, () => currentQuestionRef(page), 'read question', recorder);
            const decision = await callWs('local_catquizlab_oracle_answer', {
                runid: job.runid,
                // The page identifies a question by usage and slot; the browser
                // never sees a question id, so the server resolves it.
                questionid: 0,
                qubaid,
                slot,
                // The worker calls with its own token, so the server cannot
                // infer the simulated person from the logged-in user.
                attemptid: job.attemptid,
            });
            if (!decision.ready) {
                throw new Error(`Oracle not ready for usage ${qubaid} slot ${slot}: ${decision.message}`);
            }
            const answered = await nav.withContextRetry(page, () => answerQuestion(page, decision), 'answer', recorder);
            if (!answered) {
                throw new Error(`No answer option found for usage ${qubaid} slot ${slot}.`);
            }
            await recorder.snapshot();
            const result = await nav.clickAndSettle(page, SUBMIT_SELECTORS, QUESTION_SELECTORS, {
                label: `submit answer ${answeredCount + 1}`,
                timeout: NAV_TIMEOUT,
                recorder,
            });
            if (!result.clicked) {
                throw new Error(`No way to submit the answer to usage ${qubaid} slot ${slot} was found.`);
            }
            state = result.state;
            answeredCount++;
        }

        if (state === nav.STATE.ERROR) {
            const detail = await describePage(page);
            throw new Error(`Moodle showed an error page after ${answeredCount} answer(s). ${detail}`);
        }

        engineAttemptId = await readEngineAttemptId(page);

        // An attempt that answered nothing is not a finished attempt. Reporting
        // one as finished is how a run of empty attempts would look like a
        // completed experiment: the queue drains, every job reports success and
        // no trace is ever collected.
        if (answeredCount === 0) {
            const diagnosis = await collectZeroQuestionDiagnosis(page, engineAttemptId);
            throw new Error(
                'No question was presented; the attempt never started. ' + diagnosis
            );
        }

        // Only the activity's own finish page counts as finished.
        if (!(await onFinishPage(page))) {
            const detail = await describePage(page);
            throw new Error(`Attempt did not reach the finish page after ${answeredCount} answer(s). ${detail}`);
        }
        status = 'finished';

        if (CAPTURE === 'all' && ARTEFACT_DIR) {
            artefacts = await saveArtefacts(recorder, job, null);
        }
    } catch (error) {
        failure = error.message;
        transport = error.transport || null;
        console.error(`Attempt ${job.attemptid} failed: ${error.message}`);

        // Screenshots, DOM and events of the moment it failed, in moodledata
        // (#107): structured log lines rarely say what the page showed.
        if (ARTEFACT_DIR) {
            artefacts = await saveArtefacts(recorder, job, error);
        }

        // The page said what went wrong and not where: a production site shows
        // no debug information, so "Division by zero" arrived without a file
        // or a line for two days. The server can replay the selection this
        // sitting was making and catch the exception itself.
        if (/Fehler|Error|error=/.test(String(error.message))) {
            try {
                const where = await callWs('local_catquizlab_diagnose_attempt', {attemptid: job.attemptid});
                if (where && where.file) {
                    failure += ` | server replay: ${where.class} at ${where.file}:${where.line}`;
                    if (where.trace) {
                        failure += ` | trace: ${String(where.trace).split('\n').slice(0, 4).join(' <- ')}`;
                    }
                    console.error(`Attempt ${job.attemptid} located: ${where.class} at ${where.file}:${where.line}`);
                } else if (where && where.reason) {
                    failure += ` | server replay: ${where.reason}`;
                }
            } catch (diagnoseError) {
                failure += ` | (diagnosis unavailable: ${diagnoseError.message})`;
            }
        }
    } finally {
        await page.close();
        await context.close();
        const diagnosis = {browser: recorder.summary(), transport, artefacts};
        const fullpath = writeFullDiagnosis(diagnosis, job);
        // A report that fails must not hide what it was reporting (#111): an
        // exception thrown here replaced the attempt's own failure, and only
        // the transport error was left. Both are said, and kept beside the
        // artefacts; the lease then expires and the retry is recorded.
        try {
            await callWs('local_catquizlab_job_complete', {
                // The reason travels with the report. Without it the server sees a
                // failed attempt and no explanation, and the retry count is all
                // anyone has to go on. Cut visibly if it is long (#111).
                message: failure ? fitMessage(failure) : '',
                attemptid: job.attemptid,
                status,
                runtimems: Date.now() - started,
                engineattemptid: engineAttemptId,
                // The browser's record for the attempt history (#100, #107).
                diagnostics: fitDiagnostics(diagnosis, DIAGNOSTICS_LIMIT, fullpath),
            });
        } catch (reportError) {
            const both = reportFailureMessage(job.attemptid, status, failure, reportError);
            console.error(both);
            keepUnreported(job, {status, failure, diagnosis: fitDiagnostics(diagnosis), report: reportError.message});
            throw new Error(both);
        }
        currentAttemptId = 0;
    }
}

/**
 * Log in as the simulated user.
 *
 * Two modes are supported. In 'urltemplate' mode the worker navigates to a
 * pre-authenticated URL (the deployment's own key/SSO endpoint) with {userid}
 * substituted — nothing else is needed. Otherwise it uses the username/password
 * convention (a fixed rule maps a user id to a password in test setups).
 *
 * @param {object} page The Puppeteer page.
 * @param {number} userid The Moodle user id to log in as.
 * @param {string} username The username the server supplied for this job.
 * @returns {Promise<void>}
 */
async function login(page, userid, username, recorder = null, base = BASE_URL) {
    if (LOGIN_MODE === 'urltemplate' && LOGIN_URL_TEMPLATE) {
        await gotoSettle(page, loginUrlFor(LOGIN_URL_TEMPLATE, userid));
        return;
    }

    const name = username || usernameFor(userid);
    const password = passwordFor(userid, LOGIN_SUFFIX);

    // Twice at most. Moodle answers "Invalid login" for a wrong password and
    // for a login token its session no longer holds — and the latter is
    // transient: the same credentials succeeded minutes later on a retried
    // execution, which cost a whole execution and its retry delay. A fresh
    // login page brings a fresh token. A second refusal is real and reported.
    for (let round = 1; round <= 2; round++) {
        await gotoSettle(page, `${base}/login/index.php`);

        // No username field means a session is already open. With an isolated
        // context that should not happen, so it is reported rather than worked
        // around: silently continuing would run the test as whoever is logged in.
        if (!(await page.$('#username'))) {
            throw new Error('The login page offered no username field; a session is already open.');
        }

        // Set, not typed: typing appends to whatever the field holds — Moodle
        // refills the username after a failed login — and keystrokes follow
        // the focus, which the page's own script may move while they arrive.
        await page.$eval('#username', (el, value) => {
            el.value = value;
        }, name);
        await page.$eval('#password', (el, value) => {
            el.value = value;
        }, password);
        // Click and navigation together, and a navigation that does not come
        // is said, not swallowed (#100): it is recorded, and named in the
        // error should the login fail.
        const [navigation] = await Promise.allSettled([
            page.waitForNavigation({waitUntil: 'networkidle2', timeout: 30000}),
            clickFirst(page, ['#loginbtn', 'button[type="submit"]', 'input[type="submit"]']),
        ]);
        const stalled = navigation.status === 'rejected'
            ? (navigation.reason && navigation.reason.message) || 'no navigation'
            : '';
        if (stalled && recorder) {
            recorder.event('navigation-failed', `login: ${stalled}`);
        }

        // A failed login left the worker on the login page, where its start-attempt
        // selectors then matched the login button itself: it clicked away, found no
        // question and reported that the attempt never started. The real cause —
        // wrong credentials — never appeared anywhere.
        if (!page.url().includes('/login/')) {
            return;
        }
        const notice = await page.evaluate(() => {
            const el = document.querySelector('.loginerrors, .alert-danger, #loginerrormessage');
            return el ? el.innerText.trim() : '';
        });
        if (round === 1) {
            if (recorder) {
                recorder.event('login-retried', `${name}: ${notice || 'still on the login page'}`);
            }
            continue;
        }
        throw new Error(`Login as ${name} failed${notice ? ': ' + notice : '.'}`
            + (stalled ? ` (navigation after submit: ${stalled})` : ''));
    }
}

/**
 * Click through to start / continue the adaptivequiz attempt.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<void>}
 */
async function startAttempt(page, recorder) {
    // Already on a question (a resumed attempt): nothing to start.
    if (await hasQuestion(page)) {
        return nav.STATE.QUESTION;
    }
    const result = await nav.clickAndSettle(page, START_SELECTORS, QUESTION_SELECTORS, {
        label: 'start attempt',
        timeout: NAV_TIMEOUT,
        recorder,
    });
    if (!result.clicked) {
        throw new Error('No way to start the attempt was found on the activity page.');
    }
    return result.state;
}

/**
 * Whether a question is currently presented (any known layout).
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<boolean>}
 */
async function hasQuestion(page) {
    return (await firstHandle(page, QUESTION_SELECTORS)) !== null;
}

/**
 * Read the Moodle question id of the presented item from the DOM.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<number>}
 */
/**
 * Whether the browser is on the activity's regular attempt-finished page.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<boolean>}
 */
async function onFinishPage(page) {
    if (page.url().includes('attemptfinished.php')) {
        return true;
    }

    // Some themes and versions land on the activity view with a summary rather
    // than on a separate page, so a completion marker there counts too.
    return page.evaluate(() => {
        const markers = ['.adaptivequiz-finished', '#adaptivequiz-finished', '.attempt-summary'];
        return markers.some((selector) => document.querySelector(selector) !== null);
    });
}

/**
 * A short description of where the browser stands, for a failure message.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<string>}
 */
async function describePage(page) {
    const title = await page.title().catch(() => '');

    const found = await page
        .evaluate(() => {
            const clean = (s) => (s || '').replace(/\s+/g, ' ').trim();

            // Moodle puts its navigation, its site name and its user menu
            // before the error, so the first 200 characters of the body were
            // reliably the parts nobody needed. The error itself lives in one
            // of these, and reporting "Zum Hauptinhalt Client01 Startseite…"
            // instead of it is why a failure said nothing about itself.
            const wheres = [
                '.errormessage',
                '.core-error-message',
                '#region-main .alert-danger',
                '#region-main .alert',
                '.notifyproblem',
                '.alert-danger',
            ];

            let message = '';
            for (const sel of wheres) {
                const el = document.querySelector(sel);
                if (el && clean(el.innerText)) {
                    message = clean(el.innerText);
                    break;
                }
            }

            // Moodle's debug block, where the site shows it: the exception
            // class and the line that threw are the two things that turn "an
            // error" into something anybody can act on.
            let debug = '';
            const debugel = document.querySelector('.notifytiny, [data-region="debug"], pre.notifytiny');
            if (debugel) {
                debug = clean(debugel.innerText).slice(0, 400);
            }

            let code = '';
            const codeel = document.querySelector('[data-errorcode], .errorcode');
            if (codeel) {
                code = clean(codeel.getAttribute('data-errorcode') || codeel.innerText);
            }

            const main = document.querySelector('#region-main') || document.body;

            return {
                message: message,
                debug: debug,
                code: code,
                // The main region rather than the whole body, so the fallback
                // is at least the part of the page that is about this page.
                body: clean(main.innerText).slice(0, 300),
            };
        })
        .catch(() => ({message: '', debug: '', code: '', body: ''}));

    // Anything that looks like a token goes, wherever it came from: an error
    // report is a thing people paste into issues.
    const redact = (s) => (s || '')
        .replace(/([?&](?:wstoken|token|sesskey)=)[^&\s"']+/gi, '$1(hidden)')
        .replace(/\b[a-f0-9]{32}\b/gi, '(hidden)');

    const parts = [`url=${redact(page.url())}`, `title="${title}"`];

    if (found.message) {
        parts.push(`error="${redact(found.message)}"`);
    }
    if (found.code) {
        parts.push(`errorcode="${redact(found.code)}"`);
    }
    if (found.debug) {
        parts.push(`debug="${redact(found.debug)}"`);
    }
    if (!found.message) {
        parts.push(`page="${redact(found.body)}"`);
    }

    return parts.join(' ');
}

async function currentQuestionRef(page) {
    const id = await page.evaluate((selectors) => {
        for (const sel of selectors) {
            const el = document.querySelector(sel);
            if (el && el.id) {
                return el.id;
            }
        }
        return '';
    }, QUESTION_SELECTORS);

    // Moodle renders question-{qubaid}-{slot}. Taking the first number out of
    // that and calling it a question id is what made every oracle call fail.
    const parts = String(id).match(/(\d+)\D+(\d+)/);

    return parts
        ? {qubaid: parseInt(parts[1], 10), slot: parseInt(parts[2], 10)}
        : {qubaid: 0, slot: 0};
}

async function currentQuestionId(page) {
    const id = await page.evaluate((selectors) => {
        for (const sel of selectors) {
            const el = document.querySelector(sel);
            if (el && el.id) {
                return el.id;
            }
        }
        return '';
    }, QUESTION_SELECTORS);
    return parseQuestionId(id);
}

/**
 * Click the option matching the oracle's decision.
 *
 * @param {object} page The Puppeteer page.
 * @param {object} decision The oracle response ({ready, choice, fraction}).
 * @returns {Promise<boolean>} Whether an option was clicked.
 */
async function answerQuestion(page, decision) {
    const options = await allHandles(page, RADIO_SELECTORS);
    if (options.length === 0) {
        return false;
    }
    const index = chooseOptionIndex(decision, options.length);
    await options[index].click();
    return true;
}

/**
 * Read the adaptivequiz_attempt id from the finished attempt page/URL.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<number>}
 */
async function readEngineAttemptId(page) {
    return parseEngineAttemptId(page.url());
}

/**
 * Navigate to a URL and wait for the network to settle, tolerating slow idles.
 *
 * @param {object} page The Puppeteer page.
 * @param {string} url The destination URL.
 * @returns {Promise<void>}
 */
async function gotoSettle(page, url) {
    let firstError = null;

    try {
        await page.goto(url, {waitUntil: 'networkidle2'});
    } catch (error) {
        firstError = error;
        try {
            await page.goto(url, {waitUntil: 'domcontentloaded'});
        } catch (second) {
            // Both swallowed, the page stayed wherever it was, and the next
            // step reported whatever it failed to find there. The symptom was
            // "no question was presented" on the dashboard — true, and three
            // steps away from the cause.
            throw new Error(`Could not open ${url}: ${second.message}`);
        }
    }

    // Arriving somewhere else is its own failure: a redirect to the login page
    // or the dashboard means the session or the permission is wrong, and
    // neither is visible from the page that comes next.
    const landed = page.url();
    const wanted = url.split('?')[0];
    if (!landed.startsWith(wanted)) {
        throw new Error(
            `Expected ${url} but landed on ${landed}`
            + (firstError ? ` (first attempt: ${firstError.message})` : '')
        );
    }
}

/**
 * Return the first element handle matching any of the selectors, or null.
 *
 * @param {object} page The Puppeteer page.
 * @param {string[]} selectors The selectors to try in order.
 * @returns {Promise<object|null>}
 */
async function firstHandle(page, selectors) {
    for (const selector of selectors) {
        const handle = await page.$(selector);
        if (handle) {
            return handle;
        }
    }
    return null;
}

/**
 * Return the handles of the first selector that matches any elements.
 *
 * @param {object} page The Puppeteer page.
 * @param {string[]} selectors The selectors to try in order.
 * @returns {Promise<object[]>}
 */
async function allHandles(page, selectors) {
    for (const selector of selectors) {
        const handles = await page.$$(selector);
        if (handles.length > 0) {
            return handles;
        }
    }
    return [];
}

/**
 * Click the first present element among the selectors.
 *
 * @param {object} page The Puppeteer page.
 * @param {string[]} selectors The selectors to try in order.
 * @returns {Promise<boolean>} Whether something was clicked.
 */
async function clickFirst(page, selectors) {
    const handle = await firstHandle(page, selectors);
    if (!handle) {
        return false;
    }
    await handle.click();
    return true;
}

/**
 * Write an execution's artefacts to moodledata (#107).
 *
 * @param {object} recorder The attempt's recorder.
 * @param {object} job The claimed job.
 * @param {?Error} error What went wrong, or null for a debug capture.
 * @returns {Promise<?object>} Where they went and what was written.
 */
async function saveArtefacts(recorder, job, error) {
    const relative = artefactPath(job);
    try {
        const files = await recorder.save(require('path').join(ARTEFACT_DIR, relative), {
            experimentid: job.experimentid || 0,
            runid: job.runid,
            attemptid: job.attemptid,
            execution: job.execution || 0,
            workerid: WORKER_ID,
            correlationid: job.correlationid || '',
        }, error);
        return {path: relative, files};
    } catch (saveError) {
        console.error(`Artefacts of attempt ${job.attemptid} could not be written: ${saveError.message}`);
        return {path: relative, files: [], error: saveError.message};
    }
}

// ---------------------------------------------------------------------------
// Pure helpers (no Puppeteer / no network) — exported for unit testing.
// ---------------------------------------------------------------------------

/**
 * Parse --key=value CLI arguments into a plain object.
 *
 * @param {string[]} argv The argument list (without node/script).
 * @returns {object}
 */
function parseArgs(argv) {
    return Object.fromEntries(
        (argv || [])
            .filter((a) => a.startsWith('--'))
            .map((a) => {
                const [k, ...v] = a.replace(/^--/, '').split('=');
                return [k, v.join('=') || true];
            })
    );
}

/**
 * Normalise a base URL by stripping trailing slashes.
 *
 * @param {string} value The raw base URL.
 * @returns {string}
 */
function normaliseBaseUrl(value) {
    return (value || '').replace(/\/+$/, '');
}

/**
 * Build the REST web-service URL for a function call.
 *
 * @param {string} baseUrl The Moodle wwwroot.
 * @param {string} token The web-service token.
 * @param {string} wsfunction The function name.
 * @param {object} params The function parameters.
 * @returns {string}
 */
/** @type {object} The trace headers of the sitting being played, sent with every web service call too. */
let currentTrace = {};

/**
 * The headers that tie a request to its sitting on the server (#107, #110).
 *
 * @param {object} job The claimed job.
 * @returns {object} Header name to value; empty without a correlation id.
 */
function traceHeaders(job) {
    if (!job || !job.correlationid) {
        return {};
    }
    return {
        'X-CatQuizLab-Correlation': String(job.correlationid),
        'X-CatQuizLab-Attempt': `${Number(job.attemptid) || 0}.${Number(job.execution) || 0}`,
    };
}

function buildWsRequest(baseUrl, token, wsfunction, params, trace = {}) {
    // Everything in the body (#111): the parameters — a diagnosis can be tens
    // of kilobytes, which as a query string made the server answer 414 and the
    // report of a failure fail in turn — and the token, which in a URL ends up
    // in every access log. Moodle's REST server reads all of them from POST.
    const body = new URLSearchParams();
    body.set('wstoken', token);
    body.set('wsfunction', wsfunction);
    body.set('moodlewsrestformat', 'json');
    for (const [k, v] of Object.entries(params || {})) {
        body.set(k, String(v));
    }
    return {
        url: `${normaliseBaseUrl(baseUrl)}/webservice/rest/server.php`,
        body: body.toString(),
        headers: {'Content-Type': 'application/x-www-form-urlencoded', ...(trace || {})},
    };
}

/** @type {number} The largest diagnosis sent with a report, in bytes; larger ones are cut by structure. */
const DIAGNOSTICS_LIMIT = 60000;

/** @type {number} The longest failure message sent with a report, in characters. */
const MESSAGE_LIMIT = 4000;

/**
 * A failure message within the limit — cut visibly, never silently (#111).
 *
 * @param {string} message The message.
 * @param {number} limit The limit in characters.
 * @returns {string}
 */
function fitMessage(message, limit = MESSAGE_LIMIT) {
    const text = String(message || '');
    if (text.length <= limit) {
        return text;
    }
    const note = ` … [truncated: ${text.length - limit + 80} of ${text.length} characters cut; full text in the artefacts]`;
    return text.slice(0, limit - note.length) + note;
}

/**
 * A diagnosis within the limit, cut by its structure and saying so (#111).
 *
 * Cutting a JSON text at a byte count leaves a text that is not JSON, and the
 * server dropped the whole diagnosis: that is what the old slice did. Here the
 * long strings are shortened and the oldest browser events dropped until it
 * fits; what was cut is said in the diagnosis itself, and the full one is
 * written beside the artefacts, its path sent instead.
 *
 * @param {object} diagnosis The diagnosis.
 * @param {number} limit The limit in bytes.
 * @param {?string} fullpath Where the full diagnosis was written, if it was.
 * @returns {string} JSON, at most the limit long.
 */
function fitDiagnostics(diagnosis, limit = DIAGNOSTICS_LIMIT, fullpath = null) {
    const full = JSON.stringify(diagnosis);
    if (Buffer.byteLength(full) <= limit) {
        return full;
    }
    const copy = JSON.parse(full);
    const shorten = (value, max) => {
        if (typeof value === 'string') {
            return value.length > max ? value.slice(0, max) + ` … [${value.length - max} characters cut]` : value;
        }
        if (Array.isArray(value)) {
            return value.map((v) => shorten(v, max));
        }
        if (value && typeof value === 'object') {
            const out = {};
            for (const [k, v] of Object.entries(value)) {
                out[k] = shorten(v, max);
            }
            return out;
        }
        return value;
    };
    let fitted = copy;
    for (const max of [4000, 1000, 300, 100]) {
        fitted = shorten(copy, max);
        fitted.truncated = {originalbytes: Buffer.byteLength(full), limit, full: fullpath};
        // The oldest browser events go first: the last ones are nearest the failure.
        const events = fitted.browser && Array.isArray(fitted.browser.events) ? fitted.browser.events : null;
        while (Buffer.byteLength(JSON.stringify(fitted)) > limit && events && events.length > 20) {
            events.splice(0, Math.ceil(events.length / 4));
            fitted.truncated.eventsdropped = true;
        }
        if (Buffer.byteLength(JSON.stringify(fitted)) <= limit) {
            return JSON.stringify(fitted);
        }
    }
    // Still too large: the marker and the path, which always fit.
    return JSON.stringify({truncated: {originalbytes: Buffer.byteLength(full), limit, full: fullpath, structure: 'dropped'}});
}

/**
 * Parse a numeric question id from a `.que` element id (e.g. "question-12-34").
 *
 * @param {string} elementId The element id.
 * @returns {number}
 */
function parseQuestionId(elementId) {
    if (!elementId) {
        return 0;
    }
    const match = String(elementId).match(/(\d+)/);
    return match ? parseInt(match[1], 10) : 0;
}

/**
 * Parse the engine attempt id from an attempt URL (attempt=N).
 *
 * @param {string} url The page URL.
 * @returns {number}
 */
function parseEngineAttemptId(url) {
    const match = String(url || '').match(/[?&]attempt=(\d+)/);
    return match ? parseInt(match[1], 10) : 0;
}

/**
 * The simulated user's username for a Moodle user id (naming convention).
 *
 * @param {number} userid The Moodle user id.
 * @returns {string}
 */
function usernameFor(userid) {
    return `catlab_user_${userid}`;
}

/**
 * The simulated user's password for a Moodle user id (test convention).
 *
 * @param {number} userid The Moodle user id.
 * @param {string} suffix The configured login suffix.
 * @returns {string}
 */
function passwordFor(userid, suffix) {
    return `${userid}${suffix || ''}`;
}

/**
 * Substitute the user id into a pre-authenticated login URL template.
 *
 * @param {string} template A URL with a {userid} placeholder.
 * @param {number} userid The Moodle user id.
 * @returns {string}
 */
function loginUrlFor(template, userid) {
    return String(template || '').replace(/\{userid\}/g, String(userid));
}

/**
 * Select the on-screen option index matching the oracle's decision.
 *
 * Questions are created single-select with answer shuffling disabled, so the
 * on-screen option order equals the definition order. For a polytomous item the
 * oracle returns an ordered category (choice >= 0), which is the index of the
 * graded option to select; for a dichotomous item (choice === -1) it returns the
 * score fraction, and the correct option is defined first.
 *
 * @param {object} decision The oracle response ({choice, fraction}).
 * @param {number} count The number of on-screen options.
 * @returns {number} The option index to click.
 */
function chooseOptionIndex(decision, count) {
    const choice = decision && typeof decision.choice === 'number' ? decision.choice : -1;
    const fraction = decision && typeof decision.fraction === 'number' ? decision.fraction : 0;

    if (choice >= 0) {
        // Polytomous: category k is the k-th option (definition order, no shuffle).
        return Math.max(0, Math.min(choice, count - 1));
    }
    // Dichotomous 1-of-N: the correct option is first (fraction 1.0 at index 0).
    return fraction >= 0.5 ? 0 : Math.min(1, count - 1);
}

/**
 * Offline self test: proves the toolchain works without touching Moodle.
 *
 * A smoke test that pointed the real worker at an unreachable host was not a
 * smoke test — it exercised the polling loop, hit DNS, and failed by design,
 * which told nobody anything about the toolchain. This checks what a toolchain
 * job can actually check: that the arguments parse, that the URL builder
 * produces a well-formed endpoint, and that Puppeteer loads and can start and
 * stop a browser. It claims no job and calls no web service.
 *
 * @returns {Promise<void>} Resolves when every check passed; rejects on the first failure.
 */
/**
 * What the page shows when no question appeared.
 *
 * Kept short on purpose: this ends up in a database column and in a list in the
 * interface, where a stack trace would push out the part that matters.
 *
 * @param {object} page The Puppeteer page.
 * @param {number} engineAttemptId The engine attempt, when one was read.
 * @returns {Promise<string>} A one-line diagnosis.
 */
async function collectZeroQuestionDiagnosis(page, engineAttemptId) {
    const parts = [];

    try {
        parts.push(`url=${page.url()}`);
        parts.push(`title=${(await page.title()).slice(0, 80)}`);

        // Moodle renders its own errors and notifications in known containers;
        // the generic body text is the fallback when neither is present.
        const message = await page.evaluate(() => {
            const selectors = [
                '.errormessage', '.alert-danger', '#region-main .alert',
                '.notifyproblem', '.core-error-message',
            ];
            for (const selector of selectors) {
                const node = document.querySelector(selector);
                if (node && node.innerText.trim()) {
                    return node.innerText.trim();
                }
            }
            const main = document.querySelector('#region-main') || document.body;
            return main.innerText.trim().slice(0, 400);
        });
        if (message) {
            parts.push(`page=${message.replace(/\s+/g, ' ').slice(0, 240)}`);
        }
    } catch (error) {
        parts.push(`diagnosis unavailable: ${error.message}`);
    }

    if (engineAttemptId) {
        parts.push(`engineattempt=${engineAttemptId}`);
    }

    return parts.join(' | ');
}

/**
 * Report in every few seconds while an attempt is being played.
 *
 * A claim and a completion are minutes apart, so without this a working worker
 * is silent for exactly as long as the timeout that declares it dead — and the
 * registry has to guess which it is. The returned stop flag is how a worker is
 * asked to finish and exit: ending the process instead would leave the claim it
 * holds with nobody to complete it.
 *
 * @param {number} attemptId The attempt being played, or 0 between jobs.
 * @param {string} state What the worker is doing.
 * @returns {object} A handle with stop() and shouldStop().
 */
function startHeartbeat(attemptId, state) {
    let asked = false;

    const beat = async() => {
        try {
            const reply = await callWs('local_catquizlab_worker_heartbeat', {
                workerid: WORKER_ID,
                attemptid: attemptId,
                state: state,
            });
            if (reply && reply.stop) {
                asked = true;
            }
        } catch (error) {
            // A missed heartbeat is not worth abandoning an attempt that is
            // halfway through: the next one is seconds away, and the lease is
            // generous enough to survive a few.
        }
    };

    beat();
    const timer = setInterval(beat, 20000);

    return {
        stop: () => clearInterval(timer),
        shouldStop: () => asked,
    };
}

/**
 * The staggered start's delay as given on the command line, in milliseconds.
 *
 * @param {*} value The argument's value.
 * @returns {number} Zero for none, or anything that is not a positive number; at most an hour.
 */
function startDelayMs(value) {
    const ms = parseInt(value, 10);
    return Number.isFinite(ms) && ms > 0 ? Math.min(ms, 3600000) : 0;
}

/**
 * Wait for this worker's turn to begin, reporting in while it waits (#120).
 *
 * Workers started together took the server from no load to thousands of
 * requests a minute within seconds; each now begins a set interval after the
 * one before. A waiting worker reports as "waiting", so that it is neither
 * taken for dead nor for one that has nothing to do — and a stop asked of it
 * while it waits is granted at once: it holds no claim.
 *
 * @param {number} delayMs How long to wait.
 * @param {Function} beat Reports in; resolves to the web service's reply.
 * @param {Function} sleep Waits the given milliseconds.
 * @param {number} every How often to report, in milliseconds.
 * @returns {Promise<boolean>} False where the worker was asked to stop while waiting.
 */
async function waitForStart(delayMs, beat, sleep, every = 20000) {
    let left = delayMs;
    while (left > 0) {
        try {
            const reply = await beat();
            if (reply && reply.stop) {
                return false;
            }
        } catch (error) {
            // A missed report while waiting changes nothing: the next follows.
        }
        const step = Math.min(left, every);
        await sleep(step);
        left -= step;
    }
    return true;
}

async function selfTest() {
    const failures = [];
    const check = (label, condition) => {
        if (condition) {
            console.log(`ok   ${label}`);
        } else {
            failures.push(label);
            console.error(`FAIL ${label}`);
        }
    };

    // Which user, and with which paths. A self-test run by hand passes as the
    // interactive user and the same worker fails from cron as the web server
    // user, so the context has to be part of the output — otherwise the two
    // runs are indistinguishable in a report.
    console.log(`info user=${process.env.USER || process.env.LOGNAME || '(unset)'} uid=${typeof process.getuid === 'function' ? process.getuid() : '?'}`);
    console.log(`info HOME=${process.env.HOME || '(unset)'}`);
    console.log(`info PUPPETEER_CACHE_DIR=${process.env.PUPPETEER_CACHE_DIR || '(unset)'}`);
    console.log(`info XDG_CONFIG_HOME=${process.env.XDG_CONFIG_HOME || '(unset)'}`);

    check('node >= 20', parseInt(process.versions.node.split('.')[0], 10) >= 20);
    check('home is writable', (() => {
        // The failure this catches reads as EACCES on mkdir deep inside
        // Puppeteer, which looks like a plugin problem and is not.
        try {
            const fs = require('fs');
            const home = process.env.HOME;
            if (!home) { return false; }
            fs.mkdirSync(home, {recursive: true});
            fs.accessSync(home, fs.constants.W_OK);
            return true;
        } catch (e) {
            return false;
        }
    })());
    check('fetch is available', typeof fetch === 'function');

    const parsed = parseArgs(['--base-url=http://example.test/moodle/', '--token=t', '--headless']);
    check('argument parsing', parsed['base-url'] === 'http://example.test/moodle/' && parsed.headless === true);
    check('base url normalisation', normaliseBaseUrl('http://x/moodle///') === 'http://x/moodle');

    const request = buildWsRequest('http://x/', 'tok', 'local_catquizlab_job_claim', {workerid: 'w1'});
    check('web service request', request.url === 'http://x/webservice/rest/server.php'
        && request.body.includes('wsfunction=local_catquizlab_job_claim'));

    check('start delay', startDelayMs('40000') === 40000 && startDelayMs('-5') === 0 && startDelayMs(undefined) === 0);
    check('dichotomous choice', chooseOptionIndex({fraction: 1.0, choice: -1}, 4) === 0);
    check('polytomous choice', chooseOptionIndex({fraction: 0.5, choice: 2}, 4) === 2);

    // The browser is what the old smoke test was really meant to prove: that
    // Puppeteer resolved and its Chromium download works on this runner.
    let puppeteer;
    try {
        puppeteer = require('puppeteer');
        check('puppeteer loads', typeof puppeteer.launch === 'function');
    } catch (error) {
        check(`puppeteer loads (${error.message})`, false);
    }

    if (puppeteer && !args['no-browser']) {
        try {
            const browser = await puppeteer.launch({headless: 'new', args: ['--no-sandbox']});
            const version = await browser.version();
            await browser.close();
            check(`browser starts (${version})`, true);
        } catch (error) {
            check(`browser starts (${error.message})`, false);
        }
    }

    if (failures.length > 0) {
        throw new Error(`Self test failed: ${failures.join(', ')}`);
    }
    console.log('Worker self test passed; no Moodle instance was contacted.');
}

/**
 * Main polling loop: claim and play attempts until the queue is empty or the
 * job budget is exhausted.
 *
 * @returns {Promise<void>}
 */
async function main() {
    const puppeteer = require('puppeteer');
    const browser = await puppeteer.launch({headless: 'new', args: ['--no-sandbox']});

    // Say so, before claiming anything. Everything above this line has now
    // happened: Node ran, Puppeteer found a browser, and the web service
    // answered. Moodle's launcher waits for exactly this, because a
    // backgrounded shell command returning tells it none of those things — and
    // reporting "1 worker started" on that basis is what put "workers: 1"
    // beside "250 claimable, 0 in progress".
    //
    // A failure here is fatal on purpose: a worker that cannot reach Moodle has
    // nothing to do, and one that stays up anyway holds a slot for nothing.
    await callWs('local_catquizlab_worker_heartbeat', {
        workerid: WORKER_ID,
        attemptid: 0,
        state: 'starting',
    });

    // Not all at once (#120): wait for this worker's own start time.
    let begin = true;
    if (START_DELAY_MS > 0) {
        console.log(`Worker ${WORKER_ID} waits ${Math.round(START_DELAY_MS / 1000)} s for its staggered start.`);
        begin = await waitForStart(
            START_DELAY_MS,
            () => callWs('local_catquizlab_worker_heartbeat', {workerid: WORKER_ID, attemptid: 0, state: 'waiting'}),
            (ms) => new Promise((resolve) => setTimeout(resolve, ms))
        );
    }

    let played = 0;

    // Why this worker stopped. "finished; played 1 attempt(s)" with 250 waiting
    // is alarming or entirely routine depending on the reason, and the log said
    // nothing either way.
    let reason = 'queue-empty';

    try {
        for (;;) {
            if (!begin) {
                console.log(`Worker ${WORKER_ID} was asked to stop while waiting for its start.`);
                reason = 'stop-requested';
                break;
            }
            if (MAX_JOBS > 0 && played >= MAX_JOBS) {
                reason = 'max-jobs';
                break;
            }
            const job = await claimJob();
            if (!job) {
                reason = 'queue-empty';
                break;
            }

            // Reports every few seconds for as long as this attempt takes, and
            // carries back whether somebody has asked this worker to stop.
            const heart = startHeartbeat(job.attemptid, 'working');
            try {
                await playAttempt(browser, job);
            } finally {
                heart.stop();
            }
            played++;

            if (heart.shouldStop()) {
                // Asked to stop while playing: the attempt was finished and
                // reported first. Stopping any earlier would leave a claim
                // behind, which is the state the whole lease mechanism exists
                // to prevent.
                console.log(`Worker ${WORKER_ID} was asked to stop; finishing after this attempt.`);
                reason = 'stop-requested';
                break;
            }
        }
    } catch (error) {
        reason = 'fatal-error';
        throw error;
    } finally {
        await browser.close();
    }

    // The slot goes back deliberately rather than by timing out: a worker that
    // ended normally should not hold a place for the length of the heartbeat
    // timeout, and a crashed one should not look like this.
    try {
        // The reason travels with the last report. Without it the server saw
        // "stopping" and started a replacement whatever the cause — including
        // for a worker it had itself just asked to stop.
        await callWs('local_catquizlab_worker_heartbeat', {
            workerid: WORKER_ID,
            attemptid: 0,
            state: 'stopping',
            reason: reason,
        });
    } catch (error) {
        // Nothing to do about it here; the reaper will notice in its own time.
    }

    console.log(`Worker ${WORKER_ID} finished; played ${played} attempt(s); reason=${reason}.`);
}

if (require.main === module) {
    const entry = SELF_TEST ? selfTest : main;
    entry().catch(async (error) => {
        console.error(error);

        // Hand the slot back before dying. A worker that crashed — as this one
        // did on a web service error — kept its slot until its heartbeat
        // lapsed five minutes later, during which the page said "1 worker
        // running", the pipeline said "all-slots-busy", and nothing ran.
        if (!SELF_TEST) {
            try {
                await callWs('local_catquizlab_worker_heartbeat', {
                    workerid: WORKER_ID,
                    attemptid: 0,
                    state: 'stopping',
                    reason: 'fatal-error',
                });
                console.error(`Worker ${WORKER_ID} released its slot after a fatal error.`);
            } catch (reportError) {
                // The web service is the thing that just failed, so this may
                // fail too. The registry's own check for a vanished process
                // covers what this cannot.
                console.error(`Worker ${WORKER_ID} could not release its slot: ${reportError.message}`);
            }
        }

        process.exit(1);
    });
}

/**
 * The directory of one execution's artefacts, below the artefact root.
 *
 * @param {object} job The claimed job.
 * @returns {string}
 */
/**
 * Write the full diagnosis beside the artefacts when it is larger than a report carries (#111).
 *
 * @param {object} diagnosis The diagnosis.
 * @param {object} job The job.
 * @returns {?string} The path relative to the artefact root, or null.
 */
function writeFullDiagnosis(diagnosis, job) {
    const text = JSON.stringify(diagnosis, null, 1);
    if (Buffer.byteLength(text) <= DIAGNOSTICS_LIMIT || !ARTEFACT_DIR) {
        return null;
    }
    try {
        const path = require('path');
        const relative = path.join(artefactPath(job), 'diagnosis-full.json');
        require('fs').mkdirSync(path.dirname(path.join(ARTEFACT_DIR, relative)), {recursive: true});
        require('fs').writeFileSync(path.join(ARTEFACT_DIR, relative), text);
        return relative;
    } catch (error) {
        console.error(`The full diagnosis of attempt ${job.attemptid} could not be written: ${error.message}`);
        return null;
    }
}

/**
 * What to say when the report of an attempt fails: the attempt's own outcome first.
 *
 * @param {number} attemptid The attempt.
 * @param {string} status Its outcome.
 * @param {string} failure Its failure, if it failed.
 * @param {Error} reportError Why the report failed.
 * @returns {string}
 */
function reportFailureMessage(attemptid, status, failure, reportError) {
    return `Attempt ${attemptid} ${status}${failure ? ': ' + failure : ''} — and its report to Moodle failed: `
        + `${reportError && reportError.message ? reportError.message : String(reportError)}`;
}

/**
 * Keep an outcome that could not be reported, beside the attempt's artefacts.
 *
 * @param {object} job The job.
 * @param {object} record What was to be reported, and why it was not.
 * @returns {void}
 */
function keepUnreported(job, record) {
    if (!ARTEFACT_DIR) {
        return;
    }
    try {
        const path = require('path');
        const file = path.join(ARTEFACT_DIR, artefactPath(job), 'report-failed.json');
        require('fs').mkdirSync(path.dirname(file), {recursive: true});
        require('fs').writeFileSync(file, JSON.stringify(record, null, 1));
    } catch (error) {
        console.error(`The unreported outcome of attempt ${job.attemptid} could not be kept: ${error.message}`);
    }
}

function artefactPath(job) {
    return [
        `experiment-${job.experimentid || 0}`,
        `run-${job.runid || 0}`,
        `attempt-${job.attemptid || 0}`,
        `execution-${job.execution || 0}`,
    ].join('/');
}

/**
 * What a failed web service call can say about itself, without its token.
 *
 * @param {string} wsfunction The function called.
 * @param {string} url The URL called.
 * @param {Error} error What fetch threw.
 * @param {number} elapsedms How long it took.
 * @param {number} status The HTTP status, 0 when there was no response.
 * @param {object} who workerid and attemptid.
 * @returns {{message: string, detail: object}}
 */
function describeTransportError(wsfunction, url, error, elapsedms, status, who) {
    const cause = (error && error.cause) || {};
    const code = cause.code || (error && error.code) || '';
    let where = '';
    try {
        const parsed = new URL(url);
        where = `${parsed.origin}${parsed.pathname}`;
    } catch (parseError) {
        where = '(unparseable URL)';
    }
    const detail = {
        wsfunction,
        url: where,
        status,
        error: error ? error.message : '',
        code,
        cause: cause.message || '',
        elapsedms,
        workerid: who.workerid || '',
        attemptid: who.attemptid || 0,
    };
    const parts = [`${wsfunction} failed after ${elapsedms} ms`, `at ${where}`];
    if (status) {
        parts.push(`HTTP ${status}`);
    }
    if (code) {
        parts.push(code);
    }
    parts.push(`: ${detail.error}${detail.cause ? ` (${detail.cause})` : ''}`);
    return {message: parts.join(' '), detail};
}

module.exports = {
    login,
    describeTransportError,
    artefactPath,
    selfTest,
    parseArgs,
    startDelayMs,
    waitForStart,
    normaliseBaseUrl,
    buildWsRequest,
    traceHeaders,
    reportFailureMessage,
    fitDiagnostics,
    fitMessage,
    DIAGNOSTICS_LIMIT,
    parseQuestionId,
    parseEngineAttemptId,
    usernameFor,
    passwordFor,
    loginUrlFor,
    chooseOptionIndex,
};
