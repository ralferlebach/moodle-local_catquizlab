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
 * Six runs stopped by their operator: the same numbers on every screen (#141 DoD-CLARA-02, #158 DoD-CLARA-11, #166).
 *
 * The CLARA case of 15.11.2026: six runs of 1000 sittings, stopped with 1018 collected — 997 in the third run, 21 in
 * the sixth. One screen said 1018 of 6000, another 100 %, a third "failed for good". Seeded by
 * cli/seed_progress.php --state=stopped and stopped the real way (experiment_stop::stop()), then read where an operator
 * reads it: the experiment's progress page, every run's row and own page, "All experiments", the research data
 * package — and the experiment's page again after a reload. Nowhere 100 %, nowhere a countdown, nowhere "failed".
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {execFileSync} = require('child_process');
const fs = require('fs');
const os = require('os');
const pathlib = require('path');
const {needs} = require('./support/needs');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

/** @type {number[]} Collected per run, in run order: the CLARA case. */
const GOLDEN = [0, 0, 997, 0, 0, 21];

let seed = null;

/**
 * The stopped experiment, seeded once for the file.
 *
 * @returns {{experimentid: string, runs: Array<{id: string, collected: number}>}|{}}
 */
const seeded = () => {
    if (seed !== null) {
        return seed;
    }
    seed = {};
    try {
        const out = execFileSync('php', [pathlib.resolve(__dirname, '../../cli/seed_progress.php'), '--state=stopped'],
            {encoding: 'utf8'});
        const id = out.match(/^STOPPED_EXPERIMENTID=(\d+)$/m);
        const runs = out.match(/^STOPPED_RUNS=(.*)$/m);
        if (id && runs) {
            seed = {
                experimentid: id[1],
                runs: runs[1].split(',').map((pair) => {
                    const [runid, collected] = pair.split(':');
                    return {id: runid, collected: Number(collected)};
                }),
            };
        }
    } catch (e) {
        seed = {};
    }
    return seed;
};

const login = async(page) => {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
};

const region = (page, name) => page.locator(`[data-region="${name}"]`);

test('six stopped runs: 1018 of 6000 collected, 4982 cancelled, none failed, on the page and after a reload',
    async({page}) => {
        const s = seeded();
        needs(!s.experimentid, 'cli/seed_progress.php --state=stopped could not run here.');
        expect(s.runs.map((run) => run.collected)).toEqual(GOLDEN);
        await login(page);
        await page.goto('/local/catquizlab/runs.php?experimentid=' + s.experimentid, {waitUntil: 'networkidle'});

        for (const pass of ['as loaded', 'after a reload']) {
            await expect(region(page, 'catquizlab-progress-count'), pass).toHaveText('1018 / 6000');
            await expect(region(page, 'catquizlab-collected'), pass).toHaveText('1018');
            await expect(region(page, 'catquizlab-failed'), pass).toHaveText('0');
            await expect(region(page, 'catquizlab-cancelled'), pass).toHaveText('4982');
            await expect(region(page, 'catquizlab-queued'), pass).toHaveText('0');
            await expect(region(page, 'catquizlab-progress-cancelled'), pass).toContainText('4982');
            await expect(region(page, 'catquizlab-progress-cancelled'), pass).toContainText(/not failed|nicht gescheitert/);
            // Not done: 1018 of 6000 is not 100 %, and a stop is no end to forecast.
            const percent = Number(await region(page, 'catquizlab-progress-bar').getAttribute('aria-valuenow'));
            expect(percent, pass).toBeGreaterThan(0);
            expect(percent, pass).toBeLessThan(100);
            await expect(region(page, 'catquizlab-experiment-state'), pass).not.toContainText(/Finished|Abgeschlossen/);
            await expect(region(page, 'catquizlab-eta-remaining'), pass).not.toContainText(/\d+\s*(min|h|s)\b/);
            // Every run's row: its own sittings, collected and cancelled, none failed.
            for (const run of s.runs) {
                const card = region(page, `catquizlab-run-${run.id}-card`);
                await expect(card, `${pass}: run ${run.id}`).toContainText(`${run.collected} of 1000`);
                await expect(card, `${pass}: run ${run.id}`).toContainText(`${1000 - run.collected} were cancelled`);
                await expect(card, `${pass}: run ${run.id}`).not.toContainText(/failed/i);
            }
            if (pass === 'as loaded') {
                await page.reload({waitUntil: 'networkidle'});
            }
        }
        // The way on: resuming, not starting from scratch.
        await expect(region(page, 'catquizlab-resume')).toBeVisible();
    });

test('six stopped runs: every run page, All experiments and the research data package say the same', async({page}) => {
    const s = seeded();
    needs(!s.experimentid, 'cli/seed_progress.php --state=stopped could not run here.');
    await login(page);

    // Each run's own page: cancelled, with its own share collected.
    for (const run of s.runs) {
        await page.goto('/local/catquizlab/runs.php?runid=' + run.id, {waitUntil: 'networkidle'});
        const rows = page.locator('table.generaltable tr');
        await expect(rows.filter({hasText: /^\s*Status/})).toContainText(/Cancelled|Abgebrochen/);
        // Its share, rounded down: 997 of 1000 is 99 %, not 100 %.
        await expect(rows.filter({hasText: /^\s*(Progress|Fortschritt)/})).toContainText(
            new RegExp('\\b' + Math.floor(run.collected / 10) + '\\s*%'));
    }

    // All experiments: the same count, the same bar, no countdown.
    let found = false;
    for (let p = 0; p < 200 && !found; p++) {
        await page.goto('/local/catquizlab/runs.php?xpage=' + p, {waitUntil: 'networkidle'});
        found = (await page.locator(`details[data-experimentid="${s.experimentid}"]`).count()) > 0;
        if (!found && !(await page.locator('.pagination a.page-link', {hasText: String(p + 2)}).count())) {
            break;
        }
    }
    expect(found, 'the stopped experiment is listed under All experiments').toBe(true);
    const count = region(page, `catquizlab-exp-${s.experimentid}-count`);
    await expect(count).toContainText('1018');
    await expect(count).toContainText('6000');
    const percent = Number(await region(page, `catquizlab-exp-${s.experimentid}-bar`).getAttribute('aria-valuenow'));
    expect(percent).toBeLessThan(100);
    await expect(region(page, `catquizlab-exp-${s.experimentid}-label`)).not.toContainText(/Finished|Abgeschlossen/);

    // The research data package: per run 1000 sittings, the cancelled ones as such, none failed.
    await page.goto('/local/catquizlab/results.php?experimentid=' + s.experimentid + '&tab=export',
        {waitUntil: 'networkidle'});
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.locator('form:has(input[name="action"][value="researchpackage"]) button[type="submit"]').click(),
    ]);
    const zip = pathlib.join(fs.mkdtempSync(pathlib.join(os.tmpdir(), 'stopped-')), 'package.zip');
    await download.saveAs(zip);
    const read = (name) => execFileSync('unzip', ['-p', zip, name], {maxBuffer: 64 * 1024 * 1024}).toString('utf8');
    const manifest = JSON.parse(read('manifest.json'));
    const lines = read(manifest.tables.runs.file).trim().split('\n');
    const head = lines[0].split(',');
    const col = (name) => head.indexOf(name);
    const byrun = Object.fromEntries(lines.slice(1).map((line) => {
        const cells = line.split(',');
        return [cells[col('runid')], cells];
    }));
    for (const run of s.runs) {
        const cells = byrun[run.id];
        expect(cells, 'run ' + run.id + ' in the package').toBeTruthy();
        expect([cells[col('sittings')], cells[col('sittingsfailed')], cells[col('sittingscancelled')],
            cells[col('status')]], 'run ' + run.id).toEqual(['1000', '0', String(1000 - run.collected), 'cancelled']);
    }
});
