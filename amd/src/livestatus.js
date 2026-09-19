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

/** @var {number} How often to poll when the tab is not being looked at. */
const BACKGROUND_INTERVAL = 30000;

/** @var {number} Stop asking after this long without the page being looked at. */
const MAX_IDLE = 30 * 60 * 1000;

/** @var {string|null} The shape the page was rendered with. */
let renderedShape = null;

/** @var {number} Which experiment the page is showing. */
let experimentId = 0;

/** @var {number} Consecutive failed polls. */
let failures = 0;

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
    if (!node) {
        return;
    }

    if (node.textContent !== String(value)) {
        node.textContent = String(value);
    }

    // A region with nothing to say stays out of the way. Notices that are
    // always present are notices people stop reading.
    if (node.hasAttribute('style') || node.classList.contains('alert')) {
        node.style.display = String(value) === '' ? 'none' : '';
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
            // The experiment the page is showing. Polling globally while the
            // table beside it showed one experiment made the two disagree by
            // construction.
            args: {experimentid: experimentId},
        }])[0];

        failures = 0;
        setRegion('catquizlab-liveerror', '');

        setRegion('catquizlab-liveworkers', status.liveworkers);
        setRegion('catquizlab-queued', status.queued);
        setRegion('catquizlab-running', status.running);
        setRegion('catquizlab-collected', status.collected);
        setRegion('catquizlab-failed', status.failed);

        (status.regions || []).forEach((region) => {
            setRegion(region.name, region.text);
        });

        // The run rows, from the same verdict the page was rendered with —
        // there is no second opinion about a run's state on this side.
        (status.runs || []).forEach((run) => {
            setRegion(`catquizlab-run-${run.runid}-state`, run.state);
            setRegion(`catquizlab-run-${run.runid}-progress`, `${run.done} / ${run.total}`);

            const bar = document.querySelector(`[data-region="catquizlab-run-${run.runid}-bar"]`);
            if (bar) {
                bar.style.width = `${run.percent}%`;
                bar.setAttribute('aria-valuenow', String(run.percent));
            }
        });

        // The progress snapshot: the same numbers the page was rendered with,
        // recomputed server-side. There is no arithmetic on this side, so the
        // header and the rows cannot drift apart.
        if (status.progress) {
            setRegion('catquizlab-progress-label', status.progress.label);
            setRegion('catquizlab-progress-count', `${status.progress.done} / ${status.progress.total}`);
            setRegion('catquizlab-progress-reason', status.progress.reason || '');

            const overall = document.querySelector('[data-region="catquizlab-progress-bar"]');
            if (overall) {
                overall.style.width = `${status.progress.percent}%`;
                overall.setAttribute('aria-valuenow', String(status.progress.percent));
            }

            (status.progress.runs || []).forEach((run) => {
                setRegion(`catquizlab-run-${run.runid}-progress`, `${run.done} / ${run.total}`);

                const bar = document.querySelector(`[data-region="catquizlab-run-${run.runid}-bar"]`);
                if (bar) {
                    bar.style.width = `${run.percent}%`;
                    bar.setAttribute('aria-valuenow', String(run.percent));
                    bar.classList.toggle('bg-danger', Boolean(run.held));
                }
            });
        }

        if (status.experiment) {
            setRegion('catquizlab-experiment-state', status.experiment.label);
            setRegion(
                'catquizlab-experiment-progress',
                `${status.experiment.done} / ${status.experiment.total}`
            );
        }

        if (renderedShape === null) {
            renderedShape = status.shape;
        } else if (renderedShape !== status.shape) {
            // The page has more or fewer rows than it was rendered with. That
            // is the one change patching cannot make honestly.
            window.location.reload();
        }
    } catch (error) {
        // Said, not swallowed. Stopping silently left somebody watching a page
        // that had quietly stopped being live, which is worse than a transient
        // network error: they read stale numbers as current ones.
        failures++;

        if (failures >= 3) {
            setRegion('catquizlab-liveerror', M.util.get_string('live:interrupted', 'local_catquizlab'));
            stop();
        }
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
 * @param {number} experimentid The experiment the page is showing, or 0.
 */
export const init = (shape, experimentid) => {
    renderedShape = shape || null;
    experimentId = parseInt(experimentid, 10) || 0;
    failures = 0;
    startedAt = Date.now();

    stop();
    timer = window.setInterval(poll, INTERVAL);

    // A tab nobody is looking at does not need a request every two seconds.
    // Polling a hidden tab at full rate is a cost paid by the server for
    // nothing, and on a laptop it is paid by the battery.
    document.addEventListener('visibilitychange', () => {
        if (timer === null) {
            return;
        }

        window.clearInterval(timer);
        timer = window.setInterval(poll, document.hidden ? BACKGROUND_INTERVAL : INTERVAL);

        // Coming back to the tab should show the current state, not a state up
        // to thirty seconds old.
        if (!document.hidden) {
            poll();
        }
    });

    // Ask as soon as the tab is looked at again, rather than waiting out the
    // interval on a page that may be minutes stale.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            poll();
        }
    });
};
