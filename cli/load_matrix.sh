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
set -u
MOODLE="${MOODLE_ROOT:-/home/claude/moodle}"
WORKERS="${WORKERS:-1 5 10 25 50}"
MODES="${MODES:-classic allsubs control}"
PER="${PER:-2}"
MINANSWERS="${MINANSWERS:-120}"
MINUTES="${MINUTES:-30}"
OUT="${OUT:-/tmp/catquizlab-load}"
PHP="${PHP:-php}"
CLI="$MOODLE/local/catquizlab/cli"
mkdir -p "$OUT"
printf '%-16s %-7s %-6s %-9s %-6s %-6s %-8s %-8s %-8s %-8s %-10s %-6s %s\n' step result reqs sittings 5xx fatal peakMB rssMB \
    webRSS dbconn startgap secs detail
for w in $WORKERS; do
    for mode in $MODES; do
        name="$mode-w$w"
        persons=$(( w * PER ))
        case $mode in
            control) args=(--strategy=allsubs --budget=2,4,1,2,0.35,1.0) ;;
            # The classical test plays its whole pool, which smoke.php sizes at twice the answer floor: half the floor
            # gives MINANSWERS questions.
            classic) args=(--strategy=classic --minanswers=$(( MINANSWERS / 2 ))) ;;
            *) args=(--strategy="$mode" --minanswers="$MINANSWERS") ;;
        esac
        from=$(date +%s)
        "$PHP" "$CLI/load_sampler.php" --seconds=$(( MINUTES * 60 + 600 )) > "$OUT/$name-samples.jsonl" 2>/dev/null &
        sampler=$!
        if timeout $(( MINUTES * 60 + 300 )) "$PHP" "$CLI/smoke.php" "${args[@]}" --persons="$persons" --concurrency="$w" \
            --minutes="$MINUTES" > "$OUT/$name.log" 2>&1; then result=PASS; else result=FAIL; fi
        until=$(date +%s)
        kill "$sampler" 2>/dev/null; wait "$sampler" 2>/dev/null
        "$PHP" "$CLI/request_report.php" --from="$from" --until="$until" --json > "$OUT/$name-requests.json" 2>/dev/null
        "$PHP" "$CLI/capacity_report.php" --hours=1 --json > "$OUT/$name-capacity.json" 2>/dev/null
        summary=$(python3 - "$OUT/$name-requests.json" "$OUT/$name-samples.jsonl" <<'PY'
import json, sys
r = json.load(open(sys.argv[1]))
s = [json.loads(l) for l in open(sys.argv[2]) if l.strip()]
web = max((x.get('webrssmb') or 0 for x in s), default=0)
db = max((x.get('dbconnections') or 0 for x in s), default=0)
gap = r.get('startgap') or {}
print(r['requests'], r.get('sittings', '-'), r['http5xx'], r['fatals'], r['peakmb']['max'], (r.get('rssmb') or {}).get('max'), web, db,
      str(gap.get('min', '-')) + '/' + str(gap.get('mean', '-')))
PY
)
        detail=$(grep -oE 'PASS:.*|[0-9]+ collected, [0-9]+ failed|!!!.*!!!|DEFECT: .*|DISAGREES: .*' "$OUT/$name.log" | tail -1)
        read -r reqs sittings h5 fatal peak rss web db gap <<< "$summary"
        printf '%-16s %-7s %-6s %-9s %-6s %-6s %-8s %-8s %-8s %-8s %-10s %-6s %s\n' "$name" "$result" "$reqs" "$sittings" "$h5" \
            "$fatal" "$peak" "$rss" "$web" "$db" "$gap" $(( until - from )) "$detail"
    done
done
