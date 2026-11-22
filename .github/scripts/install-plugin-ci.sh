#!/usr/bin/env bash
# Install moodle-plugin-ci into ./ci, with retries.
#
# Release CI 2026-11-22 (0.7.77, job "Upgrade 0.7.60 → this commit", 5.3/MariaDB): composer's
# "git clone --mirror" of moodlehq/moodle-local_moodlecheck into its cache got HTTP 403 from github.com (with the
# token setup-php hands composer), and the job ended before it had tested anything; the same install worked in the
# other 24 jobs of the run. A refusal of github.com, not of this repository: now up to four tries with a growing
# pause, the half-written cache entry removed between them. Fails only when all four fail, and says so.
#
#     bash plugin/.github/scripts/install-plugin-ci.sh '^4'
set -u
VERSION=${1:?usage: install-plugin-ci.sh VERSION-CONSTRAINT}
for try in 1 2 3 4; do
    rm -rf ci
    if composer create-project -n --no-dev moodlehq/moodle-plugin-ci ci "$VERSION"; then
        if [ "$try" -gt 1 ]; then
            echo "::notice::moodle-plugin-ci installed on try $try of 4"
        fi
        exit 0
    fi
    echo "::warning::moodle-plugin-ci not installed (try $try of 4)"
    vcs=$(composer config -g cache-vcs-dir 2>/dev/null || true)
    if [ -n "$vcs" ] && [ -d "$vcs" ]; then
        find "${vcs:?}" -maxdepth 1 -name '*moodle-local-moodlecheck*' -exec rm -rf {} + 2>/dev/null || true
    fi
    if [ "$try" -lt 4 ]; then
        sleep $((try * 20))
    fi
done
echo "::error::moodle-plugin-ci could not be installed in 4 tries"
exit 1
