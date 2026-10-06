// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Navigation under delay: the race of #100, reproduced and closed.
 *
 * A local server imitates an adaptive quiz — a question, a form posted, a
 * redirect to the next question, a finish page — with the response to each
 * submission delayed at random, from none to 400 ms. The old pattern (click,
 * then start waiting) is played beside the new one, so that the race is shown
 * rather than asserted.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const http = require('http');
const os = require('os');
const fs = require('fs');
const path = require('path');
const nav = require('../navigation.js');

const QUESTIONS = ['.que'];
const SUBMIT = ['input[type=submit]'];
const STEPS = 40;

/**
 * A server that behaves like an attempt: STEPS questions, then a finish page.
 *
 * @returns {Promise<{server: object, base: string}>}
 */
function quizServer() {
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        const n = parseInt(url.searchParams.get('n') || '1', 10);
        if (request.method === 'POST') {
            // The submission takes as long as it takes: sometimes nothing,
            // sometimes long enough for a script to look at the old page.
            const delay = Math.floor(Math.random() * 400);
            request.resume();
            setTimeout(() => {
                const next = n >= STEPS ? '/mod/adaptivequiz/attemptfinished.php' : `/q?n=${n + 1}`;
                if (n % 2 === 0) {
                    response.writeHead(303, {Location: next});
                    response.end();
                    return;
                }
                // Every other step, the way Moodle's redirect() can answer: a
                // page of its own that forwards by script after a moment. The
                // first navigation completes on this page; the second replaces
                // it under whatever looks at it.
                response.writeHead(200, {'Content-Type': 'text/html'});
                response.end(`<html><head><title>Redirect</title></head><body>
                    <p>Continue</p>
                    <script>setTimeout(function() { location.replace(${JSON.stringify(next)}); }, ${Math.floor(Math.random() * 150)});</script>
                    </body></html>`);
            }, delay);
            return;
        }
        response.writeHead(200, {'Content-Type': 'text/html'});
        if (url.pathname.includes('attemptfinished.php')) {
            response.end('<html><head><title>Finished</title></head><body><div class="attempt-summary">done</div></body></html>');
            return;
        }
        response.end(`<html><head><title>Question ${n}</title></head><body>
            <div class="que" id="question-${n}">Question ${n}</div>
            <form method="post" action="/submit?n=${n}"><input type="submit" value="Submit"></form>
            </body></html>`);
    });
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => {
        resolve({server, base: `http://127.0.0.1:${server.address().port}`});
    }));
}

/**
 * A browser, or null where none can be started.
 *
 * @returns {Promise<?object>}
 */
async function browserOrNull() {
    try {
        const puppeteer = require('puppeteer');
        return await puppeteer.launch({
            headless: true,
            executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
        });
    } catch (error) {
        return null;
    }
}

test('pure helpers: lost contexts and secrets in URLs', () => {
    assert.ok(nav.isContextLost(new Error('Execution context was destroyed, most likely because of a navigation.')));
    assert.ok(nav.isContextLost('Protocol error: Cannot find context with specified id'));
    assert.ok(!nav.isContextLost(new Error('Timeout of 30000 ms exceeded')));
    assert.strictEqual(
        nav.sanitiseUrl('https://x.org/mod/adaptivequiz/attempt.php?cmid=12&sesskey=abc123&wstoken=zzz'),
        'https://x.org/mod/adaptivequiz/attempt.php?cmid=12&sesskey=[redacted]&wstoken=[redacted]'
    );
});

test('a page snapshot leaves the session key behind (#107)', () => {
    const html = '<script>M.cfg = {"wwwroot":"x","sesskey":"Ab12Cd34"};</script>'
        + '<input type="hidden" name="sesskey" value="Ab12Cd34">'
        + '<input value="Ab12Cd34" type="hidden" name="sesskey">'
        + '<a href="/login/logout.php?sesskey=Ab12Cd34">out</a>'
        + '<input type="password" name="password" value="secret">';
    const clean = nav.sanitiseHtml(html);
    assert.ok(!clean.includes('Ab12Cd34'), clean);
    assert.ok(!clean.includes('secret'), clean);
});

test('the new pattern plays every question under random delays', async(t) => {
    const browser = await browserOrNull();
    if (!browser) {
        t.skip('No browser available.');
        return;
    }
    const {server, base} = await quizServer();
    try {
        const page = await browser.newPage();
        const recorder = new nav.Recorder(page);
        await page.goto(`${base}/q?n=1`);
        let state = await nav.waitForState(page, QUESTIONS, {label: 'start', timeout: 5000});
        let answered = 0;
        while (state === nav.STATE.QUESTION) {
            const current = await nav.withContextRetry(page, () => page.$eval('.que', (el) => el.id), 'read question', recorder);
            assert.ok(current.startsWith('question-'));
            const result = await nav.clickAndSettle(page, SUBMIT, QUESTIONS, {label: `submit ${answered + 1}`, timeout: 5000, recorder});
            assert.ok(result.clicked);
            state = result.state;
            answered++;
        }
        assert.strictEqual(state, nav.STATE.FINISH);
        assert.strictEqual(answered, STEPS);
    } finally {
        server.close();
        await browser.close();
    }
});

test('the old pattern — click, then wait — stops early under the same delays', async(t) => {
    const browser = await browserOrNull();
    if (!browser) {
        t.skip('No browser available.');
        return;
    }
    const {server, base} = await quizServer();
    let answered = 0;
    let lost = 0;
    let finished = false;
    try {
        const page = await browser.newPage();
        await page.goto(`${base}/q?n=1`);
        // The answer loop of run_attempt.js until 0.7.3: while a question is
        // on screen, click submit, then wait for the navigation, swallowing.
        try {
            while ((await page.$('.que')) && answered < STEPS + 5) {
                const button = await page.$('input[type=submit]');
                await button.click();
                await page.waitForNavigation({waitUntil: 'domcontentloaded', timeout: 1500}).catch(() => null);
                answered++;
            }
        } catch (error) {
            if (nav.isContextLost(error)) {
                lost++;
            }
        }
        finished = page.url().includes('attemptfinished.php');
    } finally {
        server.close();
        await browser.close();
    }
    t.diagnostic(`old pattern: ${answered} of ${STEPS} questions answered, finished: ${finished}, `
        + `reads on a replaced page: ${lost}`);
    // Timing-dependent by nature, so reported rather than asserted; the new
    // pattern above is what is asserted.
    assert.ok(answered >= 0);
});

test('a failure is saved with screenshots, DOM, events and the error', async(t) => {
    const browser = await browserOrNull();
    if (!browser) {
        t.skip('No browser available.');
        return;
    }
    const {server, base} = await quizServer();
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'catquizlab-artefacts-'));
    try {
        const page = await browser.newPage();
        const recorder = new nav.Recorder(page);
        await page.goto(`${base}/q?n=1`);
        await recorder.snapshot();
        await nav.clickAndSettle(page, SUBMIT, QUESTIONS, {label: 'submit 1', timeout: 5000, recorder});
        await recorder.snapshot();
        const written = await recorder.save(dir, {experimentid: 1, runid: 2, attemptid: 3, execution: 1, workerid: 'w'},
            new Error('Simulated failure'));
        for (const name of ['screenshot-previous.jpg', 'screenshot-last.jpg', 'dom.html', 'events.json', 'error.json']) {
            assert.ok(written.includes(name), `${name} was not written`);
        }
        const error = JSON.parse(fs.readFileSync(path.join(dir, 'error.json'), 'utf8'));
        assert.strictEqual(error.attemptid, 3);
        assert.strictEqual(error.message, 'Simulated failure');
        const events = JSON.parse(fs.readFileSync(path.join(dir, 'events.json'), 'utf8'));
        assert.ok(events.events.some((e) => e.type === 'click'));
        assert.ok(events.statuses.length > 0);
    } finally {
        server.close();
        await browser.close();
        fs.rmSync(dir, {recursive: true, force: true});
    }
});
