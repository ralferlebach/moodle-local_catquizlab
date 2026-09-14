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
 * Keep the overview's counters and verdict current while workers run.
 *
 * The page was rendered once and then went stale behind the work it describes,
 * so watching a queue drain meant reloading. This updates the numbers in place
 * and reloads the page only when the overall verdict changes — that is where
 * the rest of the page, the run rows and their progress, stops matching what
 * the counters say.
 *
 * Polling stops when the tab is hidden and when the page has been idle for a
 * long time: a browser tab left open overnight should not keep asking.
 *
 * @module     local_catquizlab/livestatus
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/** @var {number} How often to ask, in milliseconds. */
const INTERVAL = 5000;

/** @var {number} Stop polling after this long without the page being looked at. */
const MAX_IDLE = 30 * 60 * 1000;

/** @var {string|null} The verdict the page was rendered with. */
let renderedState = null;

/** @var {number} When polling started. */
let startedAt = 0;

/** @var {number|null} The timer. */
let timer = null;

/**
 * Write one value into its element, if the page has one.
 *
 * @param {string} region The data-region name.
 * @param {string|number} value What to show.
 */
const setRegion = (region, value) => {
    const node = document.querySelector(`[data-region="${region}"]`);
    if (node && node.textContent !== String(value)) {
        node.textContent = String(value);
    }
};

/**
 * Ask the server and update the page.
 *
 * @returns {Promise<void>}
 */
const poll = async() => {
    // Nothing is gained by asking while nobody is looking, and a hidden tab
    // that keeps polling is a hidden tab that keeps a server busy.
    if (document.hidden) {
        return;
    }

    if (Date.now() - startedAt > MAX_IDLE) {
        stop();
        return;
    }

    try {
        const status = await Ajax.call([{
            methodname: 'local_catquizlab_live_status',
            args: {},
        }])[0];

        setRegion('catquizlab-liveworkers', status.liveworkers);
        setRegion('catquizlab-queued', status.queued);
        setRegion('catquizlab-running', status.running);
        setRegion('catquizlab-collected', status.collected);
        setRegion('catquizlab-failed', status.failed);

        const headline = document.querySelector('[data-region="catquizlab-situation"] strong');
        if (headline && headline.textContent !== status.headline) {
            headline.textContent = status.headline;
        }

        if (renderedState === null) {
            renderedState = status.changed;
        } else if (renderedState !== status.changed) {
            // The verdict decides more than one line: the colour of the banner,
            // whether there is a button, and which run rows are actionable.
            // Patching all of that from here would be a second renderer.
            window.location.reload();
        }
    } catch (error) {
        // A failed poll is not worth an error message on a page somebody is
        // watching: the next one is five seconds away, and a page that shouts
        // about a transient network error is a page people stop trusting.
        stop();
    }
};

/**
 * Stop polling.
 */
const stop = () => {
    if (timer !== null) {
        window.clearInterval(timer);
        timer = null;
    }
};

/**
 * Start keeping the overview current.
 *
 * @param {string} state The verdict the page was rendered with.
 */
export const init = (state) => {
    renderedState = state || null;
    startedAt = Date.now();

    stop();
    timer = window.setInterval(poll, INTERVAL);

    // Ask once as soon as the tab is looked at again, rather than waiting out
    // the interval on a page that may be minutes stale.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            poll();
        }
    });
};
