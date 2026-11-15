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
 * Experiments A–Z everywhere past 50 (#153), every run with its experiment and strategy (#150), and renaming an
 * experiment whose design its runs have locked (#152).
 *
 * Data from cli/seed_progress.php: --state=library (55 planned experiments under one prefix, with umlauts, case,
 * numbers and equal names) and --state=overview (an experiment with 30 runs, running).
 */

const {test, expect} = require('@playwright/test');
const {needs} = require('./support/needs');
const {execFileSync} = require('child_process');
const path = require('path');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';
const LIBRARY = '/local/catquizlab/index.php?tab=experiments';
const SEED = path.resolve(__dirname, '../../cli/seed_progress.php');

const seeds = {};

/**
 * A seeded state, once per file: its KEY=VALUE lines.
 *
 * @param {string} state The --state.
 * @returns {Object} Lowercased keys.
 */
const seeded = (state) => {
    if (seeds[state] === undefined) {
        seeds[state] = {};
        try {
            const out = execFileSync('php', [SEED, '--state=' + state], {encoding: 'utf8', timeout: 300000});
            for (const line of out.split('\n')) {
                const match = line.match(/^([A-Z_]+)=(.*)$/);
                if (match) {
                    seeds[state][match[1].toLowerCase()] = match[2];
                }
            }
        } catch (e) {
            seeds[state] = {};
        }
    }
    return seeds[state];
};

const login = async(page) => {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
};

// The order the server keeps: the language's collation, case ignored, accents not, numbers by value.
const collator = new Intl.Collator('en', {numeric: true, sensitivity: 'accent'});

/**
 * Names in A–Z order: no neighbour out of place.
 *
 * @param {string[]} names The names as listed.
 */
const expectAtoZ = (names) => {
    for (let i = 1; i < names.length; i++) {
        expect(collator.compare(names[i - 1], names[i]), `"${names[i - 1]}" before "${names[i]}"`).toBeLessThanOrEqual(0);
    }
};

const libraryNames = async(page) => (await page.locator('[data-region="catquizlab-library"] tbody th').allTextContents())
    .map((t) => t.trim());

test('A–Z past 50: the library, the selector and "All experiments" agree; the sort and the search are kept (#153)',
    async({page}) => {
    const lib = seeded('library');
    needs(!lib.library_prefix, 'cli/seed_progress.php --state=library could not run here.');
    const prefix = lib.library_prefix;
    await login(page);

    // The library: all 55, A–Z, numbers by value, umlauts beside their letter, equal names together.
    await page.goto(LIBRARY + '&sort=name&q=' + encodeURIComponent(prefix.trim()), {waitUntil: 'networkidle'});
    const names = await libraryNames(page);
    expect(names).toHaveLength(Number(lib.library_count));
    expectAtoZ(names);
    const at = (n) => names.indexOf(prefix + n);
    expect(at('Experiment 9')).toBeLessThan(at('Experiment 10'));
    expect(at('Run 9')).toBeLessThan(at('Run 10'));
    expect(at('Äpfel')).toBeLessThan(at('beta'));
    expect(at('Öl')).toBeLessThan(at('zebra'));
    expect(names[names.length - 1]).toBe(prefix + 'Zürich');

    // The search is kept for the session, the sort as a preference: both survive a visit without parameters.
    await page.goto(LIBRARY, {waitUntil: 'networkidle'});
    await expect(page.locator('#catquizlab-library-q')).toHaveValue(prefix.trim());
    await expect(page.locator('#catquizlab-library-sort')).toHaveValue('name');
    expect(await libraryNames(page)).toEqual(names);
    await page.goto(LIBRARY + '&sort=modified', {waitUntil: 'networkidle'});
    await page.goto(LIBRARY, {waitUntil: 'networkidle'});
    await expect(page.locator('#catquizlab-library-sort')).toHaveValue('modified');
    // Back to A–Z, and the search cleared for the tests after this one.
    await page.goto(LIBRARY + '&sort=name&q=', {waitUntil: 'networkidle'});
    await expect(page.locator('#catquizlab-library-q')).toHaveValue('');

    // The selector on every tab: every experiment, not the first 50, in the same order.
    await page.goto('/local/catquizlab/runs.php', {waitUntil: 'networkidle'});
    const options = (await page.locator('#catquizlab-experiment option').allTextContents()).map((t) => t.trim());
    expect(options.filter((n) => n.startsWith(prefix))).toEqual(names);
    expect(options.length).toBeGreaterThan(55);
    // Choosing one shows it, and it stays chosen through a reload and a poll.
    await page.selectOption('#catquizlab-experiment', {label: prefix + 'Zürich'});
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/experimentid=\d+/);
    await expect(page.locator('#catquizlab-experiment option:checked')).toHaveText(prefix + 'Zürich');
    await page.waitForTimeout(4500);
    await page.reload({waitUntil: 'networkidle'});
    await expect(page.locator('#catquizlab-experiment option:checked')).toHaveText(prefix + 'Zürich');

    // "All experiments": the sections in the same order, across its pages.
    const sections = [];
    for (let p = 0; p < 40; p++) {
        await page.goto('/local/catquizlab/runs.php?xpage=' + p, {waitUntil: 'networkidle'});
        const here = (await page.locator('details[data-experimentid] summary .h6').allTextContents()).map((t) => t.trim());
        sections.push(...here.filter((n) => n.startsWith(prefix)));
        if (!(await page.locator('.pagination a.page-link', {hasText: String(p + 2)}).count())) {
            break;
        }
    }
    expect(sections).toEqual(names);
});

test('every run names its experiment and strategy, linked; one experiment shows only its runs; 320 px (#150)',
    async({page}) => {
    const ov = seeded('overview');
    needs(!ov.overview_running_experimentid, 'cli/seed_progress.php --state=overview could not run here.');
    const id = ov.overview_running_experimentid;
    await login(page);

    await page.goto('/local/catquizlab/runs.php?experimentid=' + id, {waitUntil: 'networkidle'});
    const experiments = page.locator('[data-region^="catquizlab-runlist-"][data-region$="-experiment"]');
    expect(await experiments.count()).toBeGreaterThan(0);
    for (const link of await experiments.all()) {
        // Only this experiment's runs, each linked to it, by its present name.
        await expect(link).toHaveAttribute('href', new RegExp('experimentid=' + id + '(&|$)'));
        await expect(link).toHaveText(ov.overview_prefix + ' a running');
    }
    const first = page.locator('[data-region^="catquizlab-runlist-"][data-region$="-experiment"]').first()
        .locator('xpath=ancestor::td[1]');
    await expect(first).toContainText(/Classical test|CAT|Klassischer Test/);
    // The run id is a link to the run.
    const runlink = first.locator('a[href*="runid="]').first();
    const href = await runlink.getAttribute('href');
    await runlink.click();
    await page.waitForLoadState('networkidle');
    expect(page.url()).toContain(href.replace(/^.*(runid=\d+).*$/, '$1'));

    // "All experiments": the runs table of a section names the strategy and links the run.
    await page.goto('/local/catquizlab/runs.php', {waitUntil: 'networkidle'});
    const lists = page.locator('[data-region^="catquizlab-runlist-"][data-region$="-experiment"]');
    for (const link of await lists.all()) {
        await expect(link).toHaveAttribute('href', /experimentid=\d+/);
    }

    // A phone: the plugin's content no wider than the screen; wide tables scroll inside their own box. (Moodle's
    // own navigation bar is wider than 320 px in Boost; that is the theme's, not measured here.)
    await page.setViewportSize({width: 320, height: 800});
    await page.goto('/local/catquizlab/runs.php?experimentid=' + id, {waitUntil: 'networkidle'});
    await page.waitForTimeout(3000);
    const overflow = await page.evaluate(() => {
        const main = document.querySelector('#region-main') || document.querySelector('[role="main"]');
        return main.scrollWidth - main.clientWidth;
    });
    expect(overflow, 'no horizontal scrolling of the content at 320 px').toBeLessThanOrEqual(1);
});

test('renaming a running experiment: the new name everywhere, the design and its hash unchanged, errors said (#152)',
    async({page, browser}) => {
    const ov = seeded('overview');
    const lib = seeded('library');
    needs(!ov.overview_running_experimentid || !lib.library_prefix, 'cli/seed_progress.php could not run here.');
    const id = ov.overview_running_experimentid;
    const before = ov.overview_prefix + ' a running';
    await login(page);
    const sesskey = async() => page.evaluate(() => M.cfg.sesskey);
    const pkg = async() => {
        const response = await page.request.post('/local/catquizlab/results.php',
            {form: {experimentid: id, action: 'package', sesskey: await sesskey()}});
        return JSON.parse(await response.text());
    };
    const editor = '/local/catquizlab/experiment.php?id=' + id;

    await page.goto(editor, {waitUntil: 'networkidle'});
    const hashbefore = (await pkg()).experiment.designhash;
    expect(hashbefore).toMatch(/^[0-9a-f]{64}$/);
    // The design is locked, name and description are not, and the page says which is which.
    await expect(page.locator('#id_metadataname')).toHaveValue(before);
    await expect(page.locator('form', {has: page.locator('#id_metadataname')}))
        .toContainText(/design is locked|Design ist durch seine Läufe gesperrt/);

    // An empty name: refused, the error on the field.
    await page.fill('#id_metadataname', '');
    await page.click('#id_savemetadata');
    await expect(page.locator('#id_error_metadataname')).toBeVisible();
    await expect(page.locator('#id_error_metadataname')).not.toHaveText('');
    await page.goto(editor, {waitUntil: 'networkidle'});
    await expect(page.locator('#id_metadataname')).toHaveValue(before);

    // A forged POST without the session key changes nothing.
    const seen = await page.inputValue('input[name="seen"]');
    await page.request.post(editor, {form: {id, seen, metadataname: 'Forged ' + Date.now(), metadatadescription: '',
        action: 'metadata',
        savemetadata: '1', '_qf__local_catquizlab_form_metadata_form': '1', sesskey: 'not-the-key'}});
    await page.goto(editor, {waitUntil: 'networkidle'});
    await expect(page.locator('#id_metadataname')).toHaveValue(before);

    // Someone else's editor, opened now, saved after ours: refused, not overwritten.
    const other = await browser.newContext();
    const second = await other.newPage();
    await login(second);
    await second.goto(editor, {waitUntil: 'networkidle'});

    // Unicode, quotes and an ampersand: stored as typed, shown escaped. Named into the seeded 55, so its new place
    // among them can be checked.
    const after = lib.library_prefix + 'Mitte ' + Date.now() + ' Ärger & „Öl“ 50 %';
    await page.fill('#id_metadataname', after);
    await page.click('#id_savemetadata');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.alert-success.alert-block')).toContainText(/design is unchanged|Design ist unverändert/);
    await page.reload({waitUntil: 'networkidle'});
    await expect(page.locator('#id_metadataname')).toHaveValue(after);
    // The history: when, who, from, to.
    const history = page.locator('table', {has: page.locator('caption', {hasText: /Changes of name|Änderungen von Name/})});
    await expect(history).toContainText(before);
    await expect(history).toContainText(after);

    await second.fill('#id_metadataname', 'Lost update ' + Date.now());
    await second.click('#id_savemetadata');
    await second.waitForLoadState('networkidle');
    await expect(second.locator('.alert-danger.alert-block'))
        .toContainText(/changed since this page was opened|seit diese Seite geöffnet/);
    await expect(second.locator('#id_metadataname')).toHaveValue(after);
    await other.close();

    // The library finds it by its new name and shows it as text.
    await page.goto(LIBRARY + '&q=' + encodeURIComponent(after), {waitUntil: 'networkidle'});
    expect(await libraryNames(page)).toEqual([after]);
    await page.goto(LIBRARY + '&q=', {waitUntil: 'networkidle'});
    // It moved to its new place, A–Z; the others kept theirs.
    await page.goto('/local/catquizlab/runs.php', {waitUntil: 'networkidle'});
    const prefixed = (await page.locator('#catquizlab-experiment option').allTextContents()).map((t) => t.trim())
        .filter((n) => n.startsWith(lib.library_prefix));
    expect(prefixed).toContain(after);
    expectAtoZ(prefixed);
    // The selector and the run list: the new name; the experiment and its runs keep their ids.
    await page.goto('/local/catquizlab/runs.php?experimentid=' + id, {waitUntil: 'networkidle'});
    await expect(page.locator('#catquizlab-experiment option:checked')).toHaveText(after);
    for (const link of await page.locator('[data-region^="catquizlab-runlist-"][data-region$="-experiment"]').all()) {
        await expect(link).toHaveAttribute('title', after);
    }

    // The package: the present name, the same design hash; runs keep the name they were created under.
    const content = await pkg();
    expect(content.experiment.name).toBe(after);
    expect(content.experiment.designhash).toBe(hashbefore);
    for (const run of content.runs) {
        expect(run.experimentnameatrun).not.toBe(after);
    }
});
