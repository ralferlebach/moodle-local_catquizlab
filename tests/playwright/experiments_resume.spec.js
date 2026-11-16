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
 * A stopped experiment, resumed or reset from its progress page (#165 DoD-02, DoD-03; #166).
 *
 * The experiments come from cli/e2e_prepare.php (a run prepared in the real engine), stopped with
 * cli/stop_experiment.php: RESUME_EXPERIMENTID and RESTART_EXPERIMENTID, put into the environment by the CI job.
 * The first is resumed through the interface and then played to its end by the installation's own workers and cron,
 * nobody clicking a run; the second is reset the destructive way, which only goes through after its inventory and a
 * ticked confirmation. Without them the tests are skipped: they test resuming, not seeding.
 *
 * Order and cleanup. Playwright runs the files one after another, sorted as text: experiment_*.spec.js comes before
 * experiment.spec.js, experiments_*.spec.js after it. This one must come after it: experiment.spec.js sets the
 * installation up for its workers through the interface, and before that nothing can be played. Specs further down
 * the alphabet seed experiments that wait in the execution line without anything to play. And however a test here
 * ends, what it set going leaves the line again (afterEach): a resumed experiment nobody plays held the line in front
 * of experiment.spec.js's own on GitHub (2026-11-16), and the whole interface run failed behind it.
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const RESUME = process.env.RESUME_EXPERIMENTID || '';
const RESTART = process.env.RESTART_EXPERIMENTID || '';
// How long the workers may take to finish the resumed experiment; 0 where no worker plays (checked up to the line).
const MINUTES = Number(process.env.RESUME_WAIT_MINUTES ?? 20);

const login = async(page) => {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
};

const progress = async(page, experimentid) => {
    await page.goto('/local/catquizlab/runs.php?experimentid=' + experimentid, {waitUntil: 'networkidle'});
};

/** @type {string[]} The experiments a test here set going (resumed, reset and started), to be stopped if unfinished. */
const going = [];

// Stopped through the interface if still under way: nothing a test here set going may hold the line for the next.
test.afterEach(async({page}) => {
    while (going.length) {
        const id = going.pop();
        await progress(page, id);
        const stop = page.getByRole('button', {name: /^(Stop experiment|Experiment stoppen)$/});
        if (await stop.count() > 0) {
            await stop.first().click();
            await page.waitForLoadState('networkidle');
            await page.getByRole('button', {name: /^(Continue|Weiter)$/}).click();
            await page.waitForLoadState('networkidle');
        }
    }
});

// The experiment's sitting counts as its progress page shows them.
const counts = async(page) => {
    const read = async(region) => {
        const text = await page.locator(`[data-region="catquizlab-${region}"]`).first().innerText();
        return Number(text.replace(/[^0-9]/g, '') || 0);
    };
    return {
        queued: await read('queued'),
        running: await read('running'),
        collected: await read('collected'),
        failed: await read('failed'),
        cancelled: await read('cancelled'),
    };
};

test('#165 a stopped experiment is resumed from its progress page and finished by the workers, keeping what it had',
    async({page}) => {
    needs(!RESUME, 'RESUME_EXPERIMENTID not set; prepare a run with cli/e2e_prepare.php and stop its experiment.');
    test.setTimeout((MINUTES + 5) * 60 * 1000);
    await login(page);
    await progress(page, RESUME);

    // Stopped: what the stop closed is "cancelled", not "failed" (#166).
    const before = await counts(page);
    expect(before.cancelled).toBeGreaterThan(0);
    expect(before.failed).toBe(0);
    expect(before.running).toBe(0);
    const planned = before.collected + before.cancelled + before.queued;

    // The two ways, apart: resuming first, the destructive one marked as such.
    await expect(page.getByRole('button', {name: /^(Reset stopped runs and run them again \(deletes\)|Gestoppte Runs zurücksetzen und neu ausführen \(löscht\))$/}))
        .toBeVisible();
    // Offered twice, the same action: on the runner card and as the status bar's next step.
    const resume = page.locator('button[data-action="catquizlab-resumeexperiment"]');
    const next = page.locator('form[data-region="catquizlab-status-next"] button');
    await expect(resume).toHaveAccessibleName(/^(Resume experiment|Experiment fortsetzen)$/);
    await expect(next).toHaveAccessibleName(/^(Resume experiment|Experiment fortsetzen)$/);
    // By keyboard (AK-10).
    await resume.focus();
    await expect(resume).toBeFocused();
    await page.keyboard.press('Enter');
    await page.waitForLoadState('networkidle');

    // The preview, run by run, before anything happens (AK-02): a table a screen reader reads cell by cell.
    const table = page.locator('[data-region="catquizlab-resume-preview"]');
    await expect(table.locator('caption')).toHaveText(/What resuming does, run by run|Was das Fortsetzen tut, Run für Run/);
    const rows = table.locator('tbody tr');
    await expect(rows.first()).toBeVisible();
    await expect(rows.first().locator('th[scope="row"]')).toBeVisible();
    await expect(table.locator('tbody tr[data-action="resume"]').first()).toBeVisible();
    await expect(page.locator('#region-main')).toContainText(/Kept as they are|Unverändert bleiben/);
    // Nothing done yet.
    await progress(page, RESUME);
    expect((await counts(page)).cancelled).toBe(before.cancelled);
    await next.click();
    await page.waitForLoadState('networkidle');

    // At 320 px the page does not scroll sideways; the table scrolls in its own box.
    await page.setViewportSize({width: 320, height: 800});
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    await page.setViewportSize({width: 1440, height: 900});

    // Confirmed: resumed, said so.
    going.push(RESUME);
    await page.getByRole('button', {name: /^(Continue|Weiter)$/}).click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText(/Resumed: \d+ run\(s\)|Fortgesetzt: \d+ Run\(s\)/);

    // Nothing collected is lost, what the stop closed is queued again, nothing is failed or cancelled.
    await progress(page, RESUME);
    const resumed = await counts(page);
    expect(resumed.collected).toBeGreaterThanOrEqual(before.collected);
    expect(resumed.failed).toBe(0);
    expect(resumed.cancelled).toBe(0);
    expect(resumed.collected + resumed.queued + resumed.running).toBeLessThanOrEqual(planned);
    // Not stopped any more: no second resume offered.
    await expect(page.getByRole('button', {name: /^(Resume experiment|Experiment fortsetzen)$/})).toHaveCount(0);
    if (MINUTES <= 0) {
        // Where no worker plays (a development site whose line is taken): the experiment waits in the line.
        await expect(page.locator('#region-main')).toContainText(/execution queue|Ausführungswarteschlange|Waiting|Wartet/);
        return;
    }

    // From here on nobody: the workers and cron play what the stop closed. The figures only grow (AK-09), nothing
    // fails, nothing stays cancelled, and in the end every planned sitting is collected once.
    let last = before.collected;
    const deadline = Date.now() + MINUTES * 60 * 1000;
    let now = await counts(page);
    while (Date.now() < deadline) {
        await progress(page, RESUME);
        now = await counts(page);
        expect(now.collected).toBeGreaterThanOrEqual(last);
        expect(now.failed).toBe(0);
        last = now.collected;
        if (now.collected >= planned && now.running === 0 && now.queued === 0) {
            break;
        }
        await page.waitForTimeout(15 * 1000);
    }
    expect(now).toEqual({queued: 0, running: 0, collected: planned, failed: 0, cancelled: 0});
});

test('#165 the destructive way only after its inventory and a ticked confirmation', async({page}) => {
    needs(!RESTART, 'RESTART_EXPERIMENTID not set; prepare a run with cli/e2e_prepare.php and stop its experiment.');
    await login(page);
    await progress(page, RESTART);
    const before = await counts(page);
    expect(before.cancelled).toBeGreaterThan(0);

    // The library offers resuming too, in the experiment's "More" menu, and leads to the same preview.
    const name = (await page.locator('#catquizlab-status-name').innerText()).trim();
    await page.goto('/local/catquizlab/index.php?tab=experiments&q=' + encodeURIComponent(name), {waitUntil: 'networkidle'});
    const row = page.locator(`tr[data-experiment="${RESTART}"]`);
    await expect(row).toHaveAttribute('data-phase', 'stopped');
    await row.locator('[data-region="catquizlab-library-more"] > summary').click();
    await row.getByRole('button', {name: /^(Resume experiment|Experiment fortsetzen)$/}).click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-region="catquizlab-resume-preview"] caption'))
        .toHaveText(/What resuming does, run by run|Was das Fortsetzen tut, Run für Run/);
    // Not confirmed: nothing changed.
    await progress(page, RESTART);
    expect((await counts(page)).cancelled).toBe(before.cancelled);

    await page.getByRole('button', {name: /^(Reset stopped runs and run them again \(deletes\)|Gestoppte Runs zurücksetzen und neu ausführen \(löscht\))$/})
        .click();
    await page.waitForLoadState('networkidle');

    // What is deleted, said as a warning and listed run by run (AK-06).
    await expect(page.getByRole('alert').filter({hasText: /deletes for good|endgültig/})).toBeVisible();
    const table = page.locator('[data-region="catquizlab-restart-inventory"]');
    await expect(table.locator('caption')).toHaveText(/What is deleted, run by run|Was gelöscht wird, Run für Run/);
    await expect(table.locator('tbody th[scope="row"]').first()).toBeVisible();

    // Not without the confirmation: the box is required, the form stays.
    const submit = page.getByRole('button', {name: /^(Delete and run again|Löschen und neu ausführen)$/});
    const understood = page.getByLabel(/I understand that what is listed above is deleted for good|Mir ist klar/);
    await submit.click();
    await expect(page.locator('form[data-region="catquizlab-restartstopped"]')).toBeVisible();
    expect(await understood.evaluate((box) => box.validity.valueMissing)).toBe(true);

    // Ticked by keyboard, then done, with a message per run.
    await understood.focus();
    await page.keyboard.press('Space');
    await expect(understood).toBeChecked();
    going.push(RESTART);
    await submit.click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText(
        /\d+ of \d+ stopped run\(s\) reset and started again|\d+ von \d+ gestoppten Run\(s\) zurückgesetzt und neu gestartet/);

    // Nothing of the stop is left: its sittings are gone with the reset, the runs are prepared again. The experiment,
    // started again, is stopped once more after the test (afterEach), so that the specs after this one find the
    // installation as it was.
    await progress(page, RESTART);
    expect((await counts(page)).cancelled).toBe(0);
});
