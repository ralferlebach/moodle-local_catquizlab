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
 * A connection the server closes before any response (CI 26.11.2026, c6ec43c2, load classic at 50 workers: Apache
 * ended a process, "other side closed"): a read is sent once more on a new connection, a write never, a second
 * closed connection is the failure, and a server that answers slowly or not at all is not a closed connection.
 *
 * Against a real HTTP server and Node's own fetch, so the error is the one fetch throws, not a made-up one.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const http = require('http');
const worker = require('../run_attempt.js');

/**
 * A server that closes the connection of its first `closes` requests without a word, then answers {"ok": true}.
 *
 * @param {number} closes How many requests get their connection closed.
 * @returns {Promise<{url: string, seen: function(): number, close: function(): Promise<void>}>}
 */
async function server(closes) {
    let seen = 0;
    const srv = http.createServer((req, res) => {
        req.resume();
        req.on('end', () => {
            seen++;
            if (seen <= closes) {
                req.socket.destroy();
                return;
            }
            res.writeHead(200, {'Content-Type': 'application/json'});
            res.end('{"ok": true}');
        });
    });
    await new Promise((resolve) => srv.listen(0, '127.0.0.1', resolve));
    return {
        url: `http://127.0.0.1:${srv.address().port}/webservice/rest/server.php`,
        seen: () => seen,
        close: () => new Promise((resolve) => {
            srv.closeAllConnections();
            srv.close(resolve);
        }),
    };
}

const init = {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'a=1'};

test('the resendable functions are the two that only read', () => {
    assert.deepStrictEqual([...worker.WS_RESENDABLE].sort(),
        ['local_catquizlab_oracle_answer', 'local_catquizlab_sitting_state']);
});

test('a read whose connection the server closed is sent once more, and said', async() => {
    const s = await server(1);
    const said = [];
    try {
        const response = await worker.postWs(s.url, init, 'local_catquizlab_oracle_answer', fetch, (l) => said.push(l));
        assert.deepStrictEqual(await response.json(), {ok: true});
        assert.strictEqual(s.seen(), 2);
        assert.strictEqual(said.length, 1);
        // What Node's fetch says, as in CI: UND_ERR_SOCKET, "other side closed".
        assert.match(said[0], new RegExp('^\\[catquizlab:resend\\] \\d{4}-\\d\\d-\\d\\dT\\d\\d:\\d\\d:\\d\\d\\.\\d{3}Z '
            + 'local_catquizlab_oracle_answer: the server closed the connection before any response '
            + '\\((UND_ERR_SOCKET|ECONNRESET|EPIPE): '));
        assert.strictEqual(said[0].split(' ')[0], worker.RESEND_MARK);
    } finally {
        await s.close();
    }
});

test('a second closed connection is the failure: sent twice, not more', async() => {
    const s = await server(2);
    const said = [];
    try {
        await assert.rejects(worker.postWs(s.url, init, 'local_catquizlab_sitting_state', fetch, (l) => said.push(l)),
            (error) => worker.closedConnection(error) !== '');
        assert.strictEqual(s.seen(), 2);
        assert.strictEqual(said.length, 1);
    } finally {
        await s.close();
    }
});

test('a write is never sent twice', async() => {
    for (const wsfunction of ['local_catquizlab_job_claim', 'local_catquizlab_job_complete',
        'local_catquizlab_worker_heartbeat']) {
        const s = await server(1);
        const said = [];
        try {
            await assert.rejects(worker.postWs(s.url, init, wsfunction, fetch, (l) => said.push(l)),
                (error) => worker.closedConnection(error) !== '');
            assert.strictEqual(s.seen(), 1, wsfunction);
            assert.deepStrictEqual(said, [], wsfunction);
        } finally {
            await s.close();
        }
    }
});

test('a refused connection or a timeout is not a closed connection: no resend', async() => {
    const s = await server(0);
    const url = s.url;
    await s.close();
    let calls = 0;
    const counting = (...a) => {
        calls++;
        return fetch(...a);
    };
    await assert.rejects(worker.postWs(url, init, 'local_catquizlab_oracle_answer', counting, () => {
        throw new Error('nothing to say');
    }), (error) => worker.closedConnection(error) === '');
    assert.strictEqual(calls, 1);

    calls = 0;
    const timeout = Object.assign(new Error('fetch failed'), {cause: {code: 'UND_ERR_HEADERS_TIMEOUT'}});
    await assert.rejects(worker.postWs('http://x/', init, 'local_catquizlab_oracle_answer', () => {
        calls++;
        return Promise.reject(timeout);
    }, () => {
        throw new Error('nothing to say');
    }), (error) => error === timeout);
    assert.strictEqual(calls, 1);
});

test('an HTTP error status is a response, not a closed connection: no resend', async() => {
    let seen = 0;
    const srv = http.createServer((req, res) => {
        seen++;
        res.writeHead(503);
        res.end();
    });
    await new Promise((resolve) => srv.listen(0, '127.0.0.1', resolve));
    try {
        const response = await worker.postWs(`http://127.0.0.1:${srv.address().port}/`, init,
            'local_catquizlab_oracle_answer', fetch, () => {
                throw new Error('nothing to say');
            });
        assert.strictEqual(response.status, 503);
        assert.strictEqual(seen, 1);
    } finally {
        srv.closeAllConnections();
        await new Promise((resolve) => srv.close(resolve));
    }
});
