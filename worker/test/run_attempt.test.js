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
