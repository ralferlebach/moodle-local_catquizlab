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
 * Varied factors as levels: folded when unused, rows instead of textareas, every bad level refused (#126).
 */

const {test, expect} = require('@playwright/test');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

const login = async(page) => {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
};

test('no empty textareas in the normal view; levels as rows; the single value hidden when varied', async({page}) => {
    await login(page);
    await page.goto('/local/catquizlab/experiment.php', {waitUntil: 'networkidle'});

    // Folded, and no bare textarea anywhere in it.
    const section = page.locator('#id_sweepmore');
    await expect(section.locator('textarea:visible')).toHaveCount(0);
    await expect(page.locator('[data-catquizlab-levels-state="sweepglobalbudgets"]')).toBeHidden();
    await page.locator('#id_sweepmore .ftoggler a, #id_sweepmore legend a').first().click();
    await expect(page.locator('[data-catquizlab-levels-state="sweepglobalbudgets"]')).toContainText('Not varied');
    await expect(section.locator('textarea:visible')).toHaveCount(0);

    // Two levels of the global budget, by keyboard.
    const editor = page.locator('[data-region="catquizlab-levels-sweepglobalbudgets"]');
    await editor.getByRole('button', {name: 'Add level'}).click();
    await page.keyboard.type('10');
    await page.keyboard.press('Tab');
    await page.keyboard.type('15');
    await editor.getByRole('button', {name: 'Add level'}).click();
    await page.keyboard.type('30');
    await page.keyboard.press('Tab');
    await page.keyboard.type('20');
    // Said at the row, in words, before anything is sent.
    await expect(editor.locator('li[data-level="2"] [role="status"]')).toHaveText('The minimum is above the maximum.');
    await expect(editor.locator('li[data-level="2"] input').first()).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#id_sweepglobalbudgets')).toHaveValue('10/15\n30/20');
    await expect(page.locator('[data-catquizlab-levels-state="sweepglobalbudgets"]')).toContainText('Varied: 2 level(s)');
    // The single budget above no longer applies: hidden.
    await expect(page.locator('#id_globalmin')).toBeHidden();

    // Removed again: not varied, the single value back.
    await editor.getByRole('button', {name: 'Remove level 2'}).click();
    await editor.getByRole('button', {name: 'Remove level 1'}).click();
    await expect(page.locator('#id_sweepglobalbudgets')).toHaveValue('');
    await expect(page.locator('#id_globalmin')).toBeVisible();
});

test('a bad level is refused on saving, with its line; good levels come back as rows after saving', async({page}) => {
    await login(page);
    await page.goto('/local/catquizlab/experiment.php', {waitUntil: 'networkidle'});
    const name = 'Levels ' + Date.now();
    await page.fill('#id_name', name);
    await page.locator('#id_sweepmore .ftoggler a, #id_sweepmore legend a').first().click();

    // A strength with an unknown key, written as it would be typed into the plain field.
    const strengths = page.locator('[data-region="catquizlab-levels-sweepstrengths"]');
    await strengths.getByRole('button', {name: 'Add level'}).click();
    await strengths.locator('select').first().selectOption('shift');
    await strengths.locator('input').first().fill('1.0');
    const budgets = page.locator('[data-region="catquizlab-levels-sweepglobalbudgets"]');
    await budgets.getByRole('button', {name: 'Add level'}).click();
    await budgets.locator('input').nth(0).fill('10');
    await budgets.locator('input').nth(1).fill('15');
    await budgets.getByRole('button', {name: 'Add level'}).click();
    await budgets.locator('input').nth(2).fill('20');
    await budgets.locator('input').nth(3).fill('30');
    // A level the editor cannot express goes through the field itself and is refused by the server.
    await page.evaluate(() => {
        const area = document.getElementById('id_sweepse');
        area.value = '0.3/0.5\n0.6-0.9';
    });
    await page.click('#id_submitbutton');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#id_error_sweepse, #fitem_id_sweepse .invalid-feedback').first())
        .toContainText(/Line 2|min\/max/);

    // Fixed: saved; reopened, the levels are rows again.
    await page.evaluate(() => {
        document.getElementById('id_sweepse').value = '0.3/0.5';
    });
    await page.click('#id_submitbutton');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText(/saved|gespeichert/i);
    const reopened = page.locator('[data-region="catquizlab-levels-sweepglobalbudgets"] li');
    await expect(reopened).toHaveCount(2);
    await expect(reopened.nth(1).locator('input').first()).toHaveValue('20');
    await expect(page.locator('[data-catquizlab-levels-state="sweepstrengths"]')).toContainText('Varied: 1 level(s)');
});
