// This file is part of Moodle - http://moodle.org/
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

/**
 * Accessibility, checked by a machine on every page of the plugin (#126, #128, #130, #131).
 *
 * axe-core against WCAG 2.1 A and AA, inside the page's main region — what the plugin renders, not Moodle's
 * navigation around it. A serious or critical finding fails the test with the rule, the element and the help
 * text; minor and moderate ones are listed in the report. Keyboard and screen-reader behaviour that a machine
 * cannot judge stays in the specs of each page.
 *
 * Data: seeded here, as the status and results specs seed theirs.
 */

const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {execFileSync} = require('child_process');
const path = require('path');
const {needs} = require('./support/needs');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || '';

/**
 * An id printed by a seeding script, or from the environment.
 *
 * @param {string} name The variable, e.g. PLOTS_EXPERIMENTID.
 * @param {string} script The script under cli/.
 * @param {string[]} args Its arguments.
 * @returns {string}
 */
const seeded = (name, script, args) => {
    if (process.env[name]) {
        return process.env[name];
    }
    try {
        const out = execFileSync('php', [path.resolve(__dirname, '../../cli/' + script), ...args], {encoding: 'utf8'});
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

/**
 * Check one page; fail on serious and critical findings, with what and where.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} url The page.
 * @param {import('@playwright/test').TestInfo} info For the report.
 * @param {function} [prepare] Something to do before the check, e.g. open folded parts.
 */
const check = async(page, url, info, prepare) => {
    await page.goto(url, {waitUntil: 'networkidle'});
    if (prepare) {
        await prepare(page);
    }
    const results = await new AxeBuilder({page})
        .include('#region-main')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    const lines = results.violations.map((v) => v.impact + ' ' + v.id + ': ' + v.help + '\n'
        + v.nodes.slice(0, 5).map((n) => '    ' + n.target.join(' ') + ' — ' + (n.failureSummary || '').split('\n')[1])
            .join('\n'));
    await info.attach('axe ' + url, {body: lines.join('\n') || 'no findings', contentType: 'text/plain'});
    const serious = results.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
    expect(serious.map((v) => v.id + ' (' + v.nodes.length + ')'), url + '\n' + lines.join('\n')).toEqual([]);
};

const open = async(page) => {
    // Folded parts are part of the page: opened, so that what they hold is checked too.
    await page.evaluate(() => document.querySelectorAll('details').forEach((d) => d.setAttribute('open', '')));
};

test('the library, the experiment form and the progress page', async({page}, info) => {
    const running = seeded('PROGRESS_RUNNING_EXPERIMENTID', 'seed_progress.php', ['--state=running']);
    needs(!running, 'No running experiment could be seeded.');
    await login(page);
    await check(page, '/local/catquizlab/index.php', info);
    await check(page, '/local/catquizlab/experiment.php', info, open);
    await check(page, '/local/catquizlab/experiment.php?id=' + running, info, open);
    await check(page, '/local/catquizlab/runs.php?experimentid=' + running, info, open);
});

test('every results tab', async({page}, info) => {
    const id = seeded('PLOTS_EXPERIMENTID', 'seed_results.php', ['--label=PLOTS']);
    needs(!id, 'No results could be seeded.');
    await login(page);
    for (const tab of ['overview', 'global', 'subscales', 'deficits', 'robustness', 'testflow', 'rawdata', 'export']) {
        await check(page, '/local/catquizlab/results.php?experimentid=' + id + '&tab=' + tab, info, open);
    }
});

test('the logs, the operations page and the comparison', async({page}, info) => {
    const id = seeded('PLOTS_EXPERIMENTID', 'seed_results.php', ['--label=PLOTS']);
    needs(!id, 'No results could be seeded.');
    await login(page);
    await check(page, '/local/catquizlab/logs.php?hours=24', info, open);
    await check(page, '/local/catquizlab/operations.php', info, open);
    await check(page, '/local/catquizlab/compare.php?experimentid=' + id, info, open);
});
