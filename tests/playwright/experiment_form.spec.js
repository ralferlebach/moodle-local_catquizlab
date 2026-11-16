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
 * The experiment form follows what is chosen, as it is chosen — in a browser.
 *
 * The rules had PHPUnit tests and still failed in the browser: the script set
 * the classical test's question fields enabled again right after PHP disabled
 * them, nothing was ever hidden, a strategy chosen above counted although the
 * sweep replaced it, and the check of a budget against the pool read the wrong
 * fields. Only a browser shows what the form does.
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

/**
 * Whether a form row is hidden by the form's own logic.
 *
 * @param {object} page The page.
 * @param {string} id The row's element id.
 * @returns {Promise<boolean>}
 */
async function hidden(page, id) {
    return page.evaluate((elid) => {
        const el = document.getElementById(elid);
        const row = el ? (el.closest('.fitem') || el) : null;
        return !row || row.classList.contains('catquizlab-hidden');
    }, id);
}

test('the form hides and disables what does not apply, and checks budgets against the pool', async({page}) => {
    needs(!PASSWORD, 'MOODLE_ADMIN_PASSWORD not set.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
    await page.goto('/local/catquizlab/experiment.php', {waitUntil: 'networkidle'});

    // The classical test alone: no question budget, no subscales — hidden and disabled.
    await page.selectOption('#id_strategy', 'classic');
    expect(await hidden(page, 'id_globalmin')).toBe(true);
    expect(await hidden(page, 'id_globalmax')).toBe(true);
    expect(await hidden(page, 'id_subscalemin')).toBe(true);
    expect(await hidden(page, 'id_subscalemax')).toBe(true);
    await expect(page.locator('#id_globalmin')).toBeDisabled();
    await expect(page.locator('#id_subscalemax')).toBeDisabled();
    await expect(page.locator('[data-catquizlab-na="globalmax"]')).toBeVisible();
    // Its own row has no question budget either; another strategy's row is not shown.
    await expect(page.locator('#id_perstrategy_classic_globalmin')).toBeDisabled();
    expect(await hidden(page, 'fgroup_id_perstrategygroup_classic')).toBe(false);
    expect(await hidden(page, 'fgroup_id_perstrategygroup_allsubs')).toBe(true);

    // An adaptive strategy: the budgets are back, enabled.
    await page.selectOption('#id_strategy', 'relsubs');
    expect(await hidden(page, 'id_globalmax')).toBe(false);
    await expect(page.locator('#id_globalmax')).toBeEnabled();
    await expect(page.locator('#id_subscalemin')).toBeEnabled();

    // The reported form: classic above, four strategies in the sweep, 15–25 and
    // 3–5 over the default pool of 10 × 10 subscales.
    await page.selectOption('#id_strategy', 'classic');
    // Every section open, as somebody filling the whole form has it.
    const expand = page.locator('a.collapseexpand').first();
    if (await expand.count() && !(await expand.getAttribute('class')).includes('collapse-all')) {
        await expand.click();
    }
    await page.selectOption('#id_sweepstrategies', ['highestsub', 'classic', 'relsubs', 'allsubs']);
    await page.fill('#id_poolcategories', '10');
    await page.fill('#id_poolsubcategories', '10');
    await page.fill('#id_globalmin', '15');
    await page.fill('#id_globalmax', '25');
    await page.fill('#id_subscalemin', '3');
    await page.fill('#id_subscalemax', '5');

    // The sweep replaces the choice above, and says so.
    await expect(page.locator('#id_strategy')).toBeDisabled();
    await expect(page.locator('[data-catquizlab-na="strategy"]')).toContainText('Infer all subscales');
    // The classical test's row still has no question budget; allsubs's row is shown.
    await expect(page.locator('#id_perstrategy_classic_globalmax')).toBeDisabled();
    await expect(page.locator('#id_perstrategy_allsubs_globalmax')).toBeEnabled();
    expect(await hidden(page, 'fgroup_id_perstrategygroup_lowestsub')).toBe(true);

    // And the budget allsubs cannot meet is said while it is typed.
    const box = page.locator('[data-catquizlab-feasibility]');
    await expect(box).toBeVisible();
    await expect(box).toContainText('300');

    // A maximum that allows it settles it.
    await page.fill('#id_globalmax', '400');
    await expect(box).toBeHidden();

    // A ceiling per subscale below the minimum is said as well.
    await page.fill('#id_subscalemax', '1');
    await page.fill('#id_poolcategories', '2');
    await page.fill('#id_poolsubcategories', '2');
    await page.fill('#id_subscalemin', '1');
    await expect(box).toBeVisible();
    await expect(box).toContainText('before its minimum');

    // On submit the server refuses what the browser said.
    await page.fill('#id_subscalemax', '5');
    await page.fill('#id_poolcategories', '10');
    await page.fill('#id_poolsubcategories', '10');
    await page.fill('#id_subscalemin', '3');
    await page.fill('#id_globalmax', '25');
    await page.fill('#id_name', 'Form check');
    await page.locator('#id_submitbutton').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#id_error_globalmax')).toContainText('300');
});

test('the preview computes from the form without saving; saving and creating runs is the primary action', async({page}) => {
    needs(!PASSWORD, 'MOODLE_ADMIN_PASSWORD not set.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
    await page.goto('/local/catquizlab/experiment.php', {waitUntil: 'networkidle'});

    // One primary action (#126): save and create the runs; saving alone and the preview beside it.
    await expect(page.locator('#id_saveandsweep')).toHaveClass(/btn-primary/);
    await expect(page.locator('#id_submitbutton')).toHaveClass(/btn-secondary/);
    await expect(page.locator('#id_previewbutton')).toHaveClass(/btn-secondary/);

    const name = 'Unsaved preview ' + Date.now();
    await page.fill('#id_name', name);
    await page.locator('#id_sweepmore .ftoggler a, #id_sweepmore legend a').first().click();
    const budgets = page.locator('[data-region="catquizlab-levels-sweepglobalbudgets"]');
    for (const [min, max] of [['10', '15'], ['20', '30']]) {
        await budgets.getByRole('button', {name: 'Add level'}).click();
        await budgets.locator('li').last().locator('input').nth(0).fill(min);
        await budgets.locator('li').last().locator('input').nth(1).fill(max);
    }
    await page.click('#id_previewbutton');
    await page.waitForLoadState('networkidle');

    // The preview of what was entered: two budget levels, not saved, no sweep to create yet.
    await expect(page.locator('[data-region="catquizlab-preview-unsaved"]')).toBeVisible();
    await expect(page.locator('#id_name')).toHaveValue(name);
    await expect(page.locator('[data-region="catquizlab-levels-sweepglobalbudgets"] li')).toHaveCount(2);
    await expect(page.locator('input[name="action"][value="createsweep"]')).toHaveCount(0);
    const cells = await page.locator('.card', {hasText: 'Sweep preview'}).locator('li').first().locator('strong').innerText();
    expect(Number(cells)).toBeGreaterThanOrEqual(2);

    // A level the form refuses is named, not dropped from the preview.
    await page.evaluate(() => {
        document.getElementById('id_sweepse').value = '0.3/0.5\n0.6-0.9';
    });
    await page.click('#id_previewbutton');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-region="catquizlab-preview-unsaved"]')).toHaveCount(0);
    await expect(page.locator('body')).toContainText('No preview');
    await expect(page.locator('#id_error_sweepse, #fitem_id_sweepse .invalid-feedback').first())
        .toContainText(/Line 2|min\/max/);

    // Nothing was stored.
    await page.goto('/local/catquizlab/index.php', {waitUntil: 'networkidle'});
    await expect(page.locator('body')).not.toContainText(name);
});

test('the runs the preview announces are the runs that are created (#126, 0.7.50)', async({page}) => {
    needs(!PASSWORD, 'MOODLE_ADMIN_PASSWORD not set.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
    await page.goto('/local/catquizlab/experiment.php', {waitUntil: 'networkidle'});

    // A 2-level sweep of the global budget, entered in the form.
    const name = 'Preview equals runs ' + Date.now();
    await page.fill('#id_name', name);
    await page.locator('#id_sweepmore .ftoggler a, #id_sweepmore legend a').first().click();
    const budgets = page.locator('[data-region="catquizlab-levels-sweepglobalbudgets"]');
    for (const [min, max] of [['2', '4'], ['3', '6']]) {
        await budgets.getByRole('button', {name: 'Add level'}).click();
        await budgets.locator('li').last().locator('input').nth(0).fill(min);
        await budgets.locator('li').last().locator('input').nth(1).fill(max);
    }
    await page.click('#id_previewbutton');
    await page.waitForLoadState('networkidle');
    const card = page.locator('.card', {hasText: 'Sweep preview'});
    const announced = Number(await card.locator('li', {hasText: 'Resulting runs'}).locator('strong').innerText());
    expect(announced).toBeGreaterThanOrEqual(2);

    // Saved and created in one step, as the form offers it.
    await page.click('#id_saveandsweep');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText(new RegExp(`${announced} run`));

    // The library says the same of the stored experiment.
    await page.goto('/local/catquizlab/index.php?tab=experiments&q=' + encodeURIComponent(name), {waitUntil: 'networkidle'});
    const row = page.locator('[data-region="catquizlab-library"] tbody tr', {hasText: name});
    await expect(row).toContainText(new RegExp(`${announced} run\\(s\\)`));
});

/**
 * Fill an input as a person does: select its text, type, and let the input events fire.
 *
 * @param {object} page The page.
 * @param {string} selector The input.
 * @param {string} text What to type.
 */
async function type(page, selector, text) {
    await page.locator(selector).click();
    await page.locator(selector).fill('');
    await page.locator(selector).pressSequentially(text);
}

test('#159: log-normal discrimination as three coupled inputs with a live density sketch', async({page}) => {
    needs(!PASSWORD, 'MOODLE_ADMIN_PASSWORD not set.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
    await page.goto('/local/catquizlab/experiment.php', {waitUntil: 'networkidle'});

    const mu = '#id_lognormalmu';
    const sigma = '#id_lognormalsigma';
    const mean = '#id_lognormalmean';
    const svg = page.locator('[data-region="catquizlab-lognormal-svg"]');
    const text = page.locator('[data-region="catquizlab-lognormal-text"]');
    const num = async(selector) => Number((await page.inputValue(selector)).replace(',', '.'));

    // A new experiment: μlog 0, σlog 0.3, E(a) ≈ 1.046028, the curve drawn with its text alternative.
    await page.selectOption('#id_discriminationdist', 'lognormal');
    expect(await num(mu)).toBe(0);
    expect(await num(sigma)).toBeCloseTo(0.3, 9);
    expect(await num(mean)).toBeCloseTo(1.046028, 6);
    await expect(svg).toHaveAttribute('data-state', 'curve');
    await expect(text).toContainText('E(a) = 1.046028');
    await expect(svg.locator('[data-role="mean"]')).toHaveCount(1);
    await expect(svg.locator('[data-role="interval"]')).toHaveCount(1);
    await expect(svg).toContainText('Item discrimination a');
    await expect(svg).toContainText('Probability density');
    // Only the inputs of this family: the generic value fields are hidden.
    await expect(page.locator('#id_discriminationa')).toBeHidden();
    await expect(page.locator('#id_discriminationb')).toBeHidden();

    // μlog and σlog given: E(a) follows (μlog 0.25 → 1.343126).
    await type(page, mu, '0.25');
    expect(await num(mean)).toBeCloseTo(1.343126, 6);
    // σlog and E(a) given: μlog follows (E(a) 1.5 → 0.360465).
    await type(page, sigma, '0.3');
    await type(page, mean, '1.5');
    expect(await num(mu)).toBeCloseTo(0.360465, 6);
    const line = Number(await svg.locator('[data-role="mean"]').getAttribute('data-value'));
    expect(line).toBeCloseTo(1.5, 9);
    // E(a) and μlog given: σlog follows; a pair with no solution is said, no stale curve.
    await type(page, mu, '0.5');
    await type(page, mean, '1.0');
    await expect(page.locator(sigma)).toHaveValue('');
    await expect(page.locator(sigma)).toHaveAttribute('aria-invalid', 'true');
    await expect(svg).toHaveAttribute('data-state', 'invalid');
    await expect(text).toContainText('ln(E(a))');
    // Typing half a number does not throw or freeze.
    await type(page, mean, '1.');
    // σlog 0: one value, not a density.
    await type(page, mu, '0.2');
    await type(page, sigma, '0');
    await expect(svg).toHaveAttribute('data-state', 'constant');

    // Save and open again: the pair typed last decides, the stored μlog/σlog come back with their E(a).
    const name = 'Lognormal 159 ' + Date.now();
    await page.fill('#id_name', name);
    await type(page, sigma, '0.3');
    await type(page, mean, '1.5');
    await page.locator('#id_submitbutton').click();
    await page.waitForLoadState('networkidle');
    const invalid = page.locator('.error, .invalid-feedback, [data-fieldtype] .form-control-feedback');
    const problems = (await invalid.allInnerTexts()).filter((t) => t.trim() !== '');
    expect(problems, 'the experiment form rejected the values').toEqual([]);
    // Find it in the library by its name, and open its editor.
    await page.goto('/local/catquizlab/index.php?q=' + encodeURIComponent(name), {waitUntil: 'networkidle'});
    const id = await page.evaluate(() => {
        const link = [...document.querySelectorAll('a[href*="experiment.php?id="]')][0];
        return link ? new URL(link.href).searchParams.get('id') : '';
    });
    expect(id, 'the saved experiment is in the library').not.toBe('');
    await page.goto('/local/catquizlab/experiment.php?id=' + id, {waitUntil: 'networkidle'});
    // The search is kept for the session (#153): clear it for the tests after this one.
    await page.goto('/local/catquizlab/index.php?q=', {waitUntil: 'networkidle'});
    await page.goto('/local/catquizlab/experiment.php?id=' + id, {waitUntil: 'networkidle'});
    await page.waitForLoadState('networkidle');
    expect(await num(mu)).toBeCloseTo(0.360465, 6);
    expect(await num(sigma)).toBeCloseTo(0.3, 9);
    expect(await num(mean)).toBeCloseTo(1.5, 6);
    await expect(svg).toHaveAttribute('data-state', 'curve');

    // Constant: the log-normal inputs and the sketch are gone.
    await page.selectOption('#id_discriminationdist', 'constant');
    await expect(page.locator('#id_lognormalmu')).toBeHidden();
    await expect(page.locator('[data-region="catquizlab-lognormal-svg"]')).toBeHidden();
    await expect(page.locator('#id_discriminationa')).toBeVisible();
});

test('#159: keyboard, decimal comma, a phone, 1PL, and the three reference cases on record', async({page}, testInfo) => {
    needs(!PASSWORD, 'MOODLE_ADMIN_PASSWORD not set.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
    await page.goto('/local/catquizlab/experiment.php', {waitUntil: 'networkidle'});
    await page.selectOption('#id_discriminationdist', 'lognormal');

    const svg = page.locator('[data-region="catquizlab-lognormal-svg"]');
    const text = page.locator('[data-region="catquizlab-lognormal-text"]');
    const block = page.locator('#fitem_id_lognormalmu').locator('xpath=..');
    const num = async(selector) => Number((await page.inputValue(selector)).replace(',', '.'));
    const record = async(name) => {
        // The visual acceptance (DoD-07): the inputs and the sketch as the author sees them, no help popup over it.
        await page.evaluate(() => document.activeElement && document.activeElement.blur());
        await svg.scrollIntoViewIfNeeded();
        await page.screenshot({path: testInfo.outputPath(name + '.png'), fullPage: false});
        await testInfo.attach(name, {path: testInfo.outputPath(name + '.png'), contentType: 'image/png'});
    };
    await record('159-default-mu0-sigma0.3');

    // Keyboard only: into μlog, type; E(a) follows while typing and the focus stays in μlog; Tab moves on
    // (past the help icon) to σlog.
    await page.locator('#id_lognormalmu').focus();
    await page.keyboard.press('Control+A');
    await page.keyboard.type('0.25');
    await expect(page.locator('#id_lognormalmu')).toBeFocused();
    expect(await num('#id_lognormalmean')).toBeCloseTo(1.343126, 6);
    const sigmafield = page.locator('#id_lognormalsigma');
    for (let i = 0; i < 4 && !(await sigmafield.evaluate((el) => el === document.activeElement)); i++) {
        await page.keyboard.press('Tab');
    }
    await expect(sigmafield).toBeFocused();
    await expect(text).toContainText('1.343126');
    await record('159-mu0.25-sigma0.3');
    // σlog and then E(a) typed: these two decide, μlog follows.
    await sigmafield.focus();
    await page.keyboard.press('Control+A');
    await page.keyboard.type('0.3');
    await page.locator('#id_lognormalmean').focus();
    await page.keyboard.press('Control+A');
    await page.keyboard.type('1.5');
    await page.keyboard.press('Tab');
    expect(await num('#id_lognormalmu')).toBeCloseTo(0.360465, 6);
    await record('159-mean1.5-sigma0.3');

    // A German author writes 0,25 and reads 1,343126: the module's own reading and writing of numbers.
    const comma = await page.evaluate(() => new Promise((resolve) => {
        window.require(['local_catquizlab/lognormal'], (m) => resolve({
            parsed: m.parse('0,25', ','),
            half: Number.isNaN(m.parse('0,', ',')) ? 'NaN' : m.parse('0,', ','),
            empty: Number.isNaN(m.parse('', ',')) ? 'NaN' : 'number',
            shown: m.format(Math.exp(0.25 + 0.045), ','),
            point: m.parse('0.25', ','),
        }));
    }));
    expect(comma.parsed).toBe(0.25);
    expect(comma.half).toBe(0);
    expect(comma.empty).toBe('NaN');
    expect(comma.shown).toBe('1,343126');
    // A point is read as a decimal point too, as the server's unformat_float() reads it: no different value saved.
    expect(comma.point).toBe(0.25);

    // A phone: the sketch shrinks with the column, the form's content no wider than the screen. (Moodle's own
    // navigation bar is wider than 320 px in Boost; that is the theme's, not measured here.)
    await page.setViewportSize({width: 320, height: 800});
    await svg.scrollIntoViewIfNeeded();
    const widths = await page.evaluate(() => {
        const main = document.querySelector('#region-main') || document.querySelector('[role="main"]');
        return {
            main: main.scrollWidth - main.clientWidth,
            svg: document.querySelector('[data-region="catquizlab-lognormal-svg"]').getBoundingClientRect().width,
            room: main.clientWidth,
        };
    });
    expect(widths.main, 'no horizontal scrolling of the form at 320 px').toBeLessThanOrEqual(1);
    expect(widths.svg).toBeLessThanOrEqual(widths.room);
    await expect(block).toBeVisible();
    await page.setViewportSize({width: 1440, height: 900});

    // 1PL: no discrimination to set, no inputs and no sketch.
    await page.selectOption('#id_model', '1pl');
    await expect(page.locator('#id_lognormalmu')).toBeHidden();
    await expect(svg).toBeHidden();
    await page.selectOption('#id_model', '2pl');
    await expect(page.locator('#id_lognormalmu')).toBeVisible();
});

test('#159 AK-12: the axis fits narrow and strongly skewed distributions, said in the text alternative', async({page}, info) => {
    needs(!PASSWORD, 'MOODLE_ADMIN_PASSWORD not set.');

    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
    await page.goto('/local/catquizlab/experiment.php', {waitUntil: 'networkidle'});
    await page.selectOption('#id_discriminationdist', 'lognormal');
    const svg = page.locator('[data-region="catquizlab-lognormal-svg"]');
    const text = page.locator('[data-region="catquizlab-lognormal-text"]');
    const set = async(mu, sigma) => {
        await page.fill('#id_lognormalmu', mu);
        await page.fill('#id_lognormalsigma', sigma);
        await expect(svg).toHaveAttribute('data-state', 'curve');
    };
    // The drawn curve: where its highest point is, as a share of the plot's height, and how wide its bulk is drawn.
    const shape = () => svg.evaluate((el) => {
        const d = el.querySelector('[data-role="density"]').getAttribute('d');
        const pts = d.slice(1).split(' L').map((pair) => pair.split(',').map(Number));
        const ys = pts.map(([, y]) => y);
        const top = Math.min(...ys);
        const base = Math.max(...ys);
        const high = pts.filter(([, y]) => base - y > 0.1 * (base - top)).map(([x]) => x);
        return {peak: (180 - top) / (180 - 14), bulk: (Math.max(...high) - Math.min(...high)) / (466 - 46),
            scale: el.getAttribute('data-scale'), xmin: Number(el.getAttribute('data-xmin')),
            ticks: Array.from(el.querySelectorAll('[data-role="tick"]')).map((t) => t.textContent)};
    });

    // The default: from 0, linear, the bulk across the plot.
    await set('0', '0.3');
    let s = await shape();
    expect([s.scale, s.xmin]).toEqual(['linear', 0]);
    expect(s.bulk, 'the default\'s bulk spans the plot').toBeGreaterThan(0.3);

    // Narrow (σlog 0.02): before, a spike a few pixels wide from 0 and its peak between the sampled points. Now the
    // axis starts below the 0.5th percentile, the peak is drawn, and the text alternative says where the axis starts.
    await set('0', '0.02');
    s = await shape();
    expect(s.scale).toBe('linear');
    expect(s.xmin, 'the axis starts at the bulk, not at 0').toBeGreaterThan(0.8);
    expect(s.peak, 'the peak is drawn').toBeGreaterThan(0.85);
    expect(s.bulk, 'the bulk is wide enough to read').toBeGreaterThan(0.3);
    await expect(text).toContainText(/(starts at|beginnt bei)/);
    await page.screenshot({path: info.outputPath('159-narrow.png')});

    // Strongly skewed (σlog 1.5): before, the bulk in the first few pixels and the tail across the rest. Now a log
    // axis with ticks at 1, 2, 5 of each decade, and the text alternative says so.
    await set('0', '1.5');
    s = await shape();
    expect(s.scale).toBe('log');
    expect(s.bulk, 'the bulk spans the plot on the log axis').toBeGreaterThan(0.3);
    expect(s.ticks).toContain('1');
    await expect(text).toContainText(/(logarithmic|logarithmisch)/);
    await page.screenshot({path: info.outputPath('159-skewed.png')});
});
