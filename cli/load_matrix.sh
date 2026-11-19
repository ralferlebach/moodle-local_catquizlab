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
failed=0
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
            control) args=(--strategy=allsubs --budget=2,4,1,2,0.35,1.0) ;;
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
        if env "${envs[@]}" timeout $(( MINUTES * 60 + 300 )) "$PHP" "$CLI/smoke.php" "${args[@]}" "${extra[@]}" \
            --persons="$persons" --concurrency="$w" --minutes="$MINUTES" --require-all \
            > "$OUT/$name.log" 2>&1; then result=PASS; else result=FAIL; fi
        until=$(date +%s)
        kill "$sampler" 2>/dev/null; wait "$sampler" 2>/dev/null
        "$PHP" "$CLI/request_report.php" --from="$from" --until="$until" --json --raw="$OUT/$name-requests.csv" \
            > "$OUT/$name-requests.json" 2>/dev/null
        "$PHP" "$CLI/capacity_report.php" --hours=1 --json > "$OUT/$name-capacity.json" 2>/dev/null
        summary=$(python3 - "$OUT/$name-requests.json" "$OUT/$name-samples.jsonl" "$OUT/matrix.jsonl" "$name" "$mode" "$w" \
            "$persons" "${kind:-load}" "$result" "$(( until - from ))" "${WEBLIMIT:-}" <<'PY'
import json, sys
(req, samples, matrix, name, mode, workers, persons, kind, result, secs, weblimit) = sys.argv[1:12]
try:
    r = json.load(open(req))
except (OSError, ValueError):
    r = {}
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
       'start_gap_s': gap}
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
        if [ "$result" != PASS ] || [ "${h5:-0}" != 0 ] || [ "${fatal:-0}" != 0 ]; then failed=$(( failed + 1 )); fi
done
echo "Every step's figures: $OUT/matrix.jsonl; the requests of each step: $OUT/*-requests.csv."
if [ "$GATE" = 1 ] && [ "$failed" -gt 0 ]; then
    echo "FAIL: $failed step(s) with a sitting not played to the end, an HTTP 5xx or a PHP fatal."
    exit 1
fi
