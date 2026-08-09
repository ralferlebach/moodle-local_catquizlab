# Makefile for local_catquizlab
# Mirrors the moodle-plugin-ci check suite used in GitHub Actions.
#
# Targets:
#   make all          — fix + full check suite (default)
#   make fix          — auto-fix PHP style
#   make check        — check-only (no auto-fix)
#   make clear        — clear terminal
#
# Individual checks:
#   make lint-php     — PHPCS Moodle coding standard
#   make lint-phpdoc  — Moodle PHPDoc checker (needs a Moodle checkout, see below)
#   make lint-cpd     — PHP Copy/Paste Detector (informational)
#   make lint-md      — PHP Mess Detector (informational)
#
# Auto-fixers:
#   make fix-lint-php — phpcbf PHP code-style auto-fix
#
# Tests:
#   make phpunit      — PHPUnit testsuite for this plugin (needs initialised phpunit env)
#   make behat        — Behat scenarios tagged @local_catquizlab (needs initialised behat env)
#
# Worker (Puppeteer):
#   make worker-setup — npm install in worker/ (installs Puppeteer + Chromium)
#   make worker-check — syntax check of the worker scripts
#
# NOTE vs. the mod_vimipad ancestor of this makefile: all React/AMD build
# targets and the jMeter/k6 load harness were removed — this plugin ships no
# frontend bundle and no load tests. The only Node component is the external
# Puppeteer worker under worker/, which is not a Moodle AMD module.
#
# Paths are auto-detected from the makefile's own location. The plugin lives
# at <MOODLE_ROOT>/local/catquizlab/ — always two levels below the Moodle
# root — so both PLUGIN_DIR and MOODLE_ROOT are derived automatically.
# Override on the command line if necessary:
#   make lint-php MOODLE_ROOT=/opt/moodle

THIS_DIR      := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
PLUGIN_DIR    ?= $(THIS_DIR)
MOODLE_ROOT   ?= $(abspath $(PLUGIN_DIR)/../..)
PLUGIN_NAME   ?= local_catquizlab
PLUGIN_REL    ?= local/catquizlab
PHP           ?= $(shell which php 2>/dev/null || echo /usr/bin/php)
PHPCS         ?= phpcs
PHPCBF        ?= phpcbf
NPM           ?= npm
NODE          ?= node

WORKER_DIR    ?= $(PLUGIN_DIR)/worker

.PHONY: all fix check clear \
        lint-php lint-phpdoc lint-cpd lint-md \
        fix-lint-php \
        phpunit behat \
        worker-setup worker-check

all: fix check

fix: fix-lint-php

check: lint-php worker-check

clear:
	clear

# --- PHP style ---------------------------------------------------------------

lint-php:
	$(PHPCS) --standard=$(PLUGIN_DIR)/phpcs.xml --extensions=php $(PLUGIN_DIR)

fix-lint-php:
	-$(PHPCBF) --standard=$(PLUGIN_DIR)/phpcs.xml --extensions=php $(PLUGIN_DIR)

# The Moodle PHPDoc checker needs a Moodle checkout with local_moodlecheck or
# moodle-plugin-ci; in CI this runs via `moodle-plugin-ci phpdoc`.
lint-phpdoc:
	@if [ -d "$(MOODLE_ROOT)/local/moodlecheck" ]; then \
		$(PHP) $(MOODLE_ROOT)/local/moodlecheck/cli/moodlecheck.php \
			--path=$(PLUGIN_DIR) --format=text; \
	else \
		echo "local_moodlecheck not found under $(MOODLE_ROOT) — run via moodle-plugin-ci phpdoc in CI."; \
	fi

lint-cpd:
	-npx --yes phpcpd $(PLUGIN_DIR) --exclude worker || true

lint-md:
	-phpmd $(PLUGIN_DIR) text cleancode,codesize,design --exclude worker || true

# --- Moodle tests ------------------------------------------------------------
# Both targets assume the standard Moodle test environments were initialised
# once for this site (composer install in MOODLE_ROOT, then
#   php admin/tool/phpunit/cli/init.php   resp.   php admin/tool/behat/cli/init.php).
# See docs/dev/testsystem-setup.md.

phpunit:
	cd $(MOODLE_ROOT) && vendor/bin/phpunit --testsuite $(PLUGIN_NAME)_testsuite

behat:
	cd $(MOODLE_ROOT) && $(PHP) admin/tool/behat/cli/run.php --tags=@$(PLUGIN_NAME)

# --- Puppeteer worker --------------------------------------------------------

worker-setup:
	cd $(WORKER_DIR) && $(NPM) install --no-audit --no-fund

worker-check:
	@if command -v $(NODE) >/dev/null 2>&1; then \
		cd $(WORKER_DIR) && $(NODE) --check run_attempt.js && echo "worker: syntax OK"; \
	else \
		echo "node not installed — skipping worker syntax check."; \
	fi
