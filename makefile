# Makefile for local_catquizlab
# Mirrors the moodle-plugin-ci check suite used in GitHub Actions (see
# .github/workflows/moodle-ci.yml).
#
# `make check` runs the FULL functional suite that CI runs (minus Behat, which
# needs a browser): worker syntax, PHPCS, PHPMD, Mustache, Grunt/Gherkin,
# PHPDoc, structure validate, upgrade savepoints, and PHPUnit.
#
# The structure/doc checks (validate, savepoints, mustache, grunt, phpdoc) are
# moodle-plugin-ci commands; when `moodle-plugin-ci` is on PATH they run through
# it — the exact tool CI uses. Without it, each target falls back to a
# direct-tool equivalent where one exists, or prints how to enable the full
# check, so `make check` still runs a meaningful suite.
#
# Targets:
#   make check         — full suite (static + PHPUnit); the default via `make`
#   make check-static  — static suite only, no PHPUnit/Behat (fast)
#   make ci            — check + Behat (the whole GitHub Actions equivalent)
#   make fix           — auto-fix PHP code style (phpcbf)
#
# Individual checks (all also runnable on their own):
#   worker-check  phpcs  phpmd  mustache  grunt  phpdoc  validate  savepoints
#   phpunit  behat  lint-cpd (PHP Copy/Paste Detector, informational)
#
# Worker (Puppeteer):
#   make worker-setup  — npm install in worker/ (Puppeteer + Chromium)
#
# vs. the mod_vimipad ancestor of this makefile: the React/AMD build+lint+test
# targets (amd, react, build, lint-js, lint-react, test-react) and the jMeter/k6
# load harness were removed — this plugin ships no frontend bundle and no load
# tests. The only Node component is the external Puppeteer worker under worker/,
# which is not a Moodle AMD module. Everything else the ancestor checked (PHP
# style, PHPDoc, Mustache, PHPUnit) is restored here, plus the checks CI added
# (PHPMD, Gherkin lint, structure validate, upgrade savepoints).
#
# Paths are auto-detected; override on the command line if needed, e.g.:
#   make check MOODLE_ROOT=/opt/moodle

THIS_DIR    := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
PLUGIN_DIR  ?= $(THIS_DIR)
MOODLE_ROOT ?= $(abspath $(PLUGIN_DIR)/../..)
PLUGIN_NAME ?= local_catquizlab
PLUGIN_REL  ?= local/catquizlab
PHP         ?= $(shell which php 2>/dev/null || echo /usr/bin/php)
PHPCS       ?= phpcs
PHPCBF      ?= phpcbf
PHPMD       ?= phpmd
NPM         ?= npm
NODE        ?= node
WORKER_DIR  ?= $(PLUGIN_DIR)/worker

# moodle-plugin-ci is the tool CI uses; prefer it for the structure/doc checks.
MPCI        := $(shell command -v moodle-plugin-ci 2>/dev/null)

.PHONY: all fix check check-static ci clear \
        worker-check worker-setup \
        phpcs fix-lint-php phpmd mustache grunt phpdoc validate savepoints \
        phpunit behat lint-cpd

all: fix check

fix: fix-lint-php

# Full suite (mirrors CI, minus Behat).
check: check-static phpunit

# Static suite only (no PHPUnit/Behat) — fast pre-commit gate.
check-static: worker-check phpcs phpmd mustache grunt phpdoc validate savepoints

# Everything, including Behat.
ci: check behat

clear:
	clear

# --- Puppeteer worker --------------------------------------------------------
worker-check:
	@if command -v $(NODE) >/dev/null 2>&1; then \
		cd $(WORKER_DIR) && $(NODE) --check run_attempt.js && echo "worker: syntax OK"; \
	else echo "node not installed - skipping worker syntax check."; fi

worker-setup:
	cd $(WORKER_DIR) && $(NPM) install --no-audit --no-fund

# --- PHP CodeSniffer (fails on warnings, like CI `--max-warnings 0`) ----------
phpcs:
	$(PHPCS) --standard=$(PLUGIN_DIR)/phpcs.xml --extensions=php $(PLUGIN_DIR)

fix-lint-php:
	-$(PHPCBF) --standard=$(PLUGIN_DIR)/phpcs.xml --extensions=php $(PLUGIN_DIR)

# --- PHP Mess Detector (informational, like CI `phpmd || true`) ---------------
phpmd:
	@if [ -n "$(MPCI)" ]; then cd $(PLUGIN_DIR) && moodle-plugin-ci phpmd . || true; \
	else $(PHPMD) $(PLUGIN_DIR) text codesize,unusedcode,design --exclude '*/worker/*,*/node_modules/*' || true; fi

# --- Mustache templates ------------------------------------------------------
mustache:
	@if [ -n "$(MPCI)" ]; then cd $(PLUGIN_DIR) && moodle-plugin-ci mustache . || true; \
	elif ls $(PLUGIN_DIR)/templates/*.mustache >/dev/null 2>&1; then \
		echo "mustache: templates present - full lint via moodle-plugin-ci mustache."; \
	else echo "mustache: no templates - nothing to lint."; fi

# --- Grunt / Gherkin lint ----------------------------------------------------
grunt:
	@if [ -n "$(MPCI)" ]; then cd $(MOODLE_ROOT) && moodle-plugin-ci grunt || true; \
	else echo "grunt/gherkin lint: needs moodle-plugin-ci (skipped)."; fi

# --- PHPDoc (fails on warnings, like CI `--max-warnings 0`) -------------------
phpdoc:
	@if [ -n "$(MPCI)" ]; then cd $(PLUGIN_DIR) && moodle-plugin-ci phpdoc . --max-warnings 0; \
	elif [ -f "$(MOODLE_ROOT)/local/moodlecheck/cli/moodlecheck.php" ]; then \
		$(PHP) $(MOODLE_ROOT)/local/moodlecheck/cli/moodlecheck.php --path=$(PLUGIN_DIR) --format=text; \
	else echo "phpdoc: needs moodle-plugin-ci or local_moodlecheck (skipped)."; fi

# --- Structure validation ----------------------------------------------------
validate:
	@if [ -n "$(MPCI)" ]; then cd $(PLUGIN_DIR) && moodle-plugin-ci validate .; \
	else \
		$(PHP) -l $(PLUGIN_DIR)/version.php >/dev/null && \
		$(PHP) -r 'simplexml_load_file("$(PLUGIN_DIR)/db/install.xml");' && \
		grep -q "component *= *'$(PLUGIN_NAME)'" $(PLUGIN_DIR)/version.php && \
		test -f $(PLUGIN_DIR)/lang/en/$(PLUGIN_NAME).php && \
		echo "validate (local subset): OK - full structure check via moodle-plugin-ci."; \
	fi

# --- Upgrade savepoints (highest savepoint must be <= plugin version) --------
savepoints:
	@if [ -n "$(MPCI)" ]; then cd $(PLUGIN_DIR) && moodle-plugin-ci savepoints .; \
	else \
		ver=`grep -oE '>version[[:space:]]*=[[:space:]]*[0-9]+' $(PLUGIN_DIR)/version.php | grep -oE '[0-9]+' | head -1`; \
		sp=`grep -oE 'upgrade_plugin_savepoint\(true, [0-9]+' $(PLUGIN_DIR)/db/upgrade.php 2>/dev/null | grep -oE '[0-9]+' | sort -n | tail -1`; \
		if [ -z "$$sp" ]; then echo "savepoints: no upgrade savepoints (OK)"; \
		elif [ "$$sp" -le "$$ver" ]; then echo "savepoints: OK ($$sp <= $$ver)"; \
		else echo "savepoints: FAIL (savepoint $$sp > version $$ver)"; exit 1; fi; \
	fi

# --- PHP Copy/Paste Detector (informational; not run by CI) ------------------
lint-cpd:
	-npx --yes phpcpd $(PLUGIN_DIR) --exclude worker || true

# --- Moodle tests ------------------------------------------------------------
# Both need the standard Moodle test envs initialised once (see
# docs/dev/testsystem-setup.md): composer install in MOODLE_ROOT, then
#   php admin/tool/phpunit/cli/init.php   resp.   php admin/tool/behat/cli/init.php
phpunit:
	@if [ -n "$(MPCI)" ]; then cd $(MOODLE_ROOT) && moodle-plugin-ci phpunit --fail-on-warning; \
	else cd $(MOODLE_ROOT) && vendor/bin/phpunit --testsuite $(PLUGIN_NAME)_testsuite; fi

behat:
	@if [ -n "$(MPCI)" ]; then cd $(MOODLE_ROOT) && moodle-plugin-ci behat --profile chrome; \
	else cd $(MOODLE_ROOT) && $(PHP) admin/tool/behat/cli/run.php --tags=@$(PLUGIN_NAME); fi
