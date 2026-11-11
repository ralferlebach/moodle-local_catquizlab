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
 * What the plots mean, in the browser: sittings of exactly 625 questions as a density marker on a local axis
 * (#143); the mode comparison as signed points, one per twin and common subscale (#144).
 *
 * Self-seeding through cli/seed_results.php --length=625 --identical and cli/seed_modes.php; skipped where php
 * cannot be run here.
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');
const {execFileSync} = require('child_process');
const path = require('path');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

const login = async(page) => {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
};

const seed = (script, args, name) => {
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/' + script), ...args], {encoding: 'utf8'});
        const match = out.match(new RegExp('^' + name + '=(\\d+)', 'm'));
        return match ? match[1] : '';
    } catch (e) {
        return '';
    }
};

/**
 * The point markers of a plot: their values, counts and tooltips, as the SVG carries them.
 *
 * @param {import('@playwright/test').Locator} plot The plot.
 * @returns {Promise<Array<{x: number, y: number, count: number, title: string}>>}
 */
const markers = (plot) => plot.locator('circle[data-region="point"]').evaluateAll((circles) => circles.map((c) => ({
    x: Number(c.getAttribute('data-x')), y: Number(c.getAttribute('data-y')),
    count: Number(c.getAttribute('data-count')), title: (c.querySelector('title') || {}).textContent || '',
})));

test('625 questions in every sitting: one marker at exactly 625, its count, a local axis', async({page}) => {
    const id = seed('seed_results.php', ['--persons=40', '--length=625', '--identical', '--label=DENSITY'],
        'DENSITY_EXPERIMENTID');
    needs(!id, 'cli/seed_results.php could not be run here.');
    await login(page);
    await page.goto('/local/catquizlab/results.php?experimentid=' + id + '&tab=overview', {waitUntil: 'networkidle'});

    const plot = page.locator('svg.local-catquizlab-chart').first();
    const found = await markers(plot);
    expect(found.length).toBeGreaterThan(0);
    // Every sitting accounted for, none moved off 625.
    expect(found.reduce((n, m) => n + m.count, 0)).toBe(80);
    for (const marker of found) {
        expect(marker.x).toBe(625);
    }
    // Identical sittings are one marker, larger than a single point, and say how many they are.
    const largest = found.reduce((a, b) => (b.count > a.count ? b : a));
    expect(largest.count).toBeGreaterThan(1);
    expect(largest.title).toContain(String(largest.count));
    const radius = Number(await plot.locator(`circle[data-count="${largest.count}"]`).first().getAttribute('r'));
    expect(radius).toBeGreaterThan(2.5);
    // The x axis around 625, not from 0: its ticks are drawn first, the y axis's (from 0, an SE) after them.
    const ticks = await plot.locator('text').allTextContents();
    expect(ticks.slice(0, 3)).toEqual(['624', '625', '626']);
    expect(ticks[3]).toBe('0');
    // Said beneath the plot, for a reader who cannot hover.
    await expect(page.locator('#region-main')).toContainText(/logarithm of their number|Logarithmus ihrer Anzahl/);
});

test('the mode comparison draws one signed point per twin and common subscale', async({page}) => {
    const id = seed('seed_modes.php', [], 'MODES_EXPERIMENTID');
    needs(!id, 'cli/seed_modes.php could not be run here.');
    await login(page);
    await page.goto('/local/catquizlab/results.php?experimentid=' + id + '&tab=deficits', {waitUntil: 'networkidle'});

    const plots = page.locator('[data-region="catquizlab-mode-deficit"] [data-region="catquizlab-mode-plot"]');
    await expect(plots.first()).toBeVisible();
    const all = [];
    for (let i = 0; i < await plots.count(); i++) {
        all.push(await markers(plots.nth(i)));
    }
    // Against "all sub": five valid pairs, two common subscales — ten points on two coordinates, five each.
    const allsubs = all.find((found) => found.reduce((n, m) => n + m.count, 0) === 10);
    expect(allsubs, 'a comparison with ten points').toBeTruthy();
    const at = (x, y) => allsubs.find((m) => Math.abs(m.x - x) < 1e-9 && Math.abs(m.y - y) < 1e-9);
    // Subscale 1: "all sub" +0.6 too high, the mode +0.2 — subscale 3: +0.2 and −0.1. Signed, not averaged.
    expect(at(0.6, 0.2)?.count).toBe(5);
    expect(at(0.2, -0.1)?.count).toBe(5);
    await expect(page.locator('[data-region="catquizlab-mode-deficit"]')).toContainText(/n = 10 points|n = 10 Punkte/);
});

test('a plot fits below the fixed navigation bar on a wide and on a narrow window (#146)', async({page}) => {
    const id = seed('seed_modes.php', [], 'MODES_EXPERIMENTID');
    needs(!id, 'cli/seed_modes.php could not be run here.');
    await login(page);
    for (const viewport of [{width: 1920, height: 1080}, {width: 1280, height: 720}, {width: 390, height: 844}]) {
        await page.setViewportSize(viewport);
        await page.goto('/local/catquizlab/results.php?experimentid=' + id + '&tab=deficits', {waitUntil: 'networkidle'});
        const plot = page.locator('[data-region="catquizlab-mode-plot"] svg').first();
        await expect(plot).toBeVisible();
        // Scrolled so that the plot starts right below the bar: it ends inside the window, and the bar covers
        // nothing of it. A plot of the page's full width was taller than a wide window.
        const fits = await plot.evaluate((svg) => {
            const bar = document.querySelector('nav.navbar.fixed-top, nav.navbar');
            const below = bar ? bar.getBoundingClientRect().bottom : 0;
            // At once: Moodle 5 (Bootstrap 5) scrolls smoothly by default, and the plot would be measured before it
            // has moved.
            window.scrollBy({top: svg.getBoundingClientRect().top - below, behavior: 'instant'});
            const box = svg.getBoundingClientRect();
            const middle = document.elementFromPoint(box.left + box.width / 2, box.top + 2);
            return {
                top: Math.round(box.top), bottom: Math.round(box.bottom), below: Math.round(below),
                window: window.innerHeight, covered: !svg.contains(middle) && !svg.parentElement.contains(middle),
            };
        });
        expect(fits.bottom, JSON.stringify({viewport, fits})).toBeLessThanOrEqual(fits.window);
        expect(fits.top, JSON.stringify({viewport, fits})).toBeGreaterThanOrEqual(fits.below);
        expect(fits.covered, JSON.stringify({viewport, fits})).toBe(false);
    }
});

/**
 * A plot's own CSV (its "Download" menu) as rows of series, x, y, label.
 *
 * @param {import('@playwright/test').Locator} figure The plot's figure: the SVG's region with its download menu.
 * @returns {Promise<string[][]>}
 */
const plotCsv = async(figure) => {
    const href = await figure.locator('[data-download="csv"]').first().getAttribute('href');
    const text = Buffer.from(href.slice(href.indexOf(',') + 1), 'base64').toString('utf8');
    const parse = (line) => {
        const cells = [];
        let cell = '';
        let quoted = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (quoted && ch === '"' && line[i + 1] === '"') {
                cell += '"';
                i++;
            } else if (ch === '"') {
                quoted = !quoted;
            } else if (ch === ',' && !quoted) {
                cells.push(cell);
                cell = '';
            } else {
                cell += ch;
            }
        }
        cells.push(cell);
        return cells;
    };
    return text.split('\n').filter((l) => l !== '').map(parse);
};

test('every marker is its CSV rows, and its tooltip names every one of them (#143, #144)', async({page}) => {
    const density = seed('seed_results.php', ['--persons=40', '--length=625', '--identical', '--label=DENSITYCSV'],
        'DENSITYCSV_EXPERIMENTID');
    const modes = seed('seed_modes.php', [], 'MODES_EXPERIMENTID');
    needs(!density || !modes, 'cli/seed_results.php or cli/seed_modes.php could not be run here.');
    await login(page);
    let compared = 0;
    for (const [id, tab] of [[density, 'overview'], [density, 'global'], [modes, 'deficits'], [modes, 'subscales']]) {
        await page.goto('/local/catquizlab/results.php?experimentid=' + id + '&tab=' + tab, {waitUntil: 'networkidle'});
        const figures = page.locator('[data-region="catquizlab-plot-export"]');
        for (let i = 0; i < await figures.count(); i++) {
            // The figure's SVG is the one before its download menu.
            const plot = figures.nth(i).locator('xpath=preceding::*[local-name()="svg"][1]');
            const rows = await plotCsv(figures.nth(i));
            const [head, ...data] = rows;
            if (head.join(',') !== 'series,x,y,label') {
                continue;
            }
            // The drawn points: rows without a series, with finite numbers, grouped by their exact coordinates.
            const groups = new Map();
            for (const [series, x, y, label] of data) {
                if (series !== '' || !isFinite(Number(x)) || !isFinite(Number(y)) || x === '' || y === '') {
                    continue;
                }
                const key = (Number(x) + 0) + '|' + (Number(y) + 0);
                groups.set(key, [...(groups.get(key) || []), label]);
            }
            const found = await markers(plot);
            const say = `${tab} plot ${i}`;
            expect(found.length, say + ': one marker per exact coordinate').toBe(groups.size);
            for (const marker of found) {
                const members = groups.get((marker.x + 0) + '|' + (marker.y + 0));
                expect(members, say + ': a marker without CSV rows at ' + marker.x + ',' + marker.y).toBeTruthy();
                expect(marker.count, say).toBe(members.length);
                for (const label of new Set(members)) {
                    expect(marker.title, say + ': the tooltip names ' + label).toContain(label);
                }
            }
            compared++;
        }
    }
    expect(compared, 'plots compared with their CSV').toBeGreaterThan(1);
});
