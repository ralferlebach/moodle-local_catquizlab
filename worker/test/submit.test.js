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
 * What an answer submission ended in, told apart (#134).
 *
 * A server that answers the way the live one did on 09.10.: 200, the ordinary
 * 303, a 404 and a 500 each with Moodle's error page, a redirect to the
 * activity page, an error page with status 200, a connection dropped without
 * a response, and a response that never comes.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const http = require('http');
const nav = require('../navigation.js');

const QUESTIONS = ['.que'];
const SUBMIT = ['input[type=submit]'];

const ERROR_PAGE = '<html><head><title>Error | Site</title></head><body><div class="errorbox">'
    + '<p class="errormessage">Can\'t find data record in database.</p></div></body></html>';

/**
 * The answer the server gives to the POST of a scenario.
 *
 * @param {string} scenario Which.
 * @param {object} response The response.
 * @param {object} request The request.
 */
function answer(scenario, response, request) {
    switch (scenario) {
        case 'ok':
            response.writeHead(200, {'Content-Type': 'text/html'});
            response.end('<html><head><title>Q2</title></head><body><div class="que">2</div></body></html>');
            return;
        case 'redirect':
            response.writeHead(303, {Location: '/mod/adaptivequiz/attempt.php?cmid=1'});
            response.end();
            return;
        case 'finish':
            response.writeHead(303, {Location: '/mod/adaptivequiz/attemptfinished.php?attempt=1'});
            response.end();
            return;
        case 'elsewhere':
            response.writeHead(303, {Location: '/mod/adaptivequiz/view.php?id=1'});
            response.end();
            return;
        case 'notfound':
            response.writeHead(404, {'Content-Type': 'text/html'});
            response.end(ERROR_PAGE);
            return;
        case 'servererror':
            response.writeHead(500, {'Content-Type': 'text/html'});
            response.end(ERROR_PAGE);
            return;
        case 'errorpage':
            response.writeHead(200, {'Content-Type': 'text/html'});
            response.end(ERROR_PAGE);
            return;
        case 'dropped':
            request.socket.destroy();
            return;
        case 'hang':
            // Never answers.
            return;
        default:
            response.writeHead(500);
            response.end();
    }
}

/**
 * A server with one question page per scenario and the answers above.
 *
 * @returns {Promise<{server: object, base: string}>}
 */
function server() {
    const srv = http.createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (request.method === 'POST') {
            request.resume();
            answer(url.searchParams.get('s'), response, request);
            return;
        }
        response.writeHead(200, {'Content-Type': 'text/html'});
        if (url.pathname.includes('attemptfinished.php')) {
            response.end('<html><head><title>Done</title></head><body><div class="attempt-summary">done</div></body></html>');
        } else if (url.pathname.includes('view.php')) {
            response.end('<html><head><title>Activity</title></head><body><p>Attempt overview</p></body></html>');
        } else if (url.pathname.includes('attempt.php')) {
            response.end('<html><head><title>Q2</title></head><body><div class="que">2</div></body></html>');
        } else {
            const s = url.searchParams.get('s');
            response.end(`<html><head><title>Q1</title></head><body><div class="que">1</div>
                <form method="post" action="/mod/adaptivequiz/attempt.php?s=${s}"><input type="submit" value="Go"></form>
                </body></html>`);
        }
    });
    return new Promise((resolve) => srv.listen(0, '127.0.0.1', () => {
        resolve({server: srv, base: `http://127.0.0.1:${srv.address().port}`});
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

test('the classification: status before page, a 303 only to the attempt, timeouts apart', () => {
    const c = (chain, state, error = '') => nav.classifySubmit({chain, state, error});
    assert.strictEqual(c([{method: 'POST', status: 200, url: '/x'}], 'question'), nav.SUBMIT.OK);
    assert.strictEqual(c([{method: 'POST', status: 303, url: '/mod/adaptivequiz/attempt.php'},
        {method: 'GET', status: 200, url: 'http://h/mod/adaptivequiz/attempt.php?cmid=1'}], 'question'), nav.SUBMIT.REDIRECT);
    assert.strictEqual(c([{method: 'POST', status: 303, url: '/a'},
        {method: 'GET', status: 200, url: 'http://h/mod/adaptivequiz/view.php?id=1'}], ''), nav.SUBMIT.UNEXPECTED_REDIRECT);
    // The error page does not hide the status it came with.
    assert.strictEqual(c([{method: 'POST', status: 404, url: '/a'}], 'error'), nav.SUBMIT.HTTP_404);
    assert.strictEqual(c([{method: 'POST', status: 503, url: '/a'}], 'error'), nav.SUBMIT.HTTP_5XX);
    assert.strictEqual(c([{method: 'POST', status: 403, url: '/a'}], 'error'), nav.SUBMIT.HTTP_4XX);
    assert.strictEqual(c([{method: 'POST', status: 200, url: '/a'}], 'error'), nav.SUBMIT.MOODLE_ERROR_PAGE);
    assert.strictEqual(c([{method: 'POST', status: 0, url: '/a', failure: 'net::ERR_CONNECTION_TIMED_OUT'}], null),
        nav.SUBMIT.CONNECT_TIMEOUT);
    assert.strictEqual(c([{method: 'POST', status: 0, url: '/a', failure: 'net::ERR_EMPTY_RESPONSE'}], null),
        nav.SUBMIT.CONNECT_FAILED);
    assert.strictEqual(c([], null, 'Navigation timeout of 3000 ms exceeded'), nav.SUBMIT.CDP_TIMEOUT);
    assert.strictEqual(nav.classifySubmit({chain: [{method: 'POST', status: 200, url: '/a'}], state: null, navigated: true,
        error: 'No expected state (question, finish page or error page) within 2500 ms'}), nav.SUBMIT.NO_STATE);
    assert.strictEqual(nav.classifySubmit({chain: [{method: 'POST', status: 200, url: '/a'}], state: null, navigated: false,
        error: 'Waiting failed: 2500ms exceeded'}), nav.SUBMIT.CDP_TIMEOUT);
    // A navigation wait that ran out does not spoil a question that did arrive.
    assert.strictEqual(c([], 'question', 'Navigation timeout of 3000 ms exceeded'), nav.SUBMIT.OK);
});

test('reconciling with the server: stored, not stored, finished, missing, mismatch', () => {
    const s = (state, attempted) => ({found: true, state, questionsattempted: attempted, slots: attempted + 1});
    assert.strictEqual(nav.reconcile(4, s('inprogress', 5)), 'stored');
    assert.strictEqual(nav.reconcile(4, s('inprogress', 4)), 'notstored');
    assert.strictEqual(nav.reconcile(4, s('complete', 5)), 'finished');
    assert.strictEqual(nav.reconcile(4, s('complete', 3)), 'mismatch');
    assert.strictEqual(nav.reconcile(4, s('inprogress', 7)), 'mismatch');
    assert.strictEqual(nav.reconcile(4, {found: false, reason: 'no-engine-attempt'}), 'missing');
    assert.strictEqual(nav.reconcile(4, null), 'missing');
});

test('in a browser: every answer the server gives is told apart (#134)', async(t) => {
    const browser = await browserOrNull();
    if (!browser) {
        t.skip('No browser available.');
        return;
    }
    const {server: srv, base} = await server();
    const expected = {
        ok: nav.SUBMIT.OK,
        redirect: nav.SUBMIT.REDIRECT,
        finish: nav.SUBMIT.REDIRECT,
        elsewhere: nav.SUBMIT.UNEXPECTED_REDIRECT,
        notfound: nav.SUBMIT.HTTP_404,
        servererror: nav.SUBMIT.HTTP_5XX,
        errorpage: nav.SUBMIT.MOODLE_ERROR_PAGE,
        dropped: nav.SUBMIT.CONNECT_FAILED,
        hang: nav.SUBMIT.CDP_TIMEOUT,
    };
    try {
        for (const [scenario, wanted] of Object.entries(expected)) {
            const page = await browser.newPage();
            const recorder = new nav.Recorder(page);
            await page.goto(`${base}/q?s=${scenario}`);
            let submit;
            try {
                const result = await nav.clickAndSettle(page, SUBMIT, QUESTIONS, {
                    label: scenario, timeout: 2500, recorder, context: {qubaid: 7, slot: 3, answered: 2},
                });
                submit = result.submit;
            } catch (error) {
                assert.ok(error.submit, `${scenario}: a failure carries its submission: ${error.message}`);
                assert.ok(error.message.startsWith(`[submit:${error.submit.class}]`), error.message);
                submit = error.submit;
            }
            assert.strictEqual(submit.class, wanted, `${scenario}: ${nav.describeSubmit(submit)}`);
            assert.deepStrictEqual(submit.context, {qubaid: 7, slot: 3, answered: 2});
            if (scenario === 'redirect' || scenario === 'elsewhere') {
                assert.strictEqual(submit.chain[0].status, 303, nav.describeSubmit(submit));
                assert.ok(submit.chain[0].location.includes('/mod/adaptivequiz/'), nav.describeSubmit(submit));
            }
            assert.strictEqual(recorder.summary().submits.counts[wanted], 1);
            await page.close();
        }
    } finally {
        srv.close();
        await browser.close();
    }
});
