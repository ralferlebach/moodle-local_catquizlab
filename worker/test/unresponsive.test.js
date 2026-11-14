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
 * A page that stopped answering ends the sitting after one protocol timeout (load test 11.11.2026).
 *
 * In the load test a sitting's page froze after its 26th answer. The worker swallowed the first timed-out call,
 * waited out the next, and then asked the frozen page for a screenshot, its DOM and its title: 15 minutes in all,
 * and the retry came due after the test's time limit. Here a page is frozen on purpose (an endless loop in its
 * script) under a protocol timeout of two seconds.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const nav = require('../navigation.js');

const TIMEOUT_MS = 2000;

/**
 * A browser with a short protocol timeout, or null where none can be started.
 *
 * @returns {Promise<?object>}
 */
async function browserOrNull() {
    try {
        return await require('puppeteer').launch({
            headless: true,
            executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
            protocolTimeout: TIMEOUT_MS,
        });
    } catch (error) {
        return null;
    }
}

test('protocol timeouts are told apart from other errors', () => {
    assert.ok(nav.isUnresponsive(new Error("Runtime.evaluate timed out. Increase the 'protocolTimeout' setting in "
        + 'launch/connect calls for a higher timeout if needed.')));
    assert.ok(nav.isUnresponsive('Runtime.callFunctionOn timed out.'));
    assert.ok(nav.isUnresponsive('DOM.resolveNode timed out.'));
    assert.ok(!nav.isUnresponsive(new Error('Navigation timeout of 30000 ms exceeded')));
    assert.ok(!nav.isUnresponsive(new Error('Execution context was destroyed, most likely because of a navigation.')));
    assert.ok(!nav.isUnresponsive(null));
});

test('a frozen page: the click fails at once, the failure is saved without asking the page again', async(t) => {
    const browser = await browserOrNull();
    if (!browser) {
        t.skip('No browser available.');
        return;
    }
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cql-unresponsive-'));
    try {
        const page = await browser.newPage();
        await page.setContent('<form><label><input type="radio" name="answer" value="1"> a.</label></form>');
        const recorder = new nav.Recorder(page);
        await recorder.snapshot();
        const option = await page.$('input[name="answer"]');
        // The renderer's main thread busy for good, as a hung page's is.
        await page.evaluate(() => {
            setTimeout(() => {
                for (;;) {
                    // Spin.
                }
            }, 0);
        });

        let failure = null;
        const clickstart = Date.now();
        try {
            await nav.safeClick(option, recorder);
        } catch (error) {
            failure = error;
        }
        const clickms = Date.now() - clickstart;
        assert.ok(failure, 'the click on a frozen page fails');
        assert.ok(nav.isUnresponsive(failure), `as a protocol timeout: ${failure && failure.message}`);
        // One timeout, not the swallowed one followed by the click's own.
        assert.ok(clickms < 2 * TIMEOUT_MS, `after one timeout (${clickms} ms)`);

        const savestart = Date.now();
        const written = await recorder.save(dir, {attemptid: 1}, failure);
        const savems = Date.now() - savestart;
        assert.ok(savems < TIMEOUT_MS, `the failure is saved without waiting on the page (${savems} ms)`);
        assert.ok(written.includes('events.json') && written.includes('error.json'));
        assert.ok(written.includes('screenshot-previous.jpg'), 'what the page last showed is kept');
        assert.ok(!written.includes('dom.html') && !written.includes('screenshot-last.jpg'), 'the frozen page is not asked');
        const events = JSON.parse(fs.readFileSync(path.join(dir, 'events.json'), 'utf8')).events;
        assert.ok(events.some((e) => e.type === 'page-unresponsive'), 'and the events say why');
    } finally {
        fs.rmSync(dir, {recursive: true, force: true});
        // The frozen renderer is killed with the browser.
        const proc = browser.process();
        await Promise.race([browser.close(), new Promise((resolve) => setTimeout(resolve, 3000))]);
        if (proc && proc.exitCode === null) {
            proc.kill('SIGKILL');
        }
    }
});
