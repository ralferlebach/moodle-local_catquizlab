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
 * The library's sort and the experiment selector by keyboard alone, and on a phone (#153 AK8).
 *
 * The selector used to submit on every change of its value. The arrow keys of a closed drop-down change the value at
 * every press on Windows, so a keyboard or screen reader user left the page on the first press and could not reach
 * the experiment they wanted (WCAG 3.2.2, On Input). Now the arrow keys only choose; Enter or "Apply" shows the
 * choice; a choice made with the mouse is shown at once, as before.
 *
 * Data from cli/seed_progress.php --state=library (55 planned experiments under one prefix).
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');
const {execFileSync} = require('child_process');
const path = require('path');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const LIBRARY = '/local/catquizlab/index.php?tab=experiments';

const seededLibrary = () => {
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/seed_progress.php'), '--state=library'],
            {encoding: 'utf8', timeout: 300000});
        const match = out.match(/^LIBRARY_PREFIX=(.*)$/m);
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

/**
 * Presses a key until the focus is on the element, at most a number of times.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {import('@playwright/test').Locator} target The element.
 * @param {number} max At most this many presses.
 * @returns {Promise<number>} The presses it took, or -1.
 */
const tabTo = async(page, target, max = 60) => {
    for (let i = 1; i <= max; i++) {
        await page.keyboard.press('Tab');
        if (await target.evaluate((el) => el === document.activeElement)) {
            return i;
        }
    }
    return -1;
};

test('the selector and the library\'s sort by keyboard alone: arrows choose, Enter shows (#153 AK8)', async({page}) => {
    const prefix = seededLibrary();
    needs(!prefix, 'cli/seed_progress.php --state=library could not be run here.');
    await login(page);
    await page.goto('/local/catquizlab/runs.php', {waitUntil: 'networkidle'});
    const selector = page.locator('#catquizlab-experiment');
    await expect(selector).toHaveAttribute('aria-describedby', 'catquizlab-experiment-hint');
    await expect(page.locator('#catquizlab-experiment-hint')).not.toBeEmpty();
    const url = page.url();

    // From the top of the page to the selector with Tab only.
    await page.locator('body').focus();
    expect(await tabTo(page, selector), 'Tab reaches the selector').toBeGreaterThan(0);
    const before = await selector.inputValue();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(1500);
    // Chosen, not left: the page is the same, the value another.
    expect(page.url(), 'the arrow keys do not leave the page').toBe(url);
    const chosen = await selector.inputValue();
    expect(chosen, 'the arrow keys choose').not.toBe(before);
    await Promise.all([page.waitForURL(/experimentid=\d+/), page.keyboard.press('Enter')]);
    await page.waitForLoadState('networkidle');
    expect(new URL(page.url()).searchParams.get('experimentid'), 'Enter shows what was chosen').toBe(chosen);
    await expect(page.locator('#catquizlab-experiment')).toHaveValue(chosen);

    // "Apply" reached by Tab does the same.
    await page.locator('#catquizlab-experiment').focus();
    await page.keyboard.press('ArrowDown');
    const next = await page.locator('#catquizlab-experiment').inputValue();
    await page.keyboard.press('Tab');
    await expect(page.locator('[data-region="catquizlab-experiment-apply"]')).toBeFocused();
    await Promise.all([page.waitForURL(new RegExp('experimentid=' + next + '\\b')), page.keyboard.press('Enter')]);

    // The library's sort: label, value, and applied with the keyboard; the choice is kept.
    await page.goto(LIBRARY + '&sort=modified', {waitUntil: 'networkidle'});
    const sort = page.locator('#catquizlab-library-sort');
    await expect(page.locator('label[for="catquizlab-library-sort"]')).not.toBeEmpty();
    await page.locator('body').focus();
    expect(await tabTo(page, sort), 'Tab reaches the sort').toBeGreaterThan(0);
    await sort.selectOption('name');
    await page.keyboard.press('Tab');
    await expect(page.locator('[data-region="catquizlab-library-search"] button[type="submit"]')).toBeFocused();
    await Promise.all([page.waitForURL(/sort=name/), page.keyboard.press('Enter')]);
    await expect(page.locator('#catquizlab-library-sort')).toHaveValue('name');
    await page.goto(LIBRARY, {waitUntil: 'networkidle'});
    await expect(page.locator('#catquizlab-library-sort'), 'the sort is kept').toHaveValue('name');

    // A choice with the mouse is shown at once, as before.
    await page.goto('/local/catquizlab/runs.php', {waitUntil: 'networkidle'});
    const options = await page.locator('#catquizlab-experiment option').evaluateAll((os) => os.map((o) => o.value));
    await Promise.all([page.waitForURL(new RegExp('experimentid=' + options[2] + '\\b')),
        page.selectOption('#catquizlab-experiment', options[2])]);
});

test('the selector and the library\'s sort at 320 px: on the page, operable, labelled (#153 AK8)', async({page}) => {
    const prefix = seededLibrary();
    needs(!prefix, 'cli/seed_progress.php --state=library could not be run here.');
    await page.setViewportSize({width: 320, height: 640});
    await login(page);
    for (const [url, ids] of [['/local/catquizlab/runs.php', ['#catquizlab-experiment',
        '[data-region="catquizlab-experiment-apply"]']], [LIBRARY, ['#catquizlab-library-q', '#catquizlab-library-sort',
        '[data-region="catquizlab-library-search"] button[type="submit"]']]]) {
        await page.goto(url, {waitUntil: 'networkidle'});
        for (const id of ids) {
            const element = page.locator(id).first();
            await element.scrollIntoViewIfNeeded();
            await expect(element, id + ' visible at 320 px').toBeVisible();
            const box = await element.boundingBox();
            expect(box.x, id + ' starts on the page').toBeGreaterThanOrEqual(0);
            expect(box.x + box.width, id + ' ends on the page').toBeLessThanOrEqual(321);
            expect(box.height, id + ' big enough to touch').toBeGreaterThanOrEqual(24);
        }
    }
    // Operated by touch-sized means: the sort applied at 320 px.
    await page.locator('#catquizlab-library-sort').selectOption('state');
    await Promise.all([page.waitForURL(/sort=state/),
        page.locator('[data-region="catquizlab-library-search"] button[type="submit"]').click()]);
    await expect(page.locator('#catquizlab-library-sort')).toHaveValue('state');
    await page.locator('#catquizlab-library-sort').selectOption('name');
    await page.locator('[data-region="catquizlab-library-search"] button[type="submit"]').click();
});
