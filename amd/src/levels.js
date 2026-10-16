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

/**
 * What is wrong with a level, by the rules the server applies; '' when nothing.
 *
 * @param {string} kind items, se or strength.
 * @param {object} level The fields.
 * @returns {string}
 */
const problem = (kind, level) => {
    const number = (v) => v !== '' && !isNaN(Number(String(v).replace(',', '.')));
    if (kind === 'strength') {
        if (!number(level.value)) {
            return str('levels:notanumbershort');
        }
        const value = Number(String(level.value).replace(',', '.'));
        if (level.key === '' && (value <= 0 || value > 100)) {
            return str('levels:shareshort');
        }
        return '';
    }
    if (level.raw || !number(level.a) || !number(level.b)) {
        return str(kind === 'items' ? 'levels:itempairshort' : 'levels:sepairshort');
    }
    const a = Number(String(level.a).replace(',', '.'));
    const b = Number(String(level.b).replace(',', '.'));
    if (kind === 'items' && (!Number.isInteger(a) || !Number.isInteger(b) || a < 0)) {
        return str('levels:itempairshort');
    }
    if (a > b) {
        return str('levels:minmaxshort');
    }
    if (kind === 'se' && a <= 0) {
        return str('levels:sepositiveshort');
    }
    return '';
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
                const why = problem(kind, level);
                message.textContent = why;
                row.querySelectorAll('input, select').forEach((el) => el.setAttribute('aria-invalid', why ? 'true' : 'false'));
            };
            const label = (name) => `${str(name)} ${index + 1}`;
            if (kind === 'strength') {
                const select = document.createElement('select');
                select.className = 'custom-select custom-select-sm d-inline-block mr-1';
                select.style.width = '10rem';
                select.setAttribute('aria-label', label('levels:key'));
                STRENGTH_KEYS.forEach((key) => {
                    const option = document.createElement('option');
                    option.value = key;
                    option.textContent = key === '' ? str('levels:sharepercent') : key;
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
            remove.textContent = str('levels:remove');
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
