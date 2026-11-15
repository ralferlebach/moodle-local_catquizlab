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
 * The results pages as a screen reader will meet them, recorded for the screen reader jobs (#122 DoD 3, #143 AK7,
 * #144 AK9).
 *
 * NVDA runs on Windows and VoiceOver on macOS; Moodle is installed on Linux. This spec, in the Linux interface run,
 * records two results pages with everything the browser fetched for them — HTML, stylesheets, scripts, Moodle's own
 * requests — as a HAR file: the overview of the seeded experiment (the filter bar) and the mode-specific detection
 * of cli/seed_modes.php (a scatter plot with point markers). The screen reader jobs replay that file
 * (tests/screenreader): the pages run there as here, scripts included, without a Moodle behind them. With it, the
 * list of what a screen reader must announce: the filter bar's controls (name, role, shown value) and the plot's
 * markers (name, count). Written to artifacts/screenreader/, which the workflow uploads.
 *
 * The login happens in a browser context of its own, so the recording holds no password.
 *
 * Data from cli/seed_results.php (PLOTS_EXPERIMENTID) and cli/seed_modes.php (run here).
 */

const fs = require('fs');
const path = require('path');
const {execFileSync} = require('child_process');
const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const EXPERIMENT = process.env.PLOTS_EXPERIMENTID || '';
const OUT = process.env.SR_SNAPSHOT_DIR || path.join(__dirname, 'artifacts', 'screenreader');

const seed = (script, args, name) => {
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/' + script), ...args], {encoding: 'utf8'});
        const match = out.match(new RegExp('^' + name + '=(\\d+)', 'm'));
        return match ? match[1] : '';
    } catch (e) {
        return '';
    }
};

test('the results pages are recorded for the screen reader jobs', async({browser}, info) => {
    needs(!EXPERIMENT, 'PLOTS_EXPERIMENTID not set; run cli/seed_results.php first.');
    const modes = seed('seed_modes.php', [], 'MODES_EXPERIMENTID');
    needs(!modes, 'cli/seed_modes.php could not be run here.');
    const base = info.project.use.baseURL;
    expect(base, 'the base URL of the site').toBeTruthy();

    // Logged in elsewhere: the recording starts with the session, not with the password.
    const login = await browser.newContext({baseURL: base});
    const door = await login.newPage();
    await door.goto('/login/index.php', {waitUntil: 'networkidle'});
    await door.fill('#username', ADMIN);
    await door.fill('#password', PASSWORD);
    await door.click('#loginbtn');
    await door.waitForLoadState('networkidle');
    const state = await login.storageState();
    await login.close();

    fs.rmSync(OUT, {recursive: true, force: true});
    fs.mkdirSync(OUT, {recursive: true});
    const har = path.join(OUT, 'results.har');
    const origin = new URL(base).origin;
    const context = await browser.newContext({
        baseURL: base,
        storageState: state,
        recordHar: {path: har, content: 'embed', urlFilter: new RegExp('^' + origin.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/')},
    });
    const page = await context.newPage();

    // The filter bar: every control with its name, its role and, for a drop-down, the value it shows. In the order of
    // the page, which is the order of the keyboard.
    await page.goto('/local/catquizlab/results.php?experimentid=' + EXPERIMENT + '&tab=overview', {waitUntil: 'networkidle'});
    const bar = page.locator('[data-region="catquizlab-filter-bar"]');
    await expect(bar).toBeVisible();
    const controls = await bar.locator('select, input:not([type="hidden"]), button, a[href]').evaluateAll((elements) =>
        elements.filter((el) => el.offsetParent !== null).map((el) => {
            const tag = el.tagName.toLowerCase();
            const type = (el.getAttribute('type') || '').toLowerCase();
            const labelled = el.id ? document.querySelector('label[for="' + el.id + '"]') : null;
            const name = (el.getAttribute('aria-label') || (labelled ? labelled.textContent : '')
                || (tag === 'input' ? el.value : el.textContent) || '').replace(/\s+/g, ' ').trim();
            let role = 'textbox';
            if (tag === 'select') {
                role = el.multiple || el.size > 1 ? 'listbox' : 'combobox';
            } else if (tag === 'a') {
                role = 'link';
            } else if (tag === 'button' || ['submit', 'button', 'reset'].includes(type)) {
                role = 'button';
            } else if (type === 'checkbox' || type === 'radio') {
                role = type;
            }
            const selected = tag === 'select' && el.selectedIndex >= 0
                ? el.options[el.selectedIndex].text.replace(/\s+/g, ' ').trim() : '';
            return {tag: tag, type: type, id: el.id || '', name: name, role: role, selected: selected};
        }));
    expect(controls.length, 'the bar has controls').toBeGreaterThan(3);
    for (const control of controls) {
        expect(control.name, 'every control has a name: ' + JSON.stringify(control)).not.toBe('');
    }
    expect(controls.filter((control) => control.role === 'combobox').length, 'the six factors are drop-downs').toBe(6);
    const filterbar = {url: page.url(), controls: controls};

    // The plot: the comparison with ten points on two markers (cli/seed_modes.php), each marker named by all it
    // stands for.
    await page.goto('/local/catquizlab/results.php?experimentid=' + modes + '&tab=deficits', {waitUntil: 'networkidle'});
    await expect(page.locator('html')).toHaveAttribute('data-catquizlab-plotkeys', 'ready');
    const plots = page.locator('[data-region="catquizlab-mode-plot"]');
    let index = -1;
    for (let i = 0; i < await plots.count(); i++) {
        const counts = await plots.nth(i).locator('circle[data-region="point"]').evaluateAll((cs) =>
            cs.map((c) => Number(c.getAttribute('data-count'))));
        if (index < 0 && counts.length === 2 && counts.reduce((a, b) => a + b, 0) === 10) {
            index = i;
        }
    }
    expect(index, 'the comparison with ten points on two markers').toBeGreaterThanOrEqual(0);
    const svg = plots.nth(index).locator('svg');
    const markers = await svg.locator('circle[data-region="point"]').evaluateAll((cs) => cs.map((c) => ({
        x: Number(c.getAttribute('data-x')), y: Number(c.getAttribute('data-y')), count: Number(c.getAttribute('data-count')),
        label: c.getAttribute('aria-label'), tabindex: c.getAttribute('tabindex'),
    })));
    const title = await svg.evaluate((el) => document.getElementById(el.getAttribute('aria-labelledby').split(' ')[0])
        .textContent);
    const plot = {url: page.url(), index: index, title: title, markers: markers};
    const lang = await page.evaluate(() => document.documentElement.lang);

    await context.close();
    expect(fs.statSync(har).size, 'the recording').toBeGreaterThan(10000);
    fs.writeFileSync(path.join(OUT, 'results-pages.json'), JSON.stringify({
        lang: lang,
        filterbar: filterbar,
        plot: plot,
    }, null, 2));
});
