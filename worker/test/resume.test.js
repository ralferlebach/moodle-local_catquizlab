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
 * An attempt in progress is continued, never started again (#136).
 *
 * mod_adaptivequiz 2026101701/2026101702 shows, for an attempt in progress,
 * "Start a new attempt" (primary, POST resume=restart) before "Continue
 * attempt". The worker clicked the first submit button — the restart — after a
 * lost response and lost the sitting. The page here is the host's markup.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const http = require('http');
const worker = require('../run_attempt.js');
const nav = require('../navigation.js');

/**
 * The activity page and attempt.php; records what was asked for.
 *
 * @param {string} view The activity page's main region.
 * @returns {Promise<{server: object, base: string, seen: string[]}>}
 */
function hostServer(view) {
    const seen = [];
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        let body = '';
        request.on('data', (chunk) => {
            body += chunk;
        });
        request.on('end', () => {
            if (url.pathname === '/mod/adaptivequiz/attempt.php') {
                const form = new URLSearchParams(request.method === 'POST' ? body : url.search);
                seen.push(`${request.method} resume=${form.get('resume') || ''}`);
                response.writeHead(200, {'Content-Type': 'text/html'});
                response.end('<html><body><div class="que"><div class="qtext">Question</div></div></body></html>');
                return;
            }
            response.writeHead(200, {'Content-Type': 'text/html'});
            response.end(`<html><body><div id="region-main">${view}</div></body></html>`);
        });
    });
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => {
        resolve({server, base: `http://127.0.0.1:${server.address().port}`, seen});
    }));
}

const RESTART = `<form method="post" action="/mod/adaptivequiz/attempt.php">
    <input type="hidden" name="cmid" value="5"><input type="hidden" name="resume" value="restart">
    <input type="hidden" name="attemptid" value="7"><input type="hidden" name="sesskey" value="abc">
    <button type="submit" class="btn btn-primary">Start a new attempt</button></form>`;
const CONTINUE = `<form method="get" action="/mod/adaptivequiz/attempt.php">
    <input type="hidden" name="cmid" value="5">
    <button type="submit" class="btn btn-secondary">Continue attempt</button></form>`;
const START = `<form method="post" action="/mod/adaptivequiz/attempt.php">
    <input type="hidden" name="cmid" value="5"><input type="hidden" name="sesskey" value="abc">
    <button type="submit" class="btn btn-primary">Start attempt</button></form>`;

/**
 * A browser, or null where none can be started.
 *
 * @returns {Promise<?object>}
 */
async function browserOrNull() {
    try {
        return await require('puppeteer').launch({
            headless: true,
            executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
        });
    } catch (error) {
        return null;
    }
}

/**
 * Open the activity page and start or continue the attempt.
 *
 * @param {string} view The page's main region.
 * @returns {Promise<{seen: string[], events: object[], error: ?Error}>}
 */
async function play(view) {
    const browser = await browserOrNull();
    if (!browser) {
        return null;
    }
    const {server, base, seen} = await hostServer(view);
    try {
        const page = await browser.newPage();
        const recorder = new nav.Recorder(page);
        await page.goto(`${base}/mod/adaptivequiz/view.php?id=5`);
        let error = null;
        try {
            await worker.startAttempt(page, recorder);
        } catch (e) {
            error = e;
        }
        return {seen, events: recorder.events, error};
    } finally {
        server.close();
        await browser.close();
    }
}

test('an attempt in progress is continued, although the restart comes first', async(t) => {
    const result = await play(RESTART + CONTINUE);
    if (!result) {
        t.skip('No browser available.');
        return;
    }
    assert.strictEqual(result.error, null);
    assert.deepStrictEqual(result.seen, ['GET resume='], 'continued, never restarted');
    assert.ok(result.events.some((e) => e.type === 'resume'));
});

test('a page that offers only a restart is reported, not clicked', async(t) => {
    const result = await play(RESTART);
    if (!result) {
        t.skip('No browser available.');
        return;
    }
    assert.match(String(result.error && result.error.message), /only to start the attempt again/);
    assert.deepStrictEqual(result.seen, [], 'nothing was submitted');
});

test('a new attempt is started as before', async(t) => {
    const result = await play(START);
    if (!result) {
        t.skip('No browser available.');
        return;
    }
    assert.strictEqual(result.error, null);
    assert.deepStrictEqual(result.seen, ['POST resume=']);
});
