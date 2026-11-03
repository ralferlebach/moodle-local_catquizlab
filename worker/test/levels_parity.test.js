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
 * The sweep editor checks levels by the server's rules (#126): the same fixture as sweep_levels_test.php.
 *
 * amd/src/levels.js is a browser module; its pure functions are read from the source and run here with a
 * stand-in for M.util.get_string, so that the test needs no browser and no build.
 */

'use strict';

const {test} = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.resolve(__dirname, '../../amd/src/levels.js'), 'utf8')
    .replace(/^export const /gm, 'const ');
// eslint-disable-next-line no-new-func
const load = new Function('M', 'document', source + '\nreturn {problem, fromLine, identity, STRENGTH_KEYS};');
const M = {util: {get_string: (key) => key}};
const levels = load(M, undefined);

const fixture = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../tests/fixtures/levels_parity.json'), 'utf8'));

test('every case of the fixture: accepted by the editor exactly where the server accepts it', () => {
    assert.ok(fixture.cases.length > 30);
    const disagree = fixture.cases.filter((c) => (levels.problem(c.kind, levels.fromLine(c.kind, c.line)) === '') !== c.ok);
    assert.deepStrictEqual(disagree.map((c) => c.kind + ' "' + c.line + '"'), []);
});

test('the same level twice is told by its numbers, not its spelling', () => {
    const id = (kind, line) => levels.identity(kind, levels.fromLine(kind, line));
    const same = (kind, a, b) => id(kind, a) === id(kind, b);
    assert.ok(same('items', '10/15', ' 10 / 15 '));
    assert.ok(same('se', '0,3/0,5', '0.3/0.5'));
    assert.ok(same('strength', '10', 'fraction=0.1'));
    assert.ok(!same('items', '10/15', '10/16'));
});
