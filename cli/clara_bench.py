#!/usr/bin/env python3
"""The CLARA-like benchmark of #110, read and judged (0.7.78; acceptance workflow, job "clara-bench").

Two legs of the same benchmark — HIGHESTSUB, a pool of about 2 500 items, the same seeds, Apache + PHP-FPM at 512M:

    after   the release engine set at 1/5/12/25 workers, a faults step and a query-log step: the gate;
    before  the previous engine set (engine-pins-previous.env) at 1 and 25 workers: the comparison — what the incident
            looked like. Not gated: it may fail, and that is what it shows.

Each leg's directory holds what load_matrix.sh wrote (matrix.jsonl and per step *-smoke.json, *-requests.json,
*-traces.json, *-db.json, *-cdp.json, *.log), and limits.json (server-limits.sh), engine-manifest.txt and host.json
(the plugin's commit, the runner).

Writes clara-bench.json (every figure, machine-readable) and clara-bench.md (the report with the root cause), and
judges the eight points of #110 still open after the re-audit of 22.11.2026:

    db-errors         "Datenbankfehler": every step's database layer without an exception, its counters readable
    web-limits        "PHP-/Webserver-Grenzen dokumentieren": memory_limit, max_execution_time, pm.max_children, the
                      web server's and the gateway's timeouts — each said, from the server itself
    db-limits         "Datenbankseitig prüfen": connection limit, connections at most, locks/deadlocks, failed writes
    AK-CLARA-01       every uncertain submission's life to the millisecond, its side named; the faults step's causes
                      exactly those injected
    AK-CLARA-03       the selector's time apart from the server's database and lock time, the client's time, the
                      trace INSERT (query-log step) and the load guard
    AK-CLARA-04       the load guard and the safety cap, sample by sample, at every step: throttled or not, why
    AK-CLARA-05       every sitting terminal and valid, recovery (faults step) without a second answer; the
                      historical runs #87/#88 of CLARA are not reconstructable here — waived as operator evidence,
                      never reported as tested
    DoD-CLARA-01      before and after on the same benchmark: selector, throughput, error rate, UTC times, the cause

A point is PASS only on data; missing data is FAIL, never a pass. Exit 1 if any point FAILs.

    clara_bench.py --after DIR [--before DIR] --out DIR
    clara_bench.py --self-test
"""

import json
import os
import re
import sys
from datetime import datetime, timezone

WAIVED_HISTORY = ('The historical CLARA runs #87 and #88 (Experiment #19, 15.11.2026) are not reconstructable in CI: '
                  'their database, logs and engine state are the operator\'s. Waived as operator evidence (re-audit '
                  '22.11.2026, section 3 A) — not tested, and not reported as tested. What is tested: the same kind '
                  'of run (HIGHESTSUB, about 2 500 items) before and after, on the same seeds.')


def load(path, default=None):
    try:
        with open(path) as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def utc(ts):
    return datetime.fromtimestamp(int(ts), timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ') if ts else None


def leg(path):
    """Everything of one leg, step by step."""
    if not path or not os.path.isdir(path):
        return None
    load_dir = os.path.join(path, 'load') if os.path.isdir(os.path.join(path, 'load')) else path
    rows = []
    try:
        with open(os.path.join(load_dir, 'matrix.jsonl')) as f:
            rows = [json.loads(l) for l in f if l.strip()]
    except (OSError, ValueError):
        rows = []
    steps = []
    for row in rows:
        name = row.get('step')
        base = os.path.join(load_dir, name)
        traces = load(base + '-traces.json', {}) or {}
        requests = load(base + '-requests.json', {}) or {}
        db = load(base + '-db.json', {}) or {}
        cdp = load(base + '-cdp.json', {}) or {}
        smoke = row.get('smoke') or load(base + '-smoke.json', {}) or {}
        try:
            log = open(base + '.log').read()
        except OSError:
            log = ''
        pool = re.search(r'Pool: (\d+) items', log)
        kinds = traces.get('kinds') or {}
        answers = sum(int((kinds.get(k) or {}).get('n') or 0) for k in ('next_item', 'complete')) or None
        strategy = (traces.get('spans') or {}).get('strategy') or {}
        dec = traces.get('decomposition') or {}
        executions = smoke.get('executions') or {}
        sittings = smoke.get('sittings') or {}
        seconds = int(row.get('seconds') or 0)
        steps.append({
            'step': name, 'kind': row.get('kind'), 'mode': row.get('mode'), 'workers': row.get('workers'),
            'persons': row.get('persons'), 'pool': int(pool.group(1)) if pool else None, 'result': row.get('result'),
            'from': utc(row.get('from')), 'until': utc(row.get('until')), 'seconds': seconds,
            'reasons': row.get('verdict_reasons') or [],
            'sittings': sittings, 'validity': smoke.get('validity') or {},
            'executions': {'total': executions.get('total'), 'failed': executions.get('failed'),
                           'reasons': executions.get('reasons') or {}},
            'answers': answers,
            'answers_per_minute': round(60 * answers / seconds, 1) if answers and seconds else None,
            'sittings_per_minute': round(60 * int(sittings.get('collected') or 0) / seconds, 2) if seconds else None,
            'error_rate': (round(int(executions.get('failed') or 0) / int(executions['total']), 4)
                           if executions.get('total') else None),
            'requests': row.get('requests'), 'http5xx': row.get('http5xx'), 'fatals': row.get('fatals'),
            'exceptions': row.get('exceptions'), 'request_ms': row.get('request_ms'),
            'php_peak_mb': row.get('php_peak_mb'),
            # The memory_limit the web requests ran under, as they reported it (request_report), else as configured.
            'web_memory_limit': requests.get('memorylimit') or row.get('web_memory_limit'),
            'cpu_percent_max': row.get('cpu_percent_max'), 'mem_available_mb_min': row.get('mem_available_mb_min'),
            'apache_busy_max': row.get('apache_busy_max'), 'db_connections_max': row.get('db_connections_max'),
            'selector_ms': {k: strategy.get(k) for k in ('n', 'p50', 'p95', 'p99', 'max')} if strategy else None,
            'engine_kinds': {k: {x: (v or {}).get(x) for x in ('n', 'p50', 'p95')} for k, v in kinds.items()},
            'decomposition': {k: (dec.get(k) or {}).get('status') for k in ('persistence', 'server', 'client')},
            'persistence_ms': ((dec.get('persistence') or {}).get('stats')),
            'server_db_ms': ((dec.get('server') or {}).get('dbms')),
            'server_lockwait_ms': ((dec.get('server') or {}).get('lockwaitms')),
            'client_wait_ms': ((dec.get('client') or {}).get('stats')),
            'db': {k: db.get(k) for k in ('readable', 'deadlocks', 'dmlerrors', 'failedwrites', 'connections', 'delta')},
            'cdp': {k: cdp.get(k) for k in ('counts', 'sides', 'unattributed', 'problems')} if cdp else None,
            'loadguard': {'throttled_samples': row.get('throttled_samples'), 'workers_live_max': row.get('workers_live_max'),
                          'workers_playing_max': row.get('workers_playing_max'),
                          'worker_limit_min': row.get('worker_limit_min'), 'worker_cap': row.get('worker_cap'),
                          'smoke': smoke.get('loadguard')},
        })
    engines = {}
    try:
        for line in open(os.path.join(path, 'engine-manifest.txt')):
            parts = line.split()
            if len(parts) >= 2 and parts[0] in ('local_catquiz', 'mod_adaptivequiz', 'adaptivequizcatmodel_catquiz',
                                                'local_wunderbyte_table', 'engine_set'):
                engines[parts[0]] = ' '.join(parts[1:])
    except OSError:
        pass
    return {'engines': engines, 'host': load(os.path.join(path, 'host.json'), {}),
            'limits': load(os.path.join(path, 'limits.json'), {}), 'steps': steps}


def check(after, before):
    """The eight points, each PASS, FAIL or WAIVED with its evidence."""
    points = []

    def point(key, title, ok, evidence, waived=None):
        status = 'WAIVED' if waived else ('PASS' if ok else 'FAIL')
        points.append({'id': key, 'title': title, 'status': status, 'evidence': evidence,
                       **({'waiver': waived} if waived else {})})

    steps = (after or {}).get('steps') or []
    load_steps = [s for s in steps if s['kind'] == 'load']
    faults = [s for s in steps if s['kind'] == 'faults']
    querylog = [s for s in steps if s['kind'] == 'querylog']
    if not steps:
        for key in ('db-errors', 'web-limits', 'db-limits', 'AK-CLARA-01', 'AK-CLARA-03', 'AK-CLARA-04',
                    'AK-CLARA-05', 'DoD-CLARA-01'):
            point(key, key, False, ['no step of the after leg: nothing measured'])
        return points

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
    maxconn = int(database.get('max_connections') or 0)
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
        if lg.get('workers_live_max') is None:
            problems.append(f"{s['step']}: no worker sample")
            continue
        cap = int(lg.get('worker_cap') or 0)
        if cap and int(lg['workers_live_max']) > cap:
            problems.append(f"{s['step']}: {lg['workers_live_max']} live workers above the safety cap {cap}")
        if int(lg.get('throttled_samples') or 0) > 0 and int(s['workers'] or 0) <= 25:
            problems.append(f"{s['step']}: throttled at {s['workers']} workers ({lg.get('smoke')})")
    point('AK-CLARA-04', 'Drosselung und Safety-Cap unter 1/5/12/25 Workern gemessen', not problems,
          problems or [f"{s['step']}: live at most {s['loadguard']['workers_live_max']}, limit at least "
                       f"{s['loadguard']['worker_limit_min']}, cap {s['loadguard']['worker_cap'] or 'none'}, "
                       f"throttled samples {s['loadguard']['throttled_samples']}" for s in load_steps]
          + ([f"before: {b['step']} throttled samples {b['loadguard']['throttled_samples']}, guard "
              f"{json.dumps(b['loadguard'].get('smoke'))}" for b in (before or {}).get('steps') or []]))

    # AK-CLARA-05.
    bad = [f"{s['step']}: {s['result']} {s['sittings']}" for s in load_steps + faults
           if s['result'] != 'PASS' or s['sittings'].get('collected') != s['sittings'].get('total')
           or s['sittings'].get('queued') or s['sittings'].get('running') or s['sittings'].get('failed')]
    pools = sorted({s['pool'] for s in load_steps if s['pool']})
    if not pools or min(pools) < 2400:
        bad.append(f'pool sizes {pools}: not the benchmark\'s ~2 500 items')
    point('AK-CLARA-05', 'Alle Sittings terminal und auswertbar; Recovery ohne Doppelabgabe', not bad,
          bad or [f"{s['step']}: {s['sittings'].get('collected')}/{s['sittings'].get('total')} collected, validity "
                  f"{json.dumps(s['validity'])}" for s in load_steps + faults] + [f'pool {pools}'])
    points.append({'id': 'AK-CLARA-05-historical', 'title': 'Runs #87/#88 auf CLARA', 'status': 'WAIVED',
                   'evidence': ['not reconstructable here'], 'waiver': WAIVED_HISTORY})

    # DoD-CLARA-01.
    bsteps = (before or {}).get('steps') or []
    ok = bool(bsteps) and any((s.get('selector_ms') or {}).get('n') for s in bsteps) \
        and any((s.get('selector_ms') or {}).get('n') for s in load_steps)
    point('DoD-CLARA-01', 'Vorher/Nachher auf demselben Benchmark (HIGHESTSUB, ~2 500 Items, 1/5/12/25)', ok,
          ([] if bsteps else ['the before leg is missing'])
          + [f"{which} {s['step']} {s['from']}–{s['until']}: selector p50 {(s.get('selector_ms') or {}).get('p50')} ms, "
             f"answers/min {s['answers_per_minute']}, error rate {s['error_rate']}, uncertain submissions "
             f"{json.dumps((s.get('cdp') or {}).get('sides'))}, result {s['result']}"
             for which, group in (('before', bsteps), ('after', load_steps)) for s in group])
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
    return lines


def write(out, after, before):
    points = check(after, before)
    report = {'generated': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'), 'after': after,
              'before': before, 'points': points, 'rootcause': root_cause(after, before)}
    os.makedirs(out, exist_ok=True)
    with open(os.path.join(out, 'clara-bench.json'), 'w') as f:
        json.dump(report, f, indent=2)
    md = ['# CLARA-like benchmark (#110): HIGHESTSUB, about 2 500 items', '']
    for which, data in (('after (release engine set)', after), ('before (previous engine set)', before)):
        if not data:
            md += [f'## {which}: missing', '']
            continue
        md += [f'## {which}', '', f"Engines: {json.dumps(data.get('engines'))}  ", f"Host: {json.dumps(data.get('host'))}", '',
               '| step | pool | result | UTC | sittings | answers/min | error rate | 5xx | selector p50/p95 ms | '
               'request p50/p95 ms | client wait p50 ms | uncertain (server/client/?) | throttled |',
               '|' + '---|' * 13]
        for s in data['steps']:
            sel = s.get('selector_ms') or {}
            req = s.get('request_ms') or {}
            sides = (s.get('cdp') or {}).get('sides') or {}
            md.append(f"| {s['step']} | {s['pool']} | {s['result']} | {s['from']}–{s['until']} | "
                      f"{s['sittings'].get('collected')}/{s['sittings'].get('total')} | {s['answers_per_minute']} | "
                      f"{s['error_rate']} | {s['http5xx']} | {sel.get('p50')}/{sel.get('p95')} | "
                      f"{req.get('p50')}/{req.get('p95')} | {(s.get('client_wait_ms') or {}).get('p50')} | "
                      f"{sides.get('server', 0)}/{sides.get('client', 0)}/{sides.get('unknown', 0)} | "
                      f"{s['loadguard'].get('throttled_samples')} |")
        md.append('')
    md += ['## The eight points', '']
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


def self_test():
    import shutil
    import tempfile
    bad = 0

    def make(root, kind_rows, limits=True, slow=False):
        os.makedirs(os.path.join(root, 'load'), exist_ok=True)
        rows = []
        for name, kind, workers, result in kind_rows:
            rows.append({'step': name, 'kind': kind, 'mode': 'highestsub', 'workers': workers, 'persons': workers * 2,
                         'result': result, 'seconds': 300, 'from': 1795352394, 'until': 1795352694,
                         'http5xx': 0, 'db_connections_max': 12, 'web_memory_limit': '512M',
                         'workers_live_max': workers, 'workers_playing_max': workers, 'worker_limit_min': 25,
                         'worker_cap': 25, 'throttled_samples': 0,
                         'smoke': {'sittings': {'total': workers * 2, 'collected': workers * 2, 'queued': 0,
                                                'running': 0, 'failed': 0},
                                   'executions': {'total': workers * 2, 'failed': 0}, 'validity': {'valid': workers * 2}}})
            base = os.path.join(root, 'load', name)
            json.dump({'kinds': {'next_item': {'n': 100, 'p50': 300}}, 'spans': {'strategy': {
                'n': 100, 'p50': 60000 if slow else 250, 'p95': 65000 if slow else 400}},
                'decomposition': {'server': {'status': 'measured'}, 'client': {'status': 'measured'},
                                  'persistence': {'status': 'measured' if kind == 'querylog' else 'not_measured',
                                                  'stats': {'p50': 0.8, 'p95': 2.5}}}}, open(base + '-traces.json', 'w'))
            json.dump({'readable': True, 'deadlocks': 0, 'dmlerrors': {}, 'failedwrites': 0}, open(base + '-db.json', 'w'))
            json.dump({'counts': {}, 'sides': {'server': 5 if slow else 0, 'client': 0}, 'unattributed': 0,
                       'problems': []}, open(base + '-cdp.json', 'w'))
            open(base + '.log', 'w').write('  Pool: 2502 items over 3 subscales (--pool).\n')
        with open(os.path.join(root, 'load', 'matrix.jsonl'), 'w') as f:
            f.write('\n'.join(json.dumps(r) for r in rows) + '\n')
        if limits:
            json.dump({'php_web': {'memory_limit': '512M', 'max_execution_time': '300', 'sapi': 'fpm-fcgi'},
                       'php_fpm_pool': {'pm': 'static', 'pm.max_children': '64', 'request_terminate_timeout': '300'},
                       'apache': {'Timeout': '300', 'ProxyTimeout': 'not set (default Timeout)',
                                  'KeepAliveTimeout': '5', 'MaxRequestWorkers': '400'},
                       'gateway': 'none', 'database': {'max_connections': '100'}}, open(os.path.join(root, 'limits.json'), 'w'))
        return root

    full = [('highestsub-w1', 'load', 1, 'PASS'), ('highestsub-w5', 'load', 5, 'PASS'),
            ('highestsub-w12', 'load', 12, 'PASS'), ('highestsub-w25', 'load', 25, 'PASS'),
            ('highestsub-w5-faults', 'faults', 5, 'PASS'), ('highestsub-w5-querylog', 'querylog', 5, 'PASS')]
    before_rows = [('highestsub-w1', 'load', 1, 'FAIL'), ('highestsub-w25', 'load', 25, 'FAIL')]
    cases = [
        ('all eight on data', full, True, True, []),
        ('no before leg', full, True, False, ['DoD-CLARA-01']),
        ('no limits', full, False, True, ['web-limits', 'db-limits']),
        ('no faults step', [r for r in full if r[1] != 'faults'], True, True, ['AK-CLARA-01']),
        ('no query-log step', [r for r in full if r[1] != 'querylog'], True, True, ['AK-CLARA-03']),
        ('a step failed', [('highestsub-w25', 'load', 25, 'FAIL') if r[0] == 'highestsub-w25' else r for r in full],
         True, True, ['AK-CLARA-05']),
        ('nothing measured', [], True, True, ['db-errors', 'web-limits', 'db-limits', 'AK-CLARA-01', 'AK-CLARA-03',
                                              'AK-CLARA-04', 'AK-CLARA-05', 'DoD-CLARA-01']),
    ]
    for label, rows, limits, with_before, failing in cases:
        tmp = tempfile.mkdtemp()
        try:
            after = leg(make(os.path.join(tmp, 'a'), rows, limits))
            before = leg(make(os.path.join(tmp, 'b'), before_rows, True, slow=True)) if with_before else None
            points = write(os.path.join(tmp, 'out'), after, before)
            got = sorted(p['id'] for p in points if p['status'] == 'FAIL')
            ok = got == sorted(failing)
            if label == 'all eight on data':
                text = open(os.path.join(tmp, 'out', 'clara-bench.md')).read()
                ok = ok and 'Cause, as measured' in text and 'WAIVED' in text
        finally:
            shutil.rmtree(tmp)
        bad += not ok
        print(('ok   ' if ok else 'FAIL ') + f'{label}: failing {got} (expected {sorted(failing)})')
    print(f'{len(cases) - bad} of {len(cases)} cases as expected.')
    return 0 if bad == 0 else 1


def main(argv):
    if argv[1:2] == ['--self-test']:
        return self_test()
    args = dict(zip(argv[1::2], argv[2::2]))
    if '--after' not in args or '--out' not in args:
        print(__doc__)
        return 2
    after, before = leg(args['--after']), leg(args.get('--before'))
    points = write(args['--out'], after, before)
    for p in points:
        print(f"{p['status']:7} {p['id']}: {p['title']}")
    print(open(os.path.join(args['--out'], 'clara-bench.md')).read().split('## Root cause', 1)[-1])
    return 1 if any(p['status'] == 'FAIL' for p in points) else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
