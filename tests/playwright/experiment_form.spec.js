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
 * The experiment form follows what is chosen, as it is chosen — in a browser.
 *
 * The rules had PHPUnit tests and still failed in the browser: the script set
 * the classical test's question fields enabled again right after PHP disabled
 * them, nothing was ever hidden, a strategy chosen above counted although the
 * sweep replaced it, and the check of a budget against the pool read the wrong
 * fields. Only a browser shows what the form does.
 */

const {test, expect} = require('@playwright/test');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

/**
 * Whether a form row is hidden by the form's own logic.
 *
 * @param {object} page The page.
 * @param {string} id The row's element id.
 * @returns {Promise<boolean>}
 */
async function hidden(page, id) {
    return page.evaluate((elid) => {
        const el = document.getElementById(elid);
        const row = el ? (el.closest('.fitem') || el) : null;
        return !row || row.classList.contains('catquizlab-hidden');
    }, id);
}

test('the form hides and disables what does not apply, and checks budgets against the pool', async({page}) => {
    test.skip(!PASSWORD, 'MOODLE_ADMIN_PASSWORD not set.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
    await page.goto('/local/catquizlab/experiment.php', {waitUntil: 'networkidle'});

    // The classical test alone: no question budget, no subscales — hidden and disabled.
    await page.selectOption('#id_strategy', 'classic');
    expect(await hidden(page, 'id_globalmin')).toBe(true);
    expect(await hidden(page, 'id_globalmax')).toBe(true);
    expect(await hidden(page, 'id_subscalemin')).toBe(true);
    expect(await hidden(page, 'id_subscalemax')).toBe(true);
    await expect(page.locator('#id_globalmin')).toBeDisabled();
    await expect(page.locator('#id_subscalemax')).toBeDisabled();
    await expect(page.locator('[data-catquizlab-na="globalmax"]')).toBeVisible();
    // Its own row has no question budget either; another strategy's row is not shown.
    await expect(page.locator('#id_perstrategy_classic_globalmin')).toBeDisabled();
    expect(await hidden(page, 'fgroup_id_perstrategygroup_classic')).toBe(false);
    expect(await hidden(page, 'fgroup_id_perstrategygroup_allsubs')).toBe(true);

    // An adaptive strategy: the budgets are back, enabled.
    await page.selectOption('#id_strategy', 'relsubs');
    expect(await hidden(page, 'id_globalmax')).toBe(false);
    await expect(page.locator('#id_globalmax')).toBeEnabled();
    await expect(page.locator('#id_subscalemin')).toBeEnabled();

    // The reported form: classic above, four strategies in the sweep, 15–25 and
    // 3–5 over the default pool of 10 × 10 subscales.
    await page.selectOption('#id_strategy', 'classic');
    // Every section open, as somebody filling the whole form has it.
    const expand = page.locator('a.collapseexpand').first();
    if (await expand.count() && !(await expand.getAttribute('class')).includes('collapse-all')) {
        await expand.click();
    }
    await page.selectOption('#id_sweepstrategies', ['highestsub', 'classic', 'relsubs', 'allsubs']);
    await page.fill('#id_poolcategories', '10');
    await page.fill('#id_poolsubcategories', '10');
    await page.fill('#id_globalmin', '15');
    await page.fill('#id_globalmax', '25');
    await page.fill('#id_subscalemin', '3');
    await page.fill('#id_subscalemax', '5');

    // The sweep replaces the choice above, and says so.
    await expect(page.locator('#id_strategy')).toBeDisabled();
    await expect(page.locator('[data-catquizlab-na="strategy"]')).toContainText('Infer all subscales');
    // The classical test's row still has no question budget; allsubs's row is shown.
    await expect(page.locator('#id_perstrategy_classic_globalmax')).toBeDisabled();
    await expect(page.locator('#id_perstrategy_allsubs_globalmax')).toBeEnabled();
    expect(await hidden(page, 'fgroup_id_perstrategygroup_lowestsub')).toBe(true);

    // And the budget allsubs cannot meet is said while it is typed.
    const box = page.locator('[data-catquizlab-feasibility]');
    await expect(box).toBeVisible();
    await expect(box).toContainText('300');

    // A maximum that allows it settles it.
    await page.fill('#id_globalmax', '400');
    await expect(box).toBeHidden();

    // A ceiling per subscale below the minimum is said as well.
    await page.fill('#id_subscalemax', '1');
    await page.fill('#id_poolcategories', '2');
    await page.fill('#id_poolsubcategories', '2');
    await page.fill('#id_subscalemin', '1');
    await expect(box).toBeVisible();
    await expect(box).toContainText('before its minimum');

    // On submit the server refuses what the browser said.
    await page.fill('#id_subscalemax', '5');
    await page.fill('#id_poolcategories', '10');
    await page.fill('#id_poolsubcategories', '10');
    await page.fill('#id_subscalemin', '3');
    await page.fill('#id_globalmax', '25');
    await page.fill('#id_name', 'Form check');
    await page.locator('#id_submitbutton').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#id_error_globalmax')).toContainText('300');
});
