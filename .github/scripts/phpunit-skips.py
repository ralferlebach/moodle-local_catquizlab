#!/usr/bin/env python3
"""Every skipped or incomplete PHPUnit test of a gate job, checked (re-audit 18.11.2026, gate 3: "Skips explizit geprüft").

Reads the --testdox log of one PHPUnit run (moodle-plugin-ci phpunit --testdox), takes its "Summary of non-successful
tests", and compares every skipped (↩) and incomplete (∅) test with .github/phpunit-allowed-skips.txt. A test that
skipped and is not listed there fails the job; so does every incomplete test. Writes what it found as JSON, so that the
artefact says which tests did not run and why that is allowed.

    python3 phpunit-skips.py phpunit.log .github/phpunit-allowed-skips.txt phpunit-skips.json "job name"

The allow list: one line per test, "class | testdox sentence | why it may skip in this job"; '#' starts a comment.
A sentence ending in '*' matches every data set of that test.
"""
import json
import re
import sys


def parse(log):
    """The skipped and incomplete tests of the summary, as (class, sentence, kind)."""
    # Without colour codes, should the runner have printed them.
    lines = re.sub(r'\x1b\[[0-9;]*m', '', open(log, encoding='utf-8', errors='replace').read()).splitlines()
    try:
        start = max(i for i, l in enumerate(lines) if l.strip() == 'Summary of non-successful tests:')
    except ValueError:
        return [], lines
    found = []
    current = ''
    for line in lines[start + 1:]:
        if re.match(r'^(OK|FAILURES!|ERRORS!|Tests: )', line):
            break
        header = re.match(r'^(\S+) \(([^)]+)\)\s*$', line)
        if header:
            current = header.group(1)
            continue
        item = re.match(r'^\s*(↩|∅)\s+(.*\S)\s*$', line)
        if item and current:
            found.append((current, item.group(2), 'skipped' if item.group(1) == '↩' else 'incomplete'))
    return found, lines


def allowed(path):
    out = []
    for raw in open(path, encoding='utf-8'):
        line = raw.split('#', 1)[0].strip()
        if not line:
            continue
        parts = [p.strip() for p in line.split('|')]
        if len(parts) != 3 or not parts[2]:
            raise SystemExit(f'{path}: "class | sentence | reason" expected, got: {raw.strip()}')
        out.append(parts)
    return out


def main():
    log, allowpath, report = sys.argv[1:4]
    job = sys.argv[4] if len(sys.argv) > 4 else ''
    found, lines = parse(log)
    totals = next((l for l in reversed(lines) if l.startswith('Tests: ') or l.startswith('OK (')), '')
    allow = allowed(allowpath)
    rows = []
    for cls, sentence, kind in found:
        reason = None
        if kind == 'skipped':
            for acls, asentence, areason in allow:
                if acls == cls and (sentence == asentence or (asentence.endswith('*')
                                                               and sentence.startswith(asentence[:-1]))):
                    reason = areason
                    break
        rows.append({'class': cls, 'test': sentence, 'kind': kind, 'allowed': reason is not None, 'reason': reason})
    bad = [r for r in rows if not r['allowed']]
    json.dump({'job': job, 'totals': totals, 'nonsuccessful': rows, 'unexpected': bad}, open(report, 'w'), indent=2,
              ensure_ascii=False)
    print(f'{job}: {totals}')
    for r in rows:
        print(('ok    ' if r['allowed'] else 'FAIL  ') + f"{r['kind']}: {r['class']} :: {r['test']}"
              + (f" — {r['reason']}" if r['reason'] else ''))
    if not totals:
        raise SystemExit('No PHPUnit totals in the log: did PHPUnit run?')
    if bad:
        raise SystemExit(f'{len(bad)} test(s) did not run and are not allowed to skip here (see above).')
    print(f'{len(rows)} test(s) did not run, each one allowed with its reason.')


if __name__ == '__main__':
    main()
