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
 * Logs: quick filters, advanced filters without loss, deep links from progress, one selection everywhere (#131).
 *
 * Seeds a blocked experiment with cli/seed_progress.php (or takes PROGRESS_BLOCKED_EXPERIMENTID).
 */

const {test, expect} = require('@playwright/test');
const {execFileSync} = require('child_process');
const fs = require('fs');
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

test('quick filters: one click each, keeping the experiment; reset brings everything back', async({page}) => {
    const id = seeded('blocked');
    test.skip(!id, 'No experiment could be seeded.');
    await login(page);
    await page.goto('/local/catquizlab/logs.php?experimentid=' + id + '&hours=0', {waitUntil: 'networkidle'});

    const quick = page.locator('[data-region="catquizlab-log-quick"]');
    await expect(quick.getByRole('link')).toHaveCount(6);
    await quick.getByRole('link', {name: 'Run lifecycle'}).click();
    await page.waitForLoadState('networkidle');
    expect(new URL(page.url()).searchParams.get('channel')).toBe('lifecycle');
    expect(new URL(page.url()).searchParams.get('experimentid')).toBe(id);
    await expect(quick.getByRole('link', {name: 'Run lifecycle'})).toHaveAttribute('aria-current', 'true');
    for (const source of await page.locator('.catquizlab-logline').allTextContents()) {
        expect(source).toContain('lifecycle');
    }

    // Warnings: the pause the breaker made is one.
    await quick.getByRole('link', {name: 'Warnings'}).click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.catquizlab-logline[data-severity="warning"]').first()).toBeVisible();

    // A filter that matches nothing says so, with the way back.
    await page.goto('/local/catquizlab/logs.php?experimentid=' + id + '&hours=0&search=no-such-text-' + Date.now(),
        {waitUntil: 'networkidle'});
    await expect(page.locator('[data-region="catquizlab-log-empty"]')).toHaveAttribute('data-empty', 'nomatch');
    await page.locator('[data-action="catquizlab-log-reset"]').click();
    await page.waitForLoadState('networkidle');
    expect(new URL(page.url()).searchParams.get('search')).toBeNull();
    expect(new URL(page.url()).searchParams.get('experimentid')).toBe(id);
});

test('advanced filters are folded, keep their values, and lose nothing on applying', async({page}) => {
    const id = seeded('blocked');
    test.skip(!id, 'No experiment could be seeded.');
    await login(page);
    await page.goto('/local/catquizlab/logs.php?experimentid=' + id + '&hours=0', {waitUntil: 'networkidle'});

    const advanced = page.locator('[data-region="catquizlab-log-advanced"]');
    expect(await advanced.evaluate((el) => el.open)).toBe(false);
    // Opened by keyboard.
    await advanced.locator('> summary').focus();
    await page.keyboard.press('Enter');
    await page.selectOption('#catquizlab-severity', 'warning');
    await page.check('#catquizlab-newestfirst');
    await page.locator('form:has(#catquizlab-channel) input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const params = new URL(page.url()).searchParams;
    expect([params.get('experimentid'), params.get('severity'), params.get('newestfirst')]).toEqual([id, 'warning', '1']);
    // Set, so shown open.
    expect(await advanced.evaluate((el) => el.open)).toBe(true);
    await expect(page.locator('#catquizlab-severity')).toHaveValue('warning');
    await expect(page.locator('[data-region="catquizlab-log-timezone"]')).toContainText('Times in');
});

test('the progress page leads to the run\'s warnings and errors; text and JSON hold the same selection', async({page}) => {
    const id = seeded('blocked');
    test.skip(!id, 'No experiment could be seeded.');
    await login(page);
    await page.goto('/local/catquizlab/runs.php?experimentid=' + id, {waitUntil: 'networkidle'});

    const link = page.locator('a[href*="logs.php"][href*="severity=warning"]').first();
    await expect(link).toBeVisible();
    await link.click();
    await page.waitForLoadState('networkidle');
    const params = new URL(page.url()).searchParams;
    expect(params.get('runid')).toBeTruthy();
    expect(params.get('severity')).toBe('warning');
    const shown = await page.locator('.catquizlab-logline').count();
    expect(shown).toBeGreaterThan(0);
    await expect(page.locator('[data-region="catquizlab-log-outputs"]')).toContainText(`the ${shown} line(s) shown`);

    // Both downloads: the same lines, the same filter, the time zone, nothing secret.
    const get = async(name) => {
        const [file] = await Promise.all([
            page.waitForEvent('download'),
            page.locator('form:has(input[name="' + name + '"]) button[type="submit"]').click(),
        ]);
        return fs.readFileSync(await file.path(), 'utf8');
    };
    const json = JSON.parse(await get('downloadjson'));
    const txt = await get('download');
    expect(json.count).toBe(shown);
    expect(json.filter.runid).toBe(parseInt(params.get('runid'), 10));
    expect(json.timezone).toBeTruthy();
    expect(txt.split('\n').filter((l) => l !== '' && !l.startsWith('#')).length).toBe(shown);
    expect(txt).toContain('# timezone:');
    expect(txt).not.toMatch(/(wstoken|sesskey|token)=(?!\[redacted\])[A-Za-z0-9]/);
});
