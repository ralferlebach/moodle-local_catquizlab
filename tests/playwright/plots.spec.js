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
 * The plot conventions, checked in a browser (#105, section 10).
 *
 * Data from cli/seed_results.php, whose PLOTS_EXPERIMENTID the job puts into
 * the environment. Without it the test is skipped rather than failed.
 */

const {test, expect} = require('@playwright/test');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const EXPERIMENT = process.env.PLOTS_EXPERIMENTID || '';

/**
 * The tick labels of one axis of a plot: x along the bottom (text-anchor middle), y at the left (end).
 *
 * @param {object} svg The plot's SVG locator.
 * @param {string} anchor 'middle' for x, 'end' for y.
 * @returns {Promise<number[]>}
 */
async function ticks(svg, anchor) {
    return svg.evaluate((el, a) => Array.from(el.querySelectorAll('text[text-anchor="' + a + '"]'))
        .map((t) => t.textContent.trim()).filter((t) => /^-?\d+(\.\d+)?$/.test(t)).map(Number), anchor);
}

test('plots follow the conventions: symmetric axes, 0 in the middle, a 45° diagonal, integer steps', async({page}) => {
    test.skip(!EXPERIMENT, 'PLOTS_EXPERIMENTID not set; run cli/seed_results.php first.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');

    // Estimated against true ability.
    await page.goto('/local/catquizlab/results.php?experimentid=' + EXPERIMENT + '&tab=global', {waitUntil: 'networkidle'});
    const recovery = page.locator('svg.local-catquizlab-chart').first();
    await expect(recovery).toBeVisible();

    // AXIS-001/002: symmetric, 0 the middle tick; AXIS-003: the same range on both axes.
    const x = await ticks(recovery, 'middle');
    const y = await ticks(recovery, 'end');
    expect(x.length).toBeGreaterThan(2);
    expect(x[0]).toBe(-x[x.length - 1]);
    expect(x[Math.floor(x.length / 2)]).toBe(0);
    expect(y.slice().sort((a, b) => a - b)).toEqual(x.slice().sort((a, b) => a - b));
    expect(Math.min(...y)).toBe(-Math.max(...y));
    // AXIS-004: integers or halves.
    for (const t of x.concat(y)) {
        expect(Math.abs(t * 2 - Math.round(t * 2))).toBeLessThan(1e-9);
    }

    // The identity line is the diagonal: 45°, measured on the drawing.
    const angle = await recovery.evaluate((el) => {
        const line = el.querySelector('line[stroke-dasharray]');
        const dx = parseFloat(line.getAttribute('x2')) - parseFloat(line.getAttribute('x1'));
        const dy = parseFloat(line.getAttribute('y1')) - parseFloat(line.getAttribute('y2'));
        return Math.atan2(dy, dx) * 180 / Math.PI;
    });
    expect(Math.abs(angle - 45)).toBeLessThan(0.1);

    // What the plot is based on, and its exports.
    await expect(page.locator('[data-region="catquizlab-plot-basis"]').first()).toContainText(/Data|Daten/);
    for (const kind of ['svg', 'csv', 'json', 'png', 'pdf']) {
        await expect(page.locator('[data-download="' + kind + '"]').first()).toBeVisible();
    }

    // A single test: the step axis counts, the ability axis is symmetric.
    await page.goto('/local/catquizlab/results.php?experimentid=' + EXPERIMENT + '&tab=testflow', {waitUntil: 'networkidle'});
    const single = page.locator('svg.local-catquizlab-chart').first();
    const steps = await ticks(single, 'middle');
    for (const t of steps) {
        expect(Number.isInteger(t)).toBe(true);
    }
    const abilities = await ticks(single, 'end');
    expect(Math.min(...abilities)).toBe(-Math.max(...abilities));

    // Small multiples of a twin family share their axes.
    await page.selectOption('#cmp_layout', 'multiples');
    await page.click('input[type="submit"][value="Compare"], input[type="submit"][value="Vergleichen"]');
    await page.waitForLoadState('networkidle');
    const multiples = page.locator('[data-region="catquizlab-multiple"] svg.local-catquizlab-chart');
    expect(await multiples.count()).toBeGreaterThan(1);
    const first = await ticks(multiples.nth(0), 'end');
    const second = await ticks(multiples.nth(1), 'end');
    expect(second).toEqual(first);
});
