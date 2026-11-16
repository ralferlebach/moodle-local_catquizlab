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
 * Log-normal item discrimination as three coupled inputs with a live density sketch (#159).
 *
 * μlog, σlog and E(a): the two the author changed last decide, the third is computed from them — the same arithmetic
 * as classes/local/lognormal.php, which repeats it on the server. A computed value is shown rounded but kept at full
 * precision, and never counts as typed. Incomplete input is tolerated while typing; an unsolvable pair is said,
 * and no curve is drawn for it.
 *
 * @module     local_catquizlab/lognormal
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const ROLES = ['mu', 'sigma', 'mean'];
const DIGITS = 6;
const SVG = 'http://www.w3.org/2000/svg';

/** @type {string} The decimal separator of the user's language. */
let decsep = '.';

/**
 * The standard normal quantile (Acklam), as on the server.
 *
 * @param {number} p Probability in (0, 1).
 * @returns {number}
 */
const normalQuantile = (p) => {
    const a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02,
        -3.066479806614716e+01, 2.506628277459239e+00];
    const b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01,
        -1.328068155288572e+01];
    const c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00,
        4.374664141464968e+00, 2.938163982698783e+00];
    const d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
    const tail = (q) => (((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5])
        / ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1);
    if (p < 0.02425) {
        return tail(Math.sqrt(-2 * Math.log(p)));
    }
    if (p > 1 - 0.02425) {
        return -tail(Math.sqrt(-2 * Math.log(1 - p)));
    }
    const q = p - 0.5;
    const r = q * q;
    return (((((a[0] * r + a[1]) * r + a[2]) * r + a[3]) * r + a[4]) * r + a[5]) * q
        / (((((b[0] * r + b[1]) * r + b[2]) * r + b[3]) * r + b[4]) * r + 1);
};

/**
 * All three from two, or why not: the same rules as lognormal::solve().
 *
 * @param {Object} given Two of mu, sigma, mean.
 * @returns {{ok: boolean, mu: number, sigma: number, mean: number, error: string}}
 */
export const solve = (given) => {
    const has = (k) => Object.prototype.hasOwnProperty.call(given, k);
    for (const key of Object.keys(given)) {
        if (!Number.isFinite(given[key])) {
            return {ok: false, error: 'lognormal:errorfinite'};
        }
    }
    let {mu, sigma, mean} = given;
    if (has('sigma') && sigma < 0) {
        return {ok: false, error: 'lognormal:errorsigma'};
    }
    if (has('mean') && mean <= 0) {
        return {ok: false, error: 'lognormal:errormean'};
    }
    if (has('mu') && has('sigma')) {
        mean = Math.exp(mu + sigma * sigma / 2);
    } else if (has('mean') && has('sigma')) {
        mu = Math.log(mean) - sigma * sigma / 2;
    } else if (has('mean') && has('mu')) {
        const radicand = 2 * (Math.log(mean) - mu);
        if (radicand < -1e-12) {
            return {ok: false, error: 'lognormal:errorbelow'};
        }
        sigma = Math.sqrt(Math.max(0, radicand));
    } else {
        return {ok: false, error: 'lognormal:errortwo'};
    }
    if (![mu, sigma, mean].every(Number.isFinite)) {
        return {ok: false, error: 'lognormal:errorfinite'};
    }
    return {ok: true, mu, sigma, mean, error: ''};
};

/**
 * A number as the user writes it, or NaN; empty is NaN, not zero.
 *
 * @param {string} text What is in the input.
 * @param {string} separator The decimal separator (the user's language's when not given).
 * @returns {number}
 */
export const parse = (text, separator = decsep) => {
    const t = String(text).trim().replace(separator, '.');
    return t === '' || !/^[-+]?(\d+\.?\d*|\.\d+)(e[-+]?\d+)?$/i.test(t) ? NaN : Number(t);
};

/**
 * A number for display, in the user's decimal separator, rounded only here.
 *
 * @param {number} value The value.
 * @param {string} separator The decimal separator (the user's language's when not given).
 * @returns {string}
 */
export const format = (value, separator = decsep) => String(Number(value.toFixed(DIGITS))).replace('.', separator);

/**
 * An SVG element.
 *
 * @param {string} name The tag.
 * @param {Object} attrs Attributes.
 * @param {string} text Text content.
 * @returns {Element}
 */
const el = (name, attrs, text = '') => {
    const node = document.createElementNS(SVG, name);
    Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, String(v)));
    if (text !== '') {
        node.textContent = text;
    }
    return node;
};

/**
 * A round step at least this big: 1, 2 or 5 times a power of ten.
 *
 * @param {number} raw The least step.
 * @returns {number}
 */
const nice = (raw) => {
    const power = Math.pow(10, Math.floor(Math.log10(raw)));
    return [1, 2, 5, 10].map((m) => m * power).find((s) => s >= raw);
};

/**
 * The range shown and its scale (#159 AK-12): the 0.5th to the 99.5th percentile, at least past E(a).
 *
 * - Strongly skewed (the 99.5th percentile more than a hundred times the 0.5th, σlog above about 0.9): on a linear
 *   axis from 0 the bulk sat in the first few pixels and the long tail filled the rest. The axis is logarithmic
 *   then, labelled in values of a; the curve is the density over log a, which is what a log axis shows.
 * - Narrow (the 0.5th percentile more than a quarter of the 99.5th): from 0 the curve was a spike a few pixels wide
 *   and its peak fell between the sampled points. The axis starts below the 0.5th percentile then, at a round value.
 * - Otherwise from 0, as before.
 *
 * @param {Object} p The parameters.
 * @returns {{logscale: boolean, xmin: number, xmax: number}}
 */
const axisRange = (p) => {
    const qlo = Math.exp(p.mu + p.sigma * normalQuantile(0.005));
    const qhi = Math.max(Math.exp(p.mu + p.sigma * normalQuantile(0.995)), p.mean * 1.1);
    const logscale = qhi / qlo > 100;
    let xmin = 0;
    if (logscale) {
        xmin = qlo;
    } else if (qlo / qhi > 0.25) {
        const step = nice((qhi - qlo) / 4);
        xmin = Math.max(0, Math.floor(qlo / step) * step);
    }
    return {logscale, xmin, xmax: qhi};
};

/**
 * The curve's points over the axis, with the peak as a point of its own, and the highest density.
 *
 * The height drawn is the density of a, or on a log axis the density of log a (the normal density of z). The peak
 * (the mode of a, or the median on a log axis) is added where the grid could miss it.
 *
 * @param {Object} p The parameters.
 * @param {{logscale: boolean, xmin: number, xmax: number}} axis The range shown.
 * @returns {{points: number[][], ymax: number}}
 */
const curve = (p, axis) => {
    const {logscale, xmin, xmax} = axis;
    const density = (x) => {
        if (x <= 0) {
            return 0;
        }
        const z = (Math.log(x) - p.mu) / p.sigma;
        const normal = Math.exp(-0.5 * z * z) / (p.sigma * Math.sqrt(2 * Math.PI));
        return logscale ? normal : normal / x;
    };
    const n = 240;
    const points = [];
    let ymax = 0;
    for (let i = 0; i <= n; i++) {
        const x = logscale ? Math.exp(Math.log(xmin) + (Math.log(xmax) - Math.log(xmin)) * i / n)
            : xmin + (xmax - xmin) * i / n;
        if (x <= 0) {
            continue;
        }
        const y = density(x);
        ymax = Math.max(ymax, y);
        points.push([x, y]);
    }
    const peak = logscale ? Math.exp(p.mu) : Math.exp(p.mu - p.sigma * p.sigma);
    if (peak >= xmin && peak <= xmax) {
        ymax = Math.max(ymax, density(peak));
        points.push([peak, density(peak)]);
        points.sort((a, b) => a[0] - b[0]);
    }
    return {points, ymax};
};

/**
 * The values at which the axis gets a tick, each with the decimals its label needs: on a linear axis 1, 2 or 5 times
 * a power of ten apart; on a log axis 1, 2 and 5 of each decade in the range.
 *
 * @param {{logscale: boolean, xmin: number, xmax: number}} axis The range shown.
 * @returns {number[][]} [value, decimals] pairs.
 */
const ticks = (axis) => {
    const {logscale, xmin, xmax} = axis;
    const out = [];
    if (logscale) {
        for (let decade = Math.floor(Math.log10(xmin)); decade <= Math.ceil(Math.log10(xmax)); decade++) {
            [1, 2, 5].map((m) => m * Math.pow(10, decade)).filter((x) => x >= xmin && x <= xmax)
                .forEach((x) => out.push([x, Math.max(0, -decade)]));
        }
        return out;
    }
    const step = nice((xmax - xmin) / 4);
    const decimals = Math.max(0, -Math.floor(Math.log10(step)));
    for (let i = Math.ceil(xmin / step - 1e-9); i * step <= xmax + 1e-9; i++) {
        const x = Number((i * step).toPrecision(12));
        if (x > 0 || i === 0) {
            // On a linear axis 0 is a tick of its own; on one that starts later there is none below its start.
            out.push([x, decimals]);
        }
    }
    return out;
};

/**
 * Draw the density of a for μlog, σlog, with E(a) and the central 90 % marked; or say why there is nothing.
 *
 * @param {Element} svg The drawing.
 * @param {Element} caption The text alternative.
 * @param {Object|null} p The parameters, or null when the input has no solution.
 * @param {string} problem Why not, in words.
 */
const draw = (svg, caption, p, problem) => {
    while (svg.firstChild) {
        svg.removeChild(svg.firstChild);
    }
    const str = (key, a) => M.util.get_string(key, 'local_catquizlab', a);
    if (p === null) {
        svg.setAttribute('data-state', 'invalid');
        svg.appendChild(el('text', {x: 240, y: 110, 'text-anchor': 'middle', 'font-size': 13}, problem));
        caption.textContent = problem;
        return;
    }
    const left = 46;
    const right = 466;
    const top = 14;
    const bottom = 180;
    const q05 = Math.exp(p.mu + p.sigma * normalQuantile(0.05));
    const q95 = Math.exp(p.mu + p.sigma * normalQuantile(0.95));
    const median = Math.exp(p.mu);
    if (p.sigma === 0) {
        // No spread: one value, not a density.
        svg.setAttribute('data-state', 'constant');
        const x = (left + right) / 2;
        svg.appendChild(el('line', {x1: x, x2: x, y1: top, y2: bottom, stroke: 'currentColor', 'stroke-width': 3}));
        svg.appendChild(el('text', {x: x, y: bottom + 18, 'text-anchor': 'middle', 'font-size': 12},
            str('lognormal:constant', format(median))));
        caption.textContent = str('lognormal:constant', format(median));
        return;
    }
    svg.setAttribute('data-state', 'curve');
    const axis = axisRange(p);
    const {logscale, xmin, xmax} = axis;
    svg.setAttribute('data-scale', logscale ? 'log' : 'linear');
    svg.setAttribute('data-xmin', xmin);
    svg.setAttribute('data-xmax', xmax);
    // The position of a value on the axis, 0..1.
    const pos = logscale
        ? (x) => (Math.log(x) - Math.log(xmin)) / (Math.log(xmax) - Math.log(xmin))
        : (x) => (x - xmin) / (xmax - xmin);
    const drawn = curve(p, axis);
    const points = drawn.points;
    const ymax = drawn.ymax * 1.08;
    const sx = (x) => left + (right - left) * pos(x);
    const sy = (y) => bottom - (bottom - top) * y / ymax;

    // The central 90 %.
    const band = points.filter(([x]) => x >= q05 && x <= q95);
    if (band.length > 1) {
        const d = `M${sx(band[0][0])},${bottom} ` + band.map(([x, y]) => `L${sx(x)},${sy(y)}`).join(' ')
            + ` L${sx(band[band.length - 1][0])},${bottom} Z`;
        svg.appendChild(el('path', {d, fill: 'currentColor', 'fill-opacity': 0.15, 'data-role': 'interval'}));
    }
    svg.appendChild(el('path', {d: 'M' + points.map(([x, y]) => `${sx(x)},${sy(y)}`).join(' L'), fill: 'none',
        stroke: 'currentColor', 'stroke-width': 2, 'data-role': 'density'}));
    // E(a), labelled.
    svg.appendChild(el('line', {x1: sx(p.mean), x2: sx(p.mean), y1: top, y2: bottom, stroke: 'currentColor',
        'stroke-dasharray': '5 3', 'data-role': 'mean', 'data-value': p.mean}));
    svg.appendChild(el('text', {x: sx(p.mean) + 4, y: top + 10, 'font-size': 11}, str('lognormal:meanline', format(p.mean))));
    // Axes and their names.
    svg.appendChild(el('line', {x1: left, x2: right, y1: bottom, y2: bottom, stroke: 'currentColor'}));
    svg.appendChild(el('line', {x1: left, x2: left, y1: top, y2: bottom, stroke: 'currentColor'}));
    // Ticks at round numbers, labelled with as few decimals as they need.
    ticks(axis).forEach(([x, decimals]) => {
        const label = String(Number(x.toFixed(decimals))).replace('.', decsep);
        svg.appendChild(el('line', {x1: sx(x), x2: sx(x), y1: bottom, y2: bottom + 3, stroke: 'currentColor'}));
        svg.appendChild(el('text', {x: sx(x), y: bottom + 14, 'text-anchor': 'middle', 'font-size': 10,
            'data-role': 'tick', 'data-value': x}, label));
    });
    svg.appendChild(el('text', {x: (left + right) / 2, y: bottom + 32, 'text-anchor': 'middle', 'font-size': 12},
        str(logscale ? 'lognormal:xaxislog' : 'lognormal:xaxis')));
    svg.appendChild(el('text', {x: 12, y: (top + bottom) / 2, 'text-anchor': 'middle', 'font-size': 12,
        transform: `rotate(-90 12 ${(top + bottom) / 2})`}, str(logscale ? 'lognormal:yaxislog' : 'lognormal:yaxis')));
    caption.textContent = str('lognormal:summary', {mu: format(p.mu), sigma: format(p.sigma), mean: format(p.mean),
        median: format(median), low: format(q05), high: format(q95)})
        + (logscale ? ' ' + str('lognormal:logaxisnote') : '')
        + (!logscale && xmin > 0 ? ' ' + str('lognormal:fromnote', format(xmin)) : '');
};

/**
 * Couple the three inputs and the drawing.
 *
 * @param {string} separator The decimal separator of the user's language.
 */
export const init = (separator) => {
    decsep = separator || '.';
    const inputs = {};
    ROLES.forEach((role) => {
        inputs[role] = document.querySelector(`input[data-lognormal="${role}"]`);
    });
    const source = document.querySelector('input[name="lognormalsource"]');
    const svg = document.querySelector('[data-region="catquizlab-lognormal-svg"]');
    const caption = document.querySelector('[data-region="catquizlab-lognormal-text"]');
    if (ROLES.some((role) => !inputs[role]) || !svg || !caption) {
        return;
    }
    // The two typed last, oldest first: μlog and σlog to begin with.
    let typed = (source && source.value ? source.value.split(',') : ['mu', 'sigma'])
        .filter((r) => ROLES.includes(r)).slice(-2);
    if (typed.length !== 2) {
        typed = ['mu', 'sigma'];
    }
    // Full precision of computed values: the display is rounded, the arithmetic is not.
    const exact = {};

    const update = () => {
        const given = {};
        typed.forEach((role) => {
            given[role] = exact[role] !== undefined ? exact[role] : parse(inputs[role].value);
        });
        const computed = ROLES.find((role) => !typed.includes(role));
        const result = solve(given);
        ROLES.forEach((role) => {
            inputs[role].removeAttribute('aria-invalid');
            inputs[role].readOnly = false;
        });
        inputs[computed].setAttribute('data-computed', '1');
        typed.forEach((role) => inputs[role].removeAttribute('data-computed'));
        if (source) {
            source.value = typed.join(',');
        }
        if (!result.ok) {
            // The field the trouble belongs to, marked; the computed one emptied rather than left stale.
            const culprits = {'lognormal:errorsigma': 'sigma', 'lognormal:errormean': 'mean'};
            const culprit = culprits[result.error] || computed;
            inputs[culprit].setAttribute('aria-invalid', 'true');
            if (culprit === computed) {
                inputs[computed].value = '';
            }
            delete exact[computed];
            draw(svg, caption, null, M.util.get_string(result.error, 'local_catquizlab'));
            return;
        }
        exact[computed] = result[computed];
        inputs[computed].value = format(result[computed]);
        draw(svg, caption, result, '');
    };

    ROLES.forEach((role) => {
        inputs[role].addEventListener('input', () => {
            // Typed: this one decides now, with the other one typed last; its exact value is what was typed.
            delete exact[role];
            typed = typed.filter((r) => r !== role).concat(role).slice(-2);
            if (typed.length < 2) {
                typed = [ROLES.find((r) => r !== role && r !== 'mean') || 'mu', role];
            }
            update();
        });
    });
    update();
};
