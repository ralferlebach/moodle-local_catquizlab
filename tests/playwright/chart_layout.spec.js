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
 * Plots below the fixed navigation bar at every width and zoom the issue names (#146).
 *
 * Widths 320, 375, 390, 768, 1024, 1280, 1440 and 1920 CSS pixels at 100 %; and the browser zoomed to 125 % and 200 %
 * on a 1280 × 800 window — as a browser zooms: fewer CSS pixels, more device pixels per CSS pixel. For every plot of the
 * deficits tab and the overview, scrolled so that it starts right below the bar (as a link to it or the keyboard
 * would): its top below the bar, its bottom inside the window, nothing of the bar over it, its axis titles inside it,
 * its download menu reachable by keyboard and not under the bar when focused. Every measurement — the bar's and the
 * plot's bounding boxes, the window, the zoom — is written to chart-layout.json, and a screenshot of each case beside
 * it, so the evidence is the numbers and the pictures, not only a green tick.
 *
 * Self-seeding through cli/seed_modes.php; skipped where php cannot be run here.
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

const seed = (script, args, name) => {
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/' + script), ...args], {encoding: 'utf8'});
        const match = out.match(new RegExp('^' + name + '=(\\d+)', 'm'));
        return match ? match[1] : '';
    } catch (e) {
        return '';
    }
};

/** Window sizes in CSS pixels at 100 %, and zoomed windows: 1280 × 800 device-independent, zoomed. */
const CASES = [
    ...[320, 375, 390, 768, 1024, 1280, 1440, 1920].map((width) => ({width, height: width < 768 ? 740 : 900, zoom: 1})),
    {width: 1280, height: 800, zoom: 1.25},
    {width: 1280, height: 800, zoom: 2},
];

test('every plot fits below the navigation bar at every width and zoom (#146)', async({browser}, testInfo) => {
    test.setTimeout(10 * 60 * 1000);
    const id = seed('seed_modes.php', [], 'MODES_EXPERIMENTID');
    needs(!id, 'cli/seed_modes.php could not be run here.');
    const measured = [];
    for (const c of CASES) {
        // A zoomed browser: the window's CSS pixels shrink by the zoom, each covers zoom² device pixels.
        const context = await browser.newContext({
            viewport: {width: Math.round(c.width / c.zoom), height: Math.round(c.height / c.zoom)},
            deviceScaleFactor: c.zoom,
        });
        const page = await context.newPage();
        await page.goto('/login/index.php', {waitUntil: 'networkidle'});
        await page.fill('#username', ADMIN);
        await page.fill('#password', PASSWORD);
        await page.click('#loginbtn');
        await page.waitForLoadState('networkidle');
        for (const tab of ['deficits', 'overview']) {
            await page.goto('/local/catquizlab/results.php?experimentid=' + id + '&tab=' + tab, {waitUntil: 'networkidle'});
            const plots = page.locator('svg.local-catquizlab-chart');
            const n = await plots.count();
            expect(n, tab + ' draws plots').toBeGreaterThan(0);
            for (let i = 0; i < n; i++) {
                const plot = plots.nth(i);
                const m = await plot.evaluate((svg) => {
                    const bar = document.querySelector('nav.navbar.fixed-top, nav.navbar');
                    const below = bar ? bar.getBoundingClientRect().bottom : 0;
                    window.scrollBy({top: svg.getBoundingClientRect().top - below, behavior: 'instant'});
                    const box = svg.getBoundingClientRect();
                    const probe = document.elementFromPoint(box.left + box.width / 2, Math.min(box.top + 2, innerHeight - 1));
                    // Axis titles: the text of the plot farthest left and farthest down, inside the plot's box.
                    const texts = [...svg.querySelectorAll('text')].map((t) => t.getBoundingClientRect())
                        .filter((r) => r.width > 0);
                    const inside = texts.every((r) => r.left >= box.left - 1 && r.right <= box.right + 1
                        && r.top >= box.top - 1 && r.bottom <= box.bottom + 1);
                    return {
                        bar: bar ? {top: Math.round(bar.getBoundingClientRect().top), bottom: Math.round(below),
                            position: getComputedStyle(bar).position} : null,
                        plot: {top: Math.round(box.top), bottom: Math.round(box.bottom), left: Math.round(box.left),
                            right: Math.round(box.right), width: Math.round(box.width), height: Math.round(box.height)},
                        window: {width: innerWidth, height: innerHeight, dpr: devicePixelRatio},
                        covered: probe ? !svg.contains(probe) && !svg.parentElement.contains(probe) : true,
                        textsinside: inside, texts: texts.length,
                        overflowx: document.documentElement.scrollWidth > innerWidth + 1,
                    };
                });
                const name = `${c.width}x${c.height}@${c.zoom * 100}%-${tab}-${i}`;
                const shot = testInfo.outputPath(name + '.png');
                await page.screenshot({path: shot});
                measured.push({case: c, tab, plot: i, ...m, screenshot: path.basename(shot)});
                const say = JSON.stringify({name, ...m});
                expect(m.plot.top, say).toBeGreaterThanOrEqual(m.bar ? m.bar.bottom : 0);
                expect(m.plot.bottom, say).toBeLessThanOrEqual(m.window.height);
                expect(m.covered, say).toBe(false);
                expect(m.textsinside, say).toBe(true);
                expect(m.plot.right, say).toBeLessThanOrEqual(m.window.width + 1);
            }
            // The first plot's download menu by keyboard: focused, it is not under the bar.
            const summary = page.locator('[data-region="catquizlab-download"] summary').first();
            if (await summary.count()) {
                await summary.focus();
                const focus = await summary.evaluate((el) => {
                    el.scrollIntoView({block: 'nearest', behavior: 'instant'});
                    const bar = document.querySelector('nav.navbar.fixed-top, nav.navbar');
                    const r = el.getBoundingClientRect();
                    const at = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
                    return {focused: document.activeElement === el, top: Math.round(r.top),
                        bar: bar ? Math.round(bar.getBoundingClientRect().bottom) : 0, hidden: !el.contains(at)};
                });
                measured.push({case: c, tab, focus});
                expect(focus.focused, JSON.stringify(focus)).toBe(true);
                expect(focus.hidden, JSON.stringify({c, tab, focus})).toBe(false);
            }
        }
        await context.close();
    }
    fs.writeFileSync(testInfo.outputPath('chart-layout.json'), JSON.stringify(measured, null, 1));
    await testInfo.attach('chart-layout.json', {path: testInfo.outputPath('chart-layout.json'),
        contentType: 'application/json'});
});

test('the plugin\'s styles touch nothing of Moodle\'s own (#146)', async({page}) => {
    // Every rule of styles.css names the plugin: a fix for the plots cannot move Moodle's navigation bar elsewhere.
    const css = fs.readFileSync(path.resolve(__dirname, '../../styles.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    const selectors = [...css.matchAll(/([^{}]+)\{/g)].map((m) => m[1].trim()).filter((s) => s && !s.startsWith('@'));
    expect(selectors.length).toBeGreaterThan(0);
    // Split at the commas between selectors, not those inside :is(…) or :not(…).
    const split = (group) => {
        const parts = [];
        let depth = 0;
        let part = '';
        for (const ch of group) {
            depth += ch === '(' ? 1 : (ch === ')' ? -1 : 0);
            if (ch === ',' && depth === 0) {
                parts.push(part);
                part = '';
            } else {
                part += ch;
            }
        }
        return [...parts, part];
    };
    for (const group of selectors) {
        for (const selector of split(group)) {
            expect(selector.trim(), 'a rule of styles.css').toMatch(/^\S*catquizlab/);
        }
    }
    // And Moodle's bar on a page of Moodle's own is where it is on the plugin's.
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
    const bar = async() => page.evaluate(() => {
        const b = document.querySelector('nav.navbar');
        const s = getComputedStyle(b);
        return {position: s.position, height: Math.round(b.getBoundingClientRect().height), zindex: s.zIndex};
    });
    await page.goto('/my/', {waitUntil: 'networkidle'});
    const core = await bar();
    await page.goto('/local/catquizlab/results.php', {waitUntil: 'networkidle'});
    expect(await bar()).toEqual(core);
});
