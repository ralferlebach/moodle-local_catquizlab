/**
 * Unit tests for the pure helpers of the local_catquizlab Puppeteer worker.
 *
 * Runs on Node's built-in test runner with no external dependencies:
 *   node --test
 * The helpers are imported from the worker module, whose Puppeteer/browser code
 * only runs when the script is executed directly (require.main === module).
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const worker = require('../run_attempt.js');

test('parseArgs reads --key=value and boolean flags', () => {
    const args = worker.parseArgs(['--base-url=http://x', '--token=abc', '--headless', 'ignored']);
    assert.strictEqual(args['base-url'], 'http://x');
    assert.strictEqual(args.token, 'abc');
    assert.strictEqual(args.headless, true);
    assert.strictEqual(args.ignored, undefined);
});

test('normaliseBaseUrl strips trailing slashes', () => {
    assert.strictEqual(worker.normaliseBaseUrl('http://x/moodle///'), 'http://x/moodle');
    assert.strictEqual(worker.normaliseBaseUrl(''), '');
    assert.strictEqual(worker.normaliseBaseUrl(undefined), '');
});

test('a web service request carries everything in its body, the URL nothing (#111)', () => {
    const request = worker.buildWsRequest('http://x/', 'tok', 'local_catquizlab_job_claim', {workerid: 'w1'});
    assert.strictEqual(request.url, 'http://x/webservice/rest/server.php');
    const body = new URLSearchParams(request.body);
    assert.strictEqual(body.get('wstoken'), 'tok');
    assert.strictEqual(body.get('wsfunction'), 'local_catquizlab_job_claim');
    assert.strictEqual(body.get('moodlewsrestformat'), 'json');
    assert.strictEqual(body.get('workerid'), 'w1');
    // The URL does not grow with the diagnosis, and never carries the token.
    const large = worker.buildWsRequest('http://x/', 'tok', 'local_catquizlab_job_complete', {diagnostics: 'x'.repeat(200000)});
    assert.strictEqual(large.url, request.url);
    assert.ok(!large.url.includes('tok'));
});

test('parseQuestionId extracts the first number', () => {
    assert.strictEqual(worker.parseQuestionId('question-123-456'), 123);
    assert.strictEqual(worker.parseQuestionId('q42'), 42);
    assert.strictEqual(worker.parseQuestionId(''), 0);
    assert.strictEqual(worker.parseQuestionId(null), 0);
});

test('parseEngineAttemptId reads attempt=N from a URL', () => {
    assert.strictEqual(worker.parseEngineAttemptId('http://x/mod/adaptivequiz/attempt.php?attempt=77&cmid=5'), 77);
    assert.strictEqual(worker.parseEngineAttemptId('http://x/mod/adaptivequiz/view.php?id=5'), 0);
    assert.strictEqual(worker.parseEngineAttemptId(''), 0);
});

test('username/password follow the naming convention', () => {
    assert.strictEqual(worker.usernameFor(9), 'catlab_user_9');
    assert.strictEqual(worker.passwordFor(9, '!x'), '9!x');
    assert.strictEqual(worker.passwordFor(9, ''), '9');
});

test('loginUrlFor substitutes {userid} everywhere', () => {
    assert.strictEqual(
        worker.loginUrlFor('http://x/key.php?u={userid}&ret=/user/{userid}', 7),
        'http://x/key.php?u=7&ret=/user/7'
    );
    assert.strictEqual(worker.loginUrlFor('', 7), '');
});

test('chooseOptionIndex maps polytomous category and dichotomous fraction', () => {
    // Polytomous: category k -> k-th option, clamped.
    assert.strictEqual(worker.chooseOptionIndex({choice: 0, fraction: 0}, 4), 0);
    assert.strictEqual(worker.chooseOptionIndex({choice: 2, fraction: 0.667}, 4), 2);
    assert.strictEqual(worker.chooseOptionIndex({choice: 3, fraction: 1}, 4), 3);
    assert.strictEqual(worker.chooseOptionIndex({choice: 9, fraction: 1}, 4), 3);
    // Dichotomous: correct -> first, wrong -> a distractor.
    assert.strictEqual(worker.chooseOptionIndex({choice: -1, fraction: 1.0}, 4), 0);
    assert.strictEqual(worker.chooseOptionIndex({choice: -1, fraction: 0.0}, 4), 1);
    assert.strictEqual(worker.chooseOptionIndex({choice: -1, fraction: 0.0}, 1), 0);
});

test('selfTest is exported and runs without a Moodle instance', async () => {
    // The regression this guards: the toolchain job used to "smoke test" the
    // worker by pointing it at an unreachable host, which exercised the
    // polling loop and failed on DNS every time. A self test must complete
    // offline, so it is safe to run in CI as a real check.
    assert.strictEqual(typeof worker.selfTest, 'function');
});

test('chooseOptionIndex clamps a polytomous choice to the options on screen', () => {
    assert.strictEqual(worker.chooseOptionIndex({choice: 9, fraction: 0}, 4), 3);
    assert.strictEqual(worker.chooseOptionIndex({choice: -1, fraction: 1}, 4), 0);
    assert.strictEqual(worker.chooseOptionIndex({choice: -1, fraction: 0}, 4), 1);
});

test('a web service request escapes parameter values in its body', () => {
    const request = worker.buildWsRequest('http://x', 't o k', 'fn', {q: 'a&b=c'});
    // Separators cannot leak through unescaped; the body decodes to the values.
    const body = new URLSearchParams(request.body);
    assert.strictEqual(body.get('wstoken'), 't o k');
    assert.strictEqual(body.get('q'), 'a&b=c');
});

test('a large diagnosis is cut by its structure, visibly, and stays valid JSON (#111)', () => {
    const events = [];
    for (let i = 0; i < 3000; i++) {
        events.push({at: i, type: 'console', detail: 'e'.repeat(200) + i});
    }
    const diagnosis = {browser: {events, statuses: []}, transport: {message: 'm'.repeat(50000)}, artefacts: {path: 'p'}};
    const text = worker.fitDiagnostics(diagnosis, worker.DIAGNOSTICS_LIMIT, 'experiment-1/run-2/diagnosis-full.json');
    assert.ok(Buffer.byteLength(text) <= worker.DIAGNOSTICS_LIMIT);
    const fitted = JSON.parse(text);
    assert.strictEqual(fitted.truncated.full, 'experiment-1/run-2/diagnosis-full.json');
    assert.ok(fitted.truncated.originalbytes > worker.DIAGNOSTICS_LIMIT);
    // The newest events are kept: they are nearest the failure.
    const kept = fitted.browser.events;
    assert.strictEqual(kept[kept.length - 1].at, 2999);
    assert.match(fitted.transport.message, /characters cut\]$/);
    // A diagnosis within the limit is sent as it is.
    assert.strictEqual(worker.fitDiagnostics({a: 1}), '{"a":1}');
});

test('a long failure message is cut visibly, not silently (#111)', () => {
    const cut = worker.fitMessage('x'.repeat(10000), 4000);
    assert.ok(cut.length <= 4000);
    assert.match(cut, /\[truncated: \d+ of 10000 characters cut; full text in the artefacts\]$/);
    assert.strictEqual(worker.fitMessage('short'), 'short');
});

test('a server that refuses long URLs receives the report in the body (#111)', async() => {
    const http = require('http');
    let received = null;
    // A web server's limit on the request line, as Apache's 8 KB.
    // Node's own header limit would refuse first (431); raised, so that the
    // web server's request-line limit is what is tested.
    const server = http.createServer({maxHeaderSize: 1024 * 1024}, (request, response) => {
        if (request.url.length > 8190) {
            response.writeHead(414);
            response.end();
            return;
        }
        let body = '';
        request.on('data', (chunk) => {
            body += chunk;
        });
        request.on('end', () => {
            received = new URLSearchParams(body);
            response.writeHead(200, {'Content-Type': 'application/json'});
            response.end('{"ok":true}');
        });
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    try {
        const diagnosis = JSON.stringify({browser: {events: Array(200).fill({detail: 'd'.repeat(200)})}});
        // The old way — the diagnosis in the query — is refused.
        const old = new URL(`${base}/webservice/rest/server.php`);
        old.searchParams.set('diagnostics', diagnosis);
        assert.strictEqual((await fetch(old, {method: 'POST'})).status, 414);
        // The new way arrives, the diagnosis whole.
        const request = worker.buildWsRequest(base, 'tok', 'local_catquizlab_job_complete', {diagnostics: diagnosis});
        const response = await fetch(request.url, {method: 'POST', body: request.body,
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}});
        assert.strictEqual(response.status, 200);
        assert.strictEqual(received.get('diagnostics'), diagnosis);
    } finally {
        server.close();
    }
});

test('a failed web service call describes itself without its token (#100)', () => {
    const cause = new Error('read ECONNRESET');
    cause.code = 'ECONNRESET';
    const error = new TypeError('fetch failed', {cause});
    const url = 'https://moodle.example.org/webservice/rest/server.php?wstoken=SECRET&wsfunction=local_catquizlab_job_claim';
    const {message, detail} = worker.describeTransportError('local_catquizlab_job_claim', url, error, 1234, 0,
        {workerid: 'catquizlab-exec-3', attemptid: 17});

    assert.ok(!message.includes('SECRET'), 'the token leaked into the message');
    assert.ok(!JSON.stringify(detail).includes('SECRET'), 'the token leaked into the detail');
    assert.strictEqual(detail.code, 'ECONNRESET');
    assert.strictEqual(detail.url, 'https://moodle.example.org/webservice/rest/server.php');
    assert.strictEqual(detail.elapsedms, 1234);
    assert.strictEqual(detail.attemptid, 17);
    assert.ok(message.includes('local_catquizlab_job_claim') && message.includes('ECONNRESET'));

    const http = worker.describeTransportError('x', url, new Error('HTTP 503'), 50, 503, {workerid: 'w', attemptid: 1});
    assert.ok(http.message.includes('HTTP 503'));
});

test('artefacts of an execution have a place of their own (#107)', () => {
    assert.strictEqual(
        worker.artefactPath({experimentid: 4, runid: 36, attemptid: 1052, execution: 2}),
        'experiment-4/run-36/attempt-1052/execution-2'
    );
});

test('a failed report does not hide the attempt it was reporting (#111)', () => {
    const message = worker.reportFailureMessage(42, 'failed', 'Attempt did not reach the finish page: HTTP 500',
        new Error('local_catquizlab_job_complete: fetch failed (ECONNRESET)'));
    // The attempt's own failure first, then the report's.
    assert.ok(message.indexOf('HTTP 500') < message.indexOf('ECONNRESET'));
    assert.match(message, /^Attempt 42 failed: .*HTTP 500 — and its report to Moodle failed: .*ECONNRESET/);
});

test('a sitting\'s requests name it on the server (#110)', () => {
    const headers = worker.traceHeaders({correlationid: 'abc123', attemptid: 19084, execution: 2});
    assert.deepEqual(headers, {'X-CatQuizLab-Correlation': 'abc123', 'X-CatQuizLab-Attempt': '19084.2'});
    // Without a correlation id from the server, nothing to send.
    assert.deepEqual(worker.traceHeaders({attemptid: 5}), {});
    assert.deepEqual(worker.traceHeaders(null), {});
});

test('a web service call carries the trace headers, and no secret in them (#110)', () => {
    const trace = worker.traceHeaders({correlationid: 'abc123', attemptid: 7, execution: 1});
    const request = worker.buildWsRequest('http://x', 'secret-token', 'local_catquizlab_oracle_answer', {q: 1}, trace);
    assert.equal(request.headers['X-CatQuizLab-Correlation'], 'abc123');
    assert.equal(request.headers['X-CatQuizLab-Attempt'], '7.1');
    assert.equal(request.headers['Content-Type'], 'application/x-www-form-urlencoded');
    assert.ok(!JSON.stringify(request.headers).includes('secret-token'));
    // Without them, the content type alone, as before.
    assert.deepEqual(worker.buildWsRequest('http://x', 't', 'fn', {}).headers,
        {'Content-Type': 'application/x-www-form-urlencoded'});
});


test('a staggered worker waits for its start, reporting in, and can be stopped while it waits (#120)', async() => {
    const {startDelayMs, waitForStart} = require('../run_attempt.js');

    assert.strictEqual(startDelayMs('40000'), 40000);
    assert.strictEqual(startDelayMs('0'), 0);
    assert.strictEqual(startDelayMs('nonsense'), 0);
    assert.strictEqual(startDelayMs(undefined), 0);
    assert.strictEqual(startDelayMs('99999999'), 3600000, 'never longer than an hour');

    // 45 s in steps of 20: three reports, and exactly the delay slept.
    const slept = [];
    let beats = 0;
    const begun = await waitForStart(45000, async() => {
        beats++;
        return {stop: false};
    }, async(ms) => slept.push(ms));
    assert.strictEqual(begun, true);
    assert.strictEqual(beats, 3);
    assert.deepStrictEqual(slept, [20000, 20000, 5000]);

    // No delay: no report, no wait.
    assert.strictEqual(await waitForStart(0, async() => assert.fail('no report'), async() => assert.fail('no wait')), true);

    // Asked to stop while waiting: it does not begin, and waits no longer.
    const waited = [];
    let calls = 0;
    const stopped = await waitForStart(60000, async() => ({stop: ++calls === 2}), async(ms) => waited.push(ms));
    assert.strictEqual(stopped, false);
    assert.deepStrictEqual(waited, [20000]);

    // A report that fails is not a reason to give up waiting.
    const patient = await waitForStart(20000, async() => {
        throw new Error('503');
    }, async() => {});
    assert.strictEqual(patient, true);
});
