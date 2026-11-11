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
 * Every scope and every tab of the results page renders whole (#147, D5).
 *
 * The unfiltered page, one experiment, one run: each tab, and the page must answer 200, end with its footer and show
 * neither a resource message nor a PHP error. Data: cli/seed_results_scale.php — long sittings (625 questions, five
 * subscales) of two strategies — seeded here where the environment has none (SCALE_EXPERIMENTID). A large export is
 * fetched on its own, under the page's own time limit.
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');
const {execFileSync} = require('child_process');
const path = require('path');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const TABS = ['overview', 'global', 'subscales', 'deficits', 'robustness', 'testflow', 'rawdata', 'export'];

let seed = null;
const seeded = () => {
    if (seed !== null) {
        return seed;
    }
    seed = {experiment: process.env.SCALE_EXPERIMENTID || '', run: process.env.SCALE_RUNID || ''};
    if (!seed.experiment) {
        try {
            const out = execFileSync('php', [path.resolve(__dirname, '../../cli/seed_results_scale.php'),
                '--sittings=120', '--questions=625', '--scales=5'], {encoding: 'utf8'});
            seed.experiment = (out.match(/^SCALE_EXPERIMENTID=(\d+)/m) || [])[1] || '';
            seed.run = (out.match(/^SCALE_RUNID=(\d+)/m) || [])[1] || '';
        } catch (e) {
            seed.experiment = '';
        }
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

const scopes = () => {
    const {experiment, run} = seeded();
    return [['all experiments', ''], ['one experiment', 'experimentid=' + experiment],
        ['one run', 'experimentid=' + experiment + '&runid=' + run]];
};

for (const [name, index] of [['all experiments', 0], ['one experiment', 1], ['one run', 2]]) {
    test(`results, ${name}: every tab renders whole (#147)`, async({page}) => {
        test.setTimeout(8 * 60 * 1000);
        const {experiment, run} = seeded();
        needs(!experiment || !run, 'No long experiment could be seeded.');
        await login(page);
        const query = scopes()[index][1];
        for (const tab of TABS) {
            const response = await page.goto('/local/catquizlab/results.php?' + (query ? query + '&' : '') + 'tab=' + tab,
                {waitUntil: 'domcontentloaded', timeout: 5 * 60 * 1000});
            expect(response.status(), `${name}, ${tab}: HTTP status`).toBe(200);
            await expect(page.locator('[data-region="catquizlab-resource-limit"]'), `${name}, ${tab}: no resource stop`)
                .toHaveCount(0);
            // The page ends with its footer: nothing stopped half way.
            await expect(page.locator('#page-footer, footer#page-footer, footer').first(), `${name}, ${tab}: footer`)
                .toBeAttached();
            const text = await page.locator('body').innerText();
            expect(text, `${name}, ${tab}: no PHP error`).not.toMatch(/Fatal error|Stack trace|Exception - |Coding error/);
            await expect(page.locator('[data-region="catquizlab-tabs"], .nav-tabs').first()).toBeVisible();
        }
    });
}

test('results: the large step export is a whole file, under the page\'s own limits (#147)', async({page}) => {
    test.setTimeout(10 * 60 * 1000);
    const {experiment} = seeded();
    needs(!experiment, 'No long experiment could be seeded.');
    await login(page);
    const response = await page.request.get('/local/catquizlab/results.php?experimentid=' + experiment
        + '&tab=export&level=step&action=csv&engineinfo=0', {timeout: 9 * 60 * 1000});
    expect(response.status()).toBe(200);
    expect(response.headers()['content-disposition'] || '').toContain('attachment');
    const body = await response.text();
    const lines = body.trim().split('\n');
    // 120 sittings of 625 questions: one row per question asked, valid sittings only, and the header.
    expect(lines.length).toBeGreaterThan(60000);
    expect(lines[0]).toContain('attemptid');
    expect(body).not.toMatch(/Fatal error|catquizlab-resource-limit/);
});
