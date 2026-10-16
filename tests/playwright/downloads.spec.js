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
 * Every download the interface offers, pressed and opened (#121).
 *
 * The reproducibility package failed with "Call to undefined function
 * send_file()" for as long as nothing pressed its button: a link that is shown
 * is not a link that works. Here each one is pressed the way a reader presses
 * it, and the file that arrives is opened and checked for what it claims to be.
 *
 * Data from cli/seed_results.php (PLOTS_EXPERIMENTID) and
 * cli/seed_failed_attempt.php (RECOVERY_RUNID); without them the test is skipped.
 */

const fs = require('fs');
const {test, expect} = require('@playwright/test');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const EXPERIMENT = process.env.PLOTS_EXPERIMENTID || '';
const RUN = process.env.RECOVERY_RUNID || '';

/**
 * Sign in.
 *
 * @param {object} page The page.
 */
async function signin(page) {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
}

/**
 * Press a control and take the file it delivers.
 *
 * @param {object} page The page.
 * @param {object} control The locator to press.
 * @returns {Promise<{name: string, body: Buffer}>}
 */
async function download(page, control) {
    const [file] = await Promise.all([
        page.waitForEvent('download', {timeout: 60 * 1000}),
        control.click(),
    ]);
    const path = await file.path();
    expect(path, 'the download completed').toBeTruthy();
    return {name: file.suggestedFilename(), body: fs.readFileSync(path)};
}

/**
 * The file is what its name says it is: parseable JSON, a CSV with a header and a row, a PDF, a PNG, an SVG, a ZIP.
 *
 * @param {{name: string, body: Buffer}} file The file.
 * @param {object} options minrows: CSV data rows at least (default 1).
 */
function check(file, options = {}) {
    const text = file.body.toString('utf8');
    expect(file.body.length, file.name + ' is not empty').toBeGreaterThan(0);
    expect(text, file.name + ' is not an error page').not.toMatch(/<title>\s*Error \|/);
    if (/\.json$/.test(file.name)) {
        expect(() => JSON.parse(text), file.name + ' is JSON').not.toThrow();
    } else if (/\.csv$/.test(file.name)) {
        const lines = text.split(/\r?\n/).filter((l) => l !== '');
        expect(lines.length, file.name + ' has a header and data').toBeGreaterThan(options.minrows ?? 1);
        expect(lines[0], file.name + ' starts with a header').toMatch(/^[a-z_"]/i);
    } else if (/\.pdf$/.test(file.name)) {
        expect(text.slice(0, 5)).toBe('%PDF-');
    } else if (/\.png$/.test(file.name)) {
        expect(file.body.subarray(1, 4).toString('latin1')).toBe('PNG');
    } else if (/\.svg$/.test(file.name)) {
        expect(text).toContain('<svg');
    } else if (/\.zip$/.test(file.name)) {
        expect(file.body.subarray(0, 2).toString('latin1')).toBe('PK');
    } else if (/\.txt$/.test(file.name)) {
        expect(text.trim().length).toBeGreaterThan(0);
    } else {
        throw new Error('Unexpected download: ' + file.name);
    }
}

test('every download on the results pages delivers a usable file', async({page}) => {
    test.skip(!EXPERIMENT, 'PLOTS_EXPERIMENTID not set; run cli/seed_results.php first.');
    await signin(page);
    const results = '/local/catquizlab/results.php?experimentid=' + EXPERIMENT;

    // The reproducibility package: a POST button above the analyses.
    await page.goto(results + '&tab=overview', {waitUntil: 'networkidle'});
    const pkg = await download(page, page.locator('form:has(input[name="action"][value="package"]) button[type="submit"]'));
    check(pkg);
    const content = JSON.parse(pkg.body.toString('utf8'));
    for (const key of ['generated', 'experiment', 'versions', 'runs', 'completeness']) {
        expect(content, 'the package carries ' + key).toHaveProperty(key);
    }
    expect(content.versions).toHaveProperty('local_catquizlab');

    // Every export of the export tab: each level, as CSV, JSON and metadata.
    await page.goto(results + '&tab=export', {waitUntil: 'networkidle'});
    const exports = page.locator('a[href*="action=csv"], a[href*="action=json"], a[href*="action=meta"]');
    const count = await exports.count();
    expect(count, 'the export tab offers its downloads').toBeGreaterThan(6);
    for (let i = 0; i < count; i++) {
        const link = exports.nth(i);
        const href = await link.getAttribute('href');
        // Levels that need data the seed does not have may be legitimately empty: a header is still a file.
        check(await download(page, link), {minrows: /level=(item|detection|detectionsummary|subscale)/.test(href) ? 0 : 1});
    }

    // Every plot's own downloads, on the tabs that have plots.
    for (const tab of ['overview', 'global', 'testflow']) {
        await page.goto(results + '&tab=' + tab, {waitUntil: 'networkidle'});
        const controls = page.locator('[data-download]');
        const n = await controls.count();
        expect(n, tab + ' offers plot downloads').toBeGreaterThan(0);
        for (let i = 0; i < n; i++) {
            check(await download(page, controls.nth(i)), {minrows: 0});
        }
    }

    // The experiment definition, both variants, and the comparison's metrics.
    await page.goto('/local/catquizlab/experiment.php?id=' + EXPERIMENT, {waitUntil: 'networkidle'});
    const definitions = page.locator('a[href*="action=export"]');
    expect(await definitions.count()).toBeGreaterThan(0);
    for (let i = 0; i < await definitions.count(); i++) {
        check(await download(page, definitions.nth(i)));
    }
    await page.goto('/local/catquizlab/compare.php?experimentid=' + EXPERIMENT, {waitUntil: 'networkidle'});
    // The seed stores no run metrics: the header alone is the honest file.
    check(await download(page, page.locator('a[href*="action=export"]').first()), {minrows: 0});
});

test('the logs and a run\'s debug artefacts download', async({page}) => {
    test.skip(!RUN, 'RECOVERY_RUNID not set; run cli/seed_failed_attempt.php first.');
    await signin(page);

    await page.goto('/local/catquizlab/logs.php?hours=0', {waitUntil: 'networkidle'});
    for (const name of ['download', 'downloadjson']) {
        check(await download(page, page.locator('form:has(input[name="' + name + '"]) button[type="submit"]')));
    }

    // The debug export of the progress page: pressed where debug recording shows it, asked for directly otherwise.
    await page.goto('/local/catquizlab/runs.php', {waitUntil: 'networkidle'});
    const debugbutton = page.locator('form:has(input[name="action"][value="exportdebug"]) button[type="submit"]');
    if (await debugbutton.count() > 0) {
        check(await download(page, debugbutton.first()));
    }
    const sesskey = await page.evaluate(() => window.M.cfg.sesskey);
    const debug = await page.request.post('/local/catquizlab/operations.php', {form: {action: 'exportdebug', sesskey: sesskey}});
    expect(debug.status()).toBe(200);
    expect(debug.headers()['content-disposition']).toMatch(/attachment; filename="catquizlab-debug-.*\.json"/);
    expect(JSON.parse((await debug.body()).toString('utf8'))).toHaveProperty('generated');

    // The run's ZIP and, where a sitting failed, the ZIP of the failed ones.
    await page.goto('/local/catquizlab/runs.php?runid=' + RUN, {waitUntil: 'networkidle'});
    const zips = page.locator('form:has(input[name="action"][value="artefactzip"]) button[type="submit"]');
    const n = await zips.count();
    expect(n, 'the run offers its debug ZIPs').toBeGreaterThan(1);
    for (let i = 0; i < n; i++) {
        check(await download(page, zips.nth(i)));
    }
});

test('downloads refuse cleanly: no session key, an unknown experiment, a user without rights', async({page, browser}) => {
    test.skip(!EXPERIMENT, 'PLOTS_EXPERIMENTID not set; run cli/seed_results.php first.');
    await signin(page);
    await page.goto('/local/catquizlab/results.php?experimentid=' + EXPERIMENT + '&tab=export', {waitUntil: 'networkidle'});
    const sesskey = await page.evaluate(() => window.M.cfg.sesskey);

    /**
     * A response that is a page explaining a refusal, not a file and not a blank page.
     *
     * @param {object} response The response.
     * @param {RegExp} message What the page says.
     */
    async function refused(response, message) {
        expect(response.headers()['content-disposition'] || '', 'no file').not.toContain('attachment');
        const body = await response.text();
        expect(body.length, 'not a blank page').toBeGreaterThan(500);
        expect(body).toMatch(message);
    }

    // No session key: the package is not sent.
    await refused(await page.request.post('/local/catquizlab/results.php', {
        form: {experimentid: EXPERIMENT, action: 'package'},
    }), /sesskey|session/i);

    // An experiment that does not exist: said in words.
    await refused(await page.request.post('/local/catquizlab/results.php', {
        form: {experimentid: '987654', action: 'package', sesskey: sesskey},
    }), /no experiment with the id 987654|kein Experiment mit der ID 987654/);

    // An unknown export level falls back to the sittings, as a file.
    const fallback = await page.request.get('/local/catquizlab/results.php?experimentid=' + EXPERIMENT
        + '&tab=export&level=nonsense&action=csv');
    expect(fallback.status()).toBe(200);
    expect(fallback.headers()['content-disposition']).toContain('catquizlab_attempt_');

    // A colleague with an account and no rights: refused, no file.
    test.skip(!process.env.PLAIN_USER, 'PLAIN_USER not set; run cli/seed_plain_user.php first.');
    const context = await browser.newContext();
    const plain = await context.newPage();
    await plain.goto('/login/index.php', {waitUntil: 'networkidle'});
    await plain.fill('#username', process.env.PLAIN_USER);
    await plain.fill('#password', process.env.PLAIN_PASSWORD);
    await plain.click('#loginbtn');
    await plain.waitForLoadState('networkidle');
    const plainkey = await plain.evaluate(() => window.M.cfg.sesskey);
    for (const url of [
        '/local/catquizlab/results.php?experimentid=' + EXPERIMENT + '&tab=export&level=attempt&action=csv',
        '/local/catquizlab/experiment.php?id=' + EXPERIMENT + '&action=export',
        '/local/catquizlab/compare.php?experimentid=' + EXPERIMENT + '&action=export',
    ]) {
        await refused(await plain.request.get(url), /Access denied|Zugriff verweigert|permission|Berechtigung|not allowed/i);
    }
    await refused(await plain.request.post('/local/catquizlab/results.php', {
        form: {experimentid: EXPERIMENT, action: 'package', sesskey: plainkey},
    }), /Access denied|Zugriff verweigert|permission|Berechtigung|not allowed/i);
    await context.close();
});

test('a large export arrives whole', async({page}) => {
    test.skip(!process.env.LARGE_EXPERIMENTID, 'LARGE_EXPERIMENTID not set; run cli/seed_results.php --persons=2000 --label=LARGE.');
    await signin(page);
    const base = '/local/catquizlab/results.php?experimentid=' + process.env.LARGE_EXPERIMENTID + '&tab=export';

    // 4000 sittings, one row each.
    const attempts = await page.request.get(base + '&level=attempt&action=csv', {timeout: 5 * 60 * 1000});
    expect(attempts.status()).toBe(200);
    const rows = (await attempts.text()).split(/\r?\n/).filter((l) => l !== '');
    expect(rows.length).toBe(4001);

    // Six steps each: 24 000 rows, parseable as JSON, and the metadata count the same.
    const steps = await page.request.get(base + '&level=step&action=json', {timeout: 5 * 60 * 1000});
    expect(steps.status()).toBe(200);
    const content = JSON.parse(await steps.text());
    expect(content.rows.length).toBe(24000);
    const meta = JSON.parse(await (await page.request.get(base + '&level=step&action=meta')).text());
    expect(JSON.stringify(meta)).toContain('24000');
});
