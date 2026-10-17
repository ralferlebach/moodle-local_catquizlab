#!/usr/bin/env bash
#
# Fetch the CAT engine and its host activity for the CI test jobs.
#
# local_catquizlab detects the engine at runtime and installs without it, so the
# lint jobs deliberately run engine-free. The PHPUnit and Behat jobs install it,
# because the guard paths are not the interesting ones: the first run against a
# real local_catquiz found five defects that no amount of testing without it
# could have shown — a correct engine reported as too old, a missing question
# library, a stale retrieval cache, a NOT NULL column of the host activity, and
# provisioning that was not idempotent.
#
# The refs are pinned to branch and commit (.github/engine-pins.env), so that
# every job tests the same recorded engine state; the manifest says which.

set -euo pipefail

ENGINE_DIR="${ENGINE_DIR:-engine}"

# The Moodle release this job installs, as its stable branch name. The engine's
# plugins declare what they require, and a plugin whose requirement the branch
# does not meet cannot be installed at all: Moodle aborts the whole installation
# with pluginrequirementsnotmet, taking the job with it before a single test
# runs. The suite installs without the engine by design, so the honest answer is
# to skip it on a release it does not support rather than to fail the build.
MOODLE_BRANCH="${MOODLE_BRANCH:-${1:-}}"

# Branch name to the version Moodle reports for it. Kept here rather than
# derived, because the mapping is not computable from the branch name.
branch_version() {
    case "$1" in
        # Release versions as Moodle reports them. 2025100600 is Moodle 5.1 —
        # this table had it as 5.2. For 5.2 only "above that" matters here.
        MOODLE_405_STABLE) echo 2024100700 ;;
        MOODLE_500_STABLE) echo 2025041400 ;;
        MOODLE_501_STABLE) echo 2025100600 ;;
        MOODLE_502_STABLE) echo 2026041300 ;;
        *)                 echo 0 ;;
    esac
}

# The highest requirement across the plugins we are about to place.
max_required() {
    local highest=0 file required
    for file in "$@"; do
        required=$(grep -oE '\$plugin->requires[[:space:]]*=[[:space:]]*[0-9]+' "$file" \
            | grep -oE '[0-9]+' | head -1)
        if [ -n "${required:-}" ] && [ "$required" -gt "$highest" ]; then
            highest=$required
        fi
    done
    echo "$highest"
}

# Plugin directory name -> repository and ref. The directory name is what
# moodle-plugin-ci uses to place the plugin, so it has to match the component.
# All three CAT plugins come from one coordinated set, chosen by the Moodle
# release:
#
#   Moodle 4.5          local_catquiz 1.2.1   ALiSe-v-1.2.0-legacy (all three)
#   Moodle 5.1 and up   local_catquiz 1.3.0   migration-zu-moodle-5.x,
#                       mod_adaptivequiz and its catmodel   v-3.0
#
# ALiSe-v-1.2.0-legacy is the set that still supports Moodle 4.5: the v-3.0 line
# raised mod_adaptivequiz to requires = 2025100600, which only Moodle 5.1 and up
# meet, and Moodle then aborts the whole installation rather than skipping one
# plugin. Moodle 5.0 is no longer in the CI matrix; were it run, the
# requirement check below would skip the engine and test the plugin
# stand-alone, which is what its runtime detection is for.
#
# Branch AND commit come from .github/engine-pins.env (re-audit 16.10.2026,
# AK 5): every job tests the same, recorded state of the engine, and the
# manifest written at the end says which. ENGINE_FOLLOW_HEADS=1 takes the branch
# heads instead — to check a new engine state before the pins are moved.
# ENGINE_BRANCH, when set, overrides all three refs at once, for an experiment
# (and implies the heads).
PINS_FILE="${PINS_FILE:-$(dirname "${BASH_SOURCE[0]}")/../engine-pins.env}"
if [ -f "${PINS_FILE}" ]; then
    # shellcheck disable=SC1090
    . "${PINS_FILE}"
fi
case "${MOODLE_BRANCH}" in
    MOODLE_405_STABLE|MOODLE_404_STABLE|"")
        SET=LEGACY
        CATQUIZ_REF="${LEGACY_CATQUIZ_REF:-ALiSe-v-1.2.0-legacy}";       CATQUIZ_SHA="${LEGACY_CATQUIZ_SHA:-}"
        ADAPTIVEQUIZ_REF="${LEGACY_ADAPTIVEQUIZ_REF:-ALiSe-v-1.2.0-legacy}"; ADAPTIVEQUIZ_SHA="${LEGACY_ADAPTIVEQUIZ_SHA:-}"
        CATMODEL_REF="${LEGACY_CATMODEL_REF:-ALiSe-v-1.2.0-legacy}";     CATMODEL_SHA="${LEGACY_CATMODEL_SHA:-}"
        ;;
    *)
        SET=M5
        CATQUIZ_REF="${M5_CATQUIZ_REF:-migration-zu-moodle-5.x}";        CATQUIZ_SHA="${M5_CATQUIZ_SHA:-}"
        ADAPTIVEQUIZ_REF="${M5_ADAPTIVEQUIZ_REF:-v-3.0}";                ADAPTIVEQUIZ_SHA="${M5_ADAPTIVEQUIZ_SHA:-}"
        CATMODEL_REF="${M5_CATMODEL_REF:-v-3.0}";                        CATMODEL_SHA="${M5_CATMODEL_SHA:-}"
        ;;
esac
TABLE_REF="${WUNDERBYTE_TABLE_REF:-main}"
TABLE_SHA="${WUNDERBYTE_TABLE_SHA:-}"
if [ -n "${ENGINE_BRANCH:-}" ]; then
    CATQUIZ_REF="${ENGINE_BRANCH}"
    ADAPTIVEQUIZ_REF="${ENGINE_BRANCH}"
    CATMODEL_REF="${ENGINE_BRANCH}"
    ENGINE_FOLLOW_HEADS=1
fi
if [ "${ENGINE_FOLLOW_HEADS:-0}" = "1" ]; then
    CATQUIZ_SHA=""; ADAPTIVEQUIZ_SHA=""; CATMODEL_SHA=""; TABLE_SHA=""
fi
echo "== engine set ${SET} for ${MOODLE_BRANCH:-unknown}: local_catquiz@${CATQUIZ_REF}${CATQUIZ_SHA:+ (${CATQUIZ_SHA:0:12})}," \
     "mod_adaptivequiz@${ADAPTIVEQUIZ_REF}${ADAPTIVEQUIZ_SHA:+ (${ADAPTIVEQUIZ_SHA:0:12})}," \
     "catmodel@${CATMODEL_REF}${CATMODEL_SHA:+ (${CATMODEL_SHA:0:12})}"

declare -A PLUGINS=(
    ["local_wunderbyte_table"]="https://github.com/Wunderbyte-GmbH/moodle-local_wunderbyte_table.git|${TABLE_REF}|${TABLE_SHA}"
    ["local_catquiz"]="https://github.com/ralferlebach/moodle-local_catquiz.git|${CATQUIZ_REF}|${CATQUIZ_SHA}"
    ["mod_adaptivequiz"]="https://github.com/ralferlebach/moodle-mod_adaptivequiz.git|${ADAPTIVEQUIZ_REF}|${ADAPTIVEQUIZ_SHA}"
    ["adaptivequizcatmodel_catquiz"]="https://github.com/ralferlebach/moodle-adaptivequizcatmodel_catquiz.git|${CATMODEL_REF}|${CATMODEL_SHA}"
)

# Where the plugins go: "flat" (default) puts each under ENGINE_DIR by component,
# for moodle-plugin-ci --extra-plugins; "moodle" puts them into the Moodle tree
# at ENGINE_DIR, where the end-to-end jobs install Moodle themselves.
ENGINE_LAYOUT="${ENGINE_LAYOUT:-flat}"
# Submodules: removed when empty (default), or fetched (ENGINE_SUBMODULES=1) —
# the end-to-end jobs install local_catquiz with its hub subplugins.
ENGINE_SUBMODULES="${ENGINE_SUBMODULES:-0}"
# The record of what was tested: component, repository, ref, commit, version.
ENGINE_MANIFEST="${ENGINE_MANIFEST:-engine-manifest.txt}"

target_of() {
    if [ "${ENGINE_LAYOUT}" = "moodle" ]; then
        case "$1" in
            local_wunderbyte_table)       echo "${ENGINE_DIR}/local/wunderbyte_table" ;;
            local_catquiz)                echo "${ENGINE_DIR}/local/catquiz" ;;
            mod_adaptivequiz)             echo "${ENGINE_DIR}/mod/adaptivequiz" ;;
            adaptivequizcatmodel_catquiz) echo "${ENGINE_DIR}/mod/adaptivequiz/catmodel/catquiz" ;;
        esac
    else
        echo "${ENGINE_DIR}/$1"
    fi
}

mkdir -p "${ENGINE_DIR}"
: > "${ENGINE_MANIFEST}"
printf '# engine set %s for %s, %s\n' "${SET}" "${MOODLE_BRANCH:-unknown}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "${ENGINE_MANIFEST}"
printf '%-30s %-26s %-40s %s\n' component ref commit version >> "${ENGINE_MANIFEST}"

# The host first: in the Moodle layout the cat model goes inside it.
for name in local_wunderbyte_table local_catquiz mod_adaptivequiz adaptivequizcatmodel_catquiz; do
    entry="${PLUGINS[$name]}"
    repo="${entry%%|*}"
    rest="${entry#*|}"
    ref="${rest%%|*}"
    sha="${rest#*|}"
    target=$(target_of "${name}")

    if [ -d "${target}" ] && [ -f "${target}/version.php" ]; then
        echo "== ${name}: already present"
        continue
    fi

    echo "== ${name}: ${repo} @ ${ref}${sha:+ @ ${sha}}"
    mkdir -p "$(dirname "${target}")"
    git clone --depth 1 --branch "${ref}" --quiet "${repo}" "${target}"
    if [ -n "${sha}" ] && [ "$(git -C "${target}" rev-parse HEAD)" != "${sha}" ]; then
        # The pinned commit, not the head the branch has moved on to.
        git -C "${target}" fetch --depth 1 --quiet origin "${sha}"
        git -C "${target}" checkout --quiet "${sha}"
    fi
    commit=$(git -C "${target}" rev-parse HEAD)
    if [ -n "${sha}" ] && [ "${commit}" != "${sha}" ]; then
        echo "== ${name}: pinned ${sha}, got ${commit}" >&2
        exit 1
    fi

    if [ -f "${target}/.gitmodules" ]; then
        if [ "${ENGINE_SUBMODULES}" = "1" ]; then
            git -C "${target}" submodule update --init --depth 1 --quiet
        else
            # Submodules are left as empty directories by a plain clone. Where a
            # plugin declares subplugin types, Moodle scans those directories and
            # fails on the missing version.php -- not with a warning, but by
            # aborting whatever asked the plugin manager for the plugin list.
            # local_catquiz carries its central hub that way. The submodules are
            # not needed to drive the engine, so the empty shells are removed
            # rather than fetched: a half-materialised subplugin is worse than none.
            while read -r path; do
                [ -n "${path}" ] || continue
                if [ -d "${target}/${path}" ] && [ -z "$(ls -A "${target}/${path}" 2>/dev/null)" ]; then
                    echo "   removing unpopulated submodule ${path}"
                    rmdir "${target}/${path}"
                fi
            done < <(grep -oE '^[[:space:]]*path[[:space:]]*=[[:space:]]*.*$' "${target}/.gitmodules" \
                | sed -E 's/^[[:space:]]*path[[:space:]]*=[[:space:]]*//')
        fi
    fi

    rm -rf "${target}/.git"

    version=$(grep -oE '\$plugin->version\s*=\s*[0-9]+' "${target}/version.php" | grep -oE '[0-9]+' | head -1)
    echo "   ${commit} version ${version}"
    printf '%-30s %-26s %-40s %s\n' "${name}" "${ref}" "${commit}" "${version}" >> "${ENGINE_MANIFEST}"
done
echo "== manifest ${ENGINE_MANIFEST}:"
cat "${ENGINE_MANIFEST}"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    { echo '### Engine set tested'; echo '```'; cat "${ENGINE_MANIFEST}"; echo '```'; } >> "${GITHUB_STEP_SUMMARY}"
fi

if [ "${ENGINE_LAYOUT}" = "moodle" ]; then
    # In the Moodle tree everything already sits where it belongs.
    exit 0
fi

# The cat model is a subplugin of mod_adaptivequiz. moodle-plugin-ci installs
# each directory under --extra-plugins as a top-level plugin, so the subplugin
# has to sit inside its host before the install runs; installed side by side it
# would be placed in the wrong directory and never found.
if [ -d "${ENGINE_DIR}/adaptivequizcatmodel_catquiz" ]; then
    mkdir -p "${ENGINE_DIR}/mod_adaptivequiz/catmodel"
    mv "${ENGINE_DIR}/adaptivequizcatmodel_catquiz" "${ENGINE_DIR}/mod_adaptivequiz/catmodel/catquiz"
    echo "== adaptivequizcatmodel_catquiz moved into mod_adaptivequiz/catmodel/catquiz"
fi

# Now that every plugin is in place, check the release can carry them. The
# check happens here rather than per plugin, because the engine's parts depend
# on each other: installing some of them is not a smaller engine, it is a broken
# one.
BRANCH_VERSION=$(branch_version "${MOODLE_BRANCH}")
REQUIRED=$(max_required $(find "${ENGINE_DIR}" -name version.php))

if [ "${BRANCH_VERSION}" -gt 0 ] && [ "${REQUIRED}" -gt "${BRANCH_VERSION}" ]; then
    echo "== The engine requires Moodle ${REQUIRED}; ${MOODLE_BRANCH} is ${BRANCH_VERSION}."
    echo "== Skipping it for this job: the suite installs without the engine, and"
    echo "== its engine-facing tests skip when none is present."
    echo "# SKIPPED: requires ${REQUIRED}, ${MOODLE_BRANCH} is ${BRANCH_VERSION}; tested without the engine" >> "${ENGINE_MANIFEST}"
    rm -rf "${ENGINE_DIR:?}"/*
    mkdir -p "${ENGINE_DIR}"
    exit 0
fi

echo "Engine ready in ${ENGINE_DIR}:"
ls -1 "${ENGINE_DIR}"
