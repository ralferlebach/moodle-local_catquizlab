#!/usr/bin/env bash
#
# The release manifest of one CI job (re-audit 18.11.2026, gate 3): every component this job tested, with its version
# and commit — Moodle, PHP, the web server, the database, local_catquiz, mod_adaptivequiz, the catmodel, CatQuizLab,
# Node and the browsers. One JSON file per job; the job "Release manifest" of acceptance.yml puts them together.
#
#     JOB_NAME="load classic" CORE_ROOT=… MOODLE_ROOT=… bash plugin/.github/scripts/release-manifest.sh > manifest.json
#
# Reads only. Missing parts are written as null, not left out, so that a gap shows.

set -uo pipefail

WS="${GITHUB_WORKSPACE:-$PWD}"
CORE="${CORE_ROOT:-$WS/moodle}"
ROOT="${MOODLE_ROOT:-$CORE}"
MANIFEST="${ENGINE_MANIFEST:-$WS/engine-manifest.txt}"

json() { python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$1"; }
first() { "$@" 2>/dev/null | head -n 1 | tr -d '\r' || true; }
value() { local v; v=$(first "$@"); if [ -n "$v" ]; then json "$v"; else echo null; fi; }

engine_line() {
    local v
    v=$(awk -v k="$1" '$1 == k { $1 = ""; sub(/^ +/, ""); print; exit }' "$MANIFEST" 2>/dev/null)
    if [ -n "$v" ]; then json "$v"; else echo null; fi
}

browser_versions() {
    local out="" f v
    while IFS= read -r f; do
        v=$("$f" --version 2>/dev/null | head -n 1)
        [ -n "$v" ] && out="$out$(json "$f"): $(json "$v"),"
    done < <(find "$HOME/moodledata" "$HOME/.cache/puppeteer" "$HOME/.cache/ms-playwright" -maxdepth 12 -type f \
        \( -name chrome -o -name chrome-headless-shell -o -name headless_shell \) -perm -u+x 2>/dev/null | sort | head -n 8)
    echo "{${out%,}}"
}

DBINFO=$(cd "$CORE" && php -r 'define("CLI_SCRIPT", true); require "config.php";
    $i = $DB->get_server_info(); echo $DB->get_dbfamily() . " " . ($i["version"] ?? "") . " " . ($i["description"] ?? "");' 2>/dev/null)

cat <<JSON
{
  "job": $(json "${JOB_NAME:-${GITHUB_JOB:-local}}"),
  "workflow": $(json "${GITHUB_WORKFLOW:-}"),
  "run": $(json "${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-}/actions/runs/${GITHUB_RUN_ID:-}"),
  "time": $(json "$(date -u +%Y-%m-%dT%H:%M:%SZ)"),
  "catquizlab": {
    "commit": $(value echo "${GITHUB_SHA:-}"),
    "version": $(value sed -n "s/.*plugin->version *= *\([0-9]*\).*/\1/p" "$ROOT/local/catquizlab/version.php"),
    "release": $(value sed -n "s/.*plugin->release *= *'\([^']*\)'.*/\1/p" "$ROOT/local/catquizlab/version.php")
  },
  "moodle": {
    "branch": $(value git -C "$CORE" rev-parse --abbrev-ref HEAD),
    "commit": $(value git -C "$CORE" rev-parse HEAD),
    "release": $(value bash -c "cd '$CORE' && php admin/cli/cfg.php --name=release"),
    "version": $(value bash -c "cd '$CORE' && php admin/cli/cfg.php --name=version")
  },
  "engine": {
    "set": $(engine_line engine_set),
    "local_catquiz": $(engine_line local_catquiz),
    "mod_adaptivequiz": $(engine_line mod_adaptivequiz),
    "adaptivequizcatmodel_catquiz": $(engine_line adaptivequizcatmodel_catquiz),
    "local_wunderbyte_table": $(engine_line local_wunderbyte_table),
    "manifest": $(if [ -f "$MANIFEST" ]; then json "$(cat "$MANIFEST")"; else echo null; fi)
  },
  "installed": $(if [ -f "$WS/installed-versions.txt" ]; then json "$(cat "$WS/installed-versions.txt")"; else echo null; fi),
  "php": {
    "cli": $(value php -r 'echo PHP_VERSION;'),
    "fpm": $(value bash -c 'ls /usr/sbin/php-fpm* >/dev/null 2>&1 && /usr/sbin/php-fpm8.3 -v | head -n 1'),
    "web_memory_limit": $(value echo "${PHP_MEMORY_LIMIT:-}")
  },
  "webserver": $(value bash -c 'apache2 -v 2>/dev/null | head -n 1 || echo "php built-in"'),
  "database": $(if [ -n "$DBINFO" ]; then json "$DBINFO"; else echo null; fi),
  "node": $(value node --version),
  "npm": $(value npm --version),
  "browsers": $(browser_versions),
  "os": $(value bash -c '. /etc/os-release && echo "$PRETTY_NAME"'),
  "kernel": $(value uname -r),
  "cpus": $(value nproc),
  "memory": $(value bash -c "free -m | awk '/^Mem:/ { print \$2 \" MB\" }'")
}
JSON
