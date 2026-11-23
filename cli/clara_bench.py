#!/usr/bin/env python3
"""The CLARA-like benchmark of #110, read and judged (0.7.78; fail closed since 0.7.80, #170).

Two legs of the same benchmark — HIGHESTSUB, a pool of at least 2 500 items, the same seeds, Apache + PHP-FPM at 512M:

    after   the release engine set at 1/5/12/25 workers, a query-log, a faults and a resume step: the gate;
    before  the previous engine set (engine-pins-previous.env) at 1 and 25 workers: the comparison — what the incident
            looked like. Its steps may fail, and that is what they show; its data must be complete all the same.

Each leg's directory holds what load_matrix.sh wrote (load/matrix.jsonl and per step *-smoke.json, *-requests.json,
*-traces.json, *-db.json, *-cdp.json, *-samples.jsonl, *.log), and limits.json (server-limits.sh), engine-manifest.txt
and host.json (the plugin's commit, the run, the runner, its CPUs and memory).

Before anything is judged, the data set itself is (#170): every required cell there exactly once, no other; every
file of every step there and readable; the samples of every step parseable, inside the step, with workers in them and
agreeing with what the matrix says; status counts that add up; latencies that are figures; host, limits and engine
manifest of both legs readable, the engines the pinned release and previous sets, the plugin the commit under test.
Every defect names its leg, cell, file and figure and the points it fails — never the content of an answer, never a
credential. A defect fails its points and GATE-170; a point is PASS only on data, never by default.

Writes clara-bench.json (every figure, machine-readable) and clara-bench.md (the report with the root cause), and
judges the points of #110 and #170:

    GATE-170          the data set complete, readable and consistent (the list of defects, if any)
    db-errors         "Datenbankfehler": every step's database layer without an exception, its counters readable
    web-limits        "PHP-/Webserver-Grenzen dokumentieren": memory_limit, max_execution_time, pm.max_children, the
                      web server's and the gateway's timeouts — each said, from the server itself
    db-limits         "Datenbankseitig prüfen": connection limit, connections at most, locks/deadlocks, failed writes
    AK-CLARA-01       every uncertain submission's life to the millisecond, its side named; the faults step's causes
                      exactly those injected
    AK-CLARA-03       the selector's time apart from the server's database and lock time, the client's time, the
                      trace INSERT (query-log step) and the load guard
    AK-CLARA-04       the load guard and the safety cap, sample by sample, at every step: throttled or not, why
    AK-CLARA-05       every sitting terminal and valid, recovery (faults step) without a second answer, a retry that
                      continues the engine attempt (resume step); the historical runs #87/#88 of CLARA are not
                      reconstructable here — WAIVED as operator evidence, never reported as tested, never a PASS
    DoD-CLARA-01      before and after on the same benchmark: selector, throughput, error rate, UTC times, the cause

Exit 1 if any point FAILs (a WAIVED point is not a PASS and does not make one).

    clara_bench.py --after DIR --before DIR --out DIR --commit SHA --pins-release FILE --pins-previous FILE
                   [--profile current|0.7.78]
    clara_bench.py --verify-report DIR     the report as written: every point there, statuses allowed, the waiver
                                           WAIVED, nothing FAIL — exit 1 otherwise
    clara_bench.py --self-test [--protocol FILE]
                                           the negative matrix (#170 DoD 1): each injected defect run through the
                                           command line, each must end nonzero; the protocol as Markdown
"""

import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone

WAIVED_HISTORY = ('The historical CLARA runs #87 and #88 (Experiment #19, 15.11.2026) are not reconstructable in CI: '
                  'their database, logs and engine state are the operator\'s. Waived as operator evidence (re-audit '
                  '22.11.2026, section 3 A) — not tested, and not reported as tested. What is tested: the same kind '
                  'of run (HIGHESTSUB, about 2 500 items) before and after, on the same seeds.')

# The cells each leg must have, exactly (acceptance.yml, job clara-bench): kind and workers. "current" since 0.7.79
# (the resume step); "0.7.78" is the matrix of the reference run on e605b2e7 (Acceptance 45038234242), accepted only
# for that commit — a newer data set is never judged by an older, smaller matrix.
PROFILES = {
    'current': {'after': [('load', 1), ('load', 5), ('load', 12), ('load', 25), ('querylog', 5), ('faults', 5),
                          ('resume', 5)],
                'before': [('load', 1), ('load', 25)]},
    '0.7.78': {'after': [('load', 1), ('load', 5), ('load', 12), ('load', 25), ('querylog', 5), ('faults', 5)],
               'before': [('load', 1), ('load', 25)]},
}
PROFILE_COMMITS = {'0.7.78': 'e605b2e78797efae7aed2e190efea5b10b0a3ac5'}
SETS = {'after': 'release', 'before': 'previous'}
MODE = 'highestsub'
MIN_POOL = 2500
# The engine components of a set and the pin that names their commit (engine-pins*.env), by the set's prefix.
COMPONENTS = {'local_catquiz': 'CATQUIZ_SHA', 'mod_adaptivequiz': 'ADAPTIVEQUIZ_SHA',
              'adaptivequizcatmodel_catquiz': 'CATMODEL_SHA', 'local_wunderbyte_table': 'WUNDERBYTE_TABLE_SHA'}
POINT_IDS = ['GATE-170', 'db-errors', 'web-limits', 'db-limits', 'AK-CLARA-01', 'AK-CLARA-03', 'AK-CLARA-04',
             'AK-CLARA-05', 'AK-CLARA-05-historical', 'DoD-CLARA-01']
WAIVABLE = {'AK-CLARA-05-historical'}
# The points a step's missing cell or file fails, in the after leg. In the before leg, everything is DoD-CLARA-01.
KIND_IDS = {'load': ['AK-CLARA-03', 'AK-CLARA-04', 'AK-CLARA-05', 'DoD-CLARA-01'], 'querylog': ['AK-CLARA-03'],
            'faults': ['AK-CLARA-01', 'AK-CLARA-05'], 'resume': ['AK-CLARA-05']}
FILE_IDS = {'-smoke.json': ['AK-CLARA-05'], '-requests.json': ['AK-CLARA-05', 'web-limits'],
            '-traces.json': ['AK-CLARA-03', 'DoD-CLARA-01'], '-db.json': ['db-errors', 'db-limits'],
            '-cdp.json': ['AK-CLARA-01'], '-samples.jsonl': ['AK-CLARA-04'], '.log': ['AK-CLARA-05']}
SHA = re.compile(r'^[0-9a-f]{40}$')
# Seconds a sample may lie outside its step (the sampler starts with the step and is stopped after it).
SAMPLE_SLACK = 90


def load(path, default=None):
    try:
        with open(path) as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def utc(ts):
    return datetime.fromtimestamp(int(ts), timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ') if ts else None


def number(v):
    return isinstance(v, (int, float)) and not isinstance(v, bool)


class Defects:
    """What is wrong with the data set: leg, cell, file, figure, what, and the points it fails."""

    def __init__(self):
        self.items = []

    def add(self, which, cell, file, metric, problem, ids):
        ids = list(dict.fromkeys(['GATE-170'] + (list(ids) if which == 'after' else ['DoD-CLARA-01'])))
        self.items.append({'leg': which, 'cell': cell, 'file': file, 'metric': metric, 'problem': problem,
                           'ids': ids})

    def line(self, d):
        return f"{d['leg']} / {d['cell'] or '-'} / {d['file'] or '-'} / {d['metric'] or '-'}: {d['problem']} " \
               f"[{', '.join(d['ids'])}]"

    def of(self, key):
        return [self.line(d) for d in self.items if key in d['ids']]


def latency(stats):
    """What is wrong with a latency summary (n, p50, p95, p99, max): figures, not negative, in order."""
    if not isinstance(stats, dict):
        return 'missing'
    if not number(stats.get('n')) or stats['n'] <= 0:
        return f"n {stats.get('n')!r}: no measurement"
    order = [stats.get(k) for k in ('p50', 'p95', 'p99', 'max') if stats.get(k) is not None]
    if any(not number(v) or v < 0 for v in order) or not number(stats.get('p50')) or not number(stats.get('p95')):
        return f'not figures: {json.dumps({k: stats.get(k) for k in ("p50", "p95", "p99", "max")})}'
    if order != sorted(order):
        return f'out of order: {json.dumps({k: stats.get(k) for k in ("p50", "p95", "p99", "max")})}'
    return None


def samples_check(path, row, which, name, defects):
    """The step's samples, read on their own (#170 AK 1): parseable, inside the step, workers in them, and agreeing
    with what the matrix says of them. Returns what they show."""
    ids = ['AK-CLARA-04']
    try:
        lines = open(path).read().split('\n')
    except OSError:
        defects.add(which, name, os.path.basename(path), 'samples', 'file missing', ids)
        return None
    if lines and lines[-1] == '':
        lines.pop()
    parsed, broken = [], []
    for i, text in enumerate(lines):
        try:
            sample = json.loads(text)
            if not isinstance(sample, dict):
                raise ValueError('not an object')
            parsed.append(sample)
        except ValueError:
            # The sampler is stopped mid-line at the end of a step: an unfinished LAST line is not a sample, and not a
            # defect. Any other unreadable line is.
            if i != len(lines) - 1:
                broken.append(i + 1)
    base = os.path.basename(path)
    if broken:
        defects.add(which, name, base, 'samples', f'unparseable line(s) {broken[:5]}', ids)
    if not parsed:
        defects.add(which, name, base, 'samples', 'no parseable sample: nothing measured', ids)
        return {'n': 0}
    if row.get('samples') != len(parsed):
        defects.add(which, name, base, 'samples', f"matrix says {row.get('samples')!r} sample(s), the file has "
                    f'{len(parsed)}', ids)
    lo, hi = (row.get('from') or 0) - SAMPLE_SLACK, (row.get('until') or 0) + SAMPLE_SLACK
    outside = [x.get('time') for x in parsed if not number(x.get('time')) or not lo <= x['time'] <= hi]
    if outside:
        defects.add(which, name, base, 'time', f'{len(outside)} sample(s) without a time inside the step '
                    f"({utc(row.get('from'))}–{utc(row.get('until'))}): another step's file?", ids)
    unreadable = [x['workersunreadable'] for x in parsed if 'workersunreadable' in x]
    if unreadable:
        defects.add(which, name, base, 'workerslive', f'{len(unreadable)} sample(s) could not read the workers '
                    f'({unreadable[0][:80]})', ids)
    live = [x['workerslive'] for x in parsed if number(x.get('workerslive'))]
    if not live:
        defects.add(which, name, base, 'workerslive', 'no sample with a worker count: the workers were not measured',
                    ids)
        return {'n': len(parsed)}
    if max(live) <= 0:
        defects.add(which, name, base, 'workerslive', f"no sample saw a worker, for a step of {row.get('workers')} "
                    'worker(s)', ids)
    if row.get('workers_live_max') != max(live):
        defects.add(which, name, 'matrix.jsonl', 'workers_live_max', f"{row.get('workers_live_max')!r}, the samples "
                    f'say {max(live)}', ids)
    throttled = sum(1 for x in parsed if x.get('throttled') is True)
    if any(not isinstance(x.get('throttled'), bool) for x in parsed if number(x.get('workerslive'))):
        defects.add(which, name, base, 'throttled', 'a sample with workers but no yes/no for the load guard', ids)
    if row.get('throttled_samples') != throttled:
        defects.add(which, name, 'matrix.jsonl', 'throttled_samples', f"{row.get('throttled_samples')!r}, the "
                    f'samples say {throttled}', ids)
    over = [x for x in parsed if number(x.get('workercap')) and x['workercap'] > 0
            and ((number(x.get('workerslive')) and x['workerslive'] > x['workercap'])
                 or (number(x.get('workerlimit')) and x['workerlimit'] > x['workercap']))]
    if over:
        defects.add(which, name, base, 'workercap', f'{len(over)} sample(s) with live workers or the limit above the '
                    f"safety cap (first: live {over[0].get('workerslive')}, limit {over[0].get('workerlimit')}, cap "
                    f"{over[0].get('workercap')})", ids)
    return {'n': len(parsed), 'live_max': max(live), 'throttled': throttled}


def leg(path, which='after', defects=None, required=None):
    """Everything of one leg, step by step; what is wrong with it into defects."""
    defects = defects if defects is not None else Defects()
    if not path or not os.path.isdir(path):
        defects.add(which, None, None, None, f'the {which} leg is missing ({path!r})', POINT_IDS[1:])
        return None
    load_dir = os.path.join(path, 'load') if os.path.isdir(os.path.join(path, 'load')) else path
    rows = []
    try:
        with open(os.path.join(load_dir, 'matrix.jsonl')) as f:
            for i, text in enumerate(f):
                if not text.strip():
                    continue
                try:
                    row = json.loads(text)
                    if not isinstance(row, dict) or not row.get('step'):
                        raise ValueError('no step')
                    rows.append(row)
                except ValueError:
                    defects.add(which, None, 'matrix.jsonl', f'line {i + 1}', 'unparseable', POINT_IDS[1:])
    except OSError:
        defects.add(which, None, 'matrix.jsonl', None, 'file missing: no step of this leg is known', POINT_IDS[1:])
    # The cells: each required one exactly once, no other (#170 AK 2).
    cells = {}
    for row in rows:
        cells.setdefault((row.get('kind'), row.get('workers')), []).append(row.get('step'))
    names = [row.get('step') for row in rows]
    for name in sorted({n for n in names if names.count(n) > 1}):
        defects.add(which, name, 'matrix.jsonl', 'step', 'the same step twice', ['DoD-CLARA-01'])
    for cell in required or []:
        got = cells.get(cell, [])
        label = f'{cell[0]} at {cell[1]} worker(s)'
        if not got:
            defects.add(which, label, 'matrix.jsonl', 'cell', 'required cell missing', KIND_IDS.get(cell[0], []))
        elif len(got) > 1:
            defects.add(which, label, 'matrix.jsonl', 'cell', f'{len(got)} steps for one cell: {got}',
                        KIND_IDS.get(cell[0], []))
    for cell, got in cells.items():
        if required is not None and cell not in required:
            defects.add(which, ', '.join(map(str, got)), 'matrix.jsonl', 'cell',
                        f'{cell[0]} at {cell[1]} worker(s) is not a cell of the benchmark', ['DoD-CLARA-01'])
    steps = []
    for row in rows:
        name = row.get('step')
        kind = row.get('kind')
        ids = KIND_IDS.get(kind, ['DoD-CLARA-01'])
        base = os.path.join(load_dir, name)
        files = {}
        for suffix in ('-smoke.json', '-requests.json', '-traces.json', '-db.json', '-cdp.json'):
            data = load(base + suffix)
            if not isinstance(data, dict):
                defects.add(which, name, name + suffix, None,
                            'file missing' if not os.path.exists(base + suffix) else 'not readable as JSON',
                            FILE_IDS[suffix] + ids)
                data = {}
            files[suffix] = data
        smoke = row.get('smoke') or files['-smoke.json']
        traces, requests, db, cdp = (files[k] for k in ('-traces.json', '-requests.json', '-db.json', '-cdp.json'))
        try:
            log = open(base + '.log').read()
        except OSError:
            log = ''
            defects.add(which, name, name + '.log', None, 'file missing', FILE_IDS['.log'] + ids)
        pool = re.search(r'Pool: (\d+) items', log)
        pool = int(pool.group(1)) if pool else None
        if row.get('mode') != MODE:
            defects.add(which, name, 'matrix.jsonl', 'mode', f"{row.get('mode')!r}, not {MODE}", ['DoD-CLARA-01'] + ids)
        if pool is None or pool < MIN_POOL:
            defects.add(which, name, name + '.log', 'pool', f'{pool!r} items, not at least {MIN_POOL}',
                        ['AK-CLARA-05', 'DoD-CLARA-01'])
        if row.get('result') not in ('PASS', 'FAIL'):
            defects.add(which, name, 'matrix.jsonl', 'result', f"{row.get('result')!r}", ids)
        if not number(row.get('from')) or not number(row.get('until')) or row['until'] <= row['from'] \
                or not number(row.get('seconds')) or row['seconds'] <= 0:
            defects.add(which, name, 'matrix.jsonl', 'from/until/seconds',
                        f"{row.get('from')!r}/{row.get('until')!r}/{row.get('seconds')!r}: no time window", ids)
        # Status counts that add up (#170 DoD 1 f).
        sittings = smoke.get('sittings') or {}
        executions = smoke.get('executions') or {}
        parts = sum(int(sittings.get(k) or 0) for k in ('collected', 'queued', 'running', 'failed', 'cancelled',
                                                         'other'))
        if not number(sittings.get('total')) or sittings['total'] != parts or sittings['total'] != row.get('persons'):
            defects.add(which, name, name + '-smoke.json', 'sittings', f"total {sittings.get('total')!r}, its parts add "
                        f"up to {parts}, the step has {row.get('persons')!r} person(s)", ['AK-CLARA-05'] + ids)
        validity = smoke.get('validity') or {}
        if int(sittings.get('collected') or 0) and sum(int(v or 0) for v in validity.values()) != sittings['collected']:
            defects.add(which, name, name + '-smoke.json', 'validity', f'{json.dumps(validity)} for '
                        f"{sittings.get('collected')} collected sitting(s)", ['AK-CLARA-05'] + ids)
        started = int(sittings.get('collected') or 0) + int(sittings.get('failed') or 0)
        if not number(executions.get('total')) or executions['total'] < started:
            defects.add(which, name, name + '-smoke.json', 'executions', f"{executions.get('total')!r} execution(s) for "
                        f'{started} sitting(s) that ended', ['AK-CLARA-05'] + ids)
        for key in ('http5xx', 'fatals'):
            if not number(row.get(key)) or row[key] < 0:
                defects.add(which, name, 'matrix.jsonl', key, f'{row.get(key)!r}: not counted', ['AK-CLARA-05'] + ids)
            elif which == 'after' and row[key] > 0:
                # The gate leg: a 5xx or a fatal (an out-of-memory among them) is never masked by anything else.
                defects.add(which, name, name + '-requests.json', key, f'{row[key]} in the gate leg',
                            ['AK-CLARA-05'] + ids)
        # Latencies that are figures (#170 DoD 1 f).
        strategy = (traces.get('spans') or {}).get('strategy')
        if kind == 'load' or strategy is not None:
            wrong = latency(strategy)
            if wrong:
                defects.add(which, name, name + '-traces.json', 'selector', wrong, ['AK-CLARA-03', 'DoD-CLARA-01'])
        if row.get('request_ms') is not None:
            wrong = latency(row['request_ms'])
            if wrong:
                defects.add(which, name, 'matrix.jsonl', 'request_ms', wrong, ['AK-CLARA-03'] + ids)
        if files['-cdp.json'] and not all(k in cdp for k in ('counts', 'sides', 'unattributed', 'problems')):
            defects.add(which, name, name + '-cdp.json', None, 'without counts/sides/unattributed/problems',
                        ['AK-CLARA-01'])
        sampled = samples_check(base + '-samples.jsonl', row, which, name, defects)
        kinds = traces.get('kinds') or {}
        answers = sum(int((kinds.get(k) or {}).get('n') or 0) for k in ('next_item', 'complete')) or None
        dec = traces.get('decomposition') or {}
        seconds = int(row.get('seconds') or 0)
        steps.append({
            'step': name, 'kind': kind, 'mode': row.get('mode'), 'workers': row.get('workers'),
            'persons': row.get('persons'), 'pool': pool, 'result': row.get('result'),
            'from': utc(row.get('from')), 'until': utc(row.get('until')), 'seconds': seconds,
            'reasons': row.get('verdict_reasons') or [],
            'sittings': sittings, 'validity': validity,
            'executions': {'total': executions.get('total'), 'failed': executions.get('failed'),
                           'reasons': executions.get('reasons') or {}},
            'answers': answers,
            'answers_per_minute': round(60 * answers / seconds, 1) if answers and seconds else None,
            'sittings_per_minute': round(60 * int(sittings.get('collected') or 0) / seconds, 2) if seconds else None,
            'error_rate': (round(int(executions.get('failed') or 0) / int(executions['total']), 4)
                           if number(executions.get('total')) and executions['total'] else None),
            'requests': row.get('requests'), 'http5xx': row.get('http5xx'), 'fatals': row.get('fatals'),
            'exceptions': row.get('exceptions'), 'request_ms': row.get('request_ms'),
            'php_peak_mb': row.get('php_peak_mb'),
            # The memory_limit the web requests ran under, as they reported it (request_report), else as configured.
            'web_memory_limit': requests.get('memorylimit') or row.get('web_memory_limit'),
            'cpu_percent_max': row.get('cpu_percent_max'), 'mem_available_mb_min': row.get('mem_available_mb_min'),
            'apache_busy_max': row.get('apache_busy_max'), 'db_connections_max': row.get('db_connections_max'),
            'selector_ms': {k: strategy.get(k) for k in ('n', 'p50', 'p95', 'p99', 'max')}
            if isinstance(strategy, dict) else None,
            'engine_kinds': {k: {x: (v or {}).get(x) for x in ('n', 'p50', 'p95')} for k, v in kinds.items()},
            'decomposition': {k: (dec.get(k) or {}).get('status') for k in ('persistence', 'server', 'client')},
            'persistence_ms': ((dec.get('persistence') or {}).get('stats')),
            'server_db_ms': ((dec.get('server') or {}).get('dbms')),
            'server_lockwait_ms': ((dec.get('server') or {}).get('lockwaitms')),
            'client_wait_ms': ((dec.get('client') or {}).get('stats')),
            'db': {k: db.get(k) for k in ('readable', 'deadlocks', 'dmlerrors', 'failedwrites', 'connections', 'delta')},
            'cdp': {k: cdp.get(k) for k in ('counts', 'sides', 'unattributed', 'problems')} if cdp else None,
            'samples': sampled,
            'loadguard': {'throttled_samples': row.get('throttled_samples'), 'workers_live_max': row.get('workers_live_max'),
                          'workers_playing_max': row.get('workers_playing_max'),
                          'worker_limit_min': row.get('worker_limit_min'), 'worker_cap': row.get('worker_cap'),
                          'smoke': smoke.get('loadguard')},
        })
    engines, header = {}, ''
    try:
        for line in open(os.path.join(path, 'engine-manifest.txt')):
            if line.startswith('# engine set') and not header:
                header = line.split()[3] if len(line.split()) > 3 else ''
            parts = line.split()
            if len(parts) >= 2 and parts[0] in tuple(COMPONENTS) + ('engine_set',):
                engines[parts[0]] = ' '.join(parts[1:])
    except OSError:
        pass
    return {'engines': engines, 'engine_family': header, 'host': load(os.path.join(path, 'host.json')),
            'limits': load(os.path.join(path, 'limits.json')), 'steps': steps}


def pins(path):
    """KEY=VALUE lines of an engine-pins file, or None."""
    try:
        out = {}
        for line in open(path):
            m = re.match(r'^([A-Z0-9_]+)=(\S*)', line.strip())
            if m:
                out[m.group(1)] = m.group(2)
        return out
    except (OSError, TypeError):
        return None


def provenance(after, before, defects, commit, pinfiles, profile):
    """Host, limits and engines of both legs (#170 AK 3): readable, the pinned sets, the commit under test, and how
    far the two runners are comparable — said, never assumed."""
    compare = {}
    for which, data in (('after', after), ('before', before)):
        if not data:
            continue
        host = data.get('host')
        if not isinstance(host, dict):
            defects.add(which, None, 'host.json', None, 'missing or not readable', ['DoD-CLARA-01'])
            host = {}
        need = ['plugin', 'run', 'runner', 'cpus'] + (['cpu_model', 'mem_total_mb', 'leg', 'pins']
                                                     if profile == 'current' else [])
        missing = [k for k in need if host.get(k) in (None, '')]
        if missing:
            defects.add(which, None, 'host.json', ', '.join(missing), 'not recorded', ['DoD-CLARA-01'])
        if host.get('plugin') and not SHA.match(str(host['plugin'])):
            defects.add(which, None, 'host.json', 'plugin', f"{host['plugin']!r} is not a commit", ['DoD-CLARA-01'])
        if not commit:
            defects.add(which, None, 'host.json', 'plugin', 'the commit under test was not given (--commit): the '
                        'plugin of this leg is not checked', ['DoD-CLARA-01'])
        elif host.get('plugin') and host['plugin'] != commit:
            defects.add(which, None, 'host.json', 'plugin', f"{host['plugin']}, not the commit under test {commit}",
                        ['DoD-CLARA-01'])
        if profile == 'current' and host.get('leg') not in (None, which):
            defects.add(which, None, 'host.json', 'leg', f"{host.get('leg')!r}", ['DoD-CLARA-01'])
        if profile != 'current' and host.get('plugin') != PROFILE_COMMITS[profile]:
            defects.add(which, None, 'host.json', 'plugin', f"profile {profile} applies to {PROFILE_COMMITS[profile]} "
                        f"only, not to {host.get('plugin')!r}", ['DoD-CLARA-01'])
        if not isinstance(data.get('limits'), dict):
            defects.add(which, None, 'limits.json', None, 'missing or not readable', ['web-limits', 'db-limits'])
        engines = data.get('engines') or {}
        if not engines:
            defects.add(which, None, 'engine-manifest.txt', None, 'missing or without components', ['DoD-CLARA-01'])
            continue
        if (engines.get('engine_set') or '').split()[:1] != [SETS[which]]:
            defects.add(which, None, 'engine-manifest.txt', 'engine_set', f"{engines.get('engine_set')!r}, not "
                        f'{SETS[which]!r}', ['DoD-CLARA-01'])
        pinned = pins(pinfiles.get(SETS[which]))
        prefix = {'LEGACY': 'LEGACY_', 'M5': 'M5_'}.get(data.get('engine_family'))
        if pinned is None:
            defects.add(which, None, f'engine-pins ({SETS[which]})', None, 'not given or not readable: the engines '
                        'of this leg are not checked against their pins', ['DoD-CLARA-01'])
        if prefix is None:
            defects.add(which, None, 'engine-manifest.txt', 'engine set', f"family {data.get('engine_family')!r} "
                        '(LEGACY or M5) not said', ['DoD-CLARA-01'])
        for component, key in COMPONENTS.items():
            got = (engines.get(component) or '').split()
            sha = got[1] if len(got) >= 2 else ''
            if not SHA.match(sha):
                defects.add(which, None, 'engine-manifest.txt', component, f'no resolved commit ({sha!r})',
                            ['DoD-CLARA-01'])
                continue
            want = (pinned or {}).get(key if key.startswith('WUNDERBYTE') else (prefix or '') + key)
            if pinned is not None and prefix is not None and sha != want:
                defects.add(which, None, 'engine-manifest.txt', component, f'{sha}, the {SETS[which]} pin is {want!r}',
                            ['DoD-CLARA-01'])
        limits = data.get('limits') or {}
        compare[which] = {
            'runner': host.get('runner'), 'image': host.get('image'), 'run': host.get('run'),
            'cpus': host.get('cpus'), 'cpu_model': host.get('cpu_model'), 'mem_total_mb': host.get('mem_total_mb'),
            'memory_limit': (limits.get('php_web') or {}).get('memory_limit'),
            'max_execution_time': (limits.get('php_web') or {}).get('max_execution_time'),
            'sapi': (limits.get('php_web') or {}).get('sapi'),
            'pm': (limits.get('php_fpm_pool') or {}).get('pm'),
            'pm.max_children': (limits.get('php_fpm_pool') or {}).get('pm.max_children'),
            'max_connections': (limits.get('database') or {}).get('max_connections'),
        }
    if after and before:
        local = lambda d: ((d.get('engines') or {}).get('local_catquiz') or '').split()[1:2]
        if local(after) and local(after) == local(before):
            defects.add('both', None, 'engine-manifest.txt', 'local_catquiz', f'the same commit in both legs '
                        f'({local(after)[0]}): no before and after', ['DoD-CLARA-01'])
        pools = lambda d: sorted({s['pool'] for s in d['steps'] if s['pool']})
        if pools(after) != pools(before):
            defects.add('both', None, '*.log', 'pool', f'pools differ: after {pools(after)}, before {pools(before)}',
                        ['DoD-CLARA-01'])
    if 'after' in compare and 'before' in compare:
        # The same settings, or no comparison: what the two legs ran under (#170 AK 3).
        for key in ('cpus', 'memory_limit', 'max_execution_time', 'sapi', 'pm', 'pm.max_children', 'max_connections'):
            if compare['after'][key] != compare['before'][key]:
                defects.add('both', None, 'host.json/limits.json', key, f"after {compare['after'][key]!r}, before "
                            f"{compare['before'][key]!r}: not comparable", ['DoD-CLARA-01'])
    notes = []
    if compare:
        a, b = compare.get('after') or {}, compare.get('before') or {}
        notes.append('Two GitHub-hosted runners, one per leg: not the same physical machine, and not reported as one. '
                     f"after: runner {a.get('runner')!r}, image {a.get('image')!r}, {a.get('cpus')} CPU(s) "
                     f"{a.get('cpu_model') or '(model not recorded)'}, {a.get('mem_total_mb') or '?'} MB; before: "
                     f"runner {b.get('runner')!r}, image {b.get('image')!r}, {b.get('cpus')} CPU(s) "
                     f"{b.get('cpu_model') or '(model not recorded)'}, {b.get('mem_total_mb') or '?'} MB.")
        if a.get('cpu_model') and b.get('cpu_model') and a['cpu_model'] != b['cpu_model']:
            notes.append('The CPU models differ: absolute times compare across legs only roughly; the order of '
                         'magnitude of the selector\'s time is what the comparison rests on.')
    return {'legs': compare, 'notes': notes}


def check(after, before, defects=None):
    """The points, each PASS, FAIL or WAIVED with its evidence; a defect fails the points it names."""
    defects = defects if defects is not None else Defects()
    points = []

    def point(key, title, ok, evidence, waived=None):
        status = 'WAIVED' if waived else ('PASS' if ok else 'FAIL')
        points.append({'id': key, 'title': title, 'status': status, 'evidence': evidence,
                       **({'waiver': waived} if waived else {})})

    point('GATE-170', 'Datensatz vollständig, lesbar und schlüssig (Zellen, Dateien, Samples, Status, Latenzen, '
          'Provenienz)', not defects.items,
          [defects.line(d) for d in defects.items] or [
              f"after {len((after or {}).get('steps') or [])} step(s), before {len((before or {}).get('steps') or [])} "
              f"step(s): every required cell once, every file readable, "
              f"{sum(((s.get('samples') or {}).get('n') or 0) for d in (after, before) for s in (d or {}).get('steps') or [])}"
              ' samples read, counts and latencies consistent, engines on their pins'])

    steps = (after or {}).get('steps') or []
    load_steps = [s for s in steps if s['kind'] == 'load']
    faults = [s for s in steps if s['kind'] == 'faults']
    querylog = [s for s in steps if s['kind'] == 'querylog']
    resume = [s for s in steps if s['kind'] == 'resume']
    if not steps:
        for key in POINT_IDS[1:]:
            if key == 'AK-CLARA-05-historical':
                points.append({'id': key, 'title': 'Runs #87/#88 auf CLARA', 'status': 'WAIVED',
                               'evidence': ['not reconstructable here'], 'waiver': WAIVED_HISTORY})
            else:
                point(key, key, False, ['no step of the after leg: nothing measured'])
        return finish(points, defects)

    # Datenbankfehler.
    bad = [f"{s['step']}: {s['db']}" for s in steps
           if not s['db'].get('readable') or s['db'].get('dmlerrors') or s['db'].get('deadlocks')]
    point('db-errors', 'Datenbankfehler', not bad,
          bad or [f"{len(steps)} step(s): no exception of the database layer, 0 deadlocks, counters read"])

    # PHP-/Webserver-Grenzen.
    limits = (after or {}).get('limits') or {}
    need = {'php_web': ['memory_limit', 'max_execution_time', 'sapi'],
            'php_fpm_pool': ['pm', 'pm.max_children', 'request_terminate_timeout'],
            'apache': ['Timeout', 'ProxyTimeout', 'KeepAliveTimeout', 'MaxRequestWorkers']}
    missing = [f'{part}.{key}' for part, keys in need.items() for key in keys
               if not (limits.get(part) or {}).get(key) or 'file missing' in str((limits.get(part) or {}).get(key))]
    if not limits.get('gateway'):
        missing.append('gateway')
    seen = sorted({str(s.get('web_memory_limit')) for s in steps if s.get('web_memory_limit')})
    point('web-limits', 'PHP-/Webserver-Grenzen dokumentieren', not missing,
          [f'missing: {missing}'] if missing else [json.dumps({k: limits.get(k) for k in need}), f'gateway: {limits.get("gateway")}',
                                                   f'web memory_limit of the steps: {seen}'])

    # Datenbankseitig.
    database = limits.get('database') or {}
    try:
        maxconn = int(database.get('max_connections') or 0)
    except ValueError:
        maxconn = 0
    peak = max((int(s.get('db_connections_max') or 0) for s in steps), default=0)
    writes = sum(int(s['db'].get('failedwrites') or 0) for s in steps)
    dead = sum(int(s['db'].get('deadlocks') or 0) for s in steps)
    ok = maxconn > 0 and peak < maxconn and writes == 0 and dead == 0 and all(s['db'].get('readable') for s in steps)
    point('db-limits', 'Datenbankseitig: Verbindungen, Locks/Deadlocks, fehlgeschlagene Writes', ok,
          [f'max_connections {maxconn or "unknown"}, at most {peak} connection(s) in a step, {dead} deadlock(s), '
           f'{writes} failed write(s); limits {json.dumps(database)}'])

    # AK-CLARA-01.
    unattributed = [s['step'] for s in steps if not s['cdp'] or s['cdp'].get('unattributed') or s['cdp'].get('problems')]
    events = {s['step']: (s['cdp'] or {}).get('counts') for s in steps}
    ok = not unattributed and bool(faults) and all(f['result'] == 'PASS' for f in faults)
    point('AK-CLARA-01', 'CDP-Timeout-Lebenslauf rekonstruierbar, Seite bestimmt', ok,
          ([f'unattributed or unexpected: {unattributed}'] if unattributed else [])
          + ([] if faults else ['no faults step: the injected causes were not checked'])
          + [f'events by cause per step: {json.dumps(events)}'])

    # AK-CLARA-03.
    gaps = []
    for s in load_steps:
        if not s.get('selector_ms') or not (s['selector_ms'] or {}).get('n'):
            gaps.append(f"{s['step']}: no selector span")
        if s['decomposition'].get('server') != 'measured' or s['decomposition'].get('client') != 'measured':
            gaps.append(f"{s['step']}: decomposition {s['decomposition']}")
    if not querylog or querylog[0]['decomposition'].get('persistence') != 'measured':
        gaps.append('query-log step: the trace INSERT not measured')
    point('AK-CLARA-03', 'Selektorzeit getrennt von CDP/Navigation, Apache/PHP, Trace-Persistierung, Lastguard',
          not gaps, gaps or [
              f"{s['step']}: selector p50/p95 {s['selector_ms'].get('p50')}/{s['selector_ms'].get('p95')} ms, "
              f"DB p50 {(s['server_db_ms'] or {}).get('p50')} ms, lock wait p95 {(s['server_lockwait_ms'] or {}).get('p95')} ms, "
              f"client p50 {(s['client_wait_ms'] or {}).get('p50')} ms, throttled samples "
              f"{s['loadguard']['throttled_samples']}" for s in load_steps]
          + [f"trace INSERT p50/p95 {(querylog[0]['persistence_ms'] or {}).get('p50')}/"
             f"{(querylog[0]['persistence_ms'] or {}).get('p95')} ms" if querylog else ''])

    # AK-CLARA-04.
    problems = []
    for s in load_steps:
        lg = s['loadguard']
        # None or 0: no sample saw a worker — a load step without workers measured nothing (re-audit 22.11.2026).
        if not lg.get('workers_live_max'):
            problems.append(f"{s['step']}: no worker sample (workers live: {lg.get('workers_live_max')!r})")
            continue
        cap = int(lg.get('worker_cap') or 0)
        if cap and int(lg['workers_live_max']) > cap:
            problems.append(f"{s['step']}: {lg['workers_live_max']} live workers above the safety cap {cap}")
        if int(lg.get('throttled_samples') or 0) > 0 and int(s['workers'] or 0) <= 25:
            problems.append(f"{s['step']}: throttled at {s['workers']} workers ({lg.get('smoke')})")
    point('AK-CLARA-04', 'Drosselung und Safety-Cap unter 1/5/12/25 Workern gemessen', not problems and bool(load_steps),
          problems or [f"{s['step']}: {(s.get('samples') or {}).get('n')} samples, live at most "
                       f"{s['loadguard']['workers_live_max']}, limit at least {s['loadguard']['worker_limit_min']}, cap "
                       f"{s['loadguard']['worker_cap'] or 'none'}, throttled samples "
                       f"{s['loadguard']['throttled_samples']}" for s in load_steps]
          + ([f"before: {b['step']} {(b.get('samples') or {}).get('n')} samples, throttled samples "
              f"{b['loadguard']['throttled_samples']}, guard {json.dumps(b['loadguard'].get('smoke'))}"
              for b in (before or {}).get('steps') or []]))

    # AK-CLARA-05.
    bad = [f"{s['step']}: {s['result']} {json.dumps(s['sittings'])}" for s in load_steps + faults + resume
           if s['result'] != 'PASS' or s['sittings'].get('collected') != s['sittings'].get('total')
           or s['sittings'].get('queued') or s['sittings'].get('running') or s['sittings'].get('failed')]
    pools = sorted({s['pool'] for s in steps if s['pool']})
    # Recovery across executions (CI e605b2e): every sitting of the resume step lost its first execution and was
    # retried, the retry continued the engine attempt, and its reconciliation counted the earlier answers.
    for s in resume:
        reasons = s['executions'].get('reasons') or {}
        retried = int(s['executions'].get('failed') or 0)
        persons = int(s.get('persons') or 0)
        if s['result'] != 'PASS' or retried != persons or set(reasons) != {'execution_context_destroyed'}:
            bad.append(f"{s['step']}: {s['result']}, {retried} execution(s) retried for {persons} sitting(s), "
                       f"causes {json.dumps(reasons)} (expected each sitting retried once, execution_context_destroyed)")
    point('AK-CLARA-05', 'Alle Sittings terminal und auswertbar; Recovery ohne Doppelabgabe', not bad and bool(load_steps),
          bad or [f"{s['step']}: {s['sittings'].get('collected')}/{s['sittings'].get('total')} collected, validity "
                  f"{json.dumps(s['validity'])}" for s in load_steps + faults + resume] + [f'pool {pools}'])
    points.append({'id': 'AK-CLARA-05-historical', 'title': 'Runs #87/#88 auf CLARA', 'status': 'WAIVED',
                   'evidence': ['not reconstructable here'], 'waiver': WAIVED_HISTORY})

    # DoD-CLARA-01.
    bsteps = (before or {}).get('steps') or []
    nosel = [f"{which} {s['step']}: no selector time" for which, group in (('before', bsteps), ('after', load_steps))
             for s in group if s['kind'] == 'load' and not (s.get('selector_ms') or {}).get('n')]
    ok = bool(bsteps) and bool(load_steps) and not nosel
    point('DoD-CLARA-01', 'Vorher/Nachher auf demselben Benchmark (HIGHESTSUB, ≥ 2 500 Items, 1/5/12/25)', ok,
          nosel
          + [f"engines: after {json.dumps((after or {}).get('engines'))}, before {json.dumps((before or {}).get('engines'))}"]
          + [f"{which} {s['step']} {s['from']}–{s['until']}: selector p50 {(s.get('selector_ms') or {}).get('p50')} ms, "
             f"answers/min {s['answers_per_minute']}, error rate {s['error_rate']}, uncertain submissions "
             f"{json.dumps((s.get('cdp') or {}).get('sides'))}, result {s['result']}"
             for which, group in (('before', bsteps), ('after', load_steps)) for s in group])
    return finish(points, defects)


def finish(points, defects):
    """A defect fails every point it names, and is said there first (#170 AK 5)."""
    for p in points:
        if p['id'] in WAIVABLE:
            continue
        found = defects.of(p['id'])
        if found and p['id'] != 'GATE-170':
            p['status'] = 'FAIL'
            p['evidence'] = [f'defect: {d}' for d in found] + p['evidence']
    return points


def root_cause(after, before):
    """What the data say caused the incident, in words — only from figures that are there."""
    lines = []
    for which, data in (('before', before), ('after', after)):
        for s in (data or {}).get('steps') or []:
            if s['kind'] != 'load':
                continue
            sel = s.get('selector_ms') or {}
            sides = (s.get('cdp') or {}).get('sides') or {}
            lines.append(f"- {which}, {s['step']} ({s['pool']} items, {s['workers']} worker(s)): selector p50 "
                         f"{sel.get('p50')} ms, p95 {sel.get('p95')} ms (n {sel.get('n')}); uncertain submissions: "
                         f"server side {sides.get('server', 0)}, client side {sides.get('client', 0)}; "
                         f"{s['result']}")
    bsel = [((s.get('selector_ms') or {}).get('p50') or 0) for s in (before or {}).get('steps') or [] if s['kind'] == 'load']
    asel = [((s.get('selector_ms') or {}).get('p50') or 0) for s in (after or {}).get('steps') or [] if s['kind'] == 'load']
    bserver = sum(((s.get('cdp') or {}).get('sides') or {}).get('server', 0) for s in (before or {}).get('steps') or [])
    bunknown = sum(int((s.get('cdp') or {}).get('unattributed') or 0) for s in (before or {}).get('steps') or [])
    if bunknown:
        lines.append(f'{bunknown} uncertain submission(s) of the before leg could not be given a side (the worker\'s '
                     'times or the sitting missing): the cause below rests on the others only.')
    if bsel and asel and max(bsel) > 10 * max(max(asel), 1):
        lines.append(f'Cause, as measured: the selector of the previous engine set took up to {max(bsel):.0f} ms per '
                     f'question at this pool size, the release set at most {max(asel):.0f} ms; '
                     + (f'{bserver} uncertain submission(s) of the before leg were still running on the server when '
                        'the browser gave up (server side) — the CDP timeouts followed from the selector\'s time, '
                        'not from the browser.' if bserver else 'no uncertain submission of the before leg was on the '
                        'server side.'))
    elif bsel and asel:
        lines.append('The before and after legs do not differ by an order of magnitude in the selector\'s time: the '
                     'figures above, no cause claimed.')
    else:
        lines.append('One leg has no selector time: no cause claimed.')
    lines.append('Limits of this statement: the two legs differ in several engine commits at once (release against '
                 'previous set), not in one patch; they ran on two runners; and it is a benchmark of the same kind '
                 'as the incident, not its forensic reconstruction.')
    return lines


def write(out, after, before, defects=None, comparability=None, profile='current'):
    defects = defects if defects is not None else Defects()
    points = check(after, before, defects)
    report = {'generated': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'), 'profile': profile,
              'required': {k: [list(c) for c in v] for k, v in PROFILES[profile].items()},
              'after': after, 'before': before, 'defects': defects.items, 'comparability': comparability,
              'points': points, 'rootcause': root_cause(after, before)}
    os.makedirs(out, exist_ok=True)
    with open(os.path.join(out, 'clara-bench.json'), 'w') as f:
        json.dump(report, f, indent=2)
    md = ['# CLARA-like benchmark (#110): HIGHESTSUB, at least 2 500 items', '',
          f'Matrix profile: {profile} — after {PROFILES[profile]["after"]}, before {PROFILES[profile]["before"]}', '']
    for which, data in (('after (release engine set)', after), ('before (previous engine set)', before)):
        if not data:
            md += [f'## {which}: missing', '']
            continue
        md += [f'## {which}', '', f"Engines: {json.dumps(data.get('engines'))}  ", f"Host: {json.dumps(data.get('host'))}", '',
               '| step | pool | result | UTC | sittings | answers/min | error rate | 5xx | selector p50/p95 ms | '
               'request p50/p95 ms | client wait p50 ms | uncertain (server/client/?) | samples | throttled |',
               '|' + '---|' * 14]
        for s in data['steps']:
            sel = s.get('selector_ms') or {}
            req = s.get('request_ms') or {}
            sides = (s.get('cdp') or {}).get('sides') or {}
            md.append(f"| {s['step']} | {s['pool']} | {s['result']} | {s['from']}–{s['until']} | "
                      f"{s['sittings'].get('collected')}/{s['sittings'].get('total')} | {s['answers_per_minute']} | "
                      f"{s['error_rate']} | {s['http5xx']} | {sel.get('p50')}/{sel.get('p95')} | "
                      f"{req.get('p50')}/{req.get('p95')} | {(s.get('client_wait_ms') or {}).get('p50')} | "
                      f"{sides.get('server', 0)}/{sides.get('client', 0)}/{sides.get('unknown', 0)} | "
                      f"{(s.get('samples') or {}).get('n')} | {s['loadguard'].get('throttled_samples')} |")
        md.append('')
    md += ['## Comparability of the two legs', ''] + ((comparability or {}).get('notes') or ['not known']) + ['']
    md += ['## Defects of the data set (#170)', ''] + ([f'- {defects.line(d)}' for d in defects.items] or ['none']) + ['']
    md += ['## The points', '']
    for p in points:
        md.append(f"- **{p['id']}** {p['title']}: **{p['status']}**")
        for e in p['evidence']:
            if e:
                md.append(f'  - {e}')
        if p.get('waiver'):
            md.append(f"  - Waiver: {p['waiver']}")
    md += ['', '## Root cause', ''] + report['rootcause'] + ['']
    with open(os.path.join(out, 'clara-bench.md'), 'w') as f:
        f.write('\n'.join(md))
    return points


def verify_report(out):
    """The report as written (#170 DoD 1 f, AK 4): readable, every point there once, statuses allowed, the waiver
    WAIVED and nothing else WAIVED, the Markdown saying the same, nothing FAIL."""
    problems = []
    report = load(os.path.join(out, 'clara-bench.json'))
    if not isinstance(report, dict) or not isinstance(report.get('points'), list):
        return ['clara-bench.json missing, not readable or without points']
    ids = [p.get('id') for p in report['points'] if isinstance(p, dict)]
    for key in POINT_IDS:
        if ids.count(key) != 1:
            problems.append(f'{key}: {ids.count(key)} time(s) in the report, not once')
    for key in sorted(set(ids) - set(POINT_IDS)):
        problems.append(f'{key}: not a point of this benchmark')
    try:
        md = open(os.path.join(out, 'clara-bench.md')).read()
    except OSError:
        md = None
        problems.append('clara-bench.md missing')
    for p in report['points']:
        status = p.get('status') if isinstance(p, dict) else None
        key = p.get('id') if isinstance(p, dict) else None
        if status not in ('PASS', 'FAIL', 'WAIVED'):
            problems.append(f'{key}: status {status!r}')
        elif key in WAIVABLE and status != 'WAIVED':
            problems.append(f'{key}: {status}, but it is waived operator evidence — WAIVED, never {status}')
        elif key not in WAIVABLE and status == 'WAIVED':
            problems.append(f'{key}: WAIVED, but only {sorted(WAIVABLE)} may be')
        elif status == 'FAIL':
            problems.append(f'{key}: FAIL')
        if md is not None and key and f'**{key}**' in md and f'**{key}** {p.get("title")}: **{status}**' not in md:
            problems.append(f'{key}: the Markdown report says otherwise than the JSON')
    if report.get('defects'):
        problems.append(f"{len(report['defects'])} defect(s) of the data set")
    return problems


# ----------------------------------------------------------------------------------------------------------------------
# The negative matrix (#170 DoD 1).

REF_SHA = 'c' * 40
PIN_RELEASE = {'LEGACY_CATQUIZ_SHA': 'a' * 40, 'LEGACY_ADAPTIVEQUIZ_SHA': 'a1' * 20, 'LEGACY_CATMODEL_SHA': 'a2' * 20,
               'WUNDERBYTE_TABLE_SHA': 'a3' * 20}
PIN_PREVIOUS = {'LEGACY_CATQUIZ_SHA': 'b' * 40, 'LEGACY_ADAPTIVEQUIZ_SHA': 'b1' * 20, 'LEGACY_CATMODEL_SHA': 'b2' * 20,
                'WUNDERBYTE_TABLE_SHA': 'b3' * 20}


def fixture(root, which, cells, profile='current'):
    """A complete, consistent leg as load_matrix.sh and the workflow write it."""
    pinned = PIN_RELEASE if which == 'after' else PIN_PREVIOUS
    os.makedirs(os.path.join(root, 'load'), exist_ok=True)
    with open(os.path.join(root, 'engine-manifest.txt'), 'w') as f:
        f.write(f'# engine set LEGACY for MOODLE_405_STABLE, 2026-11-23T07:54:45Z, pinned\n'
                f'engine_set  {SETS[which]}\n'
                'component                      ref                        commit                                   version\n'
                f"local_wunderbyte_table         main                       {pinned['WUNDERBYTE_TABLE_SHA']} 2026112000\n"
                f'# local_catquiz/catquizcentralhub/host: recorded commit {"d" * 40} not on its remote, left out\n'
                f"local_catquiz                  ALiSe-v-1.2.0-legacy       {pinned['LEGACY_CATQUIZ_SHA']} 2026111707\n"
                f"mod_adaptivequiz               ALiSe-v-1.2.0-legacy       {pinned['LEGACY_ADAPTIVEQUIZ_SHA']} 2026111706\n"
                f"adaptivequizcatmodel_catquiz   ALiSe-v-1.2.0-legacy       {pinned['LEGACY_CATMODEL_SHA']} 2026111709\n")
    json.dump({'plugin': REF_SHA, 'run': '1', 'runner': f'GitHub Actions {which}', 'image': '20261111.1', 'cpus': 4,
               'cpu_model': 'AMD EPYC 7763 64-Core Processor', 'mem_total_mb': 16000, 'leg': which,
               'pins': SETS[which]}, open(os.path.join(root, 'host.json'), 'w'))
    json.dump({'php_web': {'memory_limit': '512M', 'max_execution_time': '300', 'sapi': 'fpm-fcgi'},
               'php_fpm_pool': {'pm': 'static', 'pm.max_children': '64', 'request_terminate_timeout': '300'},
               'apache': {'Timeout': '300', 'ProxyTimeout': 'not set (default Timeout)',
                          'KeepAliveTimeout': '5', 'MaxRequestWorkers': '400'},
               'gateway': 'none', 'database': {'max_connections': '100'}}, open(os.path.join(root, 'limits.json'), 'w'))
    rows = []
    t0 = 1795352394
    slow = which == 'before'
    for i, (kind, workers) in enumerate(cells):
        name = f'highestsub-w{workers}' + ('' if kind == 'load' else f'-{kind}')
        per = 1 if slow else 2
        persons = workers * per
        retried = persons if kind == 'resume' else 0
        start, end = t0 + i * 1000, t0 + i * 1000 + 300
        result = 'FAIL' if slow else 'PASS'
        samples = [{'time': start + 30 * k, 'workerslive': workers, 'workersplaying': workers, 'workerlimit': 25,
                    'workercap': 25, 'throttled': False, 'dbconnections': 12} for k in range(10)]
        rows.append({'step': name, 'kind': kind, 'mode': MODE, 'workers': workers, 'persons': persons,
                     'result': result, 'seconds': 300, 'from': start, 'until': end, 'samples': len(samples),
                     'http5xx': 0, 'fatals': 0, 'exceptions': 0, 'db_connections_max': 12, 'web_memory_limit': '512M',
                     'request_ms': {'n': 400, 'p50': 80, 'p95': 500, 'p99': 800, 'max': 1400},
                     'workers_live_max': workers, 'workers_playing_max': workers, 'worker_limit_min': 25,
                     'worker_cap': 25, 'throttled_samples': 0})
        base = os.path.join(root, 'load', name)
        collected = 0 if slow else persons
        json.dump({'sittings': {'total': persons, 'collected': collected, 'queued': 0, 'running': 0,
                                'failed': persons - collected, 'cancelled': 0, 'other': 0},
                   'executions': {'total': persons + retried, 'failed': retried + (persons - collected),
                                  'reasons': ({'execution_context_destroyed': {'n': retried, 'capacity': True}}
                                              if retried else {})},
                   'validity': {'valid': collected} if collected else {}}, open(base + '-smoke.json', 'w'))
        json.dump({'memorylimit': '512M'}, open(base + '-requests.json', 'w'))
        json.dump({'kinds': {'next_item': {'n': 100, 'p50': 300}}, 'spans': {'strategy': {
            'n': 100, 'p50': 60000 if slow else 250, 'p95': 65000 if slow else 400, 'max': 70000 if slow else 900}},
            'decomposition': {'server': {'status': 'measured'}, 'client': {'status': 'measured'},
                              'persistence': {'status': 'measured' if kind == 'querylog' else 'not_measured',
                                              'stats': {'p50': 0.8, 'p95': 2.5}}}}, open(base + '-traces.json', 'w'))
        json.dump({'readable': True, 'deadlocks': 0, 'dmlerrors': {}, 'failedwrites': 0}, open(base + '-db.json', 'w'))
        json.dump({'counts': {}, 'sides': {'server': 5 if slow else 0, 'client': 0}, 'unattributed': 0,
                   'problems': []}, open(base + '-cdp.json', 'w'))
        open(base + '.log', 'w').write('  Pool: 2502 items over 3 subscales (--pool).\n')
        with open(base + '-samples.jsonl', 'w') as f:
            f.write('\n'.join(json.dumps(x) for x in samples) + '\n{"time": 17953')   # cut mid-line, as when stopped
    with open(os.path.join(root, 'load', 'matrix.jsonl'), 'w') as f:
        f.write('\n'.join(json.dumps(r) for r in rows) + '\n')
    return root


def edit_json(path, change):
    data = json.load(open(path))
    change(data)
    json.dump(data, open(path, 'w'))


def edit_matrix(root, step, change):
    path = os.path.join(root, 'load', 'matrix.jsonl')
    rows = [json.loads(l) for l in open(path) if l.strip()]
    for r in rows:
        if r['step'] == step:
            change(r)
    open(path, 'w').write('\n'.join(json.dumps(r) for r in rows) + '\n')


def replace_in(path, old, new):
    text = open(path).read()
    open(path, 'w').write(text.replace(old, new))


def drop_cell(root, step):
    path = os.path.join(root, 'load', 'matrix.jsonl')
    rows = [l for l in open(path) if l.strip() and json.loads(l)['step'] != step]
    open(path, 'w').write(''.join(rows))


def self_test(protocol=None):
    import shutil
    import tempfile
    me = os.path.abspath(__file__)
    A, B = 'after', 'before'
    # Each case: label, the defect it injects, the change, the points that must FAIL, a phrase the report must name.
    # (a)–(f) are #170 DoD 1; the rest the other ways a data set can be incomplete or wrong.
    cases = [
        ('positive control', 'none: complete and consistent', lambda a, b, args: None, [], None),
        ('(a) empty sampler', 'after highestsub-w5-samples.jsonl emptied',
         lambda a, b, args: open(os.path.join(a, 'load', 'highestsub-w5-samples.jsonl'), 'w').close(),
         ['GATE-170', 'AK-CLARA-04'], 'no parseable sample'),
        ('(a) sampler without workers, matrix says 0', 'after w12: samples without worker fields, workers_live_max 0',
         lambda a, b, args: (open(os.path.join(a, 'load', 'highestsub-w12-samples.jsonl'), 'w').write(
             '\n'.join(json.dumps({'time': 1795354394 + 30 * k, 'dbconnections': 3}) for k in range(5)) + '\n'),
             edit_matrix(a, 'highestsub-w12', lambda r: r.update(workers_live_max=0, samples=5))),
         ['GATE-170', 'AK-CLARA-04'], 'no sample with a worker count'),
        ('(a) defective sampler line', 'after w25: an unparseable line in the middle',
         lambda a, b, args: open(os.path.join(a, 'load', 'highestsub-w25-samples.jsonl'), 'w').write(
             json.dumps({'time': 1795355394, 'workerslive': 25, 'workercap': 25, 'throttled': False}) + '\n{broken\n'
             + json.dumps({'time': 1795355424, 'workerslive': 25, 'workercap': 25, 'throttled': False}) + '\n'),
         ['GATE-170', 'AK-CLARA-04'], 'unparseable line(s) [2]'),
        ('(a) sampler of another step', 'after w1: samples from a time outside the step',
         lambda a, b, args: shutil.copy(os.path.join(a, 'load', 'highestsub-w25-samples.jsonl'),
                                        os.path.join(a, 'load', 'highestsub-w1-samples.jsonl')),
         ['GATE-170', 'AK-CLARA-04'], 'without a time inside the step'),
        ('(a) matrix and samples disagree', 'after w5: throttled_samples 0, the samples throttled',
         lambda a, b, args: open(os.path.join(a, 'load', 'highestsub-w5-samples.jsonl'), 'w').write(
             '\n'.join(json.dumps({'time': 1795353394 + 30 * k, 'workerslive': 5, 'workerlimit': 3, 'workercap': 25,
                                   'throttled': True}) for k in range(10)) + '\n'),
         ['GATE-170', 'AK-CLARA-04'], 'throttled_samples'),
        ('(b) after-w12 missing', 'after: the 12-worker cell removed', lambda a, b, args: drop_cell(a, 'highestsub-w12'),
         ['GATE-170', 'AK-CLARA-03', 'AK-CLARA-04', 'AK-CLARA-05', 'DoD-CLARA-01'], 'load at 12 worker(s)'),
        ('(b) after-w25 twice', 'after: the 25-worker cell twice',
         lambda a, b, args: open(os.path.join(a, 'load', 'matrix.jsonl'), 'a').write(
             [l for l in open(os.path.join(a, 'load', 'matrix.jsonl')) if '"highestsub-w25"' in l][0]),
         ['GATE-170', 'DoD-CLARA-01'], 'the same step twice'),
        ('(b) a cell not of the benchmark', 'after: an extra load cell at 50 workers',
         lambda a, b, args: edit_matrix(a, 'highestsub-w25', lambda r: r.update(workers=50)),
         ['GATE-170', 'DoD-CLARA-01'], 'not a cell of the benchmark'),
        ('(c) before-w25 missing', 'before: the 25-worker cell removed',
         lambda a, b, args: drop_cell(b, 'highestsub-w25'), ['GATE-170', 'DoD-CLARA-01'], 'load at 25 worker(s)'),
        ('(c) before leg missing', 'no before leg', lambda a, b, args: shutil.rmtree(b),
         ['GATE-170', 'DoD-CLARA-01'], 'the before leg is missing'),
        ('(d) engine manifest missing', 'after: engine-manifest.txt removed',
         lambda a, b, args: os.remove(os.path.join(a, 'engine-manifest.txt')), ['GATE-170', 'DoD-CLARA-01'],
         'missing or without components'),
        ('(d) engine off its pin', 'after: local_catquiz at another commit than the release pin',
         lambda a, b, args: replace_in(os.path.join(a, 'engine-manifest.txt'), 'a' * 40, 'e' * 40),
         ['GATE-170', 'DoD-CLARA-01'], 'the release pin is'),
        ('(d) before on the release set', 'before: engine_set release and the release commits',
         lambda a, b, args: shutil.copy(os.path.join(a, 'engine-manifest.txt'), os.path.join(b, 'engine-manifest.txt')),
         ['GATE-170', 'DoD-CLARA-01'], "not 'previous'"),
        ('(d) unresolved engine commit', 'after: mod_adaptivequiz without a commit',
         lambda a, b, args: replace_in(os.path.join(a, 'engine-manifest.txt'), 'a1' * 20, '-'),
         ['GATE-170', 'DoD-CLARA-01'], 'no resolved commit'),
        ('(d) no pins given', 'the pin files not passed', lambda a, b, args: args.update(pins=False),
         ['GATE-170', 'DoD-CLARA-01'], 'not checked against their pins'),
        ('(d) plugin not the commit under test', 'host.json of the before leg on another plugin commit',
         lambda a, b, args: edit_json(os.path.join(b, 'host.json'), lambda h: h.update(plugin='f' * 40)),
         ['GATE-170', 'DoD-CLARA-01'], 'not the commit under test'),
        ('(d) host manifest missing', 'after: host.json removed', lambda a, b, args: os.remove(os.path.join(a, 'host.json')),
         ['GATE-170', 'DoD-CLARA-01'], 'host.json'),
        ('(d) runners not comparable', 'before: 2 CPUs and memory_limit 256M',
         lambda a, b, args: (edit_json(os.path.join(b, 'host.json'), lambda h: h.update(cpus=2)),
                             edit_json(os.path.join(b, 'limits.json'),
                                       lambda m: m['php_web'].update(memory_limit='256M'))),
         ['GATE-170', 'DoD-CLARA-01'], 'not comparable'),
        ('(d) old profile for new data', '--profile 0.7.78 on a data set of another commit',
         lambda a, b, args: args.update(profile='0.7.78'), ['GATE-170', 'DoD-CLARA-01'], 'profile 0.7.78 applies to'),
        ('(e) query-log cell missing', 'after: the query-log step removed',
         lambda a, b, args: drop_cell(a, 'highestsub-w5-querylog'), ['GATE-170', 'AK-CLARA-03'], 'querylog at 5'),
        ('(e) faults cell missing', 'after: the faults step removed',
         lambda a, b, args: drop_cell(a, 'highestsub-w5-faults'), ['GATE-170', 'AK-CLARA-01', 'AK-CLARA-05'],
         'faults at 5'),
        ('(e) faults report missing', 'after: highestsub-w5-faults-cdp.json removed',
         lambda a, b, args: os.remove(os.path.join(a, 'load', 'highestsub-w5-faults-cdp.json')),
         ['GATE-170', 'AK-CLARA-01'], 'highestsub-w5-faults-cdp.json'),
        ('(e) resume cell missing', 'after: the resume step removed',
         lambda a, b, args: drop_cell(a, 'highestsub-w5-resume'), ['GATE-170', 'AK-CLARA-05'], 'resume at 5'),
        ('(f) latencies out of order', 'after w5: selector p50 above p95',
         lambda a, b, args: edit_json(os.path.join(a, 'load', 'highestsub-w5-traces.json'),
                                      lambda t: t['spans']['strategy'].update(p50=900, p95=400)),
         ['GATE-170', 'AK-CLARA-03', 'DoD-CLARA-01'], 'out of order'),
        ('(f) latency not a figure', 'before w1: selector p95 "n/a"',
         lambda a, b, args: edit_json(os.path.join(b, 'load', 'highestsub-w1-traces.json'),
                                      lambda t: t['spans']['strategy'].update(p95='n/a')),
         ['GATE-170', 'DoD-CLARA-01'], 'not figures'),
        ('(f) status counts do not add up', 'after w12: 24 sittings, 30 in their parts',
         lambda a, b, args: edit_json(os.path.join(a, 'load', 'highestsub-w12-smoke.json'),
                                      lambda m: m['sittings'].update(queued=6)),
         ['GATE-170', 'AK-CLARA-05'], 'its parts add up to 30'),
        ('(f) 5xx not counted', 'after w1: http5xx missing in the matrix',
         lambda a, b, args: edit_matrix(a, 'highestsub-w1', lambda r: r.pop('http5xx')),
         ['GATE-170', 'AK-CLARA-05'], 'not counted'),
        ('(f) a fatal in the gate leg', 'after w25: one fatal (out of memory) in a PASS step',
         lambda a, b, args: edit_matrix(a, 'highestsub-w25', lambda r: r.update(fatals=1)),
         ['GATE-170', 'AK-CLARA-05'], '1 in the gate leg'),
        ('(f) step report broken', 'after w5: requests.json not JSON',
         lambda a, b, args: open(os.path.join(a, 'load', 'highestsub-w5-requests.json'), 'w').write('{"requests": '),
         ['GATE-170', 'AK-CLARA-05', 'web-limits'], 'not readable as JSON'),
        ('(f) matrix line broken', 'before: a broken line in matrix.jsonl',
         lambda a, b, args: open(os.path.join(b, 'load', 'matrix.jsonl'), 'a').write('{"step": \n'),
         ['GATE-170', 'DoD-CLARA-01'], 'unparseable'),
        ('pool too small', 'after w5: 2 000 items',
         lambda a, b, args: open(os.path.join(a, 'load', 'highestsub-w5.log'), 'w').write('  Pool: 2000 items\n'),
         ['GATE-170', 'AK-CLARA-05', 'DoD-CLARA-01'], 'not at least 2500'),
        ('another strategy', 'after w1: mode allsubs',
         lambda a, b, args: edit_matrix(a, 'highestsub-w1', lambda r: r.update(mode='allsubs')),
         ['GATE-170', 'DoD-CLARA-01'], "not highestsub"),
    ]
    # Reports that must not pass --verify-report (#170 DoD 1 f, AK 4).
    report_cases = [
        ('(f) report: the waiver turned PASS', lambda r: [p.update(status='PASS') for p in r['points']
                                                         if p['id'] == 'AK-CLARA-05-historical'], 'never PASS'),
        ('(f) report: a point missing', lambda r: r.update(points=[p for p in r['points'] if p['id'] != 'AK-CLARA-04']),
         'AK-CLARA-04: 0 time(s)'),
        ('(f) report: a point WAIVED that may not be', lambda r: [p.update(status='WAIVED') for p in r['points']
                                                               if p['id'] == 'DoD-CLARA-01'], 'only'),
        ('(f) report: not JSON', None, 'not readable'),
    ]
    results = []
    tmp = tempfile.mkdtemp()
    try:
        pin_r = os.path.join(tmp, 'engine-pins.env')
        pin_p = os.path.join(tmp, 'engine-pins-previous.env')
        open(pin_r, 'w').write(''.join(f'{k}={v}\n' for k, v in PIN_RELEASE.items()))
        open(pin_p, 'w').write(''.join(f'{k}={v}\n' for k, v in PIN_PREVIOUS.items()))
        for n, (label, injection, change, failing, phrase) in enumerate(cases):
            root = os.path.join(tmp, f'case{n}')
            a = fixture(os.path.join(root, 'after'), A, PROFILES['current']['after'])
            b = fixture(os.path.join(root, 'before'), B, PROFILES['current']['before'])
            args = {'pins': True, 'profile': 'current'}
            change(a, b, args)
            cmd = [sys.executable, me, '--after', a, '--before', b, '--out', os.path.join(root, 'out'),
                   '--commit', REF_SHA, '--profile', args['profile']]
            if args['pins']:
                cmd += ['--pins-release', pin_r, '--pins-previous', pin_p]
            run = subprocess.run(cmd, capture_output=True, text=True)
            report = load(os.path.join(root, 'out', 'clara-bench.json'), {}) or {}
            got = sorted(p['id'] for p in report.get('points') or [] if p['status'] == 'FAIL')
            text = json.dumps(report.get('defects') or [])
            if failing:
                ok = run.returncode != 0 and set(failing) <= set(got) and (phrase is None or phrase in text)
            else:
                ok = run.returncode == 0 and not got and not verify_report(os.path.join(root, 'out'))
            first = (report.get('defects') or [{}])[0]
            results.append({'case': label, 'injection': injection, 'rc': run.returncode, 'failing': got,
                            'expected': failing, 'named': Defects().line(first) if first else '-', 'ok': ok,
                            'command': ' '.join(os.path.relpath(c, tmp) if c.startswith(tmp) else
                                                os.path.basename(c) if c in (me, sys.executable) else c for c in cmd)})
            if not ok:
                results[-1]['stderr'] = run.stderr[-600:]
        good = os.path.join(tmp, 'case0', 'out')
        for label, change, phrase in report_cases:
            out = os.path.join(tmp, 'report-' + str(len(results)))
            shutil.copytree(good, out)
            if change is None:
                open(os.path.join(out, 'clara-bench.json'), 'w').write('{"points": [')
            else:
                edit_json(os.path.join(out, 'clara-bench.json'), change)
            run = subprocess.run([sys.executable, me, '--verify-report', out], capture_output=True, text=True)
            ok = run.returncode != 0 and phrase in run.stdout
            results.append({'case': label, 'injection': 'clara-bench.json changed after it was written',
                            'rc': run.returncode, 'failing': ['report'], 'expected': ['report'],
                            'named': (run.stdout.strip().splitlines() or ['-'])[0], 'ok': ok,
                            'command': 'clara_bench.py --verify-report ' + os.path.basename(out)})
    finally:
        shutil.rmtree(tmp)
    bad = 0
    for r in results:
        bad += not r['ok']
        print(('ok   ' if r['ok'] else 'FAIL ') + f"{r['case']}: rc {r['rc']}, failing {r['failing']}"
              + (f" (expected at least {r['expected']})" if not r['ok'] else '') + (f"\n     {r.get('stderr')}" if r.get('stderr') else ''))
    print(f'{len(results) - bad} of {len(results)} cases as expected.')
    if protocol:
        md = ['# clara_bench.py — negative matrix (#170 DoD 1)', '',
              f"Run {datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')} with {os.path.relpath(me)}; every case "
              'is a complete, consistent data set (both legs) with one defect injected, judged through the command '
              'line. Expected: exit 0 for the positive control and nonzero for every other case, the named points '
              'FAIL, the defect named by leg / cell / file / figure.', '',
              '| # | case | injected | exit | failing points | named | as expected |', '|---|---|---|---|---|---|---|']
        for i, r in enumerate(results):
            md.append(f"| {i} | {r['case']} | {r['injection']} | {r['rc']} | {', '.join(r['failing']) or '-'} | "
                      f"{r['named'].replace('|', '/')} | {'yes' if r['ok'] else '**no**'} |")
        md += ['', f'{len(results) - bad} of {len(results)} cases as expected.', '',
               'Command of each case: `clara_bench.py --after caseN/after --before caseN/before --out caseN/out '
               '--commit <sha> --profile <profile> --pins-release engine-pins.env --pins-previous '
               'engine-pins-previous.env` (without the pins where the case says so).', '']
        os.makedirs(os.path.dirname(os.path.abspath(protocol)), exist_ok=True)
        open(protocol, 'w').write('\n'.join(md))
    return 0 if bad == 0 else 1


def main(argv):
    if argv[1:2] == ['--self-test']:
        args = dict(zip(argv[2::2], argv[3::2]))
        return self_test(args.get('--protocol'))
    if argv[1:2] == ['--verify-report']:
        if len(argv) < 3:
            print(__doc__)
            return 2
        problems = verify_report(argv[2])
        for p in problems:
            print(p)
        print('report verified: every point there, the waiver WAIVED, nothing FAIL' if not problems
              else f'{len(problems)} problem(s) with the report')
        return 1 if problems else 0
    args = dict(zip(argv[1::2], argv[2::2]))
    if '--after' not in args or '--out' not in args:
        print(__doc__)
        return 2
    profile = args.get('--profile', 'current')
    if profile not in PROFILES:
        print(f'unknown profile {profile!r}: {sorted(PROFILES)}')
        return 2
    defects = Defects()
    after = leg(args['--after'], 'after', defects, PROFILES[profile]['after'])
    before = leg(args.get('--before'), 'before', defects, PROFILES[profile]['before'])
    comparability = provenance(after, before, defects, args.get('--commit'),
                               {'release': args.get('--pins-release'), 'previous': args.get('--pins-previous')}, profile)
    points = write(args['--out'], after, before, defects, comparability, profile)
    for p in points:
        print(f"{p['status']:7} {p['id']}: {p['title']}")
    for d in defects.items:
        print('defect: ' + defects.line(d))
    print(open(os.path.join(args['--out'], 'clara-bench.md')).read().split('## Root cause', 1)[-1])
    return 1 if any(p['status'] == 'FAIL' for p in points) else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
