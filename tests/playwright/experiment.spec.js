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
 * One experiment, from an empty installation to results, through the interface.
 *
 * The CLI smoke test proves the plugin can do this. It cannot prove that a
 * person can: every button in this file is one a person would press, and a
 * change that moves or breaks one of them fails here even though every unit
 * test still passes. That gap is where most of this plugin's defects have
 * lived.
 *
 * Nothing here calls a CLI script or writes to the database.
 */

const {test, expect} = require('@playwright/test');

const ADMIN = process.env.MOODLE_ADMIN || 'admin';
const PASSWORD = process.env.MOODLE_ADMIN_PASSWORD || 'Admin#12345';

// Long enough for five sittings of up to twenty questions each, played by a
// real browser against a real Moodle.
const RUN_TIMEOUT = 20 * 60 * 1000;

// How many simulated people. The workflow offers this as an input and it was
// never read here, so the number on the form was always five whatever the
// person running the job had chosen.
const PERSONS = process.env.PERSONS || '5';

/**
 * Sign in as the administrator.
 *
 * @param {import('@playwright/test').Page} page The page.
 */
async function login(page) {
    await page.goto('/login/index.php', {waitUntil: 'networkidle'});
    await page.fill('#username', ADMIN);
    await page.fill('#password', PASSWORD);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');

    // Checked, not assumed. The previous version swallowed the navigation error
    // and carried on, so a failed sign-in surfaced ten steps later as a page
    // that did not contain the words it should have — which reads as the plugin
    // being broken rather than as never having logged in.
    const body = await page.locator('body').innerText();
    if (/You are not logged in|Sie sind nicht angemeldet|Invalid login/i.test(body)) {
        throw new Error('Sign-in failed for ' + ADMIN + ': ' + body.slice(0, 200));
    }
}

/**
 * Open one of the plugin's five process steps by its tab.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {RegExp} label Which tab.
 */
async function openStep(page, label) {
    // Within the plugin's own tab bar. The CAT engine contributes its own
    // navigation with similar words, and an unscoped match walked into the
    // CAT-Manager — where none of the fields this test fills in exist, which
    // then looked like the form failing to render.
    // The plugin's own tab bar, by name: the page carries more than one, and
    // matching on the class alone is matching on a coincidence.
    const tabs = page.locator('[data-region="catquizlab-steps"]');
    await tabs.getByRole('link', {name: label}).first().click();
    await page.waitForLoadState('networkidle');

    // Every step of this plugin renders the tab bar. Landing somewhere without
    // one means the click left the plugin, and continuing from there produces
    // failures about the wrong thing.
    await expect(page.locator('[data-region="catquizlab-steps"]')).toBeVisible();
}

test.describe('CatQuizLab, through the interface', () => {
    test('an experiment runs from definition to results', async ({page}) => {
        test.setTimeout(RUN_TIMEOUT);

        await login(page);
        await page.goto('/local/catquizlab/index.php');
        await expect(page.locator('body')).toContainText('CAT experiment suite');

        // 1. Preparation. Whatever is still open is opened from here, by the
        //    buttons the step offers — not by setting config directly, because
        //    the point is that those buttons work.
        await openStep(page, /1\.\s*(Preparation|Vorbereitung)/);

        for (let round = 0; round < 6; round++) {
            // Only forms that post back into this plugin. The preparation step
            // links out to Moodle's own settings and to the engine, and
            // pressing one of those walks the test out of the thing it is
            // testing.
            const action = page.locator(
                '#region-main form[action*="/local/catquizlab/"] button[type=submit]',
                {hasText: /Run and enable|Set up|Create|Issue|Install|Ausführen|Einrichten|Anlegen/}
            ).first();

            if (!(await action.count())) {
                break;
            }

            await action.click();
            await page.waitForLoadState('networkidle');
        }

        // The self-test is the step's own answer to "does this actually work".
        const selftest = page.locator(
            '#region-main form[action*="/local/catquizlab/"] button[type=submit]',
            {hasText: /self-test|Selbsttest/}
        ).first();
        if (await selftest.count()) {
            await selftest.click();
            await page.waitForLoadState('networkidle');
        }

        // 2. Experiment plan: define the experiment in the form, with the
        //    parameters this test is about.
        await openStep(page, /2\.\s*(Experiment plan|Experimentenplan)/);
        // Scoped to the plugin's own region: the engine contributes a
        // "CAT-Manager" area with links of its own, and an unscoped match for
        // "new experiment" landed there instead — which then looked like the
        // form failing to render.
        // The editor, at the address the plan step's own link points to.
        //
        // Clicking that link is what a person does, and this test tried for
        // three rounds to do exactly that — but the page also carries the CAT
        // engine's navigation, whose links match the same words and the same
        // region, and every attempt to disambiguate by text or container hit
        // the engine instead. Navigating to the address the link carries is the
        // same action without the ambiguity.
        await page.goto('/local/catquizlab/experiment.php');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#id_name')).toBeVisible();

        // Moodle collapses most of a long form, so the fields below exist and
        // are not visible. Only the form's own sections: the page also carries
        // collapsed navigation menus, and clicking those navigated away — after
        // which the form was gone and the failure read as the form never having
        // rendered.
        const expandall = page.locator('#region-main a.collapse-buttons, #region-main .collapsible-actions a').first();
        if (await expandall.count()) {
            await expandall.click().catch(() => {});
        }

        // Moodle re-renders the fieldset headers as each one opens, so a list
        // collected once goes stale after the first click. Re-reading it each
        // time is slower and is the only version that opens more than one.
        for (let round = 0; round < 12; round++) {
            const next = page.locator('form [aria-expanded="false"]').first();
            if (!(await next.count())) {
                break;
            }
            await next.click({timeout: 5000}).catch(() => {});
            await page.waitForTimeout(200);
        }

        const name = 'UI smoke ' + Date.now();
        await page.fill('#id_name', name);
        await page.fill('#id_replications', '1');

        // Several people, so the profiles actually differ between sittings.
        await page.fill('#id_personcount', PERSONS);

        // A pool with room: the selection takes the item that suits the current
        // estimate, so a pool sized to the answer count runs out of suitable
        // items long before it runs out of items.
        await page.fill('#id_poolcategories', '1');
        await page.fill('#id_poolsubcategories', '3');
        await page.fill('#id_poolitems', '20');

        // Questions per test: 15 to 20.
        await page.fill('#id_globalmin', '15');
        await page.fill('#id_globalmax', '20');

        // Questions per scale: 3 to 5.
        await page.fill('#id_subscalemin', '3');
        await page.fill('#id_subscalemax', '5');

        // Standard error per scale: 0.3 to 2.5.
        await page.fill('#id_semin', '0.3');
        await page.fill('#id_semax', '2.5');

        // The form's own submit, by its id. The fallback list this used to have
        // ended in `button[type=submit]`, and `.first()` on a Moodle admin page
        // is a button in the navigation — which is why saving landed in Site
        // administration and the experiment was never created.
        await page.locator('#id_submitbutton').click();
        await page.waitForLoadState('networkidle');

        // A validation error keeps the form open and says what is wrong, and
        // that is far more useful in a video than a timeout somewhere later.
        const invalid = page.locator('.error, .invalid-feedback, [data-fieldtype] .form-control-feedback');
        if (await invalid.count()) {
            const problems = (await invalid.allInnerTexts()).filter((t) => t.trim() !== '');
            expect(problems, 'the experiment form rejected the values').toEqual([]);
        }

        await expect(page.locator('body')).toContainText(name);

        // 3. Progress: prepare and run, the two actions the plugin reduces to.
        //
        // The experiment has to be the one in context first. Step 3 shows the
        // prepare and run buttons for the selected experiment and nothing at
        // all for "all experiments" — so without this the test pressed a button
        // belonging to whichever experiment happened to be selected, and the
        // one it had just defined stayed a draft with no runs.
        await openStep(page, /3\.\s*(Progress|Verlauf)/);

        const selector = page.locator('#catquizlab-experiment');
        await expect(selector).toBeVisible();
        await selector.selectOption({label: name});
        await page.waitForLoadState('networkidle');

        // And the page now says so, before anything is pressed.
        await expect(page.locator('body')).toContainText(name);

        const prepare = page.locator('button[type=submit]', {
            hasText: /Prepare experiment|Experiment vorbereiten/,
        }).first();
        await expect(prepare).toBeVisible();
        await prepare.click();
        await page.waitForLoadState('networkidle');

        // Preparation reports per run and stage when it refuses, and the
        // message is the thing worth reading in a video.
        await expect(page.locator('body')).not.toContainText(/Preparation stopped|Vorbereitung gestoppt/);

        const start = page.locator('button[type=submit]', {
            hasText: /Run experiment|Experiment ausführen/,
        }).first();
        await expect(start).toBeVisible();
        await start.click();
        await page.waitForLoadState('networkidle');

        // 4. Wait, by reloading the step a person would be watching.
        const deadline = Date.now() + (15 * 60 * 1000);
        let collected = 0;

        while (Date.now() < deadline) {
            await page.reload({waitUntil: 'networkidle'});

            const text = await page.locator('body').innerText();
            const progress = text.match(/(\d+)\s*\/\s*(\d+)/);
            if (progress) {
                collected = parseInt(progress[1], 10);
            }

            if (/Finished|Abgeschlossen/.test(text) && collected > 0) {
                break;
            }

            await page.waitForTimeout(10 * 1000);
        }

        // 5. Results: the numbers, read from the page.
        await openStep(page, /4\.\s*(Results|Ergebnisse)/);

        const results = await page.locator('body').innerText();
        expect(results).not.toMatch(/No results yet|Noch keine Ergebnisse/);

        // The measures a person came for. Their presence on the page is the
        // claim being tested: that an experiment driven from the interface
        // produces readable numbers.
        expect(results).toMatch(/rmse|bias|correlation|mae/i);

        // 6. Logs: the fifth step, which is where somebody looks when the
        //    above did not happen.
        await openStep(page, /5\.\s*Logs/);
        await expect(page.locator('[data-region="catquizlab-log"]')).toBeVisible();
    });
});
