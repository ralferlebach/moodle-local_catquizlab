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
 * The factor menus switch from one value to another without a reset (#122).
 *
 * Data from cli/seed_results.php (PLOTS_EXPERIMENTID): two runs, two strategies.
 */

const {test, expect} = require('@playwright/test');
const {execFileSync} = require('child_process');
const path = require('path');
const {needs} = require('./support/needs');

/**
 * The six-strategy experiment (#122): from the environment, or seeded now on the installation under test.
 *
 * Seeded here, as the status and progress specs seed theirs, so that the test does not hang on a workflow step
 * that may not be there — the CI run of 02.11. failed for exactly that.
 *
 * @returns {string} The experiment id, or '' where it could not be seeded.
 */
const filtersExperiment = () => {
    if (process.env.FILTERS_EXPERIMENTID) {
        return process.env.FILTERS_EXPERIMENTID;
    }
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/seed_results.php'), '--persons=4',
            '--strategies=all', '--label=FILTERS'], {encoding: 'utf8'});
        const match = out.match(/^FILTERS_EXPERIMENTID=(\d+)/m);
        return match ? match[1] : '';
    } catch (e) {
        return '';
    }
};

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const EXPERIMENT = process.env.PLOTS_EXPERIMENTID || '';

test('a chosen strategy leaves the others in its menu, and switching needs no reset', async({page}) => {
    needs(!EXPERIMENT, 'PLOTS_EXPERIMENTID not set; run cli/seed_results.php first.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');

    await page.goto('/local/catquizlab/results.php?experimentid=' + EXPERIMENT + '&tab=overview', {waitUntil: 'networkidle'});
    const menu = page.locator('#catlabfilter_strategy');
    const values = (await menu.locator('option').evaluateAll((options) => options.map((o) => o.value))).filter((v) => v !== '');
    expect(values.length, 'the seed has two strategies').toBeGreaterThan(1);

    // The first strategy, applied: the menu still offers every strategy.
    await menu.selectOption(values[0]);
    await page.click('form:has(#catlabfilter_strategy) input[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#catlabfilter_strategy')).toHaveValue(values[0]);
    const after = (await page.locator('#catlabfilter_strategy option').evaluateAll((options) => options.map((o) => o.value)))
        .filter((v) => v !== '');
    expect(after).toEqual(values);

    // Straight to the second.
    await page.locator('#catlabfilter_strategy').selectOption(values[1]);
    await page.click('form:has(#catlabfilter_strategy) input[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#catlabfilter_strategy')).toHaveValue(values[1]);
    expect(page.url()).toContain('strategy=' + values[1]);
    // Each value says how many runs it has under the rest of the selection.
    await expect(page.locator('#catlabfilter_strategy option[value="' + values[0] + '"]')).toHaveText(/\(\d+\)$/);

    // Back and forward walk the selections; the address is the selection.
    await page.goBack({waitUntil: 'networkidle'});
    await expect(page.locator('#catlabfilter_strategy')).toHaveValue(values[0]);
    await page.goForward({waitUntil: 'networkidle'});
    await expect(page.locator('#catlabfilter_strategy')).toHaveValue(values[1]);

    // Reset clears the factors and keeps the experiment.
    await page.click('[data-action="catquizlab-filter-reset"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#catlabfilter_strategy')).toHaveValue('');
    expect(page.url()).toContain('experimentid=' + EXPERIMENT);
});

test('a factor keeps the validity choice, and the menus work by keyboard and by name', async({page}) => {
    needs(!EXPERIMENT, 'PLOTS_EXPERIMENTID not set; run cli/seed_results.php first.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
    await page.goto('/local/catquizlab/results.php?experimentid=' + EXPERIMENT + '&tab=overview&validity=all',
        {waitUntil: 'networkidle'});

    // Every factor menu has an accessible name: its visible label.
    for (const id of ['catlabfilter_strategy', 'catlabfilter_model']) {
        const name = await page.locator('label[for="' + id + '"]').textContent();
        await expect(page.getByLabel(name.trim(), {exact: true})).toHaveAttribute('id', id);
    }

    // By keyboard: focus, arrow down to the first strategy, Tab on to Apply and press it.
    await page.focus('#catlabfilter_strategy');
    await page.keyboard.press('ArrowDown');
    const chosen = await page.locator('#catlabfilter_strategy').inputValue();
    expect(chosen).not.toBe('');
    await page.locator('form:has(#catlabfilter_strategy) input[type="submit"]').focus();
    await page.keyboard.press('Enter');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#catlabfilter_strategy')).toHaveValue(chosen);
    // The validity chosen before stays chosen.
    expect(page.url()).toContain('validity=all');
});

test('every strategy stays in the menu; lowestsub, fastest, classic one after another; a tab keeps the choice', async({page}) => {
    // #122: the walk the issue names, on an experiment with one run per strategy the installed engine plays.
    const experiment = filtersExperiment();
    needs(!experiment, 'No six-strategy experiment could be seeded (cli/seed_results.php --strategies=all --label=FILTERS).');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');

    await page.goto('/local/catquizlab/results.php?experimentid=' + experiment + '&tab=overview', {waitUntil: 'networkidle'});
    const options = async() => (await page.locator('#catlabfilter_strategy option')
        .evaluateAll((list) => list.map((o) => o.value))).filter((v) => v !== '');
    const all = await options();
    expect(all.length, 'at least six strategies seeded').toBeGreaterThanOrEqual(6);

    // The issue's sequence, where the engine plays it; then the rest — each chosen directly from the last.
    const walk = ['lowestsub', 'fastest', 'classic'].filter((s) => all.includes(s));
    expect(walk.length, 'lowestsub, fastest and classic seeded').toBe(3);
    for (const strategy of [...walk, ...all.filter((s) => !walk.includes(s))]) {
        await page.locator('#catlabfilter_strategy').selectOption(strategy);
        await page.click('form:has(#catlabfilter_strategy) input[type="submit"]');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#catlabfilter_strategy')).toHaveValue(strategy);
        expect(page.url()).toContain('strategy=' + strategy);
        // Its own choice does not trim its own menu.
        expect(await options()).toEqual(all);
        await expect(page.locator('body')).not.toContainText(/No attempts match this filter|Keine Bearbeitungen passen/);
    }

    // A tab change keeps the selection: every tab link carries it.
    const last = await page.locator('#catlabfilter_strategy').inputValue();
    // The results tabs (role navigation, render_tabs()) — not Moodle's administration tabs, and not the plugin's
    // process steps, whose "Results" step opens the experiment's results afresh by design.
    const tabs = page.locator('[data-region="catquizlab-results-tabs"] a.nav-link');
    const n = await tabs.count();
    expect(n).toBeGreaterThan(1);
    for (let i = 0; i < n; i++) {
        expect(await tabs.nth(i).getAttribute('href')).toContain('strategy=' + last);
    }
    await tabs.filter({hasText: /Global/}).first().click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#catlabfilter_strategy')).toHaveValue(last);
});

