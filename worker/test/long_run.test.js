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
 * The real worker process, a real browser, sittings of any length and the faults of #172, #173, #175 — against a
 * stand-in for Moodle (testlib/fake_moodle.js), so that a classical test of 2500 items takes minutes, not hours.
 *
 * CLARA run 103 (27.11.2026): 18 first executions stopped after exactly 1000 answers, a retry that continued at 264
 * after 1264. Run with CATQUIZLAB_WORKER_SCRIPT pointing at the worker of 0.7.83, the first tests fail exactly so.
 * CATQUIZLAB_LONG_RUN=0 leaves out the 2500-item sitting and the 999/1000 boundaries (1001 and the rest stay).
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
const LONG = process.env.CATQUIZLAB_LONG_RUN !== '0';

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
 * @param {number} timeoutms How long it may take.
 * @param {object} env Extra environment for the worker.
 * @returns {Promise<{report: object, moodle: object, out: string}>}
 */
async function sit(options, timeoutms = 120000, env = {}) {
    const moodle = await fake.start(options);
    try {
        const {out} = await runWorker(moodle, timeoutms, env);
        assert.strictEqual(moodle.state.reports.length, 1, `one report:\n${out.slice(-3000)}`);
        return {report: moodle.state.reports[0], moodle, out};
    } finally {
        await moodle.close();
    }
}

/**
 * The progress block of a report's diagnosis.
 *
 * @param {object} report The job_complete parameters.
 * @returns {object}
 */
function progress(report) {
    return (JSON.parse(report.diagnostics || '{}').progress) || {};
}

for (const length of LONG ? [999, 1000, 1001, 2500] : [1001]) {
    test(`a classical sitting of ${length} items is played to its end, not stopped at 1000 (#172)`, {timeout: 900000},
        async() => {
            const {report, moodle} = await sit({length, budget: length}, 900000);
            assert.strictEqual(report.status, 'finished', report.message);
            assert.strictEqual(Number(report.engineattemptid), 900);
            assert.strictEqual(moodle.state.attempts.length, 1, 'one engine attempt, never a second');
            assert.strictEqual(moodle.state.attempts[0].answered, length);
            assert.strictEqual(moodle.state.oracle, length, 'one oracle call per answer, none more');
            assert.strictEqual(progress(report).answered, length);
            assert.strictEqual(progress(report).baseline, 0);
        });
}

test('a retry continuing at 264 answers 1000 and more and ends the same attempt (#172, #173)', {timeout: 900000},
    async() => {
        const length = LONG ? 2500 : 1300;
        const {report, moodle} = await sit({length, budget: length, baseline: 264}, 900000);
        assert.strictEqual(report.status, 'finished', report.message);
        assert.strictEqual(moodle.state.attempts.length, 1, 'continued, not started again');
        assert.strictEqual(Number(report.engineattemptid), 900);
        assert.strictEqual(moodle.state.attempts[0].answered, length);
        assert.strictEqual(moodle.state.oracle, length - 264);
        assert.strictEqual(progress(report).baseline, 264);
        assert.strictEqual(progress(report).baselinesource, 'bound');
    });

test('an attempt complete before the start is collected, not started again (#173 AK05)', async() => {
    const {report, moodle} = await sit({length: 40, complete: true});
    assert.strictEqual(report.status, 'finished', report.message);
    assert.strictEqual(Number(report.engineattemptid), 900);
    assert.strictEqual(moodle.state.attempts.length, 1, 'no second attempt');
    assert.strictEqual(moodle.state.oracle, 0, 'nothing answered');
});

test('a server that cannot be asked: nothing answered, said as such, not counted from 0 (#173 AK01)', async() => {
    const {report, moodle} = await sit({length: 40, unavailable: true});
    assert.strictEqual(report.status, 'failed');
    assert.strictEqual(report.reason, 'state_unavailable');
    assert.match(report.message, /\[state:unavailable\]/);
    assert.strictEqual(moodle.state.oracle, 0, 'no answer without the server\'s word');
    assert.strictEqual(moodle.state.calls.local_catquizlab_sitting_state, 3, 'asked a bounded number of times');
});

test('an engine attempt that is not the sitting\'s: refused before any answer (#173 AK04)', async() => {
    const {report, moodle} = await sit({length: 40, foreign: true});
    assert.strictEqual(report.status, 'failed');
    assert.strictEqual(report.reason, 'identity_mismatch');
    assert.match(report.message, /\[identity:/);
    assert.strictEqual(moodle.state.oracle, 0);
});

test('an answer beyond the run\'s pool is not given: budget, with the server\'s word (#172 AK04)', async() => {
    const {report, moodle} = await sit({length: 30, budget: 30, extraslots: true});
    assert.strictEqual(report.status, 'failed');
    assert.strictEqual(report.reason, 'budget_violation');
    assert.match(report.message, /\[guard:budget\] 30 answer\(s\) confirmed/);
    assert.strictEqual(moodle.state.attempts[0].answered, 30, 'not one answer more than the pool');
    assert.strictEqual(progress(report).serverend.state, 'inprogress');
});

test('the same question back after every answer: no progress, stopped, the answers not counted (#172 AK03)', async() => {
    const {report, moodle} = await sit({length: 30, budget: 30, repeatat: 12});
    assert.strictEqual(report.status, 'failed');
    assert.strictEqual(report.reason, 'no_progress');
    assert.match(report.message, /\[guard:noprogress\] Usage 4711 slot 12/);
    assert.strictEqual(moodle.state.attempts[0].answered, 11);
    assert.strictEqual(progress(report).answered, 11);
    assert.strictEqual(progress(report).stalls, 3);
});

test('the worker sends its contract with every claim (#173)', async() => {
    const {moodle} = await sit({length: 3, budget: 3, strictcontract: true});
    assert.ok(moodle.state.contracts.every((c) => Number(c) === 2), JSON.stringify(moodle.state.contracts));
});

test('the fault "notstored" (#175 acceptance): the answers never reach the server, no progress, held by its code',
    async() => {
        const {report, moodle} = await sit({length: 20, budget: 20}, 120000, {CATQUIZLAB_FAULTS: 'notstored@4'});
        assert.strictEqual(report.status, 'failed');
        assert.strictEqual(report.reason, 'no_progress');
        // The start of the stand-in's attempt is a POST too, so the fourth POST is its third answer.
        assert.strictEqual(moodle.state.attempts[0].answered, 2, 'the answers before the fault stored, none after');
        assert.strictEqual(JSON.parse(report.diagnostics).progress.stalls, 3);
    });
