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
 * Reads an experiment's progress page as a signed-in person would, while real workers play it (#141 DoD 2).
 *
 * Started by cli/smoke.php --watch-page. Signs in, opens step 3 for the experiment and, every --interval seconds,
 * writes one JSON line: the header's question count and bar, the sittings count, and each run row's question count,
 * as the page shows them. Most readings take the page as its own poll left it; every --reload-every-th reloads it,
 * so the server-rendered page is read too. Stops on SIGTERM.
 *
 * The credentials come from the environment (CATQUIZLAB_WATCH_USER, CATQUIZLAB_WATCH_PASSWORD), never the argument
 * list, which anyone on the machine can read.
 *
 * Usage: node watch_progress.js --base-url=URL --experiment=ID --out=FILE [--interval=5] [--reload-every=6]
 */

'use strict';

const fs = require('fs');

const args = {};
for (const arg of process.argv.slice(2)) {
    const match = arg.match(/^--([^=]+)=(.*)$/);
    if (match) {
        args[match[1]] = match[2];
    }
}
const base = String(args['base-url'] || '').replace(/\/+$/, '');
const experiment = String(args.experiment || '');
const out = String(args.out || '');
const interval = Math.max(1, Number(args.interval || 5));
const reloadEvery = Math.max(1, Number(args['reload-every'] || 6));

/**
 * One line into the output file.
 *
 * @param {Object} line What to write.
 */
const write = (line) => fs.appendFileSync(out, JSON.stringify(line) + '\n');

/**
 * What the page shows, read from its regions.
 *
 * @returns {Object}
 */
const read = () => {
    const number = (value) => (value === null || value === undefined || value === '' ? null : Number(value));
    const box = document.querySelector('[data-region="catquizlab-questions"]');
    const bar = document.querySelector('[data-region="catquizlab-questions-bar"]');
    const count = document.querySelector('[data-region="catquizlab-progress-count"]');
    const runs = {};
    document.querySelectorAll('[data-region^="catquizlab-run-"][data-region$="-questions"]').forEach((node) => {
        const id = node.getAttribute('data-region').replace(/^catquizlab-run-(\d+)-questions$/, '$1');
        runs[id] = number(node.getAttribute('data-done'));
    });
    return {
        page: window.location.pathname + window.location.search,
        questions: box ? number(box.getAttribute('data-done')) : null,
        percent: bar ? number(bar.getAttribute('aria-valuenow')) : null,
        sittings: count ? count.textContent.trim() : null,
        runs,
    };
};

(async() => {
    if (!base || !experiment || !out) {
        throw new Error('--base-url, --experiment and --out are required');
    }
    const user = process.env.CATQUIZLAB_WATCH_USER || '';
    const password = process.env.CATQUIZLAB_WATCH_PASSWORD || '';
    const puppeteer = require('puppeteer');
    const browser = await puppeteer.launch({headless: 'new', args: ['--no-sandbox']});
    let stop = false;
    process.on('SIGTERM', () => {
        stop = true;
    });
    try {
        const page = await browser.newPage();
        await page.goto(base + '/login/index.php', {waitUntil: 'networkidle2'});
        await page.type('#username', user);
        await page.type('#password', password);
        await Promise.all([page.waitForNavigation({waitUntil: 'networkidle2'}), page.click('#loginbtn')]);
        const url = base + '/local/catquizlab/runs.php?experimentid=' + encodeURIComponent(experiment);
        await page.goto(url, {waitUntil: 'networkidle2'});
        for (let n = 1; !stop; n++) {
            await new Promise((resolve) => setTimeout(resolve, interval * 1000));
            const source = n % reloadEvery === 0 ? 'load' : 'poll';
            try {
                if (source === 'load') {
                    await page.goto(url, {waitUntil: 'networkidle2'});
                }
                write({at: Math.floor(Date.now() / 1000), source, ...(await page.evaluate(read))});
            } catch (e) {
                // The page reloads itself when the shape of its runs changes; a reading that falls into that
                // moment is skipped and said, not a failure. The next one reads the new page.
                write({at: Math.floor(Date.now() / 1000), source, skipped: String(e && e.message ? e.message : e)});
                await page.waitForNetworkIdle({idleTime: 500, timeout: 30000}).catch(() => null);
            }
        }
    } finally {
        await browser.close();
    }
})().catch((e) => {
    if (out) {
        write({at: Math.floor(Date.now() / 1000), error: String(e && e.message ? e.message : e)});
    }
    process.exit(1);
});
