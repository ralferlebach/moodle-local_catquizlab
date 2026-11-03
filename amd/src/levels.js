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
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Levels of a varied factor as rows with fields, instead of an empty textarea (#126).
 *
 * The textarea stays the value that is sent: the editor writes one line per
 * level into it, in exactly the format the server reads (experiment_form::
 * parse_levels), so saving, reloading and copying lose nothing. A level the
 * editor cannot read stays as it was typed and is marked — the server refuses
 * it with its line number as well. Without script the textarea is there as
 * before.
 *
 * @module     local_catquizlab/levels
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {string[]} The keys a disturbance strength may name; '' is a share in percent. */
const STRENGTH_KEYS = ['', 'shift', 'factor', 'sd', 'fraction'];

const str = (key, a) => M.util.get_string(key, 'local_catquizlab', a);

/**
 * One level as fields, from its line.
 *
 * @param {string} kind items, se or strength.
 * @param {string} line The line.
 * @returns {object} {a, b} for pairs, {key, value} for strengths.
 */
const fromLine = (kind, line) => {
    if (kind === 'strength') {
        const at = line.indexOf('=');
        return at >= 0
            ? {key: line.slice(0, at).trim(), value: line.slice(at + 1).trim()}
            : {key: '', value: line.trim()};
    }
    const parts = line.split('/');
    return {a: (parts[0] || '').trim(), b: (parts[1] || '').trim(), raw: parts.length !== 2 ? line : ''};
};

/**
 * The line of a level, as the server reads it.
 *
 * @param {string} kind items, se or strength.
 * @param {object} level The fields.
 * @returns {string}
 */
const toLine = (kind, level) => {
    if (kind === 'strength') {
        return level.key ? `${level.key}=${level.value}` : String(level.value);
    }
    return level.raw ? level.raw : `${level.a}/${level.b}`;
};

// A number as PHP's is_numeric() reads it, after a decimal comma became a point: the server's test, not
// JavaScript's Number(), which also takes "0x10", "Infinity" and an empty string (worker/test/levels_parity).
const numeric = (v) => /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test(String(v).trim().replace(',', '.'));
const valueOf = (v) => Number(String(v).trim().replace(',', '.'));

/**
 * What is wrong with a disturbance strength, by the server's rules; '' when nothing.
 *
 * @param {object} level The fields: key, value.
 * @returns {string}
 */
const strengthProblem = (level) => {
    if (level.key !== '' && !STRENGTH_KEYS.includes(level.key)) {
        return str('levels:unknownkeyshort', STRENGTH_KEYS.filter((k) => k !== '').join(', '));
    }
    if (!numeric(level.value)) {
        return level.key === '' ? str('levels:shareshort') : str('levels:notanumbershort');
    }
    if (level.key === '' && (valueOf(level.value) <= 0 || valueOf(level.value) > 100)) {
        return str('levels:shareshort');
    }
    return '';
};

/**
 * What is wrong with a level, by the rules the server applies; '' when nothing.
 *
 * @param {string} kind items, se or strength.
 * @param {object} level The fields.
 * @returns {string}
 */
const problem = (kind, level) => {
    if (kind === 'strength') {
        return strengthProblem(level);
    }
    // Item budgets: whole numbers written as digits only, the server's ctype_digit().
    const readable = kind === 'items'
        ? (v) => /^\d+$/.test(String(v).trim())
        : numeric;
    if (level.raw || !readable(level.a) || !readable(level.b)) {
        return str(kind === 'items' ? 'levels:itempairshort' : 'levels:sepairshort');
    }
    const a = valueOf(level.a);
    const b = valueOf(level.b);
    if (a > b) {
        return str('levels:minmaxshort');
    }
    if (kind === 'se' && a <= 0) {
        return str('levels:sepositiveshort');
    }
    return kind === 'items' && b < 1 ? str('levels:itemspositiveshort') : '';
};

/**
 * What a level is, as the server compares levels for duplicates: its numbers, not its spelling.
 *
 * @param {string} kind items, se or strength.
 * @param {object} level The fields.
 * @returns {string}
 */
const identity = (kind, level) => {
    const value = valueOf;
    if (kind === 'strength') {
        const number = value(level.value);
        return level.key === '' ? 'fraction=' + (number > 1 ? number / 100 : number) : level.key + '=' + number;
    }
    return value(level.a) + '/' + value(level.b);
};

/**
 * Turn one textarea into its editor.
 *
 * @param {HTMLTextAreaElement} area The textarea.
 */
const enhance = (area) => {
    const kind = area.dataset.catquizlabLevels;
    const field = area.name;
    const state = document.querySelector(`[data-catquizlab-levels-state="${field}"]`);
    const notvaried = state ? state.textContent : '';
    const levels = area.value.split(/\r?\n/).map((l) => l.trim()).filter((l) => l !== '').map((l) => fromLine(kind, l));

    const box = document.createElement('div');
    box.className = 'catquizlab-levels';
    box.setAttribute('data-region', 'catquizlab-levels-' + field);
    const list = document.createElement('ol');
    list.className = 'list-unstyled mb-1';
    box.appendChild(list);
    const add = document.createElement('button');
    add.type = 'button';
    add.className = 'btn btn-sm btn-outline-secondary';
    add.textContent = str('levels:add');
    box.appendChild(add);
    area.parentNode.insertBefore(box, area);
    area.hidden = true;

    const sync = () => {
        area.value = levels.map((level) => toLine(kind, level)).join('\n');
        // The form's own dependencies (hideIf on the single value) listen to this.
        area.dispatchEvent(new Event('change', {bubbles: true}));
        if (state) {
            state.textContent = levels.length ? str('levels:varied', levels.length) : notvaried;
        }
    };

    const input = (value, label, onchange, width = '6rem') => {
        const el = document.createElement('input');
        el.type = 'text';
        el.inputMode = 'decimal';
        el.className = 'form-control form-control-sm d-inline-block mr-1';
        el.style.width = width;
        el.value = value;
        el.setAttribute('aria-label', label);
        el.addEventListener('input', () => onchange(el.value));
        return el;
    };

    const render = () => {
        list.innerHTML = '';
        levels.forEach((level, index) => {
            const row = document.createElement('li');
            row.className = 'mb-1';
            row.setAttribute('data-level', String(index + 1));
            const message = document.createElement('span');
            message.className = 'small text-danger ml-1';
            message.setAttribute('role', 'status');
            const check = () => {
                // The same level twice is refused by the server too.
                const twice = !problem(kind, level) && levels.slice(0, index).some((other) =>
                    !problem(kind, other) && identity(kind, other) === identity(kind, level));
                const why = problem(kind, level) || (twice ? str('levels:duplicateshort') : '');
                message.textContent = why;
                row.querySelectorAll('input, select').forEach((el) => el.setAttribute('aria-invalid', why ? 'true' : 'false'));
            };
            // The level's number goes into the string, so that each language places it itself.
            const label = (name) => str(name, index + 1);
            if (kind === 'strength') {
                const select = document.createElement('select');
                select.className = 'custom-select custom-select-sm d-inline-block mr-1';
                select.style.width = '10rem';
                select.setAttribute('aria-label', label('levels:key'));
                STRENGTH_KEYS.forEach((key) => {
                    const option = document.createElement('option');
                    option.value = key;
                    option.textContent = key === '' ? str('levels:sharepercent') : str('levels:key_' + key);
                    option.selected = key === level.key;
                    select.appendChild(option);
                });
                select.addEventListener('change', () => {
                    level.key = select.value;
                    check();
                    sync();
                });
                row.appendChild(select);
                row.appendChild(input(level.value, label('levels:value'), (v) => {
                    level.value = v;
                    check();
                    sync();
                }));
            } else {
                row.appendChild(input(level.a, label('levels:min'), (v) => {
                    level.a = v;
                    level.raw = '';
                    check();
                    sync();
                }));
                row.appendChild(document.createTextNode('/ '));
                row.appendChild(input(level.b, label('levels:max'), (v) => {
                    level.b = v;
                    level.raw = '';
                    check();
                    sync();
                }));
            }
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-link';
            remove.textContent = str('levels:removeshort');
            remove.setAttribute('aria-label', label('levels:remove'));
            remove.addEventListener('click', () => {
                levels.splice(index, 1);
                render();
                sync();
                add.focus();
            });
            row.appendChild(remove);
            row.appendChild(message);
            list.appendChild(row);
            check();
        });
    };

    add.addEventListener('click', () => {
        levels.push(kind === 'strength' ? {key: '', value: ''} : {a: '', b: '', raw: ''});
        render();
        sync();
        const fields = list.lastElementChild.querySelectorAll('input');
        fields[0].focus();
    });

    render();
    if (state && levels.length) {
        state.textContent = str('levels:varied', levels.length);
    }
};

/**
 * Enhance every levels field of the form.
 */
export const init = () => {
    document.querySelectorAll('textarea[data-catquizlab-levels]').forEach((area) => {
        if (!area.disabled && !area.readOnly) {
            enhance(area);
        }
    });
};
