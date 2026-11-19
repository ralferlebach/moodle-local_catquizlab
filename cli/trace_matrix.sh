#!/bin/bash
# What the engine's request tracing and the lab's debug log cost under load, measured apart (#164): a 2 × 2 matrix,
# local_catquiz/attempttrace off/on × local_catquizlab/debuglevel off/trace, each cell the same real sittings —
# same strategies (FASTEST and HIGHESTSUB by default), same persons (the smoke experiment's fixed seed), same worker
# limit, same server — played by the worker through the real adaptive quiz.
#
#     WORKERS=4 PER=3 STRATEGIES="fastest highestsub" REPEAT=2 COLD=1 MOODLE_ROOT=/var/www/moodle \
#         local/catquizlab/cli/trace_matrix.sh
#
# Per cell and strategy: smoke.php plays it; load_sampler.php samples the web server's processes, their memory and the
# database's connections; request_report.php reads every request of the window (status, PHP peak, RSS, 5xx, fatals);
# trace_report.php reads every engine trace of the window (request time per kind and of strategy:select_question —
# n, mean, sd, 95 % interval, p50/p95/p99, max — the bytes, the link of each trace to its lab sitting) and the
# sittings' outcomes and capacity causes (submit_cdp_timeout and the others the load guard counts).
#
# The cells run in the order ABCD, then DCBA when REPEAT=2, so that a drift of the server over the hour does not land on
# one cell. COLD=1 purges Moodle's caches before each cell (cold cache); 0 keeps them (warm). With tracing off the
# engine measures nothing inside a request, and the report says so instead of printing zeros.
#
# The two settings are put back as they were when it ends, however it ends. Writes everything to $OUT. Run it on a
# server like the production one and nowhere near real work: it plays real sittings with real workers.
#
# Fail closed since 0.7.72 (re-audit 19.11.2026): each cell is checked by trace_matrix_check.py — its files there and
# readable, the smoke test passed, no 5xx, every answer request mapped onto its engine trace and every item selection
# onto its selector span where the engine traces, no trace where it does not; then the whole matrix (every cell of
# every strategy REPEAT times) and the comparison (no missing pair, no empty sample). Any finding: exit 1.
set -u
MOODLE="${MOODLE_ROOT:-/home/claude/moodle}"
WORKERS="${WORKERS:-4}"
PER="${PER:-3}"
STRATEGIES="${STRATEGIES:-fastest highestsub}"
REPEAT="${REPEAT:-1}"
COLD="${COLD:-0}"
MINUTES="${MINUTES:-30}"
OUT="${OUT:-/tmp/catquizlab-trace-matrix}"
PHP="${PHP:-php}"
STATUS_URL="${STATUS_URL:-}"
CLI="$MOODLE/local/catquizlab/cli"
CFG="$MOODLE/admin/cli/cfg.php"
mkdir -p "$OUT"

trace_before=$("$PHP" "$CFG" --component=local_catquiz --name=attempttrace 2>/dev/null)
debug_before=$("$PHP" "$CFG" --component=local_catquizlab --name=debuglevel 2>/dev/null)
restore() {
    "$PHP" "$CFG" --component=local_catquiz --name=attempttrace --set="${trace_before:-0}" > /dev/null
    "$PHP" "$CFG" --component=local_catquizlab --name=debuglevel --set="${debug_before:-off}" > /dev/null
    echo "Settings put back: attempttrace=${trace_before:-0}, debuglevel=${debug_before:-off}."
}
trap restore EXIT

cells="0:off 1:off 0:trace 1:trace"
order="$cells"
if [ "$REPEAT" -ge 2 ]; then
    order="$cells $(echo "$cells" | tr ' ' '\n' | tac | tr '\n' ' ')"
fi

printf '%-24s %-6s %s\n' cell result 'what the cell measured'
n=0
bad=0
for cell in $order; do
    trace=${cell%%:*}
    debug=${cell##*:}
    n=$((n + 1))
    "$PHP" "$CFG" --component=local_catquiz --name=attempttrace --set="$trace" > /dev/null
    "$PHP" "$CFG" --component=local_catquizlab --name=debuglevel --set="$debug" > /dev/null
    if [ "$COLD" = 1 ]; then
        "$PHP" "$MOODLE/admin/cli/purge_caches.php" > /dev/null
    fi
    for strategy in $STRATEGIES; do
        name="$n-trace$trace-debug$debug-$strategy"
        from=$(date +%s)
        "$PHP" "$CLI/load_sampler.php" --seconds=$(( MINUTES * 60 + 600 )) ${STATUS_URL:+--status-url="$STATUS_URL"} \
            > "$OUT/$name-samples.jsonl" 2>/dev/null &
        sampler=$!
        rm -f "$OUT/$name-smoke.json"
        # --keep: the step's engine traces and the lab's records are read below, before the experiment is deleted
        # (before 0.7.72 smoke.php deleted it first, and the traces with it).
        if timeout $(( MINUTES * 60 + 300 )) "$PHP" "$CLI/smoke.php" --strategy="$strategy" \
            --persons=$(( WORKERS * PER )) --concurrency="$WORKERS" --minutes="$MINUTES" --require-all --keep \
            --summary="$OUT/$name-smoke.json" > "$OUT/$name.log" 2>&1; then result=PASS; else result=FAIL; fi
        until=$(date +%s)
        kill "$sampler" 2>/dev/null; wait "$sampler" 2>/dev/null
        "$PHP" "$CLI/request_report.php" --from="$from" --until="$until" --json --raw="$OUT/$name-requests.csv" \
            > "$OUT/$name-requests.json" 2>/dev/null
        echo "{\"cell\": \"$name\", \"trace\": $trace, \"debug\": \"$debug\", \"strategy\": \"$strategy\", \"cold\": $COLD, \"from\": $from, \"until\": $until, \"result\": \"$result\"}" \
            > "$OUT/$name-cell.json"
        "$PHP" "$CLI/trace_report.php" --from="$from" --until="$until" --json > "$OUT/$name-traces.json" 2>/dev/null
        "$PHP" "$CLI/trace_report.php" --from="$from" --until="$until" > "$OUT/$name-traces.txt" 2>/dev/null
        experiment=$(python3 -c 'import json,sys
try: print(int(json.load(open(sys.argv[1])).get("experimentid") or 0))
except Exception: print(0)' "$OUT/$name-smoke.json")
        if [ "$experiment" -gt 0 ]; then
            "$PHP" "$CLI/delete_experiment.php" --id="$experiment" > "$OUT/$name-deleted.txt" 2>&1
        fi
        # Fail closed: every file there and readable, every answer and selection mapped onto its trace (#164).
        if line=$(python3 "$CLI/trace_matrix_check.py" cell "$OUT" "$name"); then :; else bad=$((bad + 1)); fi
        printf '%-24s %-6s %s\n' "trace$trace/debug$debug $strategy" "$result" "$line"
        sed 's/^/    /' "$OUT/$name-check.txt" | grep -v '^    clean$' || true
    done
done
echo "Everything in $OUT. The tables in $OUT/*-traces.txt have n, mean, sd and the 95 % interval per kind."
# The whole 2 x 2: every cell there REPEAT times for every strategy, each clean (trace_matrix_check.py).
python3 "$CLI/trace_matrix_check.py" matrix "$OUT" "$STRATEGIES" "$REPEAT" || bad=$((bad + 1))
# The comparison of the cells over every request (bootstrap intervals of the differences), report.md and report.json;
# it fails on a missing pair or an empty sample.
python3 "$CLI/trace_matrix_compare.py" "$OUT" || bad=$((bad + 1))
if [ "$bad" -gt 0 ]; then
    echo "FAIL: the trace matrix is not complete and clean ($bad finding(s) above; $OUT/*-check.txt, matrix-check.txt)."
    exit 1
fi
