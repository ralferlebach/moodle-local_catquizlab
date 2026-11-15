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
 * "All experiments" (#158): the population on top, every experiment A–Z, every run of each, live.
 *
 * Data from cli/seed_progress.php --state=overview: "… a running" (30 runs, one paused, one held), "… b finished"
 * and "… c planned".
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');
const {execFileSync} = require('child_process');
const path = require('path');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

let seed = null;

/**
 * The three experiments, seeded once for the file.
 *
 * @returns {Object} Ids and the common name prefix.
 */
const seeded = () => {
    if (seed !== null) {
        return seed;
    }
    seed = {};
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/seed_progress.php'), '--state=overview'],
            {encoding: 'utf8'});
        for (const line of out.split('\n')) {
            const match = line.match(/^OVERVIEW_(\w+?)(?:_EXPERIMENTID)?=(.*)$/);
            if (match) {
                seed[match[1].toLowerCase()] = match[2];
            }
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

/**
 * The section of a seeded experiment, on whichever page of sections it is.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} id The experiment id.
 * @returns {Promise<import('@playwright/test').Locator>}
 */
const section = async(page, id) => {
    for (let p = 0; p < 50; p++) {
        await page.goto('/local/catquizlab/runs.php?xpage=' + p, {waitUntil: 'networkidle'});
        const found = page.locator(`details[data-experimentid="${id}"]`);
        if (await found.count()) {
            return found;
        }
        if (!(await page.locator('.pagination a.page-link', {hasText: String(p + 2)}).count())) {
            break;
        }
    }
    return page.locator(`details[data-experimentid="${id}"]`);
};

test('the population, every experiment A–Z, every run: nothing cut at 25', async({page}) => {
    const ids = seeded();
    needs(!ids.running, 'cli/seed_progress.php --state=overview could not run here.');
    await login(page);
    const running = await section(page, ids.running);
    await expect(running).toBeVisible();

    // On top: the population with exact counts and the time for everything open.
    const overview = page.locator('[data-region="catquizlab-overview"]');
    await expect(overview).toContainText('All experiments');
    await expect(page.locator('[data-region="catquizlab-overview-population"]')).toContainText('runs');
    await expect(page.locator('[data-region="catquizlab-overview-eta"]')).toBeVisible();

    // All 30 runs of the running experiment, each with a link to its detail.
    await running.locator(':scope > summary').click();
    if (!(await running.getAttribute('open'))) {
        await running.locator(':scope > summary').click();
    }
    const rows = running.locator('tbody tr');
    await expect(rows).toHaveCount(30);
    await expect(rows.first().locator('th a')).toHaveAttribute('href', /runs\.php\?runid=\d+/);
    // The held run shows red, the strategy is named.
    await expect(running.locator('.progress-bar.bg-danger')).toHaveCount(1);
    await expect(running).toContainText('Classic');
    // A held run blocks the experiment, said in its badge.
    await expect(running.locator('[data-region$="-label"]')).toContainText(/Blocked|Held/);
});

test('A–Z, finished and planned listed, finished at 100 % with its end', async({page}) => {
    const ids = seeded();
    needs(!ids.finished, 'cli/seed_progress.php --state=overview could not run here.');
    await login(page);
    const finished = await section(page, ids.finished);
    await expect(finished).toHaveAttribute('data-state', 'finished');
    await expect(page.locator(`[data-region="catquizlab-exp-${ids.finished}-bar"]`)).toHaveAttribute('aria-valuenow', '100');
    await expect(page.locator(`[data-region="catquizlab-exp-${ids.finished}-finish"]`)).toContainText('Finished');

    // The three seeded experiments in A–Z order wherever they are on the page.
    const names = await page.locator('details[data-experimentid] summary .h6').allTextContents();
    const ours = names.filter((n) => n.startsWith(ids.prefix));
    expect(ours).toEqual([...ours].sort((a, b) => a.localeCompare(b)));
});

test('a section opened or closed stays so through polls and a reload; switching to one experiment and back', async({page}) => {
    const ids = seeded();
    needs(!ids.planned, 'cli/seed_progress.php --state=overview could not run here.');
    await login(page);
    const planned = await section(page, ids.planned);
    const wasopen = (await planned.getAttribute('open')) !== null;
    await planned.locator(':scope > summary').click();
    await expect(planned).toHaveJSProperty('open', !wasopen);
    await page.waitForTimeout(4500);
    await expect(planned).toHaveJSProperty('open', !wasopen);
    await page.reload({waitUntil: 'networkidle'});
    await expect(page.locator(`details[data-experimentid="${ids.planned}"]`)).toHaveJSProperty('open', !wasopen);

    // One experiment, then all again: the same counts on both.
    const count = await page.locator(`[data-region="catquizlab-exp-${ids.running}-count"]`).textContent().catch(() => null);
    await page.goto('/local/catquizlab/runs.php?experimentid=' + ids.running, {waitUntil: 'networkidle'});
    if (count) {
        await expect(page.locator('[data-region="catquizlab-progress-count"]')).toHaveText(count.trim());
    }
    await page.goto('/local/catquizlab/runs.php', {waitUntil: 'networkidle'});
    await expect(page.locator('[data-region="catquizlab-overview"]')).toBeVisible();
});

test('keyboard, screen reader, 320 px and 200 % zoom', async({page}) => {
    const ids = seeded();
    needs(!ids.running, 'cli/seed_progress.php --state=overview could not run here.');
    await login(page);
    const running = await section(page, ids.running);
    // Every bar names its unit.
    for (const bar of await page.locator('[data-region="catquizlab-overview"] [role="progressbar"]').all()) {
        await expect(bar).toHaveAttribute('aria-valuetext', /sittings|answered|of/);
    }
    // A section opens from the keyboard.
    const summary = running.locator(':scope > summary');
    await summary.focus();
    const before = await running.evaluate((d) => d.open);
    await page.keyboard.press('Enter');
    await expect(running).toHaveJSProperty('open', !before);

    for (const width of [320, 640]) {
        // 640 CSS pixels is a 1280-pixel window at 200 % zoom.
        await page.setViewportSize({width, height: 800});
        const overflow = await page.evaluate(() => {
            const card = document.querySelector('[data-region="catquizlab-overview"]');
            return card.scrollWidth - card.clientWidth;
        });
        expect(overflow, `no horizontal scrolling at ${width} px`).toBeLessThanOrEqual(1);
    }
});
