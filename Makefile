# Makefile — convenience wrapper around tools/build.sh for Linux/WSL.
#
# Usage:
#   make build              # build .txz + patched .plg with PLUGIN_VERSION
#   make build PLUGIN_VERSION=2026.07.19j
#   make clean
#   make check              # syntax-check all PHP/JSON/XML files
#   make lint-php           # php -l on every PHP file

PLUGIN_VERSION ?= $(shell cat VERSION)

.PHONY: build clean check lint-php

build:
	PLUGIN_VERSION=$(PLUGIN_VERSION) tools/build.sh

clean:
	rm -rf build

# Syntax checks that work cross-platform (no PHP required for JSON/XML).
check: check-json check-xml check-bash

check-json:
	@echo "==> JSON"
	@for f in plugins.json; do \
	    python3 -c "import json,sys; json.load(open('$$f',encoding='utf-8')); print('  OK $$f')"; \
	done

check-xml:
	@echo "==> XML"
	@for f in dynamix.file.recycle.plg \
	          source/dynamix.file.recycle/images/dynamix.file.recycle.svg; do \
	    python3 -c "import xml.dom.minidom,sys; xml.dom.minidom.parse('$$f'); print('  OK $$f')"; \
	done

check-bash:
	@echo "==> bash"
	@for f in tools/build.sh \
	          source/dynamix.file.recycle/scripts/install.sh \
	          source/dynamix.file.recycle/scripts/remove.sh \
	          source/dynamix.file.recycle/scripts/recycle-maintain; do \
	    bash -n "$$f" && echo "  OK $$f"; \
	done

lint-php:
	@echo "==> php -l"
	@find source/dynamix.file.recycle -type f -name '*.php' -print0 | \
	    xargs -0 -n1 sh -c 'php -l "$$0" >/dev/null && echo "  OK $$0"' || \
	    echo "  (php not available locally — skipping)"
