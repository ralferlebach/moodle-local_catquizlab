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

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const EXPERIMENT = process.env.PLOTS_EXPERIMENTID || '';

test('a chosen strategy leaves the others in its menu, and switching needs no reset', async({page}) => {
    test.skip(!EXPERIMENT, 'PLOTS_EXPERIMENTID not set; run cli/seed_results.php first.');

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
    test.skip(!EXPERIMENT, 'PLOTS_EXPERIMENTID not set; run cli/seed_results.php first.');

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
