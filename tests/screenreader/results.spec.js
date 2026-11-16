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
 * The results pages with a real screen reader: NVDA on Windows, VoiceOver on macOS.
 *
 * - The filter bar (#122 DoD 3): walked with the keyboard, every control must be announced with its name, its role
 *   and — for the six drop-downs — the value it shows.
 * - A scatter plot's point markers (#143 AK7, #144 AK9): the Tab key reaches the plot's first marker, the screen
 *   reader's own navigation the next one; each must be announced as a graphic with what it stands for.
 * - Since 0.7.65 the library's search and sort and the experiment selector (#153 AK8), and the experiment editor's
 *   item model with the three log-normal fields (#159 DoD5): walked like the filter bar.
 *
 * The pages are the ones the Linux interface run recorded (tests/playwright/screenreader_snapshot.spec.js): results.har
 * with everything the browser fetched — HTML, stylesheets, Moodle's scripts and requests — replayed here, so the pages
 * run as they did there, scripts included, without a Moodle behind them. What the reader said is kept as a file
 * (artifacts/<reader>-*.json) whether a test passes or not.
 *
 * SR=nvda (Chromium) or SR=voiceover (WebKit). JAWS has no automation interface Guidepup can drive and is not tested.
 */

const fs = require('fs');
const path = require('path');
const {test, expect} = require('@playwright/test');
const {nvdaTest, voiceOverTest} = require('@guidepup/playwright');
const {BAR, addStart, focused, settled, heard, walk, norm} = require('./walk');

const SR = process.env.SR;
if (SR !== 'nvda' && SR !== 'voiceover') {
    throw new Error('Set SR=nvda (Windows) or SR=voiceover (macOS).');
}
const DIR = process.env.SR_SNAPSHOT_DIR || path.join(__dirname, 'snapshot');
const HAR = path.join(DIR, 'results.har');
const META = JSON.parse(fs.readFileSync(path.join(DIR, 'results-pages.json'), 'utf8'));
const ORIGIN = new URL(META.filterbar.url).origin;
/** @type {RegExp} Every request to the recorded site; it is answered from the recording or not at all. */
const SITE = new RegExp('^' + ORIGIN.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/');
const OUT = path.join(__dirname, 'artifacts');
const POINT = 'circle[data-region="point"]';

/** How each reader says a role, as compared (norm()). */
const ROLES = {
    nvda: {combobox: /\bcombo ?box\b/, listbox: /\blist\b/, button: /\bbutton\b/, link: /\blink\b/,
        textbox: /\bedit\b/, searchbox: /\bedit\b|\bsearch\b/, checkbox: /\bcheck ?box\b/, radio: /\bradio button\b/,
        img: /\bgraphic\b/},
    voiceover: {combobox: /\bpop ?up button\b|\bmenu button\b|\bcombo ?box\b/, listbox: /\blist ?box\b|\btable\b/,
        button: /\bbutton\b/, link: /\blink\b/, textbox: /\btext field\b|\bedit text\b/,
        searchbox: /\bsearch text field\b|\btext field\b/, checkbox: /\bcheck ?box\b/, radio: /\bradio button\b/,
        img: /\bimage\b/},
};

/**
 * The words a text is recognised by: its first three, as compared.
 *
 * @param {string} text The text.
 * @returns {string}
 */
function words(text) {
    return norm(text).split(' ').slice(0, 3).join(' ');
}

/**
 * Opens a recorded page: every request of the page's site is answered from the recording, none goes out.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} url The page's URL, as recorded.
 */
async function open(page, url) {
    await page.context().routeFromHAR(HAR, {url: SITE, notFound: 'abort'});
    await page.goto(url, {waitUntil: 'load'});
}

/**
 * The plot's markers.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @returns {import('@playwright/test').Locator}
 */
function markers(page) {
    return page.locator('[data-region="catquizlab-mode-plot"]').nth(META.plot.index).locator('svg ' + POINT);
}

/**
 * Focuses whatever comes before the plot's first marker in the tab order, so that the next Tab lands on the marker.
 *
 * @param {import('@playwright/test').Page} page The page.
 */
async function beforePlot(page) {
    await page.evaluate((first) => {
        const tabbable = Array.from(document.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'))
            .filter((el) => el.tabIndex >= 0 && !el.disabled && el.getClientRects().length > 0);
        tabbable[tabbable.indexOf(first) - 1].focus();
    }, await markers(page).first().elementHandle());
}

/**
 * Which of the plot's markers has the focus, or -1.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @returns {Promise<number>}
 */
async function focusedMarker(page) {
    return markers(page).evaluateAll((cs) => cs.indexOf(document.activeElement));
}

/**
 * Keeps what a reader said, as a file and with the test's results.
 *
 * @param {import('@playwright/test').TestInfo} info The test's info.
 * @param {string} name The file's name.
 * @param {object} record What to keep.
 */
async function keep(info, name, record) {
    fs.mkdirSync(OUT, {recursive: true});
    const file = SR + '-' + name + (info.retry ? '-retry' + info.retry : '') + '.json';
    fs.writeFileSync(path.join(OUT, file), JSON.stringify(record, null, 2));
    await info.attach(file, {body: JSON.stringify(record, null, 2), contentType: 'application/json'});
}

test('the recorded pages give the screen reader names, roles and values', async({page}) => {
    // What the browser exposes, before a screen reader is asked: a failure here is the page's, not the reader's.
    await open(page, META.filterbar.url);
    const bar = page.locator(BAR);
    await expect(bar).toBeVisible();
    for (const control of META.filterbar.controls) {
        const found = bar.getByRole(control.role, {name: control.name, exact: true});
        await expect(found, control.role + ' "' + control.name + '"').toHaveCount(1);
        await expect(found).toBeVisible();
        if (control.selected !== '') {
            expect(await found.evaluate((el) => el.options[el.selectedIndex].text.replace(/\s+/g, ' ').trim()))
                .toBe(control.selected);
        }
    }

    // The plot, with Moodle's scripts running as recorded: markers named, one tab stop, the arrow keys within.
    await open(page, META.plot.url);
    await expect(page.locator('html')).toHaveAttribute('data-catquizlab-plotkeys', 'ready');
    const points = markers(page);
    await expect(points).toHaveCount(META.plot.markers.length);
    for (const [i, marker] of META.plot.markers.entries()) {
        await expect(points.nth(i)).toHaveAttribute('role', 'img');
        await expect(points.nth(i)).toHaveAttribute('aria-label', marker.label);
    }
    await beforePlot(page);
    await page.keyboard.press('Tab');
    expect(await focusedMarker(page), 'Tab reaches the first marker').toBe(0);
    await page.keyboard.press('ArrowRight');
    expect(await focusedMarker(page), 'the arrow key the next').toBe(1);

    // The other groups (#153 AK8, #159 DoD5): every control there, by its role and name, as often as recorded.
    for (const group of META.groups || []) {
        await open(page, group.url);
        const bar = page.locator(group.bar).first();
        await expect(bar, group.key).toBeVisible();
        const counted = {};
        for (const control of group.controls) {
            const key = control.role + '\u0000' + control.name;
            counted[key] = (counted[key] || 0) + 1;
        }
        for (const control of group.controls) {
            const found = bar.getByRole(control.role, {name: control.name, exact: true});
            await expect(found, group.key + ': ' + control.role + ' "' + control.name + '"')
                .toHaveCount(counted[control.role + '\u0000' + control.name]);
            if (control.id !== '' && control.selected !== '') {
                const shown = await page.locator('#' + control.id).evaluate((el) => (el.tagName === 'SELECT'
                    ? el.options[el.selectedIndex].text : el.value).replace(/\s+/g, ' ').trim());
                expect(shown, group.key + ': the value of ' + control.id).toBe(control.selected);
            }
        }
    }
});

/**
 * One recorded group of controls with the screen reader: every control reached by the keyboard, in the page's order,
 * and announced with its name, its role and the value it shows (#153 AK8, #159 DoD5).
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {object} reader NVDA or VoiceOver (Guidepup).
 * @param {string[]} keys The keys of the walk.
 * @param {object} group The recorded group (key, url, bar, selector, controls).
 * @param {import('@playwright/test').TestInfo} info The test's info.
 */
async function walkGroup(page, reader, keys, group, info) {
    const controls = group.controls;
    await open(page, group.url);
    await addStart(page, group.bar);
    await reader.navigateToWebContent();
    const result = await walk(page, reader, keys, controls.length, group.bar, group.selector);
    await keep(info, group.key, {
        reader: SR, keys: keys, order: result.order, url: group.url, refocused: result.refocused,
        controls: controls.map((control, i) => Object.assign({}, control, {key: result.keys[i], heard: result.heard[i]})),
        log: await reader.spokenPhraseLog(),
    });

    const missing = controls.filter((control, i) => result.heard[i] === null).map((control) => control.name);
    expect(missing, group.key + ': controls the keyboard did not reach with ' + keys.join(' / ')
        + (result.refocused.length ? ' (window brought back ' + result.refocused.length + ' time(s): '
            + JSON.stringify(result.refocused) + ')' : '')).toEqual([]);
    expect(result.order, group.key + ': the keyboard goes through in the page\'s order')
        .toEqual([...result.order].sort((a, b) => a - b));
    controls.forEach((control, i) => {
        const said = norm(result.heard[i]);
        const what = SR + ' on ' + control.role + ' "' + control.name + '" said "' + result.heard[i] + '"';
        expect.soft(said, what + ': the name').toContain(words(control.name));
        expect.soft(said, what + ': the role').toMatch(ROLES[SR][control.role]);
        if (control.selected !== '') {
            expect.soft(said, what + ': the value shown').toContain(words(control.selected));
        }
    });
}

/**
 * The filter bar with the screen reader.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {object} reader NVDA or VoiceOver (Guidepup).
 * @param {string[]} keys The keys of the walk.
 * @param {import('@playwright/test').TestInfo} info The test's info.
 */
async function filterbar(page, reader, keys, info) {
    const controls = META.filterbar.controls;
    await open(page, META.filterbar.url);
    await addStart(page);
    await reader.navigateToWebContent();
    const result = await walk(page, reader, keys, controls.length);
    await keep(info, 'filterbar', {
        reader: SR, keys: keys, order: result.order, refocused: result.refocused,
        controls: controls.map((control, i) => Object.assign({}, control, {key: result.keys[i], heard: result.heard[i]})),
        log: await reader.spokenPhraseLog(),
    });

    const missing = controls.filter((control, i) => result.heard[i] === null).map((control) => control.name);
    expect(missing, 'controls the keyboard did not reach with ' + keys.join(' / ')
        + (result.refocused.length ? ' (window brought back ' + result.refocused.length + ' time(s): '
            + JSON.stringify(result.refocused) + ')' : '')).toEqual([]);
    expect(result.order, 'the keyboard goes through the bar in its order').toEqual([...result.order].sort((a, b) => a - b));
    controls.forEach((control, i) => {
        const said = norm(result.heard[i]);
        const what = SR + ' on ' + control.role + ' "' + control.name + '" said "' + result.heard[i] + '"';
        expect.soft(said, what + ': the name').toContain(words(control.name));
        expect.soft(said, what + ': the role').toMatch(ROLES[SR][control.role]);
        if (control.selected !== '') {
            expect.soft(said, what + ': the value shown').toContain(words(control.selected));
        }
    });
}

/**
 * A word of one marker's description that the other's lacks: how the two announcements are told apart.
 *
 * @param {string} own The marker's description.
 * @param {string} other The other marker's description.
 * @returns {string}
 */
function distinct(own, other) {
    const theirs = new Set(norm(other).split(' '));
    return norm(own).split(' ').find((w) => /\p{L}/u.test(w) && w.length > 1 && !theirs.has(w)) || '';
}

/**
 * The plot's markers with the screen reader.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {object} reader NVDA or VoiceOver (Guidepup).
 * @param {string[]} keys The keys that may reach the plot.
 * @param {Array<function(): Promise<void>>} nexts The reader's own ways to the next graphic, tried in order.
 * @param {import('@playwright/test').TestInfo} info The test's info.
 */
async function plot(page, reader, keys, nexts, info) {
    const [first, second] = META.plot.markers;
    await open(page, META.plot.url);
    await expect(page.locator('html')).toHaveAttribute('data-catquizlab-plotkeys', 'ready');
    await reader.navigateToWebContent();

    // Into the plot with the keyboard.
    let entered = '';
    let key = null;
    for (const candidate of keys) {
        await beforePlot(page);
        await settled(reader);
        const said = await heard(reader, () => reader.press(candidate, {capture: true}));
        if (await focusedMarker(page) === 0) {
            entered = said;
            key = candidate;
            break;
        }
    }
    // On to the next marker with the screen reader's own navigation.
    const want = distinct(second.label, first.label);
    let next = '';
    let way = -1;
    for (let i = 0; i < nexts.length && way < 0; i++) {
        const said = await heard(reader, nexts[i]);
        next += (next ? ' | ' : '') + said;
        if (norm(said).includes(want)) {
            way = i;
        }
    }
    await keep(info, 'plot', {
        reader: SR, title: META.plot.title, key: key, entered: entered, next: next, way: way, want: want,
        markers: META.plot.markers, log: await reader.spokenPhraseLog(),
    });

    expect(key, 'the plot was reached with ' + keys.join(' / ')).not.toBeNull();
    const said = norm(entered);
    const what = SR + ' on the first marker said "' + entered + '"';
    expect.soft(said, what + ': it is a graphic').toMatch(ROLES[SR].img);
    expect.soft(said, what + ': how many, where').toContain(words(first.label));
    expect.soft(said, what + ': whose').toMatch(/\btwin\b/);
    expect.soft(said, what + ': its own').toContain(distinct(first.label, second.label));
    expect(way, SR + ' reached the second marker (' + want + '); it said "' + next + '"').toBeGreaterThanOrEqual(0);
}

for (const group of META.groups || []) {
    const title = {
        library: 'the library\'s search and sort are announced with names, roles and values (#153 AK8)',
        selector: 'the experiment selector is announced with its name, role and value (#153 AK8)',
        lognormal: 'the item model with the three log-normal fields is announced (#159 DoD5)',
    }[group.key] || ('the group ' + group.key + ' is announced with names, roles and values');
    if (SR === 'voiceover') {
        voiceOverTest(title, async({page, voiceOver}, info) => {
            await walkGroup(page, voiceOver, ['Tab', 'Alt+Tab'], group, info);
        });
    } else {
        nvdaTest(title, async({page, nvda}, info) => {
            await walkGroup(page, nvda, ['Tab'], group, info);
        });
    }
}

if (SR === 'voiceover') {
    voiceOverTest('the filter bar is announced with names, roles and values', async({page, voiceOver}, info) => {
        await filterbar(page, voiceOver, ['Tab', 'Alt+Tab'], info);
    });
    voiceOverTest('the plot\'s markers are announced with what they stand for', async({page, voiceOver}, info) => {
        await plot(page, voiceOver, ['Tab', 'Alt+Tab'], [
            () => voiceOver.next({capture: true}),
            () => voiceOver.perform(voiceOver.keyboardCommands.findNextGraphic, {capture: true}),
        ], info);
    });
} else {
    nvdaTest('the filter bar is announced with names, roles and values', async({page, nvda}, info) => {
        await filterbar(page, nvda, ['Tab'], info);
    });
    nvdaTest('the plot\'s markers are announced with what they stand for', async({page, nvda}, info) => {
        await plot(page, nvda, ['Tab'], [
            () => nvda.perform(nvda.keyboardCommands.moveToNextGraphic, {capture: true}),
            () => nvda.next({capture: true}),
        ], info);
    });
}
