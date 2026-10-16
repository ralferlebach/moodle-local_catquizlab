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
 * One status per experiment in header, progress card and run summary; the next step by state (#128).
 *
 * Seeds its experiments with cli/seed_progress.php (or takes PROGRESS_<STATE>_EXPERIMENTID).
 */

const {test, expect} = require('@playwright/test');
const {execFileSync} = require('child_process');
const path = require('path');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

const seeded = (state) => {
    const name = 'PROGRESS_' + state.toUpperCase() + '_EXPERIMENTID';
    if (process.env[name]) {
        return process.env[name];
    }
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/seed_progress.php'), '--state=' + state],
            {encoding: 'utf8'});
        const match = out.match(new RegExp('^' + name + '=(\\d+)', 'm'));
        return match ? match[1] : '';
    } catch (e) {
        return '';
    }
};

const login = async(page) => {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
};

const bar = (page) => page.locator('[data-region="catquizlab-status"]');

test('running: header, progress card and run summary say the same, and wait for the poll to agree', async({page}) => {
    const id = seeded('running');
    test.skip(!id, 'No running experiment could be seeded.');
    await login(page);
    await page.goto('/local/catquizlab/runs.php?experimentid=' + id, {waitUntil: 'networkidle'});

    const label = (await bar(page).locator('[data-region="catquizlab-status-label"]').textContent()).trim();
    expect(['Collecting sittings', 'Waiting']).toContain(label);
    await expect(page.locator('[data-region="catquizlab-progress-label"]')).toHaveText(label);
    await expect(page.locator('[data-region="catquizlab-experiment-state"]')).toHaveText(label);
    await expect(bar(page).locator('[data-region="catquizlab-status-sittings"]')).toContainText('28 of 40 sittings');
    await expect(bar(page).locator('[data-region="catquizlab-status-asof"]')).toContainText('As of');
    // Technical figures apart from the scientific status.
    await expect(bar(page).locator('[data-region="catquizlab-status-technical"]')).toContainText('Technical:');

    // After a few polls the three still agree.
    await page.waitForTimeout(4500);
    const after = (await bar(page).locator('[data-region="catquizlab-status-label"]').textContent()).trim();
    await expect(page.locator('[data-region="catquizlab-progress-label"]')).toHaveText(after);
    await expect(page.locator('[data-region="catquizlab-experiment-state"]')).toHaveText(after);

    // No start for a running experiment, anywhere on the page.
    await expect(page.getByRole('button', {name: 'Start experiment'})).toHaveCount(0);
    await expect(page.getByRole('button', {name: 'Run experiment'})).toHaveCount(0);

    // Results: the bar offers the way to the progress.
    await page.goto('/local/catquizlab/results.php?experimentid=' + id, {waitUntil: 'networkidle'});
    await expect(bar(page).getByRole('link', {name: 'View progress'})).toBeVisible();
});

test('blocked: "Needs attention", the problems counted, and the way to them', async({page}) => {
    const id = seeded('blocked');
    test.skip(!id, 'No blocked experiment could be seeded.');
    await login(page);
    await page.goto('/local/catquizlab/runs.php?experimentid=' + id, {waitUntil: 'networkidle'});

    await expect(bar(page)).toHaveAttribute('data-phase', 'blocked');
    await expect(bar(page).locator('[data-region="catquizlab-status-label"]')).toHaveText('Needs attention');
    await expect(bar(page).locator('[data-region="catquizlab-status-problems"]')).toContainText('1 run(s) held');
    const next = bar(page).getByRole('link', {name: 'Review problems'});
    await expect(next).toHaveAttribute('href', /#catquizlab-recovery$/);
});

test('finished: the next step is the results; planned: create runs, by POST', async({page}) => {
    const finished = seeded('finished');
    const empty = seeded('empty');
    test.skip(!finished || !empty, 'No experiments could be seeded.');
    await login(page);

    await page.goto('/local/catquizlab/runs.php?experimentid=' + finished, {waitUntil: 'networkidle'});
    await expect(bar(page).locator('[data-region="catquizlab-status-label"]')).toHaveText('Complete');
    await expect(bar(page).getByRole('link', {name: 'View results'})).toBeVisible();

    await page.goto('/local/catquizlab/runs.php?experimentid=' + empty, {waitUntil: 'networkidle'});
    await expect(bar(page)).toHaveAttribute('data-phase', 'planned');
    const create = bar(page).getByRole('button', {name: 'Create runs'});
    await expect(create).toBeVisible();
    await expect(bar(page).locator('form[data-region="catquizlab-status-next"]')).toHaveAttribute('method', 'post');
});

test('the five tabs and the result subtabs stay; no bare "Show"; keyboard and screen-reader semantics', async({page}) => {
    const id = seeded('finished');
    test.skip(!id, 'No experiment could be seeded.');
    await login(page);
    await page.goto('/local/catquizlab/results.php?experimentid=' + id, {waitUntil: 'networkidle'});

    const tabs = page.locator('[data-region="catquizlab-steps"] .nav-link');
    await expect(tabs).toHaveCount(5);
    await expect(tabs).toHaveText([/Preparation/, /Experiment plan/, /Progress/, /Results/, /Logs/]);
    for (const subtab of ['overview', 'export']) {
        const response = await page.goto(`/local/catquizlab/results.php?experimentid=${id}&tab=${subtab}`);
        expect(response.status()).toBe(200);
    }

    // No summary or button that says only "Show".
    const bare = await page.locator('summary, button, input[type=submit]').evaluateAll((nodes) =>
        nodes.map((n) => (n.value || n.textContent || '').trim()).filter((t) => t === 'Show'));
    expect(bare).toEqual([]);

    // The bar is a named region with a live status word; its action is reachable by keyboard.
    await expect(page.getByRole('region', {name: /Progress finished/})).toBeVisible();
    await expect(bar(page).getByRole('status')).toHaveText('Complete');
    const next = bar(page).getByRole('link', {name: 'View results'});
    if (await next.count()) {
        await next.focus();
        await expect(next).toBeFocused();
    }
});
