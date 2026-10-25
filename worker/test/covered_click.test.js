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
 * A covered button is still submitted; a "leave this page?" dialog does not stop the worker.
 *
 * Moodle 5.3 keeps a navigation footer fixed at the bottom of the window. The
 * quiz's "Submit answer" lay under it: the click went to the footer, nothing
 * was sent, and after the reconciliation the form change checker's
 * beforeunload dialog held the way back to the activity page until it timed
 * out. The pages here reproduce both, without Moodle.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const http = require('http');
const nav = require('../navigation.js');

// The answer form at the bottom of a long page, a footer fixed over it, and a
// beforeunload handler as Moodle's form change checker installs one.
const QUESTION = `<html><body style="margin:0">
    <div style="height:1400px">Question text</div>
    <form id="responseform" method="post" action="/mod/adaptivequiz/attempt.php">
        <label><input type="radio" name="answer" value="1"> a.</label>
        <input type="submit" name="submitanswer" value="Submit answer" style="height:38px">
    </form>
    <div class="course-linear-navigation-footer-content"
        style="position:fixed;left:0;right:0;bottom:0;height:120px;background:#eee;z-index:10">Footer</div>
    <script>
        // As Moodle's form change checker: ask once something was changed,
        // never while the form itself is being sent.
        var changed = false, sending = false;
        document.querySelector('input[name="answer"]').addEventListener('change', function() { changed = true; });
        document.getElementById('responseform').addEventListener('submit', function() { sending = true; });
        window.onbeforeunload = function(e) {
            if (!changed || sending) { return undefined; }
            e.preventDefault();
            e.returnValue = 'You have unsaved changes on this page.';
            return e.returnValue;
        };
    </script>
</body></html>`;

/**
 * A host that serves the question page and counts the answers posted to it.
 *
 * @returns {Promise<{server: object, base: string, posts: string[]}>}
 */
function hostServer() {
    const posts = [];
    const server = http.createServer((request, response) => {
        let body = '';
        request.on('data', (chunk) => {
            body += chunk;
        });
        request.on('end', () => {
            if (request.method === 'POST') {
                posts.push(body);
                response.writeHead(200, {'Content-Type': 'text/html'});
                response.end('<html><body><div class="que">Next question</div></body></html>');
                return;
            }
            response.writeHead(200, {'Content-Type': 'text/html'});
            response.end(request.url.startsWith('/view') ? '<html><body>Activity</body></html>' : QUESTION);
        });
    });
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => {
        resolve({server, base: `http://127.0.0.1:${server.address().port}`, posts});
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

test('a submit button under a fixed footer is still submitted', async(t) => {
    const browser = await browserOrNull();
    if (!browser) {
        t.skip('No browser available.');
        return;
    }
    const {server, base, posts} = await hostServer();
    try {
        const page = await browser.newPage();
        await page.setViewport({width: 800, height: 600});
        const recorder = new nav.Recorder(page);
        await page.goto(`${base}/attempt`);
        await nav.safeClick(await page.$('input[name="answer"]'), recorder);
        // Scrolled to the bottom, as a person reaching the button would be: the footer lies over it.
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await Promise.all([
            page.waitForNavigation({waitUntil: 'domcontentloaded', timeout: 10000}),
            nav.safeClick(await page.$('input[name="submitanswer"]'), recorder),
        ]);
        assert.strictEqual(posts.length, 1, 'the answer was posted once');
        assert.match(posts[0], /submitanswer=Submit\+answer/);
    } finally {
        server.close();
        await browser.close();
    }
});

test('a "leave this page?" dialog is accepted and recorded, the page is left', async(t) => {
    const browser = await browserOrNull();
    if (!browser) {
        t.skip('No browser available.');
        return;
    }
    const {server, base} = await hostServer();
    try {
        const page = await browser.newPage();
        const recorder = new nav.Recorder(page);
        nav.acceptDialogs(page, recorder);
        await page.goto(`${base}/attempt`);
        // An answer chosen and not sent: the page now asks before it is left.
        await nav.safeClick(await page.$('input[name="answer"]'), recorder);
        await page.goto(`${base}/view.php?id=5`, {waitUntil: 'domcontentloaded', timeout: 10000});
        assert.match(page.url(), /\/view\.php\?id=5$/);
        assert.ok(recorder.events.some((e) => e.type === 'dialog' && /beforeunload accepted/.test(e.detail)),
            'the dialog is in the events');
    } finally {
        server.close();
        await browser.close();
    }
});
