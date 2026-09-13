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
# The refs are pinned to branches rather than to commits so the suite is tested
# against what the engine actually ships. If that turns out to make the CI
# fragile, pin the commits here; the trade is stability against knowing early
# that the engine has moved.

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
        MOODLE_405_STABLE) echo 2024100700 ;;
        MOODLE_500_STABLE) echo 2025041400 ;;
        MOODLE_501_STABLE) echo 2025100000 ;;
        MOODLE_502_STABLE) echo 2025100600 ;;
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
# All three CAT plugins come from one coordinated branch. ALiSe-v-1.2.0-legacy
# is the set that still supports Moodle 4.5: the v-3.0 line raised
# mod_adaptivequiz to requires = 2025100600, which only Moodle 5.2 meets, and
# Moodle then aborts the whole installation rather than skipping one plugin.
#
# Checked against this branch: the three plugins' requirements are at most
# 2024100700, local_catquiz's declared dependencies on the other two are
# satisfied within the set, and it carries the fixes for catquiz#59, #62 and the
# #64 stage counts.
ENGINE_BRANCH="${ENGINE_BRANCH:-ALiSe-v-1.2.0-legacy}"

declare -A PLUGINS=(
    ["local_wunderbyte_table"]="https://github.com/Wunderbyte-GmbH/moodle-local_wunderbyte_table.git|main"
    ["local_catquiz"]="https://github.com/ralferlebach/moodle-local_catquiz.git|${ENGINE_BRANCH}"
    ["mod_adaptivequiz"]="https://github.com/ralferlebach/moodle-mod_adaptivequiz.git|${ENGINE_BRANCH}"
    ["adaptivequizcatmodel_catquiz"]="https://github.com/ralferlebach/moodle-adaptivequizcatmodel_catquiz.git|${ENGINE_BRANCH}"
)

mkdir -p "${ENGINE_DIR}"

for name in "${!PLUGINS[@]}"; do
    entry="${PLUGINS[$name]}"
    repo="${entry%%|*}"
    ref="${entry##*|}"
    target="${ENGINE_DIR}/${name}"

    if [ -d "${target}" ]; then
        echo "== ${name}: already present"
        continue
    fi

    echo "== ${name}: ${repo} @ ${ref}"
    git clone --depth 1 --branch "${ref}" --quiet "${repo}" "${target}"

    # Submodules are left as empty directories by a plain clone. Where a plugin
    # declares subplugin types, Moodle scans those directories and fails on the
    # missing version.php -- not with a warning, but by aborting whatever asked
    # the plugin manager for the plugin list. local_catquiz carries its central
    # hub that way. The submodules are not needed to drive the engine, so the
    # empty shells are removed rather than fetched: a half-materialised
    # subplugin is worse than none.
    if [ -f "${target}/.gitmodules" ]; then
        while read -r path; do
            [ -n "${path}" ] || continue
            if [ -d "${target}/${path}" ] && [ -z "$(ls -A "${target}/${path}" 2>/dev/null)" ]; then
                echo "   removing unpopulated submodule ${path}"
                rmdir "${target}/${path}"
            fi
        done < <(grep -oE '^[[:space:]]*path[[:space:]]*=[[:space:]]*.*$' "${target}/.gitmodules" \
            | sed -E 's/^[[:space:]]*path[[:space:]]*=[[:space:]]*//')
    fi

    rm -rf "${target}/.git"

    version=$(grep -oE '\$plugin->version\s*=\s*[0-9]+' "${target}/version.php" | grep -oE '[0-9]+' | head -1)
    echo "   version ${version}"
done

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
    rm -rf "${ENGINE_DIR:?}"/*
    mkdir -p "${ENGINE_DIR}"
    exit 0
fi

echo "Engine ready in ${ENGINE_DIR}:"
ls -1 "${ENGINE_DIR}"
