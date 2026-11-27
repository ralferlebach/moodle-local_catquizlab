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
 * What one execution knows about its sitting's progress, and when it has to stop (#172, #173).
 *
 * Until 0.7.83 the answer loop ended after 1000 turns of an execution, whatever the test: a classical test of 2500
 * items could not finish, and a retry that continued at 264 stopped at 1264 (CLARA run 103). The loop now ends where
 * the sitting ends — the activity's finish page or the server's word — and stops early only on evidence:
 *
 * - its question usage changes, or its question goes backwards: not the sitting's attempt any more (identity);
 * - the same question comes back after an answer, again and again, or nothing moves for longer than the time allowed
 *   (no progress) — merely seeing the same page again is not progress;
 * - one more answer would exceed the run's pool, which no sitting can (budget: from the server, never invented).
 *
 * The counts are kept apart: the start (and where it came from), the answers confirmed, the turns of the loop, the
 * reconciliations, the last question that moved it on. They travel with the report (#176).
 *
 * Pure: no browser, no network, so every edge can be tested without either.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

/** Questions in a row that come back unanswered after an answer before the server is asked and the execution ends. */
const MAX_STALLS = 3;

/** Milliseconds without progress after which the execution ends (a single step waits 30 s at most, twice). */
const STALL_MS = 10 * 60 * 1000;

/** What a question seen means for the sitting. */
const SEEN = Object.freeze({
    PROGRESS: 'progress',
    SAME: 'same',
    BACKWARDS: 'backwards',
    OTHER_USAGE: 'otherusage',
});

/**
 * Read the server's word on a sitting as a starting point: a count, or why there is none (#173).
 *
 * No 0 in place of an answer: a server that could not be asked, a sitting without its attempt, an attempt of another
 * identity or a count that is not a non-negative whole number is said, and the caller answers nothing.
 *
 * @param {object} server sitting_bind or sitting_state.
 * @returns {{ok: boolean, count: number, state: string, reason: string}}
 */
function baselineFrom(server) {
    if (!server || typeof server !== 'object') {
        return {ok: false, count: 0, state: '', reason: 'no-answer'};
    }
    if (!server.found) {
        return {ok: false, count: 0, state: '', reason: String(server.reason || 'not-found')};
    }
    const state = String(server.state || '');
    if (state !== 'inprogress' && state !== 'complete') {
        return {ok: false, count: 0, state, reason: 'unknown-state'};
    }
    const count = server.questionsattempted;
    const slots = server.slots;
    if (!Number.isInteger(count) || count < 0 || !Number.isInteger(slots) || slots < 0 || count > slots) {
        return {ok: false, count: 0, state, reason: 'invalid-count'};
    }
    return {ok: true, count, state, reason: ''};
}

/**
 * The watch over one execution.
 */
class SittingWatch {
    /**
     * @param {object} options budget (most answers the sitting can hold; 0 = unknown), budgetkind, expected,
     *     maxstalls, stallms, now (clock, for tests).
     */
    constructor(options = {}) {
        this.budget = Number.isInteger(options.budget) && options.budget > 0 ? options.budget : 0;
        this.budgetkind = this.budget > 0 ? String(options.budgetkind || 'pool') : 'unknown';
        this.expected = Number.isInteger(options.expected) && options.expected > 0 ? options.expected : 0;
        this.maxstalls = options.maxstalls || MAX_STALLS;
        this.stallms = options.stallms || STALL_MS;
        this.now = options.now || Date.now;
        this.baseline = null;
        this.baselinesource = '';
        this.qubaid = 0;
        this.engineattemptid = 0;
        this.answered = 0;
        this.actions = 0;
        this.reconciliations = 0;
        this.stalls = 0;
        this.laststalls = 0;
        this.lastslot = 0;
        this.lastprogressat = this.now();
        this.pending = false;
        this.expectsame = false;
        this.serverend = null;
    }

    /**
     * Where the execution starts: the engine's count and identity, as the server gave them.
     *
     * @param {object} start count (confirmed answers), qubaid, engineattemptid, slot (the question shown), source.
     */
    begin(start) {
        this.baseline = start.count;
        this.baselinesource = start.source || 'server';
        this.answered = start.count;
        this.qubaid = start.qubaid;
        this.engineattemptid = start.engineattemptid || 0;
        // The question shown is the next one: seeing it is the start, not progress.
        this.lastslot = Math.max(0, (start.slot || 0) - 1);
        this.lastprogressat = this.now();
    }

    /**
     * A question is shown: what it means.
     *
     * @param {number} qubaid Its usage.
     * @param {number} slot Its slot.
     * @returns {string} One of SEEN.
     */
    see(qubaid, slot) {
        this.actions++;
        if (this.qubaid > 0 && qubaid !== this.qubaid) {
            return SEEN.OTHER_USAGE;
        }
        if (slot > this.lastslot) {
            if (this.pending) {
                // The answer sent before this question was stored: the engine moved on.
                this.answered++;
                this.pending = false;
            }
            this.lastslot = slot;
            this.stalls = 0;
            this.expectsame = false;
            this.lastprogressat = this.now();
            return SEEN.PROGRESS;
        }
        if (slot === this.lastslot) {
            if (this.expectsame) {
                // Answered again after the server said the answer was not stored: as expected, not a stall.
                this.expectsame = false;
                return SEEN.PROGRESS;
            }
            // The same question back after an answer: the answer did not count.
            if (this.pending) {
                this.pending = false;
                this.stalls++;
                this.laststalls = this.stalls;
            }
            return SEEN.SAME;
        }
        return SEEN.BACKWARDS;
    }

    /** An answer was sent and a page came back: counted once the next question or the finish shows it moved on. */
    sent() {
        this.pending = true;
    }

    /** The finish page after an answer: that answer was the last. */
    finished() {
        if (this.pending) {
            this.answered++;
            this.pending = false;
        }
    }

    /**
     * An uncertain submission, reconciled with the server (#134).
     *
     * @param {string} verdict stored, notstored or storedlate.
     */
    reconciled(verdict) {
        this.reconciliations++;
        this.pending = false;
        if (verdict === 'stored' || verdict === 'storedlate') {
            this.answered++;
        } else if (verdict === 'notstored') {
            this.expectsame = true;
        }
    }

    /**
     * Whether the execution has to ask the server before going on, and why.
     *
     * @returns {string} '' to go on; 'stall' (the same question too often, or nothing moved for too long);
     *     'budget' (the next answer would be one more than the sitting can hold).
     */
    check() {
        if (this.stalls >= this.maxstalls || this.now() - this.lastprogressat > this.stallms) {
            return 'stall';
        }
        if (this.budget > 0 && this.answered >= this.budget) {
            return 'budget';
        }
        return '';
    }

    /**
     * What the report carries about this execution's progress (#176).
     *
     * @returns {object}
     */
    summary() {
        return {
            baseline: this.baseline,
            baselinesource: this.baselinesource,
            answered: this.answered,
            actions: this.actions,
            reconciliations: this.reconciliations,
            stalls: this.laststalls,
            lastslot: this.lastslot,
            qubaid: this.qubaid,
            engineattemptid: this.engineattemptid,
            budget: this.budget,
            budgetkind: this.budgetkind,
            expected: this.expected,
            lastprogressat: new Date(this.lastprogressat).toISOString(),
            serverend: this.serverend,
        };
    }
}

module.exports = {
    MAX_STALLS,
    STALL_MS,
    SEEN,
    SittingWatch,
    baselineFrom,
};
