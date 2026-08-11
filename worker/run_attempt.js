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
 * This is a reference implementation; selectors may need tuning to the theme.
 */

'use strict';

const puppeteer = require('puppeteer');

const args = Object.fromEntries(
    process.argv.slice(2)
        .filter((a) => a.startsWith('--'))
        .map((a) => {
            const [k, ...v] = a.replace(/^--/, '').split('=');
            return [k, v.join('=') || true];
        })
);

const BASE_URL = (args['base-url'] || '').replace(/\/+$/, '');
const TOKEN = args.token || '';
const WORKER_ID = args['worker-id'] || 'catquizlab-worker';
const MAX_JOBS = parseInt(args['max-jobs'] || '0', 10); // 0 = until the queue is empty.
const LOGIN_SUFFIX = args['login-suffix'] || '';

if (!BASE_URL || !TOKEN) {
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
    const url = new URL(`${BASE_URL}/webservice/rest/server.php`);
    url.searchParams.set('wstoken', TOKEN);
    url.searchParams.set('wsfunction', wsfunction);
    url.searchParams.set('moodlewsrestformat', 'json');
    for (const [k, v] of Object.entries(params)) {
        url.searchParams.set(k, String(v));
    }
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
    let engineAttemptId = 0;
    let status = 'failed';

    try {
        await login(page, job.userid);
        await page.goto(`${BASE_URL}/mod/adaptivequiz/view.php?id=${job.quizcmid}`, {waitUntil: 'networkidle2'});
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
            await answerQuestion(page, decision.fraction);
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
 * Log in as the simulated user. The suffix convention lets a fixed rule map a
 * user id to a password in test setups; replace with a login-token flow if used.
 *
 * @param {object} page The Puppeteer page.
 * @param {number} userid The Moodle user id to log in as.
 * @returns {Promise<void>}
 */
async function login(page, userid) {
    await page.goto(`${BASE_URL}/login/index.php`, {waitUntil: 'networkidle2'});
    // Username convention comes from the plugin's naming engine; adjust as needed.
    await page.type('#username', `catlab_user_${userid}`);
    await page.type('#password', `${userid}${LOGIN_SUFFIX}`);
    await Promise.all([
        page.click('#loginbtn'),
        page.waitForNavigation({waitUntil: 'networkidle2'}),
    ]);
}

/**
 * Click through to start / continue the adaptivequiz attempt.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<void>}
 */
async function startAttempt(page) {
    const start = await page.$('input[type="submit"], button[type="submit"]');
    if (start) {
        await Promise.all([start.click(), page.waitForNavigation({waitUntil: 'networkidle2'}).catch(() => {})]);
    }
}

/**
 * Whether a question is currently presented.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<boolean>}
 */
async function hasQuestion(page) {
    return (await page.$('.que')) !== null;
}

/**
 * Read the Moodle question id of the presented item from the DOM.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<number>}
 */
async function currentQuestionId(page) {
    return page.evaluate(() => {
        const el = document.querySelector('.que');
        if (!el || !el.id) {
            return 0;
        }
        const idAttr = el.id.match(/(\d+)/);
        return idAttr ? parseInt(idAttr[1], 10) : 0;
    });
}

/**
 * Select the answer matching the oracle's fraction (1 = correct, 0 = incorrect).
 *
 * For a deterministic dichotomous simulation the worker picks the first option
 * when the fraction is >= 0.5, otherwise a distractor. Map to the real correct
 * option once the item's grading is exposed.
 *
 * @param {object} page The Puppeteer page.
 * @param {number} fraction The response fraction in [0,1].
 * @returns {Promise<void>}
 */
async function answerQuestion(page, fraction) {
    const options = await page.$$('.que .answer input[type="radio"]');
    if (options.length === 0) {
        return;
    }
    const index = fraction >= 0.5 ? 0 : Math.min(1, options.length - 1);
    await options[index].click();
}

/**
 * Submit the current question and advance.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<void>}
 */
async function submitQuestion(page) {
    const submit = await page.$('input[name="submitanswer"], button[name="submitanswer"]');
    if (submit) {
        await Promise.all([submit.click(), page.waitForNavigation({waitUntil: 'networkidle2'}).catch(() => {})]);
    }
}

/**
 * Read the adaptivequiz_attempt id from the finished attempt page/URL.
 *
 * @param {object} page The Puppeteer page.
 * @returns {Promise<number>}
 */
async function readEngineAttemptId(page) {
    const url = page.url();
    const match = url.match(/[?&]attempt=(\d+)/);
    return match ? parseInt(match[1], 10) : 0;
}

/**
 * Main polling loop: claim and play attempts until the queue is empty or the
 * job budget is exhausted.
 *
 * @returns {Promise<void>}
 */
async function main() {
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

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
