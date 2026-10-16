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
 * Runs, sittings and people counted apart, and the same sample in table, plot and export (#127).
 *
 * Data from cli/seed_results.php (PLOTS_EXPERIMENTID): two strategies, one run
 * each, the same twenty twins in both.
 */

const {test, expect} = require('@playwright/test');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const EXPERIMENT = process.env.PLOTS_EXPERIMENTID || '';

test('one run of twenty sittings reads as one run, twenty sittings, twenty people — in table, plot and export', async({page}) => {
    test.skip(!EXPERIMENT, 'PLOTS_EXPERIMENTID not set; run cli/seed_results.php first.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');

    await page.goto('/local/catquizlab/results.php?experimentid=' + EXPERIMENT + '&tab=overview', {waitUntil: 'networkidle'});

    // The strategy table: Runs, Sittings, People are three columns.
    const table = page.locator('table:has(th:text-is("Runs")):has(th:text-is("Sittings"))').first();
    await expect(table).toBeVisible();
    const head = await table.locator('th').allTextContents();
    const col = (name) => head.findIndex((h) => h.trim() === name);
    const rows = table.locator('tbody tr');
    expect(await rows.count()).toBe(2);
    let sittings = 0;
    for (let i = 0; i < 2; i++) {
        const cells = await rows.nth(i).locator('td').allTextContents();
        expect(cells[col('Runs')].trim()).toBe('1');
        expect(cells[col('Sittings')].trim()).toBe('20');
        expect(cells[col('People')].trim()).toBe('20');
        sittings += parseInt(cells[col('Sittings')], 10);
    }

    // The basis line above every tab names all of them (#127 req. 1).
    await expect(page.locator('body')).toContainText(sittings + ' sittings of 20 simulated people, in 2 run(s)');

    // The plot says the same sample.
    await expect(page.locator('[data-region="catquizlab-plot-basis"]').first()).toContainText(sittings + ' sittings');
    await expect(page.locator('[data-region="catquizlab-plot-basis"]').first()).toContainText('2 run(s)');

    // The export has a row per sitting, and names its interval's unit.
    const base = '/local/catquizlab/results.php?experimentid=' + EXPERIMENT + '&validity=valid&validityrule=uniform&tab=export';
    const csv = await (await page.request.get(base + '&level=attempt&action=csv')).text();
    expect(csv.split(/\r?\n/).filter((l) => l !== '').length - 1).toBe(sittings);
    const meta = JSON.parse(await (await page.request.get(base + '&level=attempt&action=meta')).text());
    expect(meta.attempts).toBe(sittings);
    expect(meta.runs).toBe(2);
    expect(meta.people).toBe(20);
    expect(meta.cells).toBe(2);
    expect(meta.uncertainty.unit).toContain('simulated person');
});
