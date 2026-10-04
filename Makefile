PORT ?= 8000
PID_FILE := .server.pid
# Local settings and database for the messages CMS (git-ignored, never deployed).
DEV_CONFIG := dev-data/config.php

.PHONY: up down test

# Prefers PHP's built-in server (needed for the messages CMS); falls back to Python for the static pages.
up: $(DEV_CONFIG)
	@if [ -f $(PID_FILE) ] && kill -0 $$(cat $(PID_FILE)) 2>/dev/null; then \
		echo "Already running at http://localhost:$(PORT)"; \
	elif command -v php >/dev/null; then \
		CAHOB_CONFIG="$(CURDIR)/$(DEV_CONFIG)" php -S localhost:$(PORT) lib/tools/dev_router.php >/dev/null 2>&1 & echo $$! > $(PID_FILE); \
		echo "Running at http://localhost:$(PORT)"; \
	else \
		echo "php not found; serving static files with python (PHP pages won't run)"; \
		python3 -m http.server $(PORT) --bind 127.0.0.1 >/dev/null 2>&1 & echo $$! > $(PID_FILE); \
		echo "Running at http://localhost:$(PORT)"; \
	fi

down:
	@if [ -f $(PID_FILE) ]; then kill $$(cat $(PID_FILE)) 2>/dev/null || true; rm -f $(PID_FILE); echo "Stopped"; \
	else echo "Not running"; fi

test:
	@composer test

# First `make up` copies the example config with debug output on.
$(DEV_CONFIG):
	@mkdir -p dev-data
	@sed "s/'debug' => false/'debug' => true/" lib/config.example.php > $@
	@echo "Created $@ for local development"
