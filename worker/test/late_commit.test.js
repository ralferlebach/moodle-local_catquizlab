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
 * An uncertain answer stored late, and the execution's identity on every call (#173, re-audit 27.11.2026).
 *
 * Against the stand-in for Moodle (testlib/fake_moodle.js), not a real engine: the real worker process and a real
 * browser, with the server's side as the stand-in plays it. The answer of one submission is answered HTTP 502 and stored
 * after the worker's first look at the server — before it is back in (latecommitat "state"), or while it is on its way
 * to the activity page (latecommitat "view"). When that answer was the last, the engine attempt is complete by then and
 * the activity page offers a new attempt. The worker of 0.7.84 clicked it: a second attempt of the same person. Run with
 * CATQUIZLAB_WORKER_SCRIPT pointing at that worker, the tests on the last answer fail so.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const path = require('path');
const {spawn} = require('child_process');
const fake = require('../testlib/fake_moodle.js');

const SCRIPT = process.env.CATQUIZLAB_WORKER_SCRIPT || path.join(__dirname, '..', 'run_attempt.js');

/**
 * Run the worker once against the stand-in, until it has played its one sitting.
 *
 * @param {object} moodle The stand-in.
 * @param {number} timeoutms How long it may take.
 * @param {object} env Extra environment (faults).
 * @returns {Promise<{code: number, out: string}>}
 */
function runWorker(moodle, timeoutms, env = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn(process.execPath, [SCRIPT, `--base-url=${moodle.url}`, '--worker-id=fakeworker',
            '--max-jobs=1', '--token=t'], {env: {...process.env, CATQUIZLAB_MAX_IDLE: '0', ...env}, cwd: path.dirname(SCRIPT)});
        let out = '';
        child.stdout.on('data', (d) => {
            out += d;
        });
        child.stderr.on('data', (d) => {
            out += d;
        });
        const timer = setTimeout(() => {
            child.kill('SIGKILL');
            reject(new Error(`worker did not end within ${timeoutms} ms:\n${out.slice(-3000)}`));
        }, timeoutms);
        child.on('exit', (code) => {
            clearTimeout(timer);
            resolve({code, out});
        });
    });
}

/**
 * One sitting, its report.
 *
 * @param {object} options For the stand-in.
 * @param {object} env Extra environment for the worker.
 * @returns {Promise<{report: object, moodle: object, out: string, diagnosis: object}>}
 */
async function sit(options, env = {}) {
    // A negative control against a worker before 0.7.85 serves its contract (CATQUIZLAB_FAKE_CONTRACT=1).
    const moodle = await fake.start({contract: Number(process.env.CATQUIZLAB_FAKE_CONTRACT || 2), ...options});
    try {
        const {out} = await runWorker(moodle, 180000, env);
        assert.strictEqual(moodle.state.reports.length, 1, `one report:\n${out.slice(-3000)}`);
        const report = moodle.state.reports[0];
        return {report, moodle, out, diagnosis: JSON.parse(report.diagnostics || '{}')};
    } finally {
        await moodle.close();
    }
}

/**
 * The verdicts of a report's reconciliations.
 *
 * @param {object} diagnosis The report's diagnosis.
 * @returns {string[]}
 */
function verdicts(diagnosis) {
    return (diagnosis.reconciliations || []).map((r) => r.verdict);
}

test('the last answer stored late, before the worker is back in: the end is collected, no second attempt', async() => {
    const {report, moodle, diagnosis} = await sit({length: 30, budget: 30, latecommit: 30});
    assert.strictEqual(report.status, 'finished', report.message);
    assert.strictEqual(Number(report.engineattemptid), 900);
    assert.strictEqual(moodle.state.attempts.length, 1, 'no second attempt of the same person');
    assert.strictEqual(moodle.state.attempts[0].state, 'complete');
    assert.strictEqual(moodle.state.attempts[0].answered, 30, 'every answer once');
    assert.strictEqual(moodle.state.oracle, 30, 'no answer given twice');
    assert.deepStrictEqual(verdicts(diagnosis), ['notstored', 'storedlate']);
    assert.strictEqual(diagnosis.progress.answered, 30);
    assert.strictEqual(diagnosis.progress.serverend.source, 'sitting_state:complete-before-reentry');
    assert.strictEqual(diagnosis.progress.serverend.questionsattempted, 30);
});

test('the last answer stored while the worker goes back in: the page offers a new attempt, it is not clicked',
    async() => {
        const {report, moodle, diagnosis} = await sit({length: 30, budget: 30, latecommit: 30, latecommitat: 'view'});
        assert.strictEqual(report.status, 'finished', report.message);
        assert.strictEqual(moodle.state.attempts.length, 1, 'no second attempt of the same person');
        assert.strictEqual(moodle.state.attempts[0].answered, 30);
        assert.strictEqual(moodle.state.oracle, 30);
        assert.deepStrictEqual(verdicts(diagnosis), ['notstored', 'storedlate']);
        assert.strictEqual(diagnosis.progress.serverend.source, 'sitting_state:complete-at-reentry');
    });

test('an answer in the middle stored late: counted once, nothing answered twice, nothing left out', async() => {
    const {report, moodle, diagnosis} = await sit({length: 40, budget: 40, latecommit: 17});
    assert.strictEqual(report.status, 'finished', report.message);
    assert.strictEqual(moodle.state.attempts.length, 1);
    assert.strictEqual(moodle.state.attempts[0].answered, 40);
    assert.strictEqual(moodle.state.oracle, 40, 'question 17 answered once');
    assert.strictEqual(diagnosis.progress.answered, 40);
    assert.deepStrictEqual(verdicts(diagnosis), ['notstored', 'storedlate']);
    // The regular end confirmed by the server: its own numbers, apart from the worker's (#176 AK02).
    assert.strictEqual(diagnosis.progress.serverend.source, 'sitting_state:finish-page');
    assert.strictEqual(diagnosis.progress.serverend.state, 'complete');
    assert.strictEqual(diagnosis.progress.serverend.engineattemptid, 900);
});

test('the server no longer knows the sitting\'s attempt after an uncertain answer: not taken for "not stored"',
    async() => {
        const {report, moodle} = await sit({length: 30, budget: 30, latecommit: 12, ambiguousafter: true});
        assert.strictEqual(report.status, 'failed');
        assert.strictEqual(report.reason, 'identity_mismatch');
        assert.match(report.message, /\[identity:ambiguous-engine-attempt\]/);
        assert.strictEqual(moodle.state.oracle, 12, 'nothing answered after it');
        assert.strictEqual(moodle.state.attempts.length, 1, 'nothing started');
    });

for (const [fault, extra] of [['lostrequest@6', 1], ['lostresponse@6', 0]]) {
    test(`${fault.split('@')[0]}: reconciled with the server, each question answered exactly once`, async() => {
        const {report, moodle, diagnosis} = await sit({length: 20, budget: 20}, {CATQUIZLAB_FAULTS: fault});
        assert.strictEqual(report.status, 'finished', report.message);
        assert.strictEqual(moodle.state.attempts.length, 1);
        assert.strictEqual(moodle.state.attempts[0].answered, 20, 'none twice, none left out');
        // A lost request asks the oracle for the same question once more; its answer is given once.
        assert.strictEqual(moodle.state.oracle, 20 + extra);
        assert.strictEqual(diagnosis.progress.answered, 20);
        assert.strictEqual(verdicts(diagnosis).length, 1);
    });
}

test('every call that changes the sitting names the claim\'s execution identity (#173 contract 2)', async() => {
    const {report, moodle} = await sit({length: 6, budget: 6, execution: 3});
    assert.strictEqual(report.status, 'finished', report.message);
    assert.ok(moodle.state.executions.bind.length >= 1);
    for (const [call, ids] of Object.entries(moodle.state.executions)) {
        assert.ok(ids.length > 0, `${call} was called`);
        assert.ok(ids.every((id) => id === 5003), `${call}: ${JSON.stringify(ids)}`);
    }
    assert.ok(moodle.state.contracts.every((c) => Number(c) === 2));
});

test('a bind the server fences: nothing answered, the lease said lost', async() => {
    const {report, moodle} = await sit({length: 6, budget: 6, bindfence: 'lease-expired'});
    assert.strictEqual(report.status, 'failed');
    assert.strictEqual(report.reason, 'lease_lost');
    assert.match(report.message, /\[lease:lost\].*lease-expired/);
    assert.strictEqual(moodle.state.oracle, 0);
});
