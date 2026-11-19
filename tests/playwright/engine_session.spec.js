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
 * Two CAT attempts in one Moodle session, a second learner beside them, the caches purged and the cache's TTL waited
 * out in the middle (#136 AK 2, AK 3; #137 AK 5; CatQuiz #146/#147).
 *
 * Learner 1, one browser session: starts adaptive quiz A, answers, starts adaptive quiz B in the same session, and goes
 * on answering A and B in turns. Learner 2, a session of their own, answers A in between. Half-way the caches are purged
 * (purge_caches.php); then, with every attempt open, the test waits TTL_WAIT_SECONDS (the acceptance job: 610 s, past
 * the engine's 600-second cache lifetime), shifts the stored difficulty of activity A's items (a recalibration while
 * attempts are open) and goes on. Every attempt is read back from the server after every step:
 * - the same attempt and question usage throughout (never restarted by the other one);
 * - every answer given stored, none more, nothing asked before lost or changed;
 * - no question asked twice in an attempt, every question from that activity's pool (no question of the other one);
 * and at the end every attempt finished with as many questions as the activity asks (minimum = maximum) and a stop
 * criterion, and the engine's own record of it, where it keeps one, saying the same number of items.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const pathlib = require('path');
const aq = require('./support/adaptivequiz');
const {needs} = require('./support/needs');

const OUT = pathlib.join(__dirname, 'artifacts', 'engine-session');
const TTL = Number(process.env.TTL_WAIT_SECONDS || 0);

test.setTimeout((25 * 60 + TTL) * 1000);

test('two attempts in one session, a second learner, purge and TTL: nothing crosses, nothing is lost', async({browser}) => {
    needs(!process.env.ENGINE_SESSION, 'ENGINE_SESSION: the file cli/engine_session_seed.php wrote.');
    const s = aq.session();
    const [one, two] = s.learners;
    const cm = {A: s.activities.A.cmid, B: s.activities.B.cmid};
    const items = s.activities.A.items;

    const ctx1 = await browser.newContext();
    const ctx2 = await browser.newContext();
    const p1A = await ctx1.newPage();
    // The same session (cookies of ctx1), another tab: what a learner with two activities open does.
    const p1B = await ctx1.newPage();
    const p2A = await ctx2.newPage();
    await aq.login(p1A, one);
    await aq.login(p2A, two);

    const given = {'1A': 0, '1B': 0, '2A': 0};
    const last = {'1A': null, '1B': null, '2A': null};
    const state = {'1A': 'question', '1B': 'question', '2A': 'question'};
    const who = {'1A': [one, 'A', p1A], '1B': [one, 'B', p1B], '2A': [two, 'A', p2A]};
    const log = [];

    const check = (label) => {
        for (const key of Object.keys(who)) {
            if (given[key] === 0) {
                continue;
            }
            const [learner, activity] = who[key];
            const now = aq.attempt(learner.id, activity);
            aq.unharmed(now, last[key], given[key], `${label}: learner ${learner.id} in ${activity}`);
            last[key] = now;
        }
        log.push({step: label, given: {...given}, attempts: Object.fromEntries(Object.entries(last)
            .map(([k, v]) => [k, v && {id: v.id, uniqueid: v.uniqueid, questions: v.questions.map((q) => q.questionid),
                state: v.attemptstate}]))});
    };
    const step = async(key) => {
        if (state[key] === 'finished') {
            return;
        }
        const [learner, activity, page] = who[key];
        if (!(await page.locator('.que').count())) {
            if ((await aq.openQuestion(page, cm[activity])) !== 'question') {
                state[key] = 'finished';
                return;
            }
        }
        await aq.answer(page);
        given[key]++;
        // Whether the attempt goes on is the server's to say.
        const now = aq.attempt(learner.id, activity);
        state[key] = /complete|finished/.test(String(now.attemptstate)) ? 'finished' : 'question';
        check(`${key} answered ${given[key]}`);
    };

    // A started, then B started in the same session; learner 2 in A beside them.
    await step('1A');
    await step('1B');
    await step('2A');
    await step('1A');
    await step('1B');
    await step('2A');
    // The caches purged with every attempt open.
    aq.purgeCaches();
    check('after purge_caches');
    await step('1A');
    await step('2A');
    await step('1B');
    if (TTL > 0) {
        // Past the engine's cache lifetime with every attempt open.
        await p1A.waitForTimeout(TTL * 1000);
        check(`after ${TTL} s with every attempt open`);
    }
    // The items' parameters changed with every attempt open: the attempts go on, nothing crosses.
    expect(aq.changeItems(), 'item parameters of A changed').toBeGreaterThan(0);
    check('after the item parameters of A changed');
    for (let round = 0; round < items + 2 && Object.values(state).some((v) => v !== 'finished'); round++) {
        await step('1B');
        await step('1A');
        await step('2A');
    }

    for (const key of Object.keys(who)) {
        const [learner, activity] = who[key];
        const end = aq.attempt(learner.id, activity);
        expect(state[key], `${key} reached the end`).toBe('finished');
        expect(end.answered, `${key}: as many questions as the activity asks`).toBe(items);
        if ('attemptstate' in end) {
            expect(String(end.attemptstate), `${key}: the attempt is complete`).toMatch(/complete|finished/);
        }
        expect(String(end.attemptstopcriteria || end.stopcode || ''), `${key}: a stop criterion is stored`).not.toBe('');
        // The engine's record of the attempt, where it keeps one, counts the same items as the activity.
        if (end.engine && end.engine.number_of_testitems_used !== undefined && end.engine.number_of_testitems_used !== null) {
            expect(Number(end.engine.number_of_testitems_used), `${key}: the engine's count`).toBe(end.answered);
        }
        last[key] = end;
    }
    check('end');
    aq.keep(OUT, 'readings.json', {session: s.activities, learners: s.learners.map((l) => l.id), ttl: TTL, log});
    aq.keep(OUT, 'final.json', {one: aq.report(one.id), two: aq.report(two.id)});
    await ctx1.close();
    await ctx2.close();
});
