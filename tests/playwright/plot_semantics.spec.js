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
