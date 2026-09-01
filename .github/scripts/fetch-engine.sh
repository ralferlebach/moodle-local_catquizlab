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

# Plugin directory name -> repository and ref. The directory name is what
# moodle-plugin-ci uses to place the plugin, so it has to match the component.
declare -A PLUGINS=(
    ["local_wunderbyte_table"]="https://github.com/Wunderbyte-GmbH/moodle-local_wunderbyte_table.git|main"
    ["local_catquiz"]="https://github.com/ralferlebach/moodle-local_catquiz.git|main"
    ["mod_adaptivequiz"]="https://github.com/ralferlebach/moodle-mod_adaptivequiz.git|v-3.0"
    ["adaptivequizcatmodel_catquiz"]="https://github.com/ralferlebach/moodle-adaptivequizcatmodel_catquiz.git|v-3.0"
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

echo "Engine ready in ${ENGINE_DIR}:"
ls -1 "${ENGINE_DIR}"
