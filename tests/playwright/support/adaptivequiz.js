// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A learner in an adaptive quiz, as a person does it: sign in, open the activity, start or continue the attempt,
 * answer, and the server's record of it read back (cli/engine_session_seed.php --report). For the engine's session and
 * migration tests (#136, #137).
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {expect} = require('@playwright/test');
const {execFileSync} = require('child_process');
const fs = require('fs');
const pathlib = require('path');

const ROOT = process.env.MOODLE_ROOT || pathlib.resolve(__dirname, '../../../../..');
const CORE = process.env.CORE_ROOT || ROOT;
const SEED = pathlib.join(ROOT, 'local/catquizlab/cli/engine_session_seed.php');

/** The question form of the activity (mod_adaptivequiz): a question and its submit button. */
const QUESTION = '.que';
const SUBMIT = ['input[name="submitanswer"]', 'button[name="submitanswer"]', '#responseform input[type="submit"]',
    '.submitbtns input[type="submit"]', 'form[action*="attempt.php"] input[type="submit"]'];
/** An attempt in progress offers "Continue" beside "Start a new attempt" (resume=restart): never the restart. */
const CONTINUE = ['form[action*="attempt.php"]:not(:has(input[name="resume"][value="restart"])) button[type="submit"]',
    'form[action*="attempt.php"]:not(:has(input[name="resume"][value="restart"])) input[type="submit"]'];
const RESTART = 'form[action*="attempt.php"] input[name="resume"][value="restart"]';
const START = ['#id_submitbutton', 'form[action*="attempt.php"] button[type="submit"]',
    'form[action*="attempt.php"] input[type="submit"]', 'a[href*="/mod/adaptivequiz/attempt.php"]'];

/**
 * The seeded session file.
 *
 * @returns {{courseid: number, activities: object, learners: Array<{id: number, username: string, password: string}>}}
 */
function session() {
    return JSON.parse(fs.readFileSync(process.env.ENGINE_SESSION, 'utf8'));
}

/**
 * What the server stored for a learner, per activity.
 *
 * @param {number} userid The learner.
 * @returns {object}
 */
function report(userid) {
    return JSON.parse(execFileSync('php', [SEED, '--report', '--session=' + process.env.ENGINE_SESSION,
        '--user=' + userid], {encoding: 'utf8', maxBuffer: 16 * 1024 * 1024}));
}

/**
 * The one attempt of a learner in an activity (a second one would be a restart nobody asked for).
 *
 * @param {number} userid The learner.
 * @param {string} key A or B.
 * @returns {object}
 */
function attempt(userid, key) {
    const attempts = report(userid).activities[key].attempts;
    expect(attempts.length, `learner ${userid} has exactly one attempt in ${key}`).toBe(1);
    return attempts[0];
}

/**
 * Purge Moodle's caches, as an administrator would in the middle of everything.
 */
function purgeCaches() {
    execFileSync('php', [pathlib.join(CORE, 'admin/cli/purge_caches.php')], {encoding: 'utf8'});
}

/**
 * Shift the stored difficulty of activity A's items in the engine and make one item nobody was asked yet inactive,
 * attempts open (#136 AK 3: a parameter and item change mid-test).
 *
 * @returns {{changed: number, deactivated: number}} Parameter rows changed, the question made inactive.
 */
function changeItems() {
    const out = execFileSync('php', [SEED, '--change-items', '--session=' + process.env.ENGINE_SESSION], {encoding: 'utf8'});
    return JSON.parse(out.trim().split('\n').pop());
}

/**
 * Sign in.
 *
 * @param {object} page The page.
 * @param {{username: string, password: string}} learner The learner.
 */
async function login(page, learner) {
    // Up to three tries: a login page whose form token went stale while it loaded (a cold server, a page recorded on
    // video) says "Invalid login" for the right password. Signing in is not what these tests test; what they test
    // starts after it, and a learner who never got in fails here, with the page's message.
    let message = '';
    for (let attempt = 1; attempt <= 3; attempt++) {
        // Loaded, not only parsed: a field filled before the page's scripts ran can be emptied by them.
        await page.goto('/login/index.php', {waitUntil: 'load'});
        await page.fill('#username', learner.username);
        await page.fill('#password', learner.password);
        await page.click('#loginbtn');
        // Signed in: Moodle leaves the login page (through ?loginredirect=1 to the dashboard).
        const left = page.waitForURL((url) => !url.pathname.endsWith('/login/index.php'), {timeout: 60 * 1000})
            .then(() => true);
        const refused = page.locator('#loginerrormessage, .loginerrors .alert, .alert-danger').first()
            .waitFor({state: 'visible', timeout: 60 * 1000}).then(() => false);
        if (await Promise.race([left, refused]).catch(() => false)) {
            return;
        }
        message = await page.locator('#loginerrormessage, .loginerrors, .alert-danger').first().textContent()
            .catch(() => '');
    }
    throw new Error(`${learner.username} could not sign in after three tries: ${String(message).trim()}`);
}

/**
 * The first of the selectors that is on the page.
 *
 * @param {object} page The page.
 * @param {string[]} selectors Candidates, in order.
 * @returns {Promise<?object>}
 */
async function first(page, selectors) {
    for (const selector of selectors) {
        const handle = page.locator(selector).first();
        if (await handle.count()) {
            return handle;
        }
    }
    return null;
}

/**
 * Open the activity and get to its question: continue the attempt in progress, or start one if there is none.
 *
 * @param {object} page The page.
 * @param {number} cmid The activity.
 * @returns {Promise<string>} 'question', or 'finished' when the attempt has ended.
 */
async function openQuestion(page, cmid) {
    await page.goto('/mod/adaptivequiz/view.php?id=' + cmid, {waitUntil: 'domcontentloaded'});
    if (await page.locator(QUESTION).count()) {
        return 'question';
    }
    const resumable = await page.locator(RESTART).count();
    const button = await first(page, resumable ? CONTINUE : START);
    expect(button, resumable ? 'the page offers to continue the attempt' : 'the page offers to start an attempt')
        .not.toBeNull();
    await Promise.all([page.waitForLoadState('domcontentloaded'), button.click()]);
    // Some versions ask once more before the first question.
    if (!(await page.locator(QUESTION).count())) {
        const again = await first(page, START);
        if (again) {
            await Promise.all([page.waitForLoadState('domcontentloaded'), again.click()]);
        }
    }
    return (await page.locator(QUESTION).count()) ? 'question' : 'finished';
}

/**
 * Answer the question on the page with its first option and submit.
 *
 * @param {object} page The page.
 * @returns {Promise<string>} 'question' when another question is on the page, 'elsewhere' otherwise (the end, or the
 *     activity's page): whether the attempt goes on is the server's to say (report()).
 */
async function answer(page) {
    await expect(page.locator(QUESTION).first()).toBeVisible();
    const radio = page.locator('.que .answer input[type="radio"]').first();
    if (await radio.count()) {
        await radio.check();
    }
    const submit = await first(page, SUBMIT);
    expect(submit, 'the question has a submit button').not.toBeNull();
    // The question's number and its unique form fields name the question on the page: the answer is in once the
    // browser shows another one, or none (the end of the attempt, the activity's page). Whether the attempt goes on is
    // read from the server, not from the page.
    const shown = () => {
        const que = document.querySelector('.que');
        if (!que) {
            return 'none';
        }
        const field = que.querySelector('input[name$=":sequencecheck"]');
        return (que.querySelector('.info .no') || {}).textContent + '|' + (field ? field.name : que.id);
    };
    const before = await page.evaluate(shown);
    await submit.click();
    await page.waitForFunction(([fn, was]) => document.readyState !== 'loading' && (new Function('return ' + fn))()() !== was,
        [shown.toString(), before], {timeout: 120 * 1000});
    await page.waitForLoadState('domcontentloaded');
    return (await page.locator(QUESTION).count()) ? 'question' : 'elsewhere';
}

/**
 * The same attempt, read again: nothing it had is lost, nothing in it is twice, nothing in it from outside its pool.
 *
 * @param {object} now The attempt as read now.
 * @param {object} before The attempt as read before (or null).
 * @param {number} answered How many answers the test gave in it so far.
 * @param {string} what Who and where, for the messages.
 */
function unharmed(now, before, answered, what) {
    expect(now.asked_twice, `${what}: no question asked twice`).toEqual([]);
    expect(now.outside_pool, `${what}: every question from the activity's pool`).toEqual([]);
    expect(now.answered, `${what}: every answer given is stored, none more`).toBe(answered);
    if (before) {
        expect(now.id, `${what}: the same attempt`).toBe(before.id);
        expect(now.uniqueid, `${what}: the same question usage`).toBe(before.uniqueid);
        // What was asked before is still there, in the same order, and what was answered is still answered.
        expect(now.questions.slice(0, before.questions.length).map((q) => q.questionid),
            `${what}: nothing asked before is lost or replaced`).toEqual(before.questions.map((q) => q.questionid));
        before.questions.forEach((q, i) => {
            if (q.answered) {
                expect(now.questions[i].answered, `${what}: answer ${i + 1} still stored`).toBe(true);
            }
        });
    }
}

/**
 * Keep a reading for the artefact.
 *
 * @param {string} dir The folder.
 * @param {string} name The file name.
 * @param {object} data What to keep.
 */
function keep(dir, name, data) {
    fs.mkdirSync(dir, {recursive: true});
    fs.writeFileSync(pathlib.join(dir, name), JSON.stringify(data, null, 2));
}

/**
 * How far behind the engine's cached usage numbers of activity A are, at the end of a test (#136 AK 3).
 *
 * @returns {{items: number, behind: number, answersbehind: number, maxbehind: number, ahead: number}}
 */
function usageProbe() {
    const out = execFileSync('php', [SEED, '--usage-probe', '--session=' + process.env.ENGINE_SESSION], {encoding: 'utf8'});
    return JSON.parse(out.trim().split('\n').pop());
}

module.exports = {session, report, attempt, purgeCaches, changeItems, usageProbe, login, openQuestion, answer, unharmed, keep};
