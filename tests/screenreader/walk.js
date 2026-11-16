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
 * Walking the filter bar with the keyboard while a screen reader listens (tests/screenreader/filterbar.spec.js).
 *
 * The keys go to the screen reader, which passes them to the browser as a user's keystrokes would. After every key
 * the walk asks the page which control has the focus and keeps what the reader said for it. The reader only needs
 * press(), spokenPhraseLog() — the Guidepup interface of NVDA and VoiceOver.
 */

const BAR = '[data-region="catquizlab-filter-bar"]';
const CONTROLS = 'select, input:not([type="hidden"]), button, a[href]';
const START = 'catquizlab-screenreader-start';

/**
 * Puts a focusable start point right before the bar, so the first key of a walk lands on the bar's first control.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} bar The bar's selector (the results page's filter bar unless given).
 */
async function addStart(page, bar = BAR) {
    await page.evaluate(([selector, id]) => {
        const form = document.querySelector(selector);
        document.getElementById(id)?.remove();
        const start = document.createElement('span');
        start.id = id;
        start.tabIndex = 0;
        start.textContent = 'Start';
        form.parentNode.insertBefore(start, form);
    }, [bar, START]);
}

/**
 * Which of the bar's controls has the focus: its position in the bar, or -1 when the focus is elsewhere.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} bar The bar's selector.
 * @param {string} controls The selector of its controls.
 * @returns {Promise<number>}
 */
async function focused(page, bar = BAR, controls = CONTROLS) {
    return page.evaluate(([barselector, selector]) => {
        const form = document.querySelector(barselector);
        const all = Array.from(form.querySelectorAll(selector)).filter((el) => el.offsetParent !== null);
        return all.indexOf(document.activeElement);
    }, [bar, controls]);
}

/**
 * Waits until the reader has stopped speaking: its log has not grown for half a second (at most five seconds).
 *
 * @param {{spokenPhraseLog: function(): Promise<string[]>}} reader The screen reader.
 * @returns {Promise<string[]>} The log.
 */
async function settled(reader) {
    let log = await reader.spokenPhraseLog();
    for (let waited = 0, quiet = 0; waited < 5000 && quiet < 2; waited += 250) {
        await new Promise((resolve) => setTimeout(resolve, 250));
        const now = await reader.spokenPhraseLog();
        quiet = now.length === log.length ? quiet + 1 : 0;
        log = now;
    }
    return log;
}

/**
 * What the reader says while an action runs: the part of its log the action added, once it has stopped speaking.
 *
 * @param {object} reader The screen reader.
 * @param {function(): Promise<void>} action What to do.
 * @returns {Promise<string>}
 */
async function heard(reader, action) {
    const before = (await settled(reader)).length;
    await action();
    return (await settled(reader)).slice(before).join(' ');
}

/**
 * Where the keyboard focus is: whether the page has it at all (the browser window in front), and whether it is still
 * on the start point.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @returns {Promise<{page: boolean, start: boolean, element: string}>}
 */
async function focusState(page) {
    return page.evaluate((id) => ({
        page: document.hasFocus(),
        start: !!document.activeElement && document.activeElement.id === id,
        element: document.activeElement ? (document.activeElement.tagName.toLowerCase()
            + (document.activeElement.id ? '#' + document.activeElement.id : '')) : '',
    }), START);
}

/**
 * Brings the browser window to the front again and the reader back into the page, with the focus on the start point.
 *
 * On a Windows runner another window can take the foreground in the middle of a run (GitHub, NVDA, moodle53,
 * 2026-11-16): the reader's keys then go to that window, the page never sees them, and every control looks
 * unreachable although the page is fine.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {object} reader The screen reader.
 */
async function refocus(page, reader) {
    await page.bringToFront();
    if (typeof reader.navigateToWebContent === 'function') {
        await reader.navigateToWebContent();
    }
    await page.focus('#' + START);
    await settled(reader);
}

/**
 * Walks the bar from the start point, once per key until every control has been reached.
 *
 * A walk ends when the focus leaves the bar after having entered it. VoiceOver walks with Tab first and then with
 * Option+Tab, the macOS key that also reaches links and buttons when "keyboard navigation" is off.
 *
 * A key that does not arrive in the page at all (the page lost the window focus, or the focus is still on the start
 * point afterwards) is not taken as "this control cannot be reached": the window is brought back to the front and the
 * key pressed again, at most three times per key. How often that was needed is part of the result; the verdict on the
 * controls stays the same.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {object} reader The screen reader (press, spokenPhraseLog).
 * @param {string[]} keys The keys, in the order they are tried.
 * @param {number} count How many controls the bar has.
 * @param {string} bar The bar's selector.
 * @param {string} controls The selector of its controls.
 * @returns {Promise<{heard: Array<?string>, keys: Array<?string>, order: number[], refocused: Array<object>}>} Per
 *     control what was said and with which key it was reached; the order in which the first key reached them; and
 *     every time the window had to be brought to the front again, with where the focus was.
 */
async function walk(page, reader, keys, count, bar = BAR, controls = CONTROLS) {
    const heard = new Array(count).fill(null);
    const reachedwith = new Array(count).fill(null);
    const order = [];
    const refocused = [];
    for (const key of keys) {
        if (heard.every((said) => said !== null)) {
            break;
        }
        await page.focus('#' + START);
        await settled(reader);
        let entered = false;
        let retries = 0;
        for (let i = 0; i < count + 3; i++) {
            const before = (await reader.spokenPhraseLog()).length;
            await reader.press(key, {capture: true});
            const said = (await settled(reader)).slice(before).join(' ');
            const at = await focused(page, bar, controls);
            if (at < 0 && !entered && retries < 3) {
                const state = await focusState(page);
                if (!state.page || state.start) {
                    refocused.push({key: key, step: i, state: state});
                    retries++;
                    await refocus(page, reader);
                    i--;
                    continue;
                }
            }
            if (at < 0) {
                if (entered) {
                    break;
                }
                continue;
            }
            entered = true;
            if (key === keys[0]) {
                order.push(at);
            }
            if (heard[at] === null) {
                heard[at] = said;
                reachedwith[at] = key;
            }
        }
    }
    return {heard: heard, keys: reachedwith, order: order, refocused: refocused};
}

/**
 * A phrase as compared: lower case, punctuation as spaces, single spaces.
 *
 * @param {?string} text The text.
 * @returns {string}
 */
function norm(text) {
    return (text || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
}

module.exports = {BAR, CONTROLS, addStart, focused, settled, heard, walk, norm};
