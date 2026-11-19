#!/usr/bin/env python3
# This file is part of Moodle - http://moodle.org/
#
# Moodle is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Moodle is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

"""The checks of the 2 x 2 trace matrix (#164, re-audit 19.11.2026): fail closed, never a green job on missing data.

Before 0.7.72 the matrix read its cells with inline Python that broke on an empty map the PHP report wrote as a list
("'list' object has no attribute 'get'"), printed the traceback and went on; a failed cell was noted, never failed the
job; the comparison skipped a missing pair; and the engine traces were read after the step's experiment had been
deleted, with them — 1 or 2 selector spans against 240 answer requests. Now:

    trace_matrix_check.py cell OUT NAME     one cell: its summary line on stdout; its problems in OUT/NAME-check.txt,
                                            exit 1 when there is one
    trace_matrix_check.py matrix OUT STRATEGIES REPEAT
                                            the whole matrix: every (strategy, tracing, debug) cell there REPEAT times,
                                            each checked clean; exit 1 otherwise
    trace_matrix_check.py --self-test       every failure case below against a made-up cell, and one clean cell

A cell is clean when: its files are there and parse (cell, smoke summary, requests JSON and CSV, engine traces, machine
samples); the smoke test passed; the server traced requests, answers among them, no 5xx, no fatal; the engine report
has its maps as maps (kinds, spans, links, bysitting); with engine tracing off, it wrote no trace; with it on, every
sitting's requests are mapped one to one onto its traces — its attempt.php GET onto its first_item trace, each answer
(attempt.php POST) onto a next_item or complete trace — each item selection has its strategy:select_question span,
and every trace belongs to a lab sitting, except the preparation's dry run (first_item on an engine attempt that no
longer exists).
"""

import csv
import json
import os
import shutil
import sys
import tempfile

MAPS = ('kinds', 'spans', 'links', 'bysitting')


def load_json(path):
    with open(path) as f:
        return json.load(f)


def problems_of(out, name):
    """(problems, facts) of one cell."""
    problems = []
    facts = {'cell': name}

    def need(path, what):
        if not os.path.isfile(path):
            problems.append(f'{what} missing ({os.path.basename(path)})')
            return None
        try:
            return load_json(path)
        except (OSError, ValueError) as e:
            problems.append(f'{what} unreadable: {e}')
            return None

    meta = need(f'{out}/{name}-cell.json', 'cell description')
    smoke = need(f'{out}/{name}-smoke.json', 'smoke summary')
    req = need(f'{out}/{name}-requests.json', 'request report')
    traces = need(f'{out}/{name}-traces.json', 'engine trace report')
    for label, value in (('cell description', meta), ('smoke summary', smoke), ('request report', req),
                         ('engine trace report', traces)):
        if value is not None and not isinstance(value, dict):
            problems.append(f'{label} is not an object')
    meta = meta if isinstance(meta, dict) else {}
    smoke = smoke if isinstance(smoke, dict) else {}
    req = req if isinstance(req, dict) else {}
    traces = traces if isinstance(traces, dict) else {}
    trace = meta.get('trace')
    facts.update(trace=trace, debug=meta.get('debug'), strategy=meta.get('strategy'))
    if trace not in (0, 1):
        problems.append(f'cell description names no tracing 0/1 ({trace!r})')

    # The machine samples: at least one.
    samples = 0
    try:
        for line in open(f'{out}/{name}-samples.jsonl'):
            try:
                json.loads(line)
                samples += 1
            except ValueError:
                pass
    except OSError:
        problems.append('machine samples missing')
    if samples == 0:
        problems.append('no machine sample')
    facts['samples'] = samples

    # The smoke test.
    if smoke and (smoke.get('verdict') != 'pass' or meta.get('result') != 'PASS'):
        problems.append(f'smoke test did not pass (verdict {smoke.get("verdict")!r}, result {meta.get("result")!r})')

    # The requests: the server's own trace.
    if req:
        if int(req.get('requests') or 0) <= 0:
            problems.append('the server traced no request')
        for key, label in (('http5xx', 'HTTP 5xx'), ('fatals', 'PHP fatal(s)')):
            if int(req.get(key) or 0) > 0:
                problems.append(f'{req.get(key)} {label}')
    rows = []
    try:
        rows = list(csv.DictReader(open(f'{out}/{name}-requests.csv')))
    except OSError:
        problems.append('request rows missing')
    missing = {'page', 'method', 'attemptid', 'durationms'} - set(rows[0].keys() if rows else [])
    if rows and missing:
        problems.append(f'request rows without {sorted(missing)}')
        rows = []
    posts, gets = {}, {}
    for r in rows:
        if r['page'].endswith('/mod/adaptivequiz/attempt.php'):
            target = posts if r['method'] == 'POST' else gets
            target[r['attemptid']] = target.get(r['attemptid'], 0) + 1
    answers = sum(posts.values())
    facts['answers'] = answers
    if rows and answers == 0:
        problems.append('no answer request (attempt.php POST) among the requests')

    # The engine's report: maps are maps, and what tracing says it should hold.
    for key in MAPS:
        if traces and not isinstance(traces.get(key), dict):
            problems.append(f'engine trace report: "{key}" is a {type(traces.get(key)).__name__}, not a map')
    if traces and not all(isinstance(traces.get(k), dict) for k in MAPS):
        return problems, facts
    count = int(traces.get('traces') or 0) if traces else 0
    facts['traces'] = count
    if traces and trace == 0:
        if count:
            problems.append(f'engine tracing off, yet {count} trace(s) written')
        if traces.get('tracing'):
            problems.append('engine tracing off in the cell, on in the report')
    if traces and trace == 1:
        if not traces.get('tracing'):
            problems.append('engine tracing on in the cell, off in the report')
        if count == 0:
            problems.append('engine tracing on, no trace written')
        unlinked = traces.get('unlinked') if isinstance(traces.get('unlinked'), list) else []
        if int(traces['links'].get('none', 0)) != len(unlinked):
            problems.append(f'{traces["links"].get("none", 0)} trace(s) of no lab sitting, {len(unlinked)} listed')
        allowed = [u for u in unlinked if u.get('kind') == 'first_item' and not u.get('exists')]
        if len(unlinked) != len(allowed):
            problems.append(f'{len(unlinked) - len(allowed)} trace(s) of no lab sitting (not the dry run)')
        facts['dryrun'] = len(allowed)
        bysitting = traces['bysitting']
        selections = 0
        for sitting in sorted(set(posts) | set(gets)):
            got = bysitting.get(sitting)
            if not isinstance(got, dict) or not isinstance(got.get('kinds'), dict):
                problems.append(f'sitting {sitting}: {posts.get(sitting, 0)} answer(s), no trace')
                continue
            kinds = got['kinds']
            following = int(kinds.get('next_item', 0)) + int(kinds.get('complete', 0))
            if following != posts.get(sitting, 0):
                problems.append(f'sitting {sitting}: {posts.get(sitting, 0)} answer request(s), {following} '
                                'next_item/complete trace(s)')
            if int(kinds.get('first_item', 0)) != min(1, gets.get(sitting, 0)):
                problems.append(f'sitting {sitting}: {gets.get(sitting, 0)} first request(s), '
                                f'{kinds.get("first_item", 0)} first_item trace(s)')
            want = int(kinds.get('first_item', 0)) + int(kinds.get('next_item', 0))
            if int(got.get('selections') or 0) != want:
                problems.append(f'sitting {sitting}: {want} item(s) chosen, {got.get("selections")} selector span(s)')
            selections += int(got.get('selections') or 0)
        facts['selections'] = selections
        sel = traces['spans'].get('strategy:select_question') or {}
        if int(sel.get('n') or 0) < selections:
            problems.append(f'selector statistics over {sel.get("n")} span(s), {selections} mapped')
    return problems, facts


def summary_line(facts, traces_path):
    try:
        t = load_json(traces_path)
    except (OSError, ValueError):
        t = {}
    t = t if isinstance(t, dict) else {}
    spans = t.get('spans') if isinstance(t.get('spans'), dict) else {}
    kinds = t.get('kinds') if isinstance(t.get('kinds'), dict) else {}
    nxt = kinds.get('next_item') or {}
    sel = spans.get('strategy:select_question') or {}
    f = lambda d, k: ('%.0f' % d[k]) if isinstance(d, dict) and k in d else '–'
    g = lambda d, k: ('%.3f' % d[k]) if isinstance(d, dict) and k in d else '–'
    return (f"answers {facts.get('answers', '–')}  next p50/p95 {f(nxt, 'p50')}/{f(nxt, 'p95')}  "
            f"selector p50/p95 {g(sel, 'p50')}/{g(sel, 'p95')} (n {sel.get('n', 0) if isinstance(sel, dict) else 0})  "
            f"traces {facts.get('traces', '–')}  KiB {round((t.get('bytes') or 0) / 1024)}")


def check_cell(out, name):
    problems, facts = problems_of(out, name)
    with open(f'{out}/{name}-check.txt', 'w') as f:
        f.write(''.join(p + '\n' for p in problems) or 'clean\n')
    print(summary_line(facts, f'{out}/{name}-traces.json') + (f'  PROBLEMS {len(problems)}' if problems else ''))
    return 1 if problems else 0


def check_matrix(out, strategies, repeat):
    problems = []
    seen = {}
    for path in sorted(os.listdir(out)):
        if not path.endswith('-cell.json'):
            continue
        name = path[:-len('-cell.json')]
        cell_problems, facts = problems_of(out, name)
        key = (facts.get('strategy'), facts.get('trace'), facts.get('debug'))
        seen[key] = seen.get(key, 0) + 1
        problems += [f'{name}: {p}' for p in cell_problems]
    for strategy in strategies:
        for trace in (0, 1):
            for debug in ('off', 'trace'):
                n = seen.get((strategy, trace, debug), 0)
                if n < repeat:
                    problems.append(f'cell {strategy} tracing {trace} debug {debug}: {n} of {repeat} run(s)')
    with open(f'{out}/matrix-check.txt', 'w') as f:
        f.write(''.join(p + '\n' for p in problems) or 'clean\n')
    for p in problems:
        print('PROBLEM: ' + p)
    print(f'{sum(seen.values())} cell(s), {len(problems)} problem(s).')
    return 1 if problems else 0


def self_test():
    """Each failure case on a made-up cell; a clean cell first."""
    def write_cell(out, name, trace=1, **change):
        posts = change.pop('posts', 3)
        files = {
            'cell': {'cell': name, 'trace': trace, 'debug': 'off', 'strategy': 'fastest', 'result': 'PASS'},
            'smoke': {'verdict': 'pass', 'queued': 1, 'experimentid': 7},
            'requests': {'requests': 10, 'http5xx': 0, 'fatals': 0},
            'traces': {'tracing': bool(trace), 'traces': (2 + posts) if trace else 0,
                       'kinds': {'first_item': {'n': 1}} if trace else {},
                       'spans': {'strategy:select_question': {'n': posts}} if trace else {},
                       'links': {'stored': 1 + posts, 'none': 1} if trace else {},
                       'bysitting': {'5': {'kinds': {'first_item': 1, 'next_item': posts - 1, 'complete': 1},
                                           'selections': posts}} if trace else {},
                       'unlinked': [{'kind': 'first_item', 'exists': False}] if trace else []},
        }
        for key, value in change.items():
            files[key] = value
        for key in ('cell', 'smoke', 'requests', 'traces'):
            if files[key] is None:
                continue
            with open(f'{out}/{name}-{key}.json', 'w') as f:
                f.write(files[key] if isinstance(files[key], str) else json.dumps(files[key]))
        if change.get('nosamples') is None:
            with open(f'{out}/{name}-samples.jsonl', 'w') as f:
                f.write('{"cpupercent": 10}\n')
        with open(f'{out}/{name}-requests.csv', 'w') as f:
            f.write('unixms,page,method,status,durationms,attemptid\n')
            f.write('1,/mod/adaptivequiz/attempt.php,GET,200,10,5\n')
            for i in range(posts):
                f.write(f'{i},/mod/adaptivequiz/attempt.php,POST,200,10,5\n')

    good = {'trace': 1}
    t = lambda **c: dict({'tracing': True, 'traces': 5, 'kinds': {'first_item': {}}, 'spans':
                          {'strategy:select_question': {'n': 3}}, 'links': {'stored': 4, 'none': 1},
                          'bysitting': {'5': {'kinds': {'first_item': 1, 'next_item': 2, 'complete': 1},
                                              'selections': 3}},
                          'unlinked': [{'kind': 'first_item', 'exists': False}]}, **c)
    cases = [
        ('clean, tracing on', good, 0),
        ('clean, tracing off', {'trace': 0}, 0),
        ('cell description missing', {'cell': None}, 1),
        ('smoke summary missing', {'smoke': None}, 1),
        ('request report missing', {'requests': None}, 1),
        ('engine report missing', {'traces': None}, 1),
        ('engine report not JSON', {'traces': '{not json'}, 1),
        ('engine report a list', {'traces': '[]'}, 1),
        ('kinds a list', {'traces': t(kinds=[])}, 1),
        ('links a list', {'traces': t(links=[])}, 1),
        ('bysitting a list', {'traces': t(bysitting=[])}, 1),
        ('no samples', {'nosamples': True}, 1),
        ('smoke failed', {'smoke': {'verdict': 'deadline'}}, 1),
        ('5xx', {'requests': {'requests': 10, 'http5xx': 2, 'fatals': 0}}, 1),
        ('no request', {'requests': {'requests': 0}}, 1),
        ('no trace while on', {'traces': t(traces=0)}, 1),
        ('traces while off', {'trace': 0, 'traces': t(tracing=False, bysitting={}, unlinked=[])}, 1),
        ('an answer without trace', {'traces': t(bysitting={'5': {'kinds': {'first_item': 1, 'next_item': 1,
                                                                              'complete': 1}, 'selections': 2}})}, 1),
        ('a selection without span', {'traces': t(bysitting={'5': {'kinds': {'first_item': 1, 'next_item': 2,
                                                                               'complete': 1}, 'selections': 2}})}, 1),
        ('a sitting without traces', {'traces': t(bysitting={})}, 1),
        ('an unlinked trace not the dry run', {'traces': t(unlinked=[{'kind': 'next_item', 'exists': True}])}, 1),
        ('selector statistics short', {'traces': t(spans={'strategy:select_question': {'n': 1}})}, 1),
        ('an unlinked trace not listed', {'traces': t(links={'stored': 4, 'none': 2})}, 1),
    ]
    bad = 0
    for label, change, expect in cases:
        out = tempfile.mkdtemp()
        try:
            change = dict(change)
            trace = change.pop('trace', 1)
            if trace == 0 and 'traces' not in change:
                change['traces'] = {'tracing': False, 'traces': 0, 'kinds': {}, 'spans': {}, 'links': {},
                                    'bysitting': {}, 'unlinked': []}
                change['cell'] = change.get('cell', {'cell': 'c', 'trace': 0, 'debug': 'off', 'strategy': 'fastest',
                                                     'result': 'PASS'})
            if trace == 0 and 'cell' not in change:
                change['cell'] = {'cell': 'c', 'trace': 0, 'debug': 'off', 'strategy': 'fastest', 'result': 'PASS'}
            write_cell(out, 'c', trace=trace, **change)
            problems, _ = problems_of(out, 'c')
        finally:
            shutil.rmtree(out)
        got = 1 if problems else 0
        ok = got == expect
        bad += not ok
        print(('ok   ' if ok else 'FAIL ') + f'{label}: {"; ".join(problems) or "clean"}')
    # The matrix: a missing cell fails.
    out = tempfile.mkdtemp()
    try:
        for i, (trace, debug) in enumerate([(0, 'off'), (1, 'off'), (0, 'trace')]):
            cell = {'cell': f'{i}', 'trace': trace, 'debug': debug, 'strategy': 'fastest', 'result': 'PASS'}
            write_cell(out, f'{i}', trace=trace, cell=cell,
                       **({} if trace else {'traces': {'tracing': False, 'traces': 0, 'kinds': {}, 'spans': {},
                                                        'links': {}, 'bysitting': {}, 'unlinked': []}}))
        import io
        import contextlib
        with contextlib.redirect_stdout(io.StringIO()):
            got = check_matrix(out, ['fastest'], 1)
    finally:
        shutil.rmtree(out)
    ok = got == 1
    bad += not ok
    print(('ok   ' if ok else 'FAIL ') + 'a cell of the 2 x 2 missing: the matrix fails')
    print(f'{len(cases) + 1 - bad} of {len(cases) + 1} cases as expected.')
    return 0 if bad == 0 else 1


def main(argv):
    if argv[1:2] == ['--self-test']:
        return self_test()
    if argv[1:2] == ['cell']:
        return check_cell(argv[2], argv[3])
    if argv[1:2] == ['matrix']:
        return check_matrix(argv[2], argv[3].split(), int(argv[4]))
    print(__doc__)
    return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv))
