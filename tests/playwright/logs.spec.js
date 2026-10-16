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
 * The logs page against a day of request traces the size that broke it (#132).
 *
 * Data from cli/seed_request_trace.php (250 000 lines, LOGS_ERRORS server
 * errors among them). Open, filter, page back, follow a deep link, tail live,
 * download — each without a PHP fatal.
 */

const fs = require('fs');
const {test, expect} = require('@playwright/test');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const ERRORS = parseInt(process.env.LOGS_ERRORS || '0', 10);

test('large request traces: open, filter, page, deep link, tail and download', async({page}) => {
    test.skip(!ERRORS, 'LOGS_ERRORS not set; run cli/seed_request_trace.php first.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');

    // Open: the page renders, the newest page of lines, and what it read.
    const response = await page.goto('/local/catquizlab/logs.php?hours=24', {waitUntil: 'networkidle'});
    expect(response.status(), 'no fatal, no 500').toBe(200);
    const log = page.locator('[data-region="catquizlab-log"]');
    await expect(log).toBeVisible();
    await expect(page.locator('[data-region="catquizlab-log-scan"]')).toContainText(/\d+/);
    expect(await page.locator('.catquizlab-logline').count()).toBe(500);

    // Page back: older lines, and the way forward again.
    await page.click('[data-action="catquizlab-log-older"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-action="catquizlab-log-newer"]')).toBeVisible();
    expect(await page.locator('.catquizlab-logline').count()).toBe(500);

    // Filter: requests, errors only — every server error of the day, found behind the many successes.
    await page.goto('/local/catquizlab/logs.php?hours=24', {waitUntil: 'networkidle'});
    await page.selectOption('#catquizlab-channel', 'request');
    await page.selectOption('#catquizlab-severity', 'error');
    await page.click('form:has(#catquizlab-channel) input[type="submit"]');
    await page.waitForLoadState('networkidle');
    const errors = page.locator('.catquizlab-logline');
    await expect(errors).toHaveCount(ERRORS);
    for (const text of await errors.allTextContents()) {
        expect(text).toContain('→ 500');
    }

    // Deep link: one request, by its text.
    await page.goto('/local/catquizlab/logs.php?hours=24&channel=request&search=' + encodeURIComponent('seed=997 →'),
        {waitUntil: 'networkidle'});
    await expect(page.locator('.catquizlab-logline')).toHaveCount(1);
    await expect(page.locator('.catquizlab-logline').first()).toContainText('seed=997 → 500');

    // Tail: switched on, it polls and reports; nothing newer than the last line shown.
    await page.goto('/local/catquizlab/logs.php?hours=24&channel=request', {waitUntil: 'networkidle'});
    const last = await page.locator('.catquizlab-logline').last().getAttribute('data-ms');
    const [poll] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('tail=1'), {timeout: 30 * 1000}),
        page.check('[data-action="catquizlab-log-live"]'),
    ]);
    expect(poll.status()).toBe(200);
    const body = await poll.json();
    expect(body.lines.every((l) => l.ms > parseInt(last, 10))).toBe(true);
    await expect(page.locator('[data-region="catquizlab-live-status"]')).not.toHaveText('');
    await page.uncheck('[data-action="catquizlab-log-live"]');

    // Download: the file says what it holds and that it is cut.
    const [file] = await Promise.all([
        page.waitForEvent('download'),
        page.click('form:has(input[name="download"]) button[type="submit"]'),
    ]);
    const text = fs.readFileSync(await file.path(), 'utf8');
    expect(text).toContain('# truncated: true');
    expect(text).toContain('# limit: 5000');
    expect(text).toMatch(/# filter: \{[^\n]*"channel":"request"/);
});
