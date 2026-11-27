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
 * A stand-in for Moodle, mod_adaptivequiz and this plugin's web services, for running the real worker process with
 * a real browser against sittings of any length and any fault (#172, #173, #175).
 *
 * Only what the worker touches: the login form, the activity page (start, continue), the attempt page with one
 * question at a time ("question-{usage}-{slot}", radio options, "submitanswer"), the redirect after each answer, the
 * finish page; and the web services job_claim, worker_heartbeat, oracle_answer, sitting_state, sitting_bind,
 * job_complete. The test as it stops: after `length` answers the attempt is complete, like a classical test of that
 * many items.
 *
 * Options (all optional): length (answers until the end, 30), budget (sent with the claim; 0 = unknown), baseline
 * (answers already in the attempt from an earlier execution), complete (the attempt is complete before the start),
 * repeatat (from this slot on, an answer is not stored and the same question comes back), unavailable (sitting_state
 * and sitting_bind answer HTTP 503), foreign (the bound attempt is another), extraslots (the engine presents more
 * questions than `length` without ending: an answer beyond the pool), strictcontract (refuse claims without the
 * worker contract).
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const http = require('http');
const {URLSearchParams} = require('url');

const PAGE = (title, body) => `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title></head>`
    + `<body>${body}</body></html>`;

/**
 * Start the stand-in.
 *
 * @param {object} options See above.
 * @returns {Promise<object>} url, state (the attempt, the reports, the claims), close().
 */
async function start(options = {}) {
    const length = options.length || 30;
    const quba = 4711;
    const state = {
        attempts: [],
        claims: 0,
        reports: [],
        calls: {},
        binds: 0,
        oracle: 0,
        contracts: [],
    };
    if (options.baseline !== undefined || options.complete) {
        const answered = options.complete ? length : options.baseline;
        state.attempts.push({id: 900, quba, answered, slots: options.complete ? answered : answered + 1,
            state: options.complete ? 'complete' : 'inprogress'});
    }
    let bound = options.foreign ? 901 : 0;

    const current = () => state.attempts.find((a) => a.state === 'inprogress') || null;
    const newest = () => state.attempts[state.attempts.length - 1] || null;

    const questionPage = (a) => PAGE('Question', `<form id="responseform" method="post"
        action="/mod/adaptivequiz/attempt.php?cmid=5"><input type="hidden" name="slot" value="${a.slots}">
        <div class="que multichoice" id="question-${a.quba}-${a.slots}"><div class="qtext">Item ${a.slots}</div>
        <div class="answer"><input type="radio" name="answer" value="0" id="a0"><label for="a0">A</label>
        <input type="radio" name="answer" value="1" id="a1"><label for="a1">B</label></div></div>
        <input type="submit" name="submitanswer" value="Submit answer"></form>`);

    const describe = (a) => (a ? {found: true, reason: '', engineattemptid: a.id, qubaid: a.quba, bound: bound === a.id,
        state: a.state, questionsattempted: a.answered, slots: a.slots, timemodified: 0}
        : {found: false, reason: 'no-engine-attempt', engineattemptid: 0, qubaid: 0, bound: false, state: '',
            questionsattempted: 0, slots: 0, timemodified: 0});

    const ws = (fn, params) => {
        state.calls[fn] = (state.calls[fn] || 0) + 1;
        switch (fn) {
            case 'local_catquizlab_job_claim': {
                state.contracts.push(params.contract);
                if (options.strictcontract && Number(params.contract || 0) < 1) {
                    return {hasjob: false, message: 'outdated worker', nextdue: 0};
                }
                state.claims++;
                if (state.claims > 1) {
                    return {hasjob: false, message: 'none', nextdue: 0};
                }
                return {hasjob: true, runid: 1, attemptid: 77, quizcmid: 5, userid: 2, username: 'catlab_u2',
                    experimentid: 1, execution: options.baseline ? 2 : 1, correlationid: 'c0ffee',
                    budget: options.budget || 0, budgetkind: options.budget ? 'pool' : 'unknown',
                    expectedlength: length, contract: 1, message: 'claimed'};
            }
            case 'local_catquizlab_worker_heartbeat':
                return {acknowledged: true, stop: false};
            case 'local_catquizlab_oracle_answer':
                state.oracle++;
                return {ready: true, choice: -1, fraction: 1, message: ''};
            case 'local_catquizlab_sitting_state': {
                const a = bound ? state.attempts.find((x) => x.id === bound) : newest();
                if (bound && !a) {
                    return {found: false, reason: 'identity-mismatch', engineattemptid: bound, qubaid: 0, bound: false,
                        state: '', questionsattempted: 0, slots: 0, timemodified: 0};
                }
                const answer = describe(a);
                if (a && Number(params.qubaid || 0) > 0 && Number(params.qubaid) !== a.quba) {
                    return {...answer, found: false, reason: 'quba-mismatch'};
                }
                return answer;
            }
            case 'local_catquizlab_sitting_bind': {
                state.binds++;
                const shown = state.attempts.find((x) => x.quba === Number(params.qubaid));
                if (!shown) {
                    return {...describe(null), reason: 'foreign-usage', newly: false};
                }
                if (bound && bound !== shown.id) {
                    return {...describe(null), reason: 'identity-mismatch', engineattemptid: bound, newly: false};
                }
                const newly = !bound;
                bound = shown.id;
                return {...describe(shown), bound: true, newly};
            }
            case 'local_catquizlab_job_complete':
                state.reports.push(params);
                return {acknowledged: true, message: 'ok'};
            default:
                return {};
        }
    };

    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://x');
        let body = '';
        req.on('data', (chunk) => {
            body += chunk;
        });
        req.on('end', () => {
            const form = new URLSearchParams(body);
            const send = (status, html, headers = {}) => {
                res.writeHead(status, {'Content-Type': 'text/html; charset=utf-8', ...headers});
                res.end(html);
            };
            const redirect = (to) => send(303, '', {Location: to});
            const path = url.pathname;

            if (path === '/webservice/rest/server.php') {
                const fn = form.get('wsfunction') || url.searchParams.get('wsfunction');
                if (options.unavailable && ['local_catquizlab_sitting_state', 'local_catquizlab_sitting_bind'].includes(fn)) {
                    state.calls[fn] = (state.calls[fn] || 0) + 1;
                    res.writeHead(503, {'Content-Type': 'text/plain'});
                    res.end('unavailable');
                    return;
                }
                const params = {};
                for (const [key, value] of form.entries()) {
                    params[key] = value;
                }
                res.writeHead(200, {'Content-Type': 'application/json'});
                res.end(JSON.stringify(ws(fn, params)));
                return;
            }
            if (path === '/login/index.php') {
                if (req.method === 'POST') {
                    return redirect('/my/');
                }
                return send(200, PAGE('Log in', `<form method="post" action="/login/index.php" id="login">
                    <input id="username" name="username"><input id="password" name="password" type="password">
                    <button id="loginbtn" type="submit">Log in</button></form>`));
            }
            if (path === '/my/') {
                return send(200, PAGE('Dashboard', '<p>Dashboard</p>'));
            }
            if (path === '/mod/adaptivequiz/view.php') {
                const a = current();
                if (a) {
                    return send(200, PAGE('Activity', `<form method="post" action="/mod/adaptivequiz/attempt.php">
                        <input type="hidden" name="resume" value="restart"><button type="submit">Start a new attempt</button>
                        </form><form method="post" action="/mod/adaptivequiz/attempt.php">
                        <button type="submit">Continue attempt</button></form>`));
                }
                // Never an attempt in progress here: a new attempt, which a complete sitting must not get.
                return send(200, PAGE('Activity', `<form method="post" action="/mod/adaptivequiz/attempt.php">
                    <input type="submit" id="id_submitbutton" value="Start attempt"></form>`));
            }
            if (path === '/mod/adaptivequiz/attempt.php') {
                let a = current();
                if (form.get('resume') === 'restart' && a) {
                    a.state = 'abandoned';
                    a = null;
                }
                if (!a) {
                    a = {id: 900 + state.attempts.length, quba: quba + state.attempts.length, answered: 0, slots: 1,
                        state: 'inprogress'};
                    state.attempts.push(a);
                    return redirect('/mod/adaptivequiz/attempt.php?cmid=5');
                }
                if (req.method === 'POST' && form.get('submitanswer')) {
                    const slot = Number(form.get('slot'));
                    if (slot !== a.slots) {
                        return send(404, PAGE('Error', '<div class="errorbox">out of sequence</div>'));
                    }
                    if (options.repeatat && slot >= options.repeatat) {
                        // Not stored: the same question again.
                        return redirect('/mod/adaptivequiz/attempt.php?cmid=5');
                    }
                    a.answered++;
                    if (a.answered >= length && !options.extraslots) {
                        a.state = 'complete';
                        return redirect(`/mod/adaptivequiz/attemptfinished.php?attempt=${a.id}&instance=1`);
                    }
                    a.slots++;
                    return redirect('/mod/adaptivequiz/attempt.php?cmid=5');
                }
                return send(200, questionPage(a));
            }
            if (path === '/mod/adaptivequiz/attemptfinished.php') {
                return send(200, PAGE('Finished', '<div class="adaptivequiz-finished">Done</div>'));
            }
            send(404, PAGE('Error', '<div class="errorbox">not found</div>'));
        });
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}`;

    return {
        url,
        state,
        bound: () => bound,
        close: () => new Promise((resolve) => server.close(() => resolve())),
    };
}

module.exports = {start};
