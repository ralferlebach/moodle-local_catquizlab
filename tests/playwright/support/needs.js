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
 * A test's precondition: skipped on a developer's machine, a failure in a release run.
 *
 * A test that skips because its fixture is missing passes the run it skipped
 * in. In the release gate that is a green run that did not test what it
 * names (re-audit 16.10.2026, AK 3): with CATQUIZLAB_E2E_STRICT=1, which the
 * interface end-to-end workflow sets, a missing fixture fails the test with
 * the reason; locally it still skips, so a single spec can be run by hand.
 */

const {test} = require('@playwright/test');

/** @type {boolean} Whether a missing fixture fails rather than skips. */
const STRICT = process.env.CATQUIZLAB_E2E_STRICT === '1';

/**
 * Skip — or, in a strict run, fail — when a precondition is missing.
 *
 * @param {*} missing Truthy when the precondition is not met.
 * @param {string} why What is missing and how to provide it.
 */
function needs(missing, why) {
    if (!missing) {
        return;
    }
    if (STRICT) {
        throw new Error('Missing fixture in a strict end-to-end run: ' + why);
    }
    test.skip(true, why);
}

module.exports = {needs, STRICT};
