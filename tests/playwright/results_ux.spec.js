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
 * Results: analysis basis, empty states, validity mode, filters, the download menu (#130).
 *
 * Data from cli/seed_results.php (PLOTS_EXPERIMENTID; --invalid for INVALID_EXPERIMENTID),
 * seeded here where the environment has none.
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');
const {execFileSync} = require('child_process');
const path = require('path');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

const seeded = (label, args) => {
    const name = label + '_EXPERIMENTID';
    if (process.env[name]) {
        return process.env[name];
    }
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/seed_results.php'), '--label=' + label, ...args],
            {encoding: 'utf8'});
        const match = out.match(new RegExp('^' + name + '=(\\d+)', 'm'));
        return match ? match[1] : '';
    } catch (e) {
        return '';
    }
};

const login = async(page) => {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
};

test('collected but none valid: said with the reasons, never "nothing collected"', async({page}) => {
    const id = seeded('INVALID', ['--persons=5', '--invalid']);
    needs(!id, 'No experiment could be seeded.');
    await login(page);
    await page.goto('/local/catquizlab/results.php?experimentid=' + id, {waitUntil: 'networkidle'});

    await expect(page.locator('[data-empty="allexcluded"]')).toContainText('10 sitting(s) were collected');
    await expect(page.locator('body')).not.toContainText('Nothing has been collected');
    await expect(page.locator('body')).not.toContainText('No sitting collected yet');
    // The title comes before anything else of the page.
    const order = await page.evaluate(() => {
        const heading = [...document.querySelectorAll('h2')].find((h) => /Results/.test(h.textContent));
        const pkg = document.querySelector('form input[name="action"][value="package"]');
        return heading && pkg ? heading.compareDocumentPosition(pkg) & Node.DOCUMENT_POSITION_FOLLOWING : -1;
    });
    expect(order, 'the package button comes after the page title').toBeGreaterThan(0);

    // Shown as invalid, they are there.
    await page.getByRole('link', {name: /invalid only/i}).first().click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-empty]')).toHaveCount(0);
    await expect(page.locator('[data-region="catquizlab-basis-line"]')).toContainText('10 sittings');
});

test('250 collected, none with an engine verdict: a missing verdict, never "invalid" or "nothing collected"', async({page}) => {
    // #130 AK 1, #112: 125 people under two strategies, collected without an engine attempt — under the engine's
    // rule every one of them has no verdict.
    const id = seeded('NOVERDICT', ['--persons=125']);
    needs(!id, 'No experiment could be seeded.');
    await login(page);
    const base = '/local/catquizlab/results.php?experimentid=' + id + '&validityrule=engine';
    await page.goto(base + '&tab=overview', {waitUntil: 'networkidle'});

    await expect(page.locator('[data-empty="noverdict"]')).toContainText('250 sitting(s) were collected');
    await expect(page.locator('[data-empty="noverdict"]')).toContainText('This is a missing verdict, not an invalid result');
    await expect(page.locator('[data-region="catquizlab-basis-verdict"]')).toBeVisible();
    for (const wrong of ['No sitting collected yet', 'Nothing has been collected']) {
        await expect(page.locator('body')).not.toContainText(wrong);
    }
    await expect(page.locator('[data-empty="allexcluded"]')).toHaveCount(0);

    // "Invalid only" is empty, and says it is: none was judged invalid.
    await page.goto(base + '&tab=overview&validity=invalid', {waitUntil: 'networkidle'});
    await expect(page.locator('[data-empty="noinvalid"]')).toHaveCount(1);
    // "No verdict only" shows all 250.
    await page.goto(base + '&tab=overview&validity=unknown', {waitUntil: 'networkidle'});
    await expect(page.locator('[data-empty]')).toHaveCount(0);
    await expect(page.locator('[data-region="catquizlab-basis-line"]')).toContainText('250 sittings');

    // The export metadata counts them as unknown, with their causes — not as invalid.
    const meta = JSON.parse(await (await page.request.get(base + '&tab=export&validity=all&level=attempt&action=meta'))
        .text());
    const population = meta.population;
    expect(population.attempts.collected).toBe(250);
    expect(population.attempts.unknown).toBe(250);
    expect(population.attempts.invalid).toBe(0);
    expect(population.engineverdicts.unknown).toBe(250);
    expect(population.engineverdicts.invalid).toBe(0);
    expect(Object.values(population.engineverdicts.unknownreasons).reduce((a, b) => a + b, 0)).toBe(250);
    expect(population.invalidreasons).toEqual([]);

    // And the raw data says it row by row: "unknown", never "invalid".
    const csv = (await (await page.request.get(base + '&tab=export&validity=all&level=attempt&action=csv')).text())
        .split(/\r?\n/).filter((l) => l !== '');
    // Quoted fields may carry commas: split as CSV, not on every comma.
    const cellsOf = (line) => {
        const out = [];
        let cur = '';
        let quoted = false;
        for (let i = 0; i < line.length; i++) {
            const c = line[i];
            if (quoted) {
                if (c === '"' && line[i + 1] === '"') {
                    cur += '"';
                    i++;
                } else if (c === '"') {
                    quoted = false;
                } else {
                    cur += c;
                }
            } else if (c === '"') {
                quoted = true;
            } else if (c === ',') {
                out.push(cur);
                cur = '';
            } else {
                cur += c;
            }
        }
        out.push(cur);
        return out;
    };
    const head = cellsOf(csv[0]);
    const state = head.indexOf('validitystate');
    const verdict = head.indexOf('engineverdict');
    expect(state).toBeGreaterThan(-1);
    expect(csv.length - 1).toBe(250);
    for (const line of csv.slice(1)) {
        const cells = cellsOf(line);
        expect(cells[state]).toBe('unknown');
        expect(cells[verdict]).toBe('unknown');
    }
});

test('the basis is one line plus a folded part that holds everything to reproduce', async({page}) => {
    const id = seeded('PLOTS', []);
    needs(!id, 'No experiment could be seeded.');
    await login(page);
    await page.goto('/local/catquizlab/results.php?experimentid=' + id, {waitUntil: 'networkidle'});

    const basis = page.locator('[data-region="catquizlab-analysis-basis"]');
    await expect(basis).not.toHaveAttribute('open', '');
    await expect(page.locator('[data-region="catquizlab-basis-line"]')).toBeVisible();
    // Validity source and inclusion stay visible.
    await expect(page.locator('[data-region="catquizlab-coverage"]')).toBeVisible();
    await expect(page.locator('[data-region="catquizlab-stoprules"]')).toBeHidden();

    // Opened by keyboard: stop rules, population, completeness, the package.
    await basis.locator(':scope > summary').focus();
    await page.keyboard.press('Enter');
    for (const region of ['catquizlab-stoprules', 'catquizlab-analysis-population', 'catquizlab-completeness']) {
        await expect(basis.locator(`[data-region="${region}"]`)).toBeVisible();
    }
    await expect(basis.getByRole('button', {name: 'Download reproducibility package'})).toBeVisible();
});

test('a filter change and a validity switch change the basis; the export follows the selection', async({page}) => {
    const id = seeded('PLOTS', []);
    needs(!id, 'No experiment could be seeded.');
    await login(page);
    const base = '/local/catquizlab/results.php?experimentid=' + id;
    await page.goto(base, {waitUntil: 'networkidle'});
    await expect(page.locator('[data-region="catquizlab-basis-line"]')).toContainText('in 2 run(s)');

    // One strategy: one run.
    const select = page.locator('select[name="strategy"]');
    const options = await select.locator('option').evaluateAll((o) => o.map((x) => x.value).filter((v) => v !== ''));
    await select.selectOption(options[0]);
    await page.locator('form:has(select[name="strategy"]) [type="submit"]').first().click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-region="catquizlab-basis-line"]')).toContainText('in 1 run(s)');

    // The export tab offers the files of this selection.
    const url = new URL(page.url());
    await page.goto(base + '&tab=export&strategy=' + url.searchParams.get('strategy'), {waitUntil: 'networkidle'});
    const exports = page.locator('a[href*="action=csv"]');
    expect(await exports.count()).toBeGreaterThan(0);
    await expect(exports.first()).toHaveAttribute('href', new RegExp('strategy=' + url.searchParams.get('strategy')));
});

test('the download menu: closed, opens by keyboard, offers SVG, PNG, PDF and CSV; settings apart', async({page}) => {
    const id = seeded('PLOTS', []);
    needs(!id, 'No experiment could be seeded.');
    await login(page);
    await page.goto('/local/catquizlab/results.php?experimentid=' + id + '&tab=global', {waitUntil: 'networkidle'});

    const menu = page.locator('[data-region="catquizlab-download"]').first();
    await expect(menu.locator('[data-download="svg"]')).toBeHidden();
    await menu.locator('summary').focus();
    await page.keyboard.press('Enter');
    for (const kind of ['svg', 'png', 'pdf', 'csv']) {
        await expect(menu.locator(`[data-download="${kind}"]`)).toBeVisible();
    }
    await page.keyboard.press('Tab');
    await expect(menu.locator('[data-download="svg"]')).toBeFocused();
    await expect(menu.locator('[data-download="json"]')).toHaveCount(0);
    await expect(page.locator('[data-region="catquizlab-plot-export"]').first().locator('[data-download="json"]'))
        .toBeVisible();
});
