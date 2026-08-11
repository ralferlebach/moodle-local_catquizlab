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
 *   node run_attempt.js --base-url=<wwwroot> --token=<wstoken> [--worker-id=<id>]
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
const TOKEN = args.token || '';
const WORKER_ID = args['worker-id'] || 'catquizlab-worker';
const MAX_JOBS = parseInt(args['max-jobs'] || '0', 10); // 0 = until the queue is empty.
const LOGIN_SUFFIX = args['login-suffix'] || '';
const LOGIN_MODE = args['login-mode'] || 'password';
const LOGIN_URL_TEMPLATE = args['login-url-template'] || '';

if (require.main === module && (!BASE_URL || !TOKEN)) {
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
    const url = buildWsUrl(BASE_URL, TOKEN, wsfunction, params);
    const response = await fetch(url, {method: 'POST'});
    const data = await response.json();
    if (data && data.exception) {
        throw new Error(`${wsfunction}: ${data.message}`);
    }
    return data;
}

/**
 * Claim the next queued attempt, or null when the queue is empty.
 *
 * @returns {Promise<object|null>}
 */
async function claimJob() {
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
    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(NAV_TIMEOUT);
    let engineAttemptId = 0;
    let status = 'failed';

    try {
        await login(page, job.userid);
        await gotoSettle(page, `${BASE_URL}/mod/adaptivequiz/view.php?id=${job.quizcmid}`);
        await startAttempt(page);

        // Answer loop: while a question is on screen, ask the oracle and submit.
        let guard = 0;
        while (await hasQuestion(page) && guard++ < 1000) {
            const questionId = await currentQuestionId(page);
            const decision = await callWs('local_catquizlab_oracle_answer', {
                runid: job.runid,
                questionid: questionId,
            });
            if (!decision.ready) {
                throw new Error(`Oracle not ready for question ${questionId}: ${decision.message}`);
            }
            const answered = await answerQuestion(page, decision);
            if (!answered) {
                throw new Error(`No answer option found for question ${questionId}.`);
            }
            await submitQuestion(page);
        }

        engineAttemptId = await readEngineAttemptId(page);
        status = 'finished';
    } catch (error) {
        console.error(`Attempt ${job.attemptid} failed: ${error.message}`);
    } finally {
        await page.close();
        await callWs('local_catquizlab_job_complete', {
            attemptid: job.attemptid,
            status,
            runtimems: Date.now() - started,
            engineattemptid: engineAttemptId,
        });
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
 * @returns {Promise<void>}
 */
async function login(page, userid) {
    if (LOGIN_MODE === 'urltemplate' && LOGIN_URL_TEMPLATE) {
        await gotoSettle(page, loginUrlFor(LOGIN_URL_TEMPLATE, userid));
        return;
    }

    await gotoSettle(page, `${BASE_URL}/login/index.php`);
    await page.type('#username', usernameFor(userid));
    await page.type('#password', passwordFor(userid, LOGIN_SUFFIX));
    await Promise.all([
        clickFirst(page, ['#loginbtn', 'button[type="submit"]', 'input[type="submit"]']),
        page.waitForNavigation({waitUntil: 'networkidle2'}).catch(() => {}),
    ]);
}

/**
 * Click through to start / continue the adaptivequiz attempt.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<void>}
 */
async function startAttempt(page) {
    // Only navigate if there is no question yet (the view page shows a start form).
    if (await hasQuestion(page)) {
        return;
    }
    const clicked = await clickFirst(page, START_SELECTORS);
    if (clicked) {
        await page.waitForNavigation({waitUntil: 'networkidle2'}).catch(() => {});
    }
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
 * Submit the current question and advance.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<void>}
 */
async function submitQuestion(page) {
    const clicked = await clickFirst(page, SUBMIT_SELECTORS);
    if (clicked) {
        await page.waitForNavigation({waitUntil: 'networkidle2'}).catch(() => {});
    }
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
    await page.goto(url, {waitUntil: 'networkidle2'}).catch(() => page.goto(url, {waitUntil: 'domcontentloaded'}));
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
function buildWsUrl(baseUrl, token, wsfunction, params) {
    const url = new URL(`${normaliseBaseUrl(baseUrl)}/webservice/rest/server.php`);
    url.searchParams.set('wstoken', token);
    url.searchParams.set('wsfunction', wsfunction);
    url.searchParams.set('moodlewsrestformat', 'json');
    for (const [k, v] of Object.entries(params || {})) {
        url.searchParams.set(k, String(v));
    }
    return url.toString();
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
 * Main polling loop: claim and play attempts until the queue is empty or the
 * job budget is exhausted.
 *
 * @returns {Promise<void>}
 */
async function main() {
    const puppeteer = require('puppeteer');
    const browser = await puppeteer.launch({headless: 'new', args: ['--no-sandbox']});
    let played = 0;
    try {
        for (;;) {
            if (MAX_JOBS > 0 && played >= MAX_JOBS) {
                break;
            }
            const job = await claimJob();
            if (!job) {
                break;
            }
            await playAttempt(browser, job);
            played++;
        }
    } finally {
        await browser.close();
    }
    console.log(`Worker ${WORKER_ID} finished; played ${played} attempt(s).`);
}

if (require.main === module) {
    main().catch((error) => {
        console.error(error);
        process.exit(1);
    });
}

module.exports = {
    parseArgs,
    normaliseBaseUrl,
    buildWsUrl,
    parseQuestionId,
    parseEngineAttemptId,
    usernameFor,
    passwordFor,
    loginUrlFor,
    chooseOptionIndex,
};
