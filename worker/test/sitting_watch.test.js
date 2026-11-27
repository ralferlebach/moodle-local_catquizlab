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
 * The watch over one execution (#172, #173): progress, stalls, the budget, the counts kept apart.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const {SittingWatch, SEEN, MAX_STALLS, baselineFrom} = require('../sitting_watch.js');
const worker = require('../run_attempt.js');

/**
 * Play n questions through the watch, as the answer loop does: see, check, send.
 *
 * @param {SittingWatch} watch The watch.
 * @param {number} from The first slot.
 * @param {number} n How many.
 * @returns {string} The first check that stopped it, or ''.
 */
function play(watch, from, n) {
    for (let slot = from; slot < from + n; slot++) {
        assert.strictEqual(watch.see(7, slot), SEEN.PROGRESS);
        const why = watch.check();
        if (why !== '') {
            return why;
        }
        watch.sent();
    }
    return '';
}

test('no fixed number of turns: 2500 questions of a 2500-item pool go through, and 999, 1000, 1001 too', () => {
    for (const n of [999, 1000, 1001, 2500]) {
        const watch = new SittingWatch({budget: n, budgetkind: 'pool', expected: n});
        watch.begin({count: 0, qubaid: 7, engineattemptid: 3, slot: 1});
        assert.strictEqual(play(watch, 1, n), '', `${n} questions`);
        watch.finished();
        assert.strictEqual(watch.answered, n);
        assert.strictEqual(watch.actions, n);
    }
});

test('a retry continuing at 264 answers 1000 more and finishes: run 103, sitting 46794', () => {
    const watch = new SittingWatch({budget: 2500});
    watch.begin({count: 264, qubaid: 7, engineattemptid: 3, slot: 265, source: 'confirmed'});
    assert.strictEqual(play(watch, 265, 2500 - 264), '');
    watch.finished();
    assert.strictEqual(watch.answered, 2500);
    assert.strictEqual(watch.summary().baseline, 264);
    assert.strictEqual(watch.summary().baselinesource, 'confirmed');
});

test('one answer more than the pool is a budget check, not an answer', () => {
    const watch = new SittingWatch({budget: 1000});
    watch.begin({count: 0, qubaid: 7, slot: 1});
    assert.strictEqual(play(watch, 1, 1000), '');
    // The engine presents slot 1001 of a 1000-item pool: impossible, so asked about, not answered.
    assert.strictEqual(watch.see(7, 1001), SEEN.PROGRESS);
    assert.strictEqual(watch.answered, 1000);
    assert.strictEqual(watch.check(), 'budget');
});

test('without a known budget nothing is invented: only progress is watched', () => {
    const watch = new SittingWatch({budget: 0});
    watch.begin({count: 0, qubaid: 7, slot: 1});
    assert.strictEqual(play(watch, 1, 5000), '');
    assert.strictEqual(watch.summary().budgetkind, 'unknown');
});

test('the same question back after each answer is no progress: stopped after the third', () => {
    const watch = new SittingWatch({budget: 100});
    watch.begin({count: 4, qubaid: 7, slot: 5});
    assert.strictEqual(watch.see(7, 5), SEEN.PROGRESS);
    for (let round = 1; round <= MAX_STALLS; round++) {
        watch.sent();
        assert.strictEqual(watch.see(7, 5), SEEN.SAME);
        assert.strictEqual(watch.check(), round < MAX_STALLS ? '' : 'stall');
    }
    // None of those answers counted.
    assert.strictEqual(watch.answered, 4);
});

test('seeing the same page again without an answer is not progress, and not a stall either', () => {
    const watch = new SittingWatch({budget: 100});
    watch.begin({count: 0, qubaid: 7, slot: 1});
    assert.strictEqual(watch.see(7, 1), SEEN.PROGRESS);
    assert.strictEqual(watch.see(7, 1), SEEN.SAME);
    assert.strictEqual(watch.answered, 0);
    assert.strictEqual(watch.check(), '');
});

test('nothing moving for longer than allowed is a stall, measured from the last progress', () => {
    let clock = 0;
    const watch = new SittingWatch({budget: 100, stallms: 1000, now: () => clock});
    watch.begin({count: 0, qubaid: 7, slot: 1});
    assert.strictEqual(watch.see(7, 1), SEEN.PROGRESS);
    clock = 999;
    assert.strictEqual(watch.check(), '');
    clock = 1001;
    assert.strictEqual(watch.check(), 'stall');
});

test('another usage, or a question going backwards, is not this sitting', () => {
    const watch = new SittingWatch({budget: 100});
    watch.begin({count: 0, qubaid: 7, slot: 1});
    assert.strictEqual(watch.see(7, 1), SEEN.PROGRESS);
    watch.sent();
    assert.strictEqual(watch.see(8, 2), SEEN.OTHER_USAGE);
    assert.strictEqual(watch.see(7, 0), SEEN.BACKWARDS);
});

test('a reconciled submission: stored counts once, not stored expects the same question again', () => {
    const watch = new SittingWatch({budget: 100});
    watch.begin({count: 0, qubaid: 7, slot: 1});
    watch.see(7, 1);
    watch.sent();
    watch.reconciled('notstored');
    assert.strictEqual(watch.answered, 0);
    assert.strictEqual(watch.see(7, 1), SEEN.PROGRESS, 'answered again, as the server said');
    watch.sent();
    watch.reconciled('stored');
    assert.strictEqual(watch.answered, 1);
    assert.strictEqual(watch.see(7, 2), SEEN.PROGRESS);
    assert.strictEqual(watch.answered, 1, 'the stored answer is not counted twice');
    assert.strictEqual(watch.summary().reconciliations, 2);
});

test('the start is the server\'s count, or no start at all (#173)', () => {
    assert.strictEqual(baselineFrom({found: true, state: 'inprogress', questionsattempted: 264, slots: 265}).count, 264);
    for (const bad of [null, {found: false, reason: 'no-engine-attempt'}, {found: false, reason: 'quba-mismatch'},
        {found: true, state: 'inprogress', questionsattempted: null, slots: 3}]) {
        assert.strictEqual(baselineFrom(bad).ok, false, JSON.stringify(bad));
    }
});

test('a server that does not answer is asked a bounded number of times, then the execution ends unanswered', async() => {
    const waits = [];
    let calls = 0;
    const failing = async() => {
        calls++;
        throw new Error('HTTP 503');
    };
    await assert.rejects(worker.askServerWith('local_catquizlab_sitting_state', {attemptid: 1}, failing,
        async(ms) => {
            waits.push(ms);
        }, 3), /\[state:unavailable\].*HTTP 503/);
    assert.strictEqual(calls, 3);
    assert.deepStrictEqual(waits, [1000, 4000]);
    // Busy is asked again; an answer is taken.
    let round = 0;
    const busyonce = async() => (++round === 1 ? {found: false, reason: 'busy'} : {found: true, state: 'inprogress'});
    const answer = await worker.askServerWith('x', {}, busyonce, async() => undefined, 3);
    assert.strictEqual(answer.found, true);
});

test('the worker names its failures by code; the rest is left to the server (#175)', () => {
    assert.strictEqual(worker.reasonOf('[guard:budget] 1000 answer(s)'), 'budget_violation');
    assert.strictEqual(worker.reasonOf('[guard:noprogress] Usage 7'), 'no_progress');
    assert.strictEqual(worker.reasonOf('[submit:http_404] ... [identity:quba-mismatch] server'), 'identity_mismatch');
    assert.strictEqual(worker.reasonOf('[state:unavailable] x'), 'state_unavailable');
    assert.strictEqual(worker.reasonOf('[lease:lost] x'), 'lease_lost');
    assert.strictEqual(worker.reasonOf('Attempt did not reach the finish page after 1000 answer(s).'), '');
});
