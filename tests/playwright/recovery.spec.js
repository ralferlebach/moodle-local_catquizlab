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
 * A failed sitting, diagnosed and retried through the interface (#98).
 *
 * The run comes from cli/seed_failed_attempt.php, whose RECOVERY_RUNID the
 * CI job puts into the environment. Without it the test is skipped rather
 * than failed: it tests recovery, not seeding.
 */

const {test, expect} = require('@playwright/test');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const RUNID = process.env.RECOVERY_RUNID || '';

test('a failed sitting is diagnosed, retried, and its history kept', async({page}) => {
    test.skip(!RUNID, 'RECOVERY_RUNID not set; run cli/seed_failed_attempt.php first.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');

    await page.goto('/local/catquizlab/runs.php?runid=' + RUNID, {waitUntil: 'networkidle'});
    const main = page.locator('#region-main');

    // The diagnosis in its fields, not as one line of text.
    await expect(main).toContainText('DivisionByZeroError');
    await expect(main).toContainText('model_raschmodel.php:734');
    await expect(main).toContainText(/answering/);
    await expect(main).toContainText('catquizlab-exec-7');
    // A stored URL without its session key.
    await expect(main).not.toContainText('sesskey=abc123');

    // Retry every incomplete sitting.
    await main.getByRole('button', {name: /incomplete test sitting|unvollständigen Testbearbeitungen/}).click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText(/1 failed|1 fehlgeschlagene/);

    // The failure is still there, and so is the retry that followed it.
    await expect(main).toContainText('DivisionByZeroError');
    await expect(main).toContainText(/put back in the queue|wieder eingereiht/);

    // And the log link of that report opens the log filtered to it.
    const loglink = main.getByRole('link', {name: /log of this report|Protokoll zu dieser Meldung/}).first();
    await expect(loglink).toHaveAttribute('href', /correlationid=/);
});
