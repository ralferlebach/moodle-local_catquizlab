// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Two attempts left open in one session across an upgrade of the engine (#136 AK 7: attempt_cache::upgrade_session_layout
 * purges the session once on the first upgrade — the second open attempt must come through it whole).
 *
 * Two phases, with the engine upgraded between them by the workflow (acceptance.yml, job engine-migration):
 * - MIGRATION_PHASE=open (release engine): learner 1 starts adaptive quiz A and B in one browser session and answers two
 *   questions in each; the session (its cookies) and what the server stored are kept.
 * - MIGRATION_PHASE=continue (candidate engine, same database, same moodledata, same session): both attempts are
 *   continued — "Continue", never "Start a new attempt" — and played to the end.
 * Checked after the upgrade: the same attempts and question usages, nothing asked before lost or replaced, every answer
 * from before still stored, no question asked twice, every question from its activity's pool, both finished with the
 * activity's number of questions and a stop criterion; the session was still signed in (no new login).
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const fs = require('fs');
const pathlib = require('path');
const aq = require('./support/adaptivequiz');
const {needs} = require('./support/needs');

// Not under artifacts/: Playwright empties its output folder at the start of every run, and the second phase needs what
// the first one kept.
const OUT = process.env.MIGRATION_DIR || pathlib.join(__dirname, 'engine-migration');
const STATE = pathlib.join(OUT, 'session-state.json');
const BEFORE = pathlib.join(OUT, 'before.json');
const PHASE = process.env.MIGRATION_PHASE || '';

test.setTimeout(20 * 60 * 1000);

test('two attempts left open in one session survive the engine upgrade', async({browser}) => {
    needs(!process.env.ENGINE_SESSION, 'ENGINE_SESSION: the file cli/engine_session_seed.php wrote.');
    needs(!['open', 'continue'].includes(PHASE), 'MIGRATION_PHASE=open before the engine upgrade, continue after it.');
    const s = aq.session();
    const one = s.learners[0];
    const cm = {A: s.activities.A.cmid, B: s.activities.B.cmid};
    const items = s.activities.A.items;

    if (PHASE === 'open') {
        const context = await browser.newContext();
        const page = await context.newPage();
        await aq.login(page, one);
        const given = {A: 0, B: 0};
        // One tab, the two activities in turns: each turn opens its activity again (continue, never restart).
        for (const key of ['A', 'B', 'A', 'B']) {
            expect(await aq.openQuestion(page, cm[key]), `${key} shows a question`).toBe('question');
            await aq.answer(page);
            given[key]++;
            aq.unharmed(aq.attempt(one.id, key), null, given[key], `before the upgrade, ${key}`);
        }
        const before = aq.report(one.id);
        for (const key of ['A', 'B']) {
            expect(String(before.activities[key].attempts[0].attemptstate), `${key} is open`).not.toMatch(/complete/);
        }
        fs.mkdirSync(OUT, {recursive: true});
        await context.storageState({path: STATE});
        aq.keep(OUT, 'before.json', before);
        await context.close();
        return;
    }

    // After the upgrade: the same browser session.
    needs(!fs.existsSync(STATE) || !fs.existsSync(BEFORE), 'The phase "open" ran before the upgrade and kept its session.');
    const before = JSON.parse(fs.readFileSync(BEFORE, 'utf8'));
    const context = await browser.newContext({storageState: STATE});
    const page = await context.newPage();
    const given = {A: before.activities.A.attempts[0].answered, B: before.activities.B.attempts[0].answered};
    const last = {A: before.activities.A.attempts[0], B: before.activities.B.attempts[0]};
    const done = {A: false, B: false};
    for (let round = 0; round < 2 * items && !(done.A && done.B); round++) {
        for (const key of ['B', 'A']) {
            if (done[key]) {
                continue;
            }
            await page.goto('/mod/adaptivequiz/view.php?id=' + cm[key], {waitUntil: 'domcontentloaded'});
            expect(page.url(), 'still signed in after the upgrade').not.toContain('/login/index.php');
            expect(await aq.openQuestion(page, cm[key]), `${key} continues with a question`).toBe('question');
            await aq.answer(page);
            given[key]++;
            const now = aq.attempt(one.id, key);
            aq.unharmed(now, last[key], given[key], `after the upgrade, ${key}`);
            last[key] = now;
            done[key] = /complete|finished/.test(String(now.attemptstate));
        }
    }
    const after = aq.report(one.id);
    for (const key of ['A', 'B']) {
        const end = after.activities[key].attempts[0];
        expect(after.activities[key].attempts.length, `${key}: still one attempt`).toBe(1);
        expect(end.id, `${key}: the attempt from before the upgrade`).toBe(before.activities[key].attempts[0].id);
        expect(String(end.attemptstate), `${key}: finished`).toMatch(/complete|finished/);
        expect(end.answered, `${key}: as many questions as the activity asks`).toBe(items);
        expect(String(end.attemptstopcriteria || end.stopcode || ''), `${key}: a stop criterion is stored`).not.toBe('');
    }
    aq.keep(OUT, 'after.json', after);
    await context.close();
});
