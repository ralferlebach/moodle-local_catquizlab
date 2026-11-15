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
 * The point markers of a plot from the keyboard (#143 AK7, #144 AK6).
 *
 * A plot's markers are one stop in the tab order (scatter_chart puts tabindex 0 on the first, -1 on the others). The
 * arrow keys move the focus from marker to marker in the order they are drawn — x, then y — Home and End to the first
 * and the last; the marker that had the focus last is the plot's stop the next time. What the focused marker stands
 * for is shown beneath the drawing; a screen reader reads the marker's own description.
 *
 * @module     local_catquizlab/plot_points
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const POINT = 'circle[data-region="point"]';
const BOX = '[data-region="catquizlab-chart-points"]';
const READOUT = '[data-region="catquizlab-point-readout"]';

/** @type {Object<string, function(number, number): number>} Where each key moves, from position i of n. */
const MOVES = {
    ArrowRight: (i, n) => Math.min(n - 1, i + 1),
    ArrowDown: (i, n) => Math.min(n - 1, i + 1),
    ArrowLeft: (i) => Math.max(0, i - 1),
    ArrowUp: (i) => Math.max(0, i - 1),
    Home: () => 0,
    End: (i, n) => n - 1,
};

/**
 * The readout beneath the plot of a marker.
 *
 * @param {Element} marker The marker.
 * @returns {?HTMLElement}
 */
const readout = (marker) => {
    const box = marker.closest(BOX);
    return box ? box.querySelector(READOUT) : null;
};

/**
 * Moves the plot's tab stop to a marker and focuses it.
 *
 * @param {Element[]} markers The plot's markers.
 * @param {Element} to The marker.
 */
const focus = (markers, to) => {
    markers.forEach((marker) => marker.setAttribute('tabindex', marker === to ? '0' : '-1'));
    to.focus();
};

let started = false;

export const init = () => {
    if (started) {
        return;
    }
    started = true;

    document.addEventListener('keydown', (e) => {
        const marker = e.target.closest ? e.target.closest(POINT) : null;
        if (!marker || !MOVES[e.key] || e.altKey || e.ctrlKey || e.metaKey) {
            return;
        }
        const svg = marker.ownerSVGElement || marker.closest('svg');
        const markers = Array.from(svg.querySelectorAll(POINT));
        const at = markers.indexOf(marker);
        const to = markers[MOVES[e.key](at, markers.length)];
        e.preventDefault();
        if (to && to !== marker) {
            focus(markers, to);
        }
    });

    document.addEventListener('focusin', (e) => {
        const marker = e.target.closest ? e.target.closest(POINT) : null;
        if (!marker) {
            return;
        }
        const box = readout(marker);
        if (box) {
            box.textContent = marker.getAttribute('aria-label') || '';
            box.hidden = false;
        }
        marker.setAttribute('data-focused', '1');
    });

    document.addEventListener('focusout', (e) => {
        const marker = e.target.closest ? e.target.closest(POINT) : null;
        if (marker) {
            marker.removeAttribute('data-focused');
        }
    });

    document.documentElement.setAttribute('data-catquizlab-plotkeys', 'ready');
};
