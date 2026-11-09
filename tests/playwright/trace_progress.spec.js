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
 * A vanished trace is never "collected" (#138); a 625-question sitting moves the page question by question
 * (#141, #142).
 *
 * Self-seeding through cli/seed_vanished_trace.php and cli/seed_question_progress.php, which report a
 * completion through the worker's own job_complete and move an engine attempt on the way the engine does.
 * Where php cannot be run here, the tests are skipped rather than failed.
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
 * Run a seeding script, and read NAME=value lines from what it prints.
 *
 * @param {string} script The script in cli/.
 * @param {string[]} args Its arguments.
 * @returns {Object<string, string>} The values, or {} where php cannot be run.
 */
const seed = (script, args = []) => {
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/' + script), ...args], {encoding: 'utf8'});
        return Object.fromEntries([...out.matchAll(/^([A-Z_]+)=(\S+)$/gm)].map((m) => [m[1], m[2]]));
    } catch (e) {
        return {};
    }
};

test('a sitting whose trace vanished waits for it, is listed, and is never counted as collected', async({page}) => {
    const seeded = seed('seed_vanished_trace.php');
    needs(!seeded.TRACE_RUNID, 'cli/seed_vanished_trace.php could not be run here.');
    await login(page);

    await page.goto(`/local/catquizlab/runs.php?runid=${seeded.TRACE_RUNID}&show=notrace`, {waitUntil: 'networkidle'});
    const main = page.locator('#region-main');
    await expect(main).toContainText(/finished, trace missing|beendet, Trace fehlt/);
    await expect(main).toContainText(/trace not \(yet\) collected|Trace (noch )?nicht eingesammelt/);
    // Not collected: the run has no collected sitting.
    await expect(main).not.toContainText(/1 collected|1 eingesammelt/);
});

test('without its trace after every look, the sitting is given up as a technical non-result', async({page}) => {
    const seeded = seed('seed_vanished_trace.php', ['--giveup']);
    needs(!seeded.TRACE_RUNID, 'cli/seed_vanished_trace.php could not be run here.');
    await login(page);

    await page.goto(`/local/catquizlab/runs.php?runid=${seeded.TRACE_RUNID}&show=failed`, {waitUntil: 'networkidle'});
    const main = page.locator('#region-main');
    await expect(main).toContainText(/technical non-result|technisches Nicht-Ergebnis/);
    await expect(main).toContainText(/given up|aufgegeben/);
});

test('a 625-question sitting moves the question bar and the forecast while it is played', async({page}) => {
    const seeded = seed('seed_question_progress.php', ['--answered=100']);
    needs(!seeded.QPROGRESS_EXPERIMENTID, 'cli/seed_question_progress.php could not be run here.');
    await login(page);

    await page.goto('/local/catquizlab/runs.php?experimentid=' + seeded.QPROGRESS_EXPERIMENTID, {waitUntil: 'networkidle'});
    const box = page.locator('[data-region="catquizlab-questions"]');
    await expect(box).toHaveAttribute('data-kind', 'exact');
    await expect(page.locator('[data-region="catquizlab-questions-text"]')).toHaveText(/^100 (of|von) 625 \(16 %\)$/);
    const bar = page.locator('[data-region="catquizlab-questions-bar"]');
    await expect(bar).toHaveAttribute('aria-valuenow', '16');
    // The sittings bar has not moved: no sitting is collected yet. That is why there is a second bar.
    await expect(page.locator('[data-region="catquizlab-progress-count"]')).toHaveText('0 / 1');
    // A forecast from the pace of the sitting being played, not "calculating" for the next hour.
    await expect(page.locator('[data-region="catquizlab-eta"]')).toHaveAttribute('data-reliability', 'provisional');
    await expect(page.locator('[data-region="catquizlab-eta-method"]')).toContainText(/per question|je Frage/);

    // The engine confirms more answers: the page follows with its next poll, without a reload.
    seed('seed_question_progress.php', ['--engine=' + seeded.QPROGRESS_ENGINEATTEMPTID, '--answered=624']);
    await expect(page.locator('[data-region="catquizlab-questions-text"]')).toHaveText(/^624 (of|von) 625 \(99 %\)$/,
        {timeout: 20000});
    await expect(bar).toHaveAttribute('aria-valuenow', '99');
});
