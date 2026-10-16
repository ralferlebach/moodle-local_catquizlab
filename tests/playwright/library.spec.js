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
 * The experiment library: create, find, open, compare, copy, delete — and no delete by GET (#129).
 */

const {test, expect} = require('@playwright/test');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const LIBRARY = '/local/catquizlab/index.php?tab=experiments';

const login = async(page) => {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
};

const row = (page, name) => page.locator('[data-region="catquizlab-library"] tbody tr')
    .filter({has: page.getByRole('rowheader', {name, exact: true})});

test('create, find, open, compare, copy and delete an experiment from the library', async({page}) => {
    await login(page);
    const name = 'Library ' + Date.now();

    // Create: the one primary "New experiment".
    await page.goto(LIBRARY, {waitUntil: 'networkidle'});
    await page.locator('[data-region="catquizlab-new-experiment"]').click();
    await page.fill('#id_name', name);
    await page.click('#id_submitbutton');
    await page.waitForLoadState('networkidle');

    // Find it.
    await page.goto(LIBRARY, {waitUntil: 'networkidle'});
    await page.fill('#catquizlab-library-q', name);
    await page.locator('[data-region="catquizlab-library-search"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-region="catquizlab-library"] tbody tr')).toHaveCount(1);
    await expect(page.locator('[data-region="catquizlab-library-count"]')).toContainText('1 of');
    const mine = row(page, name);
    // A planned experiment: one main action, "Open", and its state in words.
    await expect(mine.locator('[data-region="catquizlab-library-main"]')).toHaveText('Open');
    await expect(mine.locator('.badge')).toHaveText('Planned — no runs yet');
    // Delete is not beside the main action and the "More" menu, but in a column of its own.
    await expect(mine.locator('td').nth(3).locator('[data-region="catquizlab-library-delete"]')).toHaveCount(0);

    // Compare, from "More".
    await mine.locator('[data-region="catquizlab-library-more"] summary').click();
    await expect(mine.getByRole('link', {name: 'Compare'})).toBeVisible();
    await expect(mine.getByRole('link', {name: 'Compare'})).toHaveAttribute('href', /compare\.php\?experimentid=\d+/);

    // Copy, by POST from "More".
    await mine.getByRole('button', {name: 'Duplicate'}).click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText(/duplicated|dupliziert|copy|Kopie/i);

    // A GET that carries the name deletes nothing.
    await page.goto(LIBRARY + '&q=' + encodeURIComponent(name), {waitUntil: 'networkidle'});
    const href = await row(page, name).locator('[data-region="catquizlab-library-delete"]').getAttribute('href');
    await page.goto(href + '&confirmname=' + encodeURIComponent(name), {waitUntil: 'networkidle'});
    await expect(page.locator('body')).toContainText('Deleting needs the confirmation form: nothing was deleted.');
    await page.goto(LIBRARY + '&q=' + encodeURIComponent(name), {waitUntil: 'networkidle'});
    expect(await page.locator('[data-region="catquizlab-library"] tbody tr').count()).toBeGreaterThanOrEqual(1);

    // Delete for real: the confirmation page, the name typed, POST.
    await row(page, name).locator('[data-region="catquizlab-library-delete"]').click();
    await page.waitForLoadState('networkidle');
    await page.fill('#catquizlab-confirmname', name);
    await page.locator('form input[type="submit"].btn-danger').click();
    await page.waitForLoadState('networkidle');
    await page.goto(LIBRARY + '&q=' + encodeURIComponent(name), {waitUntil: 'networkidle'});
    // Only the copy is left (its name starts with the original's or carries it).
    const left = await page.locator('[data-region="catquizlab-library"] tbody tr th').allTextContents();
    expect(left.filter((t) => t.trim() === name)).toEqual([]);
});

test('no match is said with a way back; sorting by name; usable on a phone and by keyboard', async({page}) => {
    await login(page);
    await page.goto(LIBRARY + '&q=' + encodeURIComponent('no-such-experiment-' + Date.now()), {waitUntil: 'networkidle'});
    await expect(page.locator('[data-region="catquizlab-library-nomatch"]')).toContainText('No experiment matches');
    await page.locator('[data-region="catquizlab-library-nomatch"] a').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#catquizlab-library-q')).toHaveValue('');

    await page.selectOption('#catquizlab-library-sort', 'name');
    await page.locator('[data-region="catquizlab-library-search"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    const names = (await page.locator('[data-region="catquizlab-library"] tbody th').allTextContents()).map((t) => t.trim());
    const sorted = [...names].sort((a, b) => a.localeCompare(b, undefined, {numeric: true, sensitivity: 'base'}));
    expect(names.slice(0, 10)).toEqual(sorted.slice(0, 10));

    // Keyboard: the search field, the sort, the button, then the first row's link.
    await page.locator('#catquizlab-library-q').focus();
    await page.keyboard.press('Tab');
    await expect(page.locator('#catquizlab-library-sort')).toBeFocused();

    // Phone width: the table scrolls inside its box, not the page.
    await page.setViewportSize({width: 390, height: 844});
    await page.goto(LIBRARY, {waitUntil: 'networkidle'});
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});
