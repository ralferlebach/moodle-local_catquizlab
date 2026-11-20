#!/bin/bash
# Load matrix of real CAT sittings (#110, #124, #136): workers × mode, one experiment per step, played by the worker
# through the real adaptive quiz against this server, each request traced by the server.
#
# Modes: classic and allsubs as long tests (MINANSWERS questions, default 120), and a short control mode (allsubs,
# 2–4 questions). Per step: smoke.php plays it (persons = workers × PER), load_sampler.php samples the web server's
# processes, their resident memory and the database's connections, request_report.php reads every traced request of
# the step (status, PHP peak, process RSS, queries, 5xx, fatals, exceptions) and capacity_report.php the workers.
#
#     WORKERS="1 5 10 25 50" PER=2 MOODLE_ROOT=/var/www/moodle local/catquizlab/cli/load_matrix.sh
#
# The web server's memory_limit is its own; say it with WEBLIMIT=512M for the report. Writes everything to $OUT.
#
# Since 0.7.71 (acceptance workflow, #110): STATUS_URL (Apache's mod_status, ?auto) adds the busy and idle workers to
# every sample; FAULTS=W adds a step with W workers in which every sitting loses a response, loses a request and gets
# a response too late (CATQUIZLAB_FAULTS) — each must be reconciled, nothing answered twice; every step's figures go
# to $OUT/matrix.jsonl, and the exit code is 1 when a step failed: a sitting not played to the end, an HTTP 5xx or a
# PHP fatal (GATE=0 reports without failing).
#
# Since 0.7.72 (re-audit 19.11.2026): every step is classified from what smoke.php wrote to its summary file, never from
# its exit code alone —
#   PASS      every sitting collected in the time limit, valid as the mode's design makes it, no 5xx, no fatal;
#   CAPACITY  every sitting collected and valid, no 5xx, no fatal, no sitting failed, none stalled — but some only after
#             the time limit, each still answering when it was reached: the machine was full (CPU, requests' times), the
#             plugin did not break. Reported with its figures; the highest step below it is the safe load of this
#             machine. Not a defect, and not hidden either: the matrix says it, and a step CAPACITY at 1 or 5 workers
#             fails, because that is no load at all;
#   FAIL      anything else: a 5xx, a fatal, a failed or stalled sitting (no answer for STALL seconds at the limit), a
#             sitting not ended after the limit, a sitting invalid where the design makes it valid, missing results.
# The step is played with --keep: its engine traces (trace_report.php, the selector's time) and the lab's records are
# read before the experiment is deleted — deleting first took the traces with it.
set -u
MOODLE="${MOODLE_ROOT:-/home/claude/moodle}"
WORKERS="${WORKERS:-1 5 10 25 50}"
MODES="${MODES:-classic allsubs control}"
PER="${PER:-2}"
MINANSWERS="${MINANSWERS:-120}"
MINUTES="${MINUTES:-30}"
OUT="${OUT:-/tmp/catquizlab-load}"
PHP="${PHP:-php}"
STATUS_URL="${STATUS_URL:-}"
FAULTS="${FAULTS:-}"
GATE="${GATE:-1}"
STALL="${STALL:-300}"
failed=0
capacity=0
CLI="$MOODLE/local/catquizlab/cli"
mkdir -p "$OUT"
printf '%-16s %-7s %-6s %-9s %-6s %-6s %-8s %-8s %-8s %-8s %-10s %-6s %s\n' step result reqs sittings 5xx fatal peakMB rssMB \
    webRSS dbconn startgap secs detail
steps=""
for w in $WORKERS; do for mode in $MODES; do steps="$steps $mode:$w:"; done; done
if [ -n "$FAULTS" ]; then steps="$steps $(echo "$MODES" | awk '{print $1}'):$FAULTS:faults"; fi
for step in $steps; do
        mode=${step%%:*}; rest=${step#*:}; w=${rest%%:*}; kind=${rest#*:}
        name="$mode-w$w${kind:+-$kind}"
        persons=$(( w * PER ))
        case $mode in
            # Short sittings, valid by design: four questions end with an SE near 1.0, so the largest SE that still
            # counts is 1.5. With 1.0 (before 0.7.72) every control sitting ended just above it, invalid, and the step
            # failed on "no observations" with every sitting collected (re-audit 19.11.2026).
            control) args=(--strategy=allsubs --budget=2,4,1,2,0.35,1.5) ;;
            # The classical test plays its whole pool, which smoke.php sizes at twice the answer floor: half the floor
            # gives MINANSWERS questions.
            classic) args=(--strategy=classic --minanswers=$(( MINANSWERS / 2 ))) ;;
            *) args=(--strategy="$mode" --minanswers="$MINANSWERS") ;;
        esac
        extra=(--no-navigation-failures)
        envs=()
        if [ "$kind" = faults ]; then
            # Each sitting: the fifth answer's response lost, the ninth request lost, the twelfth response held back past
            # both waits of the worker — three reconciliations per sitting, one of them a timeout (worker-e2e.yml).
            envs=(CATQUIZLAB_FAULTS=lostresponse@5,lostrequest@9,slowresponse@12)
            extra=(--expect-recovered=$(( persons * 3 )) \
                --expect-recovered-reasons=submit_cdp_timeout:$persons,submit_connect_failed:$(( persons * 2 )))
        fi
        from=$(date +%s)
        "$PHP" "$CLI/load_sampler.php" --seconds=$(( MINUTES * 60 + 600 )) ${STATUS_URL:+--status-url="$STATUS_URL"} \
            > "$OUT/$name-samples.jsonl" 2>/dev/null &
        sampler=$!
        rm -f "$OUT/$name-smoke.json"
        if env "${envs[@]}" timeout $(( MINUTES * 60 + 300 )) "$PHP" "$CLI/smoke.php" "${args[@]}" "${extra[@]}" \
            --persons="$persons" --concurrency="$w" --minutes="$MINUTES" --require-all --keep \
            --summary="$OUT/$name-smoke.json" --stall="$STALL" > "$OUT/$name.log" 2>&1; then smoke=0; else smoke=$?; fi
        until=$(date +%s)
        kill "$sampler" 2>/dev/null; wait "$sampler" 2>/dev/null
        "$PHP" "$CLI/request_report.php" --from="$from" --until="$until" --json --raw="$OUT/$name-requests.csv" \
            > "$OUT/$name-requests.json" 2>/dev/null
        "$PHP" "$CLI/capacity_report.php" --hours=1 --json > "$OUT/$name-capacity.json" 2>/dev/null
        # The engine's traces of the step while its sittings still exist: request times by kind, the selector.
        "$PHP" "$CLI/trace_report.php" --from="$from" --until="$until" --json > "$OUT/$name-traces.json" 2>/dev/null
        experiment=$(python3 -c 'import json,sys
try: print(int(json.load(open(sys.argv[1])).get("experimentid") or 0))
except Exception: print(0)' "$OUT/$name-smoke.json")
        if [ "$experiment" -gt 0 ]; then
            "$PHP" "$CLI/delete_experiment.php" --id="$experiment" > "$OUT/$name-deleted.txt" 2>&1
        fi
        result=$(python3 "$CLI/load_step_verdict.py" "$OUT/$name-smoke.json" "$OUT/$name-requests.json" "$smoke" "$mode" \
            "$w" 2> "$OUT/$name-verdict.txt")
        summary=$(python3 - "$OUT/$name-requests.json" "$OUT/$name-samples.jsonl" "$OUT/matrix.jsonl" "$name" "$mode" "$w" \
            "$persons" "${kind:-load}" "$result" "$(( until - from ))" "${WEBLIMIT:-}" "$OUT/$name-smoke.json" \
            "$OUT/$name-traces.json" "$OUT/$name-verdict.txt" <<'PY'
import json, sys
(req, samples, matrix, name, mode, workers, persons, kind, result, secs, weblimit, smokef, tracesf,
 verdictf) = sys.argv[1:15]
def load(p):
    try:
        v = json.load(open(p))
        return v if isinstance(v, dict) else {}
    except (OSError, ValueError):
        return {}
r = load(req)
smoke = load(smokef)
traces = load(tracesf)
try:
    reasons = [l.strip() for l in open(verdictf) if l.strip()]
except OSError:
    reasons = []
s = []
for l in open(samples):
    # The sampler is stopped mid-line at the end of a step: an unfinished last line is not a sample.
    try:
        s.append(json.loads(l))
    except ValueError:
        pass
peak = lambda k: max((x.get(k) or 0 for x in s), default=0)
mean = lambda k: round(sum((x.get(k) or 0) for x in s) / len(s), 1) if s else None
waits = {}
for x in s:
    for k, v in (x.get('dbwaiting') or {}).items():
        waits[k] = max(waits.get(k, 0), v)
gap = r.get('startgap') or {}
row = {'step': name, 'mode': mode, 'workers': int(workers), 'persons': int(persons), 'kind': kind, 'result': result,
       'seconds': int(secs), 'web_memory_limit': weblimit, 'requests': r.get('requests'), 'sittings': r.get('sittings'),
       'status': r.get('status'), 'http5xx': r.get('http5xx'), 'fatals': r.get('fatals'),
       'exceptions': r.get('exceptions'), 'aborted': r.get('aborted'), 'php_peak_mb': r.get('peakmb'),
       'process_rss_mb': r.get('rssmb'), 'queries_per_request': r.get('queries'), 'request_ms': r.get('durationms'),
       'web_processes_seen': r.get('processes'), 'samples': len(s), 'web_rss_mb_max': peak('webrssmb'),
       'web_procs_max': peak('webprocs'), 'apache_busy_max': peak('apachebusy'), 'apache_busy_mean': mean('apachebusy'),
       'db_connections_max': peak('dbconnections'), 'db_active_max': peak('dbactive'), 'db_waiting_max': waits,
       'cpu_percent_mean': mean('cpupercent'), 'cpu_percent_max': peak('cpupercent'), 'iowait_percent_max': peak('iowaitpercent'),
       'browser_rss_mb_max': peak('browserrssmb'), 'browser_procs_max': peak('browserprocs'),
       'mem_available_mb_min': min((x.get('memavailablemb') or 0 for x in s), default=None),
       'disk_written_mb': (s[-1].get('writtenmb', 0) - s[0].get('writtenmb', 0)) if len(s) > 1 else None,
       'start_gap_s': gap,
       # What smoke.php said of the step, and why the step has its result (load_step_verdict.py).
       'smoke': smoke, 'verdict_reasons': reasons,
       # The engine's own times in the step, read before the step's experiment was deleted (#110: the selector).
       'engine_traces': traces.get('traces'), 'engine_kinds': traces.get('kinds'),
       'engine_selector_ms': traces.get('selector') or (traces.get('spans') or {}).get('strategy:select_question'),
       'engine_selector_span': traces.get('selectorspan')}
with open(matrix, 'a') as f:
    f.write(json.dumps(row) + "\n")
p = r.get('peakmb') or {}
print(r.get('requests', '-'), r.get('sittings', '-'), r.get('http5xx', '-'), r.get('fatals', '-'), p.get('max'),
      (r.get('rssmb') or {}).get('max'), peak('webrssmb'), peak('dbconnections'),
      str(gap.get('min', '-')) + '/' + str(gap.get('mean', '-')))
PY
)
        detail=$(grep -oE 'PASS:.*|[0-9]+ collected, [0-9]+ failed|!!!.*!!!|DEFECT: .*|DISAGREES: .*' "$OUT/$name.log" | tail -1)
        read -r reqs sittings h5 fatal peak rss web db gap <<< "$summary"
        printf '%-16s %-7s %-6s %-9s %-6s %-6s %-8s %-8s %-8s %-8s %-10s %-6s %s\n' "$name" "$result" "$reqs" "$sittings" "$h5" \
            "$fatal" "$peak" "$rss" "$web" "$db" "$gap" $(( until - from )) "$detail"
        if [ "$result" = CAPACITY ]; then capacity=$(( capacity + 1 )); fi
        if [ "$result" != PASS ] && [ "$result" != CAPACITY ]; then failed=$(( failed + 1 )); fi
        sed 's/^/    /' "$OUT/$name-verdict.txt"
done
python3 "$CLI/load_step_verdict.py" --safe-load "$OUT/matrix.jsonl" | tee "$OUT/safe-load.txt"
echo "Every step's figures: $OUT/matrix.jsonl; the requests of each step: $OUT/*-requests.csv."
if [ "$GATE" = 1 ] && [ "$failed" -gt 0 ]; then
    echo "FAIL: $failed step(s) failed (see each step's verdict above and $OUT/*-verdict.txt)."
    exit 1
fi
if [ "$capacity" -gt 0 ]; then
    echo "$capacity step(s) beyond this machine's capacity, every sitting collected and valid (see $OUT/safe-load.txt)."
fi
