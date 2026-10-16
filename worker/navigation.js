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
 * Navigation that cannot race, and a record of what the browser saw (#100, #107).
 *
 * The worker used to click and then start waiting for the navigation the click
 * caused. On a fast page the navigation was over before anybody waited for it:
 * the wait ran into its timeout, which was swallowed, and the next DOM read met
 * a page being replaced — "Execution context was destroyed". Under load that is
 * not a rare race but the normal case, and runs were stopped by the circuit
 * breaker after ten identical failures.
 *
 * @module     local_catquizlab/worker/navigation
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const fs = require('fs');
const path = require('path');

/** The states a click in an attempt may lead to — and nothing else. */
const STATE = Object.freeze({QUESTION: 'question', FINISH: 'finish', ERROR: 'error'});

/** How many browser events an attempt keeps. */
const MAX_EVENTS = 60;

/** How many response statuses an attempt keeps. */
const MAX_STATUSES = 20;

/** How many submissions an attempt keeps in full (#134); all are counted. */
const MAX_SUBMISSIONS = 40;

/**
 * Whether an error means the page was replaced under the script.
 *
 * @param {*} error What was thrown.
 * @returns {boolean}
 */
function isContextLost(error) {
    const message = String((error && error.message) || error || '');
    return /Execution context was destroyed|Cannot find context with specified id|Execution context is not available|detached Frame|frame got detached/i
        .test(message);
}

/**
 * A URL without the secrets it may carry.
 *
 * @param {string} url The URL.
 * @returns {string}
 */
function sanitiseUrl(url) {
    return String(url || '').replace(/([?&](?:wstoken|token|sesskey|password|key)=)[^&#\s]*/gi, '$1[redacted]');
}

/**
 * A page snapshot without the secrets Moodle embeds in every page.
 *
 * @param {string} html The snapshot.
 * @returns {string}
 */
function sanitiseHtml(html) {
    return sanitiseUrl(html)
        .replace(/("sesskey"\s*:\s*")[^"]*(")/gi, '$1[redacted]$2')
        .replace(/(name=["']sesskey["'][^>]*value=["'])[^"']*(["'])/gi, '$1[redacted]$2')
        .replace(/(value=["'])[^"']*(["'][^>]*name=["']sesskey["'])/gi, '$1[redacted]$2')
        .replace(/(name=["'](?:password|logintoken)["'][^>]*value=["'])[^"']*(["'])/gi, '$1[redacted]$2');
}

/**
 * The state of the page, from inside the page.
 *
 * Error first — a Moodle error page may still contain a question's markup —
 * then the finish page, then a question. On the document that was there before
 * the click — still carrying its marker — nothing counts: a question read
 * there is the question just answered, and answering it again is how an
 * attempt goes out of sequence.
 *
 * @param {string[]} questionSelectors Selectors that mark a presented question.
 * @param {?string} marker The marker of the document to disregard.
 * @returns {string} One of STATE, or '' while none applies yet.
 */
function stateInPage(questionSelectors, marker) {
    if (marker && window.__catquizlabMarker === marker) {
        return '';
    }
    const title = document.title || '';
    const errorPage = document.querySelector('.errorbox, [data-rel="fatalerror"], #page-error, body.path-error')
        || /^(Fehler|Error)\b/.test(title);
    if (errorPage) {
        return 'error';
    }
    const finished = location.pathname.indexOf('attemptfinished.php') !== -1
        || ['.adaptivequiz-finished', '#adaptivequiz-finished', '.attempt-summary']
            .some((selector) => document.querySelector(selector) !== null);
    if (finished) {
        return 'finish';
    }
    if (questionSelectors.some((selector) => document.querySelector(selector) !== null)) {
        return 'question';
    }
    return '';
}

/**
 * Wait until the page is in exactly one of the expected states.
 *
 * @param {object} page The Puppeteer page.
 * @param {string[]} questionSelectors Selectors that mark a presented question.
 * @param {object} options label, timeout, recorder.
 * @returns {Promise<string>} One of STATE.
 */
async function waitForState(page, questionSelectors, {label = 'step', timeout = 30000, recorder = null, marker = null} = {}) {
    for (let round = 0; round < 2; round++) {
        try {
            const handle = await page.waitForFunction(stateInPage, {timeout, polling: 100}, questionSelectors, marker);
            const state = await handle.jsonValue();
            if (recorder) {
                recorder.event('state', `${label}: ${state}`);
            }
            return state;
        } catch (error) {
            if (round === 0 && isContextLost(error)) {
                // The page was replaced while the check ran: expected during a
                // navigation. Let the new page load, then look once more.
                if (recorder) {
                    recorder.event('context-lost', `${label}: ${error.message}`);
                }
                await page.waitForNavigation({waitUntil: 'domcontentloaded', timeout: 5000}).catch(() => null);
                continue;
            }
            throw new Error(`No expected state (question, finish page or error page) within ${timeout} ms after `
                + `${label}; url=${sanitiseUrl(page.url())}: ${error.message}`);
        }
    }
    throw new Error(`No expected state after ${label}.`);
}

/**
 * What a submission ended in (#134): one class per cause, never two causes in one.
 *
 * OK and REDIRECT are successes; everything else is not, and says why. A 303
 * is Moodle's ordinary answer to a POST and counts as success only where it led
 * to the next question or the finish page; a redirect elsewhere is its own case.
 */
const SUBMIT = Object.freeze({
    OK: 'ok',
    REDIRECT: 'redirect',
    UNEXPECTED_REDIRECT: 'unexpected_redirect',
    HTTP_404: 'http_404',
    HTTP_4XX: 'http_4xx',
    HTTP_5XX: 'http_5xx',
    CONNECT_TIMEOUT: 'connect_timeout',
    CONNECT_FAILED: 'connect_failed',
    CDP_TIMEOUT: 'cdp_timeout',
    MOODLE_ERROR_PAGE: 'moodle_error_page',
    NO_STATE: 'no_state',
});

/** The classes after which the answer may or may not have been stored: ask the server before going on. */
const UNCERTAIN = Object.freeze([SUBMIT.UNEXPECTED_REDIRECT, SUBMIT.HTTP_404, SUBMIT.HTTP_4XX, SUBMIT.HTTP_5XX,
    SUBMIT.CONNECT_TIMEOUT, SUBMIT.CONNECT_FAILED, SUBMIT.CDP_TIMEOUT, SUBMIT.NO_STATE]);

/** Where a submission may legitimately be redirected: the attempt, its finish page, its review. */
const EXPECTED_TARGETS = /\/mod\/adaptivequiz\/(attempt|attemptfinished|review)\.php/;

/**
 * The class of a submission from what the browser saw (#134).
 *
 * The HTTP status decides before the page does: a 404 or 500 answered with
 * Moodle's error page is a 404 or 500, not "an error page" — the page used to
 * hide the status it came with.
 *
 * @param {object} record chain (main-frame navigation responses: status, url, location, failure), state, error.
 * @returns {string} One of SUBMIT.
 */
function classifySubmit(record) {
    const chain = Array.isArray(record.chain) ? record.chain : [];
    const failure = chain.map((hop) => hop.failure || '').find((text) => text !== '') || '';
    if (failure) {
        return /TIMED_OUT/i.test(failure) ? SUBMIT.CONNECT_TIMEOUT : SUBMIT.CONNECT_FAILED;
    }
    const statuses = chain.map((hop) => Number(hop.status) || 0);
    if (statuses.some((status) => status >= 500)) {
        return SUBMIT.HTTP_5XX;
    }
    if (statuses.includes(404)) {
        return SUBMIT.HTTP_404;
    }
    if (statuses.some((status) => status >= 400)) {
        return SUBMIT.HTTP_4XX;
    }
    const error = String(record.error || '');
    if (record.state === STATE.ERROR) {
        return SUBMIT.MOODLE_ERROR_PAGE;
    }
    const redirected = statuses.some((status) => status >= 300 && status < 400);
    if (redirected) {
        const final = chain[chain.length - 1] || {};
        const target = String(final.url || '');
        const legitimate = EXPECTED_TARGETS.test(target)
            && (record.state === STATE.QUESTION || record.state === STATE.FINISH);
        return legitimate ? SUBMIT.REDIRECT : SUBMIT.UNEXPECTED_REDIRECT;
    }
    if (record.state === STATE.QUESTION || record.state === STATE.FINISH) {
        return SUBMIT.OK;
    }
    // No expected page. Either no response reached the browser before its own
    // wait ran out — nothing in the chain, or the navigation never completed —
    // or one did, and it is neither a question, the finish page nor an error page.
    if (chain.length === 0 || record.navigated === false || /timeout|timed out/i.test(error)) {
        return SUBMIT.CDP_TIMEOUT;
    }
    return SUBMIT.NO_STATE;
}

/**
 * What became of an uncertain submission, from the server's count (#134).
 *
 * @param {number} answered Answers the worker had confirmed before this submission.
 * @param {object} server sitting_state: found, state, questionsattempted, slots.
 * @returns {string} 'finished' (the attempt is complete), 'stored' (this answer was stored, the response lost),
 *      'notstored' (it was not: the question is to be answered again), 'missing' (no attempt on the server),
 *      'mismatch' (the server counts something the worker cannot account for).
 */
function reconcile(answered, server) {
    if (!server || !server.found) {
        return 'missing';
    }
    const stored = Number(server.questionsattempted) || 0;
    if (server.state === 'complete') {
        return stored >= answered ? 'finished' : 'mismatch';
    }
    if (stored === answered + 1) {
        return 'stored';
    }
    if (stored === answered) {
        return 'notstored';
    }
    return 'mismatch';
}

/**
 * Click and wait for the navigation it causes — atomically.
 *
 * The wait for the navigation is registered before the click, so a navigation
 * that completes quickly cannot be missed. Its outcome is not swallowed: a
 * failed navigation is recorded and, if no expected state follows, reported.
 *
 * @param {object} page The Puppeteer page.
 * @param {string[]} selectors What to click, first match wins.
 * @param {string[]} questionSelectors Selectors that mark a presented question.
 * @param {object} options label, timeout, recorder.
 * @returns {Promise<{clicked: boolean, state: ?string, navigated: boolean}>}
 */
async function clickAndSettle(page, selectors, questionSelectors,
    {label = 'click', timeout = 30000, recorder = null, context = null} = {}) {
    let handle = null;
    for (const selector of selectors) {
        handle = await page.$(selector);
        if (handle) {
            break;
        }
    }
    if (!handle) {
        return {clicked: false, state: null, navigated: false};
    }

    // Mark the document the click is made on: the state that follows must be
    // read on another one, whatever route the navigation takes to get there —
    // one redirect, or an intermediate page that forwards on its own.
    const marker = `m${Date.now()}${Math.random().toString(36).slice(2)}`;
    await page.evaluate((value) => {
        window.__catquizlabMarker = value;
    }, marker);

    if (recorder) {
        recorder.event('click', label);
    }
    // Every main-frame navigation the click leads to, with its status, its
    // redirect target and, where the connection failed, why (#134).
    const started = Date.now();
    const chain = [];
    const onResponse = (response) => {
        const request = response.request();
        if (request.frame() !== page.mainFrame() || !request.isNavigationRequest()) {
            return;
        }
        for (const hop of request.redirectChain()) {
            const hopResponse = hop.response();
            chain.push({method: hop.method(), status: hopResponse ? hopResponse.status() : 0,
                url: sanitiseUrl(hop.url()), location: sanitiseUrl(hopResponse ? hopResponse.headers().location || '' : '')});
        }
        chain.push({method: request.method(), status: response.status(), url: sanitiseUrl(response.url()), location: ''});
    };
    const onFailed = (request) => {
        if (request.frame() === page.mainFrame() && request.isNavigationRequest()) {
            const failure = request.failure();
            chain.push({method: request.method(), status: 0, url: sanitiseUrl(request.url()), location: '',
                failure: failure ? failure.errorText : 'failed'});
        }
    };
    page.on('response', onResponse);
    page.on('requestfailed', onFailed);
    let navigated = null;
    const submission = (state, error) => {
        const record = {label, context, chain: chain.slice(0, 8), state, navigated,
            error: error ? String(error.message || error) : '', ms: Date.now() - started};
        record.class = classifySubmit(record);
        return record;
    };
    const [navigation, click] = await Promise.allSettled([
        page.waitForNavigation({waitUntil: 'domcontentloaded', timeout}),
        handle.click(),
    ]);
    if (click.status === 'rejected' && !isContextLost(click.reason)) {
        throw new Error(`Click on ${label} failed: ${click.reason.message}`);
    }
    navigated = navigation.status === 'fulfilled';
    if (!navigated && recorder) {
        recorder.event('navigation-failed', `${label}: ${navigation.reason && navigation.reason.message}`);
    }

    try {
        // A navigation whose request failed — connection reset, refused, timed
        // out — leads to no page worth waiting for: said at once, not after the
        // full timeout on the browser's own error page (#134).
        const failedhop = chain.find((hop) => hop.failure);
        if (failedhop) {
            throw new Error(`The request failed: ${failedhop.method} ${failedhop.url}: ${failedhop.failure}`);
        }
        const state = await waitForState(page, questionSelectors, {label, timeout, recorder, marker});
        const submit = submission(state, navigated ? null : navigation.reason);
        if (recorder) {
            recorder.submission(submit);
        }
        return {clicked: true, state, navigated, submit};
    } catch (error) {
        const why = navigated ? '' : ` Navigation after ${label} did not complete: ${navigation.reason && navigation.reason.message}.`;
        const submit = submission(null, error);
        if (recorder) {
            recorder.submission(submit);
        }
        const failed = new Error(`[submit:${submit.class}] ` + error.message + why);
        failed.submit = submit;
        throw failed;
    } finally {
        page.off('response', onResponse);
        page.off('requestfailed', onFailed);
    }
}

/**
 * One line for a submission: class, statuses, redirect target, where in the attempt.
 *
 * @param {object} submit A submission record.
 * @returns {string}
 */
function describeSubmit(submit) {
    if (!submit) {
        return '';
    }
    const hops = (submit.chain || []).map((hop) => `${hop.method} ${hop.status || hop.failure}`
        + (hop.location ? ` → ${hop.location}` : '')).join(', ');
    const where = submit.context ? ` usage=${submit.context.qubaid} slot=${submit.context.slot}`
        + ` answered=${submit.context.answered}` : '';
    return `[submit:${submit.class}] ${submit.label}:${where} chain=[${hops}] in ${submit.ms} ms`;
}

/**
 * Run a DOM read, once more if the page was replaced under it.
 *
 * Only the lost-context error is retried, and only once: anything else, or the
 * same error twice, is real and reported.
 *
 * @param {object} page The Puppeteer page.
 * @param {Function} fn The read.
 * @param {string} label What it reads, for the report.
 * @param {?object} recorder The attempt's recorder.
 * @returns {Promise<*>}
 */
async function withContextRetry(page, fn, label, recorder = null) {
    try {
        return await fn();
    } catch (error) {
        if (!isContextLost(error)) {
            throw error;
        }
        if (recorder) {
            recorder.event('context-lost', `${label}: ${error.message}`);
        }
        await page.waitForNavigation({waitUntil: 'domcontentloaded', timeout: 5000}).catch(() => null);
        try {
            return await fn();
        } catch (again) {
            throw new Error(`${label} failed after one retry: ${again.message}`);
        }
    }
}

/**
 * What the browser saw during one attempt, and the files to show for it (#107).
 */
class Recorder {
    /**
     * Listen to the page.
     *
     * @param {object} page The Puppeteer page.
     * @param {object} options capture: 'failure' (default) or 'all'.
     */
    constructor(page, {capture = 'failure'} = {}) {
        this.page = page;
        this.capture = capture;
        this.events = [];
        this.statuses = [];
        this.shots = [];
        this.submissions = [];
        this.submitcounts = {};
        this.started = Date.now();

        page.on('console', (message) => {
            if (message.type() === 'error') {
                this.event('console-error', message.text());
            }
        });
        page.on('pageerror', (error) => this.event('page-error', error.message));
        page.on('requestfailed', (request) => {
            const failure = request.failure();
            this.event('request-failed', `${request.method()} ${sanitiseUrl(request.url())}: ${failure ? failure.errorText : ''}`);
        });
        page.on('response', (response) => {
            this.statuses.push({status: response.status(), url: sanitiseUrl(response.url()), at: Date.now() - this.started});
            if (this.statuses.length > MAX_STATUSES) {
                this.statuses.shift();
            }
        });
        page.on('framenavigated', (frame) => {
            if (frame === page.mainFrame()) {
                this.event('navigated', sanitiseUrl(frame.url()));
            }
        });
    }

    /**
     * Record one event.
     *
     * @param {string} type What happened.
     * @param {string} detail More about it.
     */
    event(type, detail) {
        this.events.push({at: Date.now() - this.started, type, detail: String(detail || '').slice(0, 500)});
        if (this.events.length > MAX_EVENTS) {
            this.events.shift();
        }
    }

    /**
     * Record one submission (#134): every one is counted by class, the latest kept in full.
     *
     * @param {object} submit A submission record.
     */
    submission(submit) {
        this.submitcounts[submit.class] = (this.submitcounts[submit.class] || 0) + 1;
        this.submissions.push(submit);
        if (this.submissions.length > MAX_SUBMISSIONS) {
            this.submissions.shift();
        }
        if (submit.class !== SUBMIT.OK && submit.class !== SUBMIT.REDIRECT) {
            this.event('submit-' + submit.class, describeSubmit(submit));
        }
    }

    /**
     * Keep a screenshot of the page as it stands; the two latest are kept.
     *
     * @returns {Promise<void>}
     */
    async snapshot() {
        try {
            const image = await this.page.screenshot({type: 'jpeg', quality: 50});
            this.shots.push(image);
            if (this.shots.length > 2) {
                this.shots.shift();
            }
        } catch (error) {
            this.event('screenshot-failed', error.message);
        }
    }

    /**
     * The events and statuses, for the attempt history.
     *
     * @returns {object}
     */
    summary() {
        return {events: this.events.slice(-30), statuses: this.statuses.slice(-10),
            submits: {counts: this.submitcounts, last: this.submissions.slice(-5)}};
    }

    /**
     * Write everything to a directory: screenshots, DOM, events, error.
     *
     * @param {string} dir Where.
     * @param {object} meta Experiment, run, attempt, execution, worker, correlation.
     * @param {?Error} error What went wrong, or null for a debug capture.
     * @returns {Promise<string[]>} The files written.
     */
    async save(dir, meta, error) {
        const written = [];
        fs.mkdirSync(dir, {recursive: true});
        const write = (name, content) => {
            fs.writeFileSync(path.join(dir, name), content);
            written.push(name);
        };

        // The screenshot before the one at the failure, and the failure itself.
        const previous = this.shots.length > 0 ? this.shots[this.shots.length - 1] : null;
        if (previous) {
            write('screenshot-previous.jpg', previous);
        }
        try {
            write('screenshot-last.jpg', await this.page.screenshot({type: 'jpeg', quality: 70, fullPage: true}));
        } catch (shotError) {
            this.event('screenshot-failed', shotError.message);
        }
        try {
            write('dom.html', sanitiseHtml(await this.page.content()));
        } catch (domError) {
            this.event('dom-failed', domError.message);
        }

        let title = '';
        try {
            title = await this.page.title();
        } catch (titleError) {
            title = '';
        }
        write('events.json', JSON.stringify({events: this.events, statuses: this.statuses,
            submits: {counts: this.submitcounts, all: this.submissions}}, null, 2));
        write('error.json', JSON.stringify({
            ...meta,
            timestamp: new Date().toISOString(),
            url: sanitiseUrl(this.page.url()),
            title,
            message: error ? error.message : null,
            stack: error ? error.stack : null,
        }, null, 2));

        return written;
    }
}

module.exports = {STATE, SUBMIT, UNCERTAIN, isContextLost, sanitiseUrl, sanitiseHtml, stateInPage, waitForState,
    clickAndSettle, withContextRetry, classifySubmit, reconcile, describeSubmit, Recorder};
