#!/usr/bin/env bash
#
# Run one or more Pest suites against a FRESH testing store.
#
# This is what the composer test:* scripts call. It rebuilds the disposable
# testing store (.env.testing, default ./local-dev/wordpress) from scratch via
# bin/setup-local-dev.sh --env testing --force, so every run starts from a
# known-clean state - no drift from earlier test runs, demo mode or manual
# clicking. Thanks to WP-CLI's download cache the rebuild takes well under a
# minute after the first run.
#
# For quick iteration against the EXISTING testing store, call Pest directly:
#   vendor/bin/pest --testsuite=Integration
#
# When the suites include Browser or Docs and WP_URL is a localhost URL, a PHP
# built-in server is started for the duration of the run (and stopped again).
# With a non-localhost WP_URL (e.g. a Herd-parked .test domain) the web server
# is assumed to be handled externally.
#
# Usage: bin/run-tests.sh <suite>[,<suite>...]        e.g. Integration,Browser
#
set -euo pipefail

SUITES="${1:?Usage: bin/run-tests.sh <Integration|Browser|Docs>[,...]}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

log()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

log "Provisioning a fresh testing store (.env.testing)"
"$REPO_ROOT/bin/setup-local-dev.sh" --env testing --force

env_get() { sed -n "s/^$1=//p" "$REPO_ROOT/.env.testing" 2>/dev/null | tail -1; }
WP_PATH="$(env_get WP_PATH)"; WP_PATH="${WP_PATH:-./local-dev/wordpress}"
[[ "$WP_PATH" != /* ]] && WP_PATH="$REPO_ROOT/$WP_PATH"
WP_URL="$(env_get WP_URL)"; WP_URL="${WP_URL:-http://127.0.0.1:8181}"

HOST="$(echo "$WP_URL" | sed -E 's#https?://([^:/]+).*#\1#')"
PORT="$(echo "$WP_URL" | sed -nE 's#.*:([0-9]+).*#\1#p')"; PORT="${PORT:-80}"

SERVER_PID=""
if [[ "$SUITES" == *Browser* || "$SUITES" == *Docs* ]]; then
    if [[ "$HOST" == "localhost" || "$HOST" == "127.0.0.1" ]]; then
        if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
            fail "Port $PORT is already in use - stop that server first (it may be serving the store that was just deleted)."
        fi
        log "Starting the store server at $WP_URL"
        PHP_CLI_SERVER_WORKERS=6 "${PHP_BIN:-php}" -d memory_limit=512M \
            "$WP_PATH/.wp-cli/wp-cli.phar" --path="$WP_PATH" server --host="$HOST" --port="$PORT" \
            >/dev/null 2>&1 &
        SERVER_PID=$!
        trap '[[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true' EXIT
        for _ in $(seq 1 30); do
            curl -s -o /dev/null --max-time 2 "$WP_URL" && break
            sleep 1
        done
        curl -s -o /dev/null --max-time 5 "$WP_URL" || fail "Store did not come up at $WP_URL"
    else
        log "Assuming $WP_URL is served externally (e.g. Laravel Herd)"
        curl -s -o /dev/null --max-time 5 "$WP_URL" || fail "Store not reachable at $WP_URL - is it parked/linked in your web server?"
    fi
fi

log "Running Pest suite(s): $SUITES"
"$REPO_ROOT/vendor/bin/pest" --testsuite="$SUITES"
