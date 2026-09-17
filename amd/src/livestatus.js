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
 * Keep the operations view current while work is being done.
 *
 * The first version reloaded the whole page whenever the overall verdict
 * changed, which is the thing somebody watching a run notices most: the scroll
 * position goes, an open detail closes, and a form half filled in is gone.
 *
 * So the regions are patched in place, and a reload is kept for the one case
 * that cannot be patched — the page gaining or losing rows. Adding a run row
 * from here would mean a second renderer deciding what a run row looks like,
 * and two renderers disagree eventually.
 *
 * @module     local_catquizlab/livestatus
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/** @var {number} How often to ask while the tab is visible. */
const INTERVAL = 2000;

/** @var {number} Stop asking after this long without the page being looked at. */
const MAX_IDLE = 30 * 60 * 1000;

/** @var {string|null} The shape the page was rendered with. */
let renderedShape = null;

/** @var {number} When polling started. */
let startedAt = 0;

/** @var {number|null} The timer. */
let timer = null;

/**
 * Write one value into its region, if the page has one.
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

        (status.regions || []).forEach((region) => {
            setRegion(region.name, region.text);
        });

        if (renderedShape === null) {
            renderedShape = status.shape;
        } else if (renderedShape !== status.shape) {
            // The page has more or fewer rows than it was rendered with. That
            // is the one change patching cannot make honestly.
            window.location.reload();
        }
    } catch (error) {
        // A failed poll is not worth an error message on a page somebody is
        // watching: a page that shouts about a transient network error is a
        // page people stop trusting.
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
 * Start keeping the view current.
 *
 * @param {string} shape The shape the page was rendered with.
 */
export const init = (shape) => {
    renderedShape = shape || null;
    startedAt = Date.now();

    stop();
    timer = window.setInterval(poll, INTERVAL);

    // Ask as soon as the tab is looked at again, rather than waiting out the
    // interval on a page that may be minutes stale.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            poll();
        }
    });
};
