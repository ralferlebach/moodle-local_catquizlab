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
 * Started, elapsed, finish and remaining in each state of an execution (#125).
 *
 * Data from cli/seed_progress.php --state=running|calculating|blocked|finished|empty,
 * whose ids arrive as PROGRESS_<STATE>_EXPERIMENTID.
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');
const {execFileSync} = require('child_process');
const path = require('path');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

const login = async(page) => {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
};

/**
 * The experiment in a state: from the environment, or seeded now — a running
 * state is only "running" for the few minutes after it was written.
 *
 * @param {string} state The state.
 * @returns {string} The experiment id, or '' where it cannot be had.
 */
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

const open = async(page, state) => {
    const id = seeded(state);
    needs(!id, `No experiment in state ${state}: set ${'PROGRESS_' + state.toUpperCase()}_EXPERIMENTID or allow php here.`);
    await login(page);
    await page.goto('/local/catquizlab/runs.php?experimentid=' + id, {waitUntil: 'networkidle'});
    return page.locator('[data-region="catquizlab-eta"]');
};

const value = (page, name) => page.locator(`[data-region="catquizlab-eta-${name}"]`);

test('running: all four values, a range, the method, and a start that does not move with the polls', async({page}) => {
    const box = await open(page, 'running');
    await expect(box).toHaveAttribute('data-reliability', 'estimate');
    await expect(value(page, 'started')).toContainText('(');
    await expect(value(page, 'elapsed')).not.toHaveText('—');
    await expect(value(page, 'finish')).toContainText('≈');
    await expect(value(page, 'remaining')).toContainText('between');
    await expect(value(page, 'method')).toContainText('queue order');

    // Two polls later (2 s each), the start is the same and the snapshot time has advanced or stayed.
    const started = await value(page, 'started').textContent();
    await page.waitForTimeout(5000);
    await expect(value(page, 'started')).toHaveText(started);
    // The polls went through: nothing marked stale.
    await expect(value(page, 'stale')).toBeHidden();
    // A reload computes again on the server: still the same start.
    await page.reload({waitUntil: 'networkidle'});
    await expect(value(page, 'started')).toHaveText(started);

    // Per run: the finished run says so; the other has its own estimate.
    const runs = page.locator('[data-region="catquizlab-run-times"] tbody tr');
    await expect(runs).toHaveCount(2);
    await expect(runs.nth(0)).toContainText('Nothing left');
    await expect(runs.nth(1)).toContainText('≈');

    // Keyboard: the method and the run table open from the keyboard.
    await page.locator('[data-region="catquizlab-eta"] details summary').focus();
    await page.keyboard.press('Enter');
    await expect(value(page, 'method')).toBeVisible();
});

test('starting: calculating, with the reason', async({page}) => {
    const box = await open(page, 'calculating');
    await expect(box).toHaveAttribute('data-reliability', 'calculating');
    await expect(value(page, 'started')).toContainText('(');
    await expect(value(page, 'remaining')).toHaveText('Calculating…');
    await expect(value(page, 'reason')).toContainText('Fewer than three sittings finished');
});

test('blocked: no running forecast, marked as not reliable', async({page}) => {
    const box = await open(page, 'blocked');
    await expect(box).toHaveAttribute('data-reliability', 'unreliable');
    await expect(value(page, 'status')).toHaveText('No reliable estimate');
    await expect(value(page, 'finish')).toHaveText('—');
    await expect(value(page, 'reason')).toContainText('A run is held');
});

test('finished: the actual end, and nothing counting down', async({page}) => {
    const box = await open(page, 'finished');
    await expect(box).toHaveAttribute('data-reliability', 'final');
    await expect(value(page, 'finish')).toContainText('Finished');
    await expect(value(page, 'remaining')).toHaveText('Nothing left');
    const finish = await value(page, 'finish').textContent();
    const elapsed = await value(page, 'elapsed').textContent();
    await page.waitForTimeout(4500);
    await expect(value(page, 'finish')).toHaveText(finish);
    await expect(value(page, 'elapsed')).toHaveText(elapsed);
});

test('no data: nothing to estimate, no invented times', async({page}) => {
    await open(page, 'empty');
    // A draft has no snapshot card, or one that says there is nothing to estimate.
    const box = page.locator('[data-region="catquizlab-eta"]');
    if (await box.count()) {
        await expect(box).toHaveAttribute('data-reliability', 'none');
        await expect(value(page, 'finish')).toHaveText('—');
    }
    await expect(page.locator('body')).not.toContainText('≈');
});

test('small screen: the four values stay readable without horizontal scrolling', async({page}) => {
    await page.setViewportSize({width: 390, height: 844});
    await open(page, 'running');
    for (const name of ['started', 'elapsed', 'finish', 'remaining']) {
        await expect(value(page, name)).toBeVisible();
    }
    const overflow = await page.evaluate(() => document.querySelector('[data-region="catquizlab-eta"]').scrollWidth
        - document.querySelector('[data-region="catquizlab-eta"]').clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});

test('workers: configured and in force named, technical details folded (#124)', async({page}) => {
    await open(page, 'running');
    const line = page.locator('[data-region="catquizlab-capacity"]');
    await expect(line).toContainText('configured');
    await expect(line).toContainText('in force');
    await expect(line).toContainText('draining');
    const details = page.locator('[data-region="catquizlab-worker-details"]');
    if (await details.count()) {
        await expect(details).not.toHaveAttribute('open', '');
    }
});

