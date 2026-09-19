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
module.exports = {
    testDir: '.',
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
        ['html', {outputFolder: 'report', open: 'never'}],
        ['json', {outputFile: 'report/results.json'}],
    ],
    use: {
        baseURL: process.env.MOODLE_URL || 'http://127.0.0.1:8000',
        headless: true,
        video: 'on',
        trace: 'on',
        screenshot: 'on',
        actionTimeout: 60 * 1000,
        navigationTimeout: 120 * 1000,
        viewport: {width: 1440, height: 900},
    },
    outputDir: 'artifacts',
};
