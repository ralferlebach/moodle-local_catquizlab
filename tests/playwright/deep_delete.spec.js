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
 * The experiment this run played, deleted deeply through the interface, the engine checked before and after (#161 DoD2).
 *
 * Runs on its own, after every other spec, so that nothing which still reads the experiment comes after it:
 *
 *     CATQUIZLAB_DEEP_DELETE=<experiment id, or the start of its name> npx playwright test
 *
 * (playwright.config.js runs only this spec then, into artifacts-deep-delete/ and report-deep-delete/). A name is
 * looked up in the library, the most recently changed match: the interface E2E passes "UI smoke", the experiment
 * experiment.spec.js created and played through the interface. Before: every
 * run's footprint in the engine, its activity and Moodle, the rows of others sharing an attempt number, and the
 * engine's rows without an owner (cli/deep_delete_check.php --phase=before). Then the confirmation as a person gives
 * it: the preview, the name typed, the button. Cron, running beside the job, carries out the queued task with
 * Moodle's real module deletion (course_delete_module(), from 5.2 the format's action) and the activity's
 * callbacks; the test waits for the deletion record to say so, it does not sleep. After: the experiment gone, nothing of any footprint left, nothing of others lost, no kind of ownerless
 * engine row grown, the record complete (--phase=after, cli/deletion_report.php --expect-deleted).
 */

const {test, expect} = require('@playwright/test');
const {execFileSync, spawnSync} = require('child_process');
const fs = require('fs');
const path = require('path');
const {needs} = require('./support/needs');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const WHICH = (process.env.CATQUIZLAB_DEEP_DELETE || '').trim();
const OUT = process.env.CATQUIZLAB_DEEP_DELETE_OUT || path.resolve(__dirname, 'artifacts-deep-delete');
const CLI = path.resolve(__dirname, '../../cli');

const php = (script, args) => spawnSync('php', [path.join(CLI, script), ...args], {encoding: 'utf8'});

test('the played experiment, deleted deeply through the interface: nothing of it left, nothing of others touched',
    async({page}) => {
        needs(WHICH === '', 'CATQUIZLAB_DEEP_DELETE=<id or name of an experiment that was played>');
        await page.goto('/login/index.php', {waitUntil: 'networkidle'});
        await page.fill('#username', ADMIN);
        await page.fill('#password', PASSWORD);
        await page.click('#loginbtn');
        await page.waitForLoadState('networkidle');

        let experimentid = /^\d+$/.test(WHICH) ? parseInt(WHICH, 10) : 0;
        if (!experimentid) {
            await page.goto('/local/catquizlab/index.php?tab=experiments&sort=modified&q=' + encodeURIComponent(WHICH),
                {waitUntil: 'networkidle'});
            const first = page.locator('[data-region="catquizlab-library"] tbody tr[data-experiment]').first();
            await expect(first, 'an experiment named "' + WHICH + '…" in the library').toBeVisible();
            experimentid = parseInt(await first.getAttribute('data-experiment'), 10);
        }
        expect(experimentid).toBeGreaterThan(0);

        fs.mkdirSync(OUT, {recursive: true});
        const before = path.join(OUT, 'deep-delete-ui-before.json');
        fs.writeFileSync(before, execFileSync('php', [path.join(CLI, 'deep_delete_check.php'),
            '--experimentid=' + experimentid, '--phase=before'], {encoding: 'utf8'}));
        const footprint = JSON.parse(fs.readFileSync(before, 'utf8'));
        const owned = Object.values(footprint.runs).reduce((sum, run) => sum
            + Object.values(run.owned).reduce((n, ids) => n + ids.length, 0), 0);
        const activities = Object.values(footprint.runs).reduce((n, run) => n + (run.owned.course_modules || []).length, 0);
        expect(activities, 'a played experiment has its activity').toBeGreaterThan(0);
        expect(owned, 'and rows of its own in the engine').toBeGreaterThan(activities);

        // The preview, deep by default: what goes, and the name to type.
        await page.goto('/local/catquizlab/experiment.php?id=' + experimentid + '&action=delete', {waitUntil: 'networkidle'});
        const warning = page.locator('.alert-warning').filter({has: page.locator('strong')}).first();
        await expect(warning).toBeVisible();
        const name = (await warning.locator('strong').first().textContent()).trim();
        expect(name.length).toBeGreaterThan(0);
        await expect(page.locator('input[name="deep"]')).toHaveValue('1');
        await page.fill('#catquizlab-confirmname', name);
        await Promise.all([
            page.waitForURL(/\/local\/catquizlab\/index\.php/),
            page.locator('input[type="submit"].btn-danger').click(),
        ]);

        // Cron carries the task out; the record says when. Polled, not slept.
        const state = () => {
            const result = php('deletion_report.php', ['--experimentid=' + experimentid, '--json']);
            try {
                return JSON.parse(result.stdout).state;
            } catch (e) {
                return 'unreadable: ' + result.stdout + result.stderr;
            }
        };
        await expect.poll(state, {message: 'the background task deletes the experiment', timeout: 10 * 60 * 1000,
            intervals: [1000, 2000, 5000]}).toBe('deleted');

        const check = php('deep_delete_check.php', ['--experimentid=' + experimentid, '--phase=after', '--before=' + before]);
        const report = php('deletion_report.php', ['--experimentid=' + experimentid, '--expect-deleted']);
        fs.writeFileSync(path.join(OUT, 'deep-delete-ui-check.txt'), check.stdout + check.stderr + '\n'
            + report.stdout + report.stderr);
        expect(check.status, check.stdout + check.stderr).toBe(0);
        expect(check.stdout).toContain('PASS: deleted completely, nothing of others touched');
        expect(report.status, report.stdout + report.stderr).toBe(0);
    });
