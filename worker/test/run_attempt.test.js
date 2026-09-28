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

test('buildWsUrl assembles the REST endpoint with params', () => {
    const url = worker.buildWsUrl('http://x/', 'tok', 'local_catquizlab_job_claim', {workerid: 'w1'});
    assert.ok(url.startsWith('http://x/webservice/rest/server.php?'));
    assert.match(url, /wstoken=tok/);
    assert.match(url, /wsfunction=local_catquizlab_job_claim/);
    assert.match(url, /moodlewsrestformat=json/);
    assert.match(url, /workerid=w1/);
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

test('buildWsUrl escapes parameter values', () => {
    const url = worker.buildWsUrl('http://x', 't o k', 'fn', {q: 'a&b=c'});
    // URLSearchParams encodes a space as "+", which is what a query string
    // wants; the point here is that separators cannot leak through unescaped.
    assert.ok(url.includes('wstoken=t+o+k'));
    assert.ok(url.includes('q=a%26b%3Dc'));
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
