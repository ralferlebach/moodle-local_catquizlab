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

/** @type {number} The page of the overview's sections (#158). */
let overviewPage = 0;

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
/**
 * The question bar (#141): text, kind and width as the server computed them.
 *
 * @param {Object|undefined} questions The snapshot's question figures.
 */
const setQuestions = (questions) => {
    if (!questions) {
        return;
    }
    setRegion('catquizlab-questions-text', questions.text);
    setRegion('catquizlab-questions-kind', questions.kindlabel);
    setRegion('catquizlab-questions-discarded', questions.discardedtext || '');
    const bar = document.querySelector('[data-region="catquizlab-questions-bar"]');
    if (bar) {
        bar.style.width = `${questions.percent}%`;
        bar.setAttribute('aria-valuenow', String(questions.percent));
        bar.setAttribute('aria-valuetext', questions.text);
    }
    const box = document.querySelector('[data-region="catquizlab-questions"]');
    if (box) {
        box.setAttribute('data-kind', questions.kind);
        // The count as a number, for whatever reads the page without parsing words (#141 DoD 2).
        box.setAttribute('data-done', String(questions.done));
    }
};

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
 * The run rows: the server renders the card and the page swaps it in.
 *
 * Two hidden spans used to be updated beside a visible card that never changed. There is deliberately no status
 * logic here to disagree with the server's.
 *
 * @param {Array} runs The runs of the snapshot.
 */
const applyRunCards = (runs) => {
    (runs || []).forEach((run) => {
        const cell = document.querySelector(`[data-region="catquizlab-run-${run.runid}-card"]`);
        if (cell && run.cardhtml && cell.innerHTML.trim() !== run.cardhtml.trim()) {
            cell.innerHTML = run.cardhtml;
        }

        const bar = document.querySelector(`[data-region="catquizlab-run-${run.runid}-bar"]`);
        if (bar) {
            bar.style.width = `${run.percent}%`;
            bar.setAttribute('aria-valuenow', String(run.percent));
        }
    });
};

/**
 * The experiment's status bar (#128): the same snapshot as everything below.
 *
 * @param {Object} state The status of the snapshot.
 * @returns {boolean} Whether the page must be reloaded: a different next step means a different button.
 */
const applyStatusBar = (state) => {
    const bar = document.querySelector('[data-region="catquizlab-status"]');
    if (bar) {
        if (bar.getAttribute('data-next') && bar.getAttribute('data-next') !== state.next) {
            return true;
        }
        bar.setAttribute('data-phase', state.phase);
    }
    setRegion('catquizlab-status-label', state.label);
    setRegion('catquizlab-status-sittings', state.sittings);
    setRegion('catquizlab-status-problems', state.problemtext);
    setRegion('catquizlab-status-reason', state.reason);
    setRegion('catquizlab-status-asof', state.computedtext);
    setRegion('catquizlab-status-technical', state.technical);
    return false;
};

/**
 * The times (#125): text from the server, nothing computed here.
 *
 * @param {Object} time The times of the snapshot.
 */
const applyTimes = (time) => {
    setRegion('catquizlab-eta-started', time.text.started);
    setRegion('catquizlab-eta-elapsed', time.text.elapsed);
    setRegion('catquizlab-eta-finish', time.text.finish);
    setRegion('catquizlab-eta-remaining', time.text.remaining);
    setRegion('catquizlab-eta-own', time.text.own);
    setRegion('catquizlab-eta-status', time.text.status);
    setRegion('catquizlab-eta-reason', time.text.reason);
    setRegion('catquizlab-eta-method', time.text.method);
    setRegion('catquizlab-eta-asof', M.util.get_string('eta:asof', 'local_catquizlab', time.text.computed));
    const box = document.querySelector('[data-region="catquizlab-eta"]');
    if (box) {
        box.setAttribute('data-reliability', time.reliability);
    }
    applyRunTimes(time.runs);
};

/**
 * Each run's times (#160): its own work apart from when it will be done.
 *
 * @param {Array|undefined} runs The runs' times.
 */
const applyRunTimes = (runs) => {
    (runs || []).forEach((run) => {
        setRegion(`catquizlab-run-${run.runid}-started`, run.text.started);
        setRegion(`catquizlab-run-${run.runid}-elapsed`, run.text.elapsed);
        setRegion(`catquizlab-run-${run.runid}-remaining`, run.text.remaining);
        setRegion(`catquizlab-run-${run.runid}-finish`, run.text.finish);
        setRegion(`catquizlab-run-${run.runid}-own`, run.text.own);
        setRegion(`catquizlab-run-${run.runid}-owntime`, run.text.owntime);
        setRegion(`catquizlab-run-${run.runid}-ahead`, (run.text.ahead || '') + (run.text.reason || ''));
        if (run.text.basis !== undefined) {
            setRegion(`catquizlab-run-${run.runid}-basis`, run.text.basis);
        }
    });
};

/**
 * A progress bar: width, value and what a screen reader says for it.
 *
 * @param {string} region The bar's region.
 * @param {number} percent How far along.
 * @param {string|undefined} valuetext The value in words, with its unit.
 */
const setBar = (region, percent, valuetext) => {
    const bar = document.querySelector(`[data-region="${region}"]`);
    if (!bar) {
        return;
    }
    bar.style.width = `${percent}%`;
    bar.setAttribute('aria-valuenow', String(percent));
    if (valuetext) {
        bar.setAttribute('aria-valuetext', valuetext);
    }
};

/**
 * Each run's row: sittings, questions, status, bar.
 *
 * @param {Array|undefined} runs The runs.
 */
const applyRunRows = (runs) => {
    (runs || []).forEach((run) => {
        setRegion(`catquizlab-run-${run.runid}-progress`, `${run.done} / ${run.total}`);
        if (run.questions) {
            setRegion(`catquizlab-run-${run.runid}-questions`, run.questions.text);
            const count = document.querySelector(`[data-region="catquizlab-run-${run.runid}-questions"]`);
            if (count && run.questions.done !== undefined) {
                count.setAttribute('data-done', String(run.questions.done));
            }
            setRegion(`catquizlab-run-${run.runid}-discarded`, run.questions.discardedtext || '');
        }
        if (run.statuslabel !== undefined) {
            setRegion(`catquizlab-run-${run.runid}-status`, run.statuslabel);
        }
        setBar(`catquizlab-run-${run.runid}-bar`, run.percent, run.sittingstext);
        const bar = document.querySelector(`[data-region="catquizlab-run-${run.runid}-bar"]`);
        if (bar) {
            bar.classList.toggle('bg-danger', Boolean(run.held));
        }
    });
};

/**
 * All experiments (#158): the totals, each experiment and each of its runs, written into the regions the page has.
 * Sections are never re-rendered, so one somebody opened stays open.
 *
 * @param {Object} overview The overview of the snapshot.
 */
const applyOverview = (overview) => {
    const totals = overview.totals;
    setRegion('catquizlab-overview-population', totals.text);
    setRegion('catquizlab-overview-count', `${totals.done} / ${totals.total}`);
    setBar('catquizlab-overview-bar', totals.percent, totals.sittingstext);
    setRegion('catquizlab-overview-questions', totals.questions.text);
    setBar('catquizlab-overview-questions-bar', totals.questions.percent, totals.questions.text);
    (totals.states || []).forEach((state) => setRegion(`catquizlab-overview-state-${state.state}`, state.count));
    setRegion('catquizlab-overview-finish', overview.time.text.finish);
    setRegion('catquizlab-overview-remaining', overview.time.text.remaining);
    setRegion('catquizlab-overview-status', overview.time.text.status);
    setRegion('catquizlab-overview-reason', overview.time.text.reason);
    const eta = document.querySelector('[data-region="catquizlab-overview-eta"]');
    if (eta) {
        eta.setAttribute('data-reliability', overview.time.reliability);
    }
    (overview.experiments || []).forEach((e) => {
        const prefix = `catquizlab-exp-${e.experimentid}`;
        setRegion(`${prefix}-label`, e.label);
        setRegion(`${prefix}-reason`, e.reason);
        setRegion(`${prefix}-count`, `${e.done} / ${e.total}`);
        setBar(`${prefix}-bar`, e.percent, e.sittingstext);
        setBar(`${prefix}-questions-bar`, e.questions.percent, e.questions.text);
        setRegion(`${prefix}-questions`, e.questions.text);
        ['started', 'elapsed', 'finish', 'remaining', 'own'].forEach((key) => setRegion(`${prefix}-${key}`, e.time.text[key]));
        const section = document.querySelector(`[data-region="${prefix}"]`);
        if (section) {
            section.setAttribute('data-state', e.state);
        }
        applyRunRows(e.runs);
        applyRunTimes(e.time.runs);
    });
};

/**
 * Keep the sections somebody opened or closed that way, across polls and across a reload when the page's shape
 * changes (#158 AK7). Per tab; where storage is not available, the page simply opens as rendered.
 */
const rememberSections = () => {
    document.querySelectorAll('details[data-experimentid]').forEach((section) => {
        const key = `catquizlab-exp-open-${section.getAttribute('data-experimentid')}`;
        try {
            const stored = window.sessionStorage.getItem(key);
            if (stored !== null) {
                section.open = stored === '1';
            }
        } catch (e) {
            // No storage: nothing to restore.
        }
        section.addEventListener('toggle', () => {
            try {
                window.sessionStorage.setItem(key, section.open ? '1' : '0');
            } catch (e) {
                // No storage: the state lasts until the next reload.
            }
        });
    });
};

/**
 * The progress snapshot: the same numbers the page was rendered with, recomputed server-side.
 *
 * There is no arithmetic on this side, so the header and the rows cannot drift apart.
 *
 * @param {Object} progress The progress of the snapshot.
 */
const applyProgress = (progress) => {
    setRegion('catquizlab-progress-label', progress.label);
    setRegion('catquizlab-progress-count', `${progress.done} / ${progress.total}`);
    setRegion('catquizlab-progress-reason', progress.reason || '');

    const overall = document.querySelector('[data-region="catquizlab-progress-bar"]');
    if (overall) {
        overall.style.width = `${progress.percent}%`;
        overall.setAttribute('aria-valuenow', String(progress.percent));
        if (progress.sittingstext) {
            overall.setAttribute('aria-valuetext', progress.sittingstext);
        }
    }

    // Questions (#141): the denominator and its kind come from the server.
    setQuestions(progress.questions);

    if (progress.time) {
        applyTimes(progress.time);
    }
    setRegion('catquizlab-eta-stale', '');

    applyRunRows(progress.runs);
};

/**
 * A failed poll: said, not swallowed.
 *
 * Stopping silently left somebody watching a page that had quietly stopped being live, which is worse than a
 * transient network error: they read stale numbers as current ones.
 */
const pollFailed = () => {
    failures++;

    // The figures on the page are now older than they look (#125).
    setRegion('catquizlab-eta-stale', M.util.get_string('eta:stale', 'local_catquizlab'));

    if (failures < 3) {
        return;
    }
    setRegion('catquizlab-liveerror', M.util.get_string('live:interrupted', 'local_catquizlab'));
    stop();

    // A way back that is not a full reload. The message said updating
    // had stopped and offered nothing but F5.
    const box = document.querySelector('[data-region="catquizlab-liveerror"]');
    if (box && !box.querySelector('button')) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-secondary ml-2';
        button.textContent = M.util.get_string('live:reconnect', 'local_catquizlab');
        button.addEventListener('click', () => {
            failures = 0;
            setRegion('catquizlab-liveerror', '');
            start();
        });
        box.appendChild(button);
    }
};

/**
 * Update the page from one snapshot of the server.
 *
 * @param {Object} status The snapshot.
 */
const applySnapshot = (status) => {
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

    applyRunCards(status.runs);

    if (status.status && applyStatusBar(status.status)) {
        window.location.reload();
        return;
    }

    if (status.overview) {
        applyOverview(status.overview);
    }
    if (status.progress) {
        applyProgress(status.progress);
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
            args: {experimentid: experimentId, page: overviewPage},
        }])[0];
        applySnapshot(status);
    } catch (error) {
        pollFailed();
    }
};

/**
 * Resume polling at the rate the tab's visibility calls for.
 */
const start = () => {
    stop();
    timer = window.setInterval(poll, document.hidden ? BACKGROUND_INTERVAL : INTERVAL);
    poll();
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
 * @param {number} page The page of the overview's sections, with experiment 0.
 */
export const init = (shape, experimentid, page) => {
    renderedShape = shape || null;
    experimentId = parseInt(experimentid, 10) || 0;
    overviewPage = parseInt(page, 10) || 0;
    rememberSections();
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
