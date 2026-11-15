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
 * Playwright with a screen reader: one worker, a visible browser (screen readers ignore headless ones). The pages
 * come from the recording of the interface run (snapshot/results.har); nothing is served.
 *
 * SR=nvda runs Chromium (Windows), SR=voiceover WebKit (macOS). One retry absorbs a slow start of the screen reader;
 * the report marks a test that needed it as flaky, and what the reader said is kept for every attempt.
 */

const {screenReaderConfig} = require('@guidepup/playwright');

const BROWSER = process.env.SR === 'voiceover' ? 'webkit' : 'chromium';

module.exports = {
    ...screenReaderConfig,
    testDir: '.',
    testMatch: '*.spec.js',
    timeout: 5 * 60 * 1000,
    retries: 1,
    workers: 1,
    fullyParallel: false,
    outputDir: 'test-results',
    reporter: [['list'], ['html', {outputFolder: 'report', open: 'never'}], ['json', {outputFile: 'artifacts/results.json'}]],
    use: {
        headless: false,
        video: 'on',
        trace: 'on',
    },
    projects: [{name: BROWSER, use: {browserName: BROWSER, headless: false}}],
};
