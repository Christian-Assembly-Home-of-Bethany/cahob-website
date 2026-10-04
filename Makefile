PORT ?= 8000
PID_FILE := .server.pid

.PHONY: up down

# Prefers PHP's built-in server (needed for the messages CMS); falls back to Python for the static pages.
up:
	@if [ -f $(PID_FILE) ] && kill -0 $$(cat $(PID_FILE)) 2>/dev/null; then \
		echo "Already running at http://localhost:$(PORT)"; \
	elif command -v php >/dev/null; then \
		php -S localhost:$(PORT) >/dev/null 2>&1 & echo $$! > $(PID_FILE); \
		echo "Running at http://localhost:$(PORT)"; \
	else \
		echo "php not found; serving static files with python (PHP pages won't run)"; \
		python3 -m http.server $(PORT) --bind 127.0.0.1 >/dev/null 2>&1 & echo $$! > $(PID_FILE); \
		echo "Running at http://localhost:$(PORT)"; \
	fi

down:
	@if [ -f $(PID_FILE) ]; then kill $$(cat $(PID_FILE)) 2>/dev/null || true; rm -f $(PID_FILE); echo "Stopped"; \
	else echo "Not running"; fi
