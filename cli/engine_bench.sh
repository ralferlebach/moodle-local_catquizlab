#!/bin/bash
# What one CAT sitting costs the server, by length and pool size, at the production memory limit (#136 DoD 3).
#
# For every pool size (POOLS, items over three subscales) and every length (STEPS, questions per sitting): one
# experiment, played by one worker through the real adaptive quiz — PERSONS sittings of exactly that many questions
# (global minimum = maximum) with STRATEGY. The server traces every request (request_trace); per cell this reports, over
# every request of the window: the request time (n, mean, 95 % interval, p50/p95/p99), the queries per request, PHP's
# peak memory per request and the process memory (RSS), the HTTP errors — and how the answer requests change from the
# first tenth of a sitting to the last (time, queries, peak), which is where a cost that grows with the sitting shows.
#
#     POOLS="100 500 1000" STEPS="10 50 100 150" STRATEGY=fastest MOODLE_ROOT=/var/www/moodle \
#         local/catquizlab/cli/engine_bench.sh
#
# Run once per engine set (previous, release: .github/engine-pins-previous.env and engine-pins.env) on the same kind of
# machine: the two bench.jsonl files are the before and after. Writes everything to $OUT; exit 1 when a sitting was not played to the end, a request ended
# in 5xx or PHP died (GATE=0 reports without failing).
set -u
MOODLE="${MOODLE_ROOT:-/home/claude/moodle}"
POOLS="${POOLS:-100 500 1000}"
STEPS="${STEPS:-10 50 100 150}"
STRATEGY="${STRATEGY:-fastest}"
PERSONS="${PERSONS:-2}"
MINUTES="${MINUTES:-30}"
OUT="${OUT:-/tmp/catquizlab-engine-bench}"
PHP="${PHP:-php}"
STATUS_URL="${STATUS_URL:-}"
GATE="${GATE:-1}"
CLI="$MOODLE/local/catquizlab/cli"
mkdir -p "$OUT"
: > "$OUT/bench.jsonl"
failed=0
printf '%-14s %-6s %-6s %-9s %-9s %-9s %-8s %-8s %-8s %-7s %s\n' cell result reqs ms-p50 ms-p95 queries-p95 peakMB rssMB \
    growth 5xx secs
for pool in $POOLS; do
    for steps in $STEPS; do
        name="pool$pool-steps$steps"
        if [ "$steps" -gt "$pool" ]; then
            echo "$name: skipped, a sitting cannot ask more questions than the pool has" | tee -a "$OUT/skipped.txt"
            continue
        fi
        from=$(date +%s)
        "$PHP" "$CLI/load_sampler.php" --seconds=$(( MINUTES * 60 + 600 )) ${STATUS_URL:+--status-url="$STATUS_URL"} \
            > "$OUT/$name-samples.jsonl" 2>/dev/null &
        sampler=$!
        if timeout $(( MINUTES * 60 + 300 )) "$PHP" "$CLI/smoke.php" --strategy="$STRATEGY" --persons="$PERSONS" \
            --concurrency=1 --minanswers="$steps" --pool="$pool" --budget="$steps,$steps,1,$steps,0.01,1.0" \
            --minutes="$MINUTES" --require-all --no-navigation-failures > "$OUT/$name.log" 2>&1; then
            result=PASS
        else
            result=FAIL
        fi
        until=$(date +%s)
        kill "$sampler" 2>/dev/null; wait "$sampler" 2>/dev/null
        "$PHP" "$CLI/request_report.php" --from="$from" --until="$until" --json --raw="$OUT/$name-requests.csv" \
            > "$OUT/$name-requests.json" 2>/dev/null
        line=$(python3 - "$OUT" "$name" "$pool" "$steps" "$STRATEGY" "$result" "$(( until - from ))" <<'PY'
import csv, json, sys
out, name, pool, steps, strategy, result, secs = sys.argv[1:8]
try:
    r = json.load(open(f'{out}/{name}-requests.json'))
except (OSError, ValueError):
    r = {}
rows = list(csv.DictReader(open(f'{out}/{name}-requests.csv'))) if r else []
# The answer requests of each sitting in order: attempt.php POSTs, by correlation (one sitting, one worker execution).
by = {}
for row in rows:
    if row['page'].endswith('/attempt.php') and row['method'] == 'POST':
        by.setdefault(row['correlationid'], []).append(row)
def tenth(part):
    vals = {'ms': [], 'queries': [], 'peak': []}
    for seq in by.values():
        k = max(1, len(seq) // 10)
        for row in (seq[:k] if part == 'first' else seq[-k:]):
            vals['ms'].append(float(row['durationms']))
            vals['peak'].append(float(row['peakmb']))
            if row['queries'] not in ('', None):
                vals['queries'].append(float(row['queries']))
    return {k: (round(sum(v) / len(v), 1) if v else None) for k, v in vals.items()}
samples = []
for l in open(f'{out}/{name}-samples.jsonl'):
    try:
        samples.append(json.loads(l))
    except ValueError:
        pass
answers = [len(seq) for seq in by.values()]
row = {'cell': name, 'pool': int(pool), 'steps': int(steps), 'strategy': strategy, 'result': result,
       'seconds': int(secs), 'requests': r.get('requests'), 'http5xx': r.get('http5xx'), 'fatals': r.get('fatals'),
       'request_ms': r.get('durationms'), 'queries_per_request': r.get('queries'), 'php_peak_mb': r.get('peakmb'),
       'process_rss_mb': r.get('rssmb'), 'answers_per_sitting': answers, 'first_tenth': tenth('first'),
       'last_tenth': tenth('last'),
       'web_rss_mb_max': max((x.get('webrssmb') or 0 for x in samples), default=None),
       'browser_rss_mb_max': max((x.get('browserrssmb') or 0 for x in samples), default=None)}
open(f'{out}/bench.jsonl', 'a').write(json.dumps(row) + '\n')
d = r.get('durationms') or {}
q = r.get('queries') or {}
f, l = row['first_tenth'], row['last_tenth']
growth = (f"{f['ms']}→{l['ms']}ms" if f['ms'] is not None and l['ms'] is not None else '-')
print(r.get('requests', '-'), d.get('p50', '-'), d.get('p95', '-'), q.get('p95', '-'), (r.get('peakmb') or {}).get('max', '-'),
      (r.get('rssmb') or {}).get('max', '-'), growth, r.get('http5xx', '-'))
PY
)
        read -r reqs p50 p95 q95 peak rss growth h5 <<< "$line"
        printf '%-14s %-6s %-6s %-9s %-9s %-9s %-8s %-8s %-8s %-7s %s\n' "$name" "$result" "$reqs" "$p50" "$p95" "$q95" \
            "$peak" "$rss" "$growth" "$h5" $(( until - from ))
        if [ "$result" != PASS ] || [ "${h5:-0}" != 0 ]; then failed=$(( failed + 1 )); fi
    done
done
echo "Every cell: $OUT/bench.jsonl; every request: $OUT/*-requests.csv."
if [ "$GATE" = 1 ] && [ "$failed" -gt 0 ]; then
    echo "FAIL: $failed cell(s) not played to the end or with an HTTP 5xx."
    exit 1
fi
