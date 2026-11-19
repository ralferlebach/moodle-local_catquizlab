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

"""The 2 x 2 trace matrix compared over every request (#164 AK-01 to AK-03, AK-08, DoD-01, DoD-02).

Reads what cli/trace_matrix.sh left in OUT: per cell and strategy the server's trace of every request
(*-requests.csv, request_trace: the time each request took, measured by the lab whether the engine traces or not),
the engine's traces (*-traces.json: strategy:select_question, bytes), the machine samples (*-samples.jsonl) and the
smoke test's log (sittings collected and failed).

Per cell: every request counted (n), the answer requests (attempt.php POST) with mean, p50, p95, p99 and bootstrap
95 % intervals of mean and median; HTTP 5xx and CDP timeouts; the engine's selector time; traces and bytes written; the
debug log; CPU, memory, disk writes.

Then the effects, each as a difference of the answer requests' median and mean between two cells of the same strategy,
with a bootstrap 95 % interval (2000 resamples, fixed seed):
- engine tracing on vs off, debug off (and again with debug on);
- lab debug trace vs off, engine tracing off;
and, where the engine traced, the selector's share of the request. An interval that contains 0 is reported as "no
difference measurable with this sample", not as a cost. The time a request waits outside the server (CDP, navigation,
retries) is not in these numbers: the server measures its own time; the worker's timeouts are counted apart.

    python3 cli/trace_matrix_compare.py /tmp/catquizlab-trace-matrix

Writes OUT/report.json and OUT/report.md. Fails closed since 0.7.72 (re-audit 19.11.2026): a cell without its request
rows or with no answer request, a file that does not parse, a map written as a list, and a pair of cells an effect
needs but does not have are problems, listed in the report, and the exit code is 1.
"""

import csv
import glob
import json
import os
import random
import re
import statistics
import sys

RESAMPLES = 2000

PROBLEMS = []


def load_json(path):
    try:
        with open(path) as f:
            value = json.load(f)
    except (OSError, ValueError) as e:
        PROBLEMS.append(f'{os.path.basename(path)}: {e}')
        return {}
    if not isinstance(value, dict):
        PROBLEMS.append(f'{os.path.basename(path)}: not an object')
        return {}
    return value


def as_map(value, where):
    """A map, or an empty one and a problem: a list where a map belongs is a broken report, not an empty one."""
    if isinstance(value, dict):
        return value
    if value not in (None, [], {}):
        PROBLEMS.append(f'{where}: a {type(value).__name__} where a map belongs')
    elif isinstance(value, list):
        PROBLEMS.append(f'{where}: a list where a map belongs')
    return {}


def quantile(values, q):
    if not values:
        return None
    s = sorted(values)
    return s[min(len(s) - 1, int(q * (len(s) - 1) + 0.5))]


def bootstrap(values, stat, seed):
    """A 95 % percentile interval of stat(values)."""
    if len(values) < 2:
        return None
    rng = random.Random(seed)
    n = len(values)
    est = sorted(stat([values[rng.randrange(n)] for _ in range(n)]) for _ in range(RESAMPLES))
    return [round(est[int(0.025 * RESAMPLES)], 1), round(est[int(0.975 * RESAMPLES) - 1], 1)]


def bootstrap_diff(a, b, stat, seed):
    """A 95 % interval of stat(b) - stat(a), each resampled on its own."""
    if len(a) < 2 or len(b) < 2:
        return None
    rng = random.Random(seed)
    na, nb = len(a), len(b)
    est = sorted(stat([b[rng.randrange(nb)] for _ in range(nb)]) - stat([a[rng.randrange(na)] for _ in range(na)])
                 for _ in range(RESAMPLES))
    return [round(est[int(0.025 * RESAMPLES)], 1), round(est[int(0.975 * RESAMPLES) - 1], 1)]


def samples(path):
    out = []
    try:
        for line in open(path):
            try:
                out.append(json.loads(line))
            except ValueError:
                pass
    except OSError:
        pass
    return out


def cell(out, prefix):
    meta = load_json(f'{out}/{prefix}-cell.json')
    rows = []
    try:
        rows = list(csv.DictReader(open(f'{out}/{prefix}-requests.csv')))
    except OSError:
        PROBLEMS.append(f'{prefix}: request rows missing')
    allms = [float(r['durationms']) for r in rows if r['durationms'] not in ('', '-1')]
    answers = [float(r['durationms']) for r in rows
               if r['page'].endswith('/attempt.php') and r['method'] == 'POST' and r['durationms'] not in ('', '-1')]
    traces = load_json(f'{out}/{prefix}-traces.json')
    s = samples(f'{out}/{prefix}-samples.jsonl')
    log = ''
    try:
        log = open(f'{out}/{prefix}.log').read()
    except OSError:
        pass
    m = re.findall(r'(\d+) collected, (\d+) failed', log)
    if not answers:
        PROBLEMS.append(f'{prefix}: no answer request')
    sel = as_map(traces.get('spans'), f'{prefix} spans').get('strategy:select_question') or {}
    dbg = as_map(traces.get('debug'), f'{prefix} debug')
    status = {}
    for r in rows:
        status[r['status']] = status.get(r['status'], 0) + 1
    written = (s[-1].get('writtenmb', 0) - s[0].get('writtenmb', 0)) if len(s) > 1 else None
    seed = sum(map(ord, prefix))
    return {
        'cell': prefix, 'trace': meta.get('trace'), 'debug': meta.get('debug'), 'strategy': meta.get('strategy'),
        'cold': meta.get('cold'), 'result': meta.get('result'), 'from': meta.get('from'), 'until': meta.get('until'),
        'requests': len(rows), 'status': status,
        'http5xx': sum(v for k, v in status.items() if k.startswith('5')),
        'answers': {
            'n': len(answers),
            'mean': round(statistics.mean(answers), 1) if answers else None,
            'mean_ci95': bootstrap(answers, statistics.mean, seed),
            'p50': quantile(answers, 0.5), 'p50_ci95': bootstrap(answers, statistics.median, seed + 1),
            'p95': quantile(answers, 0.95), 'p99': quantile(answers, 0.99),
            'max': max(answers) if answers else None,
        },
        'all_requests': {'n': len(allms), 'p50': quantile(allms, 0.5), 'p95': quantile(allms, 0.95),
                         'p99': quantile(allms, 0.99)},
        'selector': {k: sel.get(k) for k in ('n', 'mean', 'p50', 'p95', 'p99', 'ci95')} if sel else None,
        'traces': traces.get('traces'), 'trace_bytes': traces.get('bytes'),
        'tracing': traces.get('tracing'),
        'cdp_timeouts': as_map(traces.get('capacitycauses'), f'{prefix} capacitycauses').get('submit_cdp_timeout', 0),
        'debug_log': dbg,
        'sittings': {'collected': int(m[-1][0]), 'failed': int(m[-1][1])} if m else None,
        'cpu_percent_mean': round(statistics.mean([x['cpupercent'] for x in s if x.get('cpupercent') is not None]), 1)
        if any(x.get('cpupercent') is not None for x in s) else None,
        'web_rss_mb_max': max((x.get('webrssmb') or 0 for x in s), default=None),
        'db_active_max': max((x.get('dbactive') or 0 for x in s), default=None),
        'disk_written_mb': written,
        '_answers': answers,
    }


def main(out):
    cells = [cell(out, os.path.basename(p)[:-len('-cell.json')]) for p in sorted(glob.glob(f'{out}/*-cell.json'))]
    by = {}
    for c in cells:
        by.setdefault((c['strategy'], c['trace'], c['debug']), []).extend(c['_answers'])
    effects = []
    for strategy in sorted({c['strategy'] for c in cells}):
        for label, a, b in [
            ('engine tracing on − off (lab debug off)', (strategy, 0, 'off'), (strategy, 1, 'off')),
            ('engine tracing on − off (lab debug trace)', (strategy, 0, 'trace'), (strategy, 1, 'trace')),
            ('lab debug trace − off (engine tracing off)', (strategy, 0, 'off'), (strategy, 0, 'trace')),
            ('both on − both off', (strategy, 0, 'off'), (strategy, 1, 'trace')),
        ]:
            xa, xb = by.get(a, []), by.get(b, [])
            if not xa or not xb:
                PROBLEMS.append(f'{strategy}: "{label}" without both cells ({len(xa)} and {len(xb)} answer requests)')
                continue
            seed = sum(map(ord, label + strategy))
            dmed = bootstrap_diff(xa, xb, statistics.median, seed)
            dmean = bootstrap_diff(xa, xb, statistics.mean, seed + 7)
            measurable = dmed is not None and (dmed[0] > 0 or dmed[1] < 0)
            effects.append({
                'strategy': strategy, 'effect': label, 'n': [len(xa), len(xb)],
                'median_ms': [quantile(xa, 0.5), quantile(xb, 0.5)],
                'delta_median_ms': round(statistics.median(xb) - statistics.median(xa), 1), 'delta_median_ci95': dmed,
                'delta_mean_ms': round(statistics.mean(xb) - statistics.mean(xa), 1), 'delta_mean_ci95': dmean,
                'verdict': ('difference measured' if measurable else 'no difference measurable with this sample'),
            })
    shares = []
    for c in cells:
        if c['selector'] and c['selector'].get('p50') is not None and c['answers']['p50']:
            shares.append({'cell': c['cell'], 'selector_p50_ms': c['selector']['p50'],
                           'answer_request_p50_ms': c['answers']['p50'],
                           'selector_share_of_median': round(c['selector']['p50'] / c['answers']['p50'], 3)})
    for c in cells:
        c.pop('_answers')
    if not cells:
        PROBLEMS.append('no cell')
    report = {'cells': cells, 'effects': effects, 'selector_shares': shares, 'resamples': RESAMPLES,
              'problems': PROBLEMS}
    with open(f'{out}/report.json', 'w') as f:
        json.dump(report, f, indent=2)

    md = ['# Trace matrix: engine tracing × lab debug (#164)', '',
          'Every request of every cell, measured by the server (request_trace), not a top-n. Answer requests = '
          'attempt.php POST. Intervals: bootstrap, 95 %, ' + str(RESAMPLES) + ' resamples.', '',
          '| cell | result | requests | answers n | p50 [95 % CI] | p95 | p99 | mean [95 % CI] | 5xx | CDP timeouts | '
          'selector p50 (n) | traces / KiB | sittings ok/failed | CPU % | web RSS MB | disk MB |',
          '|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|']
    for c in cells:
        a = c['answers']
        sel = c['selector'] or {}
        md.append('| {cell} | {result} | {req} | {n} | {p50} {p50ci} | {p95} | {p99} | {mean} {meanci} | {h5} | {cdp} | '
                  '{sel} | {tr} | {sit} | {cpu} | {rss} | {disk} |'.format(
                      cell=c['cell'], result=c['result'], req=c['requests'], n=a['n'], p50=a['p50'],
                      p50ci=a['p50_ci95'] or '', p95=a['p95'], p99=a['p99'], mean=a['mean'], meanci=a['mean_ci95'] or '',
                      h5=c['http5xx'], cdp=c['cdp_timeouts'],
                      sel=(f"{sel.get('p50')} ({sel.get('n')})" if sel else 'not traced'),
                      tr=f"{c['traces'] or 0} / {round((c['trace_bytes'] or 0) / 1024)}",
                      sit=(f"{c['sittings']['collected']}/{c['sittings']['failed']}" if c['sittings'] else '-'),
                      cpu=c['cpu_percent_mean'], rss=c['web_rss_mb_max'], disk=c['disk_written_mb']))
    md += ['', '## Effects (answer requests, same strategy)', '',
           '| strategy | effect | n | median a → b (ms) | Δ median [95 % CI] | Δ mean [95 % CI] | verdict |',
           '|---|---|---|---|---|---|---|']
    for e in effects:
        md.append(f"| {e['strategy']} | {e['effect']} | {e['n'][0]} / {e['n'][1]} | {e['median_ms'][0]} → {e['median_ms'][1]} | "
                  f"{e['delta_median_ms']} {e['delta_median_ci95']} | {e['delta_mean_ms']} {e['delta_mean_ci95']} | "
                  f"{e['verdict']} |")
    md += ['', '## The selector within the request (cells with engine tracing)', '',
           '| cell | selector p50 (ms) | answer request p50 (ms) | share |', '|---|---|---|---|']
    for s in shares:
        md.append(f"| {s['cell']} | {s['selector_p50_ms']} | {s['answer_request_p50_ms']} | {s['selector_share_of_median']} |")
    md += ['', 'What these numbers do not contain: time a request spends outside the server (the worker\'s browser, '
           'CDP, navigation, retries). The worker\'s CDP timeouts are counted per cell; a request the server never saw '
           'has no line here. No effect is claimed where its interval contains 0.']
    if PROBLEMS:
        md += ['', '## Problems: this report is not complete', ''] + [f'- {p}' for p in PROBLEMS]
    with open(f'{out}/report.md', 'w') as f:
        f.write('\n'.join(md) + '\n')
    print('\n'.join(md))
    return 1 if PROBLEMS else 0


def self_test():
    """A clean 2 x 2 compares with exit 0; a missing cell, a list for a map, an empty request file each with exit 1."""
    import contextlib
    import io
    import shutil
    import tempfile

    def build(out, skip=None, spans=None, empty=None):
        for i, (trace, debug) in enumerate([(0, 'off'), (1, 'off'), (0, 'trace'), (1, 'trace')]):
            name = f'{i}-trace{trace}-debug{debug}-fastest'
            if name == skip:
                continue
            with open(f'{out}/{name}-cell.json', 'w') as f:
                json.dump({'cell': name, 'trace': trace, 'debug': debug, 'strategy': 'fastest', 'result': 'PASS'}, f)
            with open(f'{out}/{name}-requests.csv', 'w') as f:
                f.write('unixms,page,method,status,durationms\n')
                if name != empty:
                    for k in range(20):
                        f.write(f'{k},/mod/adaptivequiz/attempt.php,POST,200,{100 + k + 5 * trace}\n')
            with open(f'{out}/{name}-traces.json', 'w') as f:
                json.dump({'traces': 0, 'spans': spans if spans is not None and trace else {}, 'debug': {},
                           'capacitycauses': {}}, f)
            with open(f'{out}/{name}-samples.jsonl', 'w') as f:
                f.write('{"cpupercent": 10, "writtenmb": 1}\n{"cpupercent": 20, "writtenmb": 2}\n')

    cases = [('clean', {}, 0), ('a cell missing', {'skip': '3-trace1-debugtrace-fastest'}, 1),
             ('spans a list', {'spans': []}, 1), ('no answer request', {'empty': '1-trace1-debugoff-fastest'}, 1)]
    bad = 0
    for label, change, expect in cases:
        out = tempfile.mkdtemp()
        try:
            build(out, **change)
            PROBLEMS.clear()
            with contextlib.redirect_stdout(io.StringIO()):
                got = main(out)
        finally:
            shutil.rmtree(out)
        ok = got == expect
        bad += not ok
        print(('ok   ' if ok else 'FAIL ') + f'{label}: exit {got} — {"; ".join(PROBLEMS) or "no problem"}')
    print(f'{len(cases) - bad} of {len(cases)} cases as expected.')
    return 0 if bad == 0 else 1


if __name__ == '__main__':
    if sys.argv[1:2] == ['--self-test']:
        sys.exit(self_test())
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else '/tmp/catquizlab-trace-matrix'))
