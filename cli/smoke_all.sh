#!/bin/bash
# Play one experiment per CAT strategy, from definition to results.
#
# The release gate for #89. Each strategy runs on its own: they share an
# installation, and two at once defer each other's queues and compete for
# worker slots — which once cost a perfectly good strategy a FAIL and an
# afternoon of looking for a defect that was in the test.
#
# Exit code 0 only when every strategy passes.
set -u

MOODLE="${MOODLE_ROOT:-/home/claude/moodle}"
STRATEGIES="${STRATEGIES:-classic allsubs balanced fastest relsubs}"
PERSONS="${PERSONS:-1}"
MINUTES="${MINUTES:-3}"
OUT="${OUT:-/tmp/catquizlab-smoke}"

mkdir -p "$OUT"
failed=0

for strategy in $STRATEGIES; do
    printf '%-10s ' "$strategy"
    if timeout $(( MINUTES * 60 + 120 )) php "$MOODLE/local/catquizlab/cli/smoke.php" \
        --strategy="$strategy" --persons="$PERSONS" --minutes="$MINUTES" \
        > "$OUT/$strategy.log" 2>&1; then
        grep -oE 'PASS:.*' "$OUT/$strategy.log" | head -1
    else
        failed=1
        # The reason, not just the failure: the log holds the Moodle exception,
        # the attempt and the stage, which is what the issue asked to keep.
        grep -oE '!!!.*!!!|No attempt.*|Results are missing.*|Attempts answered.*' \
            "$OUT/$strategy.log" | head -1
        echo "           see $OUT/$strategy.log"
    fi
done

exit $failed
