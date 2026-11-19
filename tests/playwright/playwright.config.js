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
 * Configuration for the interface end-to-end run.
 *
 * Video and trace are kept for every run, not only failures. A passing run is
 * the thing somebody wants to watch when they are asking whether the interface
 * still does what it is supposed to — and a recording that only exists after a
 * failure cannot answer that.
 */
// The deep deletion of the experiment the run played (#161 DoD2, deep_delete.spec.js) runs on its own, after every
// other spec, with CATQUIZLAB_DEEP_DELETE=<experiment id>: only that spec then, into folders of its own, so that the
// recordings and the report of the main run stay as they are.
const DEEP = Boolean(process.env.CATQUIZLAB_DEEP_DELETE);
const REPORT = DEEP ? 'report-deep-delete' : 'report';
// The engine's session and migration tests (#136, #137, acceptance.yml) need two activities and two learners seeded by
// cli/engine_session_seed.php, and the migration test an engine upgrade between its two phases: they run only where
// ENGINE_SESSION names that seed, never inside the interface run.
const ENGINE = Boolean(process.env.ENGINE_SESSION);

module.exports = {
    testDir: '.',
    testMatch: DEEP ? 'deep_delete.spec.js' : '**/*.spec.js',
    testIgnore: DEEP ? [] : ['deep_delete.spec.js'].concat(ENGINE ? [] : ['engine_session.spec.js', 'engine_migration.spec.js']),
    // An experiment takes minutes: it provisions a course, questions and
    // people, then plays five sittings through a real browser.
    timeout: 30 * 60 * 1000,
    expect: {timeout: 30 * 1000},
    // One at a time. Two runs share an installation, a worker pool and a queue.
    workers: 1,
    fullyParallel: false,
    retries: 0,
    reporter: [
        ['list'],
        ['html', {outputFolder: REPORT, open: 'never'}],
        ['json', {outputFile: REPORT + '/results.json'}],
    ],
    use: {
        baseURL: process.env.MOODLE_URL || 'http://127.0.0.1:8000',
        headless: true,
        // A browser already installed, where the job provides one.
        launchOptions: process.env.PW_CHROMIUM_PATH ? {executablePath: process.env.PW_CHROMIUM_PATH} : {},
        video: 'on',
        trace: 'on',
        screenshot: 'on',
        actionTimeout: 60 * 1000,
        navigationTimeout: 120 * 1000,
        viewport: {width: 1440, height: 900},
    },
    outputDir: DEEP ? 'artifacts-deep-delete' : 'artifacts',
};
