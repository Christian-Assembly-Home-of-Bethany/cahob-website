PORT ?= 8000
PID_FILE := .server.pid
# Local settings and database for the messages CMS (git-ignored, never deployed).
DEV_CONFIG := dev-data/config.php

.PHONY: up down test seed password dev-password import-preview import

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

# Replaces the local database's messages with samples (refuses to run on a non-debug config).
seed: $(DEV_CONFIG)
	@CAHOB_CONFIG="$(CURDIR)/$(DEV_CONFIG)" php lib/tools/seed_dev.php

# Prints a password hash to paste into the server's ~/cahob-data/config.php.
password:
	@stty -echo 2>/dev/null; printf "New admin password: "; IFS= read -r pw; stty echo 2>/dev/null; echo; \
	printf '%s' "$$pw" | php lib/tools/hash_password.php

# Sets the password for logging in to the local admin pages.
dev-password: $(DEV_CONFIG)
	@stty -echo 2>/dev/null; printf "Local admin password: "; IFS= read -r pw; stty echo 2>/dev/null; echo; \
	printf '%s' "$$pw" | php lib/tools/hash_password.php $(DEV_CONFIG)

# Shows what the Blogger import would do, without writing anything.
import-preview: $(DEV_CONFIG)
	@CAHOB_CONFIG="$(CURDIR)/$(DEV_CONFIG)" php lib/tools/import_blogger.php --dry-run

# Imports the Blogger posts into the local database, replacing the messages in it.
import: $(DEV_CONFIG)
	@CAHOB_CONFIG="$(CURDIR)/$(DEV_CONFIG)" php lib/tools/import_blogger.php --replace
