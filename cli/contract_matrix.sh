#!/bin/bash
# End-to-end matrix of the run contract (#123): real sittings, played by the worker through the real adaptive quiz,
# one experiment per case. Each case checks with cli/smoke.php: the engine read back holds what was asked for, the
# sittings keep to their contract (no limit broken, no parameter drifted), and every sitting ends the same and has the
# same length in the engine, the analysis, the export and the reproduction package.
#
# The cases are those of tests/provisioning_readback_test.php — strategies, global and subscale budgets, SE targets —
# plus the classical test's "all items", the sweep with cell overrides of #104 and the root scale of #102.
#
#     MOODLE_ROOT=/var/www/moodle PERSONS=3 local/catquizlab/cli/contract_matrix.sh
#
# Writes one log per case to $OUT and a summary line per case; exit code 0 only when every case passes.
set -u
MOODLE="${MOODLE_ROOT:-/home/claude/moodle}"
PERSONS="${PERSONS:-2}"
MINUTES="${MINUTES:-6}"
OUT="${OUT:-/tmp/catquizlab-contract}"
PHP="${PHP:-php}"
mkdir -p "$OUT"

# name|strategy|budget (global min,max, subscale min,max, SE min,max)|extra options
CASES="
strategy-fastest|fastest|2,4,1,2,0.35,1.0|
strategy-allsubs|allsubs|2,4,1,2,0.35,1.0|
strategy-classic|classic|2,4,1,2,0.35,1.0|
global-3-6|allsubs|3,6,1,2,0.35,1.0|
global-1-8|allsubs|1,8,1,2,0.35,1.0|
subscale-1-3|lowestsub|2,8,1,3,0.35,1.0|
subscale-2-2|lowestsub|2,8,2,2,0.35,1.0|
se-0.30|allsubs|2,6,1,2,0.30,1.0|
se-0.50|allsubs|2,6,1,2,0.50,0.9|
classic-all-items|classic||
sweep-cell-overrides-104|classic||--case=104
root-scale-102|allsubs||--case=102
"
failed=0
printf '%-26s %-6s %s\n' case result detail
while IFS='|' read -r name strategy budget extra; do
    [ -z "$name" ] && continue
    args=(--strategy="$strategy" --persons="$PERSONS" --minutes="$MINUTES")
    [ -n "$budget" ] && args+=(--budget="$budget")
    [ -n "$extra" ] && args+=($extra)
    start=$(date +%s)
    if timeout $(( MINUTES * 60 + 300 )) "$PHP" "$MOODLE/local/catquizlab/cli/smoke.php" "${args[@]}" > "$OUT/$name.log" 2>&1; then
        result=PASS
    else
        result=FAIL; failed=1
    fi
    detail=$(grep -oE 'PASS:.*|!!!.*!!!|DEFECT: .*|DISAGREES: .*|DIFFERENCE: .*|Attempts answered.*|No attempt.*' "$OUT/$name.log" | head -1)
    ends=$(grep -oE 'Ends compared.*: (all agree|[0-9]+ disagreement)' "$OUT/$name.log" | head -1)
    printf '%-26s %-6s %s | %s | %ss\n' "$name" "$result" "$detail" "$ends" $(( $(date +%s) - start ))
done <<< "$CASES"
exit $failed
