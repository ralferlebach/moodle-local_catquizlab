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
    // Deleted in the background (0.7.49): until cron has run, the row says so and offers no second delete.
    await expect(page.locator('body')).toContainText(/Deleting it runs in the background/);
    await page.goto(LIBRARY + '&q=' + encodeURIComponent(name), {waitUntil: 'networkidle'});
    const gone = page.locator('[data-region="catquizlab-library"] tbody tr').filter({
        has: page.locator('th', {hasText: new RegExp('^\\s*' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*$')}),
    });
    await expect(gone).toHaveAttribute('data-phase', 'deleting');
    await expect(gone.locator('[data-region="catquizlab-library-delete"]')).toHaveCount(0);

    // What cron does next, done now: the queued delete task. Then the experiment is gone and its copy stays.
    // The library reloads itself when its rows change (livestatus); leave it first, so that reload does not
    // cut across the navigation below.
    await page.goto('about:blank');
    const {execFileSync} = require('child_process');
    const fs = require('fs');
    const path = require('path');
    const adhoc = [path.resolve(__dirname, '../../../../admin/cli/adhoc_task.php'),
        path.resolve(__dirname, '../../../../../admin/cli/adhoc_task.php')].find((f) => fs.existsSync(f));
    expect(adhoc, 'admin/cli/adhoc_task.php found').toBeTruthy();
    execFileSync('php', [adhoc, '--execute', '--classname=\\local_catquizlab\\task\\delete_experiment'], {encoding: 'utf8'});
    await page.goto(LIBRARY + '&q=' + encodeURIComponent(name), {waitUntil: 'networkidle'});
    await expect(gone).toHaveCount(0);
    const left = await page.locator('[data-region="catquizlab-library"] tbody tr th').allTextContents();
    expect(left.length, 'the copy is still there').toBeGreaterThanOrEqual(1);
});

test('a wrong name keeps the confirmation page and says why (0.7.50)', async({page}) => {
    await login(page);
    await page.goto(LIBRARY, {waitUntil: 'networkidle'});
    const first = page.locator('[data-region="catquizlab-library"] tbody tr').filter({
        has: page.locator('[data-region="catquizlab-library-delete"]'),
    }).first();
    const name = (await first.locator('th').innerText()).trim();
    await first.locator('[data-region="catquizlab-library-delete"]').click();
    await page.waitForLoadState('networkidle');

    // Wrong: the confirmation page stays, with the reason.
    await page.fill('#catquizlab-confirmname', name + ' x');
    await page.locator('form input[type="submit"].btn-danger').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText('The name you typed does not match');
    await expect(page.locator('#catquizlab-confirmname')).toBeVisible();
    // What does confirm — other spaces, other capitals — is tested in PHPUnit (purger_test), without deleting an
    // experiment other specs still need.
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

test('the one main action follows the phase; a running experiment shows its progress in its row (#129)', async({page}) => {
    const {execFileSync} = require('child_process');
    const path = require('path');
    const seed = (state) => {
        try {
            const out = execFileSync('php', [path.resolve(__dirname, '../../cli/seed_progress.php'), '--state=' + state],
                {encoding: 'utf8'});
            const match = out.match(new RegExp('^PROGRESS_' + state.toUpperCase() + '_EXPERIMENTID=(\\d+)', 'm'));
            return match ? match[1] : '';
        } catch (e) {
            return '';
        }
    };
    const running = seed('running');
    const finished = seed('finished');
    expect(running && finished, 'both experiments seeded').toBeTruthy();
    await login(page);

    const row = async(id) => {
        await page.goto('/local/catquizlab/experiment.php?id=' + id, {waitUntil: 'networkidle'});
        const name = await page.locator('#id_name').inputValue();
        await page.goto(LIBRARY + '&q=' + encodeURIComponent(name), {waitUntil: 'networkidle'});
        return page.locator('tr[data-experiment="' + id + '"]');
    };

    // Running: "View progress", to the progress page; its progress in the row.
    let line = await row(running);
    await expect(line.locator('[data-region="catquizlab-library-main"]')).toHaveText('View progress');
    await expect(line.locator('[data-region="catquizlab-library-main"]'))
        .toHaveAttribute('href', new RegExp('/local/catquizlab/runs\\.php\\?experimentid=' + running + '$'));
    await expect(line.locator('td').first().locator('.small')).toContainText(/\d+\s*\/\s*\d+|\d+ of \d+/);
    // One main action, not two.
    await expect(line.locator('.btn-primary')).toHaveCount(1);
    await line.locator('[data-region="catquizlab-library-main"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(new RegExp('runs\\.php\\?experimentid=' + running));

    // Finished: "View results".
    line = await row(finished);
    await expect(line.locator('[data-region="catquizlab-library-main"]')).toHaveText('View results');
    await expect(line.locator('[data-region="catquizlab-library-main"]'))
        .toHaveAttribute('href', new RegExp('/local/catquizlab/results\\.php\\?experimentid=' + finished + '$'));
    await expect(line.locator('.btn-primary')).toHaveCount(1);
});
