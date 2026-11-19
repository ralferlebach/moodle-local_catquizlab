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
 * The mode comparison with exactly one shared valid subscale, beside the one with two (#144, re-audit 18.11.2026).
 *
 * Seeded twice through cli/seed_modes.php: the deficit mode against "all sub" sharing subscales 1 and 3 (ten points),
 * and with --single sharing subscale 1 only (five points). In both: as many points as valid (twin, subscale) pairs —
 * five valid pairs, the sixth sitting is not valid — each at its signed errors (+0.6; +0.2) and (+0.2; −0.1); both zero
 * axes drawn and the points on the side their sign says; the 45° line through the plot's diagonal; the axis titles
 * name the two modes; every marker's tooltip names its five twins, their subscale and both sittings; the markers reached
 * by the keyboard; and the plot's files — SVG, PNG, PDF, CSV, JSON — made from the same points.
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');
const {execFileSync} = require('child_process');
const fs = require('fs');
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

const seed = (args) => {
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/seed_modes.php'), ...args], {encoding: 'utf8'});
        const match = out.match(/^MODES_EXPERIMENTID=(\d+)/m);
        return match ? match[1] : '';
    } catch (e) {
        return '';
    }
};

/** A value as the comparison reads it: rounded to 1e-9, so that 0.19999999999999996 is 0.2. */
const r = (v) => Math.round(Number(v) * 1e9) / 1e9;

const markers = (svg) => svg.locator('circle[data-region="point"]').evaluateAll((circles) => circles.map((c) => ({
    x: Math.round(Number(c.getAttribute('data-x')) * 1e9) / 1e9, y: Math.round(Number(c.getAttribute('data-y')) * 1e9) / 1e9,
    count: Number(c.getAttribute('data-count')),
    cx: Number(c.getAttribute('cx')), cy: Number(c.getAttribute('cy')),
    title: (c.querySelector('title') || {}).textContent || '',
})));

const line = (svg, region) => svg.locator(`line[data-region="${region}"]`).evaluateAll((ls) => ls.map((l) => ({
    x1: Number(l.getAttribute('x1')), y1: Number(l.getAttribute('y1')),
    x2: Number(l.getAttribute('x2')), y2: Number(l.getAttribute('y2')),
})));

/**
 * The comparison plot of the deficit mode against "all sub": the one with the expected number of points.
 *
 * @param {object} page The page.
 * @param {number} points How many points it must have.
 * @returns {Promise<object>} The plot's container.
 */
const comparison = async(page, points) => {
    const plots = page.locator('[data-region="catquizlab-mode-deficit"] [data-region="catquizlab-mode-plot"]');
    await expect(plots.first()).toBeVisible();
    for (let i = 0; i < await plots.count(); i++) {
        const found = await markers(plots.nth(i).locator('svg'));
        if (found.reduce((n, m) => n + m.count, 0) === points) {
            return plots.nth(i);
        }
    }
    throw new Error(`no comparison with ${points} points`);
};

for (const [label, args, points, at] of [
    ['two shared subscales', [], 10, [[0.6, 0.2, 5], [0.2, -0.1, 5]]],
    ['exactly one shared subscale', ['--single'], 5, [[0.6, 0.2, 5]]],
]) {
    test(`mode comparison, ${label}: one signed point per valid (twin, subscale) pair, zero axes, 45° line`, async({page}) => {
        const id = seed(args);
        needs(!id, 'cli/seed_modes.php could not be run here.');
        await login(page);
        await page.goto('/local/catquizlab/results.php?experimentid=' + id + '&tab=deficits', {waitUntil: 'networkidle'});
        const plot = await comparison(page, points);
        const svg = plot.locator('svg');
        const found = await markers(svg);

        // As many points as valid pairs times common subscales, at their signed errors: not one point per twin.
        expect(found.map((m) => [m.x, m.y, m.count]).sort()).toEqual(at.slice().sort());
        await expect(page.locator('[data-region="catquizlab-mode-deficit"]'))
            .toContainText(new RegExp(`n = ${points} (points|Punkte)`));

        // The zero axes, and every point on the side of each its sign says.
        const [zx] = await line(svg, 'zero-axis-x');
        const [zy] = await line(svg, 'zero-axis-y');
        expect(zx, 'the zero axis x = 0 is drawn').toBeTruthy();
        expect(zy, 'the zero axis y = 0 is drawn').toBeTruthy();
        expect(zx.x1).toBe(zx.x2);
        expect(zy.y1).toBe(zy.y2);
        for (const m of found) {
            expect(Math.sign(m.cx - zx.x1), `x sign of (${m.x}; ${m.y})`).toBe(Math.sign(m.x));
            // SVG y grows downwards: a positive value is above the axis.
            expect(Math.sign(zy.y1 - m.cy), `y sign of (${m.x}; ${m.y})`).toBe(Math.sign(m.y));
        }
        // The 45° line: the plot area is square, the line rises one unit per unit, through (0; 0).
        const [diag] = await line(svg, 'identity-line');
        expect(diag, 'the identity line is drawn').toBeTruthy();
        expect(Math.abs(Math.abs(diag.x2 - diag.x1) - Math.abs(diag.y2 - diag.y1))).toBeLessThan(0.05);
        expect(Math.sign(diag.x2 - diag.x1)).toBe(-Math.sign(diag.y2 - diag.y1));
        const t = (zx.x1 - diag.x1) / (diag.x2 - diag.x1);
        expect(Math.abs(diag.y1 + t * (diag.y2 - diag.y1) - zy.y1), 'the diagonal passes through (0; 0)').toBeLessThan(0.05);

        // Titles: the axes name the baseline and the mode; the description says what one point is.
        const text = await svg.evaluate((el) => el.textContent);
        expect(text).toMatch(/all sub|Alle Sub/i);
        const described = await svg.evaluate((el) => (el.getAttribute('aria-labelledby') || '').split(' ')
            .map((ref) => (document.getElementById(ref) || {}).textContent || '').join(' '));
        expect(described).toMatch(/one subscale observation|eine Subskalenbeobachtung/);

        // Tooltips: each marker names its five twins, the subscale, both sittings, the valid pair.
        for (const m of found) {
            expect(m.title.split('; Twin ').length, 'five twins in ' + m.title).toBe(5);
            expect(m.title).toMatch(/subscale|Subskala/);
            expect(m.title).toMatch(/a pair of valid sittings|Paar gültiger Bearbeitungen/);
        }

        // The keyboard reaches the first marker and the readout says the same.
        await expect(page.locator('html')).toHaveAttribute('data-catquizlab-plotkeys', 'ready');
        const first = svg.locator('circle[data-region="point"][tabindex="0"]');
        await first.focus();
        await expect(plot.locator('[data-region="catquizlab-point-readout"]'))
            .toHaveText(await first.getAttribute('aria-label'));
    });

    test(`mode comparison, ${label}: SVG, PNG, PDF, CSV and JSON from the same points`, async({page}) => {
        const id = seed(args);
        needs(!id, 'cli/seed_modes.php could not be run here.');
        await login(page);
        await page.goto('/local/catquizlab/results.php?experimentid=' + id + '&tab=deficits', {waitUntil: 'networkidle'});
        const plot = await comparison(page, points);
        const found = await markers(plot.locator('svg'));
        // The plot's own download menu: inside its container, after its drawing.
        const inside = plot.locator('[data-region="catquizlab-plot-export"]');
        const figure = (await inside.count()) ? inside.first()
            : plot.locator('xpath=following::*[@data-region="catquizlab-plot-export"][1]');
        await figure.evaluate((el) => el.querySelectorAll('[data-region="catquizlab-download"]')
            .forEach((menu) => menu.setAttribute('open', '')));
        const decode = async(kind) => {
            const href = await figure.locator(`[data-download="${kind}"]`).first().getAttribute('href');
            return Buffer.from(href.slice(href.indexOf(',') + 1), 'base64').toString('utf8');
        };

        // SVG: the page's markers, value for value.
        const circle = /<circle [^>]*data-x="([^"]+)" data-y="([^"]+)" data-count="(\d+)" data-region="point">/g;
        const filed = [...(await decode('svg')).matchAll(circle)].map((m) => [r(m[1]), r(m[2]), Number(m[3])]);
        expect(filed.sort()).toEqual(found.map((m) => [m.x, m.y, m.count]).sort());
        expect(await decode('svg')).toContain('data-region="zero-axis-x"');
        // CSV: one row per point, its label a twin; nothing else drawn.
        const rows = (await decode('csv')).split('\n').filter((l) => l !== '');
        expect(rows[0]).toBe('series,x,y,label');
        const pointsrows = rows.slice(1).filter((r) => r.startsWith(','));
        expect(pointsrows.length, 'one CSV row per point').toBe(points);
        // JSON: the settings count the same markers and points.
        const settings = JSON.parse(await decode('json'));
        expect(settings.markers.markers).toBe(found.length);
        expect(settings.markers.points).toBe(points);
        // PNG (drawn in the browser from the SVG) and PDF (made on the server from the SVG): real files.
        await page.waitForSelector('html[data-catquizlab-png="ready"]', {state: 'attached', timeout: 30 * 1000});
        for (const kind of ['png', 'pdf']) {
            const control = figure.locator(`[data-download="${kind}"]`).first();
            const [file] = await Promise.all([page.waitForEvent('download', {timeout: 60 * 1000}), control.click()]);
            const body = fs.readFileSync(await file.path());
            if (kind === 'png') {
                expect(body.subarray(1, 4).toString('latin1'), 'a PNG').toBe('PNG');
            } else {
                expect(body.subarray(0, 5).toString('latin1'), 'a PDF').toBe('%PDF-');
            }
        }
    });
}
