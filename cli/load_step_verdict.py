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

"""The verdict of one step of the load matrix (#110, re-audit 19.11.2026), from what the step left behind.

Before 0.7.72 a step's result was smoke.php's exit code: "control" failed in every step with every sitting collected
(each sitting invalid by the step's own design, so "no observations"), and "allsubs" at 50 workers failed with every
sitting collected and valid (eight of fifty ended 35 s after the time limit, on a machine at 100 % CPU). Neither said
which of the possible causes it was. Now each step is one of:

    PASS      every sitting collected within the time limit, at least one valid, no 5xx, no fatal;
    CAPACITY  every sitting collected and at least one valid, no 5xx, no fatal, none failed, none stalled at the limit,
              none held back, none still in play after it — but some ended after the limit, each still answering when
              it was reached: the machine was full, nothing broke. At 1 or 5 workers this is no load at all: FAIL;
              or (0.7.73, CI d23b39c0) overload answered as designed: the load guard tripped and asked workers to stop,
              every failed execution failed for an overload cause (timeouts, connections) and none for another, no 5xx,
              no fatal, a valid sitting — with the sittings still in play and the ones that failed counted and said;
    FAIL      everything else, with its reasons: no summary or request report, a 5xx, a fatal, no request, a failed,
              stalled, held back or unfinished sitting, no valid sitting, a check of smoke.php that failed.

Invalid sittings are counted and said (validity is the engine's, as the dashboard shows it), and are no failure as
long as one is valid: an extreme person's sitting may end invalid by design.

    load_step_verdict.py SMOKE.json REQUESTS.json SMOKE_EXIT MODE WORKERS   prints the verdict; reasons to stderr
    load_step_verdict.py --safe-load matrix.jsonl                           the safe load per mode
    load_step_verdict.py --self-test                                        every rule against a made-up step
"""

import json
import sys

# A step this small is no load: running out of time here is a defect, never capacity.
NO_LOAD = 5


def load(path):
    """A JSON object from a file, or None when it is missing, unreadable or not an object."""
    try:
        with open(path) as f:
            value = json.load(f)
    except (OSError, ValueError):
        return None
    return value if isinstance(value, dict) else None


def verdict(smoke, requests, smoke_exit, workers):
    """(result, reasons) of one step."""
    reasons = []
    if smoke is None:
        return 'FAIL', ['smoke.php left no summary: it did not get to define the experiment, or crashed']
    if requests is None:
        return 'FAIL', ['no request report for the step']
    h5 = int(requests.get('http5xx') or 0)
    fatals = int(requests.get('fatals') or 0)
    nreq = int(requests.get('requests') or 0)
    if nreq <= 0:
        reasons.append('the server traced no request in the step')
    if h5:
        reasons.append(f'{h5} HTTP 5xx')
    if fatals:
        reasons.append(f'{fatals} PHP fatal(s)')
    validity = smoke.get('validity') or {}
    valid = int(validity.get('valid') or 0)
    others = {k: v for k, v in validity.items() if k != 'valid'}
    queued = int(smoke.get('queued') or 0)
    said = smoke.get('verdict')
    limit = smoke.get('atlimit') or {}
    after = smoke.get('afterlimit') or {}
    if said == 'pass' and smoke_exit == 0:
        if valid < 1:
            reasons.append('no valid sitting')
        if reasons:
            return 'FAIL', reasons
        return 'PASS', [f'{queued} sitting(s) collected in the time limit, {valid} valid'
                        + (f', {json.dumps(others)} as the engine judged them' if others else '')]
    if said == 'deadline':
        late = int(limit.get('notfinished') or 0)
        stalled = int(limit.get('stalled') or 0)
        failed = int(after.get('failed') or 0) + int(limit.get('failed') or 0)
        collected = int(after.get('collected') or 0)
        held = int(after.get('held') or 0)
        inplay = int(after.get('inplay') or 0)
        if stalled:
            reasons.append(f'{stalled} sitting(s) stalled at the limit (no answer for '
                           f'{smoke.get("stallseconds")} s or none at all)')
        if failed:
            reasons.append(f'{failed} sitting(s) failed')
        if held:
            reasons.append(f'{held} queued sitting(s) never started')
        if inplay:
            reasons.append(f'{inplay} sitting(s) still in play after the limit')
        if collected != queued:
            reasons.append(f'{collected} of {queued} sittings collected after the limit')
        if valid < 1:
            reasons.append('no valid sitting')
        if workers <= NO_LOAD:
            reasons.append(f'out of time at {workers} worker(s): no load at all')
        if reasons:
            return 'FAIL', reasons
        idle = [s.get('idleseconds') for s in limit.get('sittings') or [] if s.get('idleseconds') is not None]
        return 'CAPACITY', [f'{late} of {queued} sitting(s) still answering at the limit (last answer at most '
                            f'{max(idle) if idle else "?"} s before it), all {collected} collected '
                            f'{after.get("seconds", "?")} s later, {valid} valid'
                            + (f', {json.dumps(others)}' if others else '') + '; no 5xx, no fatal, none failed']
    if said in ('overload', 'navigation'):
        return overload(smoke, said, reasons, valid, others, workers)
    reasons.append(f'smoke.php ended with exit {smoke_exit} and verdict "{said}"'
                   + (': every sitting ended invalid' if said == 'noobservations' else ''))
    return 'FAIL', reasons


def overload(smoke, said, reasons, valid, others, workers):
    """A step whose executions failed on navigation or transport, or still moving after the limit (CI d23b39c0).

    CAPACITY only as the controlled answer to overload (#110 AK 3: "Überlast statt 500er kontrolliert verhindern"):
    the load guard tripped in the step and asked workers to stop; every failed execution failed for a cause that says
    the server or the network was overloaded (timeouts, connections), none for any other (a state mismatch, an error
    page, an engine exception, an unknown cause); no 5xx, no fatal (checked before); a valid sitting among those
    collected; more than NO_LOAD workers. "navigation" means every check of the collected sittings passed before it was
    said; "overload" that sittings were still in play after the limit and the wait, so none was compared — said.
    """
    executions = smoke.get('executions') or {}
    guard = smoke.get('loadguard') or {}
    causes = executions.get('reasons') if isinstance(executions.get('reasons'), dict) else {}
    other = {code: c.get('n') for code, c in causes.items() if not (isinstance(c, dict) and c.get('capacity'))}
    if not executions:
        reasons.append('no record of the failed executions in the summary')
    if other:
        reasons.append(f'executions failed for causes other than overload: {json.dumps(other)}')
    if int(guard.get('trips') or 0) < 1:
        reasons.append('the load guard did not trip: failures without the overload the guard answers')
    if valid < 1:
        reasons.append('no valid sitting')
    if workers <= NO_LOAD:
        reasons.append(f'navigation or transport failures at {workers} worker(s): no load at all')
    if reasons:
        reasons.append(f'smoke.php verdict "{said}"')
        return 'FAIL', reasons
    after = smoke.get('afterlimit') or {}
    queued = int(smoke.get('queued') or 0)
    capacity = {code: c.get('n') for code, c in causes.items()}
    line = (f'overload, controlled: the load guard tripped {guard.get("trips")} time(s) and asked '
            f'{guard.get("drained", "?")} worker(s) to stop (limit {guard.get("limit", "?")}); '
            f'{executions.get("failed", 0)} of {executions.get("total", "?")} executions failed, all for overload '
            f'({json.dumps(capacity)}); {after.get("collected", "?")} of {queued} sittings collected, '
            f'{after.get("failed", 0)} failed, {after.get("inplay", 0)} still in play; {valid} valid'
            + (f', {json.dumps(others)}' if others else '') + '; no 5xx, no fatal')
    if said == 'overload':
        line += '; the sittings were not compared: the run was still moving'
    else:
        line += '; every check of the collected sittings passed'
    return 'CAPACITY', [line]


def safe_load(matrix):
    """Per mode: the highest worker count up to which every step passed, and the steps beyond it."""
    rows = []
    try:
        for line in open(matrix):
            try:
                rows.append(json.loads(line))
            except ValueError:
                pass
    except OSError:
        pass
    out = []
    for mode in sorted({r.get('mode') for r in rows}):
        steps = sorted((r for r in rows if r.get('mode') == mode and r.get('kind') == 'load'),
                       key=lambda r: r.get('workers') or 0)
        safe = 0
        for r in steps:
            if r.get('result') != 'PASS':
                break
            safe = r.get('workers') or 0
        beyond = ', '.join(f"{r['workers']}: {r.get('result')}" for r in steps if (r.get('workers') or 0) > safe)
        out.append(f'{mode}: every step passed up to {safe} worker(s)' + (f'; beyond: {beyond}' if beyond else ''))
    return out


def self_test():
    """Every rule against a made-up step: what must pass passes, what must fail fails, capacity is capacity."""
    req = {'requests': 100, 'http5xx': 0, 'fatals': 0}
    good = {'experimentid': 1, 'queued': 5, 'verdict': 'pass', 'validity': {'valid': 5}}
    over = {'experimentid': 1, 'queued': 50, 'verdict': 'overload', 'validity': {'valid': 20},
            'executions': {'total': 68, 'failed': 11, 'navigation': 10,
                           'reasons': {'submit_cdp_timeout': {'n': 10, 'capacity': True},
                                       'worker_timeout': {'n': 1, 'capacity': True}}},
            'loadguard': {'trips': 1, 'first': 1, 'drained': 25, 'limit': 25},
            'afterlimit': {'collected': 20, 'failed': 3, 'held': 0, 'inplay': 27, 'seconds': 241}}
    late = {'experimentid': 1, 'queued': 50, 'verdict': 'deadline', 'validity': {'valid': 50}, 'stallseconds': 300,
            'atlimit': {'collected': 42, 'failed': 0, 'notfinished': 8, 'stalled': 0,
                        'sittings': [{'sitting': 1, 'answered': 101, 'idleseconds': 9}]},
            'afterlimit': {'collected': 50, 'failed': 0, 'held': 0, 'inplay': 0, 'seconds': 35}}
    cases = [
        ('pass', good, req, 0, 25, 'PASS'),
        ('pass with one invalid', dict(good, validity={'valid': 4, 'invalid': 1}), req, 0, 25, 'PASS'),
        ('no summary', None, req, 1, 25, 'FAIL'),
        ('no request report', good, None, 0, 25, 'FAIL'),
        ('5xx', good, dict(req, http5xx=1), 0, 25, 'FAIL'),
        ('fatal', good, dict(req, fatals=1), 0, 25, 'FAIL'),
        ('no request', good, dict(req, requests=0), 0, 25, 'FAIL'),
        ('pass but none valid', dict(good, validity={'invalid': 5}), req, 0, 25, 'FAIL'),
        ('every sitting invalid', dict(good, verdict='noobservations', validity={'invalid': 5}), req, 1, 25, 'FAIL'),
        ('a check failed', dict(good, verdict='error'), req, 1, 25, 'FAIL'),
        ('pass verdict, nonzero exit', good, req, 1, 25, 'FAIL'),
        ('capacity', late, req, 1, 50, 'CAPACITY'),
        ('capacity at 5 workers', late, req, 1, 5, 'FAIL'),
        ('stalled', dict(late, atlimit=dict(late['atlimit'], stalled=1)), req, 1, 50, 'FAIL'),
        ('failed after the limit', dict(late, afterlimit=dict(late['afterlimit'], failed=1, collected=49)), req, 1, 50,
         'FAIL'),
        ('held back', dict(late, afterlimit=dict(late['afterlimit'], held=2, collected=48)), req, 1, 50, 'FAIL'),
        ('still in play', dict(late, afterlimit=dict(late['afterlimit'], inplay=1, collected=49)), req, 1, 50, 'FAIL'),
        ('late and 5xx', late, dict(req, http5xx=3), 1, 50, 'FAIL'),
        ('late, none valid', dict(late, validity={'invalid': 50}), req, 1, 50, 'FAIL'),
        ('overload, controlled', over, req, 1, 50, 'CAPACITY'),
        ('navigation failures, checks passed', dict(over, verdict='navigation'), req, 1, 50, 'CAPACITY'),
        ('overload, a state mismatch among the causes', dict(over, executions=dict(over['executions'], reasons=dict(
            over['executions']['reasons'], submit_state_mismatch={'n': 9, 'capacity': False}))), req, 1, 50, 'FAIL'),
        ('overload, an unknown cause', dict(over, executions=dict(over['executions'], reasons={
            'unknown_failure': {'n': 1, 'capacity': False}})), req, 1, 50, 'FAIL'),
        ('overload, the guard never tripped', dict(over, loadguard={'trips': 0}), req, 1, 50, 'FAIL'),
        ('overload, no record of the executions', {k: v for k, v in over.items() if k != 'executions'}, req, 1, 50,
         'FAIL'),
        ('overload and 5xx', over, dict(req, http5xx=2), 1, 50, 'FAIL'),
        ('overload, none valid', dict(over, validity={'invalid': 20}), req, 1, 50, 'FAIL'),
        ('overload at 5 workers', over, req, 1, 5, 'FAIL'),
    ]
    bad = 0
    for label, smoke, requests, code, workers, expected in cases:
        got, why = verdict(smoke, requests, code, workers)
        ok = got == expected
        bad += not ok
        print(('ok   ' if ok else 'FAIL ') + f'{label}: {got} (expected {expected}) — {"; ".join(why)}')
    matrix = [{'mode': 'm', 'kind': 'load', 'workers': w, 'result': r}
              for w, r in [(1, 'PASS'), (5, 'PASS'), (25, 'PASS'), (50, 'CAPACITY')]]
    import os
    import tempfile
    with tempfile.NamedTemporaryFile('w', suffix='.jsonl', delete=False) as f:
        f.write('\n'.join(json.dumps(r) for r in matrix) + '\n')
    lines = safe_load(f.name)
    os.unlink(f.name)
    ok = lines == ['m: every step passed up to 25 worker(s); beyond: 50: CAPACITY']
    bad += not ok
    print(('ok   ' if ok else 'FAIL ') + f'safe load: {lines}')
    print(f'{len(cases) + 1 - bad} of {len(cases) + 1} cases as expected.')
    return 0 if bad == 0 else 1


def main(argv):
    if argv[1:2] == ['--self-test']:
        return self_test()
    if argv[1:2] == ['--safe-load']:
        for line in safe_load(argv[2]):
            print(line)
        return 0
    smoke, requests = load(argv[1]), load(argv[2])
    result, reasons = verdict(smoke, requests, int(argv[3]), int(argv[5]))
    print(result)
    for reason in reasons:
        print(f'{result}: {reason}', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
