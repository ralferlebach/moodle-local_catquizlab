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
 * Login against a page that behaves like Moodle's: a transient "Invalid login"
 * is retried once with a fresh page; a lasting one is reported; the fields are
 * set exactly, never appended to a value the page put there.
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
 * A login page: refuses the first `refusals` submissions, then accepts the right credentials.
 *
 * The username field comes pre-filled after a refusal, as Moodle's does.
 *
 * @param {number} refusals How many submissions to refuse regardless.
 * @returns {Promise<{server: object, base: string, seen: string[]}>}
 */
function loginServer(refusals) {
    const seen = [];
    let refused = 0;
    const page = (prefill, error) => `<html><head><title>Log in</title></head><body>
        ${error ? '<div id="loginerrormessage" class="alert alert-danger">Invalid login, please try again</div>' : ''}
        <form method="post" action="/login/index.php">
        <input id="username" name="username" value="${prefill}"><input id="password" name="password" type="password">
        <button id="loginbtn" type="submit">Log in</button></form></body></html>`;
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (request.method === 'POST') {
            let body = '';
            request.on('data', (chunk) => {
                body += chunk;
            });
            request.on('end', () => {
                const form = new URLSearchParams(body);
                seen.push(form.get('username') + '|' + form.get('password'));
                const right = form.get('username') === 'catlab_u' && form.get('password') === '86';
                if (refused < refusals || !right) {
                    refused++;
                    response.writeHead(303, {Location: '/login/index.php?loginredirect=1&u=' + form.get('username')});
                } else {
                    response.writeHead(303, {Location: '/my/'});
                }
                response.end();
            });
            return;
        }
        response.writeHead(200, {'Content-Type': 'text/html'});
        if (url.pathname === '/my/') {
            response.end('<html><body>Dashboard</body></html>');
            return;
        }
        const failed = url.searchParams.has('loginredirect');
        response.end(page(failed ? url.searchParams.get('u') : '', failed));
    });
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => {
        resolve({server, base: `http://127.0.0.1:${server.address().port}`, seen});
    }));
}

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

test('a transient "Invalid login" is retried once, with the fields set exactly', async(t) => {
    const browser = await browserOrNull();
    if (!browser) {
        t.skip('No browser available.');
        return;
    }
    const {server, base, seen} = await loginServer(1);
    try {
        const page = await browser.newPage();
        const recorder = new nav.Recorder(page);
        await worker.login(page, 86, 'catlab_u', recorder, base);
        assert.ok(page.url().endsWith('/my/'), page.url());
        // Both submissions carried exactly the credentials, the second one not
        // appended to the username the failed page put back into the field.
        assert.deepStrictEqual(seen, ['catlab_u|86', 'catlab_u|86']);
        assert.ok(recorder.events.some((e) => e.type === 'login-retried'));
    } finally {
        server.close();
        await browser.close();
    }
});

test('a lasting refusal is reported after the second try', async(t) => {
    const browser = await browserOrNull();
    if (!browser) {
        t.skip('No browser available.');
        return;
    }
    const {server, base, seen} = await loginServer(99);
    try {
        const page = await browser.newPage();
        await assert.rejects(worker.login(page, 86, 'catlab_u', null, base), /Login as catlab_u failed: Invalid login/);
        assert.strictEqual(seen.length, 2, 'two tries, not more');
    } finally {
        server.close();
        await browser.close();
    }
});
