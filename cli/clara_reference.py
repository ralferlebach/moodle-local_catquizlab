#!/usr/bin/env python3
"""The reference run of the CLARA-like benchmark, replayed from its original artifacts (#170 DoD 2, 0.7.82).

Acceptance run 45038234242 on e605b2e7 (0.7.78, 22.11.2026) is the reference: its legs before and after and its
report, the original GitHub artifact ZIPs, byte for byte, in tests/fixtures/clara-reference-e605b2e7/, with the engine
pins of that commit. This script

    1. checks every fixture file against the SHA-256 written HERE (not in a file beside them: changing the data then
       means changing this script, in plain sight);
    2. unpacks the two legs and the report into a temporary directory (no absolute path, no "..");
    3. checks host.json of both legs: the reference commit and run;
    4. judges the legs with the current checker, clara_bench.py, unchanged, with profile 0.7.78 — the matrix of that
       run (no resume step yet), which clara_bench.py accepts for this one commit only — the commit e605b2e7 and the
       pins of that commit: expected exit 0;
    5. reads the new report back (clara_bench.py --verify-report): expected exit 0;
    6. compares it with the original report point by point: every point of the original the same status in the
       replay, the historical runs #87/#88 WAIVED in both (never PASS), GATE-170 PASS.

The before leg shows the incident (its steps FAIL) and is judged for completeness only, as clara_bench.py does. Any
missing file, wrong digest, unreadable archive, defect, FAIL or status other than the original's ends nonzero. The
protocol — the manifest, each command without secrets, expected and actual exit, each point — goes to --out.

    clara_reference.py --fixture DIR --out DIR
    clara_reference.py --self-test --fixture DIR --out DIR
        the negative control: copies of the fixture with a ZIP missing, a ZIP changed, a file removed inside a leg,
        a pin changed, the original report's waiver turned PASS, the wrong commit — each must end nonzero; and the
        mechanics once more on a synthetic reference (positive and negative), which needs no fixture
"""

import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
CHECKER = os.path.join(HERE, 'clara_bench.py')

# The reference, as the re-audit of 25.11.2026 (section 3) recorded it: run, commit, artifact ids and digests.
REFERENCE = {
    'run': '45038234242',
    'commit': 'e605b2e78797efae7aed2e190efea5b10b0a3ac5',
    'profile': '0.7.78',
    'after': 'catquizlab-acceptance-clara-bench-after.zip',
    'before': 'catquizlab-acceptance-clara-bench-before.zip',
    'report': 'catquizlab-acceptance-clara-report.zip',
    'pins_release': 'pins/engine-pins.env',
    'pins_previous': 'pins/engine-pins-previous.env',
    'files': {
        'catquizlab-acceptance-clara-bench-after.zip': {
            'sha256': '1c7a76ad9db019427869de6a978b352cceb9870a98f746368888386813803353', 'artifact': 14761403605},
        'catquizlab-acceptance-clara-bench-before.zip': {
            'sha256': '80dc76e4578513708b2d7867be7877b2e361243267ae730bb715e5750cf39dcd', 'artifact': 14763292823},
        'catquizlab-acceptance-clara-report.zip': {
            'sha256': '5787aaca35870633eaff4beb74ff19b63234c39d8c099bfe3c6a4ef70ea88c18', 'artifact': 14762403923},
        # .github/engine-pins.env and engine-pins-previous.env at e605b2e7 (GitHub API, contents?ref=e605b2e7).
        'pins/engine-pins.env': {'sha256': 'b3e2ac22c31da2a5391bda8fcfbf9327bacbb90eb7286cc0206dfe2b2caba09c'},
        'pins/engine-pins-previous.env': {'sha256': '5c45115d71caabf5bb085d9207639bcd681ec3e0e9f8444a80cb3e83c2befe5a'},
    },
}
WAIVED_ID = 'AK-CLARA-05-historical'


def digest(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 16), b''):
            h.update(chunk)
    return h.hexdigest()


def unpack(archive, target):
    """Unpack a ZIP; a member with an absolute path or ".." is refused, not unpacked."""
    with zipfile.ZipFile(archive) as z:
        for name in z.namelist():
            parts = name.replace('\\', '/').split('/')
            if name.startswith('/') or '..' in parts or ':' in parts[0]:
                raise ValueError(f'{os.path.basename(archive)}: member {name!r} outside the archive')
        z.extractall(target)
    return target


def find(root, name):
    for base, _, files in os.walk(root):
        if name in files:
            return os.path.join(base, name)
    return None


def checker_commit():
    """The commit of the checker that ran: the CI's, else the working copy's, else unknown."""
    if os.environ.get('GITHUB_SHA'):
        return os.environ['GITHUB_SHA']
    try:
        # The plugin's own repository only — not one it sits in (a Moodle checkout around local/catquizlab).
        git = lambda *a: subprocess.run(['git', '-C', HERE] + list(a), capture_output=True, text=True,
                                        timeout=10).stdout.strip()
        if os.path.realpath(git('rev-parse', '--show-toplevel') or '/') == os.path.realpath(os.path.dirname(HERE)):
            return git('rev-parse', 'HEAD')
    except (OSError, subprocess.SubprocessError):
        pass
    return 'unknown (not a git working copy of the plugin)'


def replay(fixture, out, reference=REFERENCE):
    """The replay; returns (exit code, protocol lines) and writes reference-replay.json into out: the digests, the
    checker's commit, each exit code, each point of the original and of the replay."""
    record = {'reference': {k: reference[k] for k in ('run', 'commit', 'profile')}, 'checker': checker_commit(),
              'files': {}, 'exits': {}, 'points': {}, 'problems': []}

    def done(rc, lines):
        record['exit'] = rc
        record['result'] = 'PASS' if rc == 0 else 'FAIL'
        os.makedirs(out, exist_ok=True)
        with open(os.path.join(out, 'reference-replay.json'), 'w') as f:
            json.dump(record, f, indent=2)
        return rc, lines

    lines = [f"# Replay of the reference run {reference['run']} (commit {reference['commit']}, profile "
             f"{reference['profile']})", '', f'Fixture: `{fixture}`  ', f'Checker: `{os.path.relpath(CHECKER)}` at '
             f"commit {record['checker']}", '',
             '## 1. Fixture files against the digests in clara_reference.py', '',
             '| file | expected SHA-256 | actual | artifact | |', '|---|---|---|---|---|']
    problems = []
    for name, want in reference['files'].items():
        path = os.path.join(fixture, name)
        got = digest(path) if os.path.isfile(path) else 'missing'
        ok = got == want['sha256']
        problems += [] if ok else [f'{name}: {got}, expected {want["sha256"]}']
        record['files'][name] = {'expected': want['sha256'], 'actual': got}
        lines.append(f"| {name} | `{want['sha256']}` | `{got}` | {want.get('artifact', '-')} | {'ok' if ok else '**no**'} |")
    if problems:
        record['problems'] = problems
        return done(1, lines + ['', 'Stopped: the fixture is not the reference.'] + [f'- {p}' for p in problems])
    work = tempfile.mkdtemp(prefix='clara-reference-')
    try:
        legs = {}
        try:
            for key in ('after', 'before', 'report'):
                legs[key] = unpack(os.path.join(fixture, reference[key]), os.path.join(work, key))
        except (zipfile.BadZipFile, ValueError, OSError) as e:
            record['problems'] = [f'an archive is not readable ({e})']
            return done(1, lines + ['', f'Stopped: an archive is not readable ({e}).'])
        lines += ['', '## 2. Host of both legs', '']
        for key in ('after', 'before'):
            try:
                host = json.load(open(os.path.join(legs[key], 'host.json')))
            except (OSError, ValueError):
                host = {}
            ok = host.get('plugin') == reference['commit'] and str(host.get('run')) == reference['run']
            problems += [] if ok else [f"{key}: host.json says plugin {host.get('plugin')!r}, run {host.get('run')!r}"]
            lines.append(f"- {key}: plugin `{host.get('plugin')}`, run {host.get('run')}, runner {host.get('runner')!r}, "
                         f"{host.get('cpus')} CPU(s) — {'ok' if ok else '**not the reference**'}")
        report = os.path.join(out, 'replay')
        cmd = [sys.executable, CHECKER, '--after', legs['after'], '--before', legs['before'], '--out', report,
               '--commit', reference['commit'], '--profile', reference['profile'],
               '--pins-release', os.path.join(fixture, reference['pins_release']),
               '--pins-previous', os.path.join(fixture, reference['pins_previous'])]
        judged = subprocess.run(cmd, capture_output=True, text=True)
        verified = subprocess.run([sys.executable, CHECKER, '--verify-report', report], capture_output=True, text=True)
        show = lambda c: ' '.join('<after>' if a == legs['after'] else '<before>' if a == legs['before'] else
                                  os.path.relpath(a) if os.path.isabs(a) and a != sys.executable else
                                  'python3' if a == sys.executable else a for a in c)
        lines += ['', '## 3. The current checker on the original data', '',
                  '| command | expected exit | actual exit |', '|---|---|---|',
                  f'| `{show(cmd)}` | 0 | {judged.returncode} |',
                  f"| `python3 {os.path.relpath(CHECKER)} --verify-report {os.path.relpath(report)}` | 0 | "
                  f'{verified.returncode} |']
        record['exits'] = {'clara_bench': {'expected': 0, 'actual': judged.returncode},
                           'verify_report': {'expected': 0, 'actual': verified.returncode}}
        if judged.returncode != 0:
            problems.append(f'clara_bench.py ended {judged.returncode}')
        if verified.returncode != 0:
            problems.append('--verify-report: ' + ' / '.join(verified.stdout.strip().splitlines()[:5]))
        new = json.load(open(os.path.join(report, 'clara-bench.json'))) if os.path.isfile(
            os.path.join(report, 'clara-bench.json')) else {}
        for d in new.get('defects') or []:
            problems.append('defect: ' + ' / '.join(str(d.get(k)) for k in ('leg', 'cell', 'file', 'metric', 'problem')))
        original_path = find(legs['report'], 'clara-bench.json')
        try:
            original = json.load(open(original_path)) if original_path else {}
        except ValueError:
            original = {}
        old = {p.get('id'): p.get('status') for p in original.get('points') or [] if isinstance(p, dict)}
        now = {p.get('id'): p.get('status') for p in new.get('points') or [] if isinstance(p, dict)}
        record['points'] = {k: {'original': old.get(k), 'replay': now.get(k)} for k in sorted(set(old) | set(now))}
        lines += ['', '## 4. The replay against the original report', '',
                  '| point | original (22.11.2026) | replay | |', '|---|---|---|---|']
        if not old:
            problems.append('the original report has no points (clara-bench.json missing or not readable)')
        for key in sorted(set(old) | set(now)):
            if key in old:
                ok = old[key] == now.get(key)
            else:
                ok = key == 'GATE-170' and now.get(key) == 'PASS'
            if key == WAIVED_ID:
                ok = ok and old.get(key) == 'WAIVED' and now.get(key) == 'WAIVED'
            if not ok:
                problems.append(f'{key}: original {old.get(key)!r}, replay {now.get(key)!r}')
            lines.append(f"| {key} | {old.get(key, '(not yet a point)')} | {now.get(key, '-')} | "
                         f"{'ok' if ok else '**no**'} |")
        lines += ['', '## Result', '']
        lines += [f'- {p}' for p in problems] or ['The original data, unchanged, pass the current checker with profile '
                                                  f"{reference['profile']}: every point as in the original report, "
                                                  '#87/#88 WAIVED, GATE-170 PASS.']
        lines += ['', '## The checker\'s output', '', '```', judged.stdout.strip()[-6000:], '```']
        record['problems'] = problems
        return done(1 if problems else 0, lines)
    finally:
        shutil.rmtree(work, ignore_errors=True)


def run(fixture, out, reference=REFERENCE):
    os.makedirs(out, exist_ok=True)
    rc, lines = replay(fixture, out, reference)
    lines.append('')
    lines.append(f'Exit {rc} ({"PASS" if rc == 0 else "FAIL"}).')
    with open(os.path.join(out, 'reference-replay.md'), 'w') as f:
        f.write('\n'.join(lines) + '\n')
    return rc, lines


def rezip(src_dir, archive):
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
        for base, _, files in os.walk(src_dir):
            for name in sorted(files):
                path = os.path.join(base, name)
                z.write(path, os.path.relpath(path, src_dir))


def change_zip(archive, change):
    """Unpack, change, pack again; returns the new digest (for the cases that must reach past the digest check)."""
    tmp = tempfile.mkdtemp()
    try:
        unpack(archive, tmp)
        change(tmp)
        os.remove(archive)
        rezip(tmp, archive)
    finally:
        shutil.rmtree(tmp)
    return digest(archive)


def with_sha(reference, name, sha):
    ref = json.loads(json.dumps(reference))
    ref['files'][name]['sha256'] = sha
    return ref


def negatives(fixture, reference, work):
    """Each case: a copy of the fixture with one thing wrong; the replay must end nonzero. Cases that change a file
    on purpose give the replay the new digest, so that the change reaches the checker, not only the digest check."""
    a, b, r = reference['after'], reference['before'], reference['report']

    def remove(path):
        os.remove(path)

    def flip(path):
        data = bytearray(open(path, 'rb').read())
        data[len(data) // 2] ^= 0xFF
        open(path, 'wb').write(bytes(data))

    def drop_samples(root):
        for base, _, files in os.walk(root):
            for name in files:
                if name.endswith('-samples.jsonl') and 'w12' in name:
                    os.remove(os.path.join(base, name))
                    return
        raise RuntimeError('no 12-worker samples in the after leg')

    def unwaive(root):
        path = find(root, 'clara-bench.json')
        report = json.load(open(path))
        for p in report['points']:
            if p['id'] == WAIVED_ID:
                p['status'] = 'PASS'
        json.dump(report, open(path, 'w'))

    def other_pin(path):
        text = open(path).read()
        key = 'LEGACY_CATQUIZ_SHA='
        i = text.index(key) + len(key)
        open(path, 'w').write(text[:i] + ('0' if text[i] != '0' else '1') + text[i + 1:])

    cases = [
        ('after ZIP missing', lambda f, ref: (remove(os.path.join(f, a)), ref)[1]),
        ('before ZIP changed by one byte', lambda f, ref: (flip(os.path.join(f, b)), ref)[1]),
        ('report ZIP missing', lambda f, ref: (remove(os.path.join(f, r)), ref)[1]),
        ('a file removed inside the after leg (the 12-worker samples), digest updated',
         lambda f, ref: with_sha(ref, a, change_zip(os.path.join(f, a), drop_samples))),
        ('the release pin of local_catquiz changed, digest updated',
         lambda f, ref: (other_pin(os.path.join(f, ref['pins_release'])),
                         with_sha(ref, ref['pins_release'], digest(os.path.join(f, ref['pins_release']))))[1]),
        ('the original report\'s waiver turned PASS, digest updated',
         lambda f, ref: with_sha(ref, r, change_zip(os.path.join(f, r), unwaive))),
        ('replayed for another commit (profile 0.7.78 belongs to e605b2e7 only)',
         lambda f, ref: dict(ref, commit='f' * 40)),
        ('not a ZIP, digest updated',
         lambda f, ref: (open(os.path.join(f, b), 'w').write('not a zip'),
                         with_sha(ref, b, digest(os.path.join(f, b))))[1]),
    ]
    results = []
    for i, (label, change) in enumerate(cases):
        copy = os.path.join(work, f'neg{i}')
        shutil.copytree(fixture, copy)
        try:
            ref = change(copy, reference)
            rc, lines = replay(copy, os.path.join(work, f'neg{i}-out'), ref)
            why = next((l[2:] for l in lines if l.startswith('- ') and ':' in l and 'plugin `' not in l), '') or \
                next((l for l in lines if l.startswith('Stopped')), '')
        except Exception as e:      # a case that cannot even be built is a failed case, not a pass
            rc, why = None, f'case not built: {e}'
        results.append({'case': label, 'rc': rc, 'ok': rc not in (0, None), 'why': why})
    return results


def synthetic(work):
    """A reference built from clara_bench.py's own fixture: the mechanics, without the original data."""
    sys.path.insert(0, HERE)
    sys.dont_write_bytecode = True      # no __pycache__ in the plugin's cli/
    import clara_bench as cb
    root = os.path.join(work, 'synthetic')
    legs = {}
    for leg in ('after', 'before'):
        d = cb.fixture(os.path.join(work, 'legs', leg), leg, cb.PROFILES['0.7.78'][leg])
        cb.edit_json(os.path.join(d, 'host.json'), lambda h: h.update(plugin=REFERENCE['commit'], run=REFERENCE['run']))
        legs[leg] = d
    os.makedirs(os.path.join(root, 'pins'))
    open(os.path.join(root, 'pins', 'engine-pins.env'), 'w').write(
        ''.join(f'{k}={v}\n' for k, v in cb.PIN_RELEASE.items()))
    open(os.path.join(root, 'pins', 'engine-pins-previous.env'), 'w').write(
        ''.join(f'{k}={v}\n' for k, v in cb.PIN_PREVIOUS.items()))
    ref = json.loads(json.dumps(REFERENCE))
    for leg in ('after', 'before'):
        rezip(legs[leg], os.path.join(root, ref[leg]))
    # The "original" report: the checker's own, with GATE-170 taken out (it did not exist then).
    report = os.path.join(work, 'report')
    subprocess.run([sys.executable, CHECKER, '--after', legs['after'], '--before', legs['before'], '--out', report,
                    '--commit', ref['commit'], '--profile', ref['profile'],
                    '--pins-release', os.path.join(root, 'pins', 'engine-pins.env'),
                    '--pins-previous', os.path.join(root, 'pins', 'engine-pins-previous.env')], capture_output=True)
    cb.edit_json(os.path.join(report, 'clara-bench.json'),
                 lambda x: x.update(points=[p for p in x['points'] if p['id'] != 'GATE-170']))
    rezip(report, os.path.join(root, ref['report']))
    for name in ref['files']:
        ref['files'][name]['sha256'] = digest(os.path.join(root, name))
    return root, ref


def self_test(fixture, out):
    os.makedirs(out, exist_ok=True)
    work = tempfile.mkdtemp(prefix='clara-reference-test-')
    rows = []
    try:
        root, ref = synthetic(work)
        rc, _ = replay(root, os.path.join(work, 'syn-out'), ref)
        rows.append({'set': 'synthetic', 'case': 'positive control', 'rc': rc, 'ok': rc == 0, 'why': ''})
        rows += [dict(x, set='synthetic') for x in negatives(root, ref, os.path.join(work, 'syn'))]
        if fixture and all(os.path.isfile(os.path.join(fixture, n)) for n in REFERENCE['files']):
            rows += [dict(x, set='reference') for x in negatives(fixture, REFERENCE, os.path.join(work, 'ref'))]
        else:
            rows.append({'set': 'reference', 'case': 'the reference fixture', 'rc': None, 'ok': False,
                         'why': f'not complete in {fixture!r}: the negative control on the original data cannot run'})
    finally:
        shutil.rmtree(work, ignore_errors=True)
    bad = sum(not x['ok'] for x in rows)
    md = ['# clara_reference.py — negative control (#170 DoD 2)', '',
          'Each case a copy of the fixture with one thing wrong; expected: exit nonzero (the positive control of the '
          'synthetic set: 0).', '', '| set | case | exit | as expected | first reason |', '|---|---|---|---|---|']
    for x in rows:
        md.append(f"| {x['set']} | {x['case']} | {x['rc']} | {'yes' if x['ok'] else '**no**'} | "
                  f"{str(x['why']).replace('|', '/')[:200]} |")
    md += ['', f'{len(rows) - bad} of {len(rows)} cases as expected.']
    open(os.path.join(out, 'reference-negative-control.md'), 'w').write('\n'.join(md) + '\n')
    json.dump({'checker': checker_commit(), 'cases': rows, 'as_expected': len(rows) - bad, 'total': len(rows)},
              open(os.path.join(out, 'reference-negative-control.json'), 'w'), indent=2)
    for x in rows:
        print(('ok   ' if x['ok'] else 'FAIL ') + f"{x['set']}: {x['case']}: exit {x['rc']}  {x['why'][:160]}")
    print(f'{len(rows) - bad} of {len(rows)} cases as expected.')
    return 0 if bad == 0 else 1


def main(argv):
    args = dict(zip(argv[1::2], argv[2::2])) if argv[1:2] != ['--self-test'] else dict(zip(argv[2::2], argv[3::2]))
    if '--out' not in args or '--fixture' not in args:
        print(__doc__)
        return 2
    if argv[1:2] == ['--self-test']:
        return self_test(args['--fixture'], args['--out'])
    rc, lines = run(args['--fixture'], args['--out'])
    print('\n'.join(lines))
    return rc


if __name__ == '__main__':
    sys.exit(main(sys.argv))
